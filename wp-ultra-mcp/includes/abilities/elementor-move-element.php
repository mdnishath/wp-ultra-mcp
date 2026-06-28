<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/elementor-move-element', [
    'label'       => __('Elementor: Move Element', 'wp-ultra-mcp'),
    'description' => __('Relocate an existing Elementor element to a different parent and/or position within the tree.', 'wp-ultra-mcp'),
    'category'    => 'elementor',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'post_id'      => ['type' => 'integer'],
            'element_id'   => ['type' => 'string'],
            'to_parent_id' => ['type' => 'string'],
            'position'     => ['type' => 'integer'],
        ],
        'required'             => ['post_id', 'element_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'    => ['type' => 'boolean'],
            'post_id'    => ['type' => 'integer'],
            'element_id' => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_elementor_move_element',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_elementor_move_element(array $input) {
    $post_id = (int) ($input['post_id'] ?? 0);
    $eid = (string) ($input['element_id'] ?? '');
    if ($post_id <= 0 || $eid === '') { return wpultra_err('bad_input', 'post_id and element_id are required.'); }
    $to = isset($input['to_parent_id']) && $input['to_parent_id'] !== '' ? (string) $input['to_parent_id'] : null;
    $pos = (int) ($input['position'] ?? PHP_INT_MAX);
    $updated = wpultra_el_move(wpultra_el_raw($post_id), $eid, $to, $pos);
    if (is_wp_error($updated)) { return $updated; }
    $w = wpultra_el_write($post_id, $updated);
    if (is_wp_error($w)) { return $w; }
    return wpultra_ok(['post_id' => $post_id, 'element_id' => $eid]);
}
