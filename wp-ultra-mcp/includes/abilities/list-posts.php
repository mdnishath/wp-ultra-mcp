<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/list-posts', [
    'label'       => __('List Posts', 'wp-ultra-mcp'),
    'description' => __('List posts/pages/CPTs with filtering, search, and pagination. Never returns post_content.', 'wp-ultra-mcp'),
    'category'    => 'content',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'post_type'  => ['type' => 'string'],
            'status'     => ['type' => 'string'],
            'search'     => ['type' => 'string'],
            'meta_key'   => ['type' => 'string'],
            'meta_value' => ['type' => 'string'],
            'tax_query'  => [
                'type'       => 'object',
                'properties' => [
                    'taxonomy' => ['type' => 'string'],
                    'terms'    => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
                'additionalProperties' => false,
            ],
            'orderby'  => ['type' => 'string'],
            'order'    => ['type' => 'string', 'enum' => ['ASC', 'DESC', 'asc', 'desc']],
            'per_page' => ['type' => 'integer'],
            'page'     => ['type' => 'integer'],
        ],
        'required'             => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'posts'   => ['type' => 'array'],
            'total'   => ['type' => 'integer'],
            'pages'   => ['type' => 'integer'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_list_posts',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_list_posts(array $input) {
    $result = wpultra_content_list_posts($input);
    if (is_wp_error($result)) { return $result; }
    return wpultra_ok($result);
}
