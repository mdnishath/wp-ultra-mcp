<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Scheduled reports (roadmap G4).
 *
 * A weekly (or daily / monthly) site + store + SEO + health digest emailed to
 * the owner. Each report gathers a set of enabled sections from data sources
 * that ALREADY exist elsewhere in the plugin — content (core wp_count_posts /
 * wp_count_comments), store (the WooCommerce reports engine / wc_get_orders,
 * HPOS-safe), SEO (wpultra_seo_site_audit), health (wpultra_health_run) and
 * analytics (wpultra_analytics_* when Site Kit is connected). Every source is
 * OPTIONAL: a missing source omits its section with a note, never a fatal.
 *
 * The report is rendered to a clean inline-CSS HTML email (or plain text) and
 * delivered with wp_mail to each recipient. A newest-first history ring
 * (cap 12) is kept so successive reports can show up/down deltas ("posts +3,
 * orders -2").
 *
 * PURE functions first (prefix wpultra_reports_, no WordPress — unit-tested in
 * tests/reports.test.php), thin WP wrappers after. The controller calls
 * wpultra_reports_boot() on plugins_loaded; boot registers the cron handler and
 * cheaply reconciles the recurring event against the config (marker option
 * `wpultra_reports_sched`).
 *
 * NOTE: this engine's prefix wpultra_reports_ is intentionally distinct from the
 * WooCommerce reports engine (wpultra_woo_*) so the two never collide.
 */

const WPULTRA_REPORTS_OPTION         = 'wpultra_reports';
const WPULTRA_REPORTS_HISTORY_OPTION = 'wpultra_reports_history';
const WPULTRA_REPORTS_SCHED_MARKER   = 'wpultra_reports_sched';
const WPULTRA_REPORTS_EVENT          = 'wpultra_reports_cron';
const WPULTRA_REPORTS_HISTORY_CAP    = 12;

/** Recurrence → the WP-Cron schedule slug it maps to. */
const WPULTRA_REPORTS_RECURRENCE_SCHEDULE = [
    'daily'   => 'daily',
    'weekly'  => 'weekly',
    'monthly' => 'monthly',
];

/* ===================================================================== *
 * PURE core — no WordPress calls. Everything here is unit-testable.
 * ===================================================================== */

/** Default configuration shape for the `wpultra_reports` option. Pure. */
function wpultra_reports_defaults(): array {
    return [
        'enabled'    => false,
        'recurrence' => 'weekly', // weekly | monthly | daily
        'recipients' => [],       // filled with [admin_email] at save when empty
        'sections'   => [
            'content'   => true,
            'store'     => true,
            'seo'       => true,
            'health'    => true,
            'analytics' => false, // best-effort; off by default (needs Site Kit)
        ],
        'format'   => 'html',     // html | text
        'last_run' => 0,
        'last_sent' => 0,
    ];
}

/** The section keys a report understands, in render order. Pure. */
function wpultra_reports_section_keys(): array {
    return ['content', 'store', 'seo', 'health', 'analytics'];
}

/**
 * Validate a full (defaults-merged) config. Pure.
 *
 * @return true|string true when valid, else a human-readable error string.
 */
function wpultra_reports_validate(array $cfg) {
    if (isset($cfg['enabled']) && !is_bool($cfg['enabled'])) {
        return 'enabled must be a boolean';
    }
    if (!in_array($cfg['recurrence'] ?? '', ['daily', 'weekly', 'monthly'], true)) {
        return 'recurrence must be one of: daily, weekly, monthly';
    }
    $recipients = $cfg['recipients'] ?? [];
    if (!is_array($recipients)) {
        return 'recipients must be an array of email addresses';
    }
    foreach ($recipients as $r) {
        if (!is_string($r) || $r === '') {
            return 'recipients entries must be non-empty email strings';
        }
        if (filter_var($r, FILTER_VALIDATE_EMAIL) === false) {
            return "invalid recipient email: $r";
        }
    }
    if (isset($cfg['sections']) && !is_array($cfg['sections'])) {
        return 'sections must be an object of booleans';
    }
    if (!in_array($cfg['format'] ?? '', ['html', 'text'], true)) {
        return 'format must be one of: html, text';
    }
    return true;
}

/**
 * The reporting window for a recurrence, ending at $now. Pure.
 * Returns {from, to, label} — from/to are unix timestamps, label is a short
 * human string like "weekly" used in the subject and header.
 */
function wpultra_reports_period(string $recurrence, int $now): array {
    switch ($recurrence) {
        case 'daily':
            $from = $now - 86400;
            $label = 'daily';
            break;
        case 'monthly':
            $from = $now - (30 * 86400);
            $label = 'monthly';
            break;
        case 'weekly':
        default:
            $from = $now - (7 * 86400);
            $label = 'weekly';
            break;
    }
    return ['from' => $from, 'to' => $now, 'label' => $label];
}

/** Format a unix timestamp as a UTC Y-m-d date. Pure. */
function wpultra_reports_fmt_date(int $ts): string {
    return gmdate('Y-m-d', $ts);
}

/** The email subject line "[Site] weekly report — <date>". Pure. */
function wpultra_reports_subject(array $report): string {
    $site = (string) ($report['site'] ?? 'Site');
    $label = (string) (($report['period']['label'] ?? 'weekly'));
    $to = (int) ($report['period']['to'] ?? 0);
    $date = $to > 0 ? wpultra_reports_fmt_date($to) : gmdate('Y-m-d');
    return sprintf('[%s] %s report — %s', $site, $label, $date);
}

/**
 * Render a report to a clean inline-CSS HTML email. Pure — every dynamic value
 * is escaped so a hostile site name / section value can't inject markup.
 */
function wpultra_reports_render_html(array $report): string {
    $esc = static function ($v): string {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    };
    $site = (string) ($report['site'] ?? 'Site');
    $label = (string) (($report['period']['label'] ?? 'weekly'));
    $from = (int) ($report['period']['from'] ?? 0);
    $to = (int) ($report['period']['to'] ?? 0);
    $range = $esc(wpultra_reports_fmt_date($from)) . ' &rarr; ' . $esc(wpultra_reports_fmt_date($to));

    $h = [];
    $h[] = '<div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;max-width:640px;margin:0 auto;color:#1f2933;">';
    $h[] = '<div style="background:#0b5cad;color:#fff;padding:20px 24px;border-radius:8px 8px 0 0;">';
    $h[] = '<h1 style="margin:0;font-size:20px;line-height:1.3;">' . $esc($site) . '</h1>';
    $h[] = '<p style="margin:4px 0 0;font-size:13px;opacity:.9;">' . $esc(ucfirst($label)) . ' report &middot; ' . $range . '</p>';
    $h[] = '</div>';
    $h[] = '<div style="border:1px solid #e4e7eb;border-top:0;border-radius:0 0 8px 8px;padding:8px 24px 24px;">';

    $sections = is_array($report['sections'] ?? null) ? $report['sections'] : [];
    if ($sections === []) {
        $h[] = '<p style="color:#616e7c;font-size:14px;">No sections available for this report.</p>';
    }
    foreach ($sections as $section) {
        if (!is_array($section)) { continue; }
        $title = (string) ($section['title'] ?? ($section['key'] ?? 'Section'));
        $h[] = '<h2 style="font-size:16px;margin:24px 0 8px;color:#0b5cad;">' . $esc($title) . '</h2>';
        $note = (string) ($section['note'] ?? '');
        if ($note !== '') {
            $h[] = '<p style="margin:0 0 8px;color:#616e7c;font-size:13px;font-style:italic;">' . $esc($note) . '</p>';
        }
        $rows = is_array($section['rows'] ?? null) ? $section['rows'] : [];
        if ($rows !== []) {
            $h[] = '<table style="width:100%;border-collapse:collapse;font-size:14px;">';
            foreach ($rows as $row) {
                if (!is_array($row)) { continue; }
                $labelCell = $esc($row[0] ?? '');
                $valueCell = $esc($row[1] ?? '');
                $h[] = '<tr>'
                    . '<td style="padding:6px 8px;border-bottom:1px solid #f0f2f5;color:#3e4c59;">' . $labelCell . '</td>'
                    . '<td style="padding:6px 8px;border-bottom:1px solid #f0f2f5;text-align:right;font-weight:600;">' . $valueCell . '</td>'
                    . '</tr>';
            }
            $h[] = '</table>';
        }
    }

    $gen = (int) ($report['generated_at'] ?? 0);
    $h[] = '<p style="margin:24px 0 0;color:#9aa5b1;font-size:12px;">Generated ' . $esc($gen > 0 ? gmdate('Y-m-d H:i', $gen) . ' UTC' : 'now') . ' by WP-Ultra-MCP.</p>';
    $h[] = '</div></div>';
    return implode("\n", $h);
}

/** Render a report to a plain-text email (no HTML tags). Pure. */
function wpultra_reports_render_text(array $report): string {
    $site = (string) ($report['site'] ?? 'Site');
    $label = (string) (($report['period']['label'] ?? 'weekly'));
    $from = (int) ($report['period']['from'] ?? 0);
    $to = (int) ($report['period']['to'] ?? 0);

    $lines = [];
    $lines[] = $site . ' — ' . ucfirst($label) . ' report';
    $lines[] = wpultra_reports_fmt_date($from) . ' to ' . wpultra_reports_fmt_date($to);
    $lines[] = str_repeat('=', 48);

    $sections = is_array($report['sections'] ?? null) ? $report['sections'] : [];
    if ($sections === []) {
        $lines[] = '';
        $lines[] = 'No sections available for this report.';
    }
    foreach ($sections as $section) {
        if (!is_array($section)) { continue; }
        $title = (string) ($section['title'] ?? ($section['key'] ?? 'Section'));
        $lines[] = '';
        $lines[] = strtoupper($title);
        $note = (string) ($section['note'] ?? '');
        if ($note !== '') { $lines[] = '(' . $note . ')'; }
        $rows = is_array($section['rows'] ?? null) ? $section['rows'] : [];
        foreach ($rows as $row) {
            if (!is_array($row)) { continue; }
            $lines[] = '  ' . (string) ($row[0] ?? '') . ': ' . (string) ($row[1] ?? '');
        }
    }

    $gen = (int) ($report['generated_at'] ?? 0);
    $lines[] = '';
    $lines[] = str_repeat('-', 48);
    $lines[] = 'Generated ' . ($gen > 0 ? gmdate('Y-m-d H:i', $gen) . ' UTC' : 'now') . ' by WP-Ultra-MCP.';
    return implode("\n", $lines);
}

/**
 * Compute up/down/new/removed deltas of numeric rows between the current report
 * and a previous one, matched by section key + row label. Pure.
 *
 * @return array map "section.label" => {label, section, from, to, delta, dir}
 *               dir is 'up' | 'down' | 'same' | 'new'; a row present in $prev
 *               but absent in $curr is emitted with dir 'removed'.
 */
function wpultra_reports_delta(array $curr, array $prev): array {
    $index = static function (array $report): array {
        $out = [];
        foreach (($report['sections'] ?? []) as $section) {
            if (!is_array($section)) { continue; }
            $skey = (string) ($section['key'] ?? ($section['title'] ?? ''));
            foreach (($section['rows'] ?? []) as $row) {
                if (!is_array($row) || !array_key_exists(0, $row)) { continue; }
                $label = (string) $row[0];
                $raw = $row[1] ?? null;
                if (!is_numeric($raw)) { continue; }
                $out[$skey . '.' . $label] = [
                    'section' => $skey,
                    'label'   => $label,
                    'value'   => (float) $raw,
                ];
            }
        }
        return $out;
    };

    $c = $index($curr);
    $p = $index($prev);
    $out = [];

    foreach ($c as $key => $cur) {
        if (isset($p[$key])) {
            $delta = $cur['value'] - $p[$key]['value'];
            $dir = $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'same');
            $out[$key] = [
                'section' => $cur['section'],
                'label'   => $cur['label'],
                'from'    => $p[$key]['value'],
                'to'      => $cur['value'],
                'delta'   => $delta,
                'dir'     => $dir,
            ];
        } else {
            $out[$key] = [
                'section' => $cur['section'],
                'label'   => $cur['label'],
                'from'    => null,
                'to'      => $cur['value'],
                'delta'   => $cur['value'],
                'dir'     => 'new',
            ];
        }
    }
    foreach ($p as $key => $old) {
        if (!isset($c[$key])) {
            $out[$key] = [
                'section' => $old['section'],
                'label'   => $old['label'],
                'from'    => $old['value'],
                'to'      => null,
                'delta'   => -$old['value'],
                'dir'     => 'removed',
            ];
        }
    }
    return $out;
}

