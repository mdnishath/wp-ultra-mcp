<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/render-page', [
    'label'       => __('Render Page (Diagnostics)', 'wp-ultra-mcp'),
    'description' => __('Server-side fetch of any front-end URL (or a post by id) reporting HTTP status, page title, h1 count, body length, load time, and fatal/critical-error markers. Generic sibling of elementor-render-check — use for any page, not just Elementor.', 'wp-ultra-mcp'),
    'category'    => 'diagnostics',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'url'     => ['type' => 'string'],
            'post_id' => ['type' => 'integer'],
            'checks'  => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'        => ['type' => 'boolean'],
            'url'            => ['type' => 'string'],
            'http_status'    => ['type' => 'integer'],
            'load_ms'        => ['type' => 'number'],
            'title'          => ['type' => 'string'],
            'h1_count'       => ['type' => 'integer'],
            'body_length'    => ['type' => 'integer'],
            'fatal_detected' => ['type' => 'boolean'],
            'fatal_markers'  => ['type' => 'array'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_render_page_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_render_page_cb(array $input) {
    return wpultra_devtools_render_page($input);
}
