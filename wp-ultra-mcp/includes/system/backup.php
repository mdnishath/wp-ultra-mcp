<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Full-site backup + restore engine (Roadmap #22).
 *
 * Bundles the database and the wp-content file tree into a single self-contained
 * backup directory under uploads/wpultra-backups/<name>/ containing:
 *   - db.sql.gz   : gzip SQL dump of all prefixed tables (REUSES the proven
 *                   wpultra_siteops_dump_tables() / wpultra_siteops_restore_dump()
 *                   from includes/system/siteops.php — no duplication).
 *   - files.zip   : ZipArchive of WP_CONTENT_DIR (excluding the backup/snapshot/
 *                   export dirs, cache dirs, node_modules; uploads optional).
 *
 * PURE logic (path exclusion, name sanitization, stat shaping) is factored out so
 * it is unit-testable without WordPress or a real filesystem.
 *
 * Depends on siteops.php being loaded (bootstrap loads it in the `system` and
 * `database` groups). The db functions are looked up defensively so the engine
 * degrades to a clear error rather than fataling if siteops is disabled.
 */

/* ============================================================
 * PURE helpers (unit-tested)
 * ============================================================ */

/**
 * PURE: sanitize a backup name. Only [a-z0-9-] survive; anything else is an error
 * (unlike snapshots, which silently coerce — a backup is a heavier, directory-level
 * artifact, so we reject surprising names rather than mangle them). Uppercase is
 * lowercased first. Empty / all-illegal input is an error.
 *
 * @return string|WP_Error the safe name, or WP_Error('bad_name', ...)
 */
function wpultra_backup_name_sanitize(string $name) {
    $name = strtolower(trim($name));
    if ($name === '') {
        return wpultra_err('bad_name', 'Backup name is required.');
    }
    if (!preg_match('/^[a-z0-9-]+$/', $name)) {
        return wpultra_err('bad_name', "Backup name may only contain lowercase letters, digits and hyphens ([a-z0-9-]); got: $name");
    }
    // Reject a name that is only hyphens (would create a hidden/odd dir).
    if (trim($name, '-') === '') {
        return wpultra_err('bad_name', 'Backup name must contain at least one letter or digit.');
    }
    return $name;
}

/**
 * PURE: default excludes list (top-level wp-content-relative dir names). These are
 * WP-Ultra-MCP's own artifact dirs plus common regenerable/vendor dirs. All matching
 * is prefix-based on forward-slash relative paths (see wpultra_backup_should_exclude).
 *
 * @return array<int,string>
 */
function wpultra_backup_default_excludes(): array {
    return [
        'uploads/wpultra-backups',
        'uploads/wpultra-snapshots',
        'uploads/wpultra-exports',
        'cache',
        'uploads/cache',
        'node_modules',
        'upgrade',
        'wp-rocket-config',
    ];
}

/**
 * PURE: should the given wp-content-relative path be excluded from the file zip?
 *
 * $relpath is a forward-slash path relative to WP_CONTENT_DIR (e.g. "plugins/foo/bar.php"
 * or "uploads/2024/01/img.png"). Matching is prefix-based: a path is excluded if it
 * equals an exclude entry or is nested under it ("<exclude>/..."). Backslashes are
 * normalized to forward slashes and any leading "./" or "/" is stripped so callers
 * can pass raw iterator paths. When $skip_uploads is true, the entire "uploads" tree
 * is excluded too.
 *
 * Also matches a bare directory-name segment anywhere in the path for the non-pathy
 * excludes (node_modules, cache) so a nested plugins/x/node_modules is caught.
 */
function wpultra_backup_should_exclude(string $relpath, array $excludes, bool $skip_uploads): bool {
    $rel = str_replace('\\', '/', $relpath);
    $rel = ltrim($rel, '/');
    if (str_starts_with($rel, './')) { $rel = substr($rel, 2); }
    $rel = ltrim($rel, '/');
    if ($rel === '') { return false; }

    if ($skip_uploads && ($rel === 'uploads' || str_starts_with($rel, 'uploads/'))) {
        return true;
    }

    $segments = explode('/', $rel);
    foreach ($excludes as $ex) {
        $ex = trim(str_replace('\\', '/', (string) $ex), '/');
        if ($ex === '') { continue; }
        // Full prefix match (handles path-style excludes like uploads/wpultra-backups).
        if ($rel === $ex || str_starts_with($rel, $ex . '/')) { return true; }
        // Bare-name excludes (node_modules, cache) match any path segment.
        if (strpos($ex, '/') === false && in_array($ex, $segments, true)) { return true; }
    }
    return false;
}

/**
 * PURE: shape a raw backup-dir stat fixture into the public listing row. Accepts an
 * associative array describing one backup directory and returns the normalized shape
 * used by list(). Missing numeric fields default to 0; missing name defaults to ''.
 *
 * Input keys (all optional): name, path, db_bytes, files_bytes, total_bytes, modified (epoch int).
 */
function wpultra_backup_shape(array $stat): array {
    $db    = (int) ($stat['db_bytes'] ?? 0);
    $files = (int) ($stat['files_bytes'] ?? 0);
    $total = array_key_exists('total_bytes', $stat) ? (int) $stat['total_bytes'] : ($db + $files);
    $mtime = (int) ($stat['modified'] ?? 0);
    return [
        'name'        => (string) ($stat['name'] ?? ''),
        'path'        => (string) ($stat['path'] ?? ''),
        'db_bytes'    => $db,
        'files_bytes' => $files,
        'total_bytes' => $total,
        'modified'    => $mtime > 0 ? gmdate('c', $mtime) : null,
    ];
}

/* ============================================================
 * WP-facing helpers
 * ============================================================ */

/** Base dir that holds every backup: uploads/wpultra-backups. */
function wpultra_backup_base_dir(): string {
    $base = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : (rtrim(ABSPATH, '/\\') . '/wp-content');
    return rtrim($base, '/\\') . '/uploads/wpultra-backups';
}

/** WP_CONTENT_DIR (or the conventional fallback). */
function wpultra_backup_content_dir(): string {
    $base = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : (rtrim(ABSPATH, '/\\') . '/wp-content');
    return rtrim($base, '/\\');
}

/**
 * Ensure a directory exists and is protected against direct web access with an
 * index.php + a "Deny from all" .htaccess (mirrors siteops' snapshot protection).
 * Reuses wpultra_siteops_protect_dir() when available; otherwise inlines the same.
 */
function wpultra_backup_protect_dir(string $dir): void {
    if (function_exists('wpultra_siteops_protect_dir')) {
        wpultra_siteops_protect_dir($dir);
        return;
    }
    if (!is_dir($dir)) {
        if (function_exists('wp_mkdir_p')) { wp_mkdir_p($dir); } else { @mkdir($dir, 0755, true); }
    }
    $idx = $dir . '/index.php';
    if (!is_file($idx)) { @file_put_contents($idx, "<?php // Silence is golden.\n"); }
    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) { @file_put_contents($ht, "Deny from all\n"); }
}

