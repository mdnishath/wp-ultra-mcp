<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/media-upload', [
    'label'       => __('Media Upload & Manage', 'wp-ultra-mcp'),
    'description' => __('Add and manage Media Library attachments. actions: `upload` (from `url` to sideload, or `data_base64` + `filename`), `get`, `update` (alt/title/caption/description), `delete`. Optional `attach_to_post`, `alt`, `title`, `caption`. Returns id + url.', 'wp-ultra-mcp'),
    'category'    => 'content',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action'         => ['type' => 'string', 'enum' => ['upload', 'get', 'update', 'delete'], 'default' => 'upload'],
            'url'            => ['type' => 'string'],
            'data_base64'    => ['type' => 'string'],
            'filename'       => ['type' => 'string'],
            'id'             => ['type' => 'integer'],
            'attach_to_post' => ['type' => 'integer'],
            'alt'            => ['type' => 'string'],
            'title'          => ['type' => 'string'],
            'caption'        => ['type' => 'string'],
            'description'    => ['type' => 'string'],
            'force'          => ['type' => 'boolean'],
        ],
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
    'execute_callback'    => 'wpultra_media_upload',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_media_upload(array $input) {
    $action = (string) ($input['action'] ?? 'upload');
    $meta = array_intersect_key($input, array_flip(['filename', 'attach_to_post', 'alt', 'title', 'caption', 'description']));

    switch ($action) {
        case 'upload':
            if (!empty($input['url'])) {
                $res = wpultra_media_sideload_url((string) $input['url'], $meta);
            } elseif (!empty($input['data_base64'])) {
                $res = wpultra_media_from_base64((string) $input['data_base64'], $meta);
            } else {
                return wpultra_err('missing_source', 'upload requires either url or data_base64.');
            }
            break;
        case 'get':
            $res = wpultra_media_get((int) ($input['id'] ?? 0));
            break;
        case 'update':
            $res = wpultra_media_update((int) ($input['id'] ?? 0), $meta);
            break;
        case 'delete':
            $res = wpultra_media_delete((int) ($input['id'] ?? 0), ($input['force'] ?? true) === true);
            break;
        default:
            return wpultra_err('bad_action', "Unknown action '$action'.");
    }

    if (is_wp_error($res)) { return $res; }
    wpultra_audit_log('media-upload', "$action id=" . (string) ($res['id'] ?? '?'), true);
    return wpultra_ok($res);
}
