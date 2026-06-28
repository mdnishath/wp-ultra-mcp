<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/elementor-edit-element', [
    'label'       => __('Elementor: Edit Element', 'wp-ultra-mcp'),
    'description' => __('Merge updated settings into an existing Elementor element, wrapping and validating against its widget schema.', 'wp-ultra-mcp'),
    'category'    => 'elementor',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'post_id'    => ['type' => 'integer'],
            'element_id' => ['type' => 'string'],
            'settings'   => ['type' => 'object'],
            'deep'       => ['type' => 'boolean'],
        ],
        'required'             => ['post_id', 'element_id', 'settings'],
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
    'execute_callback'    => 'wpultra_elementor_edit_element',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_elementor_edit_element(array $input) {
    $post_id = (int) ($input['post_id'] ?? 0);
    $eid = (string) ($input['element_id'] ?? '');
    if ($post_id <= 0 || $eid === '') { return wpultra_err('bad_input', 'post_id and element_id are required.'); }
    $data = wpultra_el_raw($post_id);
    $node = wpultra_el_find($data, $eid);
    if ($node === null) { return wpultra_err('element_not_found', "No element '$eid'."); }
    $settings = (array) ($input['settings'] ?? []);
    if (($node['elType'] ?? '') === 'widget' && !empty($node['widgetType'])) {
        $schema = wpultra_el_widget_schema((string) $node['widgetType']);
        $compact = (is_array($schema) && !empty($schema['props'])) ? $schema['props'] : [];
        $settings = wpultra_el_wrap_settings($settings, $compact);
        $valid = wpultra_el_validate_settings((string) $node['widgetType'], array_merge((array) ($node['settings'] ?? []), $settings));
        if (is_wp_error($valid)) { return $valid; }
    } elseif (($node['elType'] ?? '') !== 'widget') {
        // container: validate the merged result so layout props can't be silently dropped.
        $merged = array_merge((array) ($node['settings'] ?? []), $settings);
        $nv = wpultra_el_validate_node(['elType' => (string) $node['elType'], 'settings' => $merged]);
        if (!$nv['valid']) { return wpultra_err('invalid_settings', 'Container settings failed validation: ' . implode('; ', $nv['errors'])); }
        $settings = $nv['settings'];
    }
    $updated = wpultra_el_merge_settings($data, $eid, $settings, ($input['deep'] ?? false) === true);
    if (is_wp_error($updated)) { return $updated; }
    $w = wpultra_el_write($post_id, $updated);
    if (is_wp_error($w)) { return $w; }
    return wpultra_ok(['post_id' => $post_id, 'element_id' => $eid]);
}
