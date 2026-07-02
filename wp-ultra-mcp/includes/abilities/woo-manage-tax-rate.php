<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/woo-manage-tax-rate', [
    'label'       => __('WooCommerce: Manage Tax Rate', 'wp-ultra-mcp'),
    'description' => __('List/create/update/delete tax rates: country, state, postcode, city, rate, name, class, priority, compound, shipping.', 'wp-ultra-mcp'),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'action'   => ['type' => 'string', 'enum' => ['list', 'create', 'update', 'delete']],
            'id'       => ['type' => 'integer', 'description' => 'Tax rate id (required for update/delete).'],
            'country'  => ['type' => 'string'],
            'state'    => ['type' => 'string'],
            'postcode' => ['type' => 'string'],
            'city'     => ['type' => 'string'],
            'rate'     => ['type' => 'string', 'description' => 'Numeric percentage, e.g. "20.0000".'],
            'name'     => ['type' => 'string'],
            'class'    => ['type' => 'string', 'description' => 'Tax class slug, empty string for standard.'],
            'priority' => ['type' => 'integer'],
            'compound' => ['type' => 'boolean'],
            'shipping' => ['type' => 'boolean'],
            'confirm'  => ['type' => 'boolean', 'description' => 'Required true for delete.'],
        ],
        'required'   => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_woo_manage_tax_rate_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

function wpultra_woo_manage_tax_rate_cb(array $input) {
    if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    $res = wpultra_woo_tax_rate_manage($input);
    $action = (string) ($input['action'] ?? 'list');
    if (in_array($action, ['create', 'update', 'delete'], true)) {
        wpultra_audit_log('woo-manage-tax-rate', $action . (is_wp_error($res) ? ' failed' : ''), !is_wp_error($res));
    }
    if (is_wp_error($res)) { return $res; }
    return wpultra_ok($res);
}
