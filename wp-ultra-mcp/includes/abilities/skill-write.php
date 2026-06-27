<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/skill-write', [
    'label'       => __('Write Skill', 'wp-ultra-mcp'),
    'description' => __('Create or replace a user skill document.', 'wp-ultra-mcp'),
    'category'    => 'skills',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'slug'           => ['type' => 'string'],
            'description'    => ['type' => 'string'],
            'body'           => ['type' => 'string'],
            'enable_prompt'  => ['type' => 'boolean'],
            'enable_agentic' => ['type' => 'boolean'],
            'on_conflict'    => ['type' => 'string', 'enum' => ['fail', 'replace']],
        ],
        'required'             => ['slug', 'body'],
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
    'execute_callback'    => 'wpultra_skill_write',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

require_once WPULTRA_DIR . 'includes/skills/sources.php';
function wpultra_skill_write(array $input) {
    $slug = sanitize_title((string) ($input['slug'] ?? ''));
    if ($slug === '') { return wpultra_err('bad_slug', 'slug is required.'); }
    $existing = get_page_by_path($slug, OBJECT, 'wpultra_skill');
    $on_conflict = (string) ($input['on_conflict'] ?? 'fail');
    if ($existing && $on_conflict !== 'replace') {
        return wpultra_err('conflict', "Skill '$slug' exists. Pass on_conflict: 'replace' to overwrite.");
    }
    $postarr = [
        'post_type' => 'wpultra_skill', 'post_status' => 'publish', 'post_title' => $slug, 'post_name' => $slug,
        'post_excerpt' => (string) ($input['description'] ?? ''), 'post_content' => (string) ($input['body'] ?? ''),
    ];
    if ($existing) { $postarr['ID'] = $existing->ID; }
    $id = wp_insert_post($postarr, true);
    if (is_wp_error($id)) { return $id; }
    update_post_meta($id, '_enable_prompt', ($input['enable_prompt'] ?? true) ? '1' : '0');
    update_post_meta($id, '_enable_agentic', ($input['enable_agentic'] ?? true) ? '1' : '0');
    return wpultra_ok(['slug' => $slug, 'post_id' => (int) $id]);
}
