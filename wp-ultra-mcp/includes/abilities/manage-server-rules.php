<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/manage-server-rules', [
    'label'       => __('Manage Server Rules', 'wp-ultra-mcp'),
    'description' => __('Manage a WPUltra-owned block of .htaccess rules (security headers, browser caching, gzip, block xmlrpc, disable directory indexes). Writes only between "# BEGIN WPUltra" / "# END WPUltra" markers via WordPress\'s own insert_with_markers()/extract_from_markers() — content outside the markers (WordPress\'s own rewrite rules, other plugins\' blocks) is never touched. Before every write the current .htaccess is copied to .htaccess.wpultra-backup; `restore-backup` copies it back (this is a self-contained backup, independent of the universal undo engine). On nginx (no .htaccess present and server detected as nginx) `get`/`set` return the composed rules as text with a note to add them to the server config manually; `set` refuses to write in that case. actions: `get`, `set` (presets[] and/or custom_lines[], requires confirm:true), `clear` (removes the managed block, requires confirm:true), `restore-backup` (requires confirm:true). Known presets: security-headers, browser-caching, gzip, block-xmlrpc, disable-indexes.', 'wp-ultra-mcp'),
    'category'    => 'system',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action'       => ['type' => 'string', 'enum' => ['get', 'set', 'clear', 'restore-backup']],
            'presets'      => [
                'type'  => 'array',
                'items' => ['type' => 'string', 'enum' => ['security-headers', 'browser-caching', 'gzip', 'block-xmlrpc', 'disable-indexes']],
            ],
            'custom_lines' => ['type' => 'array', 'items' => ['type' => 'string']],
            'confirm'      => ['type' => 'boolean'],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'  => ['type' => 'boolean'],
            'mode'     => ['type' => 'string'],
            'file'     => ['type' => 'string'],
            'exists'   => ['type' => 'boolean'],
            'writable' => ['type' => 'boolean'],
            'size'     => ['type' => 'integer'],
            'lines'    => ['type' => 'array'],
            'text'     => ['type' => 'string'],
            'note'     => ['type' => 'string'],
            'presets'  => ['type' => 'array'],
            'backup'   => ['type' => 'string'],
            'cleared'  => ['type' => 'boolean'],
            'restored' => ['type' => 'boolean'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_manage_server_rules',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

/** @return array|WP_Error */
function wpultra_manage_server_rules(array $input) {
    $action = (string) ($input['action'] ?? '');

    switch ($action) {
        case 'get':
            $res = wpultra_rules_get($input);
            break;
        case 'set':
            $res = wpultra_rules_set($input);
            break;
        case 'clear':
            $res = wpultra_rules_clear($input);
            break;
        case 'restore-backup':
            $res = wpultra_rules_restore_backup($input);
            break;
        default:
            return wpultra_err('bad_action', "Unknown action '$action'. Use get, set, clear, or restore-backup.");
    }

    if (is_wp_error($res)) {
        wpultra_audit_log('manage-server-rules', "$action failed: " . $res->get_error_message(), false);
        return $res;
    }
    return $res;
}
