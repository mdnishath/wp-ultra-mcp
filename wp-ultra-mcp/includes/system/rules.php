<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Server rules manager (Wave: htaccess/nginx rules — security headers, caching).
 *
 * Writes a single managed block into the site's .htaccess between
 * "# BEGIN WPUltra" / "# END WPUltra" markers using WordPress core's
 * insert_with_markers()/extract_from_markers() (wp-admin/includes/misc.php).
 * Content OUTSIDE the markers (WordPress's own rewrite block, other plugins'
 * blocks, etc.) is never touched.
 *
 * Safety: before every write, the current .htaccess is copied to a sibling
 * .htaccess.wpultra-backup file (last-write-wins, single slot — simple and
 * predictable). This ability does NOT integrate with the universal undo
 * engine (includes/undo/engine.php) by design; `restore-backup` is its own
 * explicit action that copies the backup back over .htaccess.
 *
 * nginx: when there is no .htaccess AND the server is detected as nginx
 * (via $_SERVER['SERVER_SOFTWARE']), get()/set() operate in a read-only
 * "compose text" mode — the composed rules are returned as text with a note
 * that they must be added to the server config manually; set() never writes
 * a file in that mode.
 *
 * Pure builder + validator functions (the test core) have no WordPress
 * dependency; the get/set/clear/restore-backup functions are thin wrappers
 * that touch the filesystem and call into WP core's marker helpers.
 */

const WPULTRA_RULES_MARKER = 'WPUltra';

/* ------------------------------------------------------------------ *
 * PURE: preset builders — each returns a plain array of lines (no
 * leading/trailing blank lines; the composer adds spacing/comments).
 * ------------------------------------------------------------------ */

/** Security headers via mod_headers. */
function wpultra_rules_preset_security_headers(): array {
    return [
        '<IfModule mod_headers.c>',
        '    Header always set X-Frame-Options "SAMEORIGIN"',
        '    Header always set X-Content-Type-Options "nosniff"',
        '    Header always set Referrer-Policy "strict-origin-when-cross-origin"',
        '    Header always set Permissions-Policy "geolocation=(), microphone=(), camera=()"',
        '</IfModule>',
    ];
}

/** Browser caching via mod_expires (common static asset types). */
function wpultra_rules_preset_browser_caching(): array {
    return [
        '<IfModule mod_expires.c>',
        '    ExpiresActive On',
        '    ExpiresByType image/jpg "access plus 1 year"',
        '    ExpiresByType image/jpeg "access plus 1 year"',
        '    ExpiresByType image/gif "access plus 1 year"',
        '    ExpiresByType image/png "access plus 1 year"',
        '    ExpiresByType image/webp "access plus 1 year"',
        '    ExpiresByType image/svg+xml "access plus 1 year"',
        '    ExpiresByType image/x-icon "access plus 1 year"',
        '    ExpiresByType text/css "access plus 1 month"',
        '    ExpiresByType text/javascript "access plus 1 month"',
        '    ExpiresByType application/javascript "access plus 1 month"',
        '    ExpiresByType application/pdf "access plus 1 month"',
        '    ExpiresByType font/woff "access plus 1 year"',
        '    ExpiresByType font/woff2 "access plus 1 year"',
        '    ExpiresByType application/font-woff "access plus 1 year"',
        '    ExpiresByType application/vnd.ms-fontobject "access plus 1 year"',
        '    ExpiresDefault "access plus 2 days"',
        '</IfModule>',
    ];
}

/** Gzip/deflate compression via mod_deflate. */
function wpultra_rules_preset_gzip(): array {
    return [
        '<IfModule mod_deflate.c>',
        '    AddOutputFilterByType DEFLATE text/plain',
        '    AddOutputFilterByType DEFLATE text/html',
        '    AddOutputFilterByType DEFLATE text/xml',
        '    AddOutputFilterByType DEFLATE text/css',
        '    AddOutputFilterByType DEFLATE text/javascript',
        '    AddOutputFilterByType DEFLATE application/xml',
        '    AddOutputFilterByType DEFLATE application/xhtml+xml',
        '    AddOutputFilterByType DEFLATE application/rss+xml',
        '    AddOutputFilterByType DEFLATE application/javascript',
        '    AddOutputFilterByType DEFLATE application/json',
        '    AddOutputFilterByType DEFLATE image/svg+xml',
        '    AddOutputFilterByType DEFLATE font/woff',
        '    AddOutputFilterByType DEFLATE font/woff2',
        '</IfModule>',
    ];
}

