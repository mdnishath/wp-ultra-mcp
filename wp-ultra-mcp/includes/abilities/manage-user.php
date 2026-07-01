<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/manage-user', [
    'label'       => __('Manage Users', 'wp-ultra-mcp'),
    'description' => __('List, read, create, update, or delete WordPress users and their roles/meta. actions: `list` (page/per_page/role/search), `get` (id), `create` (login,email,role,password?), `update` (id + fields), `delete` (id, reassign_to?). Assigning administrator/super-admin requires `allow_admin: true`.', 'wp-ultra-mcp'),
    'category'    => 'users',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action'       => ['type' => 'string', 'enum' => ['list', 'get', 'create', 'update', 'delete'], 'default' => 'list'],
            'id'           => ['type' => 'integer'],
            'login'        => ['type' => 'string'],
            'email'        => ['type' => 'string'],
            'password'     => ['type' => 'string'],
            'role'         => ['type' => 'string'],
            'display_name' => ['type' => 'string'],
            'first_name'   => ['type' => 'string'],
            'last_name'    => ['type' => 'string'],
            'meta'         => ['type' => 'object'],
            'per_page'     => ['type' => 'integer'],
            'page'         => ['type' => 'integer'],
            'search'       => ['type' => 'string'],
            'reassign_to'  => ['type' => 'integer'],
            'allow_admin'  => ['type' => 'boolean'],
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
    'execute_callback'    => 'wpultra_manage_user',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_manage_user(array $input) {
    $action = (string) ($input['action'] ?? 'list');
    $allow_admin = ($input['allow_admin'] ?? false) === true;

    switch ($action) {
        case 'list':
            $res = wpultra_user_list((int) ($input['per_page'] ?? 25), (int) ($input['page'] ?? 1), (string) ($input['role'] ?? ''), (string) ($input['search'] ?? ''));
            break;
        case 'get':
            $id = (int) ($input['id'] ?? 0);
            $res = get_userdata($id) ? wpultra_user_shape($id) : wpultra_err('not_found', "No user with id $id.");
            break;
        case 'create':
            $res = wpultra_user_create($input, $allow_admin);
            break;
        case 'update':
            $res = wpultra_user_update($input, $allow_admin);
            break;
        case 'delete':
            $res = wpultra_user_delete((int) ($input['id'] ?? 0), (int) ($input['reassign_to'] ?? 0));
            break;
        default:
            return wpultra_err('bad_action', "Unknown action '$action'.");
    }

    if (is_wp_error($res)) { return $res; }
    if ($action !== 'list' && $action !== 'get') { wpultra_audit_log('manage-user', "$action id=" . (string) ($res['id'] ?? ($input['id'] ?? '?')), true); }
    return wpultra_ok($res);
}
