<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/pagebuilder-set-content', [
    'label'       => __('Page Builders: Set Content', 'wp-ultra-mcp'),
    'description' => __('Write a post\'s builder layout (validated, confirm-gated). Per builder `elements` shape — divi: a tree of {type: "et_pb_*", attrs, content?, children[]} OR a raw shortcode string (both are balance-validated; writes post_content + enables the builder); beaver: a flat node array/map of {node, type: row|column-group|column|module, parent, position, settings{type: <module-slug>, ...}} (ids/parents validated; stored in _fl_builder_data with object settings + cache cleared); oxygen: a component tree {name: "ct_*", options, children[]} (Oxygen 4 JSON; 3.x shortcode writing is refused). Read the current layout first with pagebuilder-get-content.', 'wp-ultra-mcp'),
    'category'    => 'builders',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'post_id'  => ['type' => 'integer'],
            'builder'  => ['type' => 'string', 'enum' => ['divi', 'beaver', 'oxygen']],
            'elements' => ['type' => ['array', 'object', 'string']],
            'confirm'  => ['type' => 'boolean'],
        ],
        'required'             => ['post_id', 'elements'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'builder' => ['type' => 'string'],
            'post_id' => ['type' => 'integer'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_pagebuilder_set_content_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_pagebuilder_set_content_cb(array $input) {
    if (($input['confirm'] ?? false) !== true) {
        return wpultra_err('confirm_required', 'Replacing a builder layout requires confirm: true.');
    }
    $r = wpultra_pagebuilder_resolve($input);
    if (is_wp_error($r)) { return $r; }
    [$driver] = $r;
    $post_id = (int) $input['post_id'];
    $post = get_post($post_id);
    if (!$post) { return wpultra_err('not_found', "No post $post_id."); }
    if (in_array($post->post_type, wpultra_reserved_post_types(), true)) {
        return wpultra_err('reserved_post_type', 'Refusing to write a plugin-internal post.');
    }
    $fn = ['divi' => 'wpultra_divi_set', 'beaver' => 'wpultra_bb_set', 'oxygen' => 'wpultra_oxy_set'][$driver];
    $res = $fn($post_id, $input['elements']);
    wpultra_audit_log('pagebuilder-set-content', "$driver layout -> post $post_id", !is_wp_error($res));
    return is_wp_error($res) ? $res : wpultra_ok(['builder' => $driver] + $res);
}
