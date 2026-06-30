<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/woo-create-order', [
    'label'       => __('WooCommerce: Create Order', 'wp-ultra-mcp'),
    'description' => __('Create an order from line_items [{product_id,quantity,variation_id?}], optional customer_id/status/billing/shipping/customer_note. Recalculates totals.', 'wp-ultra-mcp'),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'line_items'    => ['type' => 'array'],
            'customer_id'   => ['type' => 'integer'],
            'status'        => ['type' => 'string'],
            'billing'       => ['type' => 'object'],
            'shipping'      => ['type' => 'object'],
            'customer_note' => ['type' => 'string'],
        ],
        'required'   => ['line_items'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => ['success' => ['type' => 'boolean'], 'id' => ['type' => 'integer'], 'total' => ['type' => 'string'], 'status' => ['type' => 'string']],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_woo_create_order_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_woo_create_order_cb(array $input) {
    if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    $res = wpultra_woo_create_order($input);
    wpultra_audit_log('woo-create-order', is_wp_error($res) ? 'failed' : ('order ' . $res['id'] . ' total ' . $res['total']), !is_wp_error($res));
    if (is_wp_error($res)) { return $res; }
    return wpultra_ok(['id' => $res['id'], 'total' => $res['total'], 'status' => $res['status'], 'skipped' => $res['skipped']]);
}
