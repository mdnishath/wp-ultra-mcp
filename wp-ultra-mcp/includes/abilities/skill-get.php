<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/skill-get', [
    'label'       => __('Get Skill', 'wp-ultra-mcp'),
    'description' => __('Retrieve the full body of a skill by slug.', 'wp-ultra-mcp'),
    'category'    => 'skills',
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
            'success'     => ['type' => 'boolean'],
            'slug'        => ['type' => 'string'],
            'body'        => ['type' => 'string'],
            'description' => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_skill_get',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

require_once WPULTRA_DIR . 'includes/skills/sources.php';
function wpultra_skill_get(array $input) {
    $slug = (string) ($input['slug'] ?? '');
    $all = wpultra_skill_all();
    if (!isset($all[$slug])) { return wpultra_err('not_found', "No skill '$slug'."); }
    return wpultra_ok(['slug' => $slug, 'body' => $all[$slug]['body'] ?? '', 'description' => $all[$slug]['description'] ?? '']);
}
