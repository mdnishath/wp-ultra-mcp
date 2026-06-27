<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/delete-file', [
    'label'       => __('Delete File', 'wp-ultra-mcp'),
    'description' => __('Delete a file within the allowed base directory (protected paths are refused).', 'wp-ultra-mcp'),
    'category'    => 'filesystem',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'path' => ['type' => 'string'],
        ],
        'required'             => ['path'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'path'    => ['type' => 'string'],
            'deleted' => ['type' => 'boolean'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_delete_file',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => true],
    ],
]);

function wpultra_delete_file(array $input) {
    $resolved = wpultra_resolve_path((string) ($input['path'] ?? ''), false);
    if (is_wp_error($resolved)) { return $resolved; }
    $protected = array_map('wpultra_normalize_absolute_path', [
        rtrim(ABSPATH, '/\\'), ABSPATH . 'wp-admin', ABSPATH . 'wp-includes', WP_CONTENT_DIR . '/mu-plugins',
    ]);
    if (in_array(wpultra_normalize_absolute_path($resolved), $protected, true)) {
        return wpultra_err('protected_path', "Refusing to delete a protected path: $resolved");
    }
    if (!file_exists($resolved)) { return wpultra_ok(['path' => $resolved, 'deleted' => false]); }
    if (is_dir($resolved)) { return wpultra_err('is_directory', 'Refusing to delete a directory.'); }
    if (!unlink($resolved)) { return wpultra_err('delete_failed', "Could not delete: $resolved"); }
    return wpultra_ok(['path' => $resolved, 'deleted' => true]);
}
