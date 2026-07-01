<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/seo-manage-redirects', [
    'label'       => __('SEO: Manage Redirects', 'wp-ultra-mcp'),
    'description' => __('Manage a redirect map applied on the front end. action: list|add|delete. add needs source (path), target (URL), type (301|302). delete needs source.', 'wp-ultra-mcp'),
    'category'    => 'seo',
    'input_schema' => ['type' => 'object', 'properties' => ['action' => ['type' => 'string', 'enum' => ['list', 'add', 'delete']], 'source' => ['type' => 'string'], 'target' => ['type' => 'string'], 'type' => ['type' => 'integer']], 'required' => ['action'], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean'], 'redirects' => ['type' => 'array']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_seo_manage_redirects_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

function wpultra_seo_manage_redirects_cb(array $input) {
    $action = (string) ($input['action'] ?? 'list');
    if ($action === 'add') {
        $src = (string) ($input['source'] ?? '');
        if ($src === '' || empty($input['target'])) { return wpultra_err('missing_fields', 'add requires source + target.'); }
        $res = wpultra_seo_add_redirect($src, (string) $input['target'], (int) ($input['type'] ?? 301));
        wpultra_audit_log('seo-manage-redirects', "add $src", true);
    } elseif ($action === 'delete') {
        $res = wpultra_seo_delete_redirect((string) ($input['source'] ?? ''));
        wpultra_audit_log('seo-manage-redirects', 'delete ' . (string) ($input['source'] ?? ''), true);
    } else { $res = wpultra_seo_redirects(); }
    return wpultra_ok(['redirects' => $res['redirects']]);
}
