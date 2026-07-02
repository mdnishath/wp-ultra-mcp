<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/media-get', [
    'label'       => __('Get Media', 'wp-ultra-mcp'),
    'description' => __('Get a single Media Library attachment by `id`: url/title/mime/alt plus width/height, filesize, caption, description, and attached_to post id.', 'wp-ultra-mcp'),
    'category'    => 'content',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'id' => ['type' => 'integer'],
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
    'execute_callback'    => 'wpultra_media_get_ability',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_media_get_ability(array $input) {
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) { return wpultra_err('missing_id', 'id is required.'); }
    if (get_post_type($id) !== 'attachment') { return wpultra_err('not_found', "No attachment with id $id."); }
    return wpultra_ok(wpultra_media_shape_detailed($id));
}
