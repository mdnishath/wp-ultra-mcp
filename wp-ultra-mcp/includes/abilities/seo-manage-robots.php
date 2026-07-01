<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/seo-manage-robots', [
    'label'       => __('SEO: Manage Robots', 'wp-ultra-mcp'),
    'description' => __('Read or set custom robots.txt rules (appended via the robots_txt filter; no physical file written). action: get|set. rules = array of directive lines; replace=true overwrites, else appends.', 'wp-ultra-mcp'),
    'category'    => 'seo',
    'input_schema' => ['type' => 'object', 'properties' => ['action' => ['type' => 'string', 'enum' => ['get', 'set']], 'rules' => ['type' => 'array'], 'replace' => ['type' => 'boolean']], 'required' => ['action'], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean'], 'rules' => ['type' => 'array']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_seo_manage_robots_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

function wpultra_seo_manage_robots_cb(array $input) {
    $action = (string) ($input['action'] ?? 'get');
    if ($action === 'set') {
        $rules = is_array($input['rules'] ?? null) ? $input['rules'] : [];
        $res = wpultra_seo_set_robots($rules, !empty($input['replace']));
        wpultra_audit_log('seo-manage-robots', 'set ' . count($res['rules']) . ' rules', true);
    } else { $res = wpultra_seo_get_robots(); }
    return wpultra_ok(['rules' => $res['rules']]);
}
