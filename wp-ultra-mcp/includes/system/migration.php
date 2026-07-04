<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Site migration engine (Roadmap G2): full export on the OLD host → move the file →
 * import on the NEW host, adapting URLs (serialized-safe search-replace) and prefix.
 *
 * A "migration package" is nothing more than a full backup (db + files, produced by
 * the proven includes/system/backup.php packager) PLUS a manifest.json describing the
 * SOURCE site so the destination can adapt:
 *   - home_url / siteurl  → the URL search-replace pair set (old → new)
 *   - abspath             → informational (path rewrites are out of scope; noted)
 *   - wp_version / php_version / prefix → the readiness check
 *   - plugins / theme     → the readiness check (missing-plugin warnings)
 *
 * The package lives under uploads/wpultra-backups/<name>/ (created by the backup
 * engine) with a sibling manifest.json written next to db.sql.gz + files.zip. That
 * means `list`/`delete-package` here are thin views over the backup base dir, and the
 * import path restores via wpultra_backup_restore() then rewrites URLs via the
 * serialized-safe search-replace engine in siteops.php.
 *
 * DESIGN: PURE logic (manifest shaping, URL-pair generation, readiness compare, name
 * sanitization) is factored out and unit-tested without WordPress. The WP-facing
 * wrappers are thin and defer to backup.php + siteops.php — no duplication of the
 * heavy packaging / dump / restore / replace code.
 *
 * Depends on backup.php (packager) and siteops.php (search-replace). Both are looked
 * up defensively so the engine degrades to a clear error rather than fataling.
 */

/* ============================================================
 * PURE helpers (unit-tested)
 * ============================================================ */

/**
 * PURE: sanitize a migration/package name. Delegates to the backup-name rules when
 * available (only [a-z0-9-], lowercased, not all-hyphens); otherwise applies the same
 * rules locally so this is testable/usable even if backup.php is not loaded.
 *
 * @return string|WP_Error the safe name, or WP_Error('bad_name', ...)
 */
function wpultra_migrate_name_sanitize(string $name) {
    if (function_exists('wpultra_backup_name_sanitize')) {
        return wpultra_backup_name_sanitize($name);
    }
    $name = strtolower(trim($name));
    if ($name === '') {
        return wpultra_err('bad_name', 'Package name is required.');
    }
    if (!preg_match('/^[a-z0-9-]+$/', $name)) {
        return wpultra_err('bad_name', "Package name may only contain lowercase letters, digits and hyphens ([a-z0-9-]); got: $name");
    }
    if (trim($name, '-') === '') {
        return wpultra_err('bad_name', 'Package name must contain at least one letter or digit.');
    }
    return $name;
}

/**
 * PURE: build the source-describing manifest from a plain site array. The input mirrors
 * what the WP wrapper collects (home_url(), site_url(), ABSPATH, get_bloginfo('version'),
 * PHP_VERSION, $wpdb->prefix, active plugins/theme). Every field is normalized to a
 * predictable shape so the destination can compare deterministically.
 *
 * Output shape (stable):
 *   {
 *     schema:   'wpultra-migrate/1',
 *     created:  ISO 8601 UTC (from input 'created' or now),
 *     home_url, site_url, abspath, wp_version, php_version, prefix, multisite (bool),
 *     plugins:  string[] (sorted, deduped),
 *     theme:    string,
 *     package:  string (the sibling backup name, if provided)
 *   }
 *
 * @param array $site  raw site descriptor (all keys optional)
 */
