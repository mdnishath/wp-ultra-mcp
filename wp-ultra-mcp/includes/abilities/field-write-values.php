<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/field-write-values', [
    'label'       => __('Write Field Values', 'wp-ultra-mcp'),
    'description' => __('Writes a batch of custom-field values to a target (post/user/term/options) via the active field provider (ACF, Meta Box, or Pods). Atomic fields take the value directly: {"subtitle":"Hi"}. Complex fields (repeater/group/gallery/relationship) require a consent wrapper {"features":{"value":[...],"mode":"replace"}} because writing replaces the whole value. Specify provider when more than one is active. Writes go through each plugin native updater so its hooks fire.', 'wp-ultra-mcp'),
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
            'values'   => ['type' => 'object'],
            'provider' => ['type' => 'string', 'enum' => ['acf', 'metabox', 'pods', 'auto']],
        ],
        'required' => ['target', 'values'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success'  => ['type' => 'boolean'],
            'provider' => ['type' => 'string'],
            'results'  => ['type' => 'object'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_field_write_values',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_field_write_values(array $input) {
    $target = wpultra_fields_resolve_target((array) ($input['target'] ?? []));
    if (is_wp_error($target)) { return $target; }
    $values = (array) ($input['values'] ?? []);
    if (!$values) { return wpultra_err('values_empty', 'values must be a non-empty object'); }
    $batch = wpultra_fields_normalize_batch($values);
    if (is_wp_error($batch)) { return $batch; }
    $provider = wpultra_fields_pick_provider($input['provider'] ?? null);
    if (is_wp_error($provider)) { return $provider; }
    $results = wpultra_fields_route('write', $provider, [$target, $batch['atomic'], $batch['complex']]);
    if (is_wp_error($results)) { return $results; }
    wpultra_audit_log('field-write-values', "provider={$provider} target={$target['type']}:{$target['id']} fields=" . implode(',', array_keys($values)));
    return wpultra_ok(['provider' => $provider, 'results' => (object) $results]);
}
