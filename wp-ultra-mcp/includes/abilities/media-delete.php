<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/media-delete', [
    'label'       => __('Delete Media', 'wp-ultra-mcp'),
    'description' => __('Delete a Media Library attachment by `id`. `force` (default false) bypasses trash. Requires `confirm: true`.', 'wp-ultra-mcp'),
    'category'    => 'content',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'id'      => ['type' => 'integer'],
            'force'   => ['type' => 'boolean', 'default' => false],
            'confirm' => ['type' => 'boolean'],
        ],
        'required'             => ['id', 'confirm'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'id'      => ['type' => 'integer'],
            'deleted' => ['type' => 'boolean'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_media_delete_ability',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => true],
    ],
]);

function wpultra_media_delete_ability(array $input) {
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) { return wpultra_err('missing_id', 'id is required.'); }
    if (($input['confirm'] ?? false) !== true) {
        return wpultra_err('confirm_required', 'Deleting media requires confirm: true.');
    }
    $force = ($input['force'] ?? false) === true;
    $res = wpultra_media_delete($id, $force);
    if (is_wp_error($res)) { wpultra_audit_log('media-delete', "id=$id", false); return $res; }
    wpultra_audit_log('media-delete', "id=$id force=" . ($force ? '1' : '0'), true);
    return wpultra_ok($res);
}
