<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/woo-get-reports', [
    'label'       => __('WooCommerce: Get Reports', 'wp-ultra-mcp'),
    'description' => __('Sales analytics: type sales|revenue (order_count+gross over optional date_from/date_to), top_products (by qty), low_stock (out-of-stock list). HPOS-safe.', 'wp-ultra-mcp'),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'type'      => ['type' => 'string', 'enum' => ['sales', 'revenue', 'top_products', 'low_stock']],
            'date_from' => ['type' => 'string'],
            'date_to'   => ['type' => 'string'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean'], 'report' => ['type' => 'object']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_woo_get_reports_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

function wpultra_woo_get_reports_cb(array $input) {
    if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    return wpultra_ok(['report' => wpultra_woo_get_reports($input)]);
}
