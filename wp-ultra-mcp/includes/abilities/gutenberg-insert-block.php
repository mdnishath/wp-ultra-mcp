<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/gutenberg-insert-block', [
    'label'       => __('Gutenberg: Insert Block', 'wp-ultra-mcp'),
    'description' => __('Insert a block at a parent path + position. Provide block.markup (raw) or block.name/attributes/inner_blocks/inner_html.', 'wp-ultra-mcp'),
    'category'    => 'gutenberg',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'post_id'     => ['type' => 'integer'],
            'parent_path' => ['type' => 'string'],
            'position'    => ['type' => 'integer'],
            'block'       => ['type' => 'object'],
        ],
        'required'   => ['post_id', 'block'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => ['success' => ['type' => 'boolean'], 'blocks' => ['type' => 'array'], 'warning' => ['type' => 'string']],
        'required'   => ['success'],
    ],
    'execute_callback'    => 'wpultra_gb_insert_block_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_gb_insert_block_cb(array $input) {
    $post_id = (int) ($input['post_id'] ?? 0);
    $loaded = wpultra_gb_load($post_id);
    if (is_wp_error($loaded)) { return $loaded; }
    $block = wpultra_gb_normalize_block((array) ($input['block'] ?? []));
    if (is_wp_error($block)) { return $block; }
    $warning = (!empty($block['blockName']) && !wpultra_gb_is_registered($block['blockName']))
        ? "Block type '{$block['blockName']}' is not registered (allowed, but verify the name)." : '';
    $parentPath = wpultra_gb_str_to_path((string) ($input['parent_path'] ?? ''));
    $pos = isset($input['position']) ? (int) $input['position'] : PHP_INT_MAX;
    $updated = wpultra_gb_insert($loaded['blocks'], $parentPath, $pos, $block);
    if (is_wp_error($updated)) { return $updated; }
    $tree = wpultra_gb_save($post_id, $updated);
    wpultra_audit_log('gutenberg-insert-block', "post $post_id <- " . ($block['blockName'] ?? '?') . ' @ ' . (string) ($input['parent_path'] ?? '') . "/$pos", !is_wp_error($tree));
    if (is_wp_error($tree)) { return $tree; }
    $res = ['blocks' => $tree];
    if ($warning !== '') { $res['warning'] = $warning; }
    return wpultra_ok($res);
}
