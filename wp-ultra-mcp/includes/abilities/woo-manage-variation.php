<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/woo-manage-variation', [
    'label'       => __('WooCommerce: Manage Variation', 'wp-ultra-mcp'),
    'description' => __('Create/update/delete/list variations of a variable product (attributes, price, stock, sku, image).', 'wp-ultra-mcp'),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'action'         => ['type' => 'string', 'enum' => ['create', 'update', 'delete', 'list']],
            'parent_id'      => ['type' => 'integer'],
            'variation_id'   => ['type' => 'integer'],
            'attributes'     => ['type' => 'object'],
            'regular_price'  => ['type' => 'string'],
            'sale_price'     => ['type' => 'string'],
            'sku'            => ['type' => 'string'],
            'manage_stock'   => ['type' => 'boolean'],
            'stock_quantity' => ['type' => 'integer'],
            'image_id'       => ['type' => 'integer'],
        ],
        'required'   => ['action', 'parent_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => ['success' => ['type' => 'boolean']],
        'required'   => ['success'],
    ],
    'execute_callback'    => 'wpultra_woo_manage_variation_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_woo_manage_variation_cb(array $input) {
    if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    $res = wpultra_woo_manage_variation($input);
    $action = (string) ($input['action'] ?? 'list');
    if (in_array($action, ['create', 'update', 'delete'], true)) {
        wpultra_audit_log('woo-manage-variation', $action . ' on ' . (int) ($input['parent_id'] ?? 0), !is_wp_error($res));
    }
    if (is_wp_error($res)) { return $res; }
    return wpultra_ok($res);
}
