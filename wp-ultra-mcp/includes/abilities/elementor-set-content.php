<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/elementor-set-content', [
    'label'       => __('Elementor: Set Content', 'wp-ultra-mcp'),
    'description' => __('Overwrite the entire Elementor element tree of a post with a provided elements array.', 'wp-ultra-mcp'),
    'category'    => 'elementor',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'post_id'  => ['type' => 'integer'],
            'elements' => ['type' => 'array'],
            'force'    => ['type' => 'boolean'],
        ],
        'required'             => ['post_id', 'elements'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'         => ['type' => 'boolean'],
            'post_id'         => ['type' => 'integer'],
            'top_level_count' => ['type' => 'integer'],
            'warning'         => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_elementor_set_content',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_elementor_set_content(array $input) {
    $post_id = (int) ($input['post_id'] ?? 0);
    $elements = $input['elements'] ?? null;
    if (is_string($elements)) { $elements = json_decode($elements, true); }
    if (!is_array($elements)) { return wpultra_err('bad_elements', 'elements must be an array (or JSON string).'); }
    $force = ($input['force'] ?? false) === true;
    $report = wpultra_el_validate_tree($elements);
    if (!$report['ok'] && !$force) {
        $bad = array_values(array_filter($report['nodes'], fn($n) => !$n['valid']));
        return wpultra_err('tree_invalid', $report['summary']['invalid'] . ' element(s) have invalid settings. Fix them (see data) or pass force:true to write anyway.', ['summary' => $report['summary'], 'nodes' => $bad]);
    }
    $tree = $force ? $elements : $report['normalized_tree'];
    $res = wpultra_el_write($post_id, $tree);
    if (is_wp_error($res)) { return $res; }
    if (!$report['ok'] && $force) {
        $res['warning'] = $report['summary']['invalid'] . ' element(s) failed validation but were written (force=true).';
    }
    return $res;
}
