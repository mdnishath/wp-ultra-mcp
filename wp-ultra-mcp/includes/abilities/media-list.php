<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/media-list', [
    'label'       => __('List Media', 'wp-ultra-mcp'),
    'description' => __('List Media Library attachments with optional `search`, `mime` (e.g. \'image\'), `unattached` filter, and pagination (`per_page` default 20 max 100, `page`). Returns compact items + total/pages.', 'wp-ultra-mcp'),
    'category'    => 'content',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'search'     => ['type' => 'string'],
            'mime'       => ['type' => 'string'],
            'unattached' => ['type' => 'boolean'],
            'per_page'   => ['type' => 'integer', 'default' => 20],
            'page'       => ['type' => 'integer', 'default' => 1],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'items'   => ['type' => 'array'],
            'total'   => ['type' => 'integer'],
            'pages'   => ['type' => 'integer'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_media_list_ability',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_media_list_ability(array $input) {
    $res = wpultra_media_list($input);
    if (is_wp_error($res)) { return $res; }
    return wpultra_ok($res);
}