/** Hard cap on how many files we will zip; beyond this we bail with advice. */
function wpultra_backup_file_cap(): int {
    return 50000;
}

/* ============================================================
 * create
 * ============================================================ */

/**
 * Create a full backup (db.sql.gz + files.zip) under uploads/wpultra-backups/<name>/.
 *
 * @param string $name  backup name ([a-z0-9-]).
 * @param array  $opts  { skip_uploads?: bool }
 * @return array|WP_Error { name, path, db_bytes, files_bytes, file_count } on success.
 */
function wpultra_backup_create(string $name, array $opts = []) {
    @set_time_limit(300);

    $safe = wpultra_backup_name_sanitize($name);
    if (is_wp_error($safe)) { return $safe; }

    if (!class_exists('ZipArchive')) {
        return wpultra_err('zip_unavailable', 'The PHP ZipArchive extension is required for file backups but is not installed.');
    }
    if (!function_exists('wpultra_siteops_dump_tables')) {
        return wpultra_err('db_engine_unavailable', 'The DB dump engine (siteops.php) is not loaded; enable the system/database ability group.');
    }

    $skip_uploads = ($opts['skip_uploads'] ?? false) === true;
    $base = wpultra_backup_base_dir();
    $dir  = $base . '/' . $safe;

    // Protect the base (so an attacker can't fetch any backup) and create the target.
    wpultra_backup_protect_dir($base);
    if (!is_dir($dir)) {
        if (function_exists('wp_mkdir_p')) { wp_mkdir_p($dir); } else { @mkdir($dir, 0755, true); }
    }
    if (!is_dir($dir)) { return wpultra_err('backup_dir_failed', "Could not create backup dir: $dir"); }

    // ---- 1. DB dump (reuse siteops) ----
    $db_path = $dir . '/db.sql.gz';
    $db_res  = wpultra_siteops_dump_tables($db_path, []); // [] => all prefixed tables
    if (is_wp_error($db_res)) { return $db_res; }
    $db_bytes = (int) @filesize($db_path);

    // ---- 2. files.zip (walk WP_CONTENT_DIR) ----
    $content = wpultra_backup_content_dir();
    $zip_path = $dir . '/files.zip';
    $files_res = wpultra_backup_zip_content($content, $zip_path, $skip_uploads);
    if (is_wp_error($files_res)) {
        // Clean up the partial zip so a re-run isn't confused by half-written state.
        if (is_file($zip_path)) { @unlink($zip_path); }
        return $files_res;
    }
    $files_bytes = (int) @filesize($zip_path);

    wpultra_audit_log('site-backup', "create $safe files={$files_res['file_count']} db_bytes=$db_bytes", true);

    return wpultra_ok([
        'name'        => $safe,
        'path'        => $dir,
        'db_bytes'    => $db_bytes,
        'files_bytes' => $files_bytes,
        'file_count'  => (int) $files_res['file_count'],
        'skip_uploads' => $skip_uploads,
    ]);
}