/** Prepend an entry to the history ring (newest first) and cap it. Pure. */
function wpultra_reports_history_push(array $hist, array $entry, int $cap = WPULTRA_REPORTS_HISTORY_CAP): array {
    array_unshift($hist, $entry);
    if ($cap > 0 && count($hist) > $cap) { $hist = array_slice($hist, 0, $cap); }
    return array_values($hist);
}

/* ===================================================================== *
 * WordPress wrappers — config store.
 * ===================================================================== */

/** Load the saved config merged over defaults (sections deep-merged). */
function wpultra_reports_config(): array {
    $defaults = wpultra_reports_defaults();
    $saved = function_exists('get_option') ? get_option(WPULTRA_REPORTS_OPTION, []) : [];
    if (!is_array($saved)) { $saved = []; }
    $cfg = array_merge($defaults, $saved);
    $cfg['sections'] = array_merge(
        $defaults['sections'],
        is_array($saved['sections'] ?? null) ? $saved['sections'] : []
    );
    if (!is_array($cfg['recipients'])) { $cfg['recipients'] = []; }
    return $cfg;
}

/**
 * Merge $updates into the current config, default recipients to the admin
 * email, validate and persist (autoloaded — boot reads it every request).
 *
 * @return array|string the saved config, or a validation error string.
 */
