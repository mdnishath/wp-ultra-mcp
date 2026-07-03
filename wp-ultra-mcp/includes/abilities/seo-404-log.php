<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/seo-404-log', [
    'label'       => __('SEO: 404 Log', 'wp-ultra-mcp'),
    'description' => __('View or clear the recorded 404 hits. action: list (grouped top 404s by hit count, capped by limit) | clear (empty the log). Pair frequent 404s with seo-manage-redirects to add a redirect for them.', 'wp-ultra-mcp'),
    'category'    => 'seo',
    'input_schema' => ['type' => 'object', 'properties' => ['action' => ['type' => 'string', 'enum' => ['list', 'clear']], 'limit' => ['type' => 'integer']], 'required' => ['action'], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean'], 'top' => ['type' => 'array'], 'total_hits' => ['type' => 'integer'], 'hint' => ['type' => 'string']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_seo_404_log_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

function wpultra_seo_404_log_cb(array $input) {
    $action = (string) ($input['action'] ?? 'list');

    if ($action === 'clear') {
        update_option('wpultra_404_log', [], false);
        wpultra_audit_log('seo-404-log', 'clear', true);
        return wpultra_ok(['top' => [], 'total_hits' => 0]);
    }

    $ring = get_option('wpultra_404_log', []);
    if (!is_array($ring)) { $ring = []; }
    $top = wpultra_404_top($ring);
    $limit = (int) ($input['limit'] ?? 20);
    if ($limit > 0) { $top = array_slice($top, 0, $limit); }

    return wpultra_ok([
        'top'        => $top,
        'total_hits' => count($ring),
        'hint'       => 'Use wpultra/seo-manage-redirects (action: add) to redirect a recurring 404 path to a valid URL.',
    ]);
}
