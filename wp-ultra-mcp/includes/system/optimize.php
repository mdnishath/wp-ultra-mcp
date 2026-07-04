<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Performance optimizer engine (Roadmap-2 S3).
 *
 * Three concerns, each following the plugin's pure-core / thin-WP-wrapper split:
 *
 *  1. DATABASE — wpultra_optimize_database() runs a set of safe cleanup tasks
 *     (revisions with keep-last-N, auto-drafts, trashed posts, spam/trashed
 *     comments, expired transients, orphan post/term meta, and OPTIMIZE TABLE).
 *     Each task returns {found, deleted}; a dry run counts only. The DELETE
 *     builders are pure string factories so the SQL shape is unit-testable
 *     without a database.
 *
 *  2. IMAGES — wpultra_optimize_images() finds oversized image attachments
 *     (width over max_width OR file bigger than threshold) and resizes them to
 *     max_width + optionally converts to WebP, IN PLACE, reusing the media-edit
 *     engine (wpultra_media_edit_apply with overwrite:true). Batched via an
 *     offset cursor so huge libraries loop. wpultra_optimize_pick_images() is
 *     the pure decision layer (which attachments need work + why).
 *
 *  3. CACHE — cache_status()/cache_configure() honestly scope "cache": detect
 *     existing page-cache plugins (reuse of the devtools/audits probe map),
 *     enable browser-caching + gzip via the .htaccess rules engine, toggle a
 *     lazyload option (the controller wires wpultra_optimize_lazyload_filter
 *     into the runtime), and purge. No home-grown page cache is written.
 *
 * The pure functions (wpultra_optimize_valid_tasks, wpultra_optimize_revisions_sql,
 * wpultra_optimize_pick_images, wpultra_optimize_summary) are the test core.
 */

// ===========================================================================
// PURE: task vocabulary
// ===========================================================================

/** The full set of known database-optimize task ids, in canonical order. Pure. */
function wpultra_optimize_known_tasks(): array {
    return [
        'revisions',
        'auto_drafts',
        'trashed_posts',
        'spam_comments',
        'trashed_comments',
        'expired_transients',
        'orphan_postmeta',
        'orphan_termmeta',
        'optimize_tables',
    ];
}

/** The default "safe" task set used when no tasks are supplied. Pure. */
function wpultra_optimize_default_tasks(): array {
    return [
        'revisions',
        'auto_drafts',
        'trashed_posts',
        'spam_comments',
        'expired_transients',
        'orphan_postmeta',
        'optimize_tables',
    ];
}

/**
 * PURE. Filter an arbitrary task list down to the known task ids, preserving
 * request order, de-duplicated. Unknown ids are dropped silently.
 * @param array $tasks
 * @return array<int,string>
 */
function wpultra_optimize_valid_tasks(array $tasks): array {
    $known = wpultra_optimize_known_tasks();
    $out = [];
    foreach ($tasks as $t) {
        $t = is_string($t) ? $t : '';
        if ($t === '' || !in_array($t, $known, true) || in_array($t, $out, true)) { continue; }
        $out[] = $t;
    }
    return $out;
}

// ===========================================================================
// PURE: SQL builders (string factories — no $wpdb, no execution)
// ===========================================================================

/**
 * PURE. Build the DELETE that trims post revisions to the newest $keep per
 * parent post. When $keep <= 0, every revision is deleted (simple form).
 * Otherwise a correlated subquery keeps the $keep most-recent revision ids per
 * post_parent (ranked by ID desc) and deletes the rest.
 *
 * $posts / $meta are the (already-prefixed, caller-supplied) table names, so
 * the builder never has to know the WordPress prefix — the wrapper passes
 * $wpdb->posts / $wpdb->postmeta. The result is a single DELETE statement.
 *
 * @param string $posts table name (e.g. 'wp_posts')
 * @param string $meta  table name (e.g. 'wp_postmeta') — reserved for a future
 *                      orphan-meta cascade; kept in the signature so callers
 *                      pass both table names explicitly.
 * @param int    $keep  number of newest revisions to keep per post
 */
