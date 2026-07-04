<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';

if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/wpultra_health/'); }
// helpers.php provides wpultra_err / wpultra_ok (uses WP_Error stub from harness).
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/system/health.php';

/* ============================================================
 * Defaults + validation.
 * ============================================================ */

it('defaults: monitoring off, hourly, all checks on, sane thresholds', function () {
    $d = wpultra_health_defaults();
    assert_eq(false, $d['enabled']);
    assert_eq('hourly', $d['interval']);
    assert_eq(['http' => true, 'ssl' => true, 'disk' => true, 'php_errors' => true, 'cron' => true], $d['checks']);
    assert_eq([], $d['urls']);
    assert_eq('', $d['keyword']);
    assert_eq(10, $d['disk_min_free_pct']);
    assert_eq(14, $d['ssl_warn_days']);
    assert_eq('', $d['alert_email']);
    assert_eq('', $d['alert_webhook']);
});

it('validate accepts the defaults and a fully-populated valid config', function () {
    assert_eq(true, wpultra_health_validate(wpultra_health_defaults()));
    $cfg = array_merge(wpultra_health_defaults(), [
        'enabled'           => true,
        'interval'          => 'twicedaily',
        'urls'              => ['https://example.com/', 'http://other.test/page'],
        'keyword'           => 'Welcome',
        'disk_min_free_pct' => 5,
        'ssl_warn_days'     => 30,
        'alert_email'       => 'ops@example.com',
        'alert_webhook'     => 'https://hooks.example.com/x',
    ]);
    assert_eq(true, wpultra_health_validate($cfg));
});

it('validate rejects a bad interval', function () {
    $cfg = array_merge(wpultra_health_defaults(), ['interval' => 'weekly']);
    assert_true(is_string(wpultra_health_validate($cfg)), 'weekly rejected');
});

it('validate rejects more than 5 urls', function () {
    $urls = [];
    for ($i = 0; $i < 6; $i++) { $urls[] = "https://example.com/$i"; }
    $cfg = array_merge(wpultra_health_defaults(), ['urls' => $urls]);
    assert_true(is_string(wpultra_health_validate($cfg)), '6 urls rejected');
    // Exactly 5 is fine.
    $cfg['urls'] = array_slice($urls, 0, 5);
    assert_eq(true, wpultra_health_validate($cfg));
});

it('validate rejects non-http(s) and malformed urls', function () {
    foreach (['ftp://example.com/', 'javascript:alert(1)', 'not a url', 'https://'] as $bad) {
        $cfg = array_merge(wpultra_health_defaults(), ['urls' => [$bad]]);
        assert_true(is_string(wpultra_health_validate($cfg)), "rejected: $bad");
    }
});

it('validate bounds disk_min_free_pct to 1..50', function () {
    foreach ([0, 51, -3] as $bad) {
        $cfg = array_merge(wpultra_health_defaults(), ['disk_min_free_pct' => $bad]);
        assert_true(is_string(wpultra_health_validate($cfg)), "pct $bad rejected");
    }
    foreach ([1, 50] as $good) {
        $cfg = array_merge(wpultra_health_defaults(), ['disk_min_free_pct' => $good]);
        assert_eq(true, wpultra_health_validate($cfg), "pct $good accepted");
    }
});

it('validate bounds ssl_warn_days to 1..90', function () {
    foreach ([0, 91] as $bad) {
        $cfg = array_merge(wpultra_health_defaults(), ['ssl_warn_days' => $bad]);
        assert_true(is_string(wpultra_health_validate($cfg)), "days $bad rejected");
    }
    $cfg = array_merge(wpultra_health_defaults(), ['ssl_warn_days' => 90]);
    assert_eq(true, wpultra_health_validate($cfg));
});

it('validate checks alert_email format only when set', function () {
    $cfg = array_merge(wpultra_health_defaults(), ['alert_email' => 'nope@']);
    assert_true(is_string(wpultra_health_validate($cfg)), 'bad email rejected');
    $cfg['alert_email'] = '';
    assert_eq(true, wpultra_health_validate($cfg), 'empty email = off, ok');
    $cfg['alert_email'] = 'a@b.co';
    assert_eq(true, wpultra_health_validate($cfg), 'valid email ok');
});

it('validate requires https for the webhook (when set)', function () {
    $cfg = array_merge(wpultra_health_defaults(), ['alert_webhook' => 'http://hooks.example.com/x']);
    assert_true(is_string(wpultra_health_validate($cfg)), 'plain-http webhook rejected');
    $cfg['alert_webhook'] = 'https://hooks.example.com/x';
    assert_eq(true, wpultra_health_validate($cfg));
    $cfg['alert_webhook'] = '';
    assert_eq(true, wpultra_health_validate($cfg), 'empty webhook = off, ok');
});

/* ============================================================
 * eval_http matrix.
 * ============================================================ */

