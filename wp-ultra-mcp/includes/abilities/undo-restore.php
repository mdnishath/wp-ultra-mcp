<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/undo-restore', [
    'label'       => __('Restore Undo Snapshot', 'wp-ultra-mcp'),
    'description' => __('Roll back a captured change by its snapshot id (from wpultra/undo-list). Reapplies the before-state (option value/absence, previous custom CSS, previous theme.json, or the term\'s prior fields) and removes the snapshot. Term reverts require the term to still exist.', 'wp-ultra-mcp'),
    'category'    => 'undo',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => ['id' => ['type' => 'integer']],
        'required'             => ['id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'  => ['type' => 'boolean'],
            'restored' => ['type' => 'boolean'],
            'id'       => ['type' => 'integer'],
            'type'     => ['type' => 'string'],
            'target'   => ['type' => 'string'],
            'detail'   => ['type' => ['object', 'array', 'null']],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_undo_restore_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_undo_restore_cb(array $input) {
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) { return wpultra_err('missing_id', 'id is required.'); }
    $res = wpultra_undo_restore($id);
    return is_wp_error($res) ? $res : wpultra_ok($res);
}