function wpultra_reports_save_config(array $updates) {
    $cfg = wpultra_reports_config();
    $defaults = wpultra_reports_defaults();

    foreach (['enabled', 'recurrence', 'recipients', 'format'] as $k) {
        if (array_key_exists($k, $updates)) { $cfg[$k] = $updates[$k]; }
    }
    if (isset($updates['sections']) && is_array($updates['sections'])) {
        foreach (array_keys($defaults['sections']) as $s) {
            if (array_key_exists($s, $updates['sections'])) {
                $cfg['sections'][$s] = (bool) $updates['sections'][$s];
            }
        }
    }

    // Default recipients to the site admin email when left empty.
    if ((!is_array($cfg['recipients']) || $cfg['recipients'] === []) && function_exists('get_option')) {
        $admin = (string) get_option('admin_email', '');
        if ($admin !== '') { $cfg['recipients'] = [$admin]; }
    }

    $valid = wpultra_reports_validate($cfg);
    if ($valid !== true) { return $valid; }

    if (function_exists('update_option')) {
        update_option(WPULTRA_REPORTS_OPTION, $cfg, true);
    }
    return $cfg;
}

/** History ring, newest first, sliced to $limit. */
function wpultra_reports_history(int $limit = WPULTRA_REPORTS_HISTORY_CAP): array {
    $v = function_exists('get_option') ? get_option(WPULTRA_REPORTS_HISTORY_OPTION, []) : [];
    if (!is_array($v)) { $v = []; }
    $limit = max(1, min(WPULTRA_REPORTS_HISTORY_CAP, $limit));
    return count($v) > $limit ? array_slice($v, 0, $limit) : array_values($v);
}

