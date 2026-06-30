<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/woo-manage-attribute', [
    'label'       => __('WooCommerce: Manage Attribute', 'wp-ultra-mcp'),
    'description' => __('Create/update/delete/list global product attributes (pa_*) and seed their terms.', 'wp-ultra-mcp'),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'action' => ['type' => 'string', 'enum' => ['create', 'update', 'delete', 'list']],
            'id'     => ['type' => 'integer'],
            'name'   => ['type' => 'string'],
            'slug'   => ['type' => 'string'],
            'type'   => ['type' => 'string'],
            'terms'  => ['type' => 'array'],
        ],
        'required'   => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => ['success' => ['type' => 'boolean']],
        'required'   => ['success'],
    ],
    'execute_callback'    => 'wpultra_woo_manage_attribute_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_woo_manage_attribute_cb(array $input) {
    if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    $res = wpultra_woo_manage_attribute($input);
    $action = (string) ($input['action'] ?? 'list');
    if (in_array($action, ['create', 'update', 'delete'], true)) {
        wpultra_audit_log('woo-manage-attribute', $action, !is_wp_error($res));
    }
    if (is_wp_error($res)) { return $res; }
    return wpultra_ok($res);
}
