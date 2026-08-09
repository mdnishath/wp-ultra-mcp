<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/manage-transients', [
    'label'       => __('Manage Transients', 'wp-ultra-mcp'),
    'description' => __('Inspect and clean the transient cache. actions: `list` (every transient with key, byte size, expiry, and whether it has expired), `get` (one transient value by key), `delete` (one transient by key; confirm:true), `delete-expired` (sweep every expired transient — safe, never touches live ones). Regular single-site transients; object-cache backends are handled via the core transient functions.', 'wp-ultra-mcp'),
    'category'    => 'system',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['list', 'get', 'delete', 'delete-expired'], 'default' => 'list'],
            'key'     => ['type' => 'string'],
            'confirm' => ['type' => 'boolean'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'    => ['type' => 'boolean'],
            'transients' => ['type' => 'array'],
            'count'      => ['type' => 'integer'],
            'expired'    => ['type' => 'integer'],
            'key'        => ['type' => 'string'],
            'value'      => wpultra_schema_any(),
            'deleted'    => wpultra_schema_any(),
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_manage_transients',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);
