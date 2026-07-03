<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Analytics reader engine — reads Google Analytics 4 + Search Console data through
 * Google Site Kit's own authenticated REST proxy (plugin slug `google-site-kit`).
 *
 * Design: Site Kit stores the OAuth grant and exposes GA4 / Search Console reporting
 * on its own REST routes. We dispatch those routes INTERNALLY via rest_do_request()
 * (no HTTP loopback, no separate credentials) so the report runs with the current
 * (already-authenticated MCP admin) user. Routes used:
 *   GET /google-site-kit/v1/modules/analytics-4/data/report
 *   GET /google-site-kit/v1/modules/search-console/data/searchanalytics
 *
 * Split like the audits engine:
 *  - WP-touching: wpultra_analytics_status(), wpultra_analytics_report(), wpultra_analytics_search()
 *    (detection + rest_do_request dispatch).
 *  - PURE, testable core: wpultra_analytics_build_params(), wpultra_analytics_flatten_ga4(),
 *    wpultra_analytics_flatten_sc(), wpultra_analytics_resolve_date(). No WordPress calls.
 */

// ---------------------------------------------------------------------------
// Pure core
// ---------------------------------------------------------------------------

/**
 * PURE. Resolve a date token to a Y-m-d string.
 * Accepts an explicit 'Y-m-d' (returned as-is), 'today', 'yesterday', or
 * 'NdaysAgo' (e.g. '28daysAgo'). Anything else returns '' (caller validates).
 * $now is a unix timestamp (injectable for deterministic tests).
 */
function wpultra_analytics_resolve_date(string $token, int $now): string {
    $token = trim($token);
    if ($token === '') { return ''; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $token)) { return $token; }
    $lower = strtolower($token);
    if ($lower === 'today') { return gmdate('Y-m-d', $now); }
    if ($lower === 'yesterday') { return gmdate('Y-m-d', $now - 86400); }
    if (preg_match('/^(\d+)daysago$/', $lower, $m)) {
        return gmdate('Y-m-d', $now - ((int) $m[1] * 86400));
    }
    return '';
}

/**
 * PURE. Validate + normalize the incoming request into GA4 report query args.
 * Returns an assoc array of query args on success, or WP_Error on validation failure.
 *
 * Input keys (all optional; defaults applied):
 *   metrics      string|array — metric names, comma-separated or list. Default 'totalUsers,screenPageViews'.
 *   dimensions   string|array — dimension names, comma-separated or list. Default 'date'.
 *   start_date   string       — 'Y-m-d' or a relative token. Default '28daysAgo'.
 *   end_date     string       — 'Y-m-d' or a relative token. Default 'today'.
 *   limit        int          — row cap, clamped to 1..100. Default 10.
 *
 * Output query args (ready for WP_REST_Request->set_query_params):
 *   metrics    JSON array e.g. [{"name":"totalUsers"},{"name":"screenPageViews"}]
 *   dimensions JSON array e.g. [{"name":"date"}]
 *   startDate  'Y-m-d'
 *   endDate    'Y-m-d'
 *   limit      int (1..100)
 *
 * Metric/dimension names must match /^[A-Za-z0-9]+$/ (Site Kit / GA4 API names are
 * alphanumeric; this blocks JSON/GA4-expression injection through the proxy).
 */
function wpultra_analytics_build_params(array $in, ?int $now = null) {
    $now = $now ?? time();

    $metrics    = wpultra_analytics_name_list($in['metrics'] ?? '', ['totalUsers', 'screenPageViews']);
    $dimensions = wpultra_analytics_name_list($in['dimensions'] ?? '', ['date']);

    if ($metrics === null) {
        return wpultra_err('invalid_metric', 'Metric names must be alphanumeric (e.g. totalUsers, screenPageViews).');
    }
    if ($dimensions === null) {
        return wpultra_err('invalid_dimension', 'Dimension names must be alphanumeric (e.g. date, pagePath).');
    }
    if ($metrics === []) {
        return wpultra_err('missing_metric', 'At least one metric is required.');
    }

    $start = wpultra_analytics_resolve_date((string) ($in['start_date'] ?? '28daysAgo'), $now);
    $end   = wpultra_analytics_resolve_date((string) ($in['end_date'] ?? 'today'), $now);
    if ($start === '') {
        return wpultra_err('invalid_start_date', 'start_date must be Y-m-d, "today", "yesterday", or "NdaysAgo".');
    }
    if ($end === '') {
        return wpultra_err('invalid_end_date', 'end_date must be Y-m-d, "today", "yesterday", or "NdaysAgo".');
    }
    if ($start > $end) {
        return wpultra_err('date_range_inverted', "start_date ($start) must not be after end_date ($end).");
    }

    $limit = isset($in['limit']) ? (int) $in['limit'] : 10;
    if ($limit < 1) { $limit = 1; }
    if ($limit > 100) { $limit = 100; }

    return [
        'metrics'    => json_encode(array_map(static fn($n) => ['name' => $n], $metrics)),
        'dimensions' => json_encode(array_map(static fn($n) => ['name' => $n], $dimensions)),
        'startDate'  => $start,
        'endDate'    => $end,
        'limit'      => $limit,
    ];
}

