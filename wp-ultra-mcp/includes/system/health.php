<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Uptime + health monitor (roadmap C1).
 *
 * Scheduled WP-Cron checks — page reachability (HTTP + optional keyword),
 * SSL certificate expiry, disk free space, recent fatal PHP errors (reads the
 * self-healing error ring from includes/system/errors.php), and overdue cron
 * events — with alert-on-change-only delivery to email and/or an https
 * webhook.
 *
 * Config lives in the autoloaded option `wpultra_health` (see
 * wpultra_health_defaults()). The last per-check status map is kept in
 * `wpultra_health_last` and a newest-first history ring (cap 50) in
 * `wpultra_health_history` (both non-autoloaded). Alerts fire ONLY when a
 * check changes state (ok→warn/fail = degraded, back to ok = recovered), so a
 * persistently-broken check emails once, not hourly.
 *
 * PURE functions first (no WordPress — unit-tested in tests/health.test.php),
 * thin WP wrappers after. The controller calls wpultra_health_boot() on
 * plugins_loaded; boot registers the cron handler and cheaply reconciles the
 * recurring event against the config (marker option `wpultra_health_sched`).
 */

const WPULTRA_HEALTH_OPTION          = 'wpultra_health';
const WPULTRA_HEALTH_LAST_OPTION     = 'wpultra_health_last';
const WPULTRA_HEALTH_HISTORY_OPTION  = 'wpultra_health_history';
const WPULTRA_HEALTH_SCHED_MARKER    = 'wpultra_health_sched';
const WPULTRA_HEALTH_EVENT           = 'wpultra_health_check_event';
const WPULTRA_HEALTH_HISTORY_CAP     = 50;
const WPULTRA_HEALTH_URL_CAP         = 5;
const WPULTRA_HEALTH_CRON_OVERDUE_SEC = 900;  // 15 min
const WPULTRA_HEALTH_ERROR_WINDOW_SEC = 86400; // 24 h

/* ===================================================================== *
 * PURE core — no WordPress calls. Everything here is unit-testable.
 * ===================================================================== */

/** Default configuration shape for the `wpultra_health` option. */
function wpultra_health_defaults(): array {
    return [
        'enabled'  => false,
        'interval' => 'hourly', // hourly | twicedaily | daily
        'checks'   => [
            'http'       => true,
            'ssl'        => true,
            'disk'       => true,
            'php_errors' => true,
            'cron'       => true,
        ],
        'urls'              => [],   // filled with [home_url()] at save when empty
        'keyword'           => '',   // optional substring the FIRST url's body must contain
        'disk_min_free_pct' => 10,   // warn below this, fail below half of it
        'ssl_warn_days'     => 14,   // warn when cert expires within N days
        'alert_email'       => '',   // '' = email alerts off
        'alert_webhook'     => '',   // '' = webhook off; must be https
    ];
}

/**
 * Validate a full (defaults-merged) config. Pure.
 *
 * @return true|string true when valid, else a human-readable error string.
 */
