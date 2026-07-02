<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/bricks-get-content', [
    'label'       => __('Bricks: Get Content', 'wp-ultra-mcp'),
    'description' => __('Read the Bricks element tree from a post (rebuilt from the flat stored array), optionally filtered to a single element.', 'wp-ultra-mcp'),
    'category'    => 'bricks',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'post_id'    => ['type' => 'integer'],
            'element_id' => ['type' => 'string'],
        ],
        'required'             => ['post_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_bricks_get_content',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_bricks_get_content(array $input) {
    if (!wpultra_bricks_active()) { return wpultra_err('bricks_unavailable', 'Bricks is not installed/active on this site.'); }
    $post_id = (int) ($input['post_id'] ?? 0);
    $opts = [];
    if (isset($input['element_id']) && (string) $input['element_id'] !== '') { $opts['element_id'] = (string) $input['element_id']; }
    return wpultra_bricks_read($post_id, $opts);
}
