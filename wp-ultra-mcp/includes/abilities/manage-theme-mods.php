<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/manage-theme-mods', [
    'label'       => __('Manage Theme Mods', 'wp-ultra-mcp'),
    'description' => __('Read and write theme mods (Customizer settings) for the active theme — the classic-theme equivalent of theme.json global styles (colors, logo, layout, custom options). actions: `list` (all mods, secrets redacted), `get` (one by key), `set` (key + value; creates or updates), `remove` (revert one to its default; confirm:true). Sensitive-looking keys are refused for read/write.', 'wp-ultra-mcp'),
    'category'    => 'system',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['list', 'get', 'set', 'remove'], 'default' => 'list'],
            'key'     => ['type' => 'string'],
            'value'   => wpultra_schema_any(),
            'confirm' => ['type' => 'boolean'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'    => ['type' => 'boolean'],
            'theme'      => ['type' => 'string'],
            'theme_mods' => ['type' => 'object'],
            'count'      => ['type' => 'integer'],
            'key'        => ['type' => 'string'],
            'value'      => wpultra_schema_any(),
            'saved'      => ['type' => 'boolean'],
            'removed'    => ['type' => 'boolean'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_manage_theme_mods',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);
