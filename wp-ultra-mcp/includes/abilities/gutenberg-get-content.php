<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/gutenberg-get-content', [
    'label'       => __('Gutenberg: Get Block Content', 'wp-ultra-mcp'),
    'description' => __('Parse a post/page into a positional-path block tree.', 'wp-ultra-mcp'),
    'category'    => 'gutenberg',
    'input_schema' => [
        'type'       => 'object',
        'properties' => ['post_id' => ['type' => 'integer']],
        'required'   => ['post_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => ['success' => ['type' => 'boolean'], 'blocks' => ['type' => 'array']],
        'required'   => ['success'],
    ],
    'execute_callback'    => 'wpultra_gb_get_content',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_gb_get_content(array $input) {
    $tree = wpultra_gb_tree((int) ($input['post_id'] ?? 0));
    if (is_wp_error($tree)) { return $tree; }
    return wpultra_ok(['blocks' => $tree]);
}
