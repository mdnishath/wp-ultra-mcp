<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/import-content', [
    'label'       => __('Import Content', 'wp-ultra-mcp'),
    'description' => __('Import a WordPress WXR (.xml) file via the WordPress Importer (WP_Import). Requires a jailed path and confirm:true. If the importer plugin is not installed, returns importer_unavailable advising to install "WordPress Importer". Returns imported counts.', 'wp-ultra-mcp'),
    'category'    => 'system',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'path'    => ['type' => 'string'],
            'confirm' => ['type' => 'boolean'],
        ],
        'required'             => ['path'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'  => ['type' => 'boolean'],
            'path'     => ['type' => 'string'],
            'imported' => ['type' => 'object'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_siteops_import_content',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);
