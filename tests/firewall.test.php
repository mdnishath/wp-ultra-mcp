<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';

if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/wpultra_firewall/'); }
// helpers.php provides wpultra_err / wpultra_ok (uses WP_Error stub from harness).
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/system/firewall.php';

/* ============================================================
 * valid_ip_or_cidr.
 * ============================================================ */

it('valid_ip_or_cidr accepts plain IPv4 / IPv6', function () {
    assert_true(wpultra_fw_valid_ip_or_cidr('8.8.8.8'));
    assert_true(wpultra_fw_valid_ip_or_cidr('::1'));
    assert_true(wpultra_fw_valid_ip_or_cidr('2001:db8::1'));
});

it('valid_ip_or_cidr accepts IPv4 / IPv6 CIDR', function () {
    assert_true(wpultra_fw_valid_ip_or_cidr('192.168.0.0/24'));
    assert_true(wpultra_fw_valid_ip_or_cidr('2001:db8::/32'));
});

it('valid_ip_or_cidr rejects garbage and out-of-range prefixes', function () {
    assert_eq(false, wpultra_fw_valid_ip_or_cidr('nope'));
    assert_eq(false, wpultra_fw_valid_ip_or_cidr('8.8.8.8/40'));
    assert_eq(false, wpultra_fw_valid_ip_or_cidr('8.8.8.8/x'));
    assert_eq(false, wpultra_fw_valid_ip_or_cidr(''));
    assert_eq(false, wpultra_fw_valid_ip_or_cidr('2001:db8::/200'));
});

/* ============================================================
 * clean_ip_list.
 * ============================================================ */

it('clean_ip_list keeps valid entries, drops junk, dedupes', function () {
    $out = wpultra_fw_clean_ip_list(['1.2.3.4', 'bad', '1.2.3.4', '10.0.0.0/8', '  8.8.8.8  ']);
    assert_eq(['1.2.3.4', '10.0.0.0/8', '8.8.8.8'], $out);
});

it('clean_ip_list returns [] for non-array', function () {
    assert_eq([], wpultra_fw_clean_ip_list('not-array'));
});

/* ============================================================
 * ip_in_cidr — IPv4 + IPv6.
 * ============================================================ */

it('ip_in_cidr matches inside an IPv4 /24', function () {
    assert_true(wpultra_fw_ip_in_cidr('192.168.1.55', '192.168.1.0/24'));
});

it('ip_in_cidr rejects outside an IPv4 /24', function () {
    assert_eq(false, wpultra_fw_ip_in_cidr('192.168.2.5', '192.168.1.0/24'));
});

it('ip_in_cidr treats a plain IP as exact match', function () {
    assert_true(wpultra_fw_ip_in_cidr('10.0.0.7', '10.0.0.7'));
    assert_eq(false, wpultra_fw_ip_in_cidr('10.0.0.8', '10.0.0.7'));
});

it('ip_in_cidr /32 boundary', function () {
    assert_true(wpultra_fw_ip_in_cidr('203.0.113.9', '203.0.113.9/32'));
    assert_eq(false, wpultra_fw_ip_in_cidr('203.0.113.10', '203.0.113.9/32'));
});

it('ip_in_cidr /0 matches everything', function () {
    assert_true(wpultra_fw_ip_in_cidr('8.8.8.8', '0.0.0.0/0'));
    assert_true(wpultra_fw_ip_in_cidr('1.2.3.4', '0.0.0.0/0'));
});

it('ip_in_cidr /16 boundary', function () {
    assert_true(wpultra_fw_ip_in_cidr('172.16.5.9', '172.16.0.0/16'));
    assert_eq(false, wpultra_fw_ip_in_cidr('172.17.0.1', '172.16.0.0/16'));
});

it('ip_in_cidr matches inside an IPv6 CIDR', function () {
    assert_true(wpultra_fw_ip_in_cidr('2001:db8::1', '2001:db8::/32'));
    assert_eq(false, wpultra_fw_ip_in_cidr('2001:dead::1', '2001:db8::/32'));
});

it('ip_in_cidr normalizes equal IPv6 forms', function () {
    assert_true(wpultra_fw_ip_in_cidr('::1', '0:0:0:0:0:0:0:1'));
});