/**
 * Walk $content (WP_CONTENT_DIR) recursively and add every non-excluded file to a
 * new zip at $zip_path with RELATIVE forward-slash entry names. Enforces the file
 * cap. Returns ['file_count'=>int] or WP_Error.
 */
function wpultra_backup_zip_content(string $content, string $zip_path, bool $skip_uploads) {
    $excludes = wpultra_backup_default_excludes();
    $content  = rtrim(str_replace('\\', '/', $content), '/');

    if (!is_dir($content)) {
        return wpultra_err('content_missing', "wp-content directory not found: $content");
    }

    $zip = new ZipArchive();
    if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return wpultra_err('zip_open_failed', "Could not open zip for writing: $zip_path");
    }

    $prefix_len = strlen($content) + 1;
    $count = 0;

    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($content, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
    } catch (\Throwable $e) {
        $zip->close();
        if (is_file($zip_path)) { @unlink($zip_path); }
        return wpultra_err('walk_failed', 'Could not walk wp-content: ' . $e->getMessage());
    }

    foreach ($it as $fileInfo) {
        if (!$fileInfo->isFile()) { continue; }
        $abs = str_replace('\\', '/', $fileInfo->getPathname());
        // Compute the wp-content-relative, forward-slash entry name.
        $rel = substr($abs, $prefix_len);
        if ($rel === false || $rel === '') { continue; }

        if (wpultra_backup_should_exclude($rel, $excludes, $skip_uploads)) { continue; }

        if ($count >= wpultra_backup_file_cap()) {
            $zip->close();
            if (is_file($zip_path)) { @unlink($zip_path); }
            return wpultra_err(
                'too_many_files',
                'File count exceeds ' . wpultra_backup_file_cap() . '. Re-run with skip_uploads:true (or prune large regenerable dirs) to keep the archive manageable.'
            );
        }

        $zip->addFile($fileInfo->getPathname(), $rel);
        $count++;
    }

    // Guarantee a valid (possibly empty) archive with at least a marker so extractTo works.
    if ($count === 0) {
        $zip->addFromString('.wpultra-backup-empty', "No wp-content files matched the include set.\n");
    }

    if (!$zip->close()) {
        if (is_file($zip_path)) { @unlink($zip_path); }
        return wpultra_err('zip_close_failed', 'Failed to finalize the file archive (zip close returned false).');
    }

    return ['file_count' => $count];
}

/* ============================================================
 * list
 * ============================================================ */

/** List all backups with sizes + dates. @return array */
function wpultra_backup_list(): array {
    $base = wpultra_backup_base_dir();
    $items = [];
    if (is_dir($base)) {
        foreach ((array) glob($base . '/*', GLOB_ONLYDIR) as $d) {
            $name = basename($d);
            $db   = is_file($d . '/db.sql.gz') ? (int) filesize($d . '/db.sql.gz') : 0;
            $files = is_file($d . '/files.zip') ? (int) filesize($d . '/files.zip') : 0;
            // Directory mtime tracks the most recent write inside it well enough for listing.
            $mtime = (int) @filemtime($d);
            $items[] = wpultra_backup_shape([
                'name'        => $name,
                'path'        => $d,
                'db_bytes'    => $db,
                'files_bytes' => $files,
                'modified'    => $mtime,
            ]);
        }
    }
    return wpultra_ok(['backups' => $items, 'count' => count($items)]);
}

/* ============================================================
 * delete
 * ============================================================ */

/** Delete a backup directory (and its contents). Requires confirm. @return array|WP_Error */
function wpultra_backup_delete(string $name, bool $confirm = false) {
    if ($confirm !== true) {
        return wpultra_err('delete_unconfirmed', 'Deleting a backup is destructive. Re-run with confirm: true.');
    }
    $safe = wpultra_backup_name_sanitize($name);
    if (is_wp_error($safe)) { return $safe; }

    $dir = wpultra_backup_base_dir() . '/' . $safe;
    if (!is_dir($dir)) { return wpultra_err('backup_not_found', "Backup not found: $safe"); }

    $removed = wpultra_backup_rrmdir($dir);
    wpultra_audit_log('site-backup', "delete $safe files=$removed", true);
    return wpultra_ok(['name' => $safe, 'deleted' => true, 'files_removed' => $removed]);
}

