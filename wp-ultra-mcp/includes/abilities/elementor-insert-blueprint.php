<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/elementor-insert-blueprint', [
    'label'       => __('Elementor: Insert Blueprint', 'wp-ultra-mcp'),
    'description' => __('Insert a built-in structural section skeleton into a post (fresh ids, validated). Then style it with design tokens + global classes.', 'wp-ultra-mcp'),
    'category'    => 'elementor',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'post_id'   => ['type' => 'integer'],
            'blueprint' => ['type' => 'string'],
            'parent_id' => ['type' => 'string'],
            'position'  => ['type' => 'integer'],
        ],
        'required'             => ['post_id', 'blueprint'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => ['success' => ['type' => 'boolean'], 'inserted_ids' => ['type' => 'array'], 'elements' => ['type' => 'array']],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_elementor_insert_blueprint',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_elementor_insert_blueprint(array $input) {
    $post_id = (int) ($input['post_id'] ?? 0);
    if ($post_id <= 0 || !get_post($post_id)) { return wpultra_err('bad_post', 'Valid post_id required.'); }
    $name = (string) ($input['blueprint'] ?? '');
    $all = wpultra_el_blueprints();
    if (!isset($all[$name])) { return wpultra_err('bad_blueprint', "No blueprint '$name'. Available: " . implode(', ', array_keys($all))); }

    $page = wpultra_el_raw($post_id);
    $reided = wpultra_el_blueprint_reid($all[$name]['tree'], $page);

    $report = wpultra_el_validate_tree($reided);
    if (!$report['ok']) {
        $bad = array_values(array_filter($report['nodes'], fn($n) => !$n['valid']));
        return wpultra_err('blueprint_invalid', "Blueprint '$name' failed validation on this Elementor version.", ['nodes' => $bad]);
    }
    $tree = $report['normalized_tree'];

    $ids = wpultra_el_collect_ids($tree);
    $parent = isset($input['parent_id']) && $input['parent_id'] !== '' ? (string) $input['parent_id'] : null;
    $pos = isset($input['position']) ? (int) $input['position'] : PHP_INT_MAX;
    // Insert each top-level blueprint node at the target.
    $updated = $page;
    foreach ($tree as $node) {
        $updated = wpultra_el_insert($updated, $parent, $pos, $node);
        if (is_wp_error($updated)) { return $updated; }
        if ($pos !== PHP_INT_MAX) { $pos++; }
    }
    $w = wpultra_el_write($post_id, $updated);
    wpultra_audit_log('elementor-insert-blueprint', "post $post_id <- blueprint '$name' (" . count($ids) . ' nodes)', !is_wp_error($w));
    if (is_wp_error($w)) { return $w; }
    return wpultra_ok(['inserted_ids' => $ids, 'elements' => wpultra_el_compact_tree($updated)]);
}
