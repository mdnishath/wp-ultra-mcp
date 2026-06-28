<?php
declare(strict_types=1);

if (!defined('ABSPATH')) { exit(); }

/** Collapse '.'/'..', normalize to forward slashes, strip trailing slash (keep root). Pure. */
function wpultra_normalize_absolute_path(string $path): string {
    $path = str_replace('\\', '/', $path);
    $is_unc = str_starts_with($path, '//');
    $segments = explode('/', $path);
    $out = [];
    foreach ($segments as $seg) {
        if ($seg === '' || $seg === '.') { continue; }
        if ($seg === '..') { array_pop($out); continue; }
        $out[] = $seg;
    }
    $prefix = '';
    if (preg_match('#^[A-Za-z]:#', $path)) { $prefix = ''; }       // windows drive kept as first segment
    elseif ($is_unc) { $prefix = '//'; }
    elseif (str_starts_with($path, '/')) { $prefix = '/'; }
    $joined = $prefix . implode('/', $out);
    return $joined === '' ? '/' : $joined;
}

/** True if $path equals $dir or is nested under it. Pure. */
function wpultra_path_is_within_directory(string $path, string $dir): bool {
    $p = wpultra_normalize_absolute_path($path);
    $d = wpultra_normalize_absolute_path($dir);
    return $p === $d || str_starts_with($p, $d . '/');
}

function wpultra_is_valid_identifier(string $name): bool {
    return (bool) preg_match('/^[A-Za-z0-9_]+$/', $name);
}

/** The plugin's own private CPTs — the generic content abilities must not touch these. */
function wpultra_reserved_post_types(): array {
    return ['wpultra_memory', 'wpultra_skill', 'wpultra_ability'];
}

/**
 * Return ['verb'=>UPPER, 'destructive'=>bool]. Pure.
 *
 * Allow-list approach: only genuinely read-only verbs (and INSERT, which only adds
 * rows) are non-destructive. Everything else — DELETE/UPDATE (even with a WHERE,
 * since `WHERE 1=1` is a trivial bypass), DDL (DROP/TRUNCATE/ALTER/RENAME/CREATE),
 * privilege changes (GRANT/REVOKE), CTEs that can wrap a DELETE (WITH …), and any
 * unrecognised verb — requires `confirm: true`.
 */
function wpultra_classify_query(string $sql): array {
    $trimmed = trim($sql);
    $verb = strtoupper(preg_split('/\s+/', $trimmed)[0] ?? '');
    $safe = ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN', 'INSERT'];
    $destructive = !in_array($verb, $safe, true);
    return ['verb' => $verb, 'destructive' => $destructive];
}

function wpultra_filesystem_base_dir(): string {
    return (string) apply_filters('wpultra_filesystem_base_dir', ABSPATH);
}

function wpultra_path_requires_sandbox(string $path): bool {
    // Trailing dots/spaces are stripped by some filesystems on open, so `shell.php.`
    // and `shell.php ` resolve to `shell.php` — strip them before matching.
    $name = strtolower(rtrim(basename($path), " ."));
    // Any extension the PHP handler (or a server config) commonly maps to executable code.
    if (preg_match('/\.(php\d*|phtml|phps|pht|phar|ini)$/', $name)) { return true; }
    return in_array($name, ['.htaccess', 'web.config'], true);
}

/**
 * Resolve a path inside the jail. Returns absolute path string or WP_Error.
 * Relative paths resolve against the base dir. Symlink final targets are rejected.
 */
function wpultra_resolve_path(string $path, bool $must_exist = false) {
    $path = trim($path);
    if ($path === '') { return wpultra_err('missing_path', 'Path is required.'); }
    // Reject null bytes / control chars: they bypass extension checks and break FS calls.
    if (strpbrk($path, "\0") !== false || preg_match('/[\x00-\x1f]/', $path)) {
        return wpultra_err('invalid_path', 'Path contains illegal control characters.');
    }

    $base = wpultra_filesystem_base_dir();
    $is_abs = (bool) preg_match('#^([A-Za-z]:[\\\\/]|[\\\\/])#', $path);
    $candidate = $is_abs ? $path : rtrim($base, '/\\') . '/' . $path;

    // Resolve parent via realpath (handles symlinks/.. in the existing portion); append missing tail.
    $real = realpath($candidate);
    if ($real === false) {
        if ($must_exist) { return wpultra_err('path_not_found', "Path does not exist: $candidate"); }
        $parent = realpath(dirname($candidate));
        if ($parent === false) {
            $resolved = wpultra_normalize_absolute_path($candidate);
        } else {
            $resolved = wpultra_normalize_absolute_path($parent . '/' . basename($candidate));
        }
    } else {
        $resolved = wpultra_normalize_absolute_path($real);
    }

    if (!wpultra_path_is_within_directory($resolved, $base)) {
        return wpultra_err('path_outside_base', "Path is outside the allowed base directory: $resolved");
    }
    if (is_link($resolved)) {
        return wpultra_err('symlink_rejected', "Refusing to operate on a symlink: $resolved");
    }
    if (wpultra_path_requires_sandbox($resolved)) {
        $sandbox = wpultra_normalize_absolute_path(WPULTRA_SANDBOX_DIR);
        if (!wpultra_path_is_within_directory($resolved, $sandbox)) {
            return wpultra_err('sandbox_required', "Executable files must be written under the sandbox dir: $sandbox");
        }
    }
    return $resolved;
}

function wpultra_is_enabled(): bool {
    if (get_option('wpultra_enabled') !== '1') { return false; }
    $locked = (string) get_option('wpultra_domain', '');
    if ($locked === '') { return true; }
    $current = wp_parse_url(home_url(), PHP_URL_HOST);
    return $locked === $current;
}

function wpultra_current_user_can_manage(): bool {
    return is_multisite() ? is_super_admin() : current_user_can('manage_options');
}

function wpultra_permission_callback(): bool {
    return wpultra_is_enabled() && wpultra_current_user_can_manage();
}

/**
 * Append an entry to the privileged-action audit log (a capped ring buffer in an option).
 * No-ops outside WordPress (e.g. unit tests) so callers can instrument freely. Best-effort.
 */
function wpultra_audit_log(string $action, string $summary, bool $ok = true): void {
    if (!function_exists('get_option') || !function_exists('update_option')) { return; }
    $log = get_option('wpultra_audit', []);
    if (!is_array($log)) { $log = []; }
    $log[] = [
        'ts'      => function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s'),
        'user'    => function_exists('get_current_user_id') ? (int) get_current_user_id() : 0,
        'action'  => $action,
        'summary' => function_exists('mb_substr') ? mb_substr($summary, 0, 300) : substr($summary, 0, 300),
        'ok'      => $ok,
    ];
    $max = (int) (function_exists('apply_filters') ? apply_filters('wpultra_audit_max', 200) : 200);
    if ($max < 1) { $max = 200; }
    if (count($log) > $max) { $log = array_slice($log, -$max); }
    update_option('wpultra_audit', $log, false);
}

function wpultra_ok(array $fields): array { return array_merge(['success' => true], $fields); }

function wpultra_err(string $code, string $message, $data = ''): WP_Error {
    return new WP_Error($code, $message, $data);
}