function wpultra_optimize_revisions_sql(string $posts, string $meta, int $keep): string {
    if ($keep <= 0) {
        return "DELETE FROM `$posts` WHERE post_type = 'revision'";
    }
    // Wrap the ranked keep-set in a derived table so MySQL allows deleting from
    // the same table we select from. For each post_parent keep the $keep highest
    // IDs; delete every revision whose ID is not in that keep-set.
    return "DELETE r FROM `$posts` r "
         . "WHERE r.post_type = 'revision' "
         . "AND r.ID NOT IN ("
         .   "SELECT ID FROM ("
         .     "SELECT k.ID FROM `$posts` k "
         .     "WHERE k.post_type = 'revision' "
         .     "AND ("
         .       "SELECT COUNT(*) FROM `$posts` k2 "
         .       "WHERE k2.post_type = 'revision' "
         .       "AND k2.post_parent = k.post_parent "
         .       "AND k2.ID >= k.ID"
         .     ") <= $keep"
         .   ") AS keep_set"
         . ")";
}

// ===========================================================================
// PURE: image selection
// ===========================================================================

/**
 * PURE. Decide which attachment fixtures need optimizing and why.
 *
 * Each fixture: ['id'=>int, 'width'=>int, 'filesize'=>int (bytes), 'mime'=>string, 'file'=>string].
 * An image needs work when its width exceeds $max_width OR its filesize exceeds
 * $threshold_kb (KB). Non-image mimes and already-small images are skipped.
 *
 * @param array $attachment_fixtures
 * @param int   $max_width
 * @param int   $threshold_kb
 * @return array<int,array{id:int,reasons:array<int,string>,width:int,filesize:int}>
 */
function wpultra_optimize_pick_images(array $attachment_fixtures, int $max_width, int $threshold_kb): array {
    $threshold_bytes = max(0, $threshold_kb) * 1024;
    $out = [];
    foreach ($attachment_fixtures as $att) {
        if (!is_array($att)) { continue; }
        $mime = (string) ($att['mime'] ?? '');
        if ($mime !== '' && strpos($mime, 'image/') !== 0) { continue; }

        $width    = (int) ($att['width'] ?? 0);
        $filesize = (int) ($att['filesize'] ?? 0);
        $reasons  = [];
        if ($max_width > 0 && $width > $max_width) { $reasons[] = 'width'; }
        if ($threshold_bytes > 0 && $filesize > $threshold_bytes) { $reasons[] = 'filesize'; }
        if ($reasons === []) { continue; }

        $out[] = [
            'id'       => (int) ($att['id'] ?? 0),
            'reasons'  => $reasons,
            'width'    => $width,
            'filesize' => $filesize,
        ];
    }
    return $out;
}

// ===========================================================================
// PURE: summary
// ===========================================================================

/**
 * PURE. Roll a per-task {found, deleted} results map into totals.
 * Ignores non-numeric entries defensively. Returns
 * ['total_found'=>int, 'total_deleted'=>int, 'tasks_run'=>int].
 * @param array $results task_id => ['found'=>int, 'deleted'=>int]
 */
function wpultra_optimize_summary(array $results): array {
    $found = 0;
    $deleted = 0;
    $count = 0;
    foreach ($results as $r) {
        if (!is_array($r)) { continue; }
        $found   += (int) ($r['found'] ?? 0);
        $deleted += (int) ($r['deleted'] ?? 0);
        $count++;
    }
    return ['total_found' => $found, 'total_deleted' => $deleted, 'tasks_run' => $count];
}

// ===========================================================================
// WP-touching: database optimize
// ===========================================================================

/**
 * Run the selected database-cleanup $tasks. dry_run counts only (no writes).
 * Returns a task_id => ['found'=>int,'deleted'=>int] map. Unknown tasks are
 * dropped up-front via wpultra_optimize_valid_tasks().
 *
 * @param array $tasks
 * @param bool  $dry_run
 * @param int   $keep_revisions newest revisions to keep per post (default 5)
 * @return array<string,array{found:int,deleted:int}>
 */