it('ip_in_cidr returns false on v4-vs-v6 family mismatch', function () {
    assert_eq(false, wpultra_fw_ip_in_cidr('192.168.1.1', '2001:db8::/32'));
    assert_eq(false, wpultra_fw_ip_in_cidr('2001:db8::1', '192.168.1.0/24'));
});

it('ip_in_cidr returns false for malformed input (never fatal)', function () {
    assert_eq(false, wpultra_fw_ip_in_cidr('not-an-ip', '192.168.1.0/24'));
    assert_eq(false, wpultra_fw_ip_in_cidr('192.168.1.5', 'garbage'));
    assert_eq(false, wpultra_fw_ip_in_cidr('192.168.1.5', '192.168.1.0/99'));
    assert_eq(false, wpultra_fw_ip_in_cidr('192.168.1.5', '192.168.1.0/'));
    assert_eq(false, wpultra_fw_ip_in_cidr('', ''));
});

/* ============================================================
 * ip_in_list.
 * ============================================================ */

it('ip_in_list matches via plain IP or CIDR', function () {
    assert_true(wpultra_fw_ip_in_list('5.6.7.8', ['1.2.3.4', '5.6.7.8']));
    assert_true(wpultra_fw_ip_in_list('10.1.2.3', ['10.1.0.0/16']));
});

it('ip_in_list returns false when nothing matches', function () {
    assert_eq(false, wpultra_fw_ip_in_list('9.9.9.9', ['1.2.3.4', '10.0.0.0/8']));
    assert_eq(false, wpultra_fw_ip_in_list('9.9.9.9', []));
});

/* ============================================================
 * bad_bot.
 * ============================================================ */

it('bad_bot matches default scanners case-insensitively', function () {
    assert_true(wpultra_fw_bad_bot('Mozilla/5.0 SQLMAP/1.5'));
    assert_true(wpultra_fw_bad_bot('nikto/2.1.6'));
    assert_true(wpultra_fw_bad_bot('WPScan v3'));
});

it('bad_bot does NOT match Googlebot / legit crawlers', function () {
    assert_eq(false, wpultra_fw_bad_bot('Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'));
    assert_eq(false, wpultra_fw_bad_bot('Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)'));
    assert_eq(false, wpultra_fw_bad_bot('Mozilla/5.0 (compatible; SemrushBot/7~bl)'));
    assert_eq(false, wpultra_fw_bad_bot('Mozilla/5.0 (compatible; AhrefsBot/7.0)'));
});

it('bad_bot empty UA is never flagged (fail-open)', function () {
    assert_eq(false, wpultra_fw_bad_bot(''));
    assert_eq(false, wpultra_fw_bad_bot('   '));
});

it('bad_bot matches an extra site-specific UA', function () {
    assert_true(wpultra_fw_bad_bot('EvilScraper/9', ['evilscraper']));
});

it('bad_bot returns false for a normal browser', function () {
    assert_eq(false, wpultra_fw_bad_bot('Mozilla/5.0 (Windows NT 10.0) Firefox/120'));
});

it('bad_bot_defaults excludes legit crawler tokens', function () {
    $bots = wpultra_fw_bad_bot_defaults();
    assert_eq(false, in_array('googlebot', $bots, true));
    assert_eq(false, in_array('bingbot', $bots, true));
    assert_eq(false, in_array('ahrefs', $bots, true));
    assert_true(in_array('sqlmap', $bots, true));
});

/* ============================================================
 * country_blocked — FAIL-OPEN.
 * ============================================================ */

it('country_blocked matches an ISO2 code case-insensitively', function () {
    assert_true(wpultra_fw_country_blocked('cn', ['CN', 'RU']));
    assert_true(wpultra_fw_country_blocked('RU', ['ru']));
});

it('country_blocked fails open for unknown / malformed country', function () {
    assert_eq(false, wpultra_fw_country_blocked('', ['CN']));
    assert_eq(false, wpultra_fw_country_blocked('USA', ['USA'])); // 3 letters => not ISO2 => fail open
    assert_eq(false, wpultra_fw_country_blocked('1', ['CN']));
});

it('country_blocked returns false when not on the list', function () {
    assert_eq(false, wpultra_fw_country_blocked('US', ['CN', 'RU']));
});

/* ============================================================
 * bypass_path — self-lockout safeguard.
 * ============================================================ */

