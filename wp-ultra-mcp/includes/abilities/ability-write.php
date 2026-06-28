<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

require_once WPULTRA_DIR . 'includes/recipes/parser.php';

wp_register_ability('wpultra/ability-write', [
    'label'       => __('Write Ability', 'wp-ultra-mcp'),
    'description' => __('Create or replace a declarative ability recipe by submitting a raw recipe document.', 'wp-ultra-mcp'),
    'category'    => 'custom',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'recipe' => ['type' => 'string'],
        ],
        'required'             => ['recipe'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'slug'    => ['type' => 'string'],
            'post_id' => ['type' => 'integer'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_ability_write',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_ability_write(array $input) {
    $raw = (string) ($input['recipe'] ?? '');
    $parsed = wpultra_recipe_parse($raw);
    if (is_wp_error($parsed)) { return $parsed; }
    $valid = wpultra_recipe_validate($parsed);
    if (is_wp_error($valid)) { return $valid; }
    $slug = sanitize_title($parsed['name']);
    $existing = get_page_by_path($slug, OBJECT, 'wpultra_ability');
    $arr = [
        'post_type'    => 'wpultra_ability',
        'post_status'  => 'publish',
        'post_title'   => $slug,
        'post_name'    => $slug,
        'post_excerpt' => $parsed['description'],
        'post_content' => $raw,
    ];
    if ($existing) { $arr['ID'] = $existing->ID; }
    $id = wp_insert_post($arr, true);
    if (is_wp_error($id)) { return $id; }
    return wpultra_ok(['slug' => $slug, 'post_id' => (int) $id]);
}
