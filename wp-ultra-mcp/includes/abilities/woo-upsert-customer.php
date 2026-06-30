<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/woo-upsert-customer', [
    'label'       => __('WooCommerce: Upsert Customer', 'wp-ultra-mcp'),
    'description' => __('Create (email required) or update (pass id) a customer. Fields: email, first_name, last_name, username, password, role, billing, shipping. Returns rejected fields.', 'wp-ultra-mcp'),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'id'         => ['type' => 'integer'],
            'email'      => ['type' => 'string'],
            'first_name' => ['type' => 'string'],
            'last_name'  => ['type' => 'string'],
            'username'   => ['type' => 'string'],
            'password'   => ['type' => 'string'],
            'role'       => ['type' => 'string'],
            'billing'    => ['type' => 'object'],
            'shipping'   => ['type' => 'object'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => ['success' => ['type' => 'boolean'], 'id' => ['type' => 'integer'], 'rejected' => ['type' => 'array']],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_woo_upsert_customer_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_woo_upsert_customer_cb(array $input) {
    if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    $res = wpultra_woo_upsert_customer($input);
    wpultra_audit_log('woo-upsert-customer', is_wp_error($res) ? 'failed' : ('customer ' . $res['id']), !is_wp_error($res));
    if (is_wp_error($res)) { return $res; }
    return wpultra_ok(['id' => $res['id'], 'rejected' => $res['rejected']]);
}