it('bypass_path bypasses wp-admin, wp-login, and our REST routes', function () {
    assert_true(wpultra_fw_bypass_path('/wp-admin/'));
    assert_true(wpultra_fw_bypass_path('/wp-admin/edit.php'));
    assert_true(wpultra_fw_bypass_path('/wp-login.php'));
    assert_true(wpultra_fw_bypass_path('/wp-json/mcp/'));
    assert_true(wpultra_fw_bypass_path('/wp-json/wpultra/v1/x'));
});

it('bypass_path bypasses a subdir install', function () {
    assert_true(wpultra_fw_bypass_path('/blog/wp-admin/index.php'));
});

it('bypass_path does NOT bypass a normal front-end path', function () {
    assert_eq(false, wpultra_fw_bypass_path('/'));
    assert_eq(false, wpultra_fw_bypass_path('/products/shoes'));
    assert_eq(false, wpultra_fw_bypass_path('/wp-admining-tips')); // wp-admin not followed by / or end
});

/* ============================================================
 * match_rules — signature patterns (incl. encoded).
 * ============================================================ */

it('match_rules flags path traversal', function () {
    $hits = wpultra_fw_match_rules('/../../etc/passwd', '');
    $ids = array_column($hits, 'id');
    assert_true(in_array('traversal', $ids, true));
});

it('match_rules flags an SQLi signature in the query', function () {
    $hits = wpultra_fw_match_rules('/', 'id=1 union select 2');
    assert_true(in_array('sqli', array_column($hits, 'id'), true));
});

it('match_rules flags a wp-config probe', function () {
    $hits = wpultra_fw_match_rules('/wp-config.php', '');
    assert_true(in_array('wp_config', array_column($hits, 'id'), true));
});

it('match_rules catches URL-encoded traversal', function () {
    $hits = wpultra_fw_match_rules('/%2e%2e%2f%2e%2e%2fetc%2fpasswd', '');
    $ids = array_column($hits, 'id');
    assert_true(in_array('traversal', $ids, true) || in_array('etc_passwd', $ids, true));
});

it('match_rules flags a null-byte injection', function () {
    $hits = wpultra_fw_match_rules('/file%00.php', '');
    assert_true(in_array('null_byte', array_column($hits, 'id'), true));
});

it('match_rules returns [] for a clean request', function () {
    assert_eq([], wpultra_fw_match_rules('/products/nice-shoes', 'color=red&size=10'));
});

it('match_rules never fatals on a broken supplied pattern', function () {
    $hits = wpultra_fw_match_rules('/x', '', [['id' => 'broken', 'regex' => '/[unterminated', 'target' => 'both', 'label' => 'x']]);
    assert_eq([], $hits);
});

/* ============================================================
 * normalize_config.
 * ============================================================ */

it('normalize_config yields defaults for junk input', function () {
    $cfg = wpultra_fw_normalize_config('not-an-array');
    assert_eq('off', $cfg['mode']);
    assert_eq(120, $cfg['rate_limit']['per_minute']);
});

it('normalize_config coerces an unknown mode to off', function () {
    assert_eq('off', wpultra_fw_normalize_config(['mode' => 'nuke'])['mode']);
    assert_eq('block', wpultra_fw_normalize_config(['mode' => 'BLOCK'])['mode']);
});

it('normalize_config clamps per_minute to 10..1000', function () {
    assert_eq(10, wpultra_fw_normalize_config(['rate_limit' => ['per_minute' => 1]])['rate_limit']['per_minute']);
    assert_eq(1000, wpultra_fw_normalize_config(['rate_limit' => ['per_minute' => 99999]])['rate_limit']['per_minute']);
});

it('normalize_config validates ip lists and country codes', function () {
    $cfg = wpultra_fw_normalize_config([
        'ip_block'        => ['1.2.3.4', 'junk', '10.0.0.0/8'],
        'countries_block' => ['cn', 'USA', 'ru', 'r'],
    ]);
    assert_eq(['1.2.3.4', '10.0.0.0/8'], $cfg['ip_block']);
    assert_eq(['CN', 'RU'], $cfg['countries_block']);
});

it('normalize_config lowercases + dedupes bad_bots.extra', function () {
    $cfg = wpultra_fw_normalize_config(['bad_bots' => ['extra' => ['EvilBot', 'evilbot', '  ']]]);
    assert_eq(['evilbot'], $cfg['bad_bots']['extra']);
});

/* ============================================================
 * evaluate — the security control. Exhaustive.
 * ============================================================ */

