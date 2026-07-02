<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Site-ops engine (Wave 9A): export/import, cron, search-replace, maintenance mode,
 * site-health, db-snapshot. Pure logic (shaping, parsing, serialized-safe replace,
 * SQL statement splitting) is separated so it is testable without WordPress; the
 * WP-calling functions are thin wrappers.
 */

/* ============================================================
 * export-content
 * ============================================================ */

/** @return array|WP_Error */
function wpultra_siteops_export_content(array $input) {
    $post_types = array_values(array_filter(array_map('strval', (array) ($input['post_types'] ?? []))));
    $status     = array_values(array_filter(array_map('strval', (array) ($input['status'] ?? []))));

    if ($post_types === []) {
        $post_types = function_exists('get_post_types')
            ? array_values(get_post_types(['public' => true], 'names'))
            : ['post', 'page'];
    }

    $rel  = (string) ($input['path'] ?? ('wp-content/uploads/wpultra-exports/export-' . gmdate('Ymd-His') . '.xml'));
    $path = wpultra_resolve_path($rel, false);
    if (is_wp_error($path)) { return $path; }

    $dir = dirname($path);
    if (!is_dir($dir) && function_exists('wp_mkdir_p')) { wp_mkdir_p($dir); }
    if (!is_dir($dir)) { return wpultra_err('export_dir_failed', "Could not create export dir: $dir"); }

    $export = ABSPATH . 'wp-admin/includes/export.php';
    if (is_readable($export)) { require_once $export; }
    if (!function_exists('export_wp')) {
        return wpultra_err('exporter_unavailable', 'WordPress export_wp() is unavailable on this install.');
    }

    // export_wp() with a single post_type; loop and concatenate for multiple.
    $xml = '';
    foreach ($post_types as $pt) {
        ob_start();
        export_wp(['content' => $pt]);
        $xml .= (string) ob_get_clean();
    }
    if ($xml === '') { return wpultra_err('export_empty', 'Export produced no output.'); }

    $written = file_put_contents($path, $xml);
    if ($written === false) { return wpultra_err('export_write_failed', "Could not write export file: $path"); }

    wpultra_audit_log('export-content', 'export ' . implode(',', $post_types) . " -> $path", true);
    return wpultra_ok([
        'path'       => $path,
        'size'       => (int) filesize($path),
        'post_types' => $post_types,
        'status'     => $status,
    ]);
}

/* ============================================================
 * import-content
 * ============================================================ */

/** @return array|WP_Error */
function wpultra_siteops_import_content(array $input) {
    $confirm = ($input['confirm'] ?? false) === true;
    if (!$confirm) {
        return wpultra_err('import_unconfirmed', 'Importing content is destructive/bulk. Re-run with confirm: true.');
    }
    $rel  = (string) ($input['path'] ?? '');
    if (trim($rel) === '') { return wpultra_err('missing_path', 'path is required.'); }
    $path = wpultra_resolve_path($rel, true);
    if (is_wp_error($path)) { return $path; }

    // Load the WordPress importer if the plugin provides it.
    if (!class_exists('WP_Import')) {
        $candidates = [
            WP_PLUGIN_DIR . '/wordpress-importer/wordpress-importer.php',
        ];
        foreach ($candidates as $c) {
            if (defined('WP_PLUGIN_DIR') && is_readable($c)) { require_once $c; break; }
        }
    }
    if (!class_exists('WP_Import')) {
        return wpultra_err('importer_unavailable', 'WP_Import unavailable. Install/activate the "WordPress Importer" plugin, then retry.');
    }

    if (!function_exists('kses_remove_filters')) { /* no-op guard */ }
    $importer = new WP_Import();
    $importer->fetch_attachments = false;

    ob_start();
    $importer->import($path);
    ob_end_clean();

    $counts = [
        'posts' => is_array($importer->processed_posts ?? null) ? count($importer->processed_posts) : 0,
        'terms' => is_array($importer->processed_terms ?? null) ? count($importer->processed_terms) : 0,
        'authors' => is_array($importer->processed_authors ?? null) ? count($importer->processed_authors) : 0,
    ];
    wpultra_audit_log('import-content', "import $path posts={$counts['posts']}", true);
    return wpultra_ok(['path' => $path, 'imported' => $counts]);
}