/** The most recent stored report (for delta comparison), or []. */
function wpultra_reports_last_report(): array {
    $hist = wpultra_reports_history(1);
    $first = $hist[0] ?? null;
    if (is_array($first) && is_array($first['report'] ?? null)) { return $first['report']; }
    return [];
}

/* ===================================================================== *
 * WordPress wrappers — section gatherers. Each returns a section array
 * {key, title, rows:[[label,value]], note?} or null when unavailable.
 * ===================================================================== */

/** Content section — post counts by status + comments (core, always available). */
function wpultra_reports_section_content(array $window): ?array {
    if (!function_exists('wp_count_posts')) {
        return ['key' => 'content', 'title' => 'Content', 'rows' => [], 'note' => 'unavailable (WordPress core counters missing)'];
    }
    $rows = [];
    $posts = wp_count_posts('post');
    if (is_object($posts)) {
        $rows[] = ['Published posts', (int) ($posts->publish ?? 0)];
        $rows[] = ['Draft posts', (int) ($posts->draft ?? 0)];
        if (isset($posts->pending)) { $rows[] = ['Pending posts', (int) $posts->pending]; }
    }
    $pages = wp_count_posts('page');
    if (is_object($pages)) {
        $rows[] = ['Published pages', (int) ($pages->publish ?? 0)];
    }
    if (function_exists('wp_count_comments')) {
        $comments = wp_count_comments();
        if (is_object($comments)) {
            $rows[] = ['Approved comments', (int) ($comments->approved ?? 0)];
            $rows[] = ['Comments awaiting moderation', (int) ($comments->moderated ?? 0)];
        }
    }
    return ['key' => 'content', 'title' => 'Content', 'rows' => $rows];
}

