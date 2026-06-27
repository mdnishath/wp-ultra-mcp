<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/skill-edit', [
    'label'       => __('Edit Skill', 'wp-ultra-mcp'),
    'description' => __('Surgically replace a unique string in a user skill body.', 'wp-ultra-mcp'),
    'category'    => 'skills',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'slug'       => ['type' => 'string'],
            'old_string' => ['type' => 'string'],
            'new_string' => ['type' => 'string'],
        ],
        'required'             => ['slug', 'old_string', 'new_string'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'slug'    => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_skill_edit',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

require_once WPULTRA_DIR . 'includes/skills/sources.php';
function wpultra_skill_edit(array $input) {
    $slug = (string) ($input['slug'] ?? '');
    $post = get_page_by_path($slug, OBJECT, 'wpultra_skill');
    if (!$post) { return wpultra_err('not_found', "No user skill '$slug' to edit."); }
    $old = (string) ($input['old_string'] ?? ''); $new = (string) ($input['new_string'] ?? '');
    if ($old === '') { return wpultra_err('empty_old_string', 'old_string must be non-empty.'); }
    $count = substr_count($post->post_content, $old);
    if ($count === 0) { return wpultra_err('not_found', 'old_string not found.'); }
    if ($count > 1) { return wpultra_err('not_unique', "old_string occurs $count times."); }
    $res = wp_update_post(['ID' => $post->ID, 'post_content' => str_replace($old, $new, $post->post_content)], true);
    if (is_wp_error($res)) { return $res; }
    return wpultra_ok(['slug' => $slug]);
}
