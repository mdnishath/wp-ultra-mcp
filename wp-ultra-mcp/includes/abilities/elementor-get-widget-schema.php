<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/elementor-get-widget-schema', [
    'label'       => __('Elementor: Get Widget Schema', 'wp-ultra-mcp'),
    'description' => __('Return the full JSON schema for a specific Elementor widget type.', 'wp-ultra-mcp'),
    'category'    => 'elementor',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'widget_type' => ['type' => 'string'],
        ],
        'required'             => ['widget_type'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_elementor_get_widget_schema',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_elementor_get_widget_schema(array $input) {
    $type = (string) ($input['widget_type'] ?? '');
    if ($type === '') { return wpultra_err('missing_widget_type', 'widget_type is required.'); }
    $s = wpultra_el_widget_schema($type);
    if (is_wp_error($s)) { return $s; }
    return wpultra_ok($s);
}
