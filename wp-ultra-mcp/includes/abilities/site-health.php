<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/site-health', [
    'label'       => __('Site Health', 'wp-ultra-mcp'),
    'description' => __('Run WordPress core Site Health synchronous (direct) tests and report each result: slug, status (good|recommended|critical), label. Also returns a critical_count. Loads WP_Site_Health on demand; returns site_health_unavailable if the class is missing.', 'wp-ultra-mcp'),
    'category'    => 'diagnostics',
    'input_schema'  => [
        'type'                 => 'object',
        'properties'           => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'        => ['type' => 'boolean'],
            'tests'          => ['type' => 'array'],
            'count'          => ['type' => 'integer'],
            'critical_count' => ['type' => 'integer'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_siteops_site_health',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);
