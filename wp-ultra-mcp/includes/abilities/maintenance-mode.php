<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/maintenance-mode', [
    'label'       => __('Maintenance Mode', 'wp-ultra-mcp'),
    'description' => __('Toggle WordPress maintenance mode by writing/removing the .maintenance file in ABSPATH. actions: `status`, `enable` (optional message; note WP auto-expires after 10 min unless persistent:true is set, which writes a far-future timestamp), `disable`.', 'wp-ultra-mcp'),
    'category'    => 'system',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action'     => ['type' => 'string', 'enum' => ['status', 'enable', 'disable'], 'default' => 'status'],
            'message'    => ['type' => 'string'],
            'persistent' => ['type' => 'boolean'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'enabled' => ['type' => 'boolean'],
            'file'    => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_siteops_maintenance_mode',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);
