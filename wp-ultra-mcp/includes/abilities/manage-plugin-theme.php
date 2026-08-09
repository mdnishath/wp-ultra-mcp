<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/manage-plugin-theme', [
    'label'       => __('Manage Plugins & Themes', 'wp-ultra-mcp'),
    'description' => __('Install, activate, deactivate, update, or delete plugins and themes. actions: `list-plugins`, `activate-plugin`, `deactivate-plugin`, `install-plugin` (source = wp.org slug or zip URL), `update-plugin`, `delete-plugin`, `list-themes`, `activate-theme`, `install-theme` (source = slug or zip URL), `update-theme`, `delete-theme`. Plugin ref = folder/file.php; theme ref = stylesheet. `delete-plugin`, `update-plugin`, `delete-theme`, `update-theme` are irreversible and require confirm: true. WP-Ultra-MCP itself is protected; the active theme (and its parent) cannot be deleted.', 'wp-ultra-mcp'),
    'category'    => 'system',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action'     => ['type' => 'string', 'enum' => ['list-plugins', 'activate-plugin', 'deactivate-plugin', 'install-plugin', 'update-plugin', 'delete-plugin', 'list-themes', 'activate-theme', 'install-theme', 'update-theme', 'delete-theme']],
            'plugin'     => ['type' => 'string'],
            'source'     => ['type' => 'string'],
            'stylesheet' => ['type' => 'string'],
            'confirm'    => ['type' => 'boolean', 'description' => 'Required true for delete-plugin and update-plugin.'],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => wpultra_manager_output_schema(['action' => ['type' => 'string']]),
    'execute_callback'    => 'wpultra_manage_plugin_theme',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_manage_plugin_theme(array $input) {
    // The upgrader skin (install/update/delete) echoes HTML progress; any stray
    // byte corrupts the JSON-RPC response — buffer the whole run and discard.
    ob_start();
    try {
        return wpultra_manage_plugin_theme_run($input);
    } finally {
        ob_end_clean();
    }
}

function wpultra_manage_plugin_theme_run(array $input) {
    $action = (string) ($input['action'] ?? '');
    $plugin = (string) ($input['plugin'] ?? '');
    // delete-plugin removes code + often data unrecoverably; update-plugin
    // overwrites the installed version with no way back. All gate on confirm.
    if (in_array($action, ['delete-plugin', 'update-plugin', 'delete-theme', 'update-theme'], true) && ($input['confirm'] ?? false) !== true) {
        return wpultra_err('confirm_required', "'$action' is irreversible. Re-run with confirm: true.");
    }
    $stylesheet = (string) ($input['stylesheet'] ?? '');
    switch ($action) {
        case 'list-plugins':       $res = wpultra_system_list_plugins(); break;
        case 'activate-plugin':    $res = wpultra_system_activate_plugin($plugin); break;
        case 'deactivate-plugin':  $res = wpultra_system_deactivate_plugin($plugin); break;
        case 'install-plugin':     $res = wpultra_system_install_plugin((string) ($input['source'] ?? '')); break;
        case 'update-plugin':      $res = wpultra_system_update_plugin($plugin); break;
        case 'delete-plugin':      $res = wpultra_system_delete_plugin($plugin); break;
        case 'list-themes':        $res = wpultra_system_list_themes(); break;
        case 'activate-theme':     $res = wpultra_system_activate_theme($stylesheet); break;
        case 'install-theme':      $res = wpultra_system_install_theme((string) ($input['source'] ?? '')); break;
        case 'update-theme':       $res = wpultra_system_update_theme($stylesheet); break;
        case 'delete-theme':       $res = wpultra_system_delete_theme($stylesheet); break;
        default:                   return wpultra_err('bad_action', "Unknown action '$action'.");
    }
    if (is_wp_error($res)) { return $res; }
    if ($action !== 'list-plugins' && $action !== 'list-themes') { wpultra_audit_log('manage-plugin-theme', "$action " . ($plugin ?: (string) ($input['stylesheet'] ?? $input['source'] ?? '')), true); }
    return wpultra_ok($res);
}
