<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/elementor-list-blueprints', [
    'label'       => __('Elementor: List Blueprints', 'wp-ultra-mcp'),
    'description' => __('List the built-in structural section skeletons (navbar/hero/feature-grid/cta/footer). Pass name to get one blueprint\'s element tree.', 'wp-ultra-mcp'),
    'category'    => 'elementor',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => ['name' => ['type' => 'string']],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => ['success' => ['type' => 'boolean'], 'blueprints' => ['type' => 'array'], 'tree' => ['type' => 'array']],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_elementor_list_blueprints',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_elementor_list_blueprints(array $input) {
    $all = wpultra_el_blueprints();
    $name = (string) ($input['name'] ?? '');
    if ($name !== '') {
        if (!isset($all[$name])) { return wpultra_err('bad_blueprint', "No blueprint '$name'. Available: " . implode(', ', array_keys($all))); }
        return wpultra_ok(['tree' => $all[$name]['tree'], 'blueprints' => [['name' => $name] + array_intersect_key($all[$name], ['description' => 1, 'summary' => 1])]]);
    }
    $list = [];
    foreach ($all as $n => $bp) { $list[] = ['name' => $n, 'description' => $bp['description'], 'summary' => $bp['summary']]; }
    return wpultra_ok(['blueprints' => $list]);
}
