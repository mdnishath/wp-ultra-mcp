<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

if (!function_exists('wpultra_el_variant_meta')) {
    require_once WPULTRA_DIR . 'includes/elementor/variants.php';
}

wp_register_ability('wpultra/elementor-style-variant', [
    'label'       => __('Elementor: Style Variant', 'wp-ultra-mcp'),
    'description' => __('Create or update a device-specific and/or hover/focus/active variant of an Elementor global CSS class. Pass breakpoint (desktop|tablet|mobile|<active custom breakpoint id>) and/or state (normal|hover|focus|active) to target that variant; desktop+normal is the base variant. Existing variants are merged in (matched by breakpoint+state) — other variants, especially the base, are never disturbed. Omit class_id to create a new class with this variant. Pass remove:true to delete a matching non-base variant (the base cannot be removed this way — update its props instead).', 'wp-ultra-mcp'),
    'category'    => 'elementor',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'class_id'   => ['type' => 'string'],
            'label'      => ['type' => 'string'],
            'breakpoint' => ['type' => 'string', 'default' => 'desktop'],
            'state'      => ['type' => 'string', 'default' => 'normal'],
            'props'      => ['type' => 'object'],
            'enable'     => ['type' => 'boolean'],
            'remove'     => ['type' => 'boolean'],
        ],
        'required'             => ['props'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'  => ['type' => 'boolean'],
            'id'       => ['type' => 'string'],
            'label'    => ['type' => 'string'],
            'variants' => ['type' => 'array'],
            'note'     => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_elementor_style_variant_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

/** @return array|WP_Error */
function wpultra_elementor_style_variant_cb(array $input) {
    if (($input['enable'] ?? false) === true && !wpultra_el_classes_active()) {
        $en = wpultra_el_classes_enable();
        if (is_wp_error($en)) { return $en; }
        // Elementor resolves the experiment state once at boot, so the flip only takes effect on the
        // NEXT request. Tell the caller to re-run rather than returning a misleading classes_inactive.
        if (!wpultra_el_classes_active()) {
            return wpultra_err('classes_enabling', 'The Elementor "e_classes" experiment has just been enabled for you — re-run this action (Elementor applies the experiment change on the next request).');
        }
    }

    $remove = ($input['remove'] ?? false) === true;
    $props = (array) ($input['props'] ?? []);
    if (!$remove && $props === []) {
        return wpultra_err('missing_props', 'props (a map of css-prop => {$$type,value}) is required unless remove is true.');
    }

    $breakpoint_friendly = (string) ($input['breakpoint'] ?? 'desktop');
    $state_friendly = (string) ($input['state'] ?? 'normal');
    $active_keys = wpultra_el_active_breakpoint_keys();

    $meta = wpultra_el_variant_meta($breakpoint_friendly, $state_friendly, $active_keys);
    if (is_wp_error($meta)) { return $meta; }

    $id = (isset($input['class_id']) && (string) $input['class_id'] !== '') ? (string) $input['class_id'] : null;
    $label = (string) ($input['label'] ?? '');

    return wpultra_el_variant_upsert($id, $label, $meta, $props, $remove);
}
