<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/pods-define-fields', [
    'label'       => __('Define Pods Fields', 'wp-ultra-mcp'),
    'description' => __('Create or extend a Pod and its fields, or delete a field. payload = `{pod, pod_type?(post_type|taxonomy|user|...), fields?:[{name,type,label?}], delete_field?}`. Creating a Pod requires pod_type when the Pod does not yet exist.', 'wp-ultra-mcp'),
    'category'    => 'fields',
    'input_schema'  => [
        'type' => 'object',
        'properties' => [
            'payload' => ['type' => 'object'],
            'mode'    => ['type' => 'string', 'enum' => ['create', 'update', 'delete'], 'default' => 'create'],
        ],
        'required' => ['payload'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'pod'     => ['type' => 'string'],
            'fields'  => ['type' => 'array', 'items' => ['type' => 'string']],
            'mode'    => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_pods_define_fields',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_pods_define_fields(array $input) {
    $payload = (array) ($input['payload'] ?? []);
    $mode = (string) ($input['mode'] ?? 'create');
    if (!in_array($mode, ['create', 'update', 'delete'], true)) { return wpultra_err('bad_mode', 'mode must be create, update, or delete'); }
    if (!$payload) { return wpultra_err('payload_required', 'payload is required'); }
    $res = wpultra_fields_pods_define($payload, $mode);
    if (is_wp_error($res)) { return $res; }
    wpultra_audit_log('pods-define-fields', "mode={$mode} pod={$res['pod']}");
    return wpultra_ok($res);
}
