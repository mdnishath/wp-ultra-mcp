<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/seo-analyze-page', [
    'label'       => __('SEO: Analyze Page', 'wp-ultra-mcp'),
    'description' => __('Score a post\'s on-page SEO (keyword placement, density, meta length, content length, links, image alt) and return a prioritized checklist + recommendations. Optional focus_keyword override.', 'wp-ultra-mcp'),
    'category'    => 'seo',
    'input_schema' => ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer'], 'focus_keyword' => ['type' => 'string']], 'required' => ['post_id'], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean'], 'score' => ['type' => 'integer'], 'checks' => ['type' => 'array'], 'recommendations' => ['type' => 'array']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_seo_analyze_page_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

function wpultra_seo_analyze_page_cb(array $input) {
    $id = (int) ($input['post_id'] ?? 0);
    if (!get_post($id)) { return wpultra_err('post_not_found', "No post with id $id."); }
    $data = wpultra_seo_extract_post($id);
    if (!empty($input['focus_keyword'])) { $data['focus_keyword'] = (string) $input['focus_keyword']; }
    $res = wpultra_seo_score($data);
    return wpultra_ok($res);
}
