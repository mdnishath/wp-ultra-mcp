<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/seo-insert-internal-link', [
    'label'       => __('SEO: Insert Internal Link', 'wp-ultra-mcp'),
    'description' => __('Insert a contextual internal link into a post by wrapping the first unlinked occurrence of the anchor text in a link to the target URL. Returns inserted:false if the anchor is not found.', 'wp-ultra-mcp'),
    'category'    => 'seo',
    'input_schema' => ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer'], 'anchor' => ['type' => 'string'], 'target_url' => ['type' => 'string']], 'required' => ['post_id', 'anchor', 'target_url'], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean'], 'inserted' => ['type' => 'boolean']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_seo_insert_link_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

function wpultra_seo_insert_link_cb(array $input) {
    $res = wpultra_seo_insert_link((int) ($input['post_id'] ?? 0), (string) ($input['anchor'] ?? ''), (string) ($input['target_url'] ?? ''));
    wpultra_audit_log('seo-insert-internal-link', is_wp_error($res) ? 'failed' : ('post ' . $res['post_id'] . ' inserted=' . ($res['inserted'] ? '1' : '0')), !is_wp_error($res));
    if (is_wp_error($res)) { return $res; }
    return wpultra_ok(['inserted' => $res['inserted'], 'anchor' => $res['anchor']]);
}
