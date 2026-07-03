<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/bricks-delete-element', [
    'label'       => __('Bricks: Delete Element', 'wp-ultra-mcp'),
    'description' => __('Remove an element AND its whole subtree from a Bricks page (parent\'s children list is fixed up). Requires confirm: true.', 'wp-ultra-mcp'),
    'category'    => 'bricks',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'post_id'    => ['type' => 'integer'],
            'element_id' => ['type' => 'string'],
            'confirm'    => ['type' => 'boolean'],
        ],
        'required'             => ['post_id', 'element_id', 'confirm'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'  => ['type' => 'boolean'],
            'count'    => ['type' => 'integer'],
            'elements' => ['type' => 'array'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_bricks_delete_element_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => true],
    ],
]);

function wpultra_bricks_delete_element_cb(array $input) {
    if (($input['confirm'] ?? false) !== true) {
        return wpultra_err('confirm_required', 'Deleting an element subtree requires confirm: true.');
    }
    $post_id = (int) $input['post_id'];
    $res = wpultra_bricks_mutate($post_id, fn(array $elements) => wpultra_bricks_op_delete($elements, (string) $input['element_id']));
    if (is_wp_error($res)) { return $res; }
    wpultra_audit_log('bricks-delete-element', "post $post_id -= {$input['element_id']} (+subtree)", true);
    return wpultra_ok($res);
}
