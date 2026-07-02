<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/bricks-status', [
    'label'       => __('Bricks: Status', 'wp-ultra-mcp'),
    'description' => __('Reports whether the Bricks theme/builder is active, its version, and the post types Bricks editing is enabled for. Call FIRST before any other Bricks ability — active:false means Bricks is not installed on this site.', 'wp-ultra-mcp'),
    'category'    => 'bricks',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'    => ['type' => 'boolean'],
            'active'     => ['type' => 'boolean'],
            'version'    => ['type' => ['string', 'null']],
            'post_types' => ['type' => 'array'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_bricks_status_ability',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_bricks_status_ability(array $input) {
    return wpultra_ok(wpultra_bricks_status());
}
