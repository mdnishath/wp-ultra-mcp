<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/elementor-list-dynamic-tags', [
    'label'       => __('Elementor: List Dynamic Tags', 'wp-ultra-mcp'),
    'description' => __('List all registered Elementor dynamic tag groups and their tags.', 'wp-ultra-mcp'),
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
            'dynamic_tags' => ['type' => 'array'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_elementor_list_dynamic_tags',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_elementor_list_dynamic_tags(array $input) {
    if (!class_exists('\\Elementor\\Plugin')) { return wpultra_err('elementor_missing', 'Elementor is not active.'); }
    return wpultra_ok(['dynamic_tags' => wpultra_el_list_dynamic_tags()]);
}
