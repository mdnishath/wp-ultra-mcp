<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/seo-manage-sitemap', [
    'label'       => __('SEO: Manage Sitemap', 'wp-ultra-mcp'),
    'description' => __('Read the active sitemap (provider + URL + enabled), or enable/disable the WP-core sitemap. action: get|enable|disable.', 'wp-ultra-mcp'),
    'category'    => 'seo',
    'input_schema' => ['type' => 'object', 'properties' => ['action' => ['type' => 'string', 'enum' => ['get', 'enable', 'disable']]], 'required' => ['action'], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean'], 'sitemap' => ['type' => 'object']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_seo_manage_sitemap_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

function wpultra_seo_manage_sitemap_cb(array $input) {
    $action = (string) ($input['action'] ?? 'get');
    if ($action === 'enable') { $s = wpultra_seo_set_sitemap(true); }
    elseif ($action === 'disable') { $s = wpultra_seo_set_sitemap(false); }
    else { $s = wpultra_seo_sitemap_state(); }
    if ($action !== 'get') { wpultra_audit_log('seo-manage-sitemap', $action, true); }
    return wpultra_ok(['sitemap' => $s]);
}
