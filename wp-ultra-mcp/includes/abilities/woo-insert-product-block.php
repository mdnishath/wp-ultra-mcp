<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/woo-insert-product-block', [
    'label'       => __('WooCommerce: Insert Product Block', 'wp-ultra-mcp'),
    'description' => __('Insert a WooCommerce storefront block (grid/single/add_to_cart/categories/sale/featured/best_selling) into a Gutenberg post or Elementor page as a shortcode. builder=gutenberg|elementor, display, params{limit,columns,category,id}.', 'wp-ultra-mcp'),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'post_id'     => ['type' => 'integer'],
            'builder'     => ['type' => 'string', 'enum' => ['gutenberg', 'elementor']],
            'display'     => ['type' => 'string', 'enum' => ['grid', 'single', 'add_to_cart', 'categories', 'sale', 'featured', 'best_selling']],
            'params'      => ['type' => 'object'],
            'parent_path' => ['type' => 'string'],
            'position'    => ['type' => 'integer'],
        ],
        'required'   => ['post_id', 'display'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean'], 'shortcode' => ['type' => 'string'], 'builder' => ['type' => 'string']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_woo_insert_product_block_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_woo_insert_product_block_cb(array $input) {
    if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    $res = wpultra_woo_insert_product_block($input);
    wpultra_audit_log('woo-insert-product-block', is_wp_error($res) ? 'failed' : ((string) ($input['builder'] ?? 'gutenberg') . ' post ' . (int) ($input['post_id'] ?? 0)), !is_wp_error($res));
    if (is_wp_error($res)) { return $res; }
    return wpultra_ok(['shortcode' => $res['shortcode'], 'builder' => $res['builder']]);
}
