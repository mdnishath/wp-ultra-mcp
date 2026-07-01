<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/metabox-define-field-group', [
    'label'       => __('Define Meta Box Field Group', 'wp-ultra-mcp'),
    'description' => __('Create/update/delete a Meta Box field group. Persisted in the `wpultra_mb_groups` option and registered on every request via the `rwmb_meta_boxes` filter (works on free Meta Box, which stores no groups in the DB). config = `{id, title, post_types[], fields:[{id,type,name?,...}]}`.', 'wp-ultra-mcp'),
    'category'    => 'fields',
    'input_schema'  => [
        'type' => 'object',
        'properties' => [
            'config' => ['type' => 'object'],
            'mode'   => ['type' => 'string', 'enum' => ['create', 'update', 'delete'], 'default' => 'create'],
        ],
        'required' => ['config'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'id'      => ['type' => 'string'],
            'mode'    => ['type' => 'string'],
            'count'   => ['type' => 'integer'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_metabox_define_field_group',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_metabox_define_field_group(array $input) {
    $config = (array) ($input['config'] ?? []);
    $mode = (string) ($input['mode'] ?? 'create');
    if (!in_array($mode, ['create', 'update', 'delete'], true)) { return wpultra_err('bad_mode', 'mode must be create, update, or delete'); }
    if (!$config) { return wpultra_err('config_required', 'config is required'); }
    $save_mode = $mode === 'delete' ? 'delete' : 'upsert';
    $res = wpultra_fields_mb_save_group($config, $save_mode);
    if (is_wp_error($res)) { return $res; }
    wpultra_audit_log('metabox-define-field-group', "mode={$mode} id={$res['id']}");
    return wpultra_ok($res);
}
