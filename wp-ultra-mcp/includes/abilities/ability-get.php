<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/ability-get', [
    'label'       => __('Get Ability', 'wp-ultra-mcp'),
    'description' => __('Retrieve the raw recipe document for a declarative ability by slug.', 'wp-ultra-mcp'),
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
            'recipe'  => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_ability_get',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_ability_get(array $input) {
    $slug = (string) ($input['slug'] ?? '');
    $post = get_page_by_path($slug, OBJECT, 'wpultra_ability');
    if (!$post) { return wpultra_err('not_found', "No ability '$slug' found."); }
    return wpultra_ok(['slug' => $slug, 'recipe' => $post->post_content]);
}
