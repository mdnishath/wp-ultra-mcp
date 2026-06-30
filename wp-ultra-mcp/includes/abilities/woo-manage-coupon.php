<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/woo-manage-coupon', [
    'label'       => __('WooCommerce: Manage Coupon', 'wp-ultra-mcp'),
    'description' => __('Create/update/get/delete/list coupons: code, discount_type (fixed_cart|percent|fixed_product), amount, free_shipping, date_expires, min/max amount, usage_limit, product include/exclude.', 'wp-ultra-mcp'),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'action'        => ['type' => 'string', 'enum' => ['list', 'get', 'create', 'update', 'delete']],
            'id'            => ['type' => 'integer'],
            'code'          => ['type' => 'string'],
            'discount_type' => ['type' => 'string'],
            'amount'        => ['type' => 'string'],
            'description'   => ['type' => 'string'],
            'free_shipping' => ['type' => 'boolean'],
            'date_expires'  => ['type' => 'string'],
            'minimum_amount' => ['type' => 'string'],
            'maximum_amount' => ['type' => 'string'],
            'usage_limit'   => ['type' => 'integer'],
            'individual_use' => ['type' => 'boolean'],
            'product_ids'   => ['type' => 'array'],
            'excluded_product_ids' => ['type' => 'array'],
            'force'         => ['type' => 'boolean'],
            'per_page'      => ['type' => 'integer'],
        ],
        'required'   => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_woo_manage_coupon_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

function wpultra_woo_manage_coupon_cb(array $input) {
    if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    $res = wpultra_woo_manage_coupon($input);
    $action = (string) ($input['action'] ?? 'list');
    if (in_array($action, ['create', 'update', 'delete'], true)) {
        wpultra_audit_log('woo-manage-coupon', $action . (is_wp_error($res) ? ' failed' : ''), !is_wp_error($res));
    }
    if (is_wp_error($res)) { return $res; }
    return wpultra_ok($res);
}
