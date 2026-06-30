<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/woo-refund-order', [
    'label'       => __('WooCommerce: Refund Order', 'wp-ultra-mcp'),
    'description' => __('Refund an order: amount (default full remaining), reason, restock (default true), optional line_items. Uses wc_create_refund.', 'wp-ultra-mcp'),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'order_id'   => ['type' => 'integer'],
            'amount'     => ['type' => 'string'],
            'reason'     => ['type' => 'string'],
            'restock'    => ['type' => 'boolean'],
            'line_items' => ['type' => 'object'],
        ],
        'required'   => ['order_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => ['success' => ['type' => 'boolean'], 'refund_id' => ['type' => 'integer'], 'amount' => ['type' => 'string']],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_woo_refund_order_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_woo_refund_order_cb(array $input) {
    if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    $res = wpultra_woo_refund_order($input);
    wpultra_audit_log('woo-refund-order', is_wp_error($res) ? 'failed' : ('order ' . $res['order_id'] . ' refund ' . $res['amount']), !is_wp_error($res));
    if (is_wp_error($res)) { return $res; }
    return wpultra_ok(['refund_id' => $res['refund_id'], 'amount' => $res['amount']]);
}