it('eval_http: transport error is a fail regardless of code', function () {
    assert_eq(['status' => 'fail', 'detail' => 'request_failed'], wpultra_health_eval_http(0, true, true));
    assert_eq('fail', wpultra_health_eval_http(200, true, true)['status']);
});

it('eval_http: 5xx fails, 4xx warns, 2xx/3xx ok', function () {
    assert_eq(['status' => 'fail', 'detail' => 'http_500'], wpultra_health_eval_http(500, false, true));
    assert_eq('fail', wpultra_health_eval_http(503, false, true)['status']);
    assert_eq(['status' => 'warn', 'detail' => 'http_404'], wpultra_health_eval_http(404, false, true));
    assert_eq(['status' => 'ok', 'detail' => 'http_200'], wpultra_health_eval_http(200, false, true));
    assert_eq('ok', wpultra_health_eval_http(301, false, true)['status']);
});

it('eval_http: missing keyword on a 200 page is a fail', function () {
    assert_eq(['status' => 'fail', 'detail' => 'keyword_missing'], wpultra_health_eval_http(200, false, false));
    // ...but a 5xx keeps its own (worse-cause) detail.
    assert_eq('http_500', wpultra_health_eval_http(500, false, false)['detail']);
});

it('eval_http: code 0 without a WP_Error is still a fail (no response)', function () {
    assert_eq(['status' => 'fail', 'detail' => 'no_response'], wpultra_health_eval_http(0, false, true));
});

/* ============================================================
 * SSL: days math + evaluation.
 * ============================================================ */

it('ssl_days computes whole days left (floor), negative when expired', function () {
    $now = 1_700_000_000;
    assert_eq(10, wpultra_health_ssl_days($now + 10 * 86400, $now));
    assert_eq(9, wpultra_health_ssl_days($now + 10 * 86400 - 1, $now), 'partial day floors down');
    assert_eq(0, wpultra_health_ssl_days($now + 3600, $now));
    assert_eq(-1, wpultra_health_ssl_days($now - 1, $now), 'just expired = -1');
    assert_eq(-2, wpultra_health_ssl_days($now - 86400 - 1, $now));
});

it('eval_ssl: expired or <3 days fail, < warn_days warns, else ok', function () {
    assert_eq('fail', wpultra_health_eval_ssl(-5, 14)['status']);
    assert_eq('fail', wpultra_health_eval_ssl(0, 14)['status']);
    assert_eq('fail', wpultra_health_eval_ssl(2, 14)['status']);
    assert_eq('warn', wpultra_health_eval_ssl(3, 14)['status'], '3 days = warn, not fail');
    assert_eq('warn', wpultra_health_eval_ssl(13, 14)['status']);
    assert_eq('ok', wpultra_health_eval_ssl(14, 14)['status'], 'boundary: exactly warn_days is ok');
    assert_eq('ok', wpultra_health_eval_ssl(60, 14)['status']);
});

/* ============================================================
 * Disk evaluation.
 * ============================================================ */

it('eval_disk: below half the minimum fails, below minimum warns, else ok', function () {
    assert_eq('fail', wpultra_health_eval_disk(4.9, 10)['status']);
    assert_eq('warn', wpultra_health_eval_disk(5.0, 10)['status'], 'boundary: exactly half = warn');
    assert_eq('warn', wpultra_health_eval_disk(9.9, 10)['status']);
    assert_eq('ok', wpultra_health_eval_disk(10.0, 10)['status'], 'boundary: exactly minimum = ok');
    assert_eq('ok', wpultra_health_eval_disk(42.0, 10)['status']);
});

it('eval_disk detail mentions the percentage and configured minimum', function () {
    $r = wpultra_health_eval_disk(7.5, 10);
    assert_contains('7.5% free', $r['detail']);
    assert_contains('minimum 10%', $r['detail']);
});

/* ============================================================
 * Overall roll-up.
 * ============================================================ */

it('overall: fail beats warn beats ok; empty set is ok', function () {
    assert_eq('ok', wpultra_health_overall([]));
    assert_eq('ok', wpultra_health_overall([
        ['check' => 'http', 'status' => 'ok', 'detail' => ''],
        ['check' => 'disk', 'status' => 'ok', 'detail' => ''],
    ]));
    assert_eq('warn', wpultra_health_overall([
        ['check' => 'http', 'status' => 'ok', 'detail' => ''],
        ['check' => 'ssl', 'status' => 'warn', 'detail' => ''],
    ]));
    assert_eq('fail', wpultra_health_overall([
        ['check' => 'http', 'status' => 'warn', 'detail' => ''],
        ['check' => 'disk', 'status' => 'fail', 'detail' => ''],
        ['check' => 'cron', 'status' => 'ok', 'detail' => ''],
    ]));
});

/* ============================================================
 * Transitions — the alert-on-change core.
 * ============================================================ */

