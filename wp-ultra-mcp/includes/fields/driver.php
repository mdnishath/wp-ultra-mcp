<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/** Names of currently-active providers. */
function wpultra_fields_active_names(): array {
    return array_map(static fn($p) => $p['provider'], wpultra_fields_providers());
}

/**
 * Choose which provider to operate on.
 * @return string|WP_Error
 */
function wpultra_fields_pick_provider(?string $requested) {
    $active = wpultra_fields_active_names();
    if (!$active) { return new WP_Error('no_provider', 'No custom-field plugin (ACF, Meta Box, Pods) is active.'); }
    if ($requested !== null && $requested !== '' && $requested !== 'auto') {
        if (!in_array($requested, $active, true)) {
            return new WP_Error('provider_inactive', "Provider '{$requested}' is not active. Active: " . implode(', ', $active));
        }
        return $requested;
    }
    if (count($active) === 1) { return $active[0]; }
    return new WP_Error('provider_ambiguous', 'Multiple providers active (' . implode(', ', $active) . '); specify provider.', ['active' => $active]);
}

/**
 * Dispatch an operation to the provider adapter.
 * @return array|WP_Error
 */
function wpultra_fields_route(string $op, string $provider, array $args) {
    $fn = "wpultra_fields_{$provider}_{$op}";
    if (!function_exists($fn)) {
        return new WP_Error('op_unsupported', "Provider '{$provider}' does not support '{$op}'.");
    }
    return $fn(...$args);
}
