<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/security-audit', [
    'label'       => __('Security Audit', 'wp-ultra-mcp'),
    'description' => __('Runs a set of security checks (core version, file editing, debug display, admin username/count, table prefix, SSL, directory listing sentinel, XML-RPC heuristic, pending updates, inactive plugins, auth salts) and returns per-check findings (id, status pass|warn|fail, detail). Read-only.', 'wp-ultra-mcp'),
    'category'    => 'diagnostics',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [],
        'required'             => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'      => ['type' => 'boolean'],
            'findings'     => ['type' => 'array'],
            'fail_count'   => ['type' => 'integer'],
            'warn_count'   => ['type' => 'integer'],
            'pass_count'   => ['type' => 'integer'],
        ],
        'required' => ['success', 'findings'],
    ],
    'execute_callback'    => 'wpultra_security_audit',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_security_audit(array $input) {
    $ctx      = wpultra_audits_security_collect();
    $findings = wpultra_audits_security_evaluate($ctx);

    $counts = ['pass' => 0, 'warn' => 0, 'fail' => 0];
    foreach ($findings as $f) {
        $status = $f['status'] ?? '';
        if (isset($counts[$status])) { $counts[$status]++; }
    }

    return wpultra_ok([
        'findings'    => $findings,
        'fail_count'  => $counts['fail'],
        'warn_count'  => $counts['warn'],
        'pass_count'  => $counts['pass'],
    ]);
}
