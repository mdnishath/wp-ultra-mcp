<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Staging-clone engine (Wave / roadmap #23).
 *
 * Pragmatic single-server staging: the clone lives at ABSPATH/staging-<name>/ and
 * shares the SAME MySQL database as production, but under a NEW table prefix
 * ('stg<short>_'). File tree is copied; DB tables are cloned per-table
 * (CREATE TABLE ... LIKE + INSERT ... SELECT); URLs/paths inside the NEW tables
 * are rewritten serialized-safe (reusing the wpultra_sr_* helpers in siteops.php);
 * and the staging copy's wp-config.php has its $table_prefix line rewritten so it
 * boots against the cloned prefix.
 *
 * This is NOT a substitute for host-level staging (no isolated DB, no separate
 * PHP pool). It is meant for quick throwaway copies on a single server.
 *
 * PURE (unit-tested) helpers are separated from the WP/FS-calling engine so the
 * core string/prefix/exclusion logic is testable without WordPress.
 *
 * Requires siteops.php (wpultra_sr_replace_column etc.) to be loaded — the
 * bootstrap loads system/siteops.php in the same 'system' block.
 */

/* ============================================================
 * PURE helpers (unit-tested)
 * ============================================================ */

/**
 * PURE: deterministic short table prefix for a staging name.
 * 'stg' + first 4 hex of md5(name) + '_'  → e.g. 'stg1a2b_'.
 * Deterministic so re-running create/delete/list for the same name lines up.
 */
function wpultra_staging_prefix(string $name): string {
    return 'stg' . substr(md5($name), 0, 4) . '_';
}

/**
 * PURE: validate a staging name. Only [a-z0-9-], 1..40 chars, not starting or
 * ending with a hyphen. Returns '' when valid, else an error message string.
 */
function wpultra_staging_validate_name(string $name): string {
    if ($name === '') { return 'name is required.'; }
    if (strlen($name) > 40) { return 'name too long (max 40 chars).'; }
    if (!preg_match('/^[a-z0-9-]+$/', $name)) {
        return 'name must contain only lowercase letters, digits and hyphens [a-z0-9-].';
    }
    if ($name[0] === '-' || substr($name, -1) === '-') {
        return 'name must not start or end with a hyphen.';
    }
    return '';
}

/**
 * PURE: rewrite the $table_prefix assignment inside a wp-config.php string to a
 * new prefix, preserving everything else. Matches the canonical WP line
 * (single- or double-quoted, arbitrary spacing, optional leading '$'):
 *
 *   $table_prefix = 'wp_';
 *   $table_prefix  =  "wp_" ;
 *
 * Returns the rewritten config on success, or an error string (prefixed
 * 'error:') when no $table_prefix assignment is found so callers can detect it.
 */
function wpultra_staging_rewrite_config(string $config_php, string $new_prefix) {
    // Match: (optional whitespace) $table_prefix (ws) = (ws) '...' or "..." (ws) ;
    $pattern = '/(\$table_prefix\s*=\s*)([\'"])(?:[^\'"]*)\2(\s*;)/';
    if (!preg_match($pattern, $config_php)) {
        return 'error: no $table_prefix assignment found in wp-config.php';
    }
    // Preserve the original quote style captured in group 2 via a callback.
    $out = preg_replace_callback(
        $pattern,
        static function (array $m) use ($new_prefix): string {
            $quote = $m[2];
            return $m[1] . $quote . $new_prefix . $quote . $m[3];
        },
        $config_php,
        1
    );
    return $out === null ? 'error: config rewrite failed' : $out;
}

/**
 * PURE: should this relative path be EXCLUDED from the staging file copy?
 * Excludes any staging-* dir (matched by $staging_glob, e.g. 'staging-*' or the
 * exact target 'staging-foo'), plus wpultra backups/snapshots, common cache dirs,
 * node_modules, and VCS dirs — matched at ANY path depth.
 *
 * $relpath uses forward slashes, no leading slash (e.g. 'wp-content/cache/x.css').
 */
function wpultra_staging_exclude(string $relpath, string $staging_glob): bool {
    $rel = str_replace('\\', '/', ltrim($relpath, '/'));
    if ($rel === '') { return false; }
    $segments = explode('/', $rel);
    $first = $segments[0];

    // Any top-level staging-* directory (the glob covers both 'staging-*' and an
    // exact 'staging-foo'); also always exclude the generic prefix as a guard so
    // sibling stagings never get recursively copied into a new one.
    if (fnmatch($staging_glob, $first) || str_starts_with($first, 'staging-')) {
        return true;
    }

    // Directory basenames excluded at ANY depth.
    $skip_dirs = [
        'node_modules', '.git', '.svn', '.hg',
        'wpultra-backups', 'wpultra-snapshots', 'wpultra-exports',
        'cache', 'wp-cache', 'et-cache', 'litespeed', 'w3tc-config',
    ];
    foreach ($segments as $seg) {
        if (in_array($seg, $skip_dirs, true)) { return true; }
    }
    return false;
}

/**
 * PURE: build the staging home URL from a production home URL and staging name.
 * home_url is expected WITHOUT a trailing slash (WP's home_url() default);
 * returns home_url . '/staging-<name>'. Any trailing slash on the input is
 * tolerated (stripped first).
 */
function wpultra_staging_home_url(string $home_url, string $name): string {
    return rtrim($home_url, '/') . '/staging-' . $name;
}

/* ============================================================
 * Engine (WP/FS-calling)
 * ============================================================ */

/** Absolute path of the staging directory for $name (no trailing slash). */
function wpultra_staging_dir(string $name): string {
    return rtrim(ABSPATH, '/\\') . '/staging-' . $name;
}

/** @return array|WP_Error */
function wpultra_staging(array $input) {
    $action = (string) ($input['action'] ?? '');
    switch ($action) {
        case 'create': return wpultra_staging_create($input);
        case 'list':   return wpultra_staging_list($input);
        case 'delete': return wpultra_staging_delete($input);
        default:       return wpultra_err('bad_action', "Unknown action '$action'. Use create|list|delete.");
    }
}

/**
 * Copy ABSPATH → target dir, skipping staging/backup/cache/vcs paths. Caps the
 * number of files copied to guard against runaway trees.
 *
 * @return array{files:int,skipped:int}|WP_Error
 */
function wpultra_staging_copy_files(string $target, string $name, bool $skip_uploads) {
    $src = rtrim(ABSPATH, '/\\');
    $glob = 'staging-*';
    $cap = 60000;
    $files = 0; $skipped = 0;

    if (!is_dir($target) && !@mkdir($target, 0755, true) && !is_dir($target)) {
        return wpultra_err('staging_mkdir_failed', "Could not create staging dir: $target");
    }

    try {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS),
                static function ($current) use ($src, $name, $glob, $skip_uploads): bool {
                    $rel = ltrim(str_replace('\\', '/', substr($current->getPathname(), strlen($src))), '/');
                    if (wpultra_staging_exclude($rel, $glob)) { return false; }
                    if ($skip_uploads && (str_starts_with($rel, 'wp-content/uploads/') || $rel === 'wp-content/uploads')) {
                        return false;
                    }
                    return true;
                }
            ),
            \RecursiveIteratorIterator::SELF_FIRST
        );
    } catch (\Throwable $e) {
        return wpultra_err('staging_iterate_failed', 'Could not iterate site files: ' . $e->getMessage());
    }

    foreach ($it as $item) {
        $rel = ltrim(str_replace('\\', '/', substr($item->getPathname(), strlen($src))), '/');
        if ($rel === '') { continue; }
        $dest = $target . '/' . $rel;
        if ($item->isDir()) {
            if (!is_dir($dest)) { @mkdir($dest, 0755, true); }
            continue;
        }
        if ($files >= $cap) {
            return wpultra_err('staging_too_many_files', "Refusing to copy more than $cap files. Use skip_uploads:true or host-level staging for large sites.");
        }
        $ddir = dirname($dest);
        if (!is_dir($ddir)) { @mkdir($ddir, 0755, true); }
        if (@copy($item->getPathname(), $dest)) { $files++; } else { $skipped++; }
    }

    return ['files' => $files, 'skipped' => $skipped];
}

