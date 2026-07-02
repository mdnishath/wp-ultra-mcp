<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/get-post', [
    'label'       => __('Get Post', 'wp-ultra-mcp'),
    'description' => __('Get a single post/page/CPT by id, optionally including content, meta, terms, and revision count.', 'wp-ultra-mcp'),
    'category'    => 'content',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'id'                   => ['type' => 'integer'],
            'fields'               => [
                'type'  => 'array',
                'items' => ['type' => 'string', 'enum' => ['content', 'meta', 'terms', 'revisions_count']],
            ],
            'include_private_meta' => ['type' => 'boolean'],
        ],
        'required'             => ['id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'id'      => ['type' => 'integer'],
            'title'   => ['type' => 'string'],
            'slug'    => ['type' => 'string'],
            'status'  => ['type' => 'string'],
            'type'    => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_get_post',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_get_post(array $input) {
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) { return wpultra_err('missing_id', 'id is required.'); }

    $fields = is_array($input['fields'] ?? null) ? $input['fields'] : [];
    if (!empty($input['include_private_meta'])) { $fields['include_private_meta'] = true; }

    $result = wpultra_content_get_post($id, $fields);
    if (is_wp_error($result)) { return $result; }
    return wpultra_ok($result);
}
