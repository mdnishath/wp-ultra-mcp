<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/field-get-group', [
    'label'       => __('Get Field Group', 'wp-ultra-mcp'),
    'description' => __('Returns the full schema (fields with key/name/label/type + location) of one custom field group by key. Specify provider to disambiguate; otherwise each active provider is searched.', 'wp-ultra-mcp'),
    'category'    => 'fields',
    'input_schema'  => [
        'type' => 'object',
        'properties' => [
            'key'      => ['type' => 'string'],
            'provider' => ['type' => 'string', 'enum' => ['acf', 'metabox', 'pods']],
        ],
        'required' => ['key'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => ['success' => ['type' => 'boolean'], 'group' => ['type' => 'object']],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_field_get_group',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

function wpultra_field_get_group(array $input) {
    $key = (string) ($input['key'] ?? '');
    if ($key === '') { return wpultra_err('key_required', 'key is required'); }
    $only = isset($input['provider']) ? (string) $input['provider'] : '';
    foreach (wpultra_fields_providers() as $p) {
        $name = $p['provider'];
        if ($only !== '' && $only !== $name) { continue; }
        $fn = "wpultra_fields_{$name}_get_group";
        if (!function_exists($fn)) { continue; }
        $g = $fn($key);
        if (!is_wp_error($g)) { return wpultra_ok(['group' => (object) $g]); }
    }
    return wpultra_err('group_not_found', "No field group '{$key}' found in the active provider(s).");
}