/**
 * PURE. Normalize a comma-separated string or list of names into a clean list of
 * alphanumeric names. Returns null if any entry is not /^[A-Za-z0-9]+$/, or the
 * $default list (untouched) when the input is empty.
 * @return array<int,string>|null
 */
function wpultra_analytics_name_list($raw, array $default): ?array {
    if (is_string($raw)) {
        $raw = trim($raw);
        $parts = $raw === '' ? [] : preg_split('/\s*,\s*/', $raw);
    } elseif (is_array($raw)) {
        $parts = $raw;
    } else {
        $parts = [];
    }
    $parts = array_values(array_filter(array_map(static fn($p) => trim((string) $p), $parts), static fn($p) => $p !== ''));
    if ($parts === []) { return $default; }
    foreach ($parts as $p) {
        if (!preg_match('/^[A-Za-z0-9]+$/', $p)) { return null; }
    }
    return $parts;
}

/**
 * PURE. Flatten a GA4 runReport-style response into flat rows.
 * The response uses parallel header/value arrays:
 *   dimensionHeaders: [{name:'date'}, ...]
 *   metricHeaders:    [{name:'totalUsers'}, ...]
 *   rows: [{dimensionValues:[{value:'20260701'}], metricValues:[{value:'42'}]}, ...]
 * Each output row is a flat assoc array keyed by dimension name then metric name.
 * Metric values are cast to numeric where they look numeric. Handles the common
 * Site Kit shape where the payload is nested under a top-level (numeric) index or
 * a 'reports'/'rows' wrapper.
 * @return array<int,array<string,mixed>>
 */
function wpultra_analytics_flatten_ga4(array $response): array {
    $report = wpultra_analytics_unwrap_ga4($response);

    $dimHeaders = array_map(
        static fn($h) => is_array($h) ? (string) ($h['name'] ?? '') : (string) $h,
        is_array($report['dimensionHeaders'] ?? null) ? $report['dimensionHeaders'] : []
    );
    $metHeaders = array_map(
        static fn($h) => is_array($h) ? (string) ($h['name'] ?? '') : (string) $h,
        is_array($report['metricHeaders'] ?? null) ? $report['metricHeaders'] : []
    );

    $out = [];
    $rows = is_array($report['rows'] ?? null) ? $report['rows'] : [];
    foreach ($rows as $row) {
        if (!is_array($row)) { continue; }
        $flat = [];
        $dimVals = is_array($row['dimensionValues'] ?? null) ? $row['dimensionValues'] : [];
        foreach ($dimVals as $i => $dv) {
            $key = $dimHeaders[$i] ?? ('dimension' . $i);
            $flat[$key] = is_array($dv) ? ($dv['value'] ?? '') : $dv;
        }
        $metVals = is_array($row['metricValues'] ?? null) ? $row['metricValues'] : [];
        foreach ($metVals as $i => $mv) {
            $key = $metHeaders[$i] ?? ('metric' . $i);
            $val = is_array($mv) ? ($mv['value'] ?? '') : $mv;
            $flat[$key] = wpultra_analytics_numify($val);
        }
        $out[] = $flat;
    }
    return $out;
}

/**
 * PURE. Pull the report body (with dimensionHeaders/metricHeaders/rows) out of the
 * various shapes Site Kit / GA4 return it in. Returns [] if none found.
 */
function wpultra_analytics_unwrap_ga4(array $response): array {
    // Already the report body.
    if (isset($response['rows']) || isset($response['metricHeaders']) || isset($response['dimensionHeaders'])) {
        return $response;
    }
    // Site Kit returns a list: [ { ...report... } ] — first numeric-indexed entry.
    if (isset($response[0]) && is_array($response[0])) {
        return wpultra_analytics_unwrap_ga4($response[0]);
    }
    // GA4 batchRunReports style.
    if (isset($response['reports'][0]) && is_array($response['reports'][0])) {
        return wpultra_analytics_unwrap_ga4($response['reports'][0]);
    }
    return [];
}

/**
 * PURE. Flatten a Search Console searchanalytics response into flat rows.
 * Response shape: {rows:[{keys:['term'], clicks, impressions, ctr, position}, ...]}
 * $dimensions names the keys (e.g. ['query'] or ['page']); each key maps positionally.
 * @param array<int,string> $dimensions
 * @return array<int,array<string,mixed>>
 */
