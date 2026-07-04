<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

// Defensive engine require so this ability works regardless of load order
// (mirrors health-monitor → includes/system/health.php).
if (!function_exists('wpultra_reports_defaults') && defined('WPULTRA_DIR') && is_readable(WPULTRA_DIR . 'includes/system/reports.php')) {
    require_once WPULTRA_DIR . 'includes/system/reports.php';
}

wp_register_ability('wpultra/scheduled-reports', [
    'label'       => __('Scheduled Reports', 'wp-ultra-mcp'),
    'description' => __(
        'Recurring site / store / SEO / health / analytics digest emailed to the owner on a schedule (roadmap G4). '
        . 'A report gathers up to five OPTIONAL sections from data sources that already exist in the plugin — each missing source omits its section with a note, never a fatal: '
        . 'content (core wp_count_posts / wp_count_comments: published/draft/pending posts, pages, approved + pending comments), '
        . 'store (WooCommerce order count + gross/net revenue in the reporting window via the woo reports engine / wc_get_orders, HPOS-safe; omitted when WooCommerce is inactive), '
        . 'seo (an SEO health score + issue/duplicate/orphan counts derived from the site SEO audit), '
        . 'health (overall + per-check status from the uptime/health monitor run), '
        . 'analytics (best-effort GA4 sessions/users totals when Google Site Kit is connected; skipped otherwise). '
        . 'The report renders to a clean inline-CSS HTML email (default) or plain text and is delivered with wp_mail to every recipient (default: the site admin_email). A newest-first history ring (cap 12) is kept so successive reports could show up/down deltas. '
        . 'Schedule: weekly (default), monthly, or daily — reconciled to WP-Cron on the NEXT request via the plugins_loaded boot (custom weekly/monthly schedules are registered). '
        . 'Actions: '
        . 'config {enabled?, recurrence? weekly|monthly|daily, recipients? [emails], sections? {content, store, seo, health, analytics}, format? html|text} — validates (recurrence enum, each recipient a valid email, format enum), merges into the saved config and returns it (recipients default to admin_email when empty; reversible — {"action":"config","enabled":false} turns reports off and clears the schedule). With no fields it just returns the current config. '
        . 'run-now {dry?} — build the report from REAL current data and (unless dry:true) email it now; dry:true returns the built report WITHOUT sending (safe preview of real data). '
        . 'preview — build the report and return its rendered HTML string WITHOUT sending (trust-builder; never emails). '
        . 'send-test {email, confirm:true} — email the freshly-built report to ONE address (confirm-gated because it sends a real email). '
        . 'status — current config + last_run / last_sent + next scheduled run timestamp. '
        . 'history {limit? default 12, max 12} — newest-first ring of past sends {ts, subject, recipients, sent, format}. '
        . 'Examples: {"action":"config","enabled":true,"recurrence":"weekly","recipients":["owner@example.com"]} · {"action":"config","sections":{"analytics":true}} · {"action":"run-now","dry":true} · {"action":"preview"} · {"action":"send-test","email":"owner@example.com","confirm":true} · {"action":"history","limit":5}.',
        'wp-ultra-mcp'
    ),
    'category'    => 'diagnostics',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['config', 'status', 'run-now', 'preview', 'history', 'send-test'],
            ],
            'enabled'    => ['type' => 'boolean'],
            'recurrence' => ['type' => 'string', 'enum' => ['weekly', 'monthly', 'daily']],
            'recipients' => ['type' => 'array', 'items' => ['type' => 'string']],
            'sections'   => [
                'type'       => 'object',
                'properties' => [
                    'content'   => ['type' => 'boolean'],
                    'store'     => ['type' => 'boolean'],
                    'seo'       => ['type' => 'boolean'],
                    'health'    => ['type' => 'boolean'],
                    'analytics' => ['type' => 'boolean'],
                ],
                'additionalProperties' => false,
            ],
            'format'  => ['type' => 'string', 'enum' => ['html', 'text']],
            'dry'     => ['type' => 'boolean'],
            'email'   => ['type' => 'string'],
            'confirm' => ['type' => 'boolean'],
            'limit'   => ['type' => 'integer'],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'    => ['type' => 'boolean'],
            'action'     => ['type' => 'string'],
            'config'     => ['type' => 'object'],
            'note'       => ['type' => 'string'],
            'report'     => ['type' => 'object'],
            'html'       => ['type' => 'string'],
            'dry'        => ['type' => 'boolean'],
            'sent'       => ['type' => 'integer'],
            'recipients' => ['type' => 'array'],
            'subject'    => ['type' => 'string'],
            'last_run'   => ['type' => 'integer'],
            'last_sent'  => ['type' => 'integer'],
            'next_run'   => ['type' => 'integer'],
            'history'    => ['type' => 'array'],
        ],
        'required' => ['success', 'action'],
    ],
    'execute_callback'    => 'wpultra_scheduled_reports_ability',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

