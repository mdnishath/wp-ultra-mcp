<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/gutenberg-move-block', [
    'label'       => __('Gutenberg: Move Block', 'wp-ultra-mcp'),
    'description' => __('Move the block at a path to a new parent path + position.', 'wp-ultra-mcp'),
    'category'    => 'gutenberg',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'post_id'        => ['type' => 'integer'],
            'path'           => ['type' => 'string'],
            'to_parent_path' => ['type' => 'string'],
            'position'       => ['type' => 'integer', 'description' => 'Target index in the destination parent AFTER the source block has been removed. Sibling indices above the source are shifted down by one before insertion, so account for that when the destination shares the same parent as the source.'],
        ],
        'required'   => ['post_id', 'path', 'position'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => ['success' => ['type' => 'boolean'], 'blocks' => ['type' => 'array']],
        'required'   => ['success'],
    ],
    'execute_callback'    => 'wpultra_gb_move_block_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_gb_move_block_cb(array $input) {
    $post_id = (int) ($input['post_id'] ?? 0);
    $loaded = wpultra_gb_load($post_id);
    if (is_wp_error($loaded)) { return $loaded; }
    $updated = wpultra_gb_move(
        $loaded['blocks'],
        wpultra_gb_str_to_path((string) ($input['path'] ?? '')),
        wpultra_gb_str_to_path((string) ($input['to_parent_path'] ?? '')),
        (int) ($input['position'] ?? 0)
    );
    if (is_wp_error($updated)) { return $updated; }
    $tree = wpultra_gb_save($post_id, $updated);
    wpultra_audit_log('gutenberg-move-block', "post $post_id " . (string) ($input['path'] ?? '') . ' -> ' . (string) ($input['to_parent_path'] ?? '') . '/' . (int) ($input['position'] ?? 0), !is_wp_error($tree));
    if (is_wp_error($tree)) { return $tree; }
    return wpultra_ok(['blocks' => $tree]);
}
