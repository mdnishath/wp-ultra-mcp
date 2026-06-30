<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/woo-update-settings', [
    'label'       => __('WooCommerce: Update Settings', 'wp-ultra-mcp'),
    'description' => __('Update WHITELISTED store options (currency, country, units, tax/coupon toggles, store address) and enable/disable a payment gateway. Non-whitelisted keys are rejected.', 'wp-ultra-mcp'),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type'       => 'object',
        'properties' => ['options' => ['type' => 'object'], 'gateway' => ['type' => 'object']],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean'], 'updated' => ['type' => 'object'], 'rejected' => ['type' => 'array']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_woo_update_settings_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

function wpultra_woo_update_settings_cb(array $input) {
    if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    $res = wpultra_woo_update_settings($input);
    wpultra_audit_log('woo-update-settings', 'updated ' . count($res['updated']) . ' rejected ' . count($res['rejected']), true);
    return wpultra_ok($res);
}