function wpultra_migrate_build_manifest(array $site): array {
    $plugins = array_values(array_unique(array_filter(array_map('strval', (array) ($site['plugins'] ?? [])))));
    sort($plugins);

    $created = (string) ($site['created'] ?? '');
    if ($created === '') { $created = gmdate('c'); }

    return [
        'schema'      => 'wpultra-migrate/1',
        'created'     => $created,
        'home_url'    => rtrim((string) ($site['home_url'] ?? ''), '/'),
        'site_url'    => rtrim((string) ($site['site_url'] ?? ''), '/'),
        'abspath'     => (string) ($site['abspath'] ?? ''),
        'wp_version'  => (string) ($site['wp_version'] ?? ''),
        'php_version' => (string) ($site['php_version'] ?? ''),
        'prefix'      => (string) ($site['prefix'] ?? ''),
        'multisite'   => ($site['multisite'] ?? false) === true,
        'plugins'     => $plugins,
        'theme'       => (string) ($site['theme'] ?? ''),
        'package'     => (string) ($site['package'] ?? ''),
    ];
}

/**
 * PURE: the ordered find/replace pairs to rewrite the OLD host's URLs to the NEW host's
 * during import. Produces the http, https and protocol-relative (//host) variants for
 * BOTH the home_url and the site_url (siteurl), longest-first so the more specific
 * scheme'd forms replace before the bare-host form and there is no partial clobber.
 *
 * Rules:
 *   - Trailing slashes are stripped from all four inputs first (so we never leave a
 *     dangling "//" and so "http://old/" and "http://old" behave identically).
 *   - When old == new for a URL, that URL contributes NO pairs (a self-pair would be a
 *     no-op that also risks double-processing). If both home and site match, the result
 *     is [] and the caller can skip the replace entirely.
 *   - Duplicate pairs (home_url == site_url, common on most installs) are deduped.
 *   - Each pair is [search, replace]; ordering is: for each URL, https variant, then
 *     http variant, then protocol-relative variant. This keeps scheme-qualified matches
 *     ahead of the scheme-less "//host" match.
 *
 * @return array<int,array{0:string,1:string}>
 */
function wpultra_migrate_url_pairs(string $old_home, string $new_home, string $old_site, string $new_site): array {
    $pairs = [];
    $seen  = [];

    $add = static function (string $old, string $new) use (&$pairs, &$seen): void {
        $old = rtrim(trim($old), '/');
        $new = rtrim(trim($new), '/');
        if ($old === '' || $new === '' || $old === $new) { return; }

        // Strip any scheme to derive the bare host+path, then rebuild each variant so
        // an input like "https://old.com" and "http://old.com" both yield all three.
        $old_bare = preg_replace('#^https?://#i', '', $old);
        $new_bare = preg_replace('#^https?://#i', '', $new);
        if ($old_bare === '' || $new_bare === '' || $old_bare === $new_bare) { return; }

        $variants = [
            ['https://' . $old_bare, 'https://' . $new_bare],
            ['http://' . $old_bare,  'http://' . $new_bare],
            ['//' . $old_bare,       '//' . $new_bare],
        ];
        foreach ($variants as [$s, $r]) {
            $key = $s . "\0" . $r;
            if (isset($seen[$key])) { continue; }
            $seen[$key] = true;
            $pairs[] = [$s, $r];
        }
    };

    $add($old_home, $new_home);
    $add($old_site, $new_site);

    return $pairs;
}

/**
 * PURE: compare a source manifest against THIS destination site and return a list of
 * readiness findings. This is the safe, no-write "trust-builder" run performed on the
 * NEW host before importing. Each finding is
 *   { check: string, status: 'ok'|'warn'|'blocker', detail: string }.
 *
 * Rules:
 *   - php: destination PHP older than the source's major.minor is a BLOCKER (code built
 *     for a newer runtime may fatal on a downgrade). Equal or newer is OK.
 *   - wp: a DIFFERENT major version (either direction) is a WARN. Same major is OK.
 *   - prefix: a differing table prefix is a WARN (the packaged DB carries the source
 *     prefix; after restore the destination wp-config's $table_prefix must match, or be
 *     updated — noted in detail).
 *   - plugins: any source plugin not present on the destination is a single WARN listing
 *     them (they will be restored as files by the package, but if they were network- or
 *     symlink-installed this flags the gap).
 *
 * @param array $src_manifest  a manifest produced by wpultra_migrate_build_manifest()
 * @param array $dst           this site: { php_version, wp_version, prefix, plugins[] }
 * @return array<int,array{check:string,status:string,detail:string}>
 */
