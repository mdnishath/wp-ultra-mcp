<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/manage-menu', [
    'label'       => __('Manage Navigation Menu', 'wp-ultra-mcp'),
    'description' => __('List, inspect, create, or delete nav menus; add/update/remove menu items; assign a menu to a theme location.', 'wp-ultra-mcp'),
    'category'    => 'content',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['list-menus', 'get', 'create-menu', 'delete-menu', 'add-item', 'update-item', 'remove-item', 'assign-location']],
            'name'    => ['type' => 'string', 'description' => 'Menu name for create-menu.'],
            'menu'    => ['type' => ['string', 'integer']],
            'item_id' => ['type' => 'integer'],
            'item'    => [
                'type'       => 'object',
                'properties' => [
                    'title'       => ['type' => 'string'],
                    'url'         => ['type' => 'string'],
                    'object_id'   => ['type' => 'integer'],
                    'object_type' => ['type' => 'string', 'enum' => ['post', 'term']],
                    'object'      => ['type' => 'string'],
                    'parent_item' => ['type' => 'integer'],
                    'position'    => ['type' => 'integer'],
                ],
                'additionalProperties' => false,
            ],
            'location' => ['type' => 'string'],
            'confirm'  => ['type' => 'boolean'],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'   => ['type' => 'boolean'],
            'menus'     => ['type' => 'array'],
            'locations' => ['type' => 'object'],
            'menu_id'   => ['type' => 'integer'],
            'name'      => ['type' => 'string'],
            'items'     => ['type' => 'array'],
            'item_id'   => ['type' => 'integer'],
            'location'  => ['type' => 'string'],
            'deleted'   => ['type' => 'boolean'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_manage_menu',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_manage_menu(array $input) {
    $action = (string) ($input['action'] ?? '');

    switch ($action) {
        case 'list-menus':
            $result = wpultra_structure_menu_list();
            if (is_wp_error($result)) { return $result; }
            return wpultra_ok(['menus' => $result['menus'], 'locations' => (object) $result['locations']]);

        case 'get':
            if (empty($input['menu'])) { return wpultra_err('missing_menu', 'menu is required.'); }
            $result = wpultra_structure_menu_get($input['menu']);
            if (is_wp_error($result)) { return $result; }
            return wpultra_ok($result);

        case 'create-menu':
            $name = (string) ($input['name'] ?? $input['menu'] ?? '');
            $result = wpultra_structure_menu_create($name);
            if (is_wp_error($result)) { wpultra_audit_log('manage-menu', 'create-menu failed', false); return $result; }
            wpultra_audit_log('manage-menu', "created menu '{$result['name']}'");
            return wpultra_ok($result);

        case 'delete-menu':
            if (empty($input['menu'])) { return wpultra_err('missing_menu', 'menu is required.'); }
            if (($input['confirm'] ?? false) !== true) {
                return wpultra_err('confirm_required', 'Deleting a menu requires confirm: true.');
            }
            $result = wpultra_structure_menu_delete($input['menu']);
            if (is_wp_error($result)) { wpultra_audit_log('manage-menu', 'delete-menu failed', false); return $result; }
            wpultra_audit_log('manage-menu', "deleted menu {$result['menu_id']}");
            return wpultra_ok($result);

        case 'add-item':
            if (empty($input['menu'])) { return wpultra_err('missing_menu', 'menu is required.'); }
            if (empty($input['item']) || !is_array($input['item'])) { return wpultra_err('missing_item', 'item is required.'); }
            $result = wpultra_structure_menu_add_item($input['menu'], $input['item']);
            if (is_wp_error($result)) { wpultra_audit_log('manage-menu', 'add-item failed', false); return $result; }
            wpultra_audit_log('manage-menu', "added item {$result['item_id']} to menu {$result['menu_id']}");
            return wpultra_ok($result);

        case 'update-item':
            if (empty($input['menu'])) { return wpultra_err('missing_menu', 'menu is required.'); }
            $item_id = (int) ($input['item_id'] ?? 0);
            if ($item_id <= 0) { return wpultra_err('missing_item_id', 'item_id is required.'); }
            if (empty($input['item']) || !is_array($input['item'])) { return wpultra_err('missing_item', 'item is required.'); }
            $result = wpultra_structure_menu_update_item($input['menu'], $item_id, $input['item']);
            if (is_wp_error($result)) { wpultra_audit_log('manage-menu', "update-item failed for $item_id", false); return $result; }
            wpultra_audit_log('manage-menu', "updated menu item $item_id");
            return wpultra_ok($result);

        case 'remove-item':
            $item_id = (int) ($input['item_id'] ?? 0);
            if ($item_id <= 0) { return wpultra_err('missing_item_id', 'item_id is required.'); }
            if (($input['confirm'] ?? false) !== true) {
                return wpultra_err('confirm_required', 'Removing a menu item requires confirm: true.');
            }
            $result = wpultra_structure_menu_remove_item($item_id);
            if (is_wp_error($result)) { wpultra_audit_log('manage-menu', "remove-item failed for $item_id", false); return $result; }
            wpultra_audit_log('manage-menu', "removed menu item $item_id");
            return wpultra_ok($result);

        case 'assign-location':
            if (empty($input['menu'])) { return wpultra_err('missing_menu', 'menu is required.'); }
            $location = (string) ($input['location'] ?? '');
            if ($location === '') { return wpultra_err('missing_location', 'location is required.'); }
            $result = wpultra_structure_menu_assign_location($input['menu'], $location);
            if (is_wp_error($result)) { wpultra_audit_log('manage-menu', 'assign-location failed', false); return $result; }
            wpultra_audit_log('manage-menu', "assigned menu {$result['menu_id']} to location '$location'");
            return wpultra_ok($result);

        default:
            return wpultra_err('invalid_action', "Unknown action '$action'.");
    }
}
