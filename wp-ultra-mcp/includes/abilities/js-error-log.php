<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/js-error-log', [
    'label'       => __('JS Error Log', 'wp-ultra-mcp'),
    'description' => __('Front-end JavaScript error capture: a lightweight beacon logs window.onerror + unhandledrejection events into a capped ring buffer (max 50), so the AI can see client-side breakage without a headless browser. Action "status" reports whether capture is enabled + current ring size; "read" returns recent entries (filter by limit); "clear" empties the ring (confirm required); "enable"/"disable" toggle front-end capture (confirm required).', 'wp-ultra-mcp'),
    'category'    => 'diagnostics',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['status', 'read', 'clear', 'enable', 'disable']],
            'limit'   => ['type' => 'integer'],
            'confirm' => ['type' => 'boolean'],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'action'  => ['type' => 'string'],
            'enabled' => ['type' => 'boolean'],
            'entries' => ['type' => 'array'],
            'count'   => ['type' => 'integer'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_js_error_log_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
    ],
]);

/** @return array|WP_Error */
function wpultra_js_error_log_cb(array $input) {
    $action = (string) ($input['action'] ?? '');

    if ($action === 'status') {
        $ring = wpultra_jserrors_load_ring();
        return wpultra_ok(['action' => 'status', 'enabled' => wpultra_jserrors_is_enabled(), 'count' => count($ring)]);
    }

    if ($action === 'read') {
        $filters = [];
        if (isset($input['limit'])) { $filters['limit'] = (int) $input['limit']; }
        $entries = wpultra_jserrors_read($filters);
        return wpultra_ok(['action' => 'read', 'entries' => $entries, 'count' => count($entries)]);
    }

    if (in_array($action, ['clear', 'enable', 'disable'], true)) {
        $confirm = ($input['confirm'] ?? false) === true;
        if (!$confirm) {
            return wpultra_err('confirm_required', "action '$action' mutates JS error capture state — re-run with confirm:true.");
        }

        if ($action === 'clear') {
            wpultra_jserrors_clear();
            wpultra_audit_log('js-error-log', 'cleared JS error ring', true);
            return wpultra_ok(['action' => 'clear', 'count' => 0]);
        }

        $enable = ($action === 'enable');
        wpultra_jserrors_set_enabled($enable);
        wpultra_audit_log('js-error-log', ($enable ? 'enabled' : 'disabled') . ' JS error capture', true);
        return wpultra_ok(['action' => $action, 'enabled' => $enable]);
    }

    return wpultra_err('bad_action', "action must be one of: status, read, clear, enable, disable.");
}