/**
 * Store section — WooCommerce order count + revenue in the window. HPOS-safe
 * (delegates to the woo reports engine / wc_get_orders). Omitted when Woo is
 * inactive.
 */
function wpultra_reports_section_store(array $window): ?array {
    $active = function_exists('wpultra_woo_active') ? wpultra_woo_active() : (function_exists('wc_get_orders'));
    if (!$active) {
        return null; // Woo not installed — section omitted entirely.
    }
    $from = wpultra_reports_fmt_date((int) ($window['from'] ?? 0));
    $to = wpultra_reports_fmt_date((int) ($window['to'] ?? time()));
    $rows = [];
    $note = '';

    if (function_exists('wpultra_woo_get_reports')) {
        try {
            $rep = wpultra_woo_get_reports(['type' => 'sales', 'date_from' => $from, 'date_to' => $to]);
            if (is_array($rep)) {
                $money = $rep['money'] ?? $rep;
                $rows[] = ['Orders', (int) ($money['order_count'] ?? ($rep['order_count'] ?? 0))];
                if (isset($money['gross'])) { $rows[] = ['Gross revenue', number_format((float) $money['gross'], 2)]; }
                if (isset($money['net'])) { $rows[] = ['Net revenue', number_format((float) $money['net'], 2)]; }
            }
        } catch (\Throwable $e) {
            $note = 'store report error: ' . $e->getMessage();
        }
    } elseif (function_exists('wc_get_orders')) {
        try {
            $ids = wc_get_orders([
                'limit'        => -1,
                'return'       => 'ids',
                'status'       => ['wc-processing', 'wc-completed', 'wc-on-hold'],
                'date_created' => $from . '...' . $to,
            ]);
            $rows[] = ['Orders', is_array($ids) ? count($ids) : 0];
        } catch (\Throwable $e) {
            $note = 'store report error: ' . $e->getMessage();
        }
    }

    $section = ['key' => 'store', 'title' => 'Store', 'rows' => $rows];
    if ($note !== '') { $section['note'] = $note; }
    return $section;
}

