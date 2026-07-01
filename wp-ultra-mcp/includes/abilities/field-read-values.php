<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/field-read-values', [
    'label'       => __('Read Field Values', 'wp-ultra-mcp'),
    'description' => __('Reads custom-field values from a target (post/user/term/options) via the active field provider (ACF, Meta Box, or Pods). Omit fields[] to read all fields whose location applies to the target. format_values (default true) returns formatted values (images as arrays, related objects expanded); false returns raw stored data. Specify provider when more than one is active.', 'wp-ultra-mcp'),
    'category'    => 'fields',
    'input_schema'  => [
        'type' => 'object',
        'properties' => [
            'target' => [
                'type' => 'object',
                'properties' => [
                    'type' => ['type' => 'string', 'enum' => ['post', 'user', 'term', 'options']],
                    'id'   => ['type' => ['integer', 'string']],
                ],
                'required' => ['type'],
                'additionalProperties' => false,
            ],
            'fields'        => ['type' => 'array', 'items' => ['type' => 'string']],
            'format_values' => ['type' => 'boolean', 'default' => true],
            'provider'      => ['type' => 'string', 'enum' => ['acf', 'metabox', 'pods', 'auto']],
        ],
        'required' => ['target'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success'  => ['type' => 'boolean'],
            'provider' => ['type' => 'string'],
            'target'   => ['type' => 'object'],
            'values'   => ['type' => 'object'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_field_read_values',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_field_read_values(array $input) {
    $target = wpultra_fields_resolve_target((array) ($input['target'] ?? []));
    if (is_wp_error($target)) { return $target; }
    $provider = wpultra_fields_pick_provider($input['provider'] ?? null);
    if (is_wp_error($provider)) { return $provider; }
    $fields = isset($input['fields']) && is_array($input['fields']) ? array_values(array_map('strval', $input['fields'])) : null;
    if ($fields === []) { $fields = null; }
    $format = array_key_exists('format_values', $input) ? (bool) $input['format_values'] : true;
    $values = wpultra_fields_route('read', $provider, [$target, $fields, $format]);
    if (is_wp_error($values)) { return $values; }
    // Ensure an object (not a JSON array) even when empty.
    return wpultra_ok(['provider' => $provider, 'target' => $target, 'values' => (object) $values]);
}
