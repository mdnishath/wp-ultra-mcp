<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/seo-site-audit', [
    'label'       => __('SEO: Site Audit', 'wp-ultra-mcp'),
    'description' => __('Scan published posts/pages for on-page SEO issues (missing/too-long titles + descriptions, missing focus keyword, thin content, missing image alt, noindex), duplicate titles, and orphan pages. limit caps how many posts are scanned.', 'wp-ultra-mcp'),
    'category'    => 'seo',
    'input_schema' => ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer']], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean'], 'scanned' => ['type' => 'integer'], 'issue_counts' => ['type' => 'object'], 'duplicate_titles' => ['type' => 'array'], 'orphans' => ['type' => 'array'], 'posts' => ['type' => 'array']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_seo_site_audit_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

function wpultra_seo_site_audit_cb(array $input) {
    $limit = isset($input['limit']) ? (int) $input['limit'] : 200;
    return wpultra_ok(wpultra_seo_site_audit($limit));
}
