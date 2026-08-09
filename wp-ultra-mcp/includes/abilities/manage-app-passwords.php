<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/manage-app-passwords', [
    'label'       => __('Manage Application Passwords', 'wp-ultra-mcp'),
    'description' => __('Audit and revoke WordPress application passwords — the credentials MCP/REST clients authenticate with. actions: `list` (uuid, name, created, last_used, last_ip for a user — defaults to the acting user; admins pass user_id for any user), `revoke` (delete one by uuid; confirm:true). Creating a new password is done on the Connect page so the secret is revealed exactly once.', 'wp-ultra-mcp'),
    'category'    => 'users',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['list', 'revoke'], 'default' => 'list'],
            'user_id' => ['type' => 'integer', 'description' => 'Target user (admins only); defaults to the acting user.'],
            'uuid'    => ['type' => 'string'],
            'confirm' => ['type' => 'boolean'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'               => ['type' => 'boolean'],
            'user_id'               => ['type' => 'integer'],
            'application_passwords' => ['type' => 'array'],
            'count'                 => ['type' => 'integer'],
            'uuid'                  => ['type' => 'string'],
            'revoked'               => ['type' => 'boolean'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_manage_app_passwords',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);
