<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/trigger-delete', [
    'label'       => __('Delete / Toggle Event Trigger', 'wp-ultra-mcp'),
    'description' => __('Delete an event trigger by id (default), or enable/disable it without deleting via action:enable | disable. Idempotent.', 'wp-ultra-mcp'),
    'category'    => 'triggers',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'id'     => ['type' => 'integer'],
            'action' => ['type' => 'string', 'enum' => ['delete', 'enable', 'disable']],
        ],
        'required'             => ['id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'id'      => ['type' => 'integer'],
            'action'  => ['type' => 'string'],
            'changed' => ['type' => 'boolean'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_trigger_delete_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => true],
    ],
]);

function wpultra_trigger_delete_cb(array $input) {
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) { return wpultra_err('missing_id', 'id is required.'); }
    $action = (string) ($input['action'] ?? 'delete');

    if ($action === 'enable' || $action === 'disable') {
        $changed = wpultra_triggers_set_enabled($id, $action === 'enable');
        if ($changed) { wpultra_audit_log('trigger-toggle', "$action trigger #$id", true); }
        return wpultra_ok(['id' => $id, 'action' => $action, 'changed' => $changed]);
    }

    $changed = wpultra_triggers_delete($id);
    if ($changed) { wpultra_audit_log('trigger-delete', "deleted trigger #$id", true); }
    return wpultra_ok(['id' => $id, 'action' => 'delete', 'changed' => $changed]);
}
