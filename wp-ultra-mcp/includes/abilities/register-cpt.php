<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/register-cpt', [
    'label'       => __('Register Custom Post Type', 'wp-ultra-mcp'),
    'description' => __('Registers a custom post type and persists the definition so it survives future requests. show_in_rest is always forced true.', 'wp-ultra-mcp'),
    'category'    => 'content',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'slug'         => ['type' => 'string'],
            'singular'     => ['type' => 'string'],
            'plural'       => ['type' => 'string'],
            'public'       => ['type' => 'boolean'],
            'supports'     => ['type' => 'array', 'items' => ['type' => 'string']],
            'has_archive'  => ['type' => 'boolean'],
            'hierarchical' => ['type' => 'boolean'],
            'menu_icon'    => ['type' => 'string'],
            'taxonomies'   => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required'             => ['slug', 'singular', 'plural'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'slug'    => ['type' => 'string'],
            'args'    => ['type' => 'object'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_register_cpt',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_register_cpt(array $input) {
    $result = wpultra_structure_register_cpt_persist($input);
    if (is_wp_error($result)) {
        wpultra_audit_log('register-cpt', "failed to register '" . (string) ($input['slug'] ?? '') . "'", false);
        return $result;
    }
    wpultra_audit_log('register-cpt', "registered CPT '{$result['slug']}'");
    return wpultra_ok(['slug' => $result['slug'], 'args' => (object) $result['args']]);
}