function fw_cfg(array $over = []): array {
    // Start from a live (block-mode) config so sub-features are exercisable.
    $base = wpultra_fw_default_config();
    $base['mode'] = 'block';
    return array_replace_recursive($base, $over);
}
function fw_ctx(array $over = []): array {
    return array_replace(
        ['ip' => '203.0.113.5', 'ua' => 'Mozilla/5.0', 'path' => '/', 'query' => '', 'logged_in' => false, 'country' => ''],
        $over
    );
}

it('evaluate: allowlist beats EVERYTHING (blocklist + bad bot + rate limit)', function () {
    $cfg = fw_cfg([
        'ip_allow' => ['9.9.9.9'],
        'ip_block' => ['9.9.9.9'],
        'bad_bots' => ['enabled' => true],
        'rate_limit' => ['enabled' => true, 'per_minute' => 10],
    ]);
    $v = wpultra_fw_evaluate($cfg, fw_ctx(['ip' => '9.9.9.9', 'ua' => 'sqlmap/1.5']), 99999);
    assert_eq('allow', $v['verdict']);
    assert_eq('ip-allow', $v['rule']);
});

it('evaluate: blocklist IP blocks', function () {
    $v = wpultra_fw_evaluate(fw_cfg(['ip_block' => ['6.6.6.6']]), fw_ctx(['ip' => '6.6.6.6']), 0);
    assert_eq('block', $v['verdict']);
    assert_eq('ip-block', $v['rule']);
    assert_true($v['enforce']);
});

it('evaluate: blocklist CIDR blocks', function () {
    $v = wpultra_fw_evaluate(fw_cfg(['ip_block' => ['10.0.0.0/8']]), fw_ctx(['ip' => '10.20.30.40']), 0);
    assert_eq('block', $v['verdict']);
    assert_eq('ip-block', $v['rule']);
});

it('evaluate: bad-bot UA blocks when enabled', function () {
    $v = wpultra_fw_evaluate(fw_cfg(['bad_bots' => ['enabled' => true]]), fw_ctx(['ua' => 'sqlmap/1.5']), 0);
    assert_eq('block', $v['verdict']);
    assert_eq('bad-bot', $v['rule']);
});

it('evaluate: bad-bot skipped when the sub-feature is disabled', function () {
    $v = wpultra_fw_evaluate(fw_cfg(['bad_bots' => ['enabled' => false]]), fw_ctx(['ua' => 'sqlmap/1.5']), 0);
    assert_eq('allow', $v['verdict']);
});

it('evaluate: blocked country blocks', function () {
    $v = wpultra_fw_evaluate(fw_cfg(['countries_block' => ['CN']]), fw_ctx(['country' => 'CN']), 0);
    assert_eq('block', $v['verdict']);
    assert_eq('country', $v['rule']);
});

it('evaluate: country fails open when country unknown', function () {
    $v = wpultra_fw_evaluate(fw_cfg(['countries_block' => ['CN']]), fw_ctx(['country' => '']), 0);
    assert_eq('allow', $v['verdict']);
});

it('evaluate: request rule is LOG-ONLY by default (verdict block but enforce false)', function () {
    $cfg = fw_cfg(['rules' => ['enabled' => true, 'log_only' => true]]);
    $v = wpultra_fw_evaluate($cfg, fw_ctx(['query' => 'id=1 union select 2']), 0);
    assert_eq('block', $v['verdict']);
    assert_eq('request-rules', $v['rule']);
    assert_eq(false, $v['enforce']);
});

it('evaluate: request rule enforces when log_only is false', function () {
    $cfg = fw_cfg(['rules' => ['enabled' => true, 'log_only' => false]]);
    $v = wpultra_fw_evaluate($cfg, fw_ctx(['query' => 'id=1 union select 2']), 0);
    assert_eq('block', $v['verdict']);
    assert_true($v['enforce']);
});

it('evaluate: a log-only rule hit does NOT shadow an enforceable rate-limit block', function () {
    $cfg = fw_cfg([
        'rules'      => ['enabled' => true, 'log_only' => true],
        'rate_limit' => ['enabled' => true, 'per_minute' => 100],
    ]);
    // Request trips both a signature (log-only) AND the rate limit (enforceable).
    $v = wpultra_fw_evaluate($cfg, fw_ctx(['query' => 'union select 1']), 101);
    assert_eq('rate-limit', $v['rule']);
    assert_true($v['enforce']);
});