/**
 * Clone every live-prefixed table into the new prefix via CREATE TABLE ... LIKE
 * plus INSERT ... SELECT. Returns the list of NEW table names.
 *
 * @return array{tables:array<int,string>}|WP_Error
 */
function wpultra_staging_clone_tables(string $new_prefix) {
    global $wpdb;
    if (!isset($wpdb)) { return wpultra_err('no_wpdb', '$wpdb unavailable.'); }
    $live_prefix = $wpdb->prefix;

    $like = $wpdb->esc_like($live_prefix) . '%';
    $live_tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $like));
    if (empty($live_tables)) { return wpultra_err('no_tables', 'No live tables found to clone.'); }

    $created = [];
    foreach ($live_tables as $live) {
        $live = (string) $live;
        // Only rewrite the LEADING prefix occurrence.
        if (strpos($live, $live_prefix) !== 0) { continue; }
        $suffix = substr($live, strlen($live_prefix));
        $new = $new_prefix . $suffix;

        $wpdb->query("DROP TABLE IF EXISTS `$new`");
        $ok = $wpdb->query("CREATE TABLE `$new` LIKE `$live`");
        if ($ok === false) {
            return wpultra_err('staging_create_table_failed', "CREATE TABLE `$new` LIKE `$live` failed: " . $wpdb->last_error);
        }
        // INSERT ... SELECT copies all rows in one server-side statement.
        $wpdb->query("INSERT INTO `$new` SELECT * FROM `$live`");
        $created[] = $new;
    }
    return ['tables' => $created];
}