/** SEO section — health score derived from wpultra_seo_site_audit. Omitted when absent. */
function wpultra_reports_section_seo(array $window): ?array {
    if (!function_exists('wpultra_seo_site_audit')) {
        return null;
    }
    try {
        $audit = wpultra_seo_site_audit(200);
    } catch (\Throwable $e) {
        return ['key' => 'seo', 'title' => 'SEO', 'rows' => [], 'note' => 'seo audit error: ' . $e->getMessage()];
    }
    if (!is_array($audit)) {
        return ['key' => 'seo', 'title' => 'SEO', 'rows' => [], 'note' => 'seo audit returned no data'];
    }
    $scanned = (int) ($audit['scanned'] ?? 0);
    $issueCounts = is_array($audit['issue_counts'] ?? null) ? $audit['issue_counts'] : [];
    $totalIssues = array_sum(array_map('intval', $issueCounts));
    $dupes = is_array($audit['duplicate_titles'] ?? null) ? count($audit['duplicate_titles']) : 0;
    $orphans = is_array($audit['orphans'] ?? null) ? count($audit['orphans']) : 0;
    // Simple health score: 100 minus issues-per-post penalty, floored at 0.
    $score = $scanned > 0 ? max(0, (int) round(100 - ($totalIssues / $scanned) * 25)) : 100;

    $rows = [
        ['SEO health score', $score . ' / 100'],
        ['Posts scanned', $scanned],
        ['Total issues', $totalIssues],
        ['Duplicate titles', $dupes],
        ['Orphaned content', $orphans],
    ];
    return ['key' => 'seo', 'title' => 'SEO', 'rows' => $rows];
}

/** Health section — overall + per-check statuses from wpultra_health_run. Omitted when absent. */
function wpultra_reports_section_health(array $window): ?array {
    if (!function_exists('wpultra_health_run')) {
        return null;
    }
    try {
        $run = wpultra_health_run();
    } catch (\Throwable $e) {
        return ['key' => 'health', 'title' => 'Health', 'rows' => [], 'note' => 'health run error: ' . $e->getMessage()];
    }
    if (!is_array($run)) {
        return ['key' => 'health', 'title' => 'Health', 'rows' => [], 'note' => 'health run returned no data'];
    }
    $rows = [['Overall status', (string) ($run['overall'] ?? 'unknown')]];
    foreach (($run['results'] ?? []) as $r) {
        if (!is_array($r)) { continue; }
        $rows[] = [(string) ($r['check'] ?? 'check'), (string) ($r['status'] ?? '?')];
    }
    return ['key' => 'health', 'title' => 'Health', 'rows' => $rows];
}

/** Analytics section — GA4 sessions/users best-effort when Site Kit connected. Omitted otherwise. */
function wpultra_reports_section_analytics(array $window): ?array {
    if (!function_exists('wpultra_analytics_status') || !function_exists('wpultra_analytics_report')) {
        return null;
    }
    $status = wpultra_analytics_status();
    if (empty($status['analytics4_connected'])) {
        return null; // Site Kit / GA4 not connected — skip gracefully.
    }
    $from = wpultra_reports_fmt_date((int) ($window['from'] ?? 0));
    $to = wpultra_reports_fmt_date((int) ($window['to'] ?? time()));
    try {
        $rep = wpultra_analytics_report([
            'metrics'    => ['sessions', 'totalUsers'],
            'start_date' => $from,
            'end_date'   => $to,
        ]);
    } catch (\Throwable $e) {
        return ['key' => 'analytics', 'title' => 'Analytics', 'rows' => [], 'note' => 'analytics error: ' . $e->getMessage()];
    }
    if (function_exists('is_wp_error') && is_wp_error($rep)) {
        return ['key' => 'analytics', 'title' => 'Analytics', 'rows' => [], 'note' => 'analytics unavailable: ' . $rep->get_error_message()];
    }
    $totals = is_array($rep) && is_array($rep['totals'] ?? null) ? $rep['totals'] : [];
    $rows = [];
    foreach ($totals as $k => $v) {
        if (is_scalar($v)) { $rows[] = [(string) $k, is_numeric($v) ? $v + 0 : (string) $v]; }
    }
    if ($rows === []) { $rows[] = ['Sessions', 0]; }
    return ['key' => 'analytics', 'title' => 'Analytics', 'rows' => $rows];
}