function wpultra_health_validate(array $cfg) {
    if (isset($cfg['enabled']) && !is_bool($cfg['enabled'])) {
        return 'enabled must be a boolean';
    }
    if (!in_array($cfg['interval'] ?? '', ['hourly', 'twicedaily', 'daily'], true)) {
        return 'interval must be one of: hourly, twicedaily, daily';
    }
    if (isset($cfg['checks']) && !is_array($cfg['checks'])) {
        return 'checks must be an object of booleans';
    }
    $urls = $cfg['urls'] ?? [];
    if (!is_array($urls)) { return 'urls must be an array of strings'; }
    if (count($urls) > WPULTRA_HEALTH_URL_CAP) {
        return 'urls: at most ' . WPULTRA_HEALTH_URL_CAP . ' allowed';
    }
    foreach ($urls as $u) {
        if (!is_string($u) || $u === '') { return 'urls entries must be non-empty strings'; }
        if (!preg_match('#^https?://#i', $u) || filter_var($u, FILTER_VALIDATE_URL) === false) {
            return "invalid url: $u (must be a valid http(s) URL)";
        }
    }
    if (isset($cfg['keyword']) && !is_string($cfg['keyword'])) {
        return 'keyword must be a string';
    }
    $pct = $cfg['disk_min_free_pct'] ?? 10;
    if (!is_int($pct) || $pct < 1 || $pct > 50) {
        return 'disk_min_free_pct must be an integer between 1 and 50';
    }
    $days = $cfg['ssl_warn_days'] ?? 14;
    if (!is_int($days) || $days < 1 || $days > 90) {
        return 'ssl_warn_days must be an integer between 1 and 90';
    }
    $email = $cfg['alert_email'] ?? '';
    if (!is_string($email)) { return 'alert_email must be a string'; }
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return 'alert_email is not a valid email address';
    }
    $webhook = $cfg['alert_webhook'] ?? '';
    if (!is_string($webhook)) { return 'alert_webhook must be a string'; }
    if ($webhook !== '' && (!preg_match('#^https://#i', $webhook) || filter_var($webhook, FILTER_VALIDATE_URL) === false)) {
        return 'alert_webhook must be a valid https:// URL';
    }
    return true;
}

/** Severity rank used for worst-of aggregation and transition direction. Pure. */
function wpultra_health_rank(string $status): int {
    return ['ok' => 0, 'warn' => 1, 'fail' => 2][$status] ?? 0;
}

/**
 * Classify one HTTP probe. Pure.
 * $code_or_zero: HTTP status (0 when the request errored / no response).
 * $is_wp_error: transport-level failure (DNS, timeout, TLS, ...).
 * $keyword_ok: false only when a keyword was configured AND missing from the body.
 *
 * @return array{status:string,detail:string}
 */
function wpultra_health_eval_http(int $code_or_zero, bool $is_wp_error, bool $keyword_ok): array {
    if ($is_wp_error) { return ['status' => 'fail', 'detail' => 'request_failed']; }
    if ($code_or_zero >= 500) { return ['status' => 'fail', 'detail' => 'http_' . $code_or_zero]; }
    if ($code_or_zero >= 400) { return ['status' => 'warn', 'detail' => 'http_' . $code_or_zero]; }
    if ($code_or_zero < 100)  { return ['status' => 'fail', 'detail' => 'no_response']; }
    if (!$keyword_ok)         { return ['status' => 'fail', 'detail' => 'keyword_missing']; }
    return ['status' => 'ok', 'detail' => 'http_' . $code_or_zero];
}

/** Whole days until certificate expiry (negative = already expired). Pure. */
function wpultra_health_ssl_days(int $valid_to, int $now): int {
    return (int) floor(($valid_to - $now) / 86400);
}

/**
 * Classify certificate days-left. Fail when expired or < 3 days, warn when
 * < $warn_days, else ok. Pure.
 *
 * @return array{status:string,detail:string}
 */
function wpultra_health_eval_ssl(int $days_left, int $warn_days): array {
    if ($days_left < 0) {
        return ['status' => 'fail', 'detail' => 'certificate expired ' . abs($days_left) . ' day(s) ago'];
    }
    if ($days_left < 3) {
        return ['status' => 'fail', 'detail' => "certificate expires in {$days_left} day(s)"];
    }
    if ($days_left < $warn_days) {
        return ['status' => 'warn', 'detail' => "certificate expires in {$days_left} day(s)"];
    }
    return ['status' => 'ok', 'detail' => "certificate valid for {$days_left} day(s)"];
}

/**
 * Classify disk free percentage. Fail below half the configured minimum,
 * warn below the minimum, else ok. Pure.
 *
 * @return array{status:string,detail:string}
 */
function wpultra_health_eval_disk(float $free_pct, int $min_pct): array {
    $detail = sprintf('%.1f%% free (minimum %d%%)', $free_pct, $min_pct);
    if ($free_pct < $min_pct / 2) { return ['status' => 'fail', 'detail' => $detail]; }
    if ($free_pct < $min_pct)     { return ['status' => 'warn', 'detail' => $detail]; }
    return ['status' => 'ok', 'detail' => $detail];
}