it('evaluate: rate limit boundary — exactly limit ALLOWED, limit+1 BLOCKED', function () {
    $cfg = fw_cfg(['rate_limit' => ['enabled' => true, 'per_minute' => 100]]);
    $atLimit = wpultra_fw_evaluate($cfg, fw_ctx(), 100);
    assert_eq('allow', $atLimit['verdict']);
    $over = wpultra_fw_evaluate($cfg, fw_ctx(), 101);
    assert_eq('block', $over['verdict']);
    assert_eq('rate-limit', $over['rule']);
});

it('evaluate: rate limit NEVER applies to logged-in users', function () {
    $cfg = fw_cfg(['rate_limit' => ['enabled' => true, 'per_minute' => 10]]);
    $v = wpultra_fw_evaluate($cfg, fw_ctx(['logged_in' => true]), 99999);
    assert_eq('allow', $v['verdict']);
});

it('evaluate: rate limit skipped when disabled', function () {
    $cfg = fw_cfg(['rate_limit' => ['enabled' => false, 'per_minute' => 10]]);
    $v = wpultra_fw_evaluate($cfg, fw_ctx(), 99999);
    assert_eq('allow', $v['verdict']);
});

it('evaluate: mode off -> everything allowed', function () {
    // normalize inside evaluate forces mode off; sub-features still evaluate
    // (mode gating happens in the runner), but a clean request stays allow.
    $v = wpultra_fw_evaluate(['mode' => 'off'], fw_ctx(), 0);
    assert_eq('allow', $v['verdict']);
});

it('evaluate: clean request with all features on is allowed', function () {
    $cfg = fw_cfg([
        'bad_bots'        => ['enabled' => true],
        'rate_limit'      => ['enabled' => true, 'per_minute' => 120],
        'ip_block'        => ['6.6.6.6'],
        'countries_block' => ['CN'],
        'rules'           => ['enabled' => true, 'log_only' => true],
    ]);
    $v = wpultra_fw_evaluate($cfg, fw_ctx(['ua' => 'Mozilla/5.0 Firefox', 'country' => 'US']), 5);
    assert_eq('allow', $v['verdict']);
});

it('evaluate: precedence — blocklist beats bad-bot beats rate-limit', function () {
    $cfg = fw_cfg([
        'ip_block'   => ['7.7.7.7'],
        'bad_bots'   => ['enabled' => true],
        'rate_limit' => ['enabled' => true, 'per_minute' => 10],
    ]);
    $v = wpultra_fw_evaluate($cfg, fw_ctx(['ip' => '7.7.7.7', 'ua' => 'sqlmap']), 500);
    assert_eq('ip-block', $v['rule']);
});

/* ============================================================
 * http_status.
 * ============================================================ */

it('http_status is 429 for rate-limit, 403 otherwise', function () {
    assert_eq(429, wpultra_fw_http_status('rate-limit'));
    assert_eq(403, wpultra_fw_http_status('ip-block'));
    assert_eq(403, wpultra_fw_http_status('bad-bot'));
});

/* ============================================================
 * log_push / log_entry — capped ring, newest first.
 * ============================================================ */

it('log_push prepends newest first', function () {
    $ring = [];
    $ring = wpultra_fw_log_push($ring, ['ip' => 'a'], 200);
    $ring = wpultra_fw_log_push($ring, ['ip' => 'b'], 200);
    assert_eq('b', $ring[0]['ip']);
    assert_eq('a', $ring[1]['ip']);
});

it('log_push caps the ring, dropping the oldest', function () {
    $ring = [];
    for ($i = 0; $i < 250; $i++) {
        $ring = wpultra_fw_log_push($ring, ['ip' => (string) $i], 200);
    }
    assert_eq(200, count($ring));
    // Newest first: index 0 is #249; oldest kept is #50 at the tail.
    assert_eq('249', $ring[0]['ip']);
    assert_eq('50', $ring[199]['ip']);
});

it('log_entry truncates path and ua', function () {
    $entry = wpultra_fw_log_entry(['ip' => '1.2.3.4', 'path' => str_repeat('a', 500), 'ua' => str_repeat('b', 300)], 'bad-bot', 'x', 'block', 1000);
    assert_eq(200, strlen($entry['path']));
    assert_eq(120, strlen($entry['ua']));
    assert_eq('bad-bot', $entry['rule']);
    assert_eq('block', $entry['mode']);
});

run_tests();
