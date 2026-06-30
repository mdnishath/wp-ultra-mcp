<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/woo-delete-product', [
    'label'       => __('WooCommerce: Delete Product', 'wp-ultra-mcp'),
    'description' => __('Trash a product, or permanently delete it with force:true.', 'wp-ultra-mcp'),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type'       => 'object',
        'properties' => ['product_id' => ['type' => 'integer'], 'force' => ['type' => 'boolean']],
        'required'   => ['product_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => ['success' => ['type' => 'boolean'], 'id' => ['type' => 'integer'], 'deleted' => ['type' => 'boolean']],
        'required'   => ['success'],
    ],
    'execute_callback'    => 'wpultra_woo_delete_product_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_woo_delete_product_cb(array $input) {
    if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    $res = wpultra_woo_delete_product((int) ($input['product_id'] ?? 0), (bool) ($input['force'] ?? false));
    wpultra_audit_log('woo-delete-product', is_wp_error($res) ? 'failed' : ('product ' . $res['id'] . ' force=' . (($input['force'] ?? false) ? '1' : '0')), !is_wp_error($res));
    if (is_wp_error($res)) { return $res; }
    return wpultra_ok($res);
}