/**
 * Overall roll-up: 'fail' if any check failed, else 'warn' if any warned,
 * else 'ok'. Pure. $results is a list of {check, status, detail}.
 */
function wpultra_health_overall(array $results): string {
    $worst = 'ok';
    foreach ($results as $r) {
        $s = (string) ($r['status'] ?? 'ok');
        if (wpultra_health_rank($s) > wpultra_health_rank($worst)) { $worst = $s; }
    }
    return $worst;
}

/**
 * Per-check state changes between two status maps (check => status). A check
 * absent from $prev is treated as previously 'ok', so a brand-new failing
 * check alerts immediately while a new healthy check stays silent. Unchanged
 * checks are omitted — this is what makes alerts fire on CHANGE only. Pure.
 *
 * @return array list of {check, from, to, kind: 'degraded'|'recovered'}
 */
function wpultra_health_transitions(array $prev, array $curr): array {
    $out = [];
    foreach ($curr as $check => $to) {
        $from = (string) ($prev[$check] ?? 'ok');
        $to   = (string) $to;
        $rf = wpultra_health_rank($from);
        $rt = wpultra_health_rank($to);
        if ($rf === $rt) { continue; }
        $out[] = [
            'check' => (string) $check,
            'from'  => $from,
            'to'    => $to,
            'kind'  => $rt > $rf ? 'degraded' : 'recovered',
        ];
    }
    return $out;
}

/**
 * Plain-text alert body: one line per transition (with the check's current
 * detail appended) plus the current overall status. Pure.
 */
function wpultra_health_alert_text(array $transitions, array $results, string $site): string {
    $details = [];
    foreach ($results as $r) {
        $details[(string) ($r['check'] ?? '')] = (string) ($r['detail'] ?? '');
    }
    $lines = ["Health alert for $site", ''];
    foreach ($transitions as $t) {
        $check = (string) ($t['check'] ?? '');
        $line = sprintf(
            '- %s: %s -> %s (%s)',
            $check,
            (string) ($t['from'] ?? '?'),
            (string) ($t['to'] ?? '?'),
            (string) ($t['kind'] ?? '')
        );
        $detail = $details[$check] ?? '';
        if ($detail !== '') { $line .= ' — ' . $detail; }
        $lines[] = $line;
    }
    $lines[] = '';
    $lines[] = 'Overall status: ' . wpultra_health_overall($results);
    return implode("\n", $lines);
}

/** Prepend an entry to the history ring (newest first) and cap it. Pure. */
function wpultra_health_history_push(array $hist, array $entry, int $cap = WPULTRA_HEALTH_HISTORY_CAP): array {
    array_unshift($hist, $entry);
    if ($cap > 0 && count($hist) > $cap) { $hist = array_slice($hist, 0, $cap); }
    return array_values($hist);
}

/** Short site label for alert subjects (host part of a URL). Pure. */
function wpultra_health_site_label(string $url): string {
    $host = parse_url($url, PHP_URL_HOST);
    return is_string($host) && $host !== '' ? $host : $url;
}

/* ===================================================================== *
 * WordPress wrappers — config store.
 * ===================================================================== */

/** Load the saved config merged over defaults (checks deep-merged). */
function wpultra_health_config(): array {
    $defaults = wpultra_health_defaults();
    $saved = function_exists('get_option') ? get_option(WPULTRA_HEALTH_OPTION, []) : [];
    if (!is_array($saved)) { $saved = []; }
    $cfg = array_merge($defaults, $saved);
    $cfg['checks'] = array_merge(
        $defaults['checks'],
        is_array($saved['checks'] ?? null) ? $saved['checks'] : []
    );
    if (!is_array($cfg['urls'])) { $cfg['urls'] = []; }
    return $cfg;
}

