<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/elementor-validate', [
    'label'       => __('Elementor: Validate Tree', 'wp-ultra-mcp'),
    'description' => __('Dry-run validate an element tree (supplied or a post\'s current content) against Elementor atomic schemas. Returns a per-node report of which settings would be rejected — fix them before writing.', 'wp-ultra-mcp'),
    'category'    => 'elementor',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'post_id'  => ['type' => 'integer'],
            'elements' => ['type' => 'array'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'ok'      => ['type' => 'boolean'],
            'summary' => ['type' => 'object'],
            'nodes'   => ['type' => 'array'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_elementor_validate',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_elementor_validate(array $input) {
    $elements = $input['elements'] ?? null;
    if (is_string($elements)) { $elements = json_decode($elements, true); }
    if (!is_array($elements)) {
        $post_id = (int) ($input['post_id'] ?? 0);
        if ($post_id <= 0 || !get_post($post_id)) { return wpultra_err('bad_input', 'Provide elements (array) or a valid post_id.'); }
        $elements = wpultra_el_raw($post_id);
    }
    $report = wpultra_el_validate_tree($elements);
    return wpultra_ok([
        'ok'      => $report['ok'],
        'summary' => $report['summary'],
        'nodes'   => array_values(array_filter($report['nodes'], fn($n) => !$n['valid'])),
    ]);
}
