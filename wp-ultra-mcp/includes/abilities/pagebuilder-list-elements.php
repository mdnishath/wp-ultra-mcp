<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/pagebuilder-list-elements', [
    'label'       => __('Page Builders: List Elements', 'wp-ultra-mcp'),
    'description' => __('List the element/module/component types available in the active builder — Divi modules (et_pb_*), Beaver Builder modules (rich-text, photo, ...), or Oxygen components (ct_*). Use these names in pagebuilder-set-content trees.', 'wp-ultra-mcp'),
    'category'    => 'builders',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => ['builder' => ['type' => 'string', 'enum' => ['divi', 'beaver', 'oxygen']]],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'  => ['type' => 'boolean'],
            'builder'  => ['type' => 'string'],
            'elements' => ['type' => 'array'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_pagebuilder_list_elements_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_pagebuilder_list_elements_cb(array $input) {
    $r = wpultra_pagebuilder_resolve($input);
    if (is_wp_error($r)) { return $r; }
    [$driver] = $r;
    $fn = ['divi' => 'wpultra_divi_elements', 'beaver' => 'wpultra_bb_elements', 'oxygen' => 'wpultra_oxy_elements'][$driver];
    return wpultra_ok(['builder' => $driver, 'elements' => $fn()]);
}
