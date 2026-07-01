<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/field-list-groups', [
    'label'       => __('List Field Groups', 'wp-ultra-mcp'),
    'description' => __('Lists custom field groups across all active field providers (ACF, Meta Box, Pods). Optionally filter to one provider. Each entry gives key, title, provider, field_count, and location binding.', 'wp-ultra-mcp'),
    'category'    => 'fields',
    'input_schema'  => [
        'type' => 'object',
        'properties' => [ 'provider' => ['type' => 'string', 'enum' => ['acf', 'metabox', 'pods']] ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => ['success' => ['type' => 'boolean'], 'groups' => ['type' => 'array']],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_field_list_groups',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

function wpultra_field_list_groups(array $input) {
    $filter = isset($input['provider']) ? (string) $input['provider'] : '';
    $groups = [];
    foreach (wpultra_fields_providers() as $p) {
        $name = $p['provider'];
        if ($filter !== '' && $filter !== $name) { continue; }
        $fn = "wpultra_fields_{$name}_list_groups";
        if (function_exists($fn)) { $groups = array_merge($groups, $fn()); }
    }
    return wpultra_ok(['groups' => $groups]);
}