/** Block XML-RPC (common brute-force / amplification target). */
function wpultra_rules_preset_block_xmlrpc(): array {
    return [
        '<Files "xmlrpc.php">',
        '    Order allow,deny',
        '    Deny from all',
        '</Files>',
    ];
}

/** Disable directory listing. */
function wpultra_rules_preset_disable_indexes(): array {
    return ['Options -Indexes'];
}

/** Map of preset name => builder callable. Pure. */
function wpultra_rules_preset_registry(): array {
    return [
        'security-headers' => 'wpultra_rules_preset_security_headers',
        'browser-caching'  => 'wpultra_rules_preset_browser_caching',
        'gzip'             => 'wpultra_rules_preset_gzip',
        'block-xmlrpc'     => 'wpultra_rules_preset_block_xmlrpc',
        'disable-indexes'  => 'wpultra_rules_preset_disable_indexes',
    ];
}

/** Pure: the list of valid preset names, in canonical order. */
function wpultra_rules_known_presets(): array {
    return array_keys(wpultra_rules_preset_registry());
}

/**
 * PURE: compose the managed block's line list from preset names + custom
 * lines. Dedupes identical lines (keeping the first occurrence) so requesting
 * the same preset twice — or a custom line that duplicates a preset line —
 * doesn't bloat the file. Each preset's lines are preceded by a "# <name>"
 * comment so the block stays human-readable. Unknown preset names are
 * silently skipped (the ability layer validates before calling this).
 *
 * @param array $preset_names
 * @param array $custom_lines
 * @return array<int,string>
 */
function wpultra_rules_compose(array $preset_names, array $custom_lines = []): array {
    $registry = wpultra_rules_preset_registry();
    $out = [];
    $seen = [];

    foreach ($preset_names as $name) {
        $name = (string) $name;
        if (!isset($registry[$name])) { continue; }
        $builder = $registry[$name];
        $lines = (array) call_user_func($builder);
        if ($lines === []) { continue; }

        $out[] = "# $name";
        $seen["# $name"] = true;
        foreach ($lines as $line) {
            $line = (string) $line;
            if (isset($seen[$line])) { continue; }
            $seen[$line] = true;
            $out[] = $line;
        }
    }

    if ($custom_lines !== []) {
        $out[] = '# custom';
        $seen['# custom'] = true;
        foreach ($custom_lines as $line) {
            $line = (string) $line;
            if (isset($seen[$line])) { continue; }
            $seen[$line] = true;
            $out[] = $line;
        }
    }

    return $out;
}

/**
 * PURE: validate a candidate line list before it is written. Returns true
 * when every line is acceptable, or a human-readable error string describing
 * the first rejected line.
 *
 * Rejects:
 *  - null bytes / control chars (bypass extension/parsing checks elsewhere)
 *  - `php_value` / `php_flag` directives (change PHP ini settings from
 *    .htaccess — a common privilege-escalation / misconfig vector)
 *  - `<Files ...>` blocks that look like they inject a PHP handler for a
 *    non-PHP extension (AddHandler/SetHandler application/x-httpd-php style
 *    smuggling), i.e. lines containing both "Files" and ".php" together with
 *    a handler directive is caught via the AddHandler/SetHandler check below;
 *    additionally, `<Files` blocks with wildcard-only patterns pointing at
 *    handler directives are covered by the AddHandler/SetHandler line check.
 *  - `AddHandler`/`SetHandler` lines that map to a php/cgi handler (classic
 *    "upload.jpg" -> executed-as-php trick).
 *
 * Allows standard directives: Header, ExpiresActive/ExpiresByType/
 * ExpiresDefault, AddOutputFilterByType, Options -Indexes, Order/Deny/Allow,
 * <IfModule>/<Files>/comments, RewriteEngine/RewriteCond/RewriteRule (needed
 * by WP's own block and many legitimate rules).
 *
 * @param array $lines
 * @return true|string
 */
