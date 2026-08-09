<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/manage-widgets', [
    'label'       => __('Manage Widgets', 'wp-ultra-mcp'),
    'description' => __('Manage classic-theme widgets and sidebars (widget areas) — the coverage block themes get via templates, for non-block themes. actions: `list` (every registered sidebar + the widgets in it, with each widget\'s id, type, and settings, plus the inactive bucket), `list-types` (available widget types), `move` (widget_id → target sidebar at position), `remove` (park a widget in wp_inactive_widgets, or delete_instance:true + confirm:true to discard it and its settings).', 'wp-ultra-mcp'),
    'category'    => 'system',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action'          => ['type' => 'string', 'enum' => ['list', 'list-types', 'move', 'remove'], 'default' => 'list'],
            'widget_id'       => ['type' => 'string', 'description' => 'e.g. "text-2", "nav_menu-3".'],
            'target'          => ['type' => 'string', 'description' => 'Target sidebar id for move.'],
            'position'        => ['type' => 'integer', 'description' => 'Insert index in the target sidebar (-1 = end).'],
            'delete_instance' => ['type' => 'boolean', 'description' => 'remove: hard-delete instead of deactivating.'],
            'confirm'         => ['type' => 'boolean'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'      => ['type' => 'boolean'],
            'sidebars'     => ['type' => 'array'],
            'widget_types' => ['type' => 'array'],
            'count'        => ['type' => 'integer'],
            'widget_id'    => ['type' => 'string'],
            'moved_to'     => ['type' => 'string'],
            'position'     => ['type' => 'integer'],
            'deactivated'  => ['type' => 'boolean'],
            'deleted'      => ['type' => 'boolean'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_manage_widgets',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);