it('transitions: ok→fail and ok→warn are degraded, warn→fail is degraded', function () {
    $t = wpultra_health_transitions(
        ['http' => 'ok', 'ssl' => 'ok', 'disk' => 'warn'],
        ['http' => 'fail', 'ssl' => 'warn', 'disk' => 'fail']
    );
    $byCheck = [];
    foreach ($t as $x) { $byCheck[$x['check']] = $x; }
    assert_eq(3, count($t));
    assert_eq(['check' => 'http', 'from' => 'ok', 'to' => 'fail', 'kind' => 'degraded'], $byCheck['http']);
    assert_eq('degraded', $byCheck['ssl']['kind']);
    assert_eq(['check' => 'disk', 'from' => 'warn', 'to' => 'fail', 'kind' => 'degraded'], $byCheck['disk']);
});

it('transitions: fail→ok, warn→ok and fail→warn are recovered', function () {
    $t = wpultra_health_transitions(
        ['http' => 'fail', 'ssl' => 'warn', 'disk' => 'fail'],
        ['http' => 'ok', 'ssl' => 'ok', 'disk' => 'warn']
    );
    $byCheck = [];
    foreach ($t as $x) { $byCheck[$x['check']] = $x; }
    assert_eq('recovered', $byCheck['http']['kind']);
    assert_eq('recovered', $byCheck['ssl']['kind']);
    assert_eq('recovered', $byCheck['disk']['kind'], 'fail→warn is an improvement');
});

it('transitions: unchanged checks are omitted entirely', function () {
    assert_eq([], wpultra_health_transitions(
        ['http' => 'ok', 'ssl' => 'warn', 'disk' => 'fail'],
        ['http' => 'ok', 'ssl' => 'warn', 'disk' => 'fail']
    ));
});

it('transitions: a check with no previous state is baselined as ok', function () {
    // First-ever run: healthy checks are silent, a failing one alerts.
    $t = wpultra_health_transitions([], ['http' => 'ok', 'disk' => 'fail']);
    assert_eq(1, count($t));
    assert_eq(['check' => 'disk', 'from' => 'ok', 'to' => 'fail', 'kind' => 'degraded'], $t[0]);
});

/* ============================================================
 * Alert text.
 * ============================================================ */

it('alert_text: one line per transition with detail, plus site + overall', function () {
    $transitions = [
        ['check' => 'http', 'from' => 'ok', 'to' => 'fail', 'kind' => 'degraded'],
        ['check' => 'ssl', 'from' => 'warn', 'to' => 'ok', 'kind' => 'recovered'],
    ];
    $results = [
        ['check' => 'http', 'status' => 'fail', 'detail' => 'https://example.com/: http_503'],
        ['check' => 'ssl', 'status' => 'ok', 'detail' => 'certificate valid for 60 day(s)'],
        ['check' => 'disk', 'status' => 'ok', 'detail' => '42.0% free (minimum 10%)'],
    ];
    $text = wpultra_health_alert_text($transitions, $results, 'https://example.com');
    assert_contains('Health alert for https://example.com', $text);
    assert_contains('- http: ok -> fail (degraded)', $text);
    assert_contains('http_503', $text, 'current detail appended to the transition line');
    assert_contains('- ssl: warn -> ok (recovered)', $text);
    assert_contains('Overall status: fail', $text, 'overall derived from current results');
});

/* ============================================================
 * History ring + misc pure helpers.
 * ============================================================ */

it('history_push prepends newest-first and enforces the cap', function () {
    $hist = [];
    for ($i = 1; $i <= 5; $i++) {
        $hist = wpultra_health_history_push($hist, ['ts' => $i, 'overall' => 'ok'], 3);
    }
    assert_eq(3, count($hist));
    assert_eq(5, $hist[0]['ts'], 'newest first');
    assert_eq(4, $hist[1]['ts']);
    assert_eq(3, $hist[2]['ts'], 'oldest surviving entry');
});

it('history_push default cap is 50', function () {
    $hist = [];
    for ($i = 0; $i < 60; $i++) {
        $hist = wpultra_health_history_push($hist, ['ts' => $i]);
    }
    assert_eq(50, count($hist));
    assert_eq(59, $hist[0]['ts']);
});

it('site_label extracts the host, falling back to the raw string', function () {
    assert_eq('example.com', wpultra_health_site_label('https://example.com/path?q=1'));
    assert_eq('shop.example.co.uk', wpultra_health_site_label('http://shop.example.co.uk'));
    assert_eq('mysite', wpultra_health_site_label('mysite'));
});

it('rank orders ok < warn < fail (unknown = ok)', function () {
    assert_eq(0, wpultra_health_rank('ok'));
    assert_eq(1, wpultra_health_rank('warn'));
    assert_eq(2, wpultra_health_rank('fail'));
    assert_eq(0, wpultra_health_rank('bogus'));
});

run_tests();
