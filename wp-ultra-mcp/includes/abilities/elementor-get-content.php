<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/elementor-get-content', [
    'label'       => __('Elementor: Get Content', 'wp-ultra-mcp'),
    'description' => __('Read the Elementor element tree from a post, optionally filtered to a single element.', 'wp-ultra-mcp'),
    'category'    => 'elementor',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'post_id'    => ['type' => 'integer'],
            'element_id' => ['type' => 'string'],
            'full'       => ['type' => 'boolean'],
        ],
        'required'             => ['post_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_elementor_get_content',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_elementor_get_content(array $input) {
    $post_id = (int) ($input['post_id'] ?? 0);
    $opts = [];
    if (!empty($input['element_id'])) { $opts['element_id'] = (string) $input['element_id']; }
    if (($input['full'] ?? false) === true) { $opts['full'] = true; }
    return wpultra_el_read($post_id, $opts);
}
