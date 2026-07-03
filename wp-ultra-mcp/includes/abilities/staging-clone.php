<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/staging-clone', [
    'label'       => __('Staging Clone', 'wp-ultra-mcp'),
    'description' => __('Single-server subdirectory staging: copies the whole site to ABSPATH/staging-<name>/ and clones every DB table under a new prefix (stg<short>_), SHARING the same database. Serialized-safe rewrites the home URL and ABSPATH inside the cloned tables, rewrites the staging copy\'s wp-config.php $table_prefix, sets blog_public=0, and adds a wpultra_staging_of marker. actions: `create` (name required; requires confirm:true; skip_uploads defaults to TRUE), `list`, `delete` (name required; requires confirm:true — drops the cloned tables + removes the dir). CAVEATS: not a substitute for host-level staging (shares the live DB, no isolated PHP pool); hosting rewrites/CDN/multisite may break the subdirectory URL; large sites should keep skip_uploads:true. File copy is capped at 60k files.', 'wp-ultra-mcp'),
    'category'    => 'system',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action'       => ['type' => 'string', 'enum' => ['create', 'list', 'delete']],
            'name'         => ['type' => 'string', 'description' => 'Staging slug [a-z0-9-], required for create/delete.'],
            'skip_uploads' => ['type' => 'boolean', 'description' => 'Skip wp-content/uploads on create (default true).'],
            'confirm'      => ['type' => 'boolean', 'description' => 'Required for create and delete.'],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'  => ['type' => 'boolean'],
            'name'     => ['type' => 'string'],
            'url'      => ['type' => 'string'],
            'path'     => ['type' => 'string'],
            'prefix'   => ['type' => 'string'],
            'tables'   => ['type' => 'integer'],
            'files'    => ['type' => 'integer'],
            'stagings' => ['type' => 'array'],
            'count'    => ['type' => 'integer'],
            'deleted'  => ['type' => 'boolean'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_staging',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);
