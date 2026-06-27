<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/update-post', [
    'label'       => __('Update Post', 'wp-ultra-mcp'),
    'description' => __('Update fields on an existing WordPress post, page, or CPT.', 'wp-ultra-mcp'),
    'category'    => 'content',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'post_id'          => ['type' => 'integer'],
            'title'            => ['type' => 'string'],
            'content'          => ['type' => 'string'],
            'status'           => ['type' => 'string'],
            'excerpt'          => ['type' => 'string'],
            'slug'             => ['type' => 'string'],
            'menu_order'       => ['type' => 'integer'],
            'featured_image_id' => ['type' => 'integer'],
            'meta'             => ['type' => 'object'],
            'terms'            => ['type' => 'object'],
        ],
        'required'             => ['post_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'        => ['type' => 'boolean'],
            'post_id'        => ['type' => 'integer'],
            'updated_fields' => ['type' => 'array'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_update_post',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_update_post(array $input) {
    $id = (int) ($input['post_id'] ?? $input['id'] ?? 0);
    if ($id <= 0 || !get_post($id)) { return wpultra_err('not_found', 'Valid post_id is required.'); }
    $postarr = ['ID' => $id]; $updated = [];
    $map = ['title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt', 'status' => 'post_status'];
    foreach ($map as $in => $col) { if (array_key_exists($in, $input)) { $postarr[$col] = (string) $input[$in]; $updated[] = $in; } }
    if (array_key_exists('slug', $input)) { $postarr['post_name'] = sanitize_title((string) $input['slug']); $updated[] = 'slug'; }
    if (array_key_exists('menu_order', $input)) { $postarr['menu_order'] = (int) $input['menu_order']; $updated[] = 'menu_order'; }
    if (count($postarr) > 1) { $res = wp_update_post($postarr, true); if (is_wp_error($res)) { return $res; } }
    if (!empty($input['meta']) && is_array($input['meta'])) {
        foreach ($input['meta'] as $k => $v) { update_post_meta($id, (string) $k, $v); }
        $updated[] = 'meta';
    }
    if (!empty($input['terms']) && is_array($input['terms'])) {
        foreach ($input['terms'] as $tax => $terms) { wp_set_post_terms($id, (array) $terms, (string) $tax); }
        $updated[] = 'terms';
    }
    if (array_key_exists('featured_image_id', $input)) {
        $fid = (int) $input['featured_image_id'];
        if ($fid === 0) { delete_post_thumbnail($id); } else { set_post_thumbnail($id, $fid); }
        $updated[] = 'featured_image';
    }
    return wpultra_ok(['post_id' => $id, 'updated_fields' => $updated]);
}
