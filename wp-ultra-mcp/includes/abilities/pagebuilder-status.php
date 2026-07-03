<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/pagebuilder-status', [
    'label'       => __('Page Builders: Status', 'wp-ultra-mcp'),
    'description' => __('Detect which of Divi / Beaver Builder / Oxygen is installed (with versions) and which driver the other pagebuilder-* abilities will auto-select. Elementor has its own elementor-* abilities and Bricks its bricks-* set — this domain covers the other three builders.', 'wp-ultra-mcp'),
    'category'    => 'builders',
    'input_schema'  => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'  => ['type' => 'boolean'],
            'builders' => ['type' => 'object'],
            'driver'   => ['type' => ['string', 'null']],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_pagebuilder_status_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_pagebuilder_status_cb(array $input) {
    $detected = wpultra_builders_detect();
    $driver = wpultra_builders_driver('', $detected);
    return wpultra_ok(['builders' => $detected, 'driver' => is_string($driver) && isset($detected[$driver]) ? $driver : null]);
}