function wpultra_migrate_compat(array $src_manifest, array $dst): array {
    $findings = [];

    // ---- PHP ----
    $src_php = (string) ($src_manifest['php_version'] ?? '');
    $dst_php = (string) ($dst['php_version'] ?? '');
    if ($src_php === '' || $dst_php === '') {
        $findings[] = ['check' => 'php', 'status' => 'warn', 'detail' => 'PHP version unknown on one side; could not compare.'];
    } else {
        $s = wpultra_migrate_version_parts($src_php);
        $d = wpultra_migrate_version_parts($dst_php);
        $cmp = wpultra_migrate_version_cmp_minor($d, $s); // destination vs source
        if ($cmp < 0) {
            $findings[] = ['check' => 'php', 'status' => 'blocker', 'detail' => "Destination PHP $dst_php is OLDER than source PHP $src_php. Code built for the newer runtime may fatal. Upgrade PHP on the destination first."];
        } else {
            $findings[] = ['check' => 'php', 'status' => 'ok', 'detail' => "Destination PHP $dst_php >= source PHP $src_php."];
        }
    }

    // ---- WordPress major ----
    $src_wp = (string) ($src_manifest['wp_version'] ?? '');
    $dst_wp = (string) ($dst['wp_version'] ?? '');
    if ($src_wp === '' || $dst_wp === '') {
        $findings[] = ['check' => 'wp', 'status' => 'warn', 'detail' => 'WordPress version unknown on one side; could not compare.'];
    } else {
        $sMaj = wpultra_migrate_version_parts($src_wp)[0];
        $dMaj = wpultra_migrate_version_parts($dst_wp)[0];
        if ($sMaj !== $dMaj) {
            $findings[] = ['check' => 'wp', 'status' => 'warn', 'detail' => "WordPress major version differs (source $src_wp vs destination $dst_wp). Migrate between matching majors when possible."];
        } else {
            $findings[] = ['check' => 'wp', 'status' => 'ok', 'detail' => "WordPress majors match (source $src_wp, destination $dst_wp)."];
        }
    }

    // ---- Table prefix ----
    $src_prefix = (string) ($src_manifest['prefix'] ?? '');
    $dst_prefix = (string) ($dst['prefix'] ?? '');
    if ($src_prefix !== '' && $dst_prefix !== '' && $src_prefix !== $dst_prefix) {
        $findings[] = ['check' => 'prefix', 'status' => 'warn', 'detail' => "Table prefix differs (source '$src_prefix' vs destination '$dst_prefix'). The restored DB carries the source prefix; update the destination wp-config.php \$table_prefix to '$src_prefix' after import (or the site will not find its tables)."];
    } else {
        $findings[] = ['check' => 'prefix', 'status' => 'ok', 'detail' => 'Table prefix matches (or was not provided).'];
    }

    // ---- Missing plugins ----
    $src_plugins = array_values(array_filter(array_map('strval', (array) ($src_manifest['plugins'] ?? []))));
    $dst_plugins = array_values(array_filter(array_map('strval', (array) ($dst['plugins'] ?? []))));
    $missing = array_values(array_diff($src_plugins, $dst_plugins));
    if ($missing !== []) {
        $findings[] = ['check' => 'plugins', 'status' => 'warn', 'detail' => 'Plugins on source not present on destination (' . count($missing) . '): ' . implode(', ', $missing) . '. They ship in the package files, but verify after import.'];
    } else {
        $findings[] = ['check' => 'plugins', 'status' => 'ok', 'detail' => 'All source plugins are present on the destination.'];
    }

    return $findings;
}

/**
 * PURE: split a dotted version string into [major, minor, patch] ints. Missing parts
 * default to 0. Non-numeric leading tokens (e.g. "8.2.30+1") are truncated at the first
 * non-digit within each part.
 *
 * @return array{0:int,1:int,2:int}
 */
