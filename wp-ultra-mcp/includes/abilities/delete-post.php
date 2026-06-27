<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/delete-post', [
    'label'       => __('Delete Post', 'wp-ultra-mcp'),
    'description' => __('Trash or permanently delete a WordPress post, page, or CPT.', 'wp-ultra-mcp'),
    'category'    => 'content',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'force'   => ['type' => 'boolean'],
        ],
        'required'             => ['post_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'post_id' => ['type' => 'integer'],
            'result'  => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_delete_post',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_delete_post(array $input) {
    $id = (int) ($input['post_id'] ?? $input['id'] ?? 0);
    if ($id <= 0) { return wpultra_err('missing_id', 'post_id is required.'); }
    $p = get_post($id);
    if (!$p) { return wpultra_err('not_found', "No post $id."); }
    $force = ($input['force'] ?? false) === true;
    if ($force || $p->post_status === 'trash') { wp_delete_post($id, true); $result = 'deleted'; }
    else { wp_trash_post($id); $result = 'trashed'; }
    return wpultra_ok(['post_id' => $id, 'result' => $result]);
}
