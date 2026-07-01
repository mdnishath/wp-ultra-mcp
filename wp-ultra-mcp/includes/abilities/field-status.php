<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/field-status', [
    'label'       => __('Field Plugins Status', 'wp-ultra-mcp'),
    'description' => __('Reports which custom-field providers (ACF, Meta Box, Pods) are active, their edition and version, and a capability matrix (manage CPT/taxonomy/options-page, complex field types, DB-stored groups). Call FIRST before any other field ability — an empty providers list means no field plugin is active.', 'wp-ultra-mcp'),
    'category'    => 'fields',
    'input_schema'  => [
        'type' => 'object',
        'properties' => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success'      => ['type' => 'boolean'],
            'active_count' => ['type' => 'integer'],
            'providers'    => ['type' => 'array'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_field_status',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_field_status(array $input) {
    $status = wpultra_fields_status();
    return wpultra_ok($status);
}
