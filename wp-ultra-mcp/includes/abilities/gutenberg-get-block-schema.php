<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/gutenberg-get-block-schema', [
    'label'       => __('Gutenberg: Get Block Schema', 'wp-ultra-mcp'),
    'description' => __('Get the attribute schema + supports for one block type.', 'wp-ultra-mcp'),
    'category'    => 'gutenberg',
    'input_schema' => [
        'type'       => 'object',
        'properties' => ['name' => ['type' => 'string']],
        'required'   => ['name'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => ['success' => ['type' => 'boolean'], 'attributes' => ['type' => 'object'], 'supports' => ['type' => 'object']],
        'required'   => ['success'],
    ],
    'execute_callback'    => 'wpultra_gb_get_block_schema_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_gb_get_block_schema_cb(array $input) {
    $schema = wpultra_gb_block_schema((string) ($input['name'] ?? ''));
    if (is_wp_error($schema)) { return $schema; }
    return wpultra_ok($schema);
}