function wpultra_optimize_database(array $tasks, bool $dry_run = true, int $keep_revisions = 5): array {
    global $wpdb;
    $tasks = wpultra_optimize_valid_tasks($tasks);
    $keep  = max(0, $keep_revisions);
    $out   = [];

    foreach ($tasks as $task) {
        switch ($task) {
            case 'revisions':
                $out[$task] = wpultra_optimize_run_revisions($dry_run, $keep);
                break;

            case 'auto_drafts':
                $out[$task] = wpultra_optimize_run_post_status($dry_run, 'auto-draft', null);
                break;

            case 'trashed_posts':
                $out[$task] = wpultra_optimize_run_post_status($dry_run, 'trash', null);
                break;

            case 'spam_comments':
                $out[$task] = wpultra_optimize_run_comment_status($dry_run, 'spam');
                break;

            case 'trashed_comments':
                $out[$task] = wpultra_optimize_run_comment_status($dry_run, 'trash');
                break;

            case 'expired_transients':
                $out[$task] = wpultra_optimize_run_expired_transients($dry_run);
                break;

            case 'orphan_postmeta':
                $out[$task] = wpultra_optimize_run_orphan_meta(
                    $dry_run, $wpdb->postmeta, 'post_id', $wpdb->posts, 'ID'
                );
                break;

            case 'orphan_termmeta':
                $out[$task] = wpultra_optimize_run_orphan_meta(
                    $dry_run, $wpdb->termmeta, 'term_id', $wpdb->terms, 'term_id'
                );
                break;

            case 'optimize_tables':
                $out[$task] = wpultra_optimize_run_optimize_tables($dry_run);
                break;
        }
    }

    return $out;
}

/** Count/delete revisions keeping the newest $keep per post. */
function wpultra_optimize_run_revisions(bool $dry_run, int $keep): array {
    global $wpdb;
    // "found" = how many revisions WOULD be removed under the keep policy.
    if ($keep <= 0) {
        $found = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", 'revision'
        ));
    } else {
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", 'revision'
        ));
        // kept = min(count, keep) per parent — count parents' kept rows.
        $kept = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(LEAST(c, %d)), 0) FROM ("
          .   "SELECT COUNT(*) AS c FROM {$wpdb->posts} WHERE post_type = %s GROUP BY post_parent"
          . ") AS per_parent",
            $keep, 'revision'
        ));
        $found = max(0, $total - $kept);
    }

    if ($dry_run || $found === 0) { return ['found' => $found, 'deleted' => 0]; }

    $sql = wpultra_optimize_revisions_sql($wpdb->posts, $wpdb->postmeta, $keep);
    $deleted = (int) $wpdb->query($sql);
    return ['found' => $found, 'deleted' => max(0, $deleted)];
}

/** Count/delete posts of a given post_status via wp_delete_post (proper cleanup). */
function wpultra_optimize_run_post_status(bool $dry_run, string $status, $unused): array {
    global $wpdb;
    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_status = %s", $status
    ));
    $ids = is_array($ids) ? array_map('intval', $ids) : [];
    $found = count($ids);
    if ($dry_run || $found === 0) { return ['found' => $found, 'deleted' => 0]; }

    $deleted = 0;
    foreach ($ids as $id) {
        if (function_exists('wp_delete_post')) {
            if (wp_delete_post($id, true)) { $deleted++; }
        } else {
            $wpdb->delete($wpdb->posts, ['ID' => $id]);
            $wpdb->delete($wpdb->postmeta, ['post_id' => $id]);
            $deleted++;
        }
    }
    return ['found' => $found, 'deleted' => $deleted];
}