/* ===================================================================== *
 * WordPress wrappers — build, render, send, boot.
 * ===================================================================== */

/**
 * Build a structured report for the given enabled sections + window.
 * $sections is a map {content, store, seo, health, analytics} of bools.
 * Returns {generated_at, period, site, sections:[...]}.
 */
function wpultra_reports_build(array $sections, array $window): array {
    $gatherers = [
        'content'   => 'wpultra_reports_section_content',
        'store'     => 'wpultra_reports_section_store',
        'seo'       => 'wpultra_reports_section_seo',
        'health'    => 'wpultra_reports_section_health',
        'analytics' => 'wpultra_reports_section_analytics',
    ];
    $out = [];
    foreach (wpultra_reports_section_keys() as $key) {
        if (empty($sections[$key])) { continue; }
        $fn = $gatherers[$key];
        try {
            $section = $fn($window);
        } catch (\Throwable $e) {
            $section = ['key' => $key, 'title' => ucfirst($key), 'rows' => [], 'note' => 'section error: ' . $e->getMessage()];
        }
        if (is_array($section)) { $out[] = $section; }
    }

    $site = function_exists('get_bloginfo') ? (string) get_bloginfo('name') : '';
    if ($site === '' && function_exists('home_url')) { $site = (string) home_url(); }
    if ($site === '') { $site = 'Site'; }

    return [
        'generated_at' => time(),
        'period'       => [
            'from'  => (int) ($window['from'] ?? 0),
            'to'    => (int) ($window['to'] ?? time()),
            'label' => (string) ($window['label'] ?? 'weekly'),
        ],
        'site'     => $site,
        'sections' => $out,
    ];
}

/**
 * Send a rendered report to each recipient via wp_mail. HTML content type
 * headers are used when $format is 'html'. Records a history entry.
 *
 * @return array {sent:int, recipients:string[], subject:string}
 */
function wpultra_reports_send(array $report, array $recipients, string $format): array {
    $subject = wpultra_reports_subject($report);
    $body = $format === 'text'
        ? wpultra_reports_render_text($report)
        : wpultra_reports_render_html($report);
    $headers = [];
    if ($format !== 'text') {
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
    }

    $sent = 0;
    $ok = [];
    if (function_exists('wp_mail')) {
        foreach ($recipients as $to) {
            $to = (string) $to;
            if ($to === '') { continue; }
            try {
                if (wp_mail($to, $subject, $body, $headers)) { $sent++; $ok[] = $to; }
            } catch (\Throwable $e) {
                // Delivery failure of one recipient must not abort the rest.
            }
        }
    }

    // Record history (report + send meta) newest-first.
    if (function_exists('get_option') && function_exists('update_option')) {
        $hist = get_option(WPULTRA_REPORTS_HISTORY_OPTION, []);
        if (!is_array($hist)) { $hist = []; }
        $hist = wpultra_reports_history_push($hist, [
            'ts'         => time(),
            'subject'    => $subject,
            'recipients' => $ok,
            'sent'       => $sent,
            'format'     => $format,
            'report'     => $report,
        ]);
        update_option(WPULTRA_REPORTS_HISTORY_OPTION, $hist, false);
    }

    return ['sent' => $sent, 'recipients' => $ok, 'subject' => $subject];
}

/**
 * Build + send the report for the current config now. Records last_run/last_sent.
 * @param bool $dry when true, build only and DO NOT email (safe preview).
 * @return array {report, sent?, recipients?, subject?, dry}
 */
