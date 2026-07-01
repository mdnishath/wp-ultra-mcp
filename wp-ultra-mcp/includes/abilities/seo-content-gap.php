<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/seo-content-gap', [
    'label'       => __('SEO: Content Gap', 'wp-ultra-mcp'),
    'description' => __('Given a list of target topics/keywords, list which have NO dedicated page on the site (content gaps) vs which are already covered. Heuristic (title/focus-keyword match); no external data.', 'wp-ultra-mcp'),
    'category'    => 'seo',
    'input_schema' => ['type' => 'object', 'properties' => ['topics' => ['type' => 'array'], 'limit' => ['type' => 'integer']], 'required' => ['topics'], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean'], 'covered' => ['type' => 'array'], 'gaps' => ['type' => 'array']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_seo_content_gap_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

function wpultra_seo_content_gap_cb(array $input) {
    $topics = is_array($input['topics'] ?? null) ? $input['topics'] : [];
    $limit = isset($input['limit']) ? (int) $input['limit'] : 200;
    $res = wpultra_seo_keyword_gaps($topics, wpultra_seo_site_index($limit));
    return wpultra_ok(['covered' => $res['covered'], 'gaps' => $res['gaps']]);
}
