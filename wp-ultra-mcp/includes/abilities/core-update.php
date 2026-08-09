<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/core-update', [
    'label'       => __('Update WordPress Core', 'wp-ultra-mcp'),
    'description' => __('Check for and apply WordPress core updates (the plugin can already update plugins/themes; this closes the gap for WordPress itself). actions: `check` (read-only: current version, whether an update is available, and every offer), `update` (apply the recommended update, or a specific `version`; confirm:true — a failed core update can white-screen a site, so take a db-snapshot + site-backup first).', 'wp-ultra-mcp'),
    'category'    => 'system',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['check', 'update'], 'default' => 'check'],
            'version' => ['type' => 'string', 'description' => 'Target core version for update (optional; defaults to the recommended offer).'],
            'force'   => ['type' => 'boolean', 'description' => 'Force a fresh version check instead of the cached one.'],
            'confirm' => ['type' => 'boolean'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'          => ['type' => 'boolean'],
            'current'          => ['type' => 'string'],
            'update_available' => ['type' => 'boolean'],
            'offers'           => ['type' => 'array'],
            'updated'          => ['type' => 'boolean'],
            'from'             => ['type' => 'string'],
            'to'               => ['type' => 'string'],
            'note'             => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_core_update',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);
