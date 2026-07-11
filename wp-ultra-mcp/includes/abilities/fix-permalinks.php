<?php
declare(strict_types=1);

if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/fix-permalinks', [
    'label'       => __('Fix Permalinks', 'wp-ultra-mcp'),
    'description' => __('Fix the classic "every page is 404" breakage: flush rewrite rules, regenerate WordPress\'s own .htaccess "# BEGIN WordPress" block, and verify with a single HTTP probe. actions: `status` (read-only, default) reports the current permalink_structure, whether pretty permalinks are on, rewrite_rules presence/count, server type (apache/nginx/other), .htaccess path/exists/writable/has-wp-block, and a home/siteurl mismatch flag. `fix` (requires confirm:true) optionally sets a new `structure` (must start with / and contain a recognized tag, e.g. /%postname%/), hard-flushes rewrite rules (which regenerates .htaccess via core\'s own save_mod_rewrite_rules() — never hand-written), then probes one published post once to confirm it resolves. On nginx the .htaccess step is reported as skipped_nginx (no .htaccess in use); if .htaccess exists but is not writable, the DB rules are still flushed and the step is reported as skipped_unwritable along with the rule block text to add manually. Never touches the separate "# BEGIN WPUltra" block owned by manage-server-rules.', 'wp-ultra-mcp'),
    'category'    => 'diagnostics',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action'    => ['type' => 'string', 'enum' => ['status', 'fix'], 'default' => 'status'],
            'structure' => ['type' => 'string'],
            'confirm'   => ['type' => 'boolean'],
        ],
        'required'             => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'      => ['type' => 'boolean'],
            'action'       => ['type' => 'string'],
            'status'       => ['type' => 'object'],
            'flushed'      => ['type' => 'boolean'],
            'structure'    => ['type' => 'string'],
            'htaccess'     => ['type' => 'string'],
            'manual_rules' => ['type' => 'string'],
            'verify'       => ['type' => 'object'],
            'note'         => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_fix_permalinks_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
    ],
]);

/** @return array|WP_Error */
function wpultra_fix_permalinks_cb(array $input) {
    $action = (string) ($input['action'] ?? 'status');

    switch ($action) {
        case 'status':
            $res = ['action' => 'status', 'status' => wpultra_permalinks_get_status()];
            break;
        case 'fix':
            $fix = wpultra_permalinks_fix($input);
            if (is_wp_error($fix)) {
                wpultra_audit_log('fix-permalinks', 'fix failed: ' . $fix->get_error_message(), false);
                return $fix;
            }
            $res = array_merge(['action' => 'fix'], $fix);
            wpultra_audit_log('fix-permalinks', 'fix applied: htaccess=' . ($res['htaccess'] ?? '?'), true);
            break;
        default:
            return wpultra_err('bad_action', "Unknown action '$action'. Use status or fix.");
    }

    return wpultra_ok($res);
}
