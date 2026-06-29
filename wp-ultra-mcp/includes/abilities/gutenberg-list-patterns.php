<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/gutenberg-list-patterns', [
    'label'       => __('Gutenberg: List Patterns', 'wp-ultra-mcp'),
    'description' => __('List registered block patterns (name, title, categories), optionally filtered by search/category.', 'wp-ultra-mcp'),
    'category'    => 'gutenberg',
    'input_schema' => [
        'type'       => 'object',
        'properties' => ['search' => ['type' => 'string'], 'category' => ['type' => 'string']],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => ['success' => ['type' => 'boolean'], 'count' => ['type' => 'integer'], 'patterns' => ['type' => 'array']],
        'required'   => ['success'],
    ],
    'execute_callback'    => 'wpultra_gb_list_patterns_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_gb_list_patterns_cb(array $input) {
    $patterns = wpultra_gb_list_patterns((string) ($input['search'] ?? ''), (string) ($input['category'] ?? ''));
    return wpultra_ok(['count' => count($patterns), 'patterns' => $patterns]);
}