/**
 * Merge $updates into the current config, fill the default url, validate and
 * persist (autoloaded — boot reads it every request).
 *
 * @return array|string the saved config, or a validation error string.
 */
function wpultra_health_save_config(array $updates) {
    $cfg = wpultra_health_config();
    $defaults = wpultra_health_defaults();

    foreach (['enabled', 'interval', 'urls', 'keyword', 'disk_min_free_pct', 'ssl_warn_days', 'alert_email', 'alert_webhook'] as $k) {
        if (array_key_exists($k, $updates)) { $cfg[$k] = $updates[$k]; }
    }
    if (isset($updates['checks']) && is_array($updates['checks'])) {
        foreach (array_keys($defaults['checks']) as $c) {
            if (array_key_exists($c, $updates['checks'])) {
                $cfg['checks'][$c] = (bool) $updates['checks'][$c];
            }
        }
    }

    // Default the url list to the site's own home page when left empty.
    if ((!is_array($cfg['urls']) || $cfg['urls'] === []) && function_exists('home_url')) {
        $cfg['urls'] = [home_url('/')];
    }

    $valid = wpultra_health_validate($cfg);
    if ($valid !== true) { return $valid; }

    if (function_exists('update_option')) {
        update_option(WPULTRA_HEALTH_OPTION, $cfg, true);
    }
    return $cfg;
}

/** Last run snapshot: {ts, overall, map, results} or []. */
function wpultra_health_last(): array {
    $v = function_exists('get_option') ? get_option(WPULTRA_HEALTH_LAST_OPTION, []) : [];
    return is_array($v) ? $v : [];
}

/** History ring, newest first, sliced to $limit. */
function wpultra_health_history(int $limit = 20): array {
    $v = function_exists('get_option') ? get_option(WPULTRA_HEALTH_HISTORY_OPTION, []) : [];
    if (!is_array($v)) { $v = []; }
    $limit = max(1, min(WPULTRA_HEALTH_HISTORY_CAP, $limit));
    return count($v) > $limit ? array_slice($v, 0, $limit) : array_values($v);
}

/* ===================================================================== *
 * WordPress wrappers — check runners. Each returns
 * {check, status: ok|warn|fail, detail, value?}.
 * ===================================================================== */

/** Resolve the configured url list (falling back to home_url when empty). */
function wpultra_health_urls(array $cfg): array {
    $urls = is_array($cfg['urls'] ?? null) ? array_values(array_filter($cfg['urls'], 'is_string')) : [];
    if ($urls === [] && function_exists('home_url')) { $urls = [home_url('/')]; }
    return array_slice($urls, 0, WPULTRA_HEALTH_URL_CAP);
}

/** HTTP reachability: probe every configured url, worst status wins. */
function wpultra_health_check_http(array $cfg): array {
    if (!function_exists('wp_safe_remote_get')) {
        return ['check' => 'http', 'status' => 'ok', 'detail' => 'unavailable (no WP HTTP API)'];
    }
    $urls = wpultra_health_urls($cfg);
    if ($urls === []) {
        return ['check' => 'http', 'status' => 'ok', 'detail' => 'skipped (no urls configured)'];
    }
    $keyword = (string) ($cfg['keyword'] ?? '');
    $worst = 'ok';
    $problems = [];
    $per = [];
    foreach ($urls as $i => $url) {
        $resp = wp_safe_remote_get($url, ['timeout' => 10, 'redirection' => 3]);
        $is_err = function_exists('is_wp_error') && is_wp_error($resp);
        $code = 0;
        if (!$is_err && function_exists('wp_remote_retrieve_response_code')) {
            $code = (int) wp_remote_retrieve_response_code($resp);
        }
        $kw_ok = true;
        if ($keyword !== '' && $i === 0 && !$is_err && function_exists('wp_remote_retrieve_body')) {
            $kw_ok = str_contains((string) wp_remote_retrieve_body($resp), $keyword);
        }
        $eval = wpultra_health_eval_http($code, $is_err, $kw_ok);
        $per[] = ['url' => $url, 'code' => $code, 'status' => $eval['status'], 'detail' => $eval['detail']];
        if (wpultra_health_rank($eval['status']) > wpultra_health_rank($worst)) { $worst = $eval['status']; }
        if ($eval['status'] !== 'ok') { $problems[] = $url . ': ' . $eval['detail']; }
    }
    return [
        'check'  => 'http',
        'status' => $worst,
        'detail' => $problems !== [] ? implode('; ', $problems) : 'all ' . count($per) . ' url(s) responding',
        'value'  => $per,
    ];
}

