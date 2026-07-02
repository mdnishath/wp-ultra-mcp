<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/form-status', [
    'label'       => __('Form Plugins Status', 'wp-ultra-mcp'),
    'description' => __('Reports which form plugins (Contact Form 7, WPForms, Gravity Forms, Fluent Forms) are active, their version, per-plugin form counts, and whether each stores readable entries (CF7 needs the Flamingo plugin; WPForms entries need Pro). Call FIRST before any other form ability — an installed:false plugin cannot be used.', 'wp-ultra-mcp'),
    'category'    => 'forms',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'      => ['type' => 'boolean'],
            'active_count' => ['type' => 'integer'],
            'plugins'      => ['type' => 'array'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_form_status',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_form_status(array $input) {
    return wpultra_ok(wpultra_forms_status());
}
