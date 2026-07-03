<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/bricks-get-element-schema', [
    'label'       => __('Bricks: Get Element Schema', 'wp-ultra-mcp'),
    'description' => __('Introspect one Bricks element\'s control schema from Bricks\' own registry (control key → type, label, default, options). Requires a live Bricks install; use before setting unfamiliar element settings so you never guess control keys.', 'wp-ultra-mcp'),
    'category'    => 'bricks',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => ['element' => ['type' => 'string']],
        'required'             => ['element'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'  => ['type' => 'boolean'],
            'element'  => ['type' => 'string'],
            'label'    => ['type' => 'string'],
            'controls' => ['type' => 'object'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_bricks_get_element_schema_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_bricks_get_element_schema_cb(array $input) {
    $res = wpultra_bricks_element_schema((string) $input['element']);
    return is_wp_error($res) ? $res : wpultra_ok($res);
}
