<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/elementor-manage-global-colors', [
    'label'       => __('Elementor: Manage Global Colors', 'wp-ultra-mcp'),
    'description' => __('Add or overwrite Elementor global color tokens in the active kit (custom or system palette).', 'wp-ultra-mcp'),
    'category'    => 'elementor',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'colors' => [
                'type'  => 'array',
                'items' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'    => ['type' => 'string'],
                        'title' => ['type' => 'string'],
                        'color' => ['type' => 'string'],
                    ],
                    'required'             => ['title', 'color'],
                    'additionalProperties' => false,
                ],
            ],
            'target' => [
                'type' => 'string',
                'enum' => ['custom', 'system'],
            ],
        ],
        'required'             => ['colors'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'       => ['type' => 'boolean'],
            'custom_colors' => ['type' => 'array'],
            'system_colors' => ['type' => 'array'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_elementor_manage_global_colors',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_elementor_manage_global_colors(array $input) {
    $colors = $input['colors'] ?? null;
    if (!is_array($colors) || $colors === []) { return wpultra_err('bad_colors', 'colors must be a non-empty array of {title,color}.'); }
    $target = (string) ($input['target'] ?? 'custom');
    if (!in_array($target, ['custom', 'system'], true)) { $target = 'custom'; }
    return wpultra_el_set_global_colors($colors, $target);
}