/**
 * Serialized-safe URL/path replace inside the NEW (cloned) tables only.
 * Rewrites the production home_url → staging URL, and the production ABSPATH →
 * staging path, in the cloned posts/postmeta/options tables. Uses
 * wpultra_sr_replace_column() from siteops.php (already loaded).
 *
 * @return array{replacements:int}
 */
function wpultra_staging_rewrite_tables(string $new_prefix, string $home_url, string $staging_url, string $staging_path): int {
    global $wpdb;
    if (!isset($wpdb)) { return 0; }
    $live_abspath = rtrim(str_replace('\\', '/', ABSPATH), '/');
    $stg_abspath  = rtrim(str_replace('\\', '/', $staging_path), '/');

    // pairs: search => replace, longest/most-specific first.
    $pairs = [];
    if ($home_url !== '' && $staging_url !== '' && $home_url !== $staging_url) {
        $pairs[$home_url] = $staging_url;
    }
    if ($live_abspath !== '' && $stg_abspath !== '' && $live_abspath !== $stg_abspath) {
        $pairs[$live_abspath] = $stg_abspath;
    }
    if ($pairs === []) { return 0; }

    // Logical table => [pk, [text columns]] — mirrors siteops' column map but scoped to NEW prefix.
    $targets = [
        'options'  => ['option_id', ['option_value']],
        'postmeta' => ['meta_id',   ['meta_value']],
        'posts'    => ['ID',        ['post_content', 'post_excerpt', 'guid']],
    ];

    $total = 0;
    foreach ($targets as $logical => [$pk, $cols]) {
        $table = $new_prefix . $logical;
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if (!$exists) { continue; }

        $offset = 0; $batch = 500;
        $select = "`$pk`, `" . implode('`, `', $cols) . "`";
        while (true) {
            $rows = $wpdb->get_results("SELECT $select FROM `$table` ORDER BY `$pk` LIMIT $batch OFFSET $offset", ARRAY_A);
            if (!is_array($rows) || $rows === []) { break; }
            foreach ($rows as $row) {
                $updates = [];
                foreach ($cols as $col) {
                    $raw = (string) ($row[$col] ?? '');
                    if ($raw === '') { continue; }
                    $new_val = $raw; $hits = 0;
                    foreach ($pairs as $search => $replace) {
                        [$new_val, $h] = wpultra_sr_replace_column($new_val, (string) $search, (string) $replace);
                        $hits += $h;
                    }
                    if ($hits > 0) { $updates[$col] = $new_val; $total += $hits; }
                }
                if ($updates !== []) { $wpdb->update($table, $updates, [$pk => $row[$pk]]); }
            }
            $offset += $batch;
            if (count($rows) < $batch) { break; }
        }
    }

    // Mark the staging site + de-index it, in the cloned options table.
    $opt = $new_prefix . 'options';
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $opt));
    if ($exists) {
        // blog_public 0 → discourage search engines on the staging copy.
        $wpdb->query($wpdb->prepare("UPDATE `$opt` SET option_value = '0' WHERE option_name = %s", 'blog_public'));
        // Marker option so list()/tooling can identify this as a staging clone.
        $marker = maybe_serialize(['of' => $home_url, 'created' => gmdate('c')]);
        $have = $wpdb->get_var($wpdb->prepare("SELECT option_id FROM `$opt` WHERE option_name = %s", 'wpultra_staging_of'));
        if ($have) {
            $wpdb->query($wpdb->prepare("UPDATE `$opt` SET option_value = %s WHERE option_name = %s", $marker, 'wpultra_staging_of'));
        } else {
            $wpdb->query($wpdb->prepare("INSERT INTO `$opt` (option_name, option_value, autoload) VALUES (%s, %s, 'yes')", 'wpultra_staging_of', $marker));
        }
    }

    return $total;
}

