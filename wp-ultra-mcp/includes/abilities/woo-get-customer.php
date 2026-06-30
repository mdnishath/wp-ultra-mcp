<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/woo-get-customer', [
    'label'       => __('WooCommerce: Get Customer', 'wp-ultra-mcp'),
    'description' => __('Get one customer\'s full detail: name, email, billing/shipping, order count, total spent.', 'wp-ultra-mcp'),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type' => 'object',
        'properties' => ['customer_id' => ['type' => 'integer']],
        'required' => ['customer_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean'], 'customer' => ['type' => 'object']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_woo_get_customer_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

function wpultra_woo_get_customer_cb(array $input) {
    if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    $res = wpultra_woo_get_customer((int) ($input['customer_id'] ?? 0));
    if (is_wp_error($res)) { return $res; }
    return wpultra_ok(['customer' => $res]);
}