function wpultra_reports_run(bool $dry = false): array {
    $cfg = wpultra_reports_config();
    $window = wpultra_reports_period((string) $cfg['recurrence'], time());
    $report = wpultra_reports_build($cfg['sections'], $window);

    // Record last_run.
    if (function_exists('update_option')) {
        $cfg['last_run'] = time();
        update_option(WPULTRA_REPORTS_OPTION, $cfg, true);
    }

    if ($dry) {
        return ['report' => $report, 'dry' => true];
    }

    $send = wpultra_reports_send($report, $cfg['recipients'], (string) $cfg['format']);

    if (function_exists('update_option')) {
        $cfg['last_sent'] = time();
        update_option(WPULTRA_REPORTS_OPTION, $cfg, true);
    }

    return array_merge(['report' => $report, 'dry' => false], $send);
}

/** Cron action handler — a failed report run must never fatal the cron worker. */
function wpultra_reports_cron_handler(): void {
    try {
        wpultra_reports_run(false);
    } catch (\Throwable $e) {
        if (function_exists('wpultra_audit_log')) {
            wpultra_audit_log('scheduled-reports', 'cron run failed: ' . $e->getMessage(), false);
        }
    }
}

/**
 * Always-on runtime. The controller calls this on plugins_loaded — cheap and
 * idempotent. Registers the cron action handler and reconciles the recurring
 * event with the config: when reports are enabled the event is (re)scheduled at
 * the configured recurrence (marker option `wpultra_reports_sched` detects
 * changes); when disabled the event is cleared.
 */
function wpultra_reports_boot(): void {
    static $booted = false;
    if ($booted) { return; }
    $booted = true;

    if (function_exists('add_action')) {
        add_action(WPULTRA_REPORTS_EVENT, 'wpultra_reports_cron_handler');
        // Ensure the custom weekly / monthly schedules exist for wp_schedule_event.
        add_filter('cron_schedules', 'wpultra_reports_cron_schedules');
    }
    if (!function_exists('get_option') || !function_exists('update_option')
        || !function_exists('wp_next_scheduled') || !function_exists('wp_schedule_event')) {
        return;
    }

    $cfg = wpultra_reports_config();
    $recurrence = (string) $cfg['recurrence'];
    $desired = !empty($cfg['enabled']) ? (WPULTRA_REPORTS_RECURRENCE_SCHEDULE[$recurrence] ?? '') : '';
    $marker = (string) get_option(WPULTRA_REPORTS_SCHED_MARKER, '');

    if ($desired === '') {
        if ($marker !== '') {
            if (function_exists('wp_clear_scheduled_hook')) { wp_clear_scheduled_hook(WPULTRA_REPORTS_EVENT); }
            update_option(WPULTRA_REPORTS_SCHED_MARKER, '', false);
        }
        return;
    }

    if ($marker !== $desired && function_exists('wp_clear_scheduled_hook')) {
        wp_clear_scheduled_hook(WPULTRA_REPORTS_EVENT);
    }
    if (!wp_next_scheduled(WPULTRA_REPORTS_EVENT)) {
        wp_schedule_event(time() + 300, $desired, WPULTRA_REPORTS_EVENT);
    }
    if ($marker !== $desired) {
        update_option(WPULTRA_REPORTS_SCHED_MARKER, $desired, false);
    }
}

/** Register weekly / monthly cron schedules (WP core only ships hourly/twicedaily/daily). */
function wpultra_reports_cron_schedules(array $schedules): array {
    if (!isset($schedules['weekly'])) {
        $schedules['weekly'] = ['interval' => 7 * DAY_IN_SECONDS, 'display' => __('Once Weekly', 'wp-ultra-mcp')];
    }
    if (!isset($schedules['monthly'])) {
        $schedules['monthly'] = ['interval' => 30 * DAY_IN_SECONDS, 'display' => __('Once Monthly', 'wp-ultra-mcp')];
    }
    return $schedules;
}
