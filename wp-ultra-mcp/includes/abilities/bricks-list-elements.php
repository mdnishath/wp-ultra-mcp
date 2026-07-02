<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/bricks-list-elements', [
    'label'       => __('Bricks: List Elements', 'wp-ultra-mcp'),
    'description' => __('List all registered Bricks element types (name, label, category). Returns an empty list gracefully when Bricks is not active.', 'wp-ultra-mcp'),
    'category'    => 'bricks',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'  => ['type' => 'boolean'],
            'elements' => ['type' => 'array'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_bricks_list_elements_ability',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_bricks_list_elements_ability(array $input) {
    if (!wpultra_bricks_active()) { return wpultra_err('bricks_unavailable', 'Bricks is not installed/active on this site.'); }
    return wpultra_ok(['elements' => wpultra_bricks_list_elements()]);
}