function wpultra_migrate_version_parts(string $version): array {
    $parts = explode('.', trim($version));
    $out = [0, 0, 0];
    for ($i = 0; $i < 3; $i++) {
        $seg = $parts[$i] ?? '0';
        // Keep leading digits only ("30+1" -> "30", "beta" -> "").
        preg_match('/^\d+/', $seg, $m);
        $out[$i] = (int) ($m[0] ?? 0);
    }
    return $out;
}

/**
 * PURE: compare two [major, minor, patch] arrays at MAJOR.MINOR granularity (patch is
 * ignored — a PHP patch bump is not a compatibility break). Returns -1 if $a < $b,
 * 0 if equal, 1 if $a > $b.
 */
function wpultra_migrate_version_cmp_minor(array $a, array $b): int {
    $am = (int) ($a[0] ?? 0); $an = (int) ($a[1] ?? 0);
    $bm = (int) ($b[0] ?? 0); $bn = (int) ($b[1] ?? 0);
    if ($am !== $bm) { return $am <=> $bm; }
    return $an <=> $bn;
}

/**
 * PURE: does a findings list contain any blocker? Convenience for the import gate.
 */
function wpultra_migrate_has_blocker(array $findings): bool {
    foreach ($findings as $f) {
        if (($f['status'] ?? '') === 'blocker') { return true; }
    }
    return false;
}

/**
 * PURE: which logical tables the URL rewrite should sweep. Mirrors the siteops default
 * plus comments (menus/widgets/embeds often store absolute URLs there too). Kept here
 * so the import plan can preview it without touching WordPress.
 *
 * @return array<int,string>
 */
function wpultra_migrate_sr_tables(): array {
    return ['posts', 'postmeta', 'options', 'comments'];
}

/* ============================================================
 * WP-facing helpers (thin — defer to backup.php + siteops.php)
 * ============================================================ */

/** The base dir where packages (backups) live: uploads/wpultra-backups. */
function wpultra_migrate_base_dir(): string {
    if (function_exists('wpultra_backup_base_dir')) { return wpultra_backup_base_dir(); }
    $base = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : (rtrim(ABSPATH, '/\\') . '/wp-content');
    return rtrim($base, '/\\') . '/uploads/wpultra-backups';
}

/** Path to a package's sibling manifest.json. */
function wpultra_migrate_manifest_path(string $safe_name): string {
    return wpultra_migrate_base_dir() . '/' . $safe_name . '/manifest.json';
}

/**
 * Collect THIS site's descriptor (for building a manifest or running a compat check).
 * @return array
 */
function wpultra_migrate_current_site(): array {
    $plugins = [];
    if (function_exists('get_option')) {
        $plugins = (array) get_option('active_plugins', []);
        if (function_exists('is_multisite') && is_multisite() && function_exists('get_site_option')) {
            $plugins = array_merge($plugins, array_keys((array) get_site_option('active_sitewide_plugins', [])));
        }
    }
    global $wpdb;
    return [
        'home_url'    => function_exists('home_url') ? home_url() : '',
        'site_url'    => function_exists('site_url') ? site_url() : '',
        'abspath'     => defined('ABSPATH') ? ABSPATH : '',
        'wp_version'  => function_exists('get_bloginfo') ? get_bloginfo('version') : (isset($GLOBALS['wp_version']) ? (string) $GLOBALS['wp_version'] : ''),
        'php_version' => PHP_VERSION,
        'prefix'      => isset($wpdb) ? (string) $wpdb->prefix : '',
        'multisite'   => function_exists('is_multisite') ? is_multisite() : false,
        'plugins'     => array_values(array_map('strval', $plugins)),
        'theme'       => function_exists('get_stylesheet') ? (string) get_stylesheet() : '',
    ];
}

/**
 * EXPORT side: build a migration package = full backup (db + files via backup.php) +
 * a sibling manifest.json describing this source site.
 *
 * @param array $opts { name?: string, include_uploads?: bool }
 * @return array|WP_Error
 */
