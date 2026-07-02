<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/media-update', [
    'label'       => __('Update Media', 'wp-ultra-mcp'),
    'description' => __('Update a Media Library attachment\'s `alt`/`title`/`caption`/`description`, and optionally reattach it to a different post via `attach_to_post` (0 detaches).', 'wp-ultra-mcp'),
    'category'    => 'content',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'id'             => ['type' => 'integer'],
            'alt'            => ['type' => 'string'],
            'title'          => ['type' => 'string'],
            'caption'        => ['type' => 'string'],
            'description'    => ['type' => 'string'],
            'attach_to_post' => ['type' => 'integer'],
        ],
        'required'             => ['id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'id'      => ['type' => 'integer'],
            'url'     => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_media_update_ability',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_media_update_ability(array $input) {
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) { return wpultra_err('missing_id', 'id is required.'); }
    $meta = array_intersect_key($input, array_flip(['alt', 'title', 'caption', 'description', 'attach_to_post']));
    $res = wpultra_media_update($id, $meta);
    if (is_wp_error($res)) { return $res; }
    wpultra_audit_log('media-update', "id=$id", true);
    return wpultra_ok($res);
}
