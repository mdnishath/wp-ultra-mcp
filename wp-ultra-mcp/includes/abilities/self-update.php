<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/self-update', [
    'label'       => __('Self Update', 'wp-ultra-mcp'),
    'description' => __('Check GitHub for a newer WP-Ultra-MCP release, or apply it in place (action: update, confirm: true). The new code loads on the NEXT request — re-run any ability afterwards to use the updated version. The wp-admin Plugins page also shows the update natively.', 'wp-ultra-mcp'),
    'category'    => 'system',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['check', 'update'], 'description' => 'check (default) or update.'],
            'force'   => ['type' => 'boolean', 'description' => 'check only: bypass the 6h release cache.'],
            'confirm' => ['type' => 'boolean', 'description' => 'Required true for update.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'          => ['type' => 'boolean'],
            'current'          => ['type' => 'string'],
            'latest'           => ['type' => 'string'],
            'update_available' => ['type' => 'boolean'],
            'notes_url'        => ['type' => 'string'],
            'published'        => ['type' => 'string'],
            'updated'          => ['type' => 'boolean'],
            'from'             => ['type' => 'string'],
            'to'               => ['type' => 'string'],
            'note'             => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_self_update_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_self_update_cb(array $input) {
    $action = (string) ($input['action'] ?? 'check');
    if ($action === 'update') {
        if (empty($input['confirm'])) {
            return wpultra_err('confirm_required', 'Applying a self-update requires confirm: true.');
        }
        $res = wpultra_updater_apply();
        $ok  = !is_wp_error($res);
        wpultra_audit_log('self-update', $ok ? "updated {$res['from']} → {$res['to']}" : 'update failed: ' . $res->get_error_code(), $ok);
        return $ok ? wpultra_ok($res) : $res;
    }
    $res = wpultra_updater_check(!empty($input['force']));
    return is_wp_error($res) ? $res : wpultra_ok($res);
}
