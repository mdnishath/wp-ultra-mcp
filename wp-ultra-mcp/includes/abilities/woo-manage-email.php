<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/woo-manage-email', [
    'label'       => __('WooCommerce: Manage Email', 'wp-ultra-mcp'),
    'description' => __('List/get/update WooCommerce transactional email templates (subject, heading, additional content, recipient, enabled, type) and get/update the global email design (from name/address, header image, footer text, colors). After updating, preview by sending a test via wpultra/send-email.', 'wp-ultra-mcp'),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'action'   => ['type' => 'string', 'enum' => ['list', 'get', 'update', 'get-globals', 'update-globals']],
            'email_id' => ['type' => 'string', 'description' => "Email id, e.g. 'customer_processing_order'. Required for get/update."],
            'settings' => [
                'type' => 'object',
                'description' => 'For update: enabled/subject/heading/additional_content/recipient/email_type. For update-globals: woocommerce_email_* design keys.',
                'additionalProperties' => true,
            ],
        ],
        'required'   => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_woo_manage_email_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

function wpultra_woo_manage_email_cb(array $input) {
    if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    $action = (string) ($input['action'] ?? '');

    if ($action === 'list') {
        return wpultra_ok(['emails' => wpultra_woo_email_list()]);
    }

    if ($action === 'get') {
        $id = (string) ($input['email_id'] ?? '');
        if ($id === '') { return wpultra_err('missing_email_id', "action 'get' requires email_id."); }
        $email = wpultra_woo_email_get($id);
        if ($email === null) { return wpultra_err('email_not_found', "No transactional email with id '$id'."); }
        return wpultra_ok(['email' => $email]);
    }

    if ($action === 'update') {
        $id = (string) ($input['email_id'] ?? '');
        if ($id === '') { return wpultra_err('missing_email_id', "action 'update' requires email_id."); }
        $settings = is_array($input['settings'] ?? null) ? $input['settings'] : [];
        $res = wpultra_woo_email_update($id, $settings);
        $ok = !is_wp_error($res);
        wpultra_audit_log('woo-manage-email', "update $id" . ($ok ? '' : ' failed'), $ok);
        if (is_wp_error($res)) { return $res; }
        return wpultra_ok($res);
    }

    if ($action === 'get-globals') {
        return wpultra_ok(['globals' => wpultra_woo_email_globals_get()]);
    }

    if ($action === 'update-globals') {
        $settings = is_array($input['settings'] ?? null) ? $input['settings'] : [];
        $res = wpultra_woo_email_globals_update($settings);
        wpultra_audit_log('woo-manage-email', 'update-globals', true);
        return wpultra_ok($res);
    }

    return wpultra_err('bad_action', "Unknown action '$action'.");
}
