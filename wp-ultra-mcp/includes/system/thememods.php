<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Theme mods (Customizer settings) engine. For classic themes these are the
 * equivalent of theme.json's global styles — colors, logo, layout options, nav
 * menu locations, and any setting a theme or plugin registers via the
 * Customizer. Stored per-theme in the `theme_mods_<stylesheet>` option.
 *
 * Reuses wpultra_option_is_sensitive() (from system/options.php) so a mod that
 * looks like a credential never round-trips through the AI.
 */

/** True when a theme-mod key looks sensitive. Falls back to a local check if options.php isn't loaded. */
function wpultra_thememod_is_sensitive(string $key): bool {
    if (function_exists('wpultra_option_is_sensitive')) { return wpultra_option_is_sensitive($key); }
    $k = strtolower(trim($key));
    return str_contains($k, 'secret') || str_contains($k, 'password') || str_ends_with($k, '_key');
}

/** @return array all current theme mods for the active theme, secrets redacted. */
function wpultra_thememods_list(): array {
    $mods = function_exists('get_theme_mods') ? get_theme_mods() : [];
    $mods = is_array($mods) ? $mods : [];
    $out = [];
    foreach ($mods as $key => $val) {
        $key = (string) $key;
        $out[$key] = wpultra_thememod_is_sensitive($key) ? '«redacted»' : $val;
    }
    return [
        'theme'     => function_exists('get_stylesheet') ? get_stylesheet() : '',
        'theme_mods' => $out,
        'count'     => count($out),
    ];
}

/** @return array|WP_Error */
function wpultra_thememod_get(string $key) {
    if ($key === '') { return wpultra_err('missing_key', 'key is required.'); }
    if (wpultra_thememod_is_sensitive($key)) { return wpultra_err('sensitive_mod', "Refusing to read sensitive theme mod '$key'."); }
    $sentinel = new stdClass();
    $val = get_theme_mod($key, $sentinel);
    if ($val === $sentinel) { return wpultra_err('not_set', "Theme mod '$key' is not set."); }
    return ['key' => $key, 'value' => $val];
}

/** @return array|WP_Error */
function wpultra_thememod_set(string $key, $value) {
    if ($key === '') { return wpultra_err('missing_key', 'key is required.'); }
    if (wpultra_thememod_is_sensitive($key)) { return wpultra_err('sensitive_mod', "Refusing to write sensitive theme mod '$key'."); }
    set_theme_mod($key, $value);
    return ['key' => $key, 'value' => get_theme_mod($key), 'saved' => true];
}

/** @return array|WP_Error */
function wpultra_thememod_remove(string $key) {
    if ($key === '') { return wpultra_err('missing_key', 'key is required.'); }
    remove_theme_mod($key);
    return ['key' => $key, 'removed' => true];
}

/** Ability dispatcher. @return array|WP_Error */
function wpultra_manage_theme_mods(array $input) {
    $action = (string) ($input['action'] ?? 'list');
    $key    = (string) ($input['key'] ?? '');
    switch ($action) {
        case 'list':
            return wpultra_ok(wpultra_thememods_list());
        case 'get':
            $res = wpultra_thememod_get($key);
            return is_wp_error($res) ? $res : wpultra_ok($res);
        case 'set':
            if (!array_key_exists('value', $input)) { return wpultra_err('missing_value', "set requires a 'value'."); }
            $res = wpultra_thememod_set($key, $input['value']);
            if (is_wp_error($res)) { return $res; }
            wpultra_audit_log('manage-theme-mods', "set $key", true);
            return wpultra_ok($res);
        case 'remove':
            if ($e = wpultra_require_confirm($input, "Removing a theme mod reverts that Customizer setting to its default.")) { return $e; }
            $res = wpultra_thememod_remove($key);
            if (is_wp_error($res)) { return $res; }
            wpultra_audit_log('manage-theme-mods', "remove $key", true);
            return wpultra_ok($res);
        default:
            return wpultra_err('bad_action', "Unknown action '$action'.");
    }
}
