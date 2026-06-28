<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/elementor-get-design-system', [
    'label'       => __('Elementor: Get Design System', 'wp-ultra-mcp'),
    'description' => __('Read the active Elementor design system: colors, typography tokens, and CSS variables.', 'wp-ultra-mcp'),
    'category'    => 'elementor',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'    => ['type' => 'boolean'],
            'colors'     => ['type' => 'array'],
            'typography' => ['type' => 'array'],
            'variables'  => ['type' => 'object'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_elementor_get_design_system',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_elementor_get_design_system(array $input) {
    return wpultra_el_get_design_system();
}
