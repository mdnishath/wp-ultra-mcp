<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/gutenberg-insert-pattern', [
    'label'       => __('Gutenberg: Insert Pattern', 'wp-ultra-mcp'),
    'description' => __('Insert a registered block pattern\'s blocks into a post at a positional parent path + position.', 'wp-ultra-mcp'),
    'category'    => 'gutenberg',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'post_id'      => ['type' => 'integer'],
            'pattern_name' => ['type' => 'string'],
            'parent_path'  => ['type' => 'string'],
            'position'     => ['type' => 'integer'],
        ],
        'required'   => ['post_id', 'pattern_name'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => ['success' => ['type' => 'boolean'], 'inserted' => ['type' => 'integer'], 'blocks' => ['type' => 'array']],
        'required'   => ['success'],
    ],
    'execute_callback'    => 'wpultra_gb_insert_pattern_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_gb_insert_pattern_cb(array $input) {
    $post_id = (int) ($input['post_id'] ?? 0);
    $name = (string) ($input['pattern_name'] ?? '');
    $pat = wpultra_gb_get_pattern($name);
    if (is_wp_error($pat)) { return $pat; }
    $blocks = wpultra_gb_pattern_blocks((string) ($pat['content'] ?? ''));
    if ($blocks === []) { return wpultra_err('empty_pattern', "Pattern '$name' parsed to no blocks."); }
    $loaded = wpultra_gb_load($post_id);
    if (is_wp_error($loaded)) { return $loaded; }
    $parentPath = wpultra_gb_str_to_path((string) ($input['parent_path'] ?? ''));
    $pos = isset($input['position']) ? (int) $input['position'] : PHP_INT_MAX;
    $updated = $loaded['blocks'];
    foreach ($blocks as $b) {
        $updated = wpultra_gb_insert($updated, $parentPath, $pos, $b);
        if (is_wp_error($updated)) { return $updated; }
        if ($pos !== PHP_INT_MAX) { $pos++; }
    }
    $tree = wpultra_gb_save($post_id, $updated);
    wpultra_audit_log('gutenberg-insert-pattern', "post $post_id <- pattern '$name' (" . count($blocks) . ' blocks)', !is_wp_error($tree));
    if (is_wp_error($tree)) { return $tree; }
    return wpultra_ok(['inserted' => count($blocks), 'blocks' => $tree]);
}