function wpultra_migrate_package(array $opts = []) {
    if (!function_exists('wpultra_backup_create')) {
        return wpultra_err('backup_engine_unavailable', 'The backup engine (includes/system/backup.php) is not loaded; cannot build a migration package.');
    }

    $name = (string) ($opts['name'] ?? ('migrate-' . gmdate('Ymd-His')));
    $safe = wpultra_migrate_name_sanitize($name);
    if (is_wp_error($safe)) { return $safe; }

    // include_uploads defaults TRUE (a migration should be complete); map to backup's
    // skip_uploads (inverse). Callers on huge media libraries can pass include_uploads:false.
    $include_uploads = ($opts['include_uploads'] ?? true) !== false;

    $backup = wpultra_backup_create($safe, ['skip_uploads' => !$include_uploads]);
    if (is_wp_error($backup)) { return $backup; }

    $site = wpultra_migrate_current_site();
    $site['package'] = $safe;
    $manifest = wpultra_migrate_build_manifest($site);

    $mpath = wpultra_migrate_manifest_path($safe);
    $json  = function_exists('wp_json_encode') ? wp_json_encode($manifest, JSON_PRETTY_PRINT) : json_encode($manifest, JSON_PRETTY_PRINT);
    $written = @file_put_contents($mpath, (string) $json);
    if ($written === false) {
        return wpultra_err('manifest_write_failed', "Package files were written but the manifest could not be saved to: $mpath");
    }

    wpultra_audit_log('site-migrate', "export package=$safe include_uploads=" . ($include_uploads ? '1' : '0'), true);

    return wpultra_ok([
        'action'          => 'export',
        'package'         => $safe,
        'path'            => wpultra_migrate_base_dir() . '/' . $safe,
        'manifest_path'   => $mpath,
        'manifest'        => $manifest,
        'db_bytes'        => (int) ($backup['db_bytes'] ?? 0),
        'files_bytes'     => (int) ($backup['files_bytes'] ?? 0),
        'file_count'      => (int) ($backup['file_count'] ?? 0),
        'include_uploads' => $include_uploads,
        'download_note'   => 'Move the entire package directory (db.sql.gz + files.zip + manifest.json) to the new host under wp-content/uploads/wpultra-backups/' . $safe . '/, then run action:check followed by action:import there.',
    ]);
}

/**
 * Load + decode a package's manifest.json. @return array|WP_Error
 */
function wpultra_migrate_read_manifest(string $safe_name) {
    $mpath = wpultra_migrate_manifest_path($safe_name);
    if (!is_file($mpath)) {
        return wpultra_err('manifest_missing', "No manifest.json found for package '$safe_name' at $mpath. Was the package built by this plugin and copied whole?");
    }
    $raw = (string) @file_get_contents($mpath);
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return wpultra_err('manifest_invalid', "manifest.json for '$safe_name' is not valid JSON.");
    }
    return $data;
}

/**
 * CHECK side: readiness findings for a manifest against THIS site. Safe / no writes.
 * $manifest may be the package name (string in $opts['package']) OR an inline manifest
 * array in $opts['manifest'].
 *
 * @param array $opts { package?: string, manifest?: array }
 * @return array|WP_Error
 */
function wpultra_migrate_check(array $opts = []) {
    $manifest = null;
    if (isset($opts['manifest']) && is_array($opts['manifest'])) {
        $manifest = $opts['manifest'];
    } elseif (($opts['package'] ?? '') !== '') {
        $safe = wpultra_migrate_name_sanitize((string) $opts['package']);
        if (is_wp_error($safe)) { return $safe; }
        $manifest = wpultra_migrate_read_manifest($safe);
        if (is_wp_error($manifest)) { return $manifest; }
    }
    if (!is_array($manifest)) {
        return wpultra_err('missing_manifest', 'Provide either package (a package name whose manifest.json will be read) or manifest (an inline manifest object) to check.');
    }

    $dst = wpultra_migrate_current_site();
    $findings = wpultra_migrate_compat($manifest, $dst);
    $pairs = wpultra_migrate_url_pairs(
        (string) ($manifest['home_url'] ?? ''), (string) ($dst['home_url'] ?? ''),
        (string) ($manifest['site_url'] ?? ''), (string) ($dst['site_url'] ?? '')
    );

    return wpultra_ok([
        'action'       => 'check',
        'findings'     => $findings,
        'has_blocker'  => wpultra_migrate_has_blocker($findings),
        'url_pairs'    => $pairs,
        'source'       => [
            'home_url' => (string) ($manifest['home_url'] ?? ''),
            'site_url' => (string) ($manifest['site_url'] ?? ''),
        ],
        'destination'  => [
            'home_url' => (string) ($dst['home_url'] ?? ''),
            'site_url' => (string) ($dst['site_url'] ?? ''),
        ],
    ]);
}