/* ============================================================
 * manage-cron
 * ============================================================ */

/**
 * PURE: flatten the nested structure returned by _get_cron_array() into a flat
 * event list. Structure is: [ timestamp => [ hook => [ signature => [
 *   'schedule' => string|false, 'args' => array, 'interval' => int ] ] ] ].
 * Returns rows with hook, timestamp, next_run (ISO 8601 UTC), schedule, args.
 *
 * @param array $cron  Result of _get_cron_array() (or a test fixture).
 * @return array<int,array<string,mixed>>
 */
function wpultra_siteops_shape_cron(array $cron): array {
    $out = [];
    foreach ($cron as $timestamp => $hooks) {
        if (!is_int($timestamp) && !ctype_digit((string) $timestamp)) { continue; }
        $ts = (int) $timestamp;
        if (!is_array($hooks)) { continue; }
        foreach ($hooks as $hook => $signatures) {
            if (!is_array($signatures)) { continue; }
            foreach ($signatures as $sig => $data) {
                $data = is_array($data) ? $data : [];
                $out[] = [
                    'hook'      => (string) $hook,
                    'timestamp' => $ts,
                    'next_run'  => gmdate('c', $ts),
                    'schedule'  => isset($data['schedule']) && $data['schedule'] !== false ? (string) $data['schedule'] : null,
                    'interval'  => isset($data['interval']) ? (int) $data['interval'] : null,
                    'args'      => array_values((array) ($data['args'] ?? [])),
                    'signature' => (string) $sig,
                ];
            }
        }
    }
    usort($out, static fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);
    return $out;
}

/** @return array|WP_Error */
function wpultra_siteops_manage_cron(array $input) {
    $action = (string) ($input['action'] ?? 'list');

    switch ($action) {
        case 'list':
            if (!function_exists('_get_cron_array')) { return wpultra_err('cron_unavailable', 'Cron API unavailable.'); }
            $events = wpultra_siteops_shape_cron((array) _get_cron_array());
            return wpultra_ok(['events' => $events, 'count' => count($events)]);

        case 'run':
            $hook = (string) ($input['hook'] ?? '');
            if ($hook === '') { return wpultra_err('missing_hook', 'hook is required for run.'); }
            // Schedule the hook to fire immediately, then spawn the cron runner.
            if (function_exists('wp_schedule_single_event')) {
                wp_schedule_single_event(time() - 1, $hook, array_values((array) ($input['args'] ?? [])));
            }
            if (function_exists('spawn_cron')) { spawn_cron(); }
            wpultra_audit_log('manage-cron', "run $hook", true);
            return wpultra_ok(['action' => 'run', 'hook' => $hook, 'spawned' => true]);

        case 'delete':
            $confirm = ($input['confirm'] ?? false) === true;
            if (!$confirm) { return wpultra_err('delete_unconfirmed', 'Deleting a cron event is destructive. Re-run with confirm: true.'); }
            $hook = (string) ($input['hook'] ?? '');
            if ($hook === '') { return wpultra_err('missing_hook', 'hook is required for delete.'); }
            $args = array_values((array) ($input['args'] ?? []));
            $removed = 0;
            if (isset($input['timestamp']) && function_exists('wp_unschedule_event')) {
                $ok = wp_unschedule_event((int) $input['timestamp'], $hook, $args);
                $removed = $ok ? 1 : 0;
            } elseif (function_exists('wp_clear_scheduled_hook')) {
                $removed = (int) wp_clear_scheduled_hook($hook, $args);
            }
            wpultra_audit_log('manage-cron', "delete $hook removed=$removed", true);
            return wpultra_ok(['action' => 'delete', 'hook' => $hook, 'removed' => $removed]);

        default:
            return wpultra_err('bad_action', "Unknown action '$action'.");
    }
}

/* ============================================================
 * search-replace  (serialized-data-safe)
 * ============================================================ */

