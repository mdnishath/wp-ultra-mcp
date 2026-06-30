<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/seo-suggest-internal-links', [
    'label'       => __('SEO: Suggest Internal Links', 'wp-ultra-mcp'),
    'description' => __('Suggest related published posts to link to from a given post (ranked by category/tag/keyword overlap) with anchor-text suggestions.', 'wp-ultra-mcp'),
    'category'    => 'seo',
    'input_schema' => ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer'], 'limit' => ['type' => 'integer']], 'required' => ['post_id'], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean'], 'suggestions' => ['type' => 'array']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_seo_suggest_links_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

function wpultra_seo_suggest_links_cb(array $input) {
    $id = (int) ($input['post_id'] ?? 0);
    if (!get_post($id)) { return wpultra_err('post_not_found', "No post with id $id."); }
    $limit = isset($input['limit']) ? (int) $input['limit'] : 5;
    return wpultra_ok(['suggestions' => wpultra_seo_suggest_links($id, $limit)]);
}
