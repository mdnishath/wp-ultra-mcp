<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/ability-delete', [
    'label'       => __('Delete Ability', 'wp-ultra-mcp'),
    'description' => __('Delete a declarative ability recipe by slug. Idempotent — returns deleted:false if not found.', 'wp-ultra-mcp'),
    'category'    => 'custom',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'slug' => ['type' => 'string'],
        ],
        'required'             => ['slug'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'slug'    => ['type' => 'string'],
            'deleted' => ['type' => 'boolean'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_ability_delete',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => true],
    ],
]);

function wpultra_ability_delete(array $input) {
    $slug = (string) ($input['slug'] ?? '');
    $post = get_page_by_path($slug, OBJECT, 'wpultra_ability');
    if (!$post) { return wpultra_ok(['slug' => $slug, 'deleted' => false]); }
    wp_delete_post($post->ID, true);
    return wpultra_ok(['slug' => $slug, 'deleted' => true]);
}