/**
 * IMPORT side: restore a package on THIS (new) host, then rewrite the source URLs to
 * this site's URLs (serialized-safe). HEAVILY confirm-gated: dry_run:false + confirm:true
 * overwrites this site's DB and wp-content.
 *
 * @param array $opts { package: string, dry_run?: bool, confirm?: bool }
 * @return array|WP_Error
 */
function wpultra_migrate_import(array $opts = []) {
    $safe = wpultra_migrate_name_sanitize((string) ($opts['package'] ?? ''));
    if (is_wp_error($safe)) { return $safe; }

    // dry_run defaults TRUE (preview first) — a live import is the single most
    // destructive thing this plugin does.
    $dry_run = array_key_exists('dry_run', $opts) ? ($opts['dry_run'] === true) : true;
    $confirm = ($opts['confirm'] ?? false) === true;

    $manifest = wpultra_migrate_read_manifest($safe);
    if (is_wp_error($manifest)) { return $manifest; }

    $dst = wpultra_migrate_current_site();
    $findings = wpultra_migrate_compat($manifest, $dst);
    $pairs = wpultra_migrate_url_pairs(
        (string) ($manifest['home_url'] ?? ''), (string) ($dst['home_url'] ?? ''),
        (string) ($manifest['site_url'] ?? ''), (string) ($dst['site_url'] ?? '')
    );
    $has_blocker = wpultra_migrate_has_blocker($findings);

    $plan = [
        'restore'   => ['db', 'files'],
        'url_pairs' => $pairs,
        'sr_tables' => wpultra_migrate_sr_tables(),
        'findings'  => $findings,
    ];

    if ($dry_run) {
        return wpultra_ok([
            'action'      => 'import',
            'dry_run'     => true,
            'package'     => $safe,
            'plan'        => $plan,
            'has_blocker' => $has_blocker,
            'note'        => 'Preview only — nothing was changed. Re-run with dry_run:false and confirm:true to restore the package and rewrite URLs. This OVERWRITES this site\'s database and wp-content.',
        ]);
    }

    if (!$confirm) {
        return wpultra_err('import_unconfirmed', 'A live import OVERWRITES this site\'s database and wp-content, then rewrites URLs. Re-run with dry_run:false AND confirm:true.');
    }
    if ($has_blocker) {
        return wpultra_err('import_blocked', 'The readiness check found a BLOCKER (see findings via action:check). Resolve it (e.g. PHP downgrade) before importing.', ['findings' => $findings]);
    }

    if (!function_exists('wpultra_backup_restore')) {
        return wpultra_err('backup_engine_unavailable', 'The backup engine (includes/system/backup.php) is not loaded; cannot restore the package.');
    }

    @set_time_limit(600);

    // ---- 1. Restore db + files from the package (reuse backup.php). ----
    $restore = wpultra_backup_restore($safe, ['db', 'files'], true);
    if (is_wp_error($restore)) { return $restore; }

    // ---- 2. Rewrite the source URLs to this site's URLs (serialized-safe). ----
    $sr = ['skipped' => 'no_pairs'];
    if ($pairs !== [] && function_exists('wpultra_siteops_search_replace')) {
        $sr = [];
        foreach ($pairs as [$search, $replace]) {
            $res = wpultra_siteops_search_replace([
                'search'  => $search,
                'replace' => $replace,
                'tables'  => wpultra_migrate_sr_tables(),
                'dry_run' => false,
                'confirm' => true,
            ]);
            $sr[] = is_wp_error($res)
                ? ['pair' => [$search, $replace], 'error' => $res->get_error_message()]
                : ['pair' => [$search, $replace], 'tables' => $res['tables'] ?? []];
        }
    } elseif ($pairs !== []) {
        $sr = ['skipped' => 'search_replace_engine_unavailable'];
    }

    wpultra_audit_log('site-migrate', "import package=$safe pairs=" . count($pairs), (bool) ($restore['all_ok'] ?? false));

    return wpultra_ok([
        'action'         => 'import',
        'dry_run'        => false,
        'package'        => $safe,
        'restore'        => $restore,
        'url_rewrite'    => $sr,
        'url_pairs'      => $pairs,
        'findings'       => $findings,
        'warning'        => 'Files were extracted OVER wp-content and the DB was replaced. If the table prefix differed (see findings), update wp-config.php \$table_prefix now. Flush caches and re-log in; permalinks may need re-saving.',
    ]);
}

