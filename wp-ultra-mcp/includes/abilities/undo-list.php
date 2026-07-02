<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/undo-list', [
    'label'       => __('List Undo Snapshots', 'wp-ultra-mcp'),
    'description' => __('List recent reversible changes captured automatically before option, custom-CSS, theme.json, and term-update mutations (newest first). Each row: id, type, target, label, created. Use wpultra/undo-restore with an id, or wpultra/undo-last, to roll one back. (Posts/pages use wpultra/content-restore instead.)', 'wp-ultra-mcp'),
    'category'    => 'undo',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => ['type' => ['type' => 'string', 'enum' => ['option', 'custom_css', 'theme_json', 'term']]],
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
    return wpultra_ok(['snapshots' => $rows, 'count' => count($rows)]);
}