/** Rewrite the staging copy's wp-config.php $table_prefix to the cloned prefix. @return true|WP_Error */
function wpultra_staging_write_config(string $staging_dir, string $new_prefix) {
    $cfg = $staging_dir . '/wp-config.php';
    if (!is_file($cfg)) {
        return wpultra_err('staging_config_missing', "Staging wp-config.php not found at $cfg (nothing to rewrite).");
    }
    $php = (string) file_get_contents($cfg);
    $out = wpultra_staging_rewrite_config($php, $new_prefix);
    if (is_string($out) && str_starts_with($out, 'error:')) {
        return wpultra_err('staging_config_rewrite_failed', $out);
    }
    if (@file_put_contents($cfg, $out) === false) {
        return wpultra_err('staging_config_write_failed', "Could not write $cfg");
    }
    return true;
}

/** @return array|WP_Error */
function wpultra_staging_create(array $input) {
    $name = strtolower(trim((string) ($input['name'] ?? '')));
    $verr = wpultra_staging_validate_name($name);
    if ($verr !== '') { return wpultra_err('invalid_name', $verr); }

    $confirm = ($input['confirm'] ?? false) === true;
    if (!$confirm) {
        return wpultra_err('create_unconfirmed', 'Creating a staging clone copies the whole site and clones every DB table. Re-run with confirm: true.');
    }

    $target = wpultra_staging_dir($name);
    if (is_dir($target)) {
        return wpultra_err('staging_exists', "Target directory already exists: $target. Delete the existing staging first.");
    }

    $skip_uploads = array_key_exists('skip_uploads', $input) ? ($input['skip_uploads'] === true) : true;

    $steps = [];

    // (1) Copy files.
    $copy = wpultra_staging_copy_files($target, $name, $skip_uploads);
    if (is_wp_error($copy)) { return $copy; }
    $steps['copy_files'] = $copy;

    // (2) Clone DB tables.
    $new_prefix = wpultra_staging_prefix($name);
    $clone = wpultra_staging_clone_tables($new_prefix);
    if (is_wp_error($clone)) { return $clone; }
    $steps['clone_tables'] = ['count' => count($clone['tables'])];

    // (3) Serialized-safe URL/path rewrite in the NEW tables only.
    $home = function_exists('home_url') ? (string) home_url() : '';
    $staging_url = wpultra_staging_home_url($home, $name);
    $repl = wpultra_staging_rewrite_tables($new_prefix, $home, $staging_url, $target);
    $steps['rewrite_tables'] = ['replacements' => $repl];

    // (4) Rewrite staging wp-config.php $table_prefix.
    $cfg = wpultra_staging_write_config($target, $new_prefix);
    if (is_wp_error($cfg)) {
        // Non-fatal for the DB/files, but the staging won't boot correctly — surface it.
        $steps['rewrite_config'] = ['error' => $cfg->get_error_message()];
        wpultra_audit_log('staging-clone', "create $name (config rewrite failed)", false);
        return wpultra_err('staging_config_rewrite_failed', $cfg->get_error_message(), $steps);
    }
    $steps['rewrite_config'] = ['ok' => true, 'prefix' => $new_prefix];

    wpultra_audit_log('staging-clone', "create $name -> $target prefix=$new_prefix", true);
    return wpultra_ok([
        'name'    => $name,
        'url'     => $staging_url,
        'path'    => $target,
        'prefix'  => $new_prefix,
        'tables'  => count($clone['tables']),
        'files'   => $copy['files'],
        'skipped' => $copy['skipped'],
        'steps'   => $steps,
    ]);
}