/** Recursively delete a directory; returns the number of filesystem entries removed. */
function wpultra_backup_rrmdir(string $dir): int {
    $removed = 0;
    if (!is_dir($dir)) { return 0; }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $entry) {
        if ($entry->isDir()) { @rmdir($entry->getPathname()); }
        else { @unlink($entry->getPathname()); }
        $removed++;
    }
    @rmdir($dir);
    return $removed;
}

/* ============================================================
 * restore
 * ============================================================ */

/**
 * Restore parts of a backup. $parts is a subset of ['db','files']; each part is
 * best-effort and reports its own result. Requires confirm.
 *
 * DANGER: restoring 'files' extracts files.zip over WP_CONTENT_DIR, OVERWRITING
 * existing files — including plugins, and INCLUDING THIS PLUGIN, mid-request. Any
 * PHP files that WordPress has not yet loaded may change underfoot. This is
 * documented in the returned payload so the caller understands the risk.
 *
 * @param string   $name    backup name.
 * @param string[] $parts   subset of ['db','files']; default both.
 * @param bool     $confirm must be true.
 * @return array|WP_Error
 */
function wpultra_backup_restore(string $name, array $parts = ['db', 'files'], bool $confirm = false) {
    @set_time_limit(300);

    if ($confirm !== true) {
        return wpultra_err('restore_unconfirmed', 'Restoring a backup overwrites your database and/or files. Re-run with confirm: true.');
    }
    $safe = wpultra_backup_name_sanitize($name);
    if (is_wp_error($safe)) { return $safe; }

    $dir = wpultra_backup_base_dir() . '/' . $safe;
    if (!is_dir($dir)) { return wpultra_err('backup_not_found', "Backup not found: $safe"); }

    // Normalize + validate the requested parts.
    $parts = array_values(array_intersect(['db', 'files'], array_map('strval', $parts)));
    if ($parts === []) { $parts = ['db', 'files']; }

    $results = [];

    // ---- DB ----
    if (in_array('db', $parts, true)) {
        $db_path = $dir . '/db.sql.gz';
        if (!is_file($db_path)) {
            $results['db'] = ['ok' => false, 'error' => 'db.sql.gz missing from this backup'];
        } elseif (!function_exists('wpultra_siteops_restore_dump')) {
            $results['db'] = ['ok' => false, 'error' => 'DB restore engine (siteops.php) is not loaded'];
        } else {
            $r = wpultra_siteops_restore_dump($db_path);
            if (is_wp_error($r)) {
                $results['db'] = ['ok' => false, 'error' => $r->get_error_message()];
            } else {
                $results['db'] = array_merge(['ok' => true], is_array($r) ? $r : []);
            }
        }
    }

    // ---- Files ----
    if (in_array('files', $parts, true)) {
        $zip_path = $dir . '/files.zip';
        if (!class_exists('ZipArchive')) {
            $results['files'] = ['ok' => false, 'error' => 'ZipArchive extension unavailable'];
        } elseif (!is_file($zip_path)) {
            $results['files'] = ['ok' => false, 'error' => 'files.zip missing from this backup'];
        } else {
            $zip = new ZipArchive();
            if ($zip->open($zip_path) !== true) {
                $results['files'] = ['ok' => false, 'error' => 'Could not open files.zip'];
            } else {
                $entries = $zip->numFiles;
                $ok = $zip->extractTo(wpultra_backup_content_dir());
                $zip->close();
                $results['files'] = [
                    'ok'      => (bool) $ok,
                    'entries' => (int) $entries,
                    'warning' => 'Files were extracted OVER wp-content, overwriting existing files including plugins. This plugin may have been overwritten mid-request; if behavior looks inconsistent, reload the page / restart PHP and re-verify.',
                ];
            }
        }
    }

    $all_ok = true;
    foreach ($results as $r) { if (($r['ok'] ?? false) !== true) { $all_ok = false; } }

    wpultra_audit_log('site-backup', 'restore ' . $safe . ' parts=' . implode(',', $parts) . ' ok=' . ($all_ok ? '1' : '0'), $all_ok);

    return wpultra_ok([
        'name'    => $safe,
        'parts'   => $parts,
        'results' => $results,
        'all_ok'  => $all_ok,
    ]);
}
