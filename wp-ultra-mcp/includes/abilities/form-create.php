<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/form-create', [
    'label'       => __('Create Form', 'wp-ultra-mcp'),
    'description' => __('Creates a new form in the chosen plugin (`plugin` required: cf7|wpforms|gravity|fluent) from a unified `fields[]` list. Each field: type (text|email|textarea|select|checkbox|radio|number|date|file), label, required, options[] (for select/checkbox/radio). Each adapter maps the unified fields to its native format. Returns the new form id + shortcode.', 'wp-ultra-mcp'),
    'category'    => 'forms',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'plugin' => ['type' => 'string', 'enum' => ['cf7', 'wpforms', 'gravity', 'fluent']],
            'title'  => ['type' => 'string'],
            'fields' => [
                'type'  => 'array',
                'items' => [
                    'type'       => 'object',
                    'properties' => [
                        'type'     => ['type' => 'string', 'enum' => ['text', 'email', 'textarea', 'select', 'checkbox', 'radio', 'number', 'date', 'file']],
                        'label'    => ['type' => 'string'],
                        'required' => ['type' => 'boolean'],
                        'options'  => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                    'required'             => ['type', 'label'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'required'             => ['plugin', 'title', 'fields'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'   => ['type' => 'boolean'],
            'id'        => ['type' => 'integer'],
            'title'     => ['type' => 'string'],
            'plugin'    => ['type' => 'string'],
            'shortcode' => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_form_create',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_form_create(array $input) {
    $title  = trim((string) ($input['title'] ?? ''));
    if ($title === '') { return wpultra_err('missing_title', 'title is required.'); }
    $fields = (array) ($input['fields'] ?? []);
    if ($fields === []) { return wpultra_err('missing_fields', 'fields[] must contain at least one field.'); }

    $explicit = (string) ($input['plugin'] ?? '');
    if ($explicit === '') { return wpultra_err('missing_plugin', 'plugin is required (cf7|wpforms|gravity|fluent).'); }
    $driver = wpultra_forms_driver($explicit);
    if (is_wp_error($driver)) { return $driver; }

    $fn = "wpultra_forms_{$driver}_create";
    if (!function_exists($fn)) {
        return wpultra_err('forms_unavailable', "No creator for plugin '{$driver}'.");
    }
    $result = $fn($title, $fields);
    $ok = !is_wp_error($result);
    wpultra_audit_log('form-create', "create {$driver} form '{$title}' (" . count($fields) . ' fields)', $ok);
    if (!$ok) { return $result; }
    return wpultra_ok($result);
}
