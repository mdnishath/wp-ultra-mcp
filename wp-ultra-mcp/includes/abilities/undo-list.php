<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/undo-list', [
    'label'       => __('List Undo Snapshots', 'wp-ultra-mcp'),
    'description' => __('List recent reversible changes captured automatically before option, custom-CSS, theme.json, term, file, plugin/theme-activation, and post/builder-content mutations (newest first). Each row: id, type, target, label, created. Use wpultra/undo-restore with an id, or wpultra/undo-last, to roll one back. `post` snapshots cover Elementor/Bricks/Gutenberg edits + update-post (fields + builder postmeta that WP revisions miss); wpultra/content-restore additionally uses native post revisions.', 'wp-ultra-mcp'),
    'category'    => 'undo',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => array_merge(
            ['type' => ['type' => 'string', 'enum' => ['option', 'custom_css', 'theme_json', 'term', 'file', 'active_plugins', 'active_theme', 'post']]],
            wpultra_pagination_schema()
        ),
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'   => ['type' => 'boolean'],
            'snapshots' => ['type' => 'array'],
            'count'     => ['type' => 'integer'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_undo_list_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_undo_list_cb(array $input) {
    $filter = (string) ($input['type'] ?? '');
    $stack = wpultra_undo_load_stack();
    $rows = [];
    foreach ($stack as $e) {
        if ($filter !== '' && (string) ($e['type'] ?? '') !== $filter) { continue; }
        $rows[] = wpultra_undo_shape((array) $e);
    }
    // The undo ring is capped at 50, so the default page returns the whole set.
    [$page, $meta] = wpultra_paginate($rows, $input, 50);
    return wpultra_ok(['snapshots' => $page, 'count' => $meta['returned'], 'total' => $meta['total']]);
}
