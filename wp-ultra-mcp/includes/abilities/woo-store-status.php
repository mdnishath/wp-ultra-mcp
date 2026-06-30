<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/woo-store-status', [
    'label'       => __('WooCommerce: Store Status', 'wp-ultra-mcp'),
    'description' => __('Report whether WooCommerce is active and how the store is configured (version, HPOS, currency, pages, counts).', 'wp-ultra-mcp'),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => ['success' => ['type' => 'boolean'], 'store' => ['type' => 'object']],
        'required'   => ['success'],
    ],
    'execute_callback'    => 'wpultra_woo_store_status_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_woo_store_status_cb(array $input) {
    if (!wpultra_woo_active()) {
        return wpultra_ok(['store' => ['active' => false, 'hint' => 'WooCommerce is not active. Install/activate it (wp plugin install woocommerce --activate).']]);
    }
    return wpultra_ok(['store' => wpultra_woo_store_status()]);
}
