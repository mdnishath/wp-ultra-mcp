<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/elementor-list-global-classes', [
    'label'       => __('Elementor: List Global Classes', 'wp-ultra-mcp'),
    'description' => __('Return all Elementor global CSS classes registered in the active kit.', 'wp-ultra-mcp'),
    'category'    => 'elementor',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'        => ['type' => 'boolean'],
            'global_classes' => ['type' => 'array'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_elementor_list_global_classes',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_elementor_list_global_classes(array $input) {
    $list = wpultra_el_gc_list();
    return is_wp_error($list) ? $list : wpultra_ok(['global_classes' => $list]);
}
