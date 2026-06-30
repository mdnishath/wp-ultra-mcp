<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/woo-get-product', [
    'label'       => __('WooCommerce: Get Product', 'wp-ultra-mcp'),
    'description' => __('Get one product\'s full detail (prices, stock, attributes, categories, images, variations).', 'wp-ultra-mcp'),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type'       => 'object',
        'properties' => ['product_id' => ['type' => 'integer']],
        'required'   => ['product_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => ['success' => ['type' => 'boolean'], 'product' => ['type' => 'object']],
        'required'   => ['success'],
    ],
    'execute_callback'    => 'wpultra_woo_get_product_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_woo_get_product_cb(array $input) {
    if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    $res = wpultra_woo_get_product((int) ($input['product_id'] ?? 0));
    if (is_wp_error($res)) { return $res; }
    return wpultra_ok(['product' => $res]);
}
