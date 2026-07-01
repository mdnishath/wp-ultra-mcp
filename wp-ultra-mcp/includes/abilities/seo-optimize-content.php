<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/seo-optimize-content', [
    'label'       => __('SEO: Optimize Content', 'wp-ultra-mcp'),
    'description' => __('Score a post for a target keyword and return a prioritized content improvement plan (the failing/warning on-page checks as actionable steps). Optional focus_keyword override. Advisory — does not rewrite content.', 'wp-ultra-mcp'),
    'category'    => 'seo',
    'input_schema' => ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer'], 'focus_keyword' => ['type' => 'string']], 'required' => ['post_id'], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean'], 'score' => ['type' => 'integer'], 'improvements' => ['type' => 'array']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_seo_optimize_content_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

function wpultra_seo_optimize_content_cb(array $input) {
    $id = (int) ($input['post_id'] ?? 0);
    if (!get_post($id)) { return wpultra_err('post_not_found', "No post with id $id."); }
    $data = wpultra_seo_extract_post($id);
    if (!empty($input['focus_keyword'])) { $data['focus_keyword'] = (string) $input['focus_keyword']; }
    $scored = wpultra_seo_score($data);
    $improvements = [];
    foreach ($scored['checks'] as $c) {
        if ($c['status'] !== 'pass') { $improvements[] = ['priority' => ($c['status'] === 'fail' ? 'high' : 'medium'), 'check' => $c['id'], 'action' => $c['message']]; }
    }
    return wpultra_ok(['score' => $scored['score'], 'improvements' => $improvements]);
}
