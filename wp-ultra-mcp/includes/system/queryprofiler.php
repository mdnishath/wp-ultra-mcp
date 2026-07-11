<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Query profiler engine (Roadmap-4 BF2.2).
 *
 * SAVEQUERIES-based profiling of one WordPress request: total query count/time,
 * the N slowest queries, and duplicate-query detection via normalized-SQL
 * grouping. Query-Monitor-lite.
 *
 * IMPORTANT: this file never toggles SAVEQUERIES itself. SAVEQUERIES must be
 * defined true BEFORE the request runs for $wpdb->queries to populate — that
 * is the `debug-mode` ability's job (includes/abilities/debug-mode.php /
 * includes/system/debugmode.php), not this one's. This engine only reads
 * whatever $wpdb has already captured.
 *
 * PURE functions (unit-tested, no WordPress calls):
 *   wpultra_qprof_normalize_sql()
 *   wpultra_qprof_caller()
 *   wpultra_qprof_excerpt()
 *   wpultra_qprof_analyze()
 *
 * WP-touching wrappers (live-tested by the controller, not unit tests):
 *   wpultra_qprof_analyze_current(), wpultra_qprof_profile_url()
 */

/* =====================================================================
 * PURE
 * ===================================================================== */

/**
 * PURE. Normalize a SQL string for duplicate-query grouping: collapse all
 * whitespace runs to a single space, then mask single-quoted and
 * double-quoted string literals and standalone numeric literals with `?`.
 * Two queries that only differ in the literal values they bind normalize to
 * the same string.
 *
 * Quoted literals are masked with a single-pass character scanner (not two
 * independent regex passes) so a mode of quote is only ever exited by its
 * own matching quote character. A prior implementation masked '...' and
 * "..." in separate stateless preg_replace passes; the single-quote pass had
 * no notion of "already inside a double-quoted string" and would greedily
 * span from an apostrophe inside one "..." literal to an apostrophe inside a
 * later "..." literal, silently swallowing everything (including whole
 * clauses) in between. The scanner below tracks one quote mode at a time and
 * honors backslash escapes and doubled-quote escapes ('' / "").
 */
function wpultra_qprof_normalize_sql(string $sql): string {
    $s = trim($sql);
    $s = (string) preg_replace('/\s+/', ' ', $s);

    $len = strlen($s);
    $out = '';
    $i = 0;
    while ($i < $len) {
        $ch = $s[$i];
        if ($ch === "'" || $ch === '"') {
            $quote = $ch;
            $j = $i + 1;
            while ($j < $len) {
                if ($s[$j] === '\\' && $j + 1 < $len) {
                    $j += 2; // skip an escaped character (e.g. \" or \')
                    continue;
                }
                if ($s[$j] === $quote) {
                    if ($j + 1 < $len && $s[$j + 1] === $quote) {
                        $j += 2; // doubled-quote escape ('' or "") — still inside the literal
                        continue;
                    }
                    $j++; // consume the closing quote
                    break;
                }
                $j++;
            }
            $out .= '?';
            $i = $j;
            continue;
        }
        $out .= $ch;
        $i++;
    }

    // Mask standalone numeric literals (integers and decimals), leaving identifiers alone.
    $out = (string) preg_replace('/\b\d+(?:\.\d+)?\b/', '?', $out);
    return trim($out);
}

/**
 * PURE. Given a $wpdb->queries call-stack column (a comma-separated list of
 * frames, outermost...innermost), return the last "meaningful" frame — i.e.
 * the last entry that isn't a generic require/include frame. Falls back to
 * the last raw frame if every entry is generic, and to 'unknown' for an
 * empty/blank stack.
 */
function wpultra_qprof_caller(string $stack): string {
    $parts = array_values(array_filter(array_map('trim', explode(',', $stack)), static fn($p) => $p !== ''));
    if ($parts === []) { return 'unknown'; }

    $skip = ['require', 'require_once', 'include', 'include_once'];
    for ($i = count($parts) - 1; $i >= 0; $i--) {
        if (!in_array(strtolower($parts[$i]), $skip, true)) {
            return $parts[$i];
        }
    }
    return $parts[count($parts) - 1];
}

/** PURE. Collapse whitespace and cap a SQL string to 200 chars (+ ellipsis) for display. */
function wpultra_qprof_excerpt(string $sql): string {
    $s = (string) preg_replace('/\s+/', ' ', trim($sql));
    return strlen($s) > 200 ? substr($s, 0, 200) . '...' : $s;
}

/**
 * PURE. Analyze raw $wpdb->queries rows (each row: [0]=sql, [1]=duration
 * seconds (float), [2]=call stack, [3]=start time, [4]=custom — guarded,
 * only [0] and [1] are required). Returns:
 *   {total_queries, total_time_ms, slowest: [{sql_excerpt, ms, caller}],
 *    duplicates: [{normalized_sql, count, total_ms}]}
 * `slowest` is capped at $top entries, ordered by duration descending.
 * `duplicates` groups by normalized SQL and only reports groups with
 * count >= 2, ordered by count then total_ms descending.
 */
