<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/purge-cache', [
    'label'       => __('Purge Cache', 'wp-ultra-mcp'),
    'description' => __('Purge every known cache layer that is actually present (WP Rocket, LiteSpeed, W3 Total Cache, WP Super Cache, Autoptimize, Elementor CSS cache, plus the WordPress object cache). Reports which layers were purged vs skipped (not installed).', 'wp-ultra-mcp'),
    'category'    => 'system',
    'input_schema'  => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'purged'  => ['type' => 'array'],
            'skipped' => ['type' => 'array'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_purge_cache_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_purge_cache_cb(array $input) {
    return wpultra_devtools_purge_cache();
}
