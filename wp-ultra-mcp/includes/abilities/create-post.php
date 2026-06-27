<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/create-post', [
    'label'       => __('Create Post', 'wp-ultra-mcp'),
    'description' => __('Create a new WordPress post, page, or CPT.', 'wp-ultra-mcp'),
    'category'    => 'content',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'title'     => ['type' => 'string'],
            'content'   => ['type' => 'string'],
            'status'    => ['type' => 'string', 'enum' => ['publish', 'draft', 'pending', 'private', 'future']],
            'post_type' => ['type' => 'string'],
            'excerpt'   => ['type' => 'string'],
            'slug'      => ['type' => 'string'],
            'parent'    => ['type' => 'integer'],
            'author'    => ['type' => 'integer'],
            'date'      => ['type' => 'string'],
            'meta'      => ['type' => 'object'],
            'terms'     => ['type' => 'object'],
        ],
        'required'             => ['title'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'   => ['type' => 'boolean'],
            'post_id'   => ['type' => 'integer'],
            'permalink' => ['type' => 'string'],
            'edit_url'  => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_create_post',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_create_post(array $input) {
    $title = (string) ($input['title'] ?? $input['post_title'] ?? '');
    if (trim($title) === '') { return wpultra_err('missing_title', 'title is required.'); }
    $postarr = [
        'post_title'   => $title,
        'post_content' => (string) ($input['content'] ?? $input['post_content'] ?? ''),
        'post_excerpt' => (string) ($input['excerpt'] ?? ''),
        'post_status'  => (string) ($input['status'] ?? 'draft'),
        'post_type'    => (string) ($input['post_type'] ?? 'page'),
    ];
    if (!empty($input['slug'])) { $postarr['post_name'] = sanitize_title((string) $input['slug']); }
    if (!empty($input['parent'])) { $postarr['post_parent'] = (int) $input['parent']; }
    if (!empty($input['author'])) { $postarr['post_author'] = (int) $input['author']; }
    if (!empty($input['date'])) { $postarr['post_date'] = (string) $input['date']; }
    if (!empty($input['meta']) && is_array($input['meta'])) { $postarr['meta_input'] = $input['meta']; }
    $id = wp_insert_post($postarr, true);
    if (is_wp_error($id)) { return $id; }
    if (!empty($input['terms']) && is_array($input['terms'])) {
        foreach ($input['terms'] as $tax => $terms) { wp_set_post_terms((int) $id, (array) $terms, (string) $tax); }
    }
    return wpultra_ok(['post_id' => (int) $id, 'permalink' => get_permalink($id), 'edit_url' => get_edit_post_link($id, 'raw')]);
}
