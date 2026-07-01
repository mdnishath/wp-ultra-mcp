<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/acf-define-field-group', [
    'label'       => __('Define ACF Field Group', 'wp-ultra-mcp'),
    'description' => __('Create/update/delete an ACF field group from a native ACF export payload (`{key?, title, fields[], location[][], ...}`). Pro-only field types (repeater/flexible_content/gallery/clone/group) are rejected on ACF free.', 'wp-ultra-mcp'),
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
            'key'     => ['type' => 'string'],
            'id'      => ['type' => 'integer'],
            'mode'    => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_acf_define_field_group',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_acf_define_field_group(array $input) {
    $payload = (array) ($input['payload'] ?? []);
    $mode = (string) ($input['mode'] ?? 'create');
    if (!in_array($mode, ['create', 'update', 'delete'], true)) { return wpultra_err('bad_mode', 'mode must be create, update, or delete'); }
    if (!$payload) { return wpultra_err('payload_required', 'payload is required'); }
    $res = wpultra_fields_acf_define_group($payload, $mode);
    if (is_wp_error($res)) { return $res; }
    wpultra_audit_log('acf-define-field-group', "mode={$mode} key={$res['key']}");
    return wpultra_ok($res);
}
