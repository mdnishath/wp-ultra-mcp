<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/seo-keyword-research', [
    'label'       => __('SEO: Keyword Research', 'wp-ultra-mcp'),
    'description' => __('Given candidate keywords (you, the AI, propose them — there is NO search-volume data), report which the site already targets vs content gaps. If no candidates are given, returns the site\'s current focus keywords as a starting point.', 'wp-ultra-mcp'),
    'category'    => 'seo',
    'input_schema' => ['type' => 'object', 'properties' => ['candidate_keywords' => ['type' => 'array'], 'limit' => ['type' => 'integer']], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean'], 'covered' => ['type' => 'array'], 'gaps' => ['type' => 'array'], 'existing_focus_keywords' => ['type' => 'array']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_seo_keyword_research_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

function wpultra_seo_keyword_research_cb(array $input) {
    $limit = isset($input['limit']) ? (int) $input['limit'] : 200;
    $index = wpultra_seo_site_index($limit);
    $existing = [];
    foreach ($index as $row) { if (!empty($row['focus_keyword'])) { $existing[] = $row['focus_keyword']; } }
    $cands = isset($input['candidate_keywords']) && is_array($input['candidate_keywords']) ? $input['candidate_keywords'] : [];
    $res = wpultra_seo_keyword_gaps($cands, $index);
    return wpultra_ok(['covered' => $res['covered'], 'gaps' => $res['gaps'], 'existing_focus_keywords' => array_values(array_unique($existing))]);
}
