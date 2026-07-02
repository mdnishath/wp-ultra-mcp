<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/register-taxonomy', [
    'label'       => __('Register Taxonomy', 'wp-ultra-mcp'),
    'description' => __('Registers a custom taxonomy and persists the definition so it survives future requests. show_in_rest is always forced true.', 'wp-ultra-mcp'),
    'category'    => 'content',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'slug'         => ['type' => 'string'],
            'singular'     => ['type' => 'string'],
            'plural'       => ['type' => 'string'],
            'public'       => ['type' => 'boolean'],
            'hierarchical' => ['type' => 'boolean'],
            'object_types' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required'             => ['slug', 'singular', 'plural', 'object_types'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'      => ['type' => 'boolean'],
            'slug'         => ['type' => 'string'],
            'object_types' => ['type' => 'array'],
            'args'         => ['type' => 'object'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_register_taxonomy',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_register_taxonomy(array $input) {
    $result = wpultra_structure_register_taxonomy_persist($input);
    if (is_wp_error($result)) {
        wpultra_audit_log('register-taxonomy', "failed to register '" . (string) ($input['slug'] ?? '') . "'", false);
        return $result;
    }
    wpultra_audit_log('register-taxonomy', "registered taxonomy '{$result['slug']}'");
    return wpultra_ok(['slug' => $result['slug'], 'object_types' => $result['object_types'], 'args' => (object) $result['args']]);
}
