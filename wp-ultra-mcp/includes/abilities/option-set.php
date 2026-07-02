<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/option-set', [
    'label'       => __('Set Option', 'wp-ultra-mcp'),
    'description' => __('Write a wp_options value by `name` (any JSON `value`). Refuses secret-looking names and WP-Ultra-MCP\'s own critical options (self-lockout guard). `confirm: true` is required when overwriting an existing option.', 'wp-ultra-mcp'),
    'category'    => 'system',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'name'    => ['type' => 'string'],
            'value'   => [],
            'confirm' => ['type' => 'boolean'],
        ],
        'required'             => ['name', 'value'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'name'    => ['type' => 'string'],
            'updated' => ['type' => 'boolean'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_option_set_ability',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => true],
    ],
]);

function wpultra_option_set_ability(array $input) {
    $name = (string) ($input['name'] ?? '');
    $value = $input['value'] ?? null;
    $confirm = ($input['confirm'] ?? false) === true;
    $res = wpultra_option_set($name, $value, $confirm);
    if (is_wp_error($res)) { return $res; }
    return wpultra_ok($res);
}
