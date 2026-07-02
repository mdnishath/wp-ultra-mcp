<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/theme-json-get', [
    'label'       => __('Get Theme JSON', 'wp-ultra-mcp'),
    'description' => __('Read merged (theme+user), theme-only, or user-only global styles/settings data (theme.json layers) for the active block theme.', 'wp-ultra-mcp'),
    'category'    => 'fse',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'layer' => ['type' => 'string', 'enum' => ['merged', 'theme', 'user'], 'default' => 'merged'],
        ],
        'required'             => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'layer'   => ['type' => 'string'],
            'data'    => ['type' => 'object'],
            'user'    => ['type' => 'object'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_theme_json_get',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_theme_json_get(array $input) {
    $layer = (string) ($input['layer'] ?? 'merged');
    if (!in_array($layer, ['merged', 'theme', 'user'], true)) {
        return wpultra_err('bad_layer', 'layer must be merged, theme, or user.');
    }
    $res = wpultra_fse_theme_json_get($layer);
    if (is_wp_error($res)) { return $res; }
    return wpultra_ok($res);
}
