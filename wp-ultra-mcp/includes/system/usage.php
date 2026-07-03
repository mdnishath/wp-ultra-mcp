<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Usage analytics (Roadmap #35): pure helpers over the per-ability stats rows
 * produced by wpultra_stats_rank() (see includes/selftest/engine.php — the
 * canonical stats store: option `wpultra_ability_stats`, row shape
 * {action, calls, fails, fail_rate, last_error}).
 *
 * Kept pure + dependency-free so both the admin dashboard
 * (includes/admin/stats-page.php) and the `wpultra/usage-stats` ability can
 * share one source of truth, and so tests/usage-stats.test.php can exercise
 * them without a live WordPress.
 */

/**
 * Pure: roll a list of stats rows (action, calls, fails, fail_rate, last_error)
 * into totals: total calls, total fails, distinct ability count, and the
 * action with the most calls (the "top ability"). Empty input -> zeroed totals
 * with top_action = ''.
 */
function wpultra_usage_totals(array $rows): array {
    $calls = 0;
    $fails = 0;
    $top_action = '';
    $top_calls = -1;
    foreach ($rows as $row) {
        if (!is_array($row)) { continue; }
        $c = (int) ($row['calls'] ?? 0);
        $f = (int) ($row['fails'] ?? 0);
        $calls += $c;
        $fails += $f;
        if ($c > $top_calls) {
            $top_calls = $c;
            $top_action = (string) ($row['action'] ?? '');
        }
    }
    return [
        'calls'      => $calls,
        'fails'      => $fails,
        'abilities'  => count($rows),
        'top_action' => $top_action,
    ];
}

/**
 * Pure: sort stats rows descending by the given key (calls|fails|fail_rate).
 * Ties broken by calls desc, then action name asc, for a stable/deterministic
 * order. Unknown keys fall back to 'calls'. Does not mutate the input array.
 */
function wpultra_usage_sort(array $rows, string $key): array {
    if (!in_array($key, ['calls', 'fails', 'fail_rate'], true)) { $key = 'calls'; }
    $sorted = array_values($rows);
    usort($sorted, function ($a, $b) use ($key) {
        $av = is_array($a) ? ($a[$key] ?? 0) : 0;
        $bv = is_array($b) ? ($b[$key] ?? 0) : 0;
        $ac = is_array($a) ? (int) ($a['calls'] ?? 0) : 0;
        $bc = is_array($b) ? (int) ($b['calls'] ?? 0) : 0;
        $aa = is_array($a) ? (string) ($a['action'] ?? '') : '';
        $ba = is_array($b) ? (string) ($b['action'] ?? '') : '';
        return [$bv, $bc, $aa] <=> [$av, $ac, $ba];
    });
    return $sorted;
}

/**
 * Pure: convert a call count into a 0-100 integer bar width relative to $max.
 * Guards the zero/negative-max case (returns 0 instead of dividing by zero).
 * Negative $calls is treated as 0. Result is clamped to [0,100].
 */
function wpultra_usage_bar_width(int $calls, int $max): int {
    if ($max <= 0) { return 0; }
    $calls = max(0, $calls);
    $pct = (int) round(($calls / $max) * 100);
    return max(0, min(100, $pct));
}
