<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

// Defensively load the engine (the controller normally loads it, but the
// ability must stand alone if registered in isolation).
if (!function_exists('wpultra_wlabel_merge_config')) {
    require_once __DIR__ . '/../system/whitelabel.php';
}

wp_register_ability('wpultra/white-label', [
    'label'       => __('White Label', 'wp-ultra-mcp'),
    'description' => __('Cosmetically rebrand WP Ultra MCP and lock its admin surface down for client sites. REBRANDS: the plugin/menu title shown in wp-admin, the vendor name/URL, the admin footer text, an optional custom login-logo, and hiding the WordPress admin-bar logo. CLIENT MODE: for users whose roles are NOT in allowed_roles (administrators are always exempt to prevent self-lockout), hide the configured admin menu slugs and optionally hide the plugin\'s row on the Plugins screen from listed roles. ACTIONS: config (merge+validate branding/client_mode into the wpultra_whitelabel option; reversible, no confirm needed) · status (return the current effective config) · preview (compute what a given `role` would see WITHOUT applying — pass role:"editor" to simulate) · reset (confirm-gated: clear the option back to WP Ultra MCP defaults so a live client site is not accidentally un-branded). EXAMPLES: {action:"config", enabled:true, brand:{menu_title:"Acme Site Tools", vendor_name:"Acme", admin_footer_text:"Powered by Acme"}} · {action:"config", client_mode:{enabled:true, allowed_roles:["administrator"], hide_menus:["wpultra"], hide_plugin_from:["editor","shop_manager"]}} · {action:"preview", role:"editor"} · {action:"reset", confirm:true}. NOTE: this is COSMETIC polish for client-facing screens only — it does NOT strip GPL authorship or the license; the plugin stays GPL and its readme/LICENSE are untouched.', 'wp-ultra-mcp'),
    'category'    => 'system',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['config', 'status', 'preview', 'reset']],
            'enabled' => ['type' => 'boolean'],
            'brand'   => [
                'type'       => 'object',
                'properties' => [
                    'plugin_name'       => ['type' => 'string'],
                    'menu_title'        => ['type' => 'string'],
                    'vendor_name'       => ['type' => 'string'],
                    'vendor_url'        => ['type' => 'string'],
                    'admin_footer_text' => ['type' => 'string'],
                    'hide_wp_logo'      => ['type' => 'boolean'],
                    'login_logo_url'    => ['type' => 'string'],
                ],
                'additionalProperties' => false,
            ],
            'client_mode' => [
                'type'       => 'object',
                'properties' => [
                    'enabled'          => ['type' => 'boolean'],
                    'allowed_roles'    => ['type' => 'array', 'items' => ['type' => 'string']],
                    'hide_menus'       => ['type' => 'array', 'items' => ['type' => 'string']],
                    'hide_plugin_from' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
                'additionalProperties' => false,
            ],
            'role'    => ['type' => 'string'],
            'confirm' => ['type' => 'boolean'],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'  => ['type' => 'boolean'],
            'action'   => ['type' => 'string'],
            'config'   => ['type' => 'object'],
            'warnings' => ['type' => 'array'],
            'preview'  => ['type' => 'object'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_white_label_ability',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
    ],
]);

/** @return array|WP_Error */
function wpultra_white_label_ability(array $input) {
    if (!function_exists('wpultra_wlabel_merge_config')) {
        require_once __DIR__ . '/../system/whitelabel.php';
    }

    $action = (string) ($input['action'] ?? '');

    switch ($action) {
        case 'status':
            $config = wpultra_wlabel_get_config();
            wpultra_audit_log('white-label', 'status', true);
            return wpultra_ok([
                'action'  => 'status',
                'config'  => $config,
                'preview' => wpultra_wlabel_preview($config, ['administrator']),
            ]);

        case 'preview':
            $config = wpultra_wlabel_get_config();
            $role = wpultra_wlabel_clean_text((string) ($input['role'] ?? ''), 60);
            $roles = $role !== '' ? [$role] : ['administrator'];
            wpultra_audit_log('white-label', "preview role=$role", true);
            return wpultra_ok([
                'action'  => 'preview',
                'preview' => wpultra_wlabel_preview($config, $roles),
                'config'  => $config,
            ]);

        case 'config':
            // Start from current stored config, layer the provided patch fields.
            $current = wpultra_wlabel_get_config();
            $patch = $current;
            if (array_key_exists('enabled', $input)) { $patch['enabled'] = (bool) $input['enabled']; }
            if (is_array($input['brand'] ?? null)) {
                $patch['brand'] = array_merge($current['brand'], $input['brand']);
            }
            if (is_array($input['client_mode'] ?? null)) {
                $patch['client_mode'] = array_merge($current['client_mode'], $input['client_mode']);
            }

            $validated = wpultra_wlabel_validate($patch);
            $saved = wpultra_wlabel_save_config($validated['config']);
            wpultra_audit_log('white-label', 'config enabled=' . ($saved['enabled'] ? '1' : '0') . ' client_mode=' . (!empty($saved['client_mode']['enabled']) ? '1' : '0') . ' warnings=' . count($validated['warnings']), true);
            return wpultra_ok([
                'action'   => 'config',
                'config'   => $saved,
                'warnings' => $validated['warnings'],
                'preview'  => wpultra_wlabel_preview($saved, ['administrator']),
            ]);

        case 'reset':
            if (($input['confirm'] ?? false) !== true) {
                return wpultra_err('unconfirmed', 'Reset clears all white-label branding and returns the plugin to WP Ultra MCP defaults. On a live client site this un-brands every admin screen. Re-run with confirm: true.');
            }
            if (function_exists('delete_option')) {
                delete_option(WPULTRA_WHITELABEL_OPTION);
            }
            $config = wpultra_wlabel_defaults();
            wpultra_audit_log('white-label', 'reset', true);
            return wpultra_ok([
                'action'  => 'reset',
                'config'  => $config,
                'preview' => wpultra_wlabel_preview($config, ['administrator']),
            ]);

        default:
            return wpultra_err('bad_action', "Unknown action '$action'. Use one of: config, status, preview, reset.");
    }
}
