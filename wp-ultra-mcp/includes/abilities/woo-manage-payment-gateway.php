<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/woo-manage-payment-gateway', [
    'label'       => __('WooCommerce: Manage Payment Gateway', 'wp-ultra-mcp'),
    'description' => __('List/get/enable/disable/update-settings for payment gateways (title, description, gateway-specific settings). Sensitive-looking setting values (keys/secrets/tokens/passwords) are always masked in output, never returned.', 'wp-ultra-mcp'),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'action'   => ['type' => 'string', 'enum' => ['list', 'get', 'enable', 'disable', 'update-settings']],
            'gateway'  => ['type' => 'string', 'description' => "Gateway id, e.g. 'bacs', 'cod', 'stripe', 'paypal'."],
            'settings' => [
                'type' => 'object',
                'properties' => [
                    'title'       => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                ],
                'additionalProperties' => true,
            ],
        ],
        'required'   => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_woo_manage_payment_gateway_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

function wpultra_woo_manage_payment_gateway_cb(array $input) {
    if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    $res = wpultra_woo_gateway_manage($input);
    $action = (string) ($input['action'] ?? 'list');
    if (in_array($action, ['enable', 'disable', 'update-settings'], true)) {
        wpultra_audit_log('woo-manage-payment-gateway', $action . (is_wp_error($res) ? ' failed' : ''), !is_wp_error($res));
    }
    if (is_wp_error($res)) { return $res; }
    return wpultra_ok($res);
}
