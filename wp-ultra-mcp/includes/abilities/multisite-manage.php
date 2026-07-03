<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Baseline gate for the ability. The fine-grained "mutations need super-admin" rule is
 * enforced inside wpultra_multisite_manage() itself, since the ability framework's
 * permission_callback here runs without access to the parsed action input.
 */
function wpultra_multisite_manage_permission(): bool {
    return wpultra_permission_callback();
}

wp_register_ability('wpultra/multisite-manage', [
    'label'       => __('Manage Multisite Network', 'wp-ultra-mcp'),
    'description' => __('Manage a WordPress multisite network. actions: `list` (all sites), `create` (slug, title, blog_id? admin user via blog_id-less admin_user_id), `set-status` (blog_id, field in archived|deleted|spam|public, value, confirm for deleted/archived), `delete` (blog_id, confirm), `get-option`/`set-option` (name, value) for network (site) options. Returns `not_multisite` on single-site installs. Mutating actions require super-admin.', 'wp-ultra-mcp'),
    'category'    => 'system',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['list', 'create', 'set-status', 'delete', 'get-option', 'set-option'], 'default' => 'list'],
            'slug'    => ['type' => 'string'],
            'title'   => ['type' => 'string'],
            'blog_id' => ['type' => 'integer'],
            'admin_user_id' => ['type' => 'integer'],
            'field'   => ['type' => 'string', 'enum' => ['archived', 'deleted', 'spam', 'public']],
            'value'   => [],
            'name'    => ['type' => 'string'],
            'confirm' => ['type' => 'boolean'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_multisite_manage',
    'permission_callback' => 'wpultra_multisite_manage_permission',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_multisite_manage(array $input) {
    $action = (string) ($input['action'] ?? 'list');

    // Mutations additionally require super-admin, enforced again here (defense in depth —
    // the permission_callback above may not always see a parsed 'action' depending on transport).
    $is_mutation = $action !== 'list' && $action !== 'get-option';
    if ($is_mutation && function_exists('is_multisite') && is_multisite() && !is_super_admin()) {
        return wpultra_err('forbidden', 'This action requires network super-admin privileges.');
    }

    switch ($action) {
        case 'list':
            $res = wpultra_ms_sites_list();
            break;
        case 'create':
            $slug = (string) ($input['slug'] ?? '');
            $title = (string) ($input['title'] ?? '');
            $admin_user_id = isset($input['admin_user_id']) ? (int) $input['admin_user_id'] : null;
            $res = wpultra_ms_site_create($slug, $title, $admin_user_id);
            break;
        case 'set-status':
            $blog_id = (int) ($input['blog_id'] ?? 0);
            $field = (string) ($input['field'] ?? '');
            $value = (bool) ($input['value'] ?? false);
            if (in_array($field, ['deleted', 'archived'], true) && $value && !(($input['confirm'] ?? false) === true)) {
                $res = wpultra_err('confirm_required', "Setting $field=true is disruptive. Re-run with confirm: true.");
                break;
            }
            $res = wpultra_ms_site_update_status($blog_id, $field, $value);
            break;
        case 'delete':
            $blog_id = (int) ($input['blog_id'] ?? 0);
            $confirm = ($input['confirm'] ?? false) === true;
            $res = wpultra_ms_site_delete($blog_id, $confirm);
            break;
        case 'get-option':
            $name = (string) ($input['name'] ?? '');
            $res = wpultra_ms_network_option_get($name);
            break;
        case 'set-option':
            $name = (string) ($input['name'] ?? '');
            $value = $input['value'] ?? null;
            $res = wpultra_ms_network_option_set($name, $value);
            break;
        default:
            return wpultra_err('bad_action', "Unknown action '$action'.");
    }

    if (is_wp_error($res)) { return $res; }
    return wpultra_ok($res);
}
