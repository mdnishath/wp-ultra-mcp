<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/elementor-add-element', [
    'label'       => __('Elementor: Add Element', 'wp-ultra-mcp'),
    'description' => __('Insert a new widget or container into the Elementor element tree at the specified parent and position.', 'wp-ultra-mcp'),
    'category'    => 'elementor',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'post_id'      => ['type' => 'integer'],
            'element_type' => ['type' => 'string'],
            'parent_id'    => ['type' => 'string'],
            'position'     => ['type' => 'integer'],
            'widget_type'  => ['type' => 'string'],
            'settings'     => ['type' => 'object'],
            'element_id'   => ['type' => 'string'],
        ],
        'required'             => ['post_id', 'element_type'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'    => ['type' => 'boolean'],
            'post_id'    => ['type' => 'integer'],
            'element_id' => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_elementor_add_element',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_elementor_add_element(array $input) {
    $atomic = wpultra_el_require_atomic();
    if (is_wp_error($atomic)) { return $atomic; }
    $post_id = (int) ($input['post_id'] ?? 0);
    if ($post_id <= 0 || !get_post($post_id)) { return wpultra_err('bad_post', 'Valid post_id required.'); }
    $elType = (string) ($input['element_type'] ?? '');
    $data = wpultra_el_raw($post_id);
    $suppliedId = (string) ($input['element_id'] ?? '');
    if ($suppliedId !== '' && wpultra_el_find($data, $suppliedId) !== null) {
        return wpultra_err('duplicate_id', "An element with id '$suppliedId' already exists on this page.");
    }
    $id = $suppliedId !== '' ? $suppliedId : wpultra_el_new_id($data);
    $settings = (array) ($input['settings'] ?? []);
    $node = ['id' => $id, 'elType' => $elType, 'settings' => [], 'elements' => []];
    if ($elType === 'widget') {
        $wt = (string) ($input['widget_type'] ?? '');
        if ($wt === '') { return wpultra_err('missing_widget_type', "element_type 'widget' requires widget_type."); }
        $node['widgetType'] = $wt;
        $schema = wpultra_el_widget_schema($wt);
        $compact = (is_array($schema) && !empty($schema['props'])) ? $schema['props'] : [];
        $wrapped = wpultra_el_wrap_settings($settings, $compact);
        $valid = wpultra_el_validate_settings($wt, $wrapped);
        if (is_wp_error($valid)) { return $valid; }
        $node['settings'] = $valid['settings'];
    } else {
        // container (e-flexbox / e-div-block): validate atomic container props (layout: flex/gap/padding/width).
        $nv = wpultra_el_validate_node(['elType' => $elType, 'settings' => $settings]);
        if (!$nv['valid']) { return wpultra_err('invalid_settings', 'Container settings failed validation: ' . implode('; ', $nv['errors'])); }
        $node['settings'] = $nv['settings'];
    }
    $parent = isset($input['parent_id']) && $input['parent_id'] !== '' ? (string) $input['parent_id'] : null;
    $pos = (int) ($input['position'] ?? PHP_INT_MAX);
    $updated = wpultra_el_insert($data, $parent, $pos, $node);
    if (is_wp_error($updated)) { return $updated; }
    $w = wpultra_el_write($post_id, $updated);
    if (is_wp_error($w)) { return $w; }
    return wpultra_ok(['post_id' => $post_id, 'element_id' => $id]);
}