/** Count/delete comments in a given status ('spam'|'trash') via wp_delete_comment. */
function wpultra_optimize_run_comment_status(bool $dry_run, string $status): array {
    global $wpdb;
    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved = %s", $status
    ));
    $ids = is_array($ids) ? array_map('intval', $ids) : [];
    $found = count($ids);
    if ($dry_run || $found === 0) { return ['found' => $found, 'deleted' => 0]; }

    $deleted = 0;
    foreach ($ids as $id) {
        if (function_exists('wp_delete_comment')) {
            if (wp_delete_comment($id, true)) { $deleted++; }
        } else {
            $wpdb->delete($wpdb->comments, ['comment_ID' => $id]);
            $deleted++;
        }
    }
    return ['found' => $found, 'deleted' => $deleted];
}

/** Count/delete expired transients (both site + regular) via the options table. */
function wpultra_optimize_run_expired_transients(bool $dry_run): array {
    global $wpdb;
    $now = time();
    // Timeout rows whose value (an expiry unix ts) is in the past.
    $timeout_like = $wpdb->esc_like('_transient_timeout_') . '%';
    $site_timeout_like = $wpdb->esc_like('_site_transient_timeout_') . '%';

    $expired_names = $wpdb->get_col($wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options} "
      . "WHERE (option_name LIKE %s OR option_name LIKE %s) "
      . "AND CAST(option_value AS UNSIGNED) < %d",
        $timeout_like, $site_timeout_like, $now
    ));
    $expired_names = is_array($expired_names) ? $expired_names : [];
    $found = count($expired_names);
    if ($dry_run || $found === 0) { return ['found' => $found, 'deleted' => 0]; }

    $deleted = 0;
    foreach ($expired_names as $timeout_name) {
        // Derive the sibling value option name by stripping the "timeout_" marker.
        $value_name = str_replace(
            ['_transient_timeout_', '_site_transient_timeout_'],
            ['_transient_', '_site_transient_'],
            (string) $timeout_name
        );
        if (function_exists('delete_option')) {
            delete_option((string) $timeout_name);
            delete_option($value_name);
        } else {
            $wpdb->delete($wpdb->options, ['option_name' => (string) $timeout_name]);
            $wpdb->delete($wpdb->options, ['option_name' => $value_name]);
        }
        $deleted++; // count the transient pair as one removed transient
    }
    return ['found' => $found, 'deleted' => $deleted];
}

/**
 * Count/delete orphan meta rows: meta whose owning object row no longer exists.
 * Generic across postmeta/termmeta by table + key-column names.
 */
function wpultra_optimize_run_orphan_meta(bool $dry_run, string $meta_table, string $fk_col, string $owner_table, string $owner_pk): array {
    global $wpdb;
    $found = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM `$meta_table` m "
      . "LEFT JOIN `$owner_table` o ON m.`$fk_col` = o.`$owner_pk` "
      . "WHERE o.`$owner_pk` IS NULL"
    );
    if ($dry_run || $found === 0) { return ['found' => $found, 'deleted' => 0]; }

    $deleted = (int) $wpdb->query(
        "DELETE m FROM `$meta_table` m "
      . "LEFT JOIN `$owner_table` o ON m.`$fk_col` = o.`$owner_pk` "
      . "WHERE o.`$owner_pk` IS NULL"
    );
    return ['found' => $found, 'deleted' => max(0, $deleted)];
}

/** Run OPTIMIZE TABLE against every prefixed table. found = table count. */
function wpultra_optimize_run_optimize_tables(bool $dry_run): array {
    global $wpdb;
    $like = $wpdb->esc_like($wpdb->prefix) . '%';
    $tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $like));
    $tables = is_array($tables) ? $tables : [];
    $found = count($tables);
    if ($dry_run || $found === 0) { return ['found' => $found, 'deleted' => 0]; }

    $optimized = 0;
    foreach ($tables as $table) {
        // Table name comes from SHOW TABLES (server-provided), backtick-quoted.
        $wpdb->query('OPTIMIZE TABLE `' . str_replace('`', '', (string) $table) . '`');
        $optimized++;
    }
    // For OPTIMIZE, "deleted" carries the count of tables optimized.
    return ['found' => $found, 'deleted' => $optimized];
}

