<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/security-scan', [
    'label'       => __('Security Scan', 'wp-ultra-mcp'),
    'description' => __('Read-only malware / integrity scan. Runs the requested scans (default all): checksums (compare wp-admin + wp-includes files against the official wp.org checksum manifest for the installed version → modified/missing lists; skipped if the version is unknown or the manifest is unreachable), suspicious-code (scan plugin + uploads .php files for high-signal backdoor patterns — eval, base64_decode, gzinflate, str_rot13, superglobal-as-callable, preg_replace /e, assert, system/exec/shell_exec/passthru — with per-hit severity; ANY .php file under the uploads directory is itself flagged high), recently-modified (.php files changed within the last `days` days, default 7). All scans are file-capped and size-guarded. Returns findings grouped by scan, each with a severity, plus an overall risk level (clean|low|medium|high). Read-only.', 'wp-ultra-mcp'),
    'category'    => 'diagnostics',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'scans' => [
                'type'  => 'array',
                'items' => ['type' => 'string', 'enum' => ['checksums', 'suspicious-code', 'recently-modified']],
            ],
            'days'  => ['type' => 'integer'],
            'limit' => ['type' => 'integer'],
        ],
        'required'             => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'scans'   => ['type' => 'array'],
            'risk'    => ['type' => 'string'],
        ],
        'required' => ['success', 'scans', 'risk'],
    ],
    'execute_callback'    => 'wpultra_security_scan_ability',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

/** @return array|WP_Error */
function wpultra_security_scan_ability(array $input) {
    $scans = array_values(array_filter(array_map('strval', (array) ($input['scans'] ?? []))));
    $days  = isset($input['days']) ? max(1, (int) $input['days']) : 7;

    // Optional per-scan file cap override (bounds runtime); routed via the filter the engine reads.
    if (isset($input['limit'])) {
        $limit = max(1, (int) $input['limit']);
        if (function_exists('add_filter')) {
            add_filter('wpultra_security_scan_file_cap', static fn() => $limit);
        }
    }

    $result = wpultra_security_scan($scans, $days);

    wpultra_audit_log('security-scan', 'scans=' . (implode(',', $scans) ?: 'all') . ' risk=' . $result['risk'], true);

    return wpultra_ok([
        'scans' => $result['scans'],
        'risk'  => $result['risk'],
    ]);
}