/**
 * PURE + RECURSIVE: replace $search with $replace inside an already-unserialized
 * value. Walks nested arrays/objects; increments $count for every scalar string
 * hit. Non-string scalars (int/float/bool/null) pass through untouched.
 *
 * WRITE-PATH FLOW (documented, done by the caller wpultra_siteops_sr_run_table):
 *   1. Read the raw DB column string $raw.
 *   2. $data = maybe_unserialize($raw)  — turns serialized blobs into live values.
 *   3. $new  = wpultra_sr_replace_value($data, $search, $replace, $count).
 *   4. If $count > 0: $out = maybe_serialize($new) if $data was array/object,
 *      else the plain replaced string. maybe_serialize re-serializes with the
 *      NEW string lengths, keeping the blob valid (naive str_replace would
 *      corrupt PHP-serialized length prefixes).
 *
 * @param mixed  $value
 * @param string $search
 * @param string $replace
 * @param int    $count   by-ref running total of replacements
 * @return mixed same shape as $value with replacements applied
 */
/**
 * Serialized-detection shim: prefer WP's is_serialized(); fall back to a minimal
 * local check under the test harness where WP is not loaded.
 */
function wpultra_sr_is_serialized(string $value): bool {
    if (function_exists('is_serialized')) { return (bool) is_serialized($value); }
    $value = trim($value);
    if ('N;' === $value) { return true; }
    if (strlen($value) < 4 || ':' !== ($value[1] ?? '')) { return false; }
    return (bool) preg_match('/^[aOsbid]:/', $value);
}

function wpultra_sr_replace_value($value, string $search, string $replace, int &$count) {
    if ($search === '') { return $value; }

    if (is_string($value)) {
        if (strpos($value, $search) === false) { return $value; }
        // A string leaf can ITSELF be serialized data (e.g. a serialized payload
        // stored inside an option array). A length-changing str_replace would
        // corrupt the inner s:N:"..." length prefixes, so unserialize, recurse,
        // and re-serialize instead. WP-CLI does the same.
        if (wpultra_sr_is_serialized($value)) {
            $inner = @unserialize($value, ['allowed_classes' => false]);
            // Guard against unserialize failure: leave a corrupted/unserializable
            // serialized-looking leaf untouched rather than mangle it.
            if ($inner !== false || $value === 'b:0;') {
                $sub = wpultra_sr_replace_value($inner, $search, $replace, $count);
                return serialize($sub);
            }
            return $value;
        }
        $count += substr_count($value, $search);
        return str_replace($search, $replace, $value);
    }

    if (is_array($value)) {
        $out = [];
        foreach ($value as $k => $v) {
            // Keys can themselves contain the search string.
            $newKey = is_string($k) ? wpultra_sr_replace_value($k, $search, $replace, $count) : $k;
            $out[$newKey] = wpultra_sr_replace_value($v, $search, $replace, $count);
        }
        return $out;
    }

    if (is_object($value)) {
        // Preserve concrete object types where possible; clone and mutate public props.
        if ($value instanceof \stdClass) {
            $out = new \stdClass();
            foreach (get_object_vars($value) as $k => $v) {
                $out->$k = wpultra_sr_replace_value($v, $search, $replace, $count);
            }
            return $out;
        }
        // Unknown class: mutate a clone's accessible properties, best-effort.
        $out = clone $value;
        foreach (get_object_vars($out) as $k => $v) {
            $out->$k = wpultra_sr_replace_value($v, $search, $replace, $count);
        }
        return $out;
    }

    return $value; // int/float/bool/null
}

/**
 * PURE: given a raw DB string, apply a serialized-safe replace and return
 * [new_string, hits]. Uses the maybe_(un)serialize round-trip described above.
 * Falls back to sane behavior in tests where WP's maybe_* funcs are stubbed.
 *
 * @return array{0:string,1:int}
 */
function wpultra_sr_replace_column(string $raw, string $search, string $replace): array {
    $count = 0;
    $data  = function_exists('maybe_unserialize') ? maybe_unserialize($raw) : $raw;
    $new   = wpultra_sr_replace_value($data, $search, $replace, $count);
    if ($count === 0) { return [$raw, 0]; }
    if (is_array($new) || is_object($new)) {
        $out = function_exists('maybe_serialize') ? maybe_serialize($new) : serialize($new);
    } else {
        $out = (string) $new;
    }
    return [(string) $out, $count];
}

/** Default tables (unprefixed logical names) for search-replace. */
function wpultra_siteops_sr_default_tables(): array {
    return ['posts', 'postmeta', 'options'];
}

/**
 * Text/blob columns worth scanning per logical table. Anything not listed is left alone.
 */
