<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/performance-audit', [
    'label'       => __('Performance Audit', 'wp-ultra-mcp'),
    'description' => __('Runs a set of performance checks (autoloaded options size + top 10 largest, expired transients, revisions count, attachment file integrity, active plugin count, object cache presence, page-cache plugin detection, overdue cron events) and returns per-check findings (id, status pass|warn|fail, detail) plus a 0-100 score. Read-only.', 'wp-ultra-mcp'),
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
            'success'    => ['type' => 'boolean'],
            'findings'   => ['type' => 'array'],
            'score'      => ['type' => 'integer'],
            'fail_count' => ['type' => 'integer'],
            'warn_count' => ['type' => 'integer'],
            'pass_count' => ['type' => 'integer'],
        ],
        'required' => ['success', 'findings', 'score'],
    ],
    'execute_callback'    => 'wpultra_performance_audit',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_performance_audit(array $input) {
    $ctx      = wpultra_audits_performance_collect();
    $findings = wpultra_audits_performance_evaluate($ctx);
    $score    = wpultra_audits_performance_score($findings);

    $counts = ['pass' => 0, 'warn' => 0, 'fail' => 0];
    foreach ($findings as $f) {
        $status = $f['status'] ?? '';
        if (isset($counts[$status])) { $counts[$status]++; }
    }

    return wpultra_ok([
        'findings'    => $findings,
        'score'       => $score,
        'fail_count'  => $counts['fail'],
        'warn_count'  => $counts['warn'],
        'pass_count'  => $counts['pass'],
    ]);
}
