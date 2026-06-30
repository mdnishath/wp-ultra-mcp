<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/woo-manage-product-category', [
    'label'       => __('WooCommerce: Manage Product Category/Tag', 'wp-ultra-mcp'),
    'description' => __('Create/update/delete/list product categories (or tags via taxonomy:tag).', 'wp-ultra-mcp'),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'action'      => ['type' => 'string', 'enum' => ['create', 'update', 'delete', 'list']],
            'taxonomy'    => ['type' => 'string', 'enum' => ['category', 'tag']],
            'id'          => ['type' => 'integer'],
            'name'        => ['type' => 'string'],
            'slug'        => ['type' => 'string'],
            'parent'      => ['type' => 'integer'],
            'description' => ['type' => 'string'],
        ],
        'required'   => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => ['success' => ['type' => 'boolean']],
        'required'   => ['success'],
    ],
    'execute_callback'    => 'wpultra_woo_manage_term_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_woo_manage_term_cb(array $input) {
    if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    $res = wpultra_woo_manage_term($input);
    $action = (string) ($input['action'] ?? 'list');
    if (in_array($action, ['create', 'update', 'delete'], true)) {
        wpultra_audit_log('woo-manage-product-category', $action, !is_wp_error($res));
    }
    if (is_wp_error($res)) { return $res; }
    return wpultra_ok($res);
}
