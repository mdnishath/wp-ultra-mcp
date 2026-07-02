<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/search-replace', [
    'label'       => __('Search Replace', 'wp-ultra-mcp'),
    'description' => __('Serialized-data-safe search & replace across the database (default tables: posts, postmeta, options). Recursively walks serialized arrays/objects and re-serializes with corrected lengths. dry_run:true (default) only reports counts; dry_run:false requires confirm:true. Returns per-table match/replace counts.', 'wp-ultra-mcp'),
    'category'    => 'database',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'search'  => ['type' => 'string'],
            'replace' => ['type' => 'string'],
            'tables'  => ['type' => 'array', 'items' => ['type' => 'string']],
            'dry_run' => ['type' => 'boolean', 'default' => true],
            'confirm' => ['type' => 'boolean'],
        ],
        'required'             => ['search', 'replace'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'dry_run' => ['type' => 'boolean'],
            'tables'  => ['type' => 'object'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_siteops_search_replace',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);
