<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/jetengine-status', [
    'label'       => __('JetEngine: Status', 'wp-ultra-mcp'),
    'description' => __('Full JetEngine inventory in one call: version, active modules, custom post types (with field lists; status "built-in" rows add meta to core types), taxonomies, meta boxes, relations, and listing items. The orientation call before jetengine-manage-cpt / -taxonomy / -meta-box.', 'wp-ultra-mcp'),
    'category'    => 'jetengine',
    'input_schema'  => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'    => ['type' => 'boolean'],
            'active'     => ['type' => 'boolean'],
            'version'    => ['type' => 'string'],
            'modules'    => ['type' => 'array'],
            'post_types' => ['type' => 'array'],
            'taxonomies' => ['type' => 'array'],
            'meta_boxes' => ['type' => 'array'],
            'relations'  => ['type' => 'array'],
            'listings'   => ['type' => 'array'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_jetengine_status_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_jetengine_status_cb(array $input) {
    if (!wpultra_je_active()) {
        return wpultra_ok(['active' => false, 'version' => '', 'modules' => [], 'post_types' => [], 'taxonomies' => [], 'meta_boxes' => [], 'relations' => [], 'listings' => []]);
    }
    $modules = [];
    try {
        if (function_exists('jet_engine') && isset(jet_engine()->modules) && method_exists(jet_engine()->modules, 'get_active_modules')) {
            $modules = array_values((array) jet_engine()->modules->get_active_modules());
        }
    } catch (\Throwable $e) {}
    $boxes = array_map(static fn($b) => [
        'id'     => (string) ($b['id'] ?? ''),
        'title'  => (string) ($b['args']['name'] ?? $b['args']['title'] ?? ''),
        'fields' => array_values(array_map(static fn($f) => (string) ($f['name'] ?? ''), (array) ($b['meta_fields'] ?? []))),
    ], wpultra_je_meta_boxes());
    return wpultra_ok([
        'active'     => true,
        'version'    => wpultra_je_version(),
        'modules'    => $modules,
        'post_types' => array_map(static fn($r) => wpultra_je_shape_row($r), wpultra_je_rows('cpt')),
        'taxonomies' => array_map(static fn($r) => wpultra_je_shape_row($r), wpultra_je_rows('tax')),
        'meta_boxes' => $boxes,
        'relations'  => wpultra_je_relations(),
        'listings'   => wpultra_je_listings(),
    ]);
}
