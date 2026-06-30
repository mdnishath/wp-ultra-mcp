<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/woo-get-settings', [
    'label'       => __('WooCommerce: Get Settings', 'wp-ultra-mcp'),
    'description' => __('Read store settings: general (currency/country/units), payment gateways, shipping zones+methods, tax options.', 'wp-ultra-mcp'),
    'category'    => 'woocommerce',
    'input_schema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean'], 'settings' => ['type' => 'object']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_woo_get_settings_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

function wpultra_woo_get_settings_cb(array $input) {
    if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    return wpultra_ok(['settings' => wpultra_woo_get_settings()]);
}
