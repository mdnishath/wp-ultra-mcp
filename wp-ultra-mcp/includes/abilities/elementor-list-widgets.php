<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/elementor-list-widgets', [
    'label'       => __('Elementor: List Widgets', 'wp-ultra-mcp'),
    'description' => __('List all registered Elementor widgets with optional atomic-only filter.', 'wp-ultra-mcp'),
    'category'    => 'elementor',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'atomic_only' => ['type' => 'boolean'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'widgets' => ['type' => 'array'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_elementor_list_widgets',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_elementor_list_widgets(array $input) {
    if (!wpultra_el_active()) { return wpultra_err('elementor_missing', 'Elementor is not installed/active.'); }
    return wpultra_ok(['widgets' => wpultra_el_list_widgets(['atomic_only' => ($input['atomic_only'] ?? false) === true])]);
}
