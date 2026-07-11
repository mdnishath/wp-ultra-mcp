<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/safe-mode-manage', [
    'label'       => __('Safe Mode Manage', 'wp-ultra-mcp'),
    'description' => __('Inspect or manage the sandbox safe-mode sentinel (.crashed) that suspends execute-php/run-wp-cli after a fatal. Action "status" (default, read-only) reports whether safe mode is active, the sentinel path/content/mtime, the last captured fatal (from error-reports), and a how_to_clear note. Action "clear" (confirm-gated) REQUIRES a `cause` string (>=10 chars) naming the diagnosed root cause of the fatal; deletes the sentinel and records an audit entry — code-exec resumes on the NEXT request. Action "arm" (confirm-gated) deliberately re-creates the sentinel to proactively block code-exec (e.g. before a risky maintenance window); `reason` is optional context.', 'wp-ultra-mcp'),
    'category'    => 'diagnostics',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['status', 'clear', 'arm'], 'default' => 'status'],
            'cause'   => ['type' => 'string'],
            'reason'  => ['type' => 'string'],
            'confirm' => ['type' => 'boolean'],
        ],
        'required'             => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'          => ['type' => 'boolean'],
            'action'           => ['type' => 'string'],
            'active'           => ['type' => 'boolean'],
            'sentinel_path'    => ['type' => 'string'],
            'sentinel_exists'  => ['type' => 'boolean'],
            'sentinel_content' => ['type' => ['string', 'null']],
            'sentinel_mtime'   => ['type' => ['integer', 'null']],
            'last_fatal'       => ['type' => ['object', 'null']],
            'how_to_clear'     => ['type' => 'string'],
            'cleared'          => ['type' => 'boolean'],
            'was_active'       => ['type' => 'boolean'],
            'armed'            => ['type' => 'boolean'],
            'note'             => ['type' => 'string'],
        ],
        'required' => ['success', 'action'],
    ],
    'execute_callback'    => 'wpultra_safe_mode_manage_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => true],
    ],
]);

/** @return array|WP_Error */
function wpultra_safe_mode_manage_cb(array $input) {
    $action = (string) ($input['action'] ?? 'status');
    if ($action === '') { $action = 'status'; }

    if ($action === 'status') {
        $status = wpultra_safemode_status();
        return wpultra_ok(array_merge(['action' => 'status'], $status));
    }

    if ($action === 'clear') {
        $confirm = ($input['confirm'] ?? false) === true;
        if (!$confirm) {
            return wpultra_err('confirm_required', 'Clearing safe mode re-enables code execution. Re-run with confirm:true and a cause describing the diagnosed root cause.');
        }
        $cause = (string) ($input['cause'] ?? '');
        $result = wpultra_safemode_do_clear($cause);
        if (is_wp_error($result)) { return $result; }
        wpultra_audit_log('safe-mode-manage', 'clear cause=' . $cause, true);
        return wpultra_ok(array_merge(['action' => 'clear'], $result));
    }

    if ($action === 'arm') {
        $confirm = ($input['confirm'] ?? false) === true;
        if (!$confirm) {
            return wpultra_err('confirm_required', 'Arming safe mode blocks execute-php/run-wp-cli. Re-run with confirm:true.');
        }
        $reason = (string) ($input['reason'] ?? '');
        $result = wpultra_safemode_do_arm($reason);
        if (is_wp_error($result)) { return $result; }
        wpultra_audit_log('safe-mode-manage', 'arm reason=' . $reason, true);
        return wpultra_ok(array_merge(['action' => 'arm'], $result));
    }

    return wpultra_err('bad_action', "action must be 'status', 'clear', or 'arm'.");
}
