<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/elementor-apply-class', [
    'label'       => __('Elementor: Apply Global Class', 'wp-ultra-mcp'),
    'description' => __('Apply or remove an Elementor global class on a specific element within a post.', 'wp-ultra-mcp'),
    'category'    => 'elementor',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'post_id'    => ['type' => 'integer'],
            'element_id' => ['type' => 'string'],
            'class_id'   => ['type' => 'string'],
            'remove'     => ['type' => 'boolean'],
        ],
        'required'             => ['post_id', 'element_id', 'class_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_elementor_apply_class',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_elementor_apply_class(array $input) {
    $post_id = (int) ($input['post_id'] ?? 0);
    $eid = (string) ($input['element_id'] ?? '');
    $cid = (string) ($input['class_id'] ?? '');
    if ($post_id <= 0 || $eid === '' || $cid === '') { return wpultra_err('bad_input', 'post_id, element_id, class_id are required.'); }
    return wpultra_el_apply_class($post_id, $eid, $cid, ($input['remove'] ?? false) === true);
}
