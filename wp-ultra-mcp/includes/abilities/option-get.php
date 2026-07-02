<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/option-get', [
    'label'       => __('Get Option', 'wp-ultra-mcp'),
    'description' => __('Read a wp_options value by `name`. Refuses secret-looking names (auth keys/salts, *secret*, *password*, *_key). Returns value (JSON-safe) and autoload flag.', 'wp-ultra-mcp'),
    'category'    => 'system',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'name' => ['type' => 'string'],
        ],
        'required'             => ['name'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'name'    => ['type' => 'string'],
            'exists'  => ['type' => 'boolean'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_option_get_ability',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_option_get_ability(array $input) {
    $name = (string) ($input['name'] ?? '');
    $res = wpultra_option_get($name);
    if (is_wp_error($res)) { return $res; }
    return wpultra_ok($res);
}