function wpultra_siteops_sr_table_columns(string $logical): array {
    $map = [
        'posts'    => ['id' => 'ID', 'cols' => ['post_content', 'post_excerpt', 'post_title', 'guid']],
        'postmeta' => ['id' => 'meta_id', 'cols' => ['meta_value']],
        'options'  => ['id' => 'option_id', 'cols' => ['option_value']],
        'comments' => ['id' => 'comment_ID', 'cols' => ['comment_content']],
        'usermeta' => ['id' => 'umeta_id', 'cols' => ['meta_value']],
        'termmeta' => ['id' => 'meta_id', 'cols' => ['meta_value']],
    ];
    return $map[$logical] ?? [];
}

/** @return array|WP_Error */
function wpultra_siteops_search_replace(array $input) {
    global $wpdb;
    $search  = (string) ($input['search'] ?? '');
    $replace = (string) ($input['replace'] ?? '');
    if ($search === '') { return wpultra_err('missing_search', 'search is required.'); }

    $dry_run = array_key_exists('dry_run', $input) ? ($input['dry_run'] === true) : true;
    $confirm = ($input['confirm'] ?? false) === true;
    if (!$dry_run && !$confirm) {
        return wpultra_err('replace_unconfirmed', 'Live search-replace is destructive. Re-run with confirm: true (dry_run:false requires confirm).');
    }

    $tables = array_values(array_filter(array_map('strval', (array) ($input['tables'] ?? []))));
    if ($tables === []) { $tables = wpultra_siteops_sr_default_tables(); }

    $results = [];
    foreach ($tables as $logical) {
        $spec = wpultra_siteops_sr_table_columns($logical);
        if ($spec === []) { $results[$logical] = ['skipped' => 'no_column_map']; continue; }
        $results[$logical] = wpultra_siteops_sr_run_table($logical, $spec, $search, $replace, $dry_run);
    }

    wpultra_audit_log('search-replace', "search-replace '$search' dry_run=" . ($dry_run ? '1' : '0'), true);
    return wpultra_ok(['dry_run' => $dry_run, 'tables' => $results]);
}

/**
 * Iterate one table in batches of 500, applying serialized-safe replace to each
 * mapped column. Returns match/replace counts. Thin WP wrapper (uses $wpdb).
 */
function wpultra_siteops_sr_run_table(string $logical, array $spec, string $search, string $replace, bool $dry_run): array {
    global $wpdb;
    if (!isset($wpdb)) { return ['matches' => 0, 'replaced' => 0]; }
    $table = $wpdb->prefix . $logical;
    $pk    = $spec['id'];
    $cols  = $spec['cols'];

    $matches = 0; $replaced = 0; $offset = 0; $batch = 500;
    $select = "`$pk`, `" . implode('`, `', $cols) . "`";

    while (true) {
        $sql  = "SELECT $select FROM `$table` ORDER BY `$pk` LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $batch, $offset), ARRAY_A);
        if (!is_array($rows) || $rows === []) { break; }

        foreach ($rows as $row) {
            $updates = [];
            foreach ($cols as $col) {
                $raw = (string) ($row[$col] ?? '');
                if ($raw === '') { continue; }
                [$new, $hits] = wpultra_sr_replace_column($raw, $search, $replace);
                if ($hits > 0) {
                    $matches += $hits;
                    if (!$dry_run) { $updates[$col] = $new; }
                }
            }
            if (!$dry_run && $updates !== []) {
                $ok = $wpdb->update($table, $updates, [$pk => $row[$pk]]);
                if ($ok !== false) { $replaced += count($updates); }
            }
        }

        $offset += $batch;
        if (count($rows) < $batch) { break; }
    }

    return ['matches' => $matches, 'replaced' => $dry_run ? 0 : $replaced];
}

/* ============================================================
 * maintenance-mode
 * ============================================================ */

/** Far-future timestamp: keeps maintenance ON past WP's 600s auto-expiry window. */
function wpultra_siteops_maintenance_persistent_ts(): int {
    // WP compares: time() - $upgrading > 600  => expired.
    // A future $upgrading makes (time() - future) negative, so it never exceeds 600.
    $year = defined('YEAR_IN_SECONDS') ? YEAR_IN_SECONDS : 31536000;
    return time() + 10 * $year;
}

function wpultra_siteops_maintenance_file(): string {
    return rtrim(ABSPATH, '/\\') . '/.maintenance';
}