function wpultra_rules_validate_lines(array $lines) {
    foreach ($lines as $i => $line) {
        $line = (string) $line;

        if (strpos($line, "\0") !== false || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $line)) {
            return "Line " . ($i + 1) . ' contains illegal control characters / null bytes.';
        }

        if (preg_match('/\bphp_(value|flag|admin_value|admin_flag)\b/i', $line)) {
            return "Line " . ($i + 1) . " uses a php_* directive, which is not allowed: \"$line\"";
        }

        if (preg_match('/\b(AddHandler|SetHandler)\b.*\b(php|cgi|cgi-script|fcgid-script)\b/i', $line)) {
            return "Line " . ($i + 1) . " maps a handler to PHP/CGI execution, which is not allowed: \"$line\"";
        }

        // A <Files ...> block whose pattern targets a non-php extension but
        // whose block body (this same line, since we validate line-by-line)
        // still can't smuggle a handler — the AddHandler/SetHandler check
        // above covers the body lines. Guard the opening tag itself against
        // obviously dangerous patterns (wildcard covering .php while framed
        // as if it were something else is still just a Files selector, which
        // is safe on its own; the executable mapping only happens via
        // AddHandler/SetHandler, already rejected above).
        if (preg_match('/^\s*php_/i', $line)) {
            return "Line " . ($i + 1) . " starts with a php_* directive, which is not allowed: \"$line\"";
        }
    }
    return true;
}

/* ------------------------------------------------------------------ *
 * Filesystem plumbing (thin WordPress wrappers).
 * ------------------------------------------------------------------ */

function wpultra_rules_htaccess_path(): string {
    return rtrim(ABSPATH, '/\\') . '/.htaccess';
}

function wpultra_rules_backup_path(): string {
    return wpultra_rules_htaccess_path() . '.wpultra-backup';
}

/** True when the current request environment looks like nginx (no Apache-style rewriting). */
function wpultra_rules_is_nginx(): bool {
    $sw = (string) ($_SERVER['SERVER_SOFTWARE'] ?? '');
    return stripos($sw, 'nginx') !== false;
}

/** Ensure WP core's marker helpers are loaded. Best-effort; safe to call repeatedly. */
function wpultra_rules_load_misc(): void {
    if (function_exists('insert_with_markers') && function_exists('extract_from_markers')) { return; }
    $misc = rtrim(ABSPATH, '/\\') . '/wp-admin/includes/misc.php';
    if (is_readable($misc)) { require_once $misc; }
}

/**
 * Copy the current .htaccess to the single-slot backup file. No-ops (returns
 * true) if .htaccess does not currently exist — there is nothing to protect.
 * Returns false only on a genuine copy failure.
 */
function wpultra_rules_backup_current(): bool {
    $file = wpultra_rules_htaccess_path();
    if (!is_file($file)) { return true; }
    return @copy($file, wpultra_rules_backup_path()) !== false;
}

/**
 * GET: current managed block lines, whether the file is writable, and file size.
 * In nginx mode (no .htaccess + nginx detected), returns the composed rules as
 * text instead of reading a managed block, with a note that it must be applied
 * to the server config manually.
 *
 * @return array|WP_Error
 */
function wpultra_rules_get(array $input = []) {
    $file = wpultra_rules_htaccess_path();

    if (!is_file($file) && wpultra_rules_is_nginx()) {
        $presets = array_values(array_filter(array_map('strval', (array) ($input['presets'] ?? []))));
        $custom  = array_values(array_map('strval', (array) ($input['custom_lines'] ?? [])));
        $lines   = wpultra_rules_compose($presets, $custom);
        return wpultra_ok([
            'mode'  => 'nginx',
            'note'  => 'nginx: add to server config manually',
            'text'  => implode("\n", $lines),
            'lines' => $lines,
        ]);
    }

    wpultra_rules_load_misc();
    $lines = [];
    if (is_file($file) && function_exists('extract_from_markers')) {
        $lines = (array) extract_from_markers($file, WPULTRA_RULES_MARKER);
    }

    return wpultra_ok([
        'mode'      => 'apache',
        'file'      => $file,
        'exists'    => is_file($file),
        'writable'  => is_file($file) ? is_writable($file) : is_writable(dirname($file)),
        'size'      => is_file($file) ? (int) filesize($file) : 0,
        'lines'     => array_values(array_map('strval', $lines)),
    ]);
}

/**
 * SET: validate + write the composed preset/custom lines into the managed
 * marker block. Backs up the current .htaccess first. Requires confirm:true.
 *
 * @return array|WP_Error
 */
