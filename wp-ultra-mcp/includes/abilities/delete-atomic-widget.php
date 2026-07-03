<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/delete-atomic-widget', [
    'label'       => __('Delete Custom Atomic Widget', 'wp-ultra-mcp'),
    'description' => __('Delete a generated custom atomic widget (its PHP class, Twig template, and assets) and clear any crash quarantine. Pages that still reference the element type will render it empty. Requires confirm: true.', 'wp-ultra-mcp'),
    'category'    => 'elementor',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'name'    => ['type' => 'string'],
            'confirm' => ['type' => 'boolean'],
        ],
        'required'             => ['name', 'confirm'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'name'    => ['type' => 'string'],
            'deleted' => ['type' => 'boolean'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_delete_atomic_widget_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => true],
    ],
]);

function wpultra_delete_atomic_widget_cb(array $input) {
    if (($input['confirm'] ?? false) !== true) {
        return wpultra_err('confirm_required', 'Deleting a widget requires confirm: true.');
    }
    $name = (string) ($input['name'] ?? '');
    if (!wpultra_widget_valid_name($name)) { return wpultra_err('bad_name', 'Invalid widget name.'); }
    $deleted = wpultra_widget_delete_files($name);
    if ($deleted) { wpultra_audit_log('delete-atomic-widget', "deleted widget wpu-$name", true); }
    return wpultra_ok(['name' => $name, 'deleted' => $deleted]);
}
