<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/security-harden', [
    'label'       => __('Security Harden', 'wp-ultra-mcp'),
    'description' => __('Apply one or more idempotent security-hardening measures and report per-measure result (applied|partial|skipped|error) with an undo note (partial = applied but needs a manual step, e.g. .htaccess headers on an nginx server). Measures: disable-file-edit (writes define(\'DISALLOW_FILE_EDIT\', true) into wp-config.php via a marker before the "stop editing" line, backed up first), disable-xmlrpc (forces the xmlrpc_enabled filter false on every request), limit-login (limits failed logins per IP with a lockout window; options.max_attempts default 5, options.lockout_minutes default 15), security-headers (writes the security-headers preset via the server-rules .htaccess engine), hide-version (removes the WordPress generator meta from wp_head). Requires confirm:true — these are live config changes. Already-satisfied measures are reported as skipped, not re-applied.', 'wp-ultra-mcp'),
    'category'    => 'diagnostics',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'measures' => [
                'type'  => 'array',
                'items' => ['type' => 'string', 'enum' => ['disable-file-edit', 'disable-xmlrpc', 'limit-login', 'security-headers', 'hide-version']],
            ],
            'options' => [
                'type'       => 'object',
                'properties' => [
                    'max_attempts'    => ['type' => 'integer'],
                    'lockout_minutes' => ['type' => 'integer'],
                ],
                'additionalProperties' => false,
            ],
            'confirm' => ['type' => 'boolean'],
        ],
        'required'             => ['measures', 'confirm'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'       => ['type' => 'boolean'],
            'results'       => ['type' => 'array'],
            'applied_count' => ['type' => 'integer'],
            'skipped_count' => ['type' => 'integer'],
            'error_count'   => ['type' => 'integer'],
        ],
        'required' => ['success', 'results'],
    ],
    'execute_callback'    => 'wpultra_security_harden_ability',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => true],
    ],
]);

/** @return array|WP_Error */
function wpultra_security_harden_ability(array $input) {
    $confirm = ($input['confirm'] ?? false) === true;
    if (!$confirm) {
        return wpultra_err('unconfirmed', 'Security hardening makes live config changes. Re-run with confirm: true.');
    }

    $measures = array_values(array_filter(array_map('strval', (array) ($input['measures'] ?? []))));
    if ($measures === []) {
        return wpultra_err('no_measures', 'Provide at least one measure. Known: ' . implode(', ', wpultra_security_known_measures()));
    }
    foreach ($measures as $m) {
        if (!in_array($m, wpultra_security_known_measures(), true)) {
            return wpultra_err('unknown_measure', "Unknown measure '$m'. Known: " . implode(', ', wpultra_security_known_measures()));
        }
    }

    $options = is_array($input['options'] ?? null) ? $input['options'] : [];

    $run = wpultra_security_harden($measures, $options);
    $results = $run['results'];

    $counts = ['applied' => 0, 'skipped' => 0, 'error' => 0];
    foreach ($results as $r) {
        $status = is_array($r) ? (string) ($r['status'] ?? '') : '';
        if ($status === 'partial') { $status = 'applied'; } // applied-with-caveat (e.g. nginx .htaccess)
        if (isset($counts[$status])) { $counts[$status]++; }
    }

    $ok = $counts['error'] === 0;
    wpultra_audit_log('security-harden', 'measures=' . implode(',', $measures) . " applied={$counts['applied']} skipped={$counts['skipped']} error={$counts['error']}", $ok);

    return wpultra_ok([
        'results'       => $results,
        'applied_count' => $counts['applied'],
        'skipped_count' => $counts['skipped'],
        'error_count'   => $counts['error'],
    ]);
}
