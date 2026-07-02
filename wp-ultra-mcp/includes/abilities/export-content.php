<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/export-content', [
    'label'       => __('Export Content', 'wp-ultra-mcp'),
    'description' => __('Export site content to a WordPress WXR (.xml) file via export_wp(). Optional post_types[] (default all public) and status[]. Writes to a jailed path under uploads/wpultra-exports. Returns file path, size, and post types exported.', 'wp-ultra-mcp'),
    'category'    => 'system',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'post_types' => ['type' => 'array', 'items' => ['type' => 'string']],
            'status'     => ['type' => 'array', 'items' => ['type' => 'string']],
            'path'       => ['type' => 'string'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'    => ['type' => 'boolean'],
            'path'       => ['type' => 'string'],
            'size'       => ['type' => 'integer'],
            'post_types' => ['type' => 'array'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_siteops_export_content',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);
