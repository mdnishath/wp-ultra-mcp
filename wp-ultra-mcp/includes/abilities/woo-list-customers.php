<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/woo-list-customers', [
    'label'       => __('WooCommerce: List Customers', 'wp-ultra-mcp'),
    'description' => __('List customers with optional search/page/per_page; rows include order count + total spent.', 'wp-ultra-mcp'),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type' => 'object',
        'properties' => ['search' => ['type' => 'string'], 'page' => ['type' => 'integer'], 'per_page' => ['type' => 'integer']],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean'], 'count' => ['type' => 'integer'], 'customers' => ['type' => 'array']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_woo_list_customers_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

function wpultra_woo_list_customers_cb(array $input) {
    if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    return wpultra_ok(wpultra_woo_list_customers($input));
}
