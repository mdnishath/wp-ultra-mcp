<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/elementor-upsert-global-class', [
    'label'       => __('Elementor: Upsert Global Class', 'wp-ultra-mcp'),
    'description' => __('Create or update an Elementor global CSS class with the given CSS properties.', 'wp-ultra-mcp'),
    'category'    => 'elementor',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'label'  => ['type' => 'string'],
            'props'  => ['type' => 'object'],
            'id'     => ['type' => 'string'],
            'enable' => ['type' => 'boolean'],
        ],
        'required'             => ['label', 'props'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'id'      => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_elementor_upsert_global_class',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_elementor_upsert_global_class(array $input) {
    if (($input['enable'] ?? false) === true && !wpultra_el_classes_active()) {
        $en = wpultra_el_classes_enable();
        if (is_wp_error($en)) { return $en; }
    }
    $label = (string) ($input['label'] ?? '');
    $props = (array) ($input['props'] ?? []);
    if ($props === []) { return wpultra_err('missing_props', 'props (a map of css-prop => {$$type,value}) is required.'); }
    return wpultra_el_gc_upsert($label, $props, isset($input['id']) ? (string) $input['id'] : null);
}
