<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/pagebuilder-get-content', [
    'label'       => __('Page Builders: Get Content', 'wp-ultra-mcp'),
    'description' => __('Read a post\'s builder layout as a compact tree. builder: divi (shortcode tree: {type, attrs, content?, children[]}) | beaver (nested nodes: {node, type, module?, children[]}) | oxygen (component tree, or the raw 3.x shortcode string) — auto-detected when only one is installed.', 'wp-ultra-mcp'),
    'category'    => 'builders',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'builder' => ['type' => 'string', 'enum' => ['divi', 'beaver', 'oxygen']],
        ],
        'required'             => ['post_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'  => ['type' => 'boolean'],
            'builder'  => ['type' => 'string'],
            'post_id'  => ['type' => 'integer'],
            'elements' => ['type' => 'array'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_pagebuilder_get_content_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

/** Shared driver resolution for the pagebuilder-* abilities. @return array{0:string}|WP_Error */
function wpultra_pagebuilder_resolve(array $input) {
    $driver = wpultra_builders_driver((string) ($input['builder'] ?? ''), wpultra_builders_detect());
    if (!in_array($driver, ['divi', 'beaver', 'oxygen'], true)) {
        return wpultra_err('builder_unavailable', (string) $driver);
    }
    return [$driver];
}

function wpultra_pagebuilder_get_content_cb(array $input) {
    $r = wpultra_pagebuilder_resolve($input);
    if (is_wp_error($r)) { return $r; }
    [$driver] = $r;
    $fn = ['divi' => 'wpultra_divi_get', 'beaver' => 'wpultra_bb_get', 'oxygen' => 'wpultra_oxy_get'][$driver];
    $res = $fn((int) $input['post_id']);
    return is_wp_error($res) ? $res : wpultra_ok(['builder' => $driver] + $res);
}
