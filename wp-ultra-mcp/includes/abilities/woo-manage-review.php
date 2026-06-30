<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/woo-manage-review', [
    'label'       => __('WooCommerce: Manage Review', 'wp-ultra-mcp'),
    'description' => __('List/create/approve/unapprove/spam/trash/delete product reviews. create needs product_id, content, author, email, optional rating 1-5.', 'wp-ultra-mcp'),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'action'     => ['type' => 'string', 'enum' => ['list', 'create', 'approve', 'unapprove', 'spam', 'trash', 'delete']],
            'id'         => ['type' => 'integer'],
            'product_id' => ['type' => 'integer'],
            'status'     => ['type' => 'string'],
            'author'     => ['type' => 'string'],
            'email'      => ['type' => 'string'],
            'content'    => ['type' => 'string'],
            'rating'     => ['type' => 'integer'],
            'force'      => ['type' => 'boolean'],
            'per_page'   => ['type' => 'integer'],
        ],
        'required'   => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_woo_manage_review_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

function wpultra_woo_manage_review_cb(array $input) {
    if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    $res = wpultra_woo_manage_review($input);
    $action = (string) ($input['action'] ?? 'list');
    if ($action !== 'list') {
        wpultra_audit_log('woo-manage-review', $action . (is_wp_error($res) ? ' failed' : ''), !is_wp_error($res));
    }
    if (is_wp_error($res)) { return $res; }
    return wpultra_ok($res);
}