/** SSL expiry for the first https url. Never fatals — fully try/caught. */
function wpultra_health_check_ssl(array $cfg): array {
    try {
        $https = null;
        foreach (wpultra_health_urls($cfg) as $u) {
            if (stripos($u, 'https://') === 0) { $https = $u; break; }
        }
        if ($https === null) {
            return ['check' => 'ssl', 'status' => 'ok', 'detail' => 'skipped (no https url configured)'];
        }
        if (!function_exists('openssl_x509_parse') || !function_exists('stream_socket_client')) {
            return ['check' => 'ssl', 'status' => 'ok', 'detail' => 'skipped (openssl unavailable)'];
        }
        $host = parse_url($https, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return ['check' => 'ssl', 'status' => 'warn', 'detail' => 'could not parse host from url'];
        }
        $port = (int) (parse_url($https, PHP_URL_PORT) ?: 443);
        $ctx = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'SNI_enabled'       => true,
                'peer_name'         => $host,
            ],
        ]);
        $errno = 0;
        $errstr = '';
        $client = @stream_socket_client(
            'ssl://' . $host . ':' . $port,
            $errno,
            $errstr,
            10,
            STREAM_CLIENT_CONNECT,
            $ctx
        );
        if ($client === false) {
            return ['check' => 'ssl', 'status' => 'warn', 'detail' => 'probe failed: ' . ($errstr !== '' ? $errstr : "errno $errno")];
        }
        $params = stream_context_get_params($client);
        fclose($client);
        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        if (!$cert) {
            return ['check' => 'ssl', 'status' => 'warn', 'detail' => 'no peer certificate captured'];
        }
        $parsed = @openssl_x509_parse($cert);
        $valid_to = is_array($parsed) ? (int) ($parsed['validTo_time_t'] ?? 0) : 0;
        if ($valid_to <= 0) {
            return ['check' => 'ssl', 'status' => 'warn', 'detail' => 'could not read certificate expiry'];
        }
        $days = wpultra_health_ssl_days($valid_to, time());
        $eval = wpultra_health_eval_ssl($days, (int) ($cfg['ssl_warn_days'] ?? 14));
        return ['check' => 'ssl', 'status' => $eval['status'], 'detail' => $eval['detail'], 'value' => $days];
    } catch (\Throwable $e) {
        return ['check' => 'ssl', 'status' => 'warn', 'detail' => 'probe error: ' . $e->getMessage()];
    }
}

/** Disk free space at ABSPATH. Hosts that disable the fns → ok w/ note. */
function wpultra_health_check_disk(array $cfg): array {
    $path = defined('ABSPATH') ? ABSPATH : __DIR__;
    $free = function_exists('disk_free_space') ? @disk_free_space($path) : false;
    $total = function_exists('disk_total_space') ? @disk_total_space($path) : false;
    if ($free === false || $total === false || (float) $total <= 0.0) {
        return ['check' => 'disk', 'status' => 'ok', 'detail' => 'unavailable (disk_free_space disabled on this host)'];
    }
    $pct = (float) $free / (float) $total * 100.0;
    $eval = wpultra_health_eval_disk($pct, (int) ($cfg['disk_min_free_pct'] ?? 10));
    return ['check' => 'disk', 'status' => $eval['status'], 'detail' => $eval['detail'], 'value' => round($pct, 1)];
}

