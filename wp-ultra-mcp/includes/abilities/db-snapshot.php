<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/db-snapshot', [
    'label'       => __('DB Snapshot', 'wp-ultra-mcp'),
    'description' => __('Create/list/restore/delete gzip SQL snapshots under uploads/wpultra-snapshots (protected with index.php + .htaccess deny). actions: `create` (dump SHOW CREATE + batched INSERTs), `list`, `restore` (raw statement replay; requires confirm:true), `delete` (requires confirm:true). Optional tables[] (default all prefixed tables).', 'wp-ultra-mcp'),
    'category'    => 'database',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action'   => ['type' => 'string', 'enum' => ['create', 'list', 'restore', 'delete'], 'default' => 'list'],
            'snapshot' => ['type' => 'string'],
            'tables'   => ['type' => 'array', 'items' => ['type' => 'string']],
            'confirm'  => ['type' => 'boolean'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'   => ['type' => 'boolean'],
            'snapshot'  => ['type' => 'string'],
            'path'      => ['type' => 'string'],
            'size'      => ['type' => 'integer'],
            'snapshots' => ['type' => 'array'],
            'tables'    => ['type' => 'array'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_siteops_db_snapshot',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);
