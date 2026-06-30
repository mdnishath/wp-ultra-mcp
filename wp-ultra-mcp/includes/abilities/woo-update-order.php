<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/woo-update-order', [
    'label'       => __('WooCommerce: Update Order', 'wp-ultra-mcp'),
    'description' => __('Update an order: status, add_order_note (note + note_to_customer), billing/shipping addresses, add_items / remove_item_ids. Recalculates totals on item changes.', 'wp-ultra-mcp'),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'order_id'         => ['type' => 'integer'],
            'status'           => ['type' => 'string'],
            'note'             => ['type' => 'string'],
            'note_to_customer' => ['type' => 'boolean'],
            'billing'          => ['type' => 'object'],
            'shipping'         => ['type' => 'object'],
            'add_items'        => ['type' => 'array'],
            'remove_item_ids'  => ['type' => 'array'],
        ],
        'required'   => ['order_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => ['success' => ['type' => 'boolean'], 'id' => ['type' => 'integer'], 'status' => ['type' => 'string'], 'total' => ['type' => 'string']],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_woo_update_order_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_woo_update_order_cb(array $input) {
    if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    $res = wpultra_woo_update_order($input);
    wpultra_audit_log('woo-update-order', is_wp_error($res) ? 'failed' : ('order ' . $res['id'] . ' -> ' . $res['status']), !is_wp_error($res));
    if (is_wp_error($res)) { return $res; }
    return wpultra_ok($res);
}
