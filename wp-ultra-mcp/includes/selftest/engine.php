<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Self-improvement engine: structural health checks + per-ability call/failure stats.
 *
 * The health checks are intentionally pure (they take an `exists`/`callable` probe) so they
 * can be unit-tested without a live WordPress, and so the same logic that powers the
 * `wpultra/self-test` ability also guards against regressions like a router/adapter
 * function-name mismatch (a whole feature silently going dead).
 */

/* ------------------------------------------------------------------ stats (pure) */

/** Pure: increment the call (and, when !$ok, failure) tally for one action. */
function wpultra_stats_apply(array $stats, string $action, bool $ok): array {
    if (!isset($stats[$action]) || !is_array($stats[$action])) {
        $stats[$action] = ['calls' => 0, 'fails' => 0, 'last_error' => ''];
    }
    $stats[$action]['calls'] = (int) ($stats[$action]['calls'] ?? 0) + 1;
    if (!$ok) { $stats[$action]['fails'] = (int) ($stats[$action]['fails'] ?? 0) + 1; }
    return $stats;
}

/** Pure: sort actions by failure rate (desc), then calls (desc); return the top $limit. */
function wpultra_stats_rank(array $stats, int $limit = 10): array {
    $rows = [];
    foreach ($stats as $action => $s) {
        $calls = (int) ($s['calls'] ?? 0);
        $fails = (int) ($s['fails'] ?? 0);
        $rows[] = [
            'action'    => (string) $action,
            'calls'     => $calls,
            'fails'     => $fails,
            'fail_rate' => $calls > 0 ? round($fails / $calls, 3) : 0.0,
            'last_error' => (string) ($s['last_error'] ?? ''),
        ];
    }
    usort($rows, function ($a, $b) {
        return [$b['fail_rate'], $b['calls']] <=> [$a['fail_rate'], $a['calls']];
    });
    return array_slice($rows, 0, max(1, $limit));
}

/* ------------------------------------------------------------------ stats (live) */

/** Persist one call outcome. Best-effort; no-op outside WordPress. */
function wpultra_stats_bump(string $action, bool $ok, string $error = ''): void {
    if (!function_exists('get_option') || !function_exists('update_option')) { return; }
    $stats = get_option('wpultra_ability_stats', []);
    if (!is_array($stats)) { $stats = []; }
    $stats = wpultra_stats_apply($stats, $action, $ok);
    if (!$ok && $error !== '') {
        $stats[$action]['last_error'] = function_exists('mb_substr') ? mb_substr($error, 0, 200) : substr($error, 0, 200);
    }
    update_option('wpultra_ability_stats', $stats, false);
}

/* ------------------------------------------------------------- health (pure) */

/**
 * Pure: given the active fields providers and an `exists` probe, return the adapter functions
 * the router (wpultra_fields_{provider}_{op}) expects but that are missing. An empty array = healthy.
 */
function wpultra_selftest_provider_matrix(array $providers, callable $exists): array {
    $ops = ['read', 'write', 'list_groups', 'get_group'];
    $missing = [];
    foreach ($providers as $p) {
        foreach ($ops as $op) {
            $fn = "wpultra_fields_{$p}_{$op}";
            if (!$exists($fn)) { $missing[] = $fn; }
        }
    }
    return $missing;
}

/**
 * Pure: given a map of subsystem => [required function names] and an `exists` probe, return the
 * subset that are wired incompletely. Empty array = every enabled subsystem's entrypoints exist.
 */
function wpultra_selftest_subsystem_matrix(array $required, callable $exists): array {
    $broken = [];
    foreach ($required as $subsystem => $fns) {
        $miss = array_values(array_filter((array) $fns, fn($fn) => !$exists($fn)));
        if ($miss) { $broken[$subsystem] = $miss; }
    }
    return $broken;
}

/**
 * Pure: roll individual checks into an overall verdict. $checks is a list of
 * ['name'=>string,'ok'=>bool,'detail'=>string]. Returns ['ok'=>bool,'checks'=>..,'failed'=>[names]].
 */
function wpultra_selftest_summarize(array $checks): array {
    $failed = [];
    foreach ($checks as $c) {
        if (empty($c['ok'])) { $failed[] = (string) ($c['name'] ?? '?'); }
    }
    return ['ok' => $failed === [], 'checks' => $checks, 'failed' => $failed];
}
