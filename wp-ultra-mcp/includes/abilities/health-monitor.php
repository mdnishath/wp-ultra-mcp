<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

// Defensive engine require so this ability works regardless of load order
// (mirrors email-campaign → includes/marketing/campaigns.php).
if (!function_exists('wpultra_health_defaults') && defined('WPULTRA_DIR') && is_readable(WPULTRA_DIR . 'includes/system/health.php')) {
    require_once WPULTRA_DIR . 'includes/system/health.php';
}

wp_register_ability('wpultra/health-monitor', [
    'label'       => __('Uptime + Health Monitor', 'wp-ultra-mcp'),
    'description' => __(
        'Scheduled uptime + health checks with alert-on-change email/webhook notifications. Five checks: '
        . 'http (wp_safe_remote_get each configured url, timeout 10s, max 3 redirects: 2xx/3xx = ok, 4xx = warn, 5xx or transport error = fail; an optional keyword substring is required in the FIRST url\'s body — missing keyword = fail "keyword_missing"; up to 5 urls, external urls allowed), '
        . 'ssl (live certificate probe of the first https url: fail when expired or expiring in under 3 days, warn when expiring within ssl_warn_days [default 14]; skipped harmlessly when no https url or openssl is unavailable), '
        . 'disk (free-space percentage at ABSPATH: warn below disk_min_free_pct [default 10%], fail below half of it; hosts that disable disk functions report ok with a note), '
        . 'php_errors (fatal PHP error reports captured by the self-healing error log in the last 24h: warn at 1+, fail at 5+), '
        . 'cron (WP-Cron events overdue by more than 15 minutes: warn at 5+, fail at 20+). '
        . 'Alert model: per-check statuses are compared with the previous run and alerts fire ONLY on state transitions (ok→warn/fail = degraded, warn/fail→ok = recovered) — a persistently broken check alerts once, not every interval. Alerts go to alert_email (plain-text, subject "[host] health: <overall>") and/or alert_webhook (https only; JSON POST {site, overall, transitions, results}, non-blocking). '
        . 'Actions: config {enabled?, interval? hourly|twicedaily|daily, checks? {http, ssl, disk, php_errors, cron}, urls? [max 5 http(s)], keyword?, disk_min_free_pct? 1-50, ssl_warn_days? 1-90, alert_email?, alert_webhook?} — validates, merges into the saved config and returns it (urls default to the site home page when left empty; the cron schedule reconciles on the NEXT request via the plugins_loaded boot). With no fields it just returns the current config. '
        . 'run-now — execute all enabled checks immediately and return {results, overall, transitions, alerted}. '
        . 'status — last run results + overall + next scheduled run timestamp. '
        . 'history {limit? default 20, max 50} — newest-first ring of past runs {ts, overall, map}. '
        . 'Examples: {"action":"config","enabled":true,"interval":"hourly","alert_email":"ops@example.com"} · {"action":"config","urls":["https://example.com/","https://example.com/shop/"],"keyword":"Add to cart"} · {"action":"run-now"} · {"action":"history","limit":10}. '
        . 'CAUTION for local/dev environments: the http check fetches the configured urls from within PHP — on single-worker dev servers (e.g. `php -S`, some Local/XAMPP setups) fetching the site\'s OWN url from inside a request can deadlock. On such environments point urls at an external site, or rely on real cron runs (wp-cron via system scheduler) instead of run-now. Config changes are reversible ({"action":"config","enabled":false} turns monitoring off and clears the schedule).',
        'wp-ultra-mcp'
    ),
    'category'    => 'diagnostics',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['config', 'status', 'run-now', 'history'],
            ],
            'enabled'  => ['type' => 'boolean'],
            'interval' => ['type' => 'string', 'enum' => ['hourly', 'twicedaily', 'daily']],
            'checks'   => [
                'type'       => 'object',
                'properties' => [
                    'http'       => ['type' => 'boolean'],
                    'ssl'        => ['type' => 'boolean'],
                    'disk'       => ['type' => 'boolean'],
                    'php_errors' => ['type' => 'boolean'],
                    'cron'       => ['type' => 'boolean'],
                ],
                'additionalProperties' => false,
            ],
            'urls'              => ['type' => 'array', 'items' => ['type' => 'string']],
            'keyword'           => ['type' => 'string'],
            'disk_min_free_pct' => ['type' => 'integer'],
            'ssl_warn_days'     => ['type' => 'integer'],
            'alert_email'       => ['type' => 'string'],
            'alert_webhook'     => ['type' => 'string'],
            'limit'             => ['type' => 'integer'],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'     => ['type' => 'boolean'],
            'action'      => ['type' => 'string'],
            'config'      => ['type' => 'object'],
            'note'        => ['type' => 'string'],
            'overall'     => ['type' => 'string'],
            'results'     => ['type' => 'array'],
            'transitions' => ['type' => 'array'],
            'alerted'     => ['type' => 'boolean'],
            'last'        => ['type' => 'object'],
            'next_run'    => ['type' => 'integer'],
            'history'     => ['type' => 'array'],
        ],
        'required' => ['success', 'action'],
    ],
    'execute_callback'    => 'wpultra_health_monitor_ability',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
    ],
]);

