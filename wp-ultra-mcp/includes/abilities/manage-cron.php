<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/manage-cron', [
    'label'       => __('Manage Cron', 'wp-ultra-mcp'),
    'description' => __('Inspect and manage WP-Cron. actions: `list` (all scheduled events: hook, next_run ISO, schedule, args), `run` (fire a hook now via a single-event + spawn_cron), `delete` (unschedule a hook; requires confirm:true).', 'wp-ultra-mcp'),
    'category'    => 'system',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action'    => ['type' => 'string', 'enum' => ['list', 'run', 'delete'], 'default' => 'list'],
            'hook'      => ['type' => 'string'],
            'timestamp' => ['type' => 'integer'],
            'args'      => ['type' => 'array'],
            'confirm'   => ['type' => 'boolean'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'events'  => ['type' => 'array'],
            'count'   => ['type' => 'integer'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_siteops_manage_cron',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);
