<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/elementor-get-style-schema', [
    'label'       => __('Elementor: Get Style Schema', 'wp-ultra-mcp'),
    'description' => __('Return the global Elementor v4 style/props schema.', 'wp-ultra-mcp'),
    'category'    => 'elementor',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'      => ['type' => 'boolean'],
            'style_schema' => ['type' => 'object'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_elementor_get_style_schema',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_elementor_get_style_schema(array $input) {
    if (!wpultra_el_active()) { return wpultra_err('elementor_missing', 'Elementor is not active.'); }
    return wpultra_ok(['style_schema' => wpultra_el_style_schema()]);
}
