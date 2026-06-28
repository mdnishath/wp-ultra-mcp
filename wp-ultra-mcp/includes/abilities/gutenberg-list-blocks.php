<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/gutenberg-list-blocks', [
    'label'       => __('Gutenberg: List Block Types', 'wp-ultra-mcp'),
    'description' => __('List registered block types, optionally filtered by search/category.', 'wp-ultra-mcp'),
    'category'    => 'gutenberg',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'search'   => ['type' => 'string'],
            'category' => ['type' => 'string'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => ['success' => ['type' => 'boolean'], 'count' => ['type' => 'integer'], 'blocks' => ['type' => 'array']],
        'required'   => ['success'],
    ],
    'execute_callback'    => 'wpultra_gb_list_blocks_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_gb_list_blocks_cb(array $input) {
    $blocks = wpultra_gb_list_block_types((string) ($input['search'] ?? ''), (string) ($input['category'] ?? ''));
    return wpultra_ok(['count' => count($blocks), 'blocks' => $blocks]);
}
