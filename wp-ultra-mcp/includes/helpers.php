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

/** Return ['verb'=>UPPER, 'destructive'=>bool]. Pure. */
function wpultra_classify_query(string $sql): array {
    $trimmed = trim($sql);
    $verb = strtoupper(preg_split('/\s+/', $trimmed)[0] ?? '');
    $has_where = (bool) preg_match('/\bWHERE\b/i', $trimmed);
    $destructive = false;
    if (in_array($verb, ['DROP', 'TRUNCATE', 'ALTER'], true)) { $destructive = true; }
    if (in_array($verb, ['DELETE', 'UPDATE'], true) && !$has_where) { $destructive = true; }
    return ['verb' => $verb, 'destructive' => $destructive];
}

function wpultra_filesystem_base_dir(): string {
    return (string) apply_filters('wpultra_filesystem_base_dir', ABSPATH);
}

function wpultra_path_requires_sandbox(string $path): bool {
    $name = strtolower(basename($path));
    if (str_ends_with($name, '.php')) { return true; }
    if (str_ends_with($name, '.ini')) { return true; }
    return in_array($name, ['.htaccess', 'php.ini', 'web.config', '.user.ini'], true);
}

/**
 * Resolve a path inside the jail. Returns absolute path string or WP_Error.
 * Relative paths resolve against the base dir. Symlink final targets are rejected.
 */
function wpultra_resolve_path(string $path, bool $must_exist = false) {
    $path = trim($path);
    if ($path === '') { return wpultra_err('missing_path', 'Path is required.'); }

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

function wpultra_ok(array $fields): array { return array_merge(['success' => true], $fields); }

function wpultra_err(string $code, string $message, $data = ''): WP_Error {
    return new WP_Error($code, $message, $data);
}
