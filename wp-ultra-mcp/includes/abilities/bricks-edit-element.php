<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/bricks-edit-element', [
    'label'       => __('Bricks: Edit Element', 'wp-ultra-mcp'),
    'description' => __('Deep-merge new settings into one Bricks element (siblings and untouched keys survive). Use bricks-get-content to find element ids and bricks-get-element-schema (live Bricks) for the control keys.', 'wp-ultra-mcp'),
    'category'    => 'bricks',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'post_id'    => ['type' => 'integer'],
            'element_id' => ['type' => 'string'],
            'settings'   => ['type' => 'object'],
            'deep'       => ['type' => 'boolean', 'description' => 'Deep-merge assoc settings (default true); false = replace listed keys wholesale.'],
        ],
        'required'             => ['post_id', 'element_id', 'settings'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'  => ['type' => 'boolean'],
            'count'    => ['type' => 'integer'],
            'elements' => ['type' => 'array'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_bricks_edit_element_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_bricks_edit_element_cb(array $input) {
    $post_id = (int) $input['post_id'];
    $res = wpultra_bricks_mutate($post_id, fn(array $elements) => wpultra_bricks_op_edit(
        $elements,
        (string) $input['element_id'],
        (array) $input['settings'],
        array_key_exists('deep', $input) ? ($input['deep'] === true) : true
    ));
    if (is_wp_error($res)) { return $res; }
    wpultra_audit_log('bricks-edit-element', "post $post_id ~ {$input['element_id']}", true);
    return wpultra_ok($res);
}
