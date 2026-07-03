<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/bricks-move-element', [
    'label'       => __('Bricks: Move Element', 'wp-ultra-mcp'),
    'description' => __('Relocate an element (with its subtree) to a new parent ("0" = top level) and/or position. Cycle-guarded — an element can never be moved into its own subtree.', 'wp-ultra-mcp'),
    'category'    => 'bricks',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'post_id'    => ['type' => 'integer'],
            'element_id' => ['type' => 'string'],
            'parent_id'  => ['type' => 'string'],
            'position'   => ['type' => 'integer'],
        ],
        'required'             => ['post_id', 'element_id'],
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
    'execute_callback'    => 'wpultra_bricks_move_element_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_bricks_move_element_cb(array $input) {
    $post_id = (int) $input['post_id'];
    $res = wpultra_bricks_mutate($post_id, fn(array $elements) => wpultra_bricks_op_move(
        $elements,
        (string) $input['element_id'],
        (string) ($input['parent_id'] ?? '0'),
        (int) ($input['position'] ?? PHP_INT_MAX)
    ));
    if (is_wp_error($res)) { return $res; }
    wpultra_audit_log('bricks-move-element', "post $post_id ~> {$input['element_id']}", true);
    return wpultra_ok($res);
}
