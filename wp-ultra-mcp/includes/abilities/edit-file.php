<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/edit-file', [
    'label'       => __('Edit File', 'wp-ultra-mcp'),
    'description' => __('Replace a unique substring in a file within the allowed base directory.', 'wp-ultra-mcp'),
    'category'    => 'filesystem',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'path'       => ['type' => 'string'],
            'old_string' => ['type' => 'string'],
            'new_string' => ['type' => 'string'],
        ],
        'required'             => ['path', 'old_string', 'new_string'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'      => ['type' => 'boolean'],
            'path'         => ['type' => 'string'],
            'replacements' => ['type' => 'integer'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_edit_file',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_edit_file(array $input) {
    $resolved = wpultra_resolve_path((string) ($input['path'] ?? ''), true);
    if (is_wp_error($resolved)) { return $resolved; }
    $old = (string) ($input['old_string'] ?? '');
    $new = (string) ($input['new_string'] ?? '');
    if ($old === '') { return wpultra_err('empty_old_string', 'old_string must be non-empty.'); }
    $content = file_get_contents($resolved);
    if ($content === false) { return wpultra_err('read_failed', "Could not read: $resolved"); }
    $count = substr_count($content, $old);
    if ($count === 0) { return wpultra_err('not_found', 'old_string not found in file.'); }
    if ($count > 1) { return wpultra_err('not_unique', "old_string occurs $count times; make it unique."); }
    $updated = str_replace($old, $new, $content);
    if (file_put_contents($resolved, $updated) === false) { return wpultra_err('write_failed', "Could not write: $resolved"); }
    return wpultra_ok(['path' => $resolved, 'replacements' => 1]);
}
