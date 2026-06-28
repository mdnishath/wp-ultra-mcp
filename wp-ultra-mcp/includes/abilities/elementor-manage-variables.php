<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/elementor-manage-variables', [
    'label'       => __('Elementor: Manage Variables', 'wp-ultra-mcp'),
    'description' => __('List existing Elementor CSS variables or create a new one (color, font, or size).', 'wp-ultra-mcp'),
    'category'    => 'elementor',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['list', 'create'],
            ],
            'type'  => ['type' => 'string'],
            'label' => ['type' => 'string'],
            'value' => ['type' => 'string'],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'   => ['type' => 'boolean'],
            'variables' => ['type' => 'array'],
            'variable'  => ['type' => 'object'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_elementor_manage_variables',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_elementor_manage_variables(array $input) {
    $action = (string) ($input['action'] ?? '');
    if ($action === 'list') {
        $list = wpultra_el_variables_list();
        return is_wp_error($list) ? $list : wpultra_ok(['variables' => $list]);
    }
    if ($action === 'create') {
        $type = (string) ($input['type'] ?? '');
        $label = (string) ($input['label'] ?? '');
        if ($label === '') { return wpultra_err('missing_label', 'label is required to create a variable.'); }
        return wpultra_el_variables_create($type, $label, $input['value'] ?? '');
    }
    return wpultra_err('bad_action', "action must be 'list' or 'create'.");
}
