<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/** WordPress options engine: read/write wp_options with a sensitive-name deny-list and a self-lockout guard. */

/**
 * Pure: true when an option name looks like a secret (auth keys/salts, *_secret*, *password*, *_key)
 * — case-insensitive. Used to deny option-get/option-set on values that should never round-trip
 * through an AI-facing API.
 */
function wpultra_option_is_sensitive(string $name): bool {
    $n = strtolower(trim($name));
    if ($n === '') { return false; }
    // WordPress' own auth unique keys/salts (auth_key, auth_salt, secure_auth_key, logged_in_salt, nonce_key, nonce_salt, ...).
    if (preg_match('/(auth|secure|logged_in|nonce)_(key|salt)$/', $n)) { return true; }
    if (str_contains($n, 'secret'))   { return true; }
    if (str_contains($n, 'password')) { return true; }
    if (str_ends_with($n, '_key'))    { return true; }
    return false;
}

/** Pure: WP-Ultra's own critical options that must never be overwritten via option-set (self-lockout guard). */
function wpultra_option_critical_names(): array {
    return ['wpultra_enabled', 'wpultra_ability_rules', 'wpultra_disabled_categories'];
}

/** @return array|WP_Error */
function wpultra_option_get(string $name) {
    if ($name === '') { return wpultra_err('missing_name', 'name is required.'); }
    if (wpultra_option_is_sensitive($name)) { return wpultra_err('sensitive_option', "Refusing to read sensitive option '$name'."); }
    if (!function_exists('get_option')) { return wpultra_err('wp_unavailable', 'WordPress option functions are unavailable.'); }
    $exists = true;
    $default = new stdClass(); // sentinel to distinguish "unset" from a real null/false value
    $value = get_option($name, $default);
    if ($value === $default) { $exists = false; $value = null; }
    $autoload = null;
    global $wpdb;
    if ($exists && isset($wpdb) && is_object($wpdb) && method_exists($wpdb, 'get_var')) {
        $autoload = $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $name));
    }
    return ['name' => $name, 'exists' => $exists, 'value' => $value, 'autoload' => $autoload];
}

/** @return array|WP_Error */
function wpultra_option_set(string $name, $value, bool $confirm) {
    if ($name === '') { return wpultra_err('missing_name', 'name is required.'); }
    if (wpultra_option_is_sensitive($name)) { return wpultra_err('sensitive_option', "Refusing to write sensitive option '$name'."); }
    if (in_array($name, wpultra_option_critical_names(), true)) {
        return wpultra_err('critical_option', "Refusing to modify WP-Ultra-MCP's own critical option '$name' (risk of self-lockout).");
    }
    if (!function_exists('get_option') || !function_exists('update_option')) {
        return wpultra_err('wp_unavailable', 'WordPress option functions are unavailable.');
    }
    $default = new stdClass();
    $old = get_option($name, $default);
    $existed = $old !== $default;
    if ($existed && !$confirm) {
        return wpultra_err('confirm_required', "Option '$name' already exists. Re-run with confirm: true to overwrite.");
    }
    $ok = update_option($name, $value);
    $summary = $existed
        ? "set $name (was " . wp_json_encode($old) . ' -> ' . wp_json_encode($value) . ')'
        : "created $name = " . wp_json_encode($value);
    wpultra_audit_log('option-set', $summary, $ok !== false);
    return ['name' => $name, 'value' => $value, 'updated' => true];
}