// ===========================================================================
// WP-touching: image optimize
// ===========================================================================

/**
 * Optimize oversized images in place (resize to max_width + optional WebP),
 * batched by an offset cursor.
 *
 * $in keys: max_width (default 1920), threshold_kb (default 300),
 *           limit (default 20, capped 1..100), offset (default 0),
 *           convert_webp (default true).
 *
 * @param array $in
 * @return array{processed:int,saved_bytes:int,next_offset:int|null,scanned:int,items:array}
 */
function wpultra_optimize_images(array $in): array {
    $max_width    = max(1, (int) ($in['max_width'] ?? 1920));
    $threshold_kb = max(0, (int) ($in['threshold_kb'] ?? 300));
    $limit        = max(1, min(100, (int) ($in['limit'] ?? 20)));
    $offset       = max(0, (int) ($in['offset'] ?? 0));
    $convert_webp = ($in['convert_webp'] ?? true) === true;

    // Pull a page of image attachments (ids), then build fixtures for the pure picker.
    $ids = [];
    if (function_exists('get_posts')) {
        $ids = get_posts([
            'post_type'      => 'attachment',
            'post_mime_type' => 'image',
            'post_status'    => 'inherit',
            'numberposts'    => $limit,
            'offset'         => $offset,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'fields'         => 'ids',
        ]);
    }
    $ids = is_array($ids) ? array_map('intval', $ids) : [];
    $scanned = count($ids);

    $fixtures = [];
    foreach ($ids as $id) {
        $file = function_exists('get_attached_file') ? get_attached_file($id) : '';
        $meta = function_exists('wp_get_attachment_metadata') ? wp_get_attachment_metadata($id) : [];
        $width = is_array($meta) ? (int) ($meta['width'] ?? 0) : 0;
        $filesize = ($file && file_exists($file)) ? (int) filesize($file) : 0;
        $fixtures[] = [
            'id'       => $id,
            'width'    => $width,
            'filesize' => $filesize,
            'mime'     => function_exists('get_post_mime_type') ? (string) get_post_mime_type($id) : 'image/*',
            'file'     => (string) $file,
        ];
    }

    $picks = wpultra_optimize_pick_images($fixtures, $max_width, $threshold_kb);

    $processed = 0;
    $saved_bytes = 0;
    $items = [];
    foreach ($picks as $pick) {
        $id = (int) $pick['id'];
        $file = function_exists('get_attached_file') ? get_attached_file($id) : '';
        $before = ($file && file_exists($file)) ? (int) filesize($file) : 0;

        $ops = [['op' => 'resize', 'width' => $max_width]];
        if ($convert_webp) { $ops[] = ['op' => 'convert', 'format' => 'webp']; }

        $result = function_exists('wpultra_media_edit_apply')
            ? wpultra_media_edit_apply($id, $ops, true)
            : wpultra_err('media_unavailable', 'Media edit engine unavailable.');

        if (is_wp_error($result)) {
            $items[] = ['id' => $id, 'ok' => false, 'error' => $result->get_error_message()];
            continue;
        }

        // Measure the new file size (path may have changed on WebP convert).
        $new_file = function_exists('get_attached_file') ? get_attached_file($id) : $file;
        $after = ($new_file && file_exists($new_file)) ? (int) filesize($new_file) : $before;
        $saved = max(0, $before - $after);
        $saved_bytes += $saved;
        $processed++;
        $items[] = ['id' => $id, 'ok' => true, 'saved_bytes' => $saved, 'reasons' => $pick['reasons']];
    }

    // Cursor: if this page was full, there may be more; advance by the page size.
    $next_offset = ($scanned === $limit) ? ($offset + $limit) : null;

    return [
        'processed'   => $processed,
        'saved_bytes' => $saved_bytes,
        'next_offset' => $next_offset,
        'scanned'     => $scanned,
        'items'       => $items,
    ];
}