/** Fatal PHP errors captured in the last 24h (system/errors.php ring). */
function wpultra_health_check_php_errors(): array {
    $since = time() - WPULTRA_HEALTH_ERROR_WINDOW_SEC;
    $count = null;
    if (function_exists('wpultra_errors_read')) {
        $count = count(wpultra_errors_read(['since' => $since]));
    } elseif (function_exists('get_option')) {
        $ring = get_option('wpultra_error_log', []);
        if (is_array($ring)) {
            $count = count(array_filter($ring, static fn($e) => (int) (is_array($e) ? ($e['ts'] ?? 0) : 0) >= $since));
        }
    }
    if ($count === null) {
        return ['check' => 'php_errors', 'status' => 'ok', 'detail' => 'unavailable (no error report source)'];
    }
    $status = $count >= 5 ? 'fail' : ($count >= 1 ? 'warn' : 'ok');
    return ['check' => 'php_errors', 'status' => $status, 'detail' => $count . ' fatal error report(s) in the last 24h', 'value' => $count];
}

/** Overdue WP-Cron events (scheduled > 15 min ago and still pending). */
function wpultra_health_check_cron(): array {
    if (!function_exists('_get_cron_array')) {
        return ['check' => 'cron', 'status' => 'ok', 'detail' => 'unavailable (no cron array)'];
    }
    $crons = _get_cron_array();
    if (!is_array($crons)) { $crons = []; }
    $cutoff = time() - WPULTRA_HEALTH_CRON_OVERDUE_SEC;
    $overdue = 0;
    foreach ($crons as $ts => $hooks) {
        if (!is_numeric($ts) || (int) $ts >= $cutoff || !is_array($hooks)) { continue; }
        foreach ($hooks as $events) {
            $overdue += is_array($events) ? count($events) : 1;
        }
    }
    $status = $overdue >= 20 ? 'fail' : ($overdue >= 5 ? 'warn' : 'ok');
    return ['check' => 'cron', 'status' => $status, 'detail' => $overdue . ' cron event(s) overdue by more than 15 min', 'value' => $overdue];
}

/* ===================================================================== *
 * WordPress wrappers — runner, alerts, boot.
 * ===================================================================== */

/**
 * Execute all enabled checks, persist last/history, and fire alerts when any
 * check changed state. Returns {ts, results, overall, transitions, alerted}.
 */
function wpultra_health_run(): array {
    $cfg = wpultra_health_config();
    $checks = $cfg['checks'];

    $results = [];
    if (!empty($checks['http']))       { $results[] = wpultra_health_check_http($cfg); }
    if (!empty($checks['ssl']))        { $results[] = wpultra_health_check_ssl($cfg); }
    if (!empty($checks['disk']))       { $results[] = wpultra_health_check_disk($cfg); }
    if (!empty($checks['php_errors'])) { $results[] = wpultra_health_check_php_errors(); }
    if (!empty($checks['cron']))       { $results[] = wpultra_health_check_cron(); }

    $overall = wpultra_health_overall($results);
    $map = [];
    foreach ($results as $r) { $map[(string) $r['check']] = (string) $r['status']; }

    $prev = wpultra_health_last();
    $prev_map = is_array($prev['map'] ?? null) ? $prev['map'] : [];
    $transitions = wpultra_health_transitions($prev_map, $map);

    $now = time();
    if (function_exists('update_option')) {
        update_option(WPULTRA_HEALTH_LAST_OPTION, [
            'ts'      => $now,
            'overall' => $overall,
            'map'     => $map,
            'results' => $results,
        ], false);

        $hist = function_exists('get_option') ? get_option(WPULTRA_HEALTH_HISTORY_OPTION, []) : [];
        if (!is_array($hist)) { $hist = []; }
        $hist = wpultra_health_history_push($hist, ['ts' => $now, 'overall' => $overall, 'map' => $map]);
        update_option(WPULTRA_HEALTH_HISTORY_OPTION, $hist, false);
    }

    $alerted = false;
    if ($transitions !== []) {
        $alerted = wpultra_health_send_alerts($cfg, $transitions, $results);
    }

    return [
        'ts'          => $now,
        'results'     => $results,
        'overall'     => $overall,
        'transitions' => $transitions,
        'alerted'     => $alerted,
    ];
}