function wpultra_analytics_flatten_sc(array $response, array $dimensions = ['query']): array {
    // Site Kit wraps the report as {rows:[...]}; some shapes hand back the row list directly.
    if (isset($response['rows']) && is_array($response['rows'])) {
        $rows = $response['rows'];
    } elseif (isset($response[0]) && is_array($response[0])) {
        $rows = $response;
    } else {
        $rows = [];
    }

    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) { continue; }
        $flat = [];
        $keys = is_array($row['keys'] ?? null) ? $row['keys'] : [];
        foreach ($keys as $i => $kv) {
            $name = $dimensions[$i] ?? ('key' . $i);
            $flat[$name] = is_scalar($kv) ? $kv : '';
        }
        $flat['clicks']      = wpultra_analytics_numify($row['clicks'] ?? 0);
        $flat['impressions'] = wpultra_analytics_numify($row['impressions'] ?? 0);
        $flat['ctr']         = wpultra_analytics_numify($row['ctr'] ?? 0);
        $flat['position']    = wpultra_analytics_numify($row['position'] ?? 0);
        $out[] = $flat;
    }
    return $out;
}

/** PURE. Cast a value to int/float when it is a numeric string; otherwise return it unchanged. */
function wpultra_analytics_numify($val) {
    if (is_int($val) || is_float($val)) { return $val; }
    if (is_string($val) && is_numeric($val)) {
        return (strpos($val, '.') !== false || stripos($val, 'e') !== false) ? (float) $val : (int) $val;
    }
    return $val;
}

// ---------------------------------------------------------------------------
// WP-touching: detection
// ---------------------------------------------------------------------------

/**
 * WP-touching. Report Site Kit availability + which modules are connected.
 * @return array{
 *   installed:bool, version:string,
 *   analytics4_connected:bool, property_id:string,
 *   search_console_available:bool
 * }
 */
function wpultra_analytics_status(): array {
    $installed = defined('GOOGLESITEKIT_VERSION');
    $version   = $installed ? (string) constant('GOOGLESITEKIT_VERSION') : '';

    $ga4_settings = function_exists('get_option') ? get_option('googlesitekit_analytics-4_settings', []) : [];
    $property_id  = is_array($ga4_settings) ? (string) ($ga4_settings['propertyID'] ?? '') : '';

    $has_connected_admins = function_exists('get_option') ? (bool) get_option('googlesitekit_has_connected_admins', false) : false;
    $ga4_connected = $installed && $property_id !== '' && $has_connected_admins;

    $sc_settings = function_exists('get_option') ? get_option('googlesitekit_search-console_settings', []) : [];
    $sc_property = is_array($sc_settings) ? (string) ($sc_settings['propertyID'] ?? '') : '';
    // Search Console is available whenever Site Kit is connected (it is the baseline module).
    $sc_available = $installed && $has_connected_admins && ($sc_property !== '' || $ga4_connected);

    return [
        'installed'                => $installed,
        'version'                  => $version,
        'analytics4_connected'     => $ga4_connected,
        'property_id'              => $property_id,
        'search_console_available' => $sc_available,
    ];
}

// ---------------------------------------------------------------------------
// WP-touching: dispatch
// ---------------------------------------------------------------------------

/**
 * WP-touching. Fetch a GA4 report through Site Kit's proxy.
 * @param array $in see wpultra_analytics_build_params(). $now injectable for tests.
 * @return array{rows:array,totals:array,status:array}|WP_Error
 */
function wpultra_analytics_report(array $in, ?int $now = null) {
    $status = wpultra_analytics_status();
    if (!$status['installed']) {
        return wpultra_err('sitekit_missing', 'Google Site Kit (google-site-kit) is not installed/active. Install and connect it, then retry.');
    }
    if (!$status['analytics4_connected']) {
        return wpultra_err('ga4_not_connected', 'Google Analytics 4 is not connected in Site Kit (no propertyID / no connected admin). Connect Analytics in Site Kit first.');
    }

    $params = wpultra_analytics_build_params($in, $now);
    if (is_wp_error($params)) { return $params; }

    $body = wpultra_analytics_dispatch('GET', '/google-site-kit/v1/modules/analytics-4/data/report', $params);
    if (is_wp_error($body)) { return $body; }

    $rows   = wpultra_analytics_flatten_ga4(is_array($body) ? $body : []);
    $report = wpultra_analytics_unwrap_ga4(is_array($body) ? $body : []);
    $totals = wpultra_analytics_extract_ga4_totals($report);

    return ['rows' => $rows, 'totals' => $totals, 'status' => $status];
}

/**
 * WP-touching. Fetch a Search Console report through Site Kit's proxy.
 * @param array $in { dimensions?:string|array (default 'query'), start_date?, end_date?, limit? }
 * @return array{rows:array,status:array}|WP_Error
 */
