<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/woo-list-products', [
    'label'       => __('WooCommerce: List Products', 'wp-ultra-mcp'),
    'description' => __('List products with filters (search, status, type, category slug, stock_status, on_sale, page, per_page).', 'wp-ultra-mcp'),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'search'       => ['type' => 'string'],
            'status'       => ['type' => 'string'],
            'type'         => ['type' => 'string'],
            'category'     => ['type' => 'string'],
            'stock_status' => ['type' => 'string'],
            'on_sale'      => ['type' => 'boolean'],
            'page'         => ['type' => 'integer'],
            'per_page'     => ['type' => 'integer'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => ['success' => ['type' => 'boolean'], 'count' => ['type' => 'integer'], 'products' => ['type' => 'array']],
        'required'   => ['success'],
    ],
    'execute_callback'    => 'wpultra_woo_list_products_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_woo_list_products_cb(array $input) {
    if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    $res = wpultra_woo_list_products($input);
    return wpultra_ok($res);
}
