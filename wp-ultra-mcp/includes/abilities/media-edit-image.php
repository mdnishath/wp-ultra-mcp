<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/media-edit-image', [
    'label'       => __('Edit Image', 'wp-ultra-mcp'),
    'description' => __('Apply resize/crop/rotate/flip/quality/convert operations (in order) to a Media Library image via WP_Image_Editor. `operations` is an ordered array of `{op, ...params}`: `resize` {width?, height?, crop?}, `crop` {x, y, width, height}, `rotate` {degrees}, `flip` {horizontal?, vertical?}, `quality` {value 1-100}, `convert` {format: jpeg|png|webp}. By default (`overwrite: false`) saves the result as a NEW attachment (suffixed `-edited`/`-edited-N`) and leaves the original untouched; `overwrite: true` replaces the original file in place and requires `confirm: true`.', 'wp-ultra-mcp'),
    'category'    => 'content',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'id'         => ['type' => 'integer'],
            'operations' => [
                'type'  => 'array',
                'items' => [
                    'type'       => 'object',
                    'properties' => [
                        'op'         => ['type' => 'string', 'enum' => ['resize', 'crop', 'rotate', 'flip', 'quality', 'convert']],
                        'width'      => ['type' => 'integer'],
                        'height'     => ['type' => 'integer'],
                        'crop'       => ['type' => 'boolean'],
                        'x'          => ['type' => 'integer'],
                        'y'          => ['type' => 'integer'],
                        'degrees'    => ['type' => 'number'],
                        'horizontal' => ['type' => 'boolean'],
                        'vertical'   => ['type' => 'boolean'],
                        'value'      => ['type' => 'integer'],
                        'format'     => ['type' => 'string', 'enum' => ['jpeg', 'png', 'webp']],
                    ],
                    'required'             => ['op'],
                    'additionalProperties' => false,
                ],
            ],
            'overwrite' => ['type' => 'boolean', 'default' => false],
            'confirm'   => ['type' => 'boolean'],
        ],
        'required'             => ['id', 'operations'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'    => ['type' => 'boolean'],
            'id'         => ['type' => 'integer'],
            'url'        => ['type' => 'string'],
            'operations' => ['type' => 'array'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_media_edit_image_ability',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_media_edit_image_ability(array $input) {
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) { return wpultra_err('missing_id', 'id is required.'); }

    $ops = $input['operations'] ?? null;
    if (!is_array($ops)) { return wpultra_err('missing_operations', 'operations is required.'); }

    $valid = wpultra_media_edit_validate_ops($ops);
    if ($valid !== true) { return wpultra_err('bad_ops', $valid); }

    $overwrite = ($input['overwrite'] ?? false) === true;
    if ($overwrite && ($input['confirm'] ?? false) !== true) {
        return wpultra_err('confirm_required', 'overwrite: true requires confirm: true.');
    }

    $res = wpultra_media_edit_apply($id, $ops, $overwrite);
    if (is_wp_error($res)) {
        wpultra_audit_log('media-edit-image', "id=$id overwrite=" . ($overwrite ? '1' : '0'), false);
        return $res;
    }

    $res['operations'] = $ops;
    wpultra_audit_log('media-edit-image', "id=$id overwrite=" . ($overwrite ? '1' : '0') . ' ops=' . count($ops), true);
    return wpultra_ok($res);
}
