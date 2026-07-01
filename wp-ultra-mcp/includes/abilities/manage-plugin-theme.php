<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/manage-plugin-theme', [
    'label'       => __('Manage Plugins & Themes', 'wp-ultra-mcp'),
    'description' => __('Install, activate, deactivate, update, or delete plugins and themes. actions: `list-plugins`, `activate-plugin`, `deactivate-plugin`, `install-plugin` (source = wp.org slug or zip URL), `update-plugin`, `delete-plugin`, `list-themes`, `activate-theme`. Plugin ref = folder/file.php. WP-Ultra-MCP itself is protected from deactivation/deletion.', 'wp-ultra-mcp'),
    'category'    => 'system',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action'     => ['type' => 'string', 'enum' => ['list-plugins', 'activate-plugin', 'deactivate-plugin', 'install-plugin', 'update-plugin', 'delete-plugin', 'list-themes', 'activate-theme']],
            'plugin'     => ['type' => 'string'],
            'source'     => ['type' => 'string'],
            'stylesheet' => ['type' => 'string'],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => ['success' => ['type' => 'boolean']],
        'required'   => ['success'],
    ],
    'execute_callback'    => 'wpultra_manage_plugin_theme',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_manage_plugin_theme(array $input) {
    $action = (string) ($input['action'] ?? '');
    $plugin = (string) ($input['plugin'] ?? '');
    switch ($action) {
        case 'list-plugins':       $res = wpultra_system_list_plugins(); break;
        case 'activate-plugin':    $res = wpultra_system_activate_plugin($plugin); break;
        case 'deactivate-plugin':  $res = wpultra_system_deactivate_plugin($plugin); break;
        case 'install-plugin':     $res = wpultra_system_install_plugin((string) ($input['source'] ?? '')); break;
        case 'update-plugin':      $res = wpultra_system_update_plugin($plugin); break;
        case 'delete-plugin':      $res = wpultra_system_delete_plugin($plugin); break;
        case 'list-themes':        $res = wpultra_system_list_themes(); break;
        case 'activate-theme':     $res = wpultra_system_activate_theme((string) ($input['stylesheet'] ?? '')); break;
        default:                   return wpultra_err('bad_action', "Unknown action '$action'.");
    }
    if (is_wp_error($res)) { return $res; }
    if ($action !== 'list-plugins' && $action !== 'list-themes') { wpultra_audit_log('manage-plugin-theme', "$action " . ($plugin ?: (string) ($input['stylesheet'] ?? $input['source'] ?? '')), true); }
    return wpultra_ok($res);
}