function wpultra_qprof_analyze(array $queries, int $top): array {
    $top = max(1, $top);

    $valid = [];
    foreach ($queries as $row) {
        if (!is_array($row) || !array_key_exists(0, $row) || !array_key_exists(1, $row)) { continue; }
        $valid[] = [
            'sql'      => (string) $row[0],
            'duration' => (float) $row[1],
            'stack'    => isset($row[2]) ? (string) $row[2] : '',
        ];
    }

    $total_queries = count($valid);
    $total_time = 0.0;
    foreach ($valid as $v) { $total_time += $v['duration']; }

    $sorted = $valid;
    usort($sorted, static fn($a, $b) => $b['duration'] <=> $a['duration']);
    $slowest = [];
    foreach (array_slice($sorted, 0, $top) as $v) {
        $slowest[] = [
            'sql_excerpt' => wpultra_qprof_excerpt($v['sql']),
            'ms'          => round($v['duration'] * 1000, 2),
            'caller'      => wpultra_qprof_caller($v['stack']),
        ];
    }

    $groups = [];
    foreach ($valid as $v) {
        $norm = wpultra_qprof_normalize_sql($v['sql']);
        if (!isset($groups[$norm])) { $groups[$norm] = ['count' => 0, 'total_ms' => 0.0]; }
        $groups[$norm]['count']++;
        $groups[$norm]['total_ms'] += $v['duration'] * 1000;
    }
    $duplicates = [];
    foreach ($groups as $norm => $g) {
        if ($g['count'] >= 2) {
            $duplicates[] = ['normalized_sql' => $norm, 'count' => $g['count'], 'total_ms' => round($g['total_ms'], 2)];
        }
    }
    usort($duplicates, static function ($a, $b) {
        return $b['count'] <=> $a['count'] ?: $b['total_ms'] <=> $a['total_ms'];
    });

    return [
        'total_queries' => $total_queries,
        'total_time_ms' => round($total_time * 1000, 2),
        'slowest'       => $slowest,
        'duplicates'    => $duplicates,
    ];
}

/* =====================================================================
 * WP-touching
 * ===================================================================== */

/**
 * analyze-current: analyze the CURRENT request's already-captured
 * $wpdb->queries. SAVEQUERIES must have been true BEFORE this request
 * started (it cannot be enabled mid-request) — if it isn't, report that
 * honestly instead of erroring, and point at the `debug-mode` ability
 * (the one that actually flips SAVEQUERIES in wp-config.php) plus the
 * profile-url fallback.
 */
function wpultra_qprof_analyze_current(int $top): array {
    if (!(defined('SAVEQUERIES') && SAVEQUERIES)) {
        return wpultra_ok([
            'mode'          => 'analyze-current',
            'captured'      => false,
            'total_queries' => 0,
            'total_time_ms' => 0.0,
            'slowest'       => [],
            'duplicates'    => [],
            'note'          => 'SAVEQUERIES is not enabled; enable it via the debug-mode ability (set SAVEQUERIES=true) then profile a fresh request, or use action=profile-url.',
        ]);
    }

    global $wpdb;
    $queries = (isset($wpdb) && is_object($wpdb) && isset($wpdb->queries) && is_array($wpdb->queries)) ? $wpdb->queries : [];

    $result = wpultra_qprof_analyze($queries, $top);
    $result['mode'] = 'analyze-current';
    $result['captured'] = true;
    if ($queries === []) {
        $result['note'] = 'SAVEQUERIES is enabled but no queries were captured yet for this request.';
    }
    return wpultra_ok($result);
}

/**
 * profile-url: make ONE wp_remote_get probe of a front-end URL (default
 * home_url()) and report request timing + HTTP status. This does NOT
 * capture per-query data — SAVEQUERIES cannot be turned on for a request
 * fired from a separate outgoing HTTP call, and this function never tries
 * to enable it. Only a single probe is made (no retry loop): a single-worker
 * dev host (e.g. `php -S`) can only serve one request at a time, so an
 * outgoing self-request from within the current request already risks
 * blocking until timeout — looping probes would multiply that risk.
 *
 * @return array|WP_Error
 */
function wpultra_qprof_profile_url(array $input) {
    if (!function_exists('wp_remote_get')) {
        return wpultra_err('wp_unavailable', 'wp_remote_get() is unavailable.');
    }

    $url = trim((string) ($input['url'] ?? ''));
    if ($url === '') {
        $url = function_exists('home_url') ? (string) home_url('/') : '';
    }
    if ($url === '') {
        return wpultra_err('no_url', 'No url provided and home_url() is unavailable.');
    }

    $token = (string) (function_exists('wp_generate_password') ? wp_generate_password(12, false) : uniqid('', true));
    $probe_url = function_exists('add_query_arg')
        ? (string) add_query_arg('wpultra_profile', $token, $url)
        : $url . (str_contains($url, '?') ? '&' : '?') . 'wpultra_profile=' . rawurlencode($token);

    $start = microtime(true);
    $resp = wp_remote_get($probe_url, ['timeout' => 15, 'sslverify' => false, 'redirection' => 3]);
    $elapsed_ms = round((microtime(true) - $start) * 1000, 2);

    if (is_wp_error($resp)) {
        return wpultra_err('probe_failed', "Request to $probe_url failed: " . $resp->get_error_message());
    }

    $status = (int) wp_remote_retrieve_response_code($resp);

    return wpultra_ok([
        'mode'        => 'profile-url',
        'url'         => $probe_url,
        'status'      => $status,
        'elapsed_ms'  => $elapsed_ms,
        'note'        => 'This mode reports request timing and HTTP status only — a single outgoing probe cannot capture per-query data, because SAVEQUERIES must already be true BEFORE the profiled request runs and this ability never enables it. For full query capture: enable SAVEQUERIES via the debug-mode ability, then call this ability with action=analyze-current on a subsequent request.',
    ]);
}
