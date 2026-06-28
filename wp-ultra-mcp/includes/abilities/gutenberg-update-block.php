<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/gutenberg-update-block', [
    'label'       => __('Gutenberg: Update Block', 'wp-ultra-mcp'),
    'description' => __('Merge attributes (and optionally innerHTML) of the block at a path.', 'wp-ultra-mcp'),
    'category'    => 'gutenberg',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'post_id'    => ['type' => 'integer'],
            'path'       => ['type' => 'string'],
            'attributes' => ['type' => 'object'],
            'inner_html' => ['type' => 'string'],
            'deep'       => ['type' => 'boolean'],
        ],
        'required'   => ['post_id', 'path'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => ['success' => ['type' => 'boolean'], 'blocks' => ['type' => 'array']],
        'required'   => ['success'],
    ],
    'execute_callback'    => 'wpultra_gb_update_block_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_gb_update_block_cb(array $input) {
    $post_id = (int) ($input['post_id'] ?? 0);
    $loaded = wpultra_gb_load($post_id);
    if (is_wp_error($loaded)) { return $loaded; }
    $path = wpultra_gb_str_to_path((string) ($input['path'] ?? ''));
    $blocks = $loaded['blocks'];
    if (isset($input['attributes']) && is_array($input['attributes'])) {
        $blocks = wpultra_gb_merge_attrs($blocks, $path, (array) $input['attributes'], !empty($input['deep']));
        if (is_wp_error($blocks)) { return $blocks; }
    }
    if (isset($input['inner_html'])) {
        $loc = wpultra_gb_locate($blocks, $path);
        if (!$loc) { return wpultra_err('block_path_not_found', 'Path not found: ' . (string) ($input['path'] ?? '')); }
        $ref = &wpultra_gb_ref($blocks, $loc['parent_path']);
        $ref[$loc['index']]['innerHTML']    = (string) $input['inner_html'];
        $ref[$loc['index']]['innerContent'] = [(string) $input['inner_html']];
        unset($ref);
    }
    $tree = wpultra_gb_save($post_id, $blocks);
    wpultra_audit_log('gutenberg-update-block', "post $post_id @ " . (string) ($input['path'] ?? ''), !is_wp_error($tree));
    if (is_wp_error($tree)) { return $tree; }
    return wpultra_ok(['blocks' => $tree]);
}
