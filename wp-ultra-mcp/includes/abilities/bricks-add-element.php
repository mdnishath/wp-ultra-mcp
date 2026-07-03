<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/bricks-add-element', [
    'label'       => __('Bricks: Add Element', 'wp-ultra-mcp'),
    'description' => __('Insert a new element into a Bricks page: {name (e.g. section, container, block, heading, text-basic, button, image — see bricks-list-elements), settings?} under parent_id ("0"/omitted = top level) at position. A fresh Bricks-style id is generated; parent/children consistency is enforced and the result re-validated before writing.', 'wp-ultra-mcp'),
    'category'    => 'bricks',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'post_id'   => ['type' => 'integer'],
            'name'      => ['type' => 'string'],
            'settings'  => ['type' => 'object'],
            'parent_id' => ['type' => 'string'],
            'position'  => ['type' => 'integer'],
        ],
        'required'             => ['post_id', 'name'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'  => ['type' => 'boolean'],
            'id'       => ['type' => 'string'],
            'count'    => ['type' => 'integer'],
            'elements' => ['type' => 'array'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_bricks_add_element_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_bricks_add_element_cb(array $input) {
    $post_id = (int) $input['post_id'];
    $new_id = '';
    $res = wpultra_bricks_mutate($post_id, function (array $elements) use ($input, &$new_id) {
        $new_id = wpultra_bricks_new_id(array_keys(wpultra_bricks_index($elements)));
        $node = [
            'id'       => $new_id,
            'name'     => (string) $input['name'],
            'settings' => (array) ($input['settings'] ?? []),
            'children' => [],
        ];
        return wpultra_bricks_op_insert($elements, $node, (string) ($input['parent_id'] ?? '0'), (int) ($input['position'] ?? PHP_INT_MAX));
    });
    if (is_wp_error($res)) { return $res; }
    wpultra_audit_log('bricks-add-element', "post $post_id += {$input['name']} ($new_id)", true);
    return wpultra_ok(['id' => $new_id] + $res);
}
