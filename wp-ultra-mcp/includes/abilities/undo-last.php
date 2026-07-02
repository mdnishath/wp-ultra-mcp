<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/undo-last', [
    'label'       => __('Undo Last Change', 'wp-ultra-mcp'),
    'description' => __('Roll back the most recent captured change (option / custom CSS / theme.json / term update), optionally filtered by type. Convenience wrapper over the newest wpultra/undo-list snapshot.', 'wp-ultra-mcp'),
    'category'    => 'undo',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => ['type' => ['type' => 'string', 'enum' => ['option', 'custom_css', 'theme_json', 'term']]],
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
    'execute_callback'    => 'wpultra_undo_last_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_undo_last_cb(array $input) {
    $filter = (string) ($input['type'] ?? '');
    $stack = wpultra_undo_load_stack();
    foreach ($stack as $e) { // newest first
        if ($filter !== '' && (string) ($e['type'] ?? '') !== $filter) { continue; }
        $res = wpultra_undo_restore((int) ($e['id'] ?? 0));
        return is_wp_error($res) ? $res : wpultra_ok($res);
    }
    return wpultra_err('nothing_to_undo', $filter !== '' ? "No $filter snapshot to undo." : 'No captured changes to undo.');
}
