<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/woo-list-orders', [
    'label'       => __('WooCommerce: List Orders', 'wp-ultra-mcp'),
    'description' => __('List orders (HPOS-safe) with filters: status, customer id, date_from/date_to (ISO), search, page, per_page.', 'wp-ultra-mcp'),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'status'    => ['type' => 'string'],
            'customer'  => ['type' => 'integer'],
            'date_from' => ['type' => 'string'],
            'date_to'   => ['type' => 'string'],
            'search'    => ['type' => 'string'],
            'page'      => ['type' => 'integer'],
            'per_page'  => ['type' => 'integer'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => ['success' => ['type' => 'boolean'], 'count' => ['type' => 'integer'], 'orders' => ['type' => 'array']],
        'required'   => ['success'],
    ],
    'execute_callback'    => 'wpultra_woo_list_orders_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_woo_list_orders_cb(array $input) {
    if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    return wpultra_ok(wpultra_woo_list_orders($input));
}