/** @return array|WP_Error */
function wpultra_siteops_maintenance_mode(array $input) {
    $action = (string) ($input['action'] ?? 'status');
    $file   = wpultra_siteops_maintenance_file();

    switch ($action) {
        case 'status':
            return wpultra_ok(['enabled' => is_file($file), 'file' => $file]);

        case 'enable':
            $persistent = ($input['persistent'] ?? false) === true;
            $ts = $persistent ? wpultra_siteops_maintenance_persistent_ts() : time();
            $body = "<?php \$upgrading = $ts;\n";
            $msg = (string) ($input['message'] ?? '');
            if ($msg !== '') {
                // Store custom message as a comment for a maintenance drop-in to read.
                $body .= '// wpultra_message: ' . str_replace(["\r", "\n"], ' ', $msg) . "\n";
                if (function_exists('update_option')) { update_option('wpultra_maintenance_message', $msg, false); }
            }
            $ok = file_put_contents($file, $body);
            if ($ok === false) { return wpultra_err('maintenance_write_failed', "Could not write $file"); }
            wpultra_audit_log('maintenance-mode', 'enable persistent=' . ($persistent ? '1' : '0'), true);
            return wpultra_ok(['enabled' => true, 'persistent' => $persistent, 'file' => $file]);

        case 'disable':
            if (is_file($file)) { @unlink($file); }
            if (function_exists('delete_option')) { delete_option('wpultra_maintenance_message'); }
            wpultra_audit_log('maintenance-mode', 'disable', true);
            return wpultra_ok(['enabled' => false, 'file' => $file]);

        default:
            return wpultra_err('bad_action', "Unknown action '$action'.");
    }
}

/* ============================================================
 * site-health
 * ============================================================ */

/**
 * PURE: shape one WP_Site_Health test result into a compact row. WP test results
 * look like ['label'=>..., 'status'=>'good|recommended|critical', 'badge'=>..., ...].
 */
function wpultra_siteops_shape_health_test(string $slug, array $result): array {
    $status = (string) ($result['status'] ?? 'recommended');
    return [
        'slug'   => $slug,
        'status' => in_array($status, ['good', 'recommended', 'critical'], true) ? $status : 'recommended',
        'label'  => (string) ($result['label'] ?? $slug),
    ];
}

/** PURE: count how many shaped rows are critical. */
function wpultra_siteops_health_critical_count(array $tests): int {
    $n = 0;
    foreach ($tests as $t) { if (($t['status'] ?? '') === 'critical') { $n++; } }
    return $n;
}

/** @return array|WP_Error */
function wpultra_siteops_site_health(array $input) {
    $health = ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
    if (is_readable($health)) { require_once $health; }
    if (!class_exists('WP_Site_Health')) {
        return wpultra_err('site_health_unavailable', 'WP_Site_Health is unavailable on this install.');
    }
    $sh = method_exists('WP_Site_Health', 'get_instance') ? WP_Site_Health::get_instance() : new WP_Site_Health();
    $registry = method_exists($sh, 'get_tests') ? $sh->get_tests() : ['direct' => []];
    $direct = (array) ($registry['direct'] ?? []);

    $tests = [];
    foreach ($direct as $slug => $test) {
        $cb = $test['test'] ?? null;
        $result = null;
        if (is_callable($cb)) {
            $result = $cb;
        } elseif (is_string($cb) && method_exists($sh, "get_test_$cb")) {
            $result = [$sh, "get_test_$cb"];
        }
        if ($result === null) { continue; }
        try {
            $r = is_callable($result) ? call_user_func($result) : null;
        } catch (\Throwable $e) {
            continue;
        }
        if (is_array($r)) { $tests[] = wpultra_siteops_shape_health_test((string) $slug, $r); }
    }

    return wpultra_ok([
        'tests'          => $tests,
        'count'          => count($tests),
        'critical_count' => wpultra_siteops_health_critical_count($tests),
    ]);
}

/* ============================================================
 * db-snapshot
 * ============================================================ */

/**
 * PURE: split a SQL dump into individual statements. Splits on a ';' that ends a
 * statement, but NEVER when the ';' is inside a single-quoted string ('…', with
 * '\'' and '' escapes) or inside a backtick-quoted identifier (`…`). Returns the
 * list of trimmed, non-empty statements (without the trailing ';').
 *
 * @return array<int,string>
 */