function wpultra_analytics_search(array $in, ?int $now = null) {
    $status = wpultra_analytics_status();
    if (!$status['installed']) {
        return wpultra_err('sitekit_missing', 'Google Site Kit (google-site-kit) is not installed/active. Install and connect it, then retry.');
    }
    if (!$status['search_console_available']) {
        return wpultra_err('search_console_unavailable', 'Search Console is not available in Site Kit (not connected). Connect Site Kit first.');
    }

    $now = $now ?? time();
    $dims = wpultra_analytics_name_list($in['dimensions'] ?? 'query', ['query']);
    if ($dims === null) {
        return wpultra_err('invalid_dimension', 'Search Console dimensions must be alphanumeric (e.g. query, page, date, country, device).');
    }

    $start = wpultra_analytics_resolve_date((string) ($in['start_date'] ?? '28daysAgo'), $now);
    $end   = wpultra_analytics_resolve_date((string) ($in['end_date'] ?? 'today'), $now);
    if ($start === '') { return wpultra_err('invalid_start_date', 'start_date must be Y-m-d, "today", "yesterday", or "NdaysAgo".'); }
    if ($end === '') { return wpultra_err('invalid_end_date', 'end_date must be Y-m-d, "today", "yesterday", or "NdaysAgo".'); }
    if ($start > $end) { return wpultra_err('date_range_inverted', "start_date ($start) must not be after end_date ($end)."); }

    $limit = isset($in['limit']) ? (int) $in['limit'] : 10;
    if ($limit < 1) { $limit = 1; }
    if ($limit > 100) { $limit = 100; }

    $params = [
        'dimensions' => json_encode($dims),
        'startDate'  => $start,
        'endDate'    => $end,
        'limit'      => $limit,
    ];

    $body = wpultra_analytics_dispatch('GET', '/google-site-kit/v1/modules/search-console/data/searchanalytics', $params);
    if (is_wp_error($body)) { return $body; }

    return ['rows' => wpultra_analytics_flatten_sc(is_array($body) ? $body : [], $dims), 'status' => $status];
}

/**
 * WP-touching. Build + fire an internal REST request against a Site Kit route and
 * return its decoded data, or a helpful WP_Error carrying Site Kit's own message.
 * @param array<string,mixed> $params query args
 * @return mixed|WP_Error
 */
function wpultra_analytics_dispatch(string $method, string $route, array $params) {
    if (!class_exists('WP_REST_Request') || !function_exists('rest_do_request')) {
        return wpultra_err('rest_unavailable', 'The WordPress REST infrastructure is unavailable in this context.');
    }
    $request = new WP_REST_Request($method, $route);
    $request->set_query_params($params);

    $response = rest_do_request($request);

    // rest_do_request returns a WP_REST_Response; is_error()/get_status() describe the outcome.
    if (function_exists('is_wp_error') && is_wp_error($response)) {
        /** @var WP_Error $response */
        return wpultra_err('sitekit_rest_error', 'Site Kit returned an error: ' . $response->get_error_message(), $response->get_error_data());
    }

    $status = method_exists($response, 'get_status') ? (int) $response->get_status() : 0;
    $data   = method_exists($response, 'get_data') ? $response->get_data() : null;

    if ($status < 200 || $status >= 300) {
        $msg = '';
        if (is_array($data)) {
            $msg = (string) ($data['message'] ?? '');
            if ($msg === '' && isset($data['code'])) { $msg = (string) $data['code']; }
        }
        if ($msg === '') { $msg = "HTTP $status from $route"; }
        return wpultra_err('sitekit_report_failed', "Site Kit report failed (HTTP $status): $msg. Confirm the module is connected and the requested metrics/dimensions are valid.", $data);
    }

    return $data;
}

/**
 * PURE. Extract GA4 report-wide totals (metric name => numeric) if present.
 * GA4 totals shape: {totals:[{metricValues:[{value:'123'}, ...]}]} aligned to metricHeaders.
 * @return array<string,mixed>
 */
function wpultra_analytics_extract_ga4_totals(array $report): array {
    $totals = [];
    $metHeaders = array_map(
        static fn($h) => is_array($h) ? (string) ($h['name'] ?? '') : (string) $h,
        is_array($report['metricHeaders'] ?? null) ? $report['metricHeaders'] : []
    );
    $totalRows = is_array($report['totals'] ?? null) ? $report['totals'] : [];
    $first = $totalRows[0] ?? null;
    if (is_array($first) && isset($first['metricValues']) && is_array($first['metricValues'])) {
        foreach ($first['metricValues'] as $i => $mv) {
            $key = $metHeaders[$i] ?? ('metric' . $i);
            $val = is_array($mv) ? ($mv['value'] ?? '') : $mv;
            $totals[$key] = wpultra_analytics_numify($val);
        }
    }
    return $totals;
}