// ===========================================================================
// WP-touching: cache (honestly scoped) + lazyload runtime filter
// ===========================================================================

/**
 * Report cache posture: which page-cache plugin (if any) is present, whether an
 * external object cache is active, whether the browser-caching/gzip rules are in
 * our managed .htaccess block, and the lazyload flag.
 *
 * @return array
 */
function wpultra_optimize_cache_status(): array {
    $page_cache = function_exists('wpultra_audits_page_cache_probe') ? (bool) wpultra_audits_page_cache_probe() : false;
    $object_cache = function_exists('wp_using_ext_object_cache') ? (bool) wp_using_ext_object_cache() : false;

    // Are browser-caching / gzip rules present in our managed block?
    $browser_rules = false;
    $gzip_rules = false;
    if (function_exists('wpultra_rules_get')) {
        $rules = wpultra_rules_get();
        if (is_array($rules) && !empty($rules['lines'])) {
            $joined = implode("\n", array_map('strval', (array) $rules['lines']));
            $browser_rules = strpos($joined, 'mod_expires') !== false || strpos($joined, 'browser-caching') !== false;
            $gzip_rules    = strpos($joined, 'mod_deflate') !== false || strpos($joined, 'gzip') !== false;
        }
    }

    return [
        'page_cache_plugin'   => $page_cache,
        'object_cache'        => $object_cache,
        'browser_caching'     => $browser_rules,
        'gzip'                => $gzip_rules,
        'lazyload'            => wpultra_optimize_lazyload_enabled(),
    ];
}

/** True when the lazyload flag option is on. */
function wpultra_optimize_lazyload_enabled(): bool {
    return function_exists('get_option') ? (get_option('wpultra_perf_lazyload') === '1') : false;
}

/**
 * PURE. Detect which presets are present in a managed-block line list, and
 * which remaining lines are custom. Used so cache enable/disable can rewrite
 * the block without dropping presets other features wrote (e.g.
 * security-headers).
 *
 * Detection matches each preset's own composed BODY lines (trim-compared)
 * against the block — NOT "# <name>" comment headers, because the rules
 * engine's get() strips comment lines. A preset counts as present when ALL of
 * its non-structural lines (everything except <IfModule>/</IfModule>) appear.
 * Lines claimed by no detected preset (and not comments/structural) are
 * returned as custom, so an unrecognized-but-real directive survives a rewrite.
 *
 * @param array $lines           the current managed-block lines
 * @param array $preset_line_map preset name => its composed lines
 * @return array{presets:array<int,string>, custom:array<int,string>}
 */
function wpultra_optimize_rules_sections(array $lines, array $preset_line_map): array {
    $is_structural = static function (string $l): bool {
        return stripos($l, '<IfModule') === 0 || strcasecmp($l, '</IfModule>') === 0;
    };

    $present_lines = [];
    foreach ($lines as $l) {
        $t = trim((string) $l);
        if ($t !== '') { $present_lines[$t] = true; }
    }

    $presets = [];
    $claimed = [];
    foreach ($preset_line_map as $name => $plines) {
        $body = [];
        foreach ((array) $plines as $pl) {
            $pl = trim((string) $pl);
            if ($pl === '' || $pl[0] === '#') { continue; }
            $body[] = $pl;
        }
        if ($body === []) { continue; }
        $signature = array_values(array_filter($body, static fn($l) => !$is_structural($l)));
        $check = $signature !== [] ? $signature : $body;
        $all_found = true;
        foreach ($check as $l) {
            if (!isset($present_lines[$l])) { $all_found = false; break; }
        }
        if ($all_found) {
            $presets[] = (string) $name;
            foreach ($body as $l) { $claimed[$l] = true; }
        }
    }

    $custom = [];
    foreach ($lines as $l) {
        $t = trim((string) $l);
        if ($t === '' || $t[0] === '#' || isset($claimed[$t]) || $is_structural($t)) { continue; }
        if (!in_array($t, $custom, true)) { $custom[] = $t; }
    }
    return ['presets' => $presets, 'custom' => $custom];
}