/**
 * LIST side: available packages (backup dirs that carry a manifest.json are marked as
 * migration-ready). @return array
 */
function wpultra_migrate_list(): array {
    $base = wpultra_migrate_base_dir();
    $items = [];
    if (is_dir($base)) {
        foreach ((array) glob($base . '/*', GLOB_ONLYDIR) as $d) {
            $name = basename($d);
            $has_manifest = is_file($d . '/manifest.json');
            $db    = is_file($d . '/db.sql.gz') ? (int) filesize($d . '/db.sql.gz') : 0;
            $files = is_file($d . '/files.zip') ? (int) filesize($d . '/files.zip') : 0;
            $items[] = [
                'package'      => $name,
                'path'         => $d,
                'has_manifest' => $has_manifest,
                'db_bytes'     => $db,
                'files_bytes'  => $files,
                'total_bytes'  => $db + $files,
                'modified'     => gmdate('c', (int) @filemtime($d)),
            ];
        }
    }
    return wpultra_ok(['packages' => $items, 'count' => count($items)]);
}

/**
 * DELETE side: remove a package (backup dir + its manifest). Requires confirm.
 * @return array|WP_Error
 */
function wpultra_migrate_delete(string $name, bool $confirm = false) {
    if ($confirm !== true) {
        return wpultra_err('delete_unconfirmed', 'Deleting a migration package is destructive. Re-run with confirm: true.');
    }
    $safe = wpultra_migrate_name_sanitize($name);
    if (is_wp_error($safe)) { return $safe; }

    // Reuse the backup engine's deleter (it removes the whole dir incl. manifest.json).
    if (function_exists('wpultra_backup_delete')) {
        $res = wpultra_backup_delete($safe, true);
        if (is_wp_error($res)) { return $res; }
        wpultra_audit_log('site-migrate', "delete-package $safe", true);
        return wpultra_ok(['action' => 'delete-package', 'package' => $safe, 'deleted' => true]);
    }

    $dir = wpultra_migrate_base_dir() . '/' . $safe;
    if (!is_dir($dir)) { return wpultra_err('package_not_found', "Package not found: $safe"); }
    return wpultra_err('backup_engine_unavailable', 'The backup engine (includes/system/backup.php) is not loaded; cannot delete the package directory.');
}

/* ============================================================
 * Runtime contract
 * ============================================================ */

/**
 * Boot hook (cheap, idempotent). Called by the controller. Cleans up abandoned
 * half-written migration manifests older than 30 days is out of scope; for now this is
 * a no-op placeholder so the controller's boot loop has a stable symbol to call. The
 * packages themselves are managed via the ability's list/delete-package actions.
 */
function wpultra_migrate_boot(): void {
    // Intentionally a no-op: packages are user-managed artifacts and the backup engine
    // already protects the base dir. Kept for a stable controller runtime contract.
}
