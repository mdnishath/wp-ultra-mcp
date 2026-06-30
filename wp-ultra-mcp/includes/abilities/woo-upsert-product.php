<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/woo-upsert-product', [
    'label'       => __('WooCommerce: Upsert Product', 'wp-ultra-mcp'),
    'description' => __('Create or update a product (simple/variable/grouped/external). Pass id to update. Returns rejected fields.', 'wp-ultra-mcp'),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'id'                 => ['type' => 'integer'],
            'name'               => ['type' => 'string'],
            'type'               => ['type' => 'string'],
            'status'             => ['type' => 'string'],
            'slug'               => ['type' => 'string'],
            'description'        => ['type' => 'string'],
            'short_description'  => ['type' => 'string'],
            'sku'                => ['type' => 'string'],
            'regular_price'      => ['type' => 'string'],
            'sale_price'         => ['type' => 'string'],
            'manage_stock'       => ['type' => 'boolean'],
            'stock_quantity'     => ['type' => 'integer'],
            'stock_status'       => ['type' => 'string'],
            'backorders'         => ['type' => 'string'],
            'weight'             => ['type' => 'string'],
            'length'             => ['type' => 'string'],
            'width'              => ['type' => 'string'],
            'height'             => ['type' => 'string'],
            'virtual'            => ['type' => 'boolean'],
            'downloadable'       => ['type' => 'boolean'],
            'featured'           => ['type' => 'boolean'],
            'catalog_visibility' => ['type' => 'string'],
            'category_ids'       => ['type' => 'array'],
            'tag_ids'            => ['type' => 'array'],
            'image_id'           => ['type' => 'integer'],
            'gallery_image_ids'  => ['type' => 'array'],
            'menu_order'         => ['type' => 'integer'],
            'external_url'       => ['type' => 'string'],
            'button_text'        => ['type' => 'string'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => ['success' => ['type' => 'boolean'], 'id' => ['type' => 'integer'], 'rejected' => ['type' => 'array']],
        'required'   => ['success'],
    ],
    'execute_callback'    => 'wpultra_woo_upsert_product_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_woo_upsert_product_cb(array $input) {
    if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    $res = wpultra_woo_upsert_product($input);
    wpultra_audit_log('woo-upsert-product', is_wp_error($res) ? 'failed' : ('product ' . $res['id']), !is_wp_error($res));
    if (is_wp_error($res)) { return $res; }
    return wpultra_ok(['id' => $res['id'], 'rejected' => $res['rejected']]);
}