/** @return array|WP_Error */
function wpultra_staging_list(array $input) {
    global $wpdb;
    $base = rtrim(ABSPATH, '/\\');
    $items = [];
    foreach ((array) glob($base . '/staging-*', GLOB_ONLYDIR) as $dir) {
        $name = substr(basename($dir), strlen('staging-'));
        $prefix = wpultra_staging_prefix($name);
        $of = ''; $created = '';
        // Read the marker option from the cloned options table (shares this DB).
        if (isset($wpdb)) {
            $opt = $prefix . 'options';
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $opt));
            if ($exists) {
                $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM `$opt` WHERE option_name = %s", 'wpultra_staging_of'));
                if ($raw) {
                    $m = maybe_unserialize($raw);
                    if (is_array($m)) { $of = (string) ($m['of'] ?? ''); $created = (string) ($m['created'] ?? ''); }
                }
            }
        }
        $items[] = [
            'name'    => $name,
            'path'    => $dir,
            'prefix'  => $prefix,
            'of'      => $of,
            'created' => $created,
        ];
    }
    return wpultra_ok(['stagings' => $items, 'count' => count($items)]);
}

/** @return array|WP_Error */
function wpultra_staging_delete(array $input) {
    global $wpdb;
    $name = strtolower(trim((string) ($input['name'] ?? '')));
    $verr = wpultra_staging_validate_name($name);
    if ($verr !== '') { return wpultra_err('invalid_name', $verr); }

    $confirm = ($input['confirm'] ?? false) === true;
    if (!$confirm) {
        return wpultra_err('delete_unconfirmed', 'Deleting a staging drops its cloned DB tables and removes its directory. Re-run with confirm: true.');
    }

    $dir = wpultra_staging_dir($name);
    $prefix = wpultra_staging_prefix($name);

    // (1) Drop cloned tables.
    $dropped = 0;
    if (isset($wpdb)) {
        $like = $wpdb->esc_like($prefix) . '%';
        $tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $like));
        foreach ((array) $tables as $t) {
            $t = (string) $t;
            // Guard: only drop tables that actually start with the staging prefix.
            if (strpos($t, $prefix) !== 0) { continue; }
            $wpdb->query("DROP TABLE IF EXISTS `$t`");
            $dropped++;
        }
    }

    // (2) Remove directory recursively.
    $removed = is_dir($dir) ? wpultra_staging_rmdir($dir) : false;

    wpultra_audit_log('staging-clone', "delete $name dropped=$dropped dir=" . ($removed ? '1' : '0'), true);
    return wpultra_ok([
        'name'    => $name,
        'deleted' => true,
        'tables'  => $dropped,
        'dir'     => $removed,
        'path'    => $dir,
    ]);
}

/** Recursively delete a directory. Returns true when the dir no longer exists. */
function wpultra_staging_rmdir(string $dir): bool {
    if (!is_dir($dir)) { return true; }
    try {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            if ($item->isDir()) { @rmdir($item->getPathname()); }
            else { @unlink($item->getPathname()); }
        }
    } catch (\Throwable $e) {
        return false;
    }
    @rmdir($dir);
    return !is_dir($dir);
}
