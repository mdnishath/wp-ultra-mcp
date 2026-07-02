<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/duplicate-post', [
    'label'       => __('Duplicate Post', 'wp-ultra-mcp'),
    'description' => __('Duplicate a post/page/CPT, optionally copying meta (Elementor-safe) and taxonomy terms.', 'wp-ultra-mcp'),
    'category'    => 'content',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'id'         => ['type' => 'integer'],
            'new_status' => ['type' => 'string'],
            'new_title'  => ['type' => 'string'],
            'copy_meta'  => ['type' => 'boolean'],
            'copy_terms' => ['type' => 'boolean'],
        ],
        'required'             => ['id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'   => ['type' => 'boolean'],
            'post_id'   => ['type' => 'integer'],
            'title'     => ['type' => 'string'],
            'status'    => ['type' => 'string'],
            'edit_link' => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_duplicate_post',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_duplicate_post(array $input) {
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) { return wpultra_err('missing_id', 'id is required.'); }

    $options = [
        'new_status' => (string) ($input['new_status'] ?? 'draft'),
        'new_title'  => (string) ($input['new_title'] ?? ''),
        'copy_meta'  => !array_key_exists('copy_meta', $input) || $input['copy_meta'] !== false,
        'copy_terms' => !array_key_exists('copy_terms', $input) || $input['copy_terms'] !== false,
    ];

    $result = wpultra_content_duplicate_post($id, $options);
    if (is_wp_error($result)) {
        wpultra_audit_log('duplicate-post', "duplicate of post $id failed", false);
        return $result;
    }
    wpultra_audit_log('duplicate-post', "duplicated post $id to new post {$result['post_id']}", true);
    return wpultra_ok($result);
}