function wpultra_siteops_split_sql(string $dump): array {
    $statements = [];
    $buf = '';
    $len = strlen($dump);
    $inSingle = false;  // inside '...'
    $inBacktick = false; // inside `...`

    for ($i = 0; $i < $len; $i++) {
        $ch = $dump[$i];

        if ($inSingle) {
            $buf .= $ch;
            if ($ch === '\\') {
                // Backslash escape: consume the next char literally.
                if ($i + 1 < $len) { $buf .= $dump[++$i]; }
                continue;
            }
            if ($ch === "'") {
                // Could be a closing quote, or a doubled '' escape.
                if ($i + 1 < $len && $dump[$i + 1] === "'") {
                    $buf .= $dump[++$i]; // consume the second quote, stay inside
                } else {
                    $inSingle = false;
                }
            }
            continue;
        }

        if ($inBacktick) {
            $buf .= $ch;
            if ($ch === '`') { $inBacktick = false; }
            continue;
        }

        // Not inside any quote.
        if ($ch === "'") { $inSingle = true; $buf .= $ch; continue; }
        if ($ch === '`') { $inBacktick = true; $buf .= $ch; continue; }

        if ($ch === ';') {
            $stmt = trim($buf);
            if ($stmt !== '') { $statements[] = $stmt; }
            $buf = '';
            continue;
        }

        $buf .= $ch;
    }

    $tail = trim($buf);
    if ($tail !== '') { $statements[] = $tail; }
    return $statements;
}

function wpultra_siteops_snapshot_dir(): string {
    $base = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : (rtrim(ABSPATH, '/\\') . '/wp-content');
    return rtrim($base, '/\\') . '/uploads/wpultra-snapshots';
}

/** Write index.php + .htaccess deny into the snapshot dir. */
function wpultra_siteops_protect_dir(string $dir): void {
    if (!is_dir($dir)) {
        if (function_exists('wp_mkdir_p')) { wp_mkdir_p($dir); } else { @mkdir($dir, 0755, true); }
    }
    $idx = $dir . '/index.php';
    if (!is_file($idx)) { @file_put_contents($idx, "<?php // Silence is golden.\n"); }
    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) { @file_put_contents($ht, "Deny from all\n"); }
}

/** PURE: sanitize a snapshot name to a safe filename stem. */
function wpultra_siteops_snapshot_name(string $name): string {
    $name = preg_replace('/[^A-Za-z0-9_\-]/', '-', $name);
    $name = trim((string) $name, '-');
    return $name === '' ? ('snapshot-' . gmdate('Ymd-His')) : $name;
}

/** @return array|WP_Error */
function wpultra_siteops_db_snapshot(array $input) {
    global $wpdb;
    $action = (string) ($input['action'] ?? 'list');
    $dir    = wpultra_siteops_snapshot_dir();

    switch ($action) {
        case 'list':
            $items = [];
            if (is_dir($dir)) {
                foreach ((array) glob($dir . '/*.sql.gz') as $f) {
                    $items[] = [
                        'snapshot' => basename($f, '.sql.gz'),
                        'path'     => $f,
                        'size'     => (int) filesize($f),
                        'modified' => gmdate('c', (int) filemtime($f)),
                    ];
                }
            }
            return wpultra_ok(['snapshots' => $items, 'count' => count($items)]);

        case 'create':
            wpultra_siteops_protect_dir($dir);
            $name = wpultra_siteops_snapshot_name((string) ($input['snapshot'] ?? ('snapshot-' . gmdate('Ymd-His'))));
            $path = $dir . '/' . $name . '.sql.gz';
            $res  = wpultra_siteops_dump_tables($path, (array) ($input['tables'] ?? []));
            if (is_wp_error($res)) { return $res; }
            wpultra_audit_log('db-snapshot', "create $name", true);
            return wpultra_ok(array_merge(['snapshot' => $name, 'path' => $path, 'size' => (int) @filesize($path)], $res));

        case 'restore':
            if (($input['confirm'] ?? false) !== true) {
                return wpultra_err('restore_unconfirmed', 'Restoring a DB snapshot is destructive. Re-run with confirm: true.');
            }
            $name = wpultra_siteops_snapshot_name((string) ($input['snapshot'] ?? ''));
            $path = $dir . '/' . $name . '.sql.gz';
            if (!is_file($path)) { return wpultra_err('snapshot_not_found', "Snapshot not found: $name"); }
            $res = wpultra_siteops_restore_dump($path);
            if (is_wp_error($res)) { return $res; }
            wpultra_audit_log('db-snapshot', "restore $name", true);
            return wpultra_ok(array_merge(['snapshot' => $name, 'restored' => true], $res));

        case 'delete':
            if (($input['confirm'] ?? false) !== true) {
                return wpultra_err('delete_unconfirmed', 'Deleting a snapshot is destructive. Re-run with confirm: true.');
            }
            $name = wpultra_siteops_snapshot_name((string) ($input['snapshot'] ?? ''));
            $path = $dir . '/' . $name . '.sql.gz';
            if (!is_file($path)) { return wpultra_err('snapshot_not_found', "Snapshot not found: $name"); }
            @unlink($path);
            wpultra_audit_log('db-snapshot', "delete $name", true);
            return wpultra_ok(['snapshot' => $name, 'deleted' => true]);

        default:
            return wpultra_err('bad_action', "Unknown action '$action'.");
    }
}

