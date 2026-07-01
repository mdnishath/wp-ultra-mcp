<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/gutenberg-delete-block', [
    'label'       => __('Gutenberg: Delete Block', 'wp-ultra-mcp'),
    'description' => __('Remove the block at a positional path.', 'wp-ultra-mcp'),
    'category'    => 'gutenberg',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'path'    => ['type' => 'string', 'description' => 'Slash path of the block to remove (e.g. "1/0"). Use the RAW path exactly as returned in the "path" field by gutenberg-get-content — indices count all siblings including hidden freeform/whitespace nodes.'],
        ],
        'required'   => ['post_id', 'path'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => ['success' => ['type' => 'boolean'], 'blocks' => ['type' => 'array']],
        'required'   => ['success'],
    ],
    'execute_callback'    => 'wpultra_gb_delete_block_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_gb_delete_block_cb(array $input) {
    $post_id = (int) ($input['post_id'] ?? 0);
    $loaded = wpultra_gb_load($post_id);
    if (is_wp_error($loaded)) { return $loaded; }
    $path = wpultra_gb_str_to_path((string) ($input['path'] ?? ''));
    if ($path === null) { return wpultra_err('invalid_path', 'path must be slash-separated integers (e.g. "1/0"): ' . (string) ($input['path'] ?? '')); }
    $updated = wpultra_gb_remove($loaded['blocks'], $path);
    if (is_wp_error($updated)) { return $updated; }
    $tree = wpultra_gb_save($post_id, $updated);
    wpultra_audit_log('gutenberg-delete-block', "post $post_id @ " . (string) ($input['path'] ?? ''), !is_wp_error($tree));
    if (is_wp_error($tree)) { return $tree; }
    return wpultra_ok(['blocks' => $tree]);
}
