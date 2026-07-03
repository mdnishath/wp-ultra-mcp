<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

// The engine ships under includes/system/. Require it defensively so this ability
// works regardless of load order (mirrors how the diagnostics abilities lean on their engine).
if (!function_exists('wpultra_analytics_report') && defined('WPULTRA_DIR') && is_readable(WPULTRA_DIR . 'includes/system/analytics.php')) {
    require_once WPULTRA_DIR . 'includes/system/analytics.php';
}

wp_register_ability('wpultra/analytics-report', [
    'label'       => __('Analytics Report', 'wp-ultra-mcp'),
    'description' => __('Read Google Analytics 4 or Search Console data through Google Site Kit\'s own authenticated proxy (no separate credentials). Requires the google-site-kit plugin installed and connected (Analytics 4 needs a propertyID; Search Console needs Site Kit connected). source=ga4 returns metric rows by dimension (default metrics totalUsers,screenPageViews by date) plus totals; source=search-console returns top queries/pages with clicks, impressions, ctr, position. Dates accept Y-m-d or relative tokens (today, yesterday, NdaysAgo; default range 28daysAgo..today). Read-only.', 'wp-ultra-mcp'),
    'category'    => 'diagnostics',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'source'     => ['type' => 'string', 'enum' => ['ga4', 'search-console'], 'default' => 'ga4', 'description' => 'Which Site Kit module to read: ga4 (Analytics 4) or search-console.'],
            'metrics'    => ['type' => 'string', 'description' => 'GA4 only. Comma-separated metric names (alphanumeric), e.g. "totalUsers,screenPageViews". Default totalUsers,screenPageViews.'],
            'dimensions' => ['type' => 'string', 'description' => 'Comma-separated dimension names (alphanumeric). GA4 default "date" (try pagePath, country, deviceCategory). Search Console default "query" (or page, date, country, device).'],
            'start_date' => ['type' => 'string', 'description' => 'Y-m-d or relative token (today, yesterday, NdaysAgo). Default 28daysAgo.'],
            'end_date'   => ['type' => 'string', 'description' => 'Y-m-d or relative token. Default today.'],
            'limit'      => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Max rows (clamped 1..100). Default 10.'],
        ],
        'required'             => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'source'  => ['type' => 'string'],
            'rows'    => ['type' => 'array'],
            'totals'  => ['type' => 'object'],
            'status'  => ['type' => 'object'],
        ],
        'required' => ['success', 'rows'],
    ],
    'execute_callback'    => 'wpultra_analytics_report_ability',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_analytics_report_ability(array $input) {
    if (!function_exists('wpultra_analytics_report')) {
        return wpultra_err('analytics_engine_missing', 'The analytics engine (includes/system/analytics.php) is not loaded.');
    }

    $source = strtolower((string) ($input['source'] ?? 'ga4'));

    if ($source === 'search-console') {
        $result = wpultra_analytics_search($input);
        if (is_wp_error($result)) { return $result; }
        return wpultra_ok([
            'source' => 'search-console',
            'rows'   => $result['rows'],
            'status' => $result['status'],
        ]);
    }

    // Default: GA4.
    $result = wpultra_analytics_report($input);
    if (is_wp_error($result)) { return $result; }
    return wpultra_ok([
        'source' => 'ga4',
        'rows'   => $result['rows'],
        'totals' => (object) $result['totals'],
        'status' => $result['status'],
    ]);
}
