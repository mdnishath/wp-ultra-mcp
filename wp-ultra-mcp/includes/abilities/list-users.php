<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/list-users', [
    'label'       => __('List Users', 'wp-ultra-mcp'),
    'description' => __('List WordPress users with optional `role`, `search`, and pagination (`per_page` default 20 max 200, `page`). Returns id, login, email, display_name, roles, registered, post_count.', 'wp-ultra-mcp'),
    'category'    => 'users',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'role'     => ['type' => 'string'],
            'search'   => ['type' => 'string'],
            'per_page' => ['type' => 'integer', 'default' => 20],
            'page'     => ['type' => 'integer', 'default' => 1],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'users'   => ['type' => 'array'],
            'total'   => ['type' => 'integer'],
            'pages'   => ['type' => 'integer'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_list_users_ability',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_list_users_ability(array $input) {
    $res = wpultra_users_list($input);
    if (is_wp_error($res)) { return $res; }
    return wpultra_ok($res);
}