/**
 * Configure "cache" in the honestly-scoped sense: write browser-caching + gzip
 * rules into the managed .htaccess block (via the rules engine), toggle the
 * lazyload flag, and purge existing caches. Requires the rules write to be
 * confirmed by the caller (confirm passes straight through to the rules engine).
 *
 * @param bool  $enable whether to enable (true) or disable (false) the features
 * @param array $opts   ['confirm'=>bool, 'browser_rules'=>bool, 'lazyload'=>bool, 'purge'=>bool]
 * @return array|WP_Error
 */
function wpultra_optimize_cache_configure(bool $enable, array $opts = []) {
    $confirm       = ($opts['confirm'] ?? false) === true;
    $do_rules      = ($opts['browser_rules'] ?? true) === true;
    $do_lazyload   = ($opts['lazyload'] ?? true) === true;
    $do_purge      = ($opts['purge'] ?? true) === true;

    $result = ['enabled' => $enable, 'rules' => null, 'lazyload' => null, 'purged' => null];

    if ($do_rules && function_exists('wpultra_rules_set') && function_exists('wpultra_rules_clear')) {
        // rules_set REPLACES the whole managed block, so merge with whatever is
        // already there (e.g. security-headers written by security-harden)
        // instead of clobbering it.
        $preset_map = [];
        if (function_exists('wpultra_rules_preset_registry')) {
            foreach (wpultra_rules_preset_registry() as $pname => $builder) {
                $preset_map[$pname] = (array) call_user_func($builder);
            }
        }
        $current_lines = [];
        if (function_exists('wpultra_rules_get')) {
            $cur = wpultra_rules_get();
            if (is_array($cur) && isset($cur['lines']) && is_array($cur['lines'])) { $current_lines = $cur['lines']; }
        }
        $sections = wpultra_optimize_rules_sections($current_lines, $preset_map);
        $cache_presets = ['browser-caching', 'gzip'];

        if ($enable) {
            $presets = array_values(array_unique(array_merge($sections['presets'], $cache_presets)));
            $rules = wpultra_rules_set(['presets' => $presets, 'custom_lines' => $sections['custom'], 'confirm' => $confirm]);
        } else {
            $remaining = array_values(array_diff($sections['presets'], $cache_presets));
            if ($remaining !== [] || $sections['custom'] !== []) {
                $rules = wpultra_rules_set(['presets' => $remaining, 'custom_lines' => $sections['custom'], 'confirm' => $confirm]);
            } else {
                $rules = wpultra_rules_clear(['confirm' => $confirm]);
            }
        }
        if (is_wp_error($rules)) { return $rules; }
        $result['rules'] = is_array($rules) ? ($rules['lines'] ?? []) : [];
    }

    if ($do_lazyload && function_exists('update_option')) {
        update_option('wpultra_perf_lazyload', $enable ? '1' : '0', false);
        $result['lazyload'] = $enable;
    }

    if ($do_purge && function_exists('wpultra_devtools_purge_cache')) {
        $purge = wpultra_devtools_purge_cache();
        $result['purged'] = is_array($purge) ? ($purge['purged'] ?? []) : [];
    }

    return $result;
}

/**
 * Runtime filter the controller wires onto `wp_get_attachment_image_attributes`
 * (and can be reused for content images): when the lazyload flag is on, ensure
 * every emitted <img> carries loading="lazy". PURE-ish: given the current attrs
 * array, return the (possibly) augmented attrs. No-ops when the flag is off.
 *
 * @param array $attr existing image attributes (attr-name => value)
 * @return array
 */
function wpultra_optimize_lazyload_filter($attr) {
    if (!is_array($attr)) { return $attr; }
    if (!wpultra_optimize_lazyload_enabled()) { return $attr; }
    if (empty($attr['loading'])) { $attr['loading'] = 'lazy'; }
    return $attr;
}
