<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/plugin-checksum-verify', [
    'label'       => __('Plugin Checksum Verify', 'wp-ultra-mcp'),
    'description' => __('Verify installed plugin files against the wp.org plugin-repo checksum API (the plugin analogue of the core-checksums scan). action=list (default) lists installed plugins with slug + version + whether verification is possible. action=verify checks one plugin (input "plugin": folder slug) or every plugin ("all": true): for each, fetches the wp.org manifest for its installed version and md5-compares every listed file, reporting modified/missing/unknown files. Plugins not hosted on wp.org (premium/custom) report status "not_on_wporg" — this is informational, not a failure. A network problem reports "check_failed" (inconclusive). Read-only; never modifies anything.', 'wp-ultra-mcp'),
    'category'    => 'diagnostics',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action' => ['type' => 'string', 'enum' => ['list', 'verify'], 'default' => 'list'],
            'plugin' => ['type' => 'string', 'description' => 'Plugin folder slug to verify (action=verify only), e.g. "akismet".'],
            'all'    => ['type' => 'boolean', 'description' => 'Verify every installed plugin (action=verify only).'],
        ],
        'required'             => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'plugins' => ['type' => 'array'],
            'count'   => ['type' => 'integer'],
            'results' => ['type' => 'array'],
            'capped'  => ['type' => 'boolean'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_plugin_checksum_verify_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

/** @return array|WP_Error */
function wpultra_plugin_checksum_verify_cb(array $input) {
    $action = isset($input['action']) ? (string) $input['action'] : 'list';
    if (!in_array($action, ['list', 'verify'], true)) {
        return wpultra_err('bad_action', "Unknown action '$action'. Known actions: list, verify.");
    }

    if ($action === 'list') {
        return wpultra_ok(wpultra_pluginck_list_installed());
    }

    $data = wpultra_pluginck_verify($input);
    if (is_wp_error($data)) { return $data; }

    wpultra_audit_log('plugin-checksum-verify', 'checked ' . $data['count'] . ' plugin(s)', true);

    return wpultra_ok($data);
}