/** @return array|WP_Error */
function wpultra_scheduled_reports_ability(array $input) {
    if (!function_exists('wpultra_reports_defaults')) {
        return wpultra_err('wpultra_reports_engine_missing', 'Reports engine not loaded.');
    }

    $action = (string) ($input['action'] ?? 'status');

    switch ($action) {
        case 'config': {
            $updates = [];
            foreach (['enabled', 'recurrence', 'sections', 'format'] as $k) {
                if (array_key_exists($k, $input)) { $updates[$k] = $input[$k]; }
            }
            if (isset($updates['enabled'])) { $updates['enabled'] = (bool) $updates['enabled']; }
            if (array_key_exists('recipients', $input)) {
                $updates['recipients'] = array_values(array_map('strval', (array) $input['recipients']));
            }

            if ($updates === []) {
                return wpultra_ok([
                    'action' => 'config',
                    'config' => wpultra_reports_config(),
                ]);
            }

            $saved = wpultra_reports_save_config($updates);
            if (is_string($saved)) {
                wpultra_audit_log('scheduled-reports', 'config rejected: ' . $saved, false);
                return wpultra_err('wpultra_reports_invalid_config', $saved);
            }

            wpultra_audit_log('scheduled-reports', 'config updated: enabled=' . ($saved['enabled'] ? '1' : '0') . ' recurrence=' . $saved['recurrence'], true);
            return wpultra_ok([
                'action' => 'config',
                'config' => $saved,
                'note'   => 'Saved. The cron schedule reconciles on the next request (plugins_loaded boot). Reversible: {"action":"config","enabled":false} disables reports.',
            ]);
        }

        case 'run-now': {
            $dry = !empty($input['dry']);
            $run = wpultra_reports_run($dry);
            wpultra_audit_log('scheduled-reports', 'run-now dry=' . ($dry ? '1' : '0') . ' sent=' . (int) ($run['sent'] ?? 0), true);
            $out = ['action' => 'run-now', 'dry' => (bool) $run['dry'], 'report' => $run['report']];
            if (!$dry) {
                $out['sent'] = (int) ($run['sent'] ?? 0);
                $out['recipients'] = $run['recipients'] ?? [];
                $out['subject'] = (string) ($run['subject'] ?? '');
            } else {
                $out['note'] = 'Dry run: report built from real data but NOT emailed.';
            }
            return wpultra_ok($out);
        }

        case 'preview': {
            $cfg = wpultra_reports_config();
            $window = wpultra_reports_period((string) $cfg['recurrence'], time());
            $report = wpultra_reports_build($cfg['sections'], $window);
            return wpultra_ok([
                'action'  => 'preview',
                'report'  => $report,
                'subject' => wpultra_reports_subject($report),
                'html'    => wpultra_reports_render_html($report),
                'note'    => 'Preview only — nothing was emailed.',
            ]);
        }

        case 'send-test': {
            $email = trim((string) ($input['email'] ?? ''));
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return wpultra_err('wpultra_reports_bad_email', 'A valid "email" address is required for send-test.');
            }
            if (empty($input['confirm'])) {
                return wpultra_err('wpultra_reports_confirm_required', 'send-test emails a real message. Re-run with "confirm": true to proceed.');
            }
            $cfg = wpultra_reports_config();
            $window = wpultra_reports_period((string) $cfg['recurrence'], time());
            $report = wpultra_reports_build($cfg['sections'], $window);
            $send = wpultra_reports_send($report, [$email], (string) $cfg['format']);
            wpultra_audit_log('scheduled-reports', 'send-test to ' . $email . ' sent=' . (int) $send['sent'], (int) $send['sent'] > 0);
            return wpultra_ok([
                'action'     => 'send-test',
                'sent'       => (int) $send['sent'],
                'recipients' => $send['recipients'],
                'subject'    => $send['subject'],
                'note'       => (int) $send['sent'] > 0 ? 'Test report emailed.' : 'wp_mail reported no delivery (check the site mailer).',
            ]);
        }

        case 'status': {
            $cfg = wpultra_reports_config();
            $next = function_exists('wp_next_scheduled') ? wp_next_scheduled(WPULTRA_REPORTS_EVENT) : false;
            return wpultra_ok([
                'action'    => 'status',
                'config'    => $cfg,
                'last_run'  => (int) ($cfg['last_run'] ?? 0),
                'last_sent' => (int) ($cfg['last_sent'] ?? 0),
                'next_run'  => is_int($next) ? $next : 0,
            ]);
        }

        case 'history': {
            $limit = isset($input['limit']) ? max(1, min(WPULTRA_REPORTS_HISTORY_CAP, (int) $input['limit'])) : WPULTRA_REPORTS_HISTORY_CAP;
            // Strip the bulky embedded report from each history entry for a shaped response.
            $hist = array_map(static function ($e) {
                if (is_array($e)) { unset($e['report']); }
                return $e;
            }, wpultra_reports_history($limit));
            return wpultra_ok([
                'action'  => 'history',
                'history' => $hist,
            ]);
        }
    }

    return wpultra_err('wpultra_reports_bad_action', 'Unknown action: ' . $action . ' (use config, status, run-now, preview, history or send-test).');
}
