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
        // Core directories.
        rtrim(ABSPATH, '/\\'), ABSPATH . 'wp-admin', ABSPATH . 'wp-includes', WP_CONTENT_DIR . '/mu-plugins',
        // Critical files whose removal takes the site down or triggers the install flow.
        ABSPATH . 'wp-config.php', dirname(rtrim(ABSPATH, '/\\')) . '/wp-config.php',
        ABSPATH . 'index.php', ABSPATH . '.htaccess', ABSPATH . 'wp-load.php',
        ABSPATH . 'wp-settings.php', ABSPATH . 'wp-blog-header.php', ABSPATH . 'wp-login.php',
        ABSPATH . 'wp-cron.php', ABSPATH . 'wp-activate.php',
    ]);
    if (in_array(wpultra_normalize_absolute_path($resolved), $protected, true)) {
        return wpultra_err('protected_path', "Refusing to delete a protected path: $resolved");
    }
    if (!file_exists($resolved)) { return wpultra_ok(['path' => $resolved, 'deleted' => false]); }
    if (is_dir($resolved)) { return wpultra_err('is_directory', 'Refusing to delete a directory.'); }
    // Undo coverage (BF2.6): snapshot the prior file contents before deleting so
    // undo-restore can recreate this file. Guarded — never blocks the delete. If the
    // content can't be read (e.g. a permission error) we skip the capture rather than
    // guess: a missing snapshot is safe, a wrong one (mis-recorded as never-existed)
    // is not.
    if (function_exists('wpultra_undo_capture')) {
        $wpultra_undo_before = @file_get_contents($resolved);
        if ($wpultra_undo_before !== false) {
            wpultra_undo_capture('file', $resolved, $wpultra_undo_before, 'delete-file');
        }
    }
    if (!unlink($resolved)) { return wpultra_err('delete_failed', "Could not delete: $resolved"); }
    wpultra_audit_log('delete-file', $resolved, true);
    return wpultra_ok(['path' => $resolved, 'deleted' => true]);
}
