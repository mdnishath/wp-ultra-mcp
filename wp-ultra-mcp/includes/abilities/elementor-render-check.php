<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/elementor-render-check', [
    'label'       => __('Elementor: Render Check', 'wp-ultra-mcp'),
    'description' => __('Render a post\'s Elementor content server-side and report which elements actually rendered, any dropped element ids, whether CSS was generated, and the front-end preview URL (screenshot it to compare against the reference).', 'wp-ultra-mcp'),
    'category'    => 'elementor',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => ['post_id' => ['type' => 'integer']],
        'required'   => ['post_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'        => ['type' => 'boolean'],
            'preview_url'    => ['type' => 'string'],
            'expected_count' => ['type' => 'integer'],
            'rendered_count' => ['type' => 'integer'],
            'dropped_ids'    => ['type' => 'array'],
            'css_generated'  => ['type' => 'boolean'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_elementor_render_check',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_elementor_render_check(array $input) {
    return wpultra_el_render_check((int) ($input['post_id'] ?? 0));
}