function wpultra_rules_set(array $input) {
    $confirm = ($input['confirm'] ?? false) === true;
    if (!$confirm) {
        return wpultra_err('rules_unconfirmed', 'Writing server rules is a live config change. Re-run with confirm: true.');
    }

    $presets = array_values(array_filter(array_map('strval', (array) ($input['presets'] ?? []))));
    foreach ($presets as $name) {
        if (!in_array($name, wpultra_rules_known_presets(), true)) {
            return wpultra_err('unknown_preset', "Unknown preset '$name'. Known presets: " . implode(', ', wpultra_rules_known_presets()));
        }
    }
    $custom = array_values(array_map('strval', (array) ($input['custom_lines'] ?? [])));

    $lines = wpultra_rules_compose($presets, $custom);
    if ($lines === []) {
        return wpultra_err('empty_rules', 'No presets or custom_lines produced any rules to write.');
    }

    $valid = wpultra_rules_validate_lines($lines);
    if ($valid !== true) {
        return wpultra_err('invalid_rule_line', (string) $valid);
    }

    $file = wpultra_rules_htaccess_path();

    if (!is_file($file) && wpultra_rules_is_nginx()) {
        return wpultra_err('nginx_manual', 'nginx detected and no .htaccess exists: rules cannot be written automatically. Add the composed text to the server config manually (see get action).');
    }

    if (!wpultra_rules_backup_current()) {
        return wpultra_err('backup_failed', 'Could not back up the current .htaccess before writing; aborting for safety.');
    }

    wpultra_rules_load_misc();
    if (!function_exists('insert_with_markers')) {
        return wpultra_err('misc_unavailable', 'insert_with_markers() is unavailable (wp-admin/includes/misc.php could not be loaded).');
    }

    $ok = insert_with_markers($file, WPULTRA_RULES_MARKER, $lines);
    if (!$ok) {
        return wpultra_err('write_failed', "Could not write the managed block into $file.");
    }

    wpultra_audit_log('manage-server-rules', 'set presets=' . implode(',', $presets) . ' custom_lines=' . count($custom), true);
    return wpultra_ok([
        'mode'    => 'apache',
        'file'    => $file,
        'presets' => $presets,
        'lines'   => $lines,
        'backup'  => wpultra_rules_backup_path(),
    ]);
}

/**
 * CLEAR: remove the managed block (writes an empty insertion, which
 * insert_with_markers() interprets as "delete the marker block"). Backs up
 * first. Requires confirm:true.
 *
 * @return array|WP_Error
 */
function wpultra_rules_clear(array $input) {
    $confirm = ($input['confirm'] ?? false) === true;
    if (!$confirm) {
        return wpultra_err('rules_unconfirmed', 'Clearing server rules is a live config change. Re-run with confirm: true.');
    }

    $file = wpultra_rules_htaccess_path();
    if (!is_file($file)) {
        return wpultra_ok(['mode' => 'apache', 'file' => $file, 'cleared' => false, 'note' => 'no .htaccess file exists.']);
    }

    if (!wpultra_rules_backup_current()) {
        return wpultra_err('backup_failed', 'Could not back up the current .htaccess before clearing; aborting for safety.');
    }

    wpultra_rules_load_misc();
    if (!function_exists('insert_with_markers')) {
        return wpultra_err('misc_unavailable', 'insert_with_markers() is unavailable (wp-admin/includes/misc.php could not be loaded).');
    }

    $ok = insert_with_markers($file, WPULTRA_RULES_MARKER, []);
    if (!$ok) {
        return wpultra_err('clear_failed', "Could not clear the managed block in $file.");
    }

    wpultra_audit_log('manage-server-rules', 'clear', true);
    return wpultra_ok(['mode' => 'apache', 'file' => $file, 'cleared' => true, 'backup' => wpultra_rules_backup_path()]);
}

/**
 * RESTORE-BACKUP: copy .htaccess.wpultra-backup back over .htaccess. This is
 * this ability's OWN restore action — it intentionally does not go through
 * the universal undo engine (includes/undo/engine.php). Requires confirm:true.
 *
 * @return array|WP_Error
 */
function wpultra_rules_restore_backup(array $input) {
    $confirm = ($input['confirm'] ?? false) === true;
    if (!$confirm) {
        return wpultra_err('rules_unconfirmed', 'Restoring the .htaccess backup overwrites the live file. Re-run with confirm: true.');
    }

    $backup = wpultra_rules_backup_path();
    if (!is_file($backup)) {
        return wpultra_err('no_backup', "No backup file found at $backup.");
    }

    $file = wpultra_rules_htaccess_path();
    $ok = @copy($backup, $file);
    if (!$ok) {
        return wpultra_err('restore_failed', "Could not copy $backup over $file.");
    }

    wpultra_audit_log('manage-server-rules', 'restore-backup', true);
    return wpultra_ok(['mode' => 'apache', 'file' => $file, 'restored' => true, 'backup' => $backup]);
}
