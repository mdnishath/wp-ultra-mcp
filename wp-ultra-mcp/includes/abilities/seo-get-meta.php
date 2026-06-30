<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/seo-get-meta', [
    'label'       => __('SEO: Get Meta', 'wp-ultra-mcp'),
    'description' => __('Get a post\'s SEO meta (title, description, focus_keyword, canonical, robots, OG/Twitter) — mode-aware (Yoast/Rank Math/native).', 'wp-ultra-mcp'),
    'category'    => 'seo',
    'input_schema' => ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer']], 'required' => ['post_id'], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean'], 'meta' => ['type' => 'object']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_seo_get_meta_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

function wpultra_seo_get_meta_cb(array $input) {
    $id = (int) ($input['post_id'] ?? 0);
    if (!get_post($id)) { return wpultra_err('post_not_found', "No post with id $id."); }
    return wpultra_ok(['meta' => wpultra_seo_get_meta($id)]);
}
