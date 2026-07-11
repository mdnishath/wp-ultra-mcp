<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

require_once __DIR__ . '/../system/debugmode.php';

wp_register_ability('wpultra/debug-mode', [
    'label'       => __('Debug Mode', 'wp-ultra-mcp'),
    'description' => __('Safely inspect and toggle the WordPress debug constants (WP_DEBUG, WP_DEBUG_LOG, WP_DEBUG_DISPLAY, SCRIPT_DEBUG, SAVEQUERIES) by editing wp-config.php. actions: `status` (default, read-only — reports each constant\'s live runtime value plus what wp-config.php source currently says, and the debug.log path/existence/size), `set` (confirm-gated — `constants` is a map using only the 5 whitelisted names, e.g. {"WP_DEBUG": true, "WP_DEBUG_LOG": true}; backs up wp-config.php first, writes via the shared editor, verifies the write, and auto-restores the backup on any verification failure; constants take effect on the NEXT request), `restore-backup` (confirm-gated — restores wp-config.php from the backup written by the last `set`).', 'wp-ultra-mcp'),
    'category'    => 'diagnostics',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action'    => ['type' => 'string', 'enum' => ['status', 'set', 'restore-backup'], 'default' => 'status'],
            'constants' => ['type' => 'object'],
            'confirm'   => ['type' => 'boolean'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'     => ['type' => 'boolean'],
            'runtime'     => ['type' => 'object'],
            'source'      => ['type' => 'object'],
            'config_path' => ['type' => 'string'],
            'debug_log'   => ['type' => 'object'],
            'applied'     => ['type' => 'array'],
            'backup_path' => ['type' => 'string'],
            'note'        => ['type' => 'string'],
            'restored_from' => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_debug_mode_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

/** @return array|WP_Error */
function wpultra_debug_mode_cb(array $input) {
    $action = (string) ($input['action'] ?? 'status');

    switch ($action) {
        case 'status':
            $res = wpultra_debugmode_status();
            break;
        case 'set':
            $res = wpultra_debugmode_set($input);
            break;
        case 'restore-backup':
            $res = wpultra_debugmode_restore_backup($input);
            break;
        default:
            return wpultra_err('bad_action', "Unknown action '$action'. Use status, set, or restore-backup.");
    }

    if (is_wp_error($res)) {
        wpultra_audit_log('debug-mode', "$action failed: " . $res->get_error_message(), false);
        return $res;
    }
    return $res;
}