/**
 * Deliver alerts for a set of transitions. Email (wp_mail) and/or webhook
 * (non-blocking wp_safe_remote_post with a JSON body). Returns true when at
 * least one channel was attempted.
 */
function wpultra_health_send_alerts(array $cfg, array $transitions, array $results): bool {
    $overall = wpultra_health_overall($results);
    $site = function_exists('home_url') ? (string) home_url() : (string) ($_SERVER['HTTP_HOST'] ?? 'site');
    $sent = false;

    $email = (string) ($cfg['alert_email'] ?? '');
    if ($email !== '' && function_exists('wp_mail')) {
        $subject = sprintf('[%s] health: %s', wpultra_health_site_label($site), $overall);
        $body = wpultra_health_alert_text($transitions, $results, $site);
        try {
            wp_mail($email, $subject, $body);
            $sent = true;
        } catch (\Throwable $e) {
            // Alert delivery must never break the cron run.
        }
    }

    $webhook = (string) ($cfg['alert_webhook'] ?? '');
    if ($webhook !== '' && function_exists('wp_safe_remote_post')) {
        $payload = ['site' => $site, 'overall' => $overall, 'transitions' => $transitions, 'results' => $results];
        $json = function_exists('wp_json_encode') ? wp_json_encode($payload) : json_encode($payload);
        try {
            wp_safe_remote_post($webhook, [
                'timeout'  => 10,
                'blocking' => false,
                'headers'  => ['Content-Type' => 'application/json'],
                'body'     => (string) $json,
            ]);
            $sent = true;
        } catch (\Throwable $e) {
            // Ignore — non-blocking best effort.
        }
    }

    return $sent;
}

/** Cron action handler — a failed check run must never fatal the cron worker. */
function wpultra_health_cron_handler(): void {
    try {
        wpultra_health_run();
    } catch (\Throwable $e) {
        if (function_exists('wpultra_audit_log')) {
            wpultra_audit_log('health-monitor', 'cron run failed: ' . $e->getMessage(), false);
        }
    }
}

/**
 * Always-on runtime. The controller calls this on plugins_loaded — cheap and
 * idempotent. Registers the cron action handler and reconciles the recurring
 * event with the config: when monitoring is enabled the event is (re)scheduled
 * at the configured interval (marker option `wpultra_health_sched` detects
 * interval changes); when disabled the event is cleared.
 */
function wpultra_health_boot(): void {
    static $booted = false;
    if ($booted) { return; }
    $booted = true;

    if (function_exists('add_action')) {
        add_action(WPULTRA_HEALTH_EVENT, 'wpultra_health_cron_handler');
    }
    if (!function_exists('get_option') || !function_exists('update_option')
        || !function_exists('wp_next_scheduled') || !function_exists('wp_schedule_event')) {
        return;
    }

    $cfg = wpultra_health_config();
    $desired = !empty($cfg['enabled']) ? (string) $cfg['interval'] : '';
    $marker = (string) get_option(WPULTRA_HEALTH_SCHED_MARKER, '');

    if ($desired === '') {
        if ($marker !== '') {
            if (function_exists('wp_clear_scheduled_hook')) { wp_clear_scheduled_hook(WPULTRA_HEALTH_EVENT); }
            update_option(WPULTRA_HEALTH_SCHED_MARKER, '', false);
        }
        return;
    }

    if ($marker !== $desired && function_exists('wp_clear_scheduled_hook')) {
        wp_clear_scheduled_hook(WPULTRA_HEALTH_EVENT);
    }
    if (!wp_next_scheduled(WPULTRA_HEALTH_EVENT)) {
        wp_schedule_event(time() + 60, $desired, WPULTRA_HEALTH_EVENT);
    }
    if ($marker !== $desired) {
        update_option(WPULTRA_HEALTH_SCHED_MARKER, $desired, false);
    }
}
