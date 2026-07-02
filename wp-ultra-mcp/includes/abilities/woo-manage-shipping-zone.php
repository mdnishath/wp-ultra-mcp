<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/woo-manage-shipping-zone', [
    'label'       => __('WooCommerce: Manage Shipping Zone', 'wp-ultra-mcp'),
    'description' => __('List/get/create/update/delete shipping zones and add/update/remove their methods (flat_rate, free_shipping, local_pickup) with settings (title, cost, min_amount).', 'wp-ultra-mcp'),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'action'      => ['type' => 'string', 'enum' => ['list', 'get', 'create', 'update', 'delete', 'add-method', 'update-method', 'remove-method']],
            'id'          => ['type' => 'integer', 'description' => 'Shipping zone id.'],
            'name'        => ['type' => 'string'],
            'order'       => ['type' => 'integer'],
            'locations'   => ['type' => 'array', 'items' => ['type' => 'object']],
            'method_id'   => ['type' => 'string', 'enum' => ['flat_rate', 'free_shipping', 'local_pickup']],
            'instance_id' => ['type' => 'integer'],
            'settings'    => [
                'type' => 'object',
                'properties' => [
                    'title'      => ['type' => 'string'],
                    'cost'       => ['type' => 'string'],
                    'min_amount' => ['type' => 'string'],
                ],
                'additionalProperties' => true,
            ],
            'confirm' => ['type' => 'boolean', 'description' => 'Required true for delete/remove-method.'],
        ],
        'required'   => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_woo_manage_shipping_zone_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

function wpultra_woo_manage_shipping_zone_cb(array $input) {
    if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    $res = wpultra_woo_shipping_zone_manage($input);
    $action = (string) ($input['action'] ?? 'list');
    if (in_array($action, ['create', 'update', 'delete', 'add-method', 'update-method', 'remove-method'], true)) {
        wpultra_audit_log('woo-manage-shipping-zone', $action . (is_wp_error($res) ? ' failed' : ''), !is_wp_error($res));
    }
    if (is_wp_error($res)) { return $res; }
    return wpultra_ok($res);
}
