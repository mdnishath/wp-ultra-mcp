<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/woo-manage-subscription', [
    'label'       => __('WooCommerce: Manage Subscription', 'wp-ultra-mcp'),
    'description' => __('List/get/set-status recurring subscriptions. Requires the WooCommerce Subscriptions plugin — returns a subscriptions_unavailable error otherwise. action=list filters by status/customer_id/page/per_page. action=set-status accepts status in active|on-hold|cancelled|pending-cancel|expired (pass confirm:true when cancelling).', 'wp-ultra-mcp'),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'action'      => ['type' => 'string', 'enum' => ['list', 'get', 'set-status']],
            'id'          => ['type' => 'integer'],
            'status'      => ['type' => 'string'],
            'customer_id' => ['type' => 'integer'],
            'page'        => ['type' => 'integer'],
            'per_page'    => ['type' => 'integer'],
            'confirm'     => ['type' => 'boolean'],
        ],
        'required'   => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_woo_manage_subscription_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

function wpultra_woo_manage_subscription_cb(array $input) {
    $action = (string) ($input['action'] ?? 'list');

    if ($action === 'list') {
        $res = wpultra_woo_ext_subscriptions_list($input);
        if (is_wp_error($res)) { return $res; }
        return wpultra_ok($res);
    }

    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) { return wpultra_err('missing_id', 'id is required for get/set-status.'); }

    if ($action === 'get') {
        $res = wpultra_woo_ext_subscription_get($id);
        if (is_wp_error($res)) { return $res; }
        return wpultra_ok($res);
    }

    if ($action === 'set-status') {
        $status = (string) ($input['status'] ?? '');
        if ($status === '') { return wpultra_err('missing_status', 'status is required for set-status.'); }
        if ($status === 'cancelled' && empty($input['confirm'])) {
            return wpultra_err('confirm_required', 'Cancelling a subscription requires confirm:true.');
        }
        $res = wpultra_woo_ext_subscription_set_status($id, $status);
        wpultra_audit_log('woo-manage-subscription', "set-status $id -> $status" . (is_wp_error($res) ? ' failed' : ''), !is_wp_error($res));
        if (is_wp_error($res)) { return $res; }
        return wpultra_ok($res);
    }

    return wpultra_err('unknown_action', "Unknown action: $action");
}