/**
 * Dump the given (or all prefixed) tables to a gzip .sql.gz file via $wpdb.
 * @return array|WP_Error  ['tables' => [...]] on success.
 */
function wpultra_siteops_dump_tables(string $path, array $tables) {
    global $wpdb;
    if (!isset($wpdb)) { return wpultra_err('no_wpdb', '$wpdb unavailable.'); }

    if ($tables === []) {
        $like = $wpdb->esc_like($wpdb->prefix) . '%';
        $tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $like));
    }
    if (empty($tables)) { return wpultra_err('no_tables', 'No tables matched for snapshot.'); }

    $gz = gzopen($path, 'wb9');
    if ($gz === false) { return wpultra_err('snapshot_open_failed', "Could not open $path for writing."); }

    gzwrite($gz, "-- WP-Ultra-MCP snapshot " . gmdate('c') . "\nSET FOREIGN_KEY_CHECKS=0;\n");
    foreach ($tables as $table) {
        $table = (string) $table;
        gzwrite($gz, "\nDROP TABLE IF EXISTS `$table`;\n");
        $create = $wpdb->get_row("SHOW CREATE TABLE `$table`", ARRAY_N);
        if (is_array($create) && isset($create[1])) { gzwrite($gz, $create[1] . ";\n"); }

        $offset = 0; $batch = 500;
        while (true) {
            $rows = $wpdb->get_results("SELECT * FROM `$table` LIMIT $batch OFFSET $offset", ARRAY_A);
            if (!is_array($rows) || $rows === []) { break; }
            foreach ($rows as $row) {
                $vals = [];
                foreach ($row as $v) {
                    $vals[] = $v === null ? 'NULL' : "'" . $wpdb->_real_escape((string) $v) . "'";
                }
                gzwrite($gz, "INSERT INTO `$table` VALUES (" . implode(',', $vals) . ");\n");
            }
            $offset += $batch;
            if (count($rows) < $batch) { break; }
        }
    }
    gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
    gzclose($gz);
    return ['tables' => array_map('strval', $tables)];
}

/**
 * Restore a .sql.gz dump: read, gunzip, split into statements, execute each.
 * @return array|WP_Error ['statements' => int]
 */
function wpultra_siteops_restore_dump(string $path) {
    global $wpdb;
    if (!isset($wpdb)) { return wpultra_err('no_wpdb', '$wpdb unavailable.'); }
    $raw = function_exists('gzdecode') ? @gzdecode((string) file_get_contents($path)) : false;
    if ($raw === false) {
        // Try streaming gzopen fallback.
        $raw = '';
        $gz = gzopen($path, 'rb');
        if ($gz === false) { return wpultra_err('snapshot_read_failed', "Could not read $path"); }
        while (!gzeof($gz)) { $raw .= gzread($gz, 8192); }
        gzclose($gz);
    }
    $statements = wpultra_siteops_split_sql((string) $raw);
    $n = 0;
    foreach ($statements as $stmt) {
        if (str_starts_with($stmt, '--')) { continue; }
        $wpdb->query($stmt);
        $n++;
    }
    return ['statements' => $n];
}
