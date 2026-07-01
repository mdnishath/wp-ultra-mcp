<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/seo-competitor-analysis', [
    'label'       => __('SEO: Competitor Analysis', 'wp-ultra-mcp'),
    'description' => __('Compare our post against a competitor page. YOU (the AI) fetch the competitor page and pass its on-page data as competitor={title,headings[],word_count,keywords[]} — the server does NOT scrape. Returns missing headings/keywords + word-count delta + recommendations.', 'wp-ultra-mcp'),
    'category'    => 'seo',
    'input_schema' => ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer'], 'competitor' => ['type' => 'object']], 'required' => ['post_id', 'competitor'], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean'], 'missing_headings' => ['type' => 'array'], 'missing_keywords' => ['type' => 'array'], 'word_count_delta' => ['type' => 'integer'], 'recommendations' => ['type' => 'array']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_seo_competitor_analysis_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

function wpultra_seo_competitor_analysis_cb(array $input) {
    $id = (int) ($input['post_id'] ?? 0);
    if (!get_post($id)) { return wpultra_err('post_not_found', "No post with id $id."); }
    $d = wpultra_seo_extract_post($id);
    $ours = [
        'title' => $d['title'] ?? '',
        'headings' => array_filter([$d['h1'] ?? '']),
        'word_count' => str_word_count((string) ($d['body_text'] ?? '')),
        'keywords' => array_filter([$d['focus_keyword'] ?? '']),
    ];
    $theirs = is_array($input['competitor'] ?? null) ? $input['competitor'] : [];
    return wpultra_ok(wpultra_seo_competitor_compare($ours, $theirs));
}
