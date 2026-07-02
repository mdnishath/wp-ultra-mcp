<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/search-content', [
    'label'       => __('Search Content', 'wp-ultra-mcp'),
    'description' => __('Search post titles and content, returning matches with a highlighted snippet around the first hit.', 'wp-ultra-mcp'),
    'category'    => 'content',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'query'      => ['type' => 'string'],
            'post_types' => ['type' => 'array', 'items' => ['type' => 'string']],
            'per_page'   => ['type' => 'integer'],
            'page'       => ['type' => 'integer'],
        ],
        'required'             => ['query'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'matches' => ['type' => 'array'],
            'total'   => ['type' => 'integer'],
            'pages'   => ['type' => 'integer'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_search_content',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_search_content(array $input) {
    $result = wpultra_content_search($input);
    if (is_wp_error($result)) { return $result; }
    return wpultra_ok($result);
}
