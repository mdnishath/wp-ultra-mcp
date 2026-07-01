<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/seo-link-audit', [
    'label'       => __('SEO: Link Audit', 'wp-ultra-mcp'),
    'description' => __('Audit the internal-link graph across published posts/pages: orphan pages (no incoming internal links) and broken internal links. limit caps how many posts are scanned.', 'wp-ultra-mcp'),
    'category'    => 'seo',
    'input_schema' => ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer']], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean'], 'orphans' => ['type' => 'array'], 'broken' => ['type' => 'array'], 'counts' => ['type' => 'object']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_seo_link_audit_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

function wpultra_seo_link_audit_cb(array $input) {
    $limit = isset($input['limit']) ? (int) $input['limit'] : 200;
    return wpultra_ok(wpultra_seo_link_audit($limit));
}