/** @return array|WP_Error */
function wpultra_health_monitor_ability(array $input) {
    if (!function_exists('wpultra_health_defaults')) {
        return wpultra_err('wpultra_health_engine_missing', 'Health engine not loaded.');
    }

    $action = (string) ($input['action'] ?? 'status');

    switch ($action) {
        case 'config': {
            $updates = [];
            foreach (['enabled', 'interval', 'checks', 'urls', 'keyword', 'alert_email', 'alert_webhook'] as $k) {
                if (array_key_exists($k, $input)) { $updates[$k] = $input[$k]; }
            }
            foreach (['disk_min_free_pct', 'ssl_warn_days'] as $k) {
                if (array_key_exists($k, $input)) { $updates[$k] = (int) $input[$k]; }
            }
            if (isset($updates['enabled'])) { $updates['enabled'] = (bool) $updates['enabled']; }
            if (isset($updates['urls'])) {
                $updates['urls'] = array_values(array_map('strval', (array) $updates['urls']));
            }

            if ($updates === []) {
                // Read-only: just report the current config.
                return wpultra_ok([
                    'action' => 'config',
                    'config' => wpultra_health_config(),
                ]);
            }

            $saved = wpultra_health_save_config($updates);
            if (is_string($saved)) {
                wpultra_audit_log('health-monitor', 'config rejected: ' . $saved, false);
                return wpultra_err('wpultra_health_invalid_config', $saved);
            }

            wpultra_audit_log('health-monitor', 'config updated: enabled=' . ($saved['enabled'] ? '1' : '0') . ' interval=' . $saved['interval'], true);
            return wpultra_ok([
                'action' => 'config',
                'config' => $saved,
                'note'   => 'Saved. The cron schedule reconciles on the next request (plugins_loaded boot). Reversible: {"action":"config","enabled":false} disables monitoring.',
            ]);
        }

        case 'run-now': {
            $run = wpultra_health_run();
            wpultra_audit_log('health-monitor', 'run-now overall=' . $run['overall'] . ' transitions=' . count($run['transitions']) . ' alerted=' . ($run['alerted'] ? '1' : '0'), true);
            return wpultra_ok([
                'action'      => 'run-now',
                'overall'     => $run['overall'],
                'results'     => $run['results'],
                'transitions' => $run['transitions'],
                'alerted'     => $run['alerted'],
            ]);
        }

        case 'status': {
            $cfg = wpultra_health_config();
            $last = wpultra_health_last();
            $next = function_exists('wp_next_scheduled') ? wp_next_scheduled(WPULTRA_HEALTH_EVENT) : false;
            return wpultra_ok([
                'action'   => 'status',
                'config'   => $cfg,
                'overall'  => (string) ($last['overall'] ?? 'unknown'),
                'last'     => $last,
                'next_run' => is_int($next) ? $next : 0,
            ]);
        }

        case 'history': {
            $limit = isset($input['limit']) ? max(1, min(50, (int) $input['limit'])) : 20;
            return wpultra_ok([
                'action'  => 'history',
                'history' => wpultra_health_history($limit),
            ]);
        }
    }

    return wpultra_err('wpultra_health_bad_action', 'Unknown action: ' . $action . ' (use config, status, run-now or history).');
}
