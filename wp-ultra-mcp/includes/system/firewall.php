<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Firewall / WAF-lite engine (Roadmap-2 C4).
 *
 * An APPLICATION-LAYER PHP firewall — defence-in-depth, not a perimeter. It
 * only sees requests that reach PHP (a CDN/page cache answers before it), and
 * spoofable headers are treated accordingly (client IP = REMOTE_ADDR only).
 *
 * Protections (evaluated in this order — first block wins):
 *   1. IP allowlist  — matching IPs ALWAYS bypass everything below.
 *   2. IP blocklist  — plain IPs or CIDRs (IPv4 + IPv6).
 *   3. Bad bots      — case-insensitive user-agent substrings. Defaults are
 *                      attack/scanner tooling ONLY (sqlmap, nikto, wpscan…) —
 *                      NEVER legit crawlers (Googlebot, semrush, ahrefs).
 *   4. Country block — ISO2 list, FAIL-OPEN: unknown country => allow.
 *   5. Request rules — high-signal path/query attack signatures (traversal,
 *                      wp-config probes, SQLi, XSS, uploads-php…). LOG-ONLY
 *                      by default even in block mode (pattern false positives
 *                      are dangerous); set rules.log_only=false to enforce.
 *   6. Rate limit    — per-IP requests/minute; NEVER applies to logged-in users.
 *
 * Modes: 'off' (default — nothing runs), 'log' (verdicts recorded, nothing
 * blocked — the DEFAULT operating mode after enabling), 'block' (enforce).
 * Recommended flow: enable in log mode, review wpultra_firewall_log for a day,
 * then switch to block explicitly.
 *
 * SELF-LOCKOUT SAFEGUARDS (absolute — the firewall must never lock out the
 * site owner or the AI driving it over MCP):
 *   - Hard bypass BEFORE any evaluation: CLI SAPI, DOING_CRON, is_admin(),
 *     loopback REMOTE_ADDR (127.0.0.1 / ::1), and any request path touching
 *     /wp-admin, /wp-login.php, /wp-json/mcp or /wp-json/wpultra
 *     (wpultra_fw_bypass_path — deliberately broad; erring toward bypass is
 *     the safe direction).
 *   - Logged-in administrators (manage_options) always bypass.
 *   - Allowlisted IPs always bypass (checked before the blocklist).
 *   - Rate limiting never applies to any logged-in user.
 *   - Country blocking fails open when the country cannot be resolved.
 *   - The whole evaluator runs inside try/catch — the firewall itself must
 *     never fatal a request (it FAILS OPEN on internal errors).
 *
 * Layout: PURE functions first (prefix wpultra_fw_ — no WordPress calls, unit
 * tested in tests/firewall.test.php), then guarded WordPress wrappers
 * (wpultra_firewall_*). The controller calls wpultra_firewall_boot() on
 * plugins_loaded; boot is cheap (one autoloaded option read, returns instantly
 * when mode is 'off') and hooks the evaluator on 'init' priority 1 — after
 * auth is set up, so the admin bypass and the logged-in rate-limit exemption
 * can see the current user.
 *
 * Options:
 *   wpultra_firewall      (autoloaded)     — config, shape in wpultra_fw_default_config()
 *   wpultra_firewall_log  (non-autoloaded) — verdict ring buffer, cap 200, newest first
 */

const WPULTRA_FIREWALL_OPTION     = 'wpultra_firewall';
const WPULTRA_FIREWALL_LOG_OPTION = 'wpultra_firewall_log';
const WPULTRA_FW_LOG_CAP          = 200;

/* =====================================================================
 * PURE — config shape + normalization.
 * ===================================================================== */

/** PURE. The full default config. Mode 'off': the firewall does nothing until enabled. */
function wpultra_fw_default_config(): array {
    return [
        'mode'            => 'off',            // 'off' | 'log' | 'block'
        'rate_limit'      => ['enabled' => true, 'per_minute' => 120],
        'ip_allow'        => [],               // IPs or CIDRs — always bypass
        'ip_block'        => [],               // IPs or CIDRs
        'bad_bots'        => ['enabled' => true, 'extra' => []],
        'countries_block' => [],               // ISO2 codes
        'rules'           => ['enabled' => false, 'log_only' => true],
    ];
}

/** PURE. Is a string a valid plain IP (v4/v6) or CIDR? */
function wpultra_fw_valid_ip_or_cidr(string $s): bool {
    $s = trim($s);
    if ($s === '') { return false; }
    if (strpos($s, '/') === false) { return @inet_pton($s) !== false; }
    $parts = explode('/', $s, 2);
    if (count($parts) !== 2 || $parts[1] === '' || !ctype_digit($parts[1])) { return false; }
    $bin = @inet_pton(trim($parts[0]));
    if ($bin === false) { return false; }
    return (int) $parts[1] <= strlen($bin) * 8;
}

/** PURE. Sanitize an IP/CIDR list: strings, trimmed, valid entries only, deduped. */
function wpultra_fw_clean_ip_list($list): array {
    if (!is_array($list)) { return []; }
    $out = [];
    foreach ($list as $entry) {
        $entry = trim((string) $entry);
        if ($entry !== '' && wpultra_fw_valid_ip_or_cidr($entry) && !in_array($entry, $out, true)) {
            $out[] = $entry;
        }
    }
    return $out;
}

/**
 * PURE. Merge an arbitrary stored/user value into a fully-shaped config:
 * unknown mode => 'off', per_minute clamped to 10..1000, IP lists validated,
 * country codes filtered to uppercase ISO2, missing keys take defaults.
 */
function wpultra_fw_normalize_config($raw): array {
    $cfg = wpultra_fw_default_config();
    if (!is_array($raw)) { return $cfg; }

    $mode = strtolower(trim((string) ($raw['mode'] ?? 'off')));
    $cfg['mode'] = in_array($mode, ['off', 'log', 'block'], true) ? $mode : 'off';

    if (isset($raw['rate_limit']) && is_array($raw['rate_limit'])) {
        if (array_key_exists('enabled', $raw['rate_limit'])) {
            $cfg['rate_limit']['enabled'] = (bool) $raw['rate_limit']['enabled'];
        }
        if (array_key_exists('per_minute', $raw['rate_limit'])) {
            $cfg['rate_limit']['per_minute'] = max(10, min(1000, (int) $raw['rate_limit']['per_minute']));
        }
    }

    $cfg['ip_allow'] = wpultra_fw_clean_ip_list($raw['ip_allow'] ?? []);
    $cfg['ip_block'] = wpultra_fw_clean_ip_list($raw['ip_block'] ?? []);

    if (isset($raw['bad_bots']) && is_array($raw['bad_bots'])) {
        if (array_key_exists('enabled', $raw['bad_bots'])) {
            $cfg['bad_bots']['enabled'] = (bool) $raw['bad_bots']['enabled'];
        }
        $extra = [];
        foreach ((array) ($raw['bad_bots']['extra'] ?? []) as $ua) {
            $ua = strtolower(trim((string) $ua));
            if ($ua !== '' && !in_array($ua, $extra, true)) { $extra[] = $ua; }
        }
        $cfg['bad_bots']['extra'] = $extra;
    }

    $countries = [];
    foreach ((array) ($raw['countries_block'] ?? []) as $c) {
        $c = strtoupper(trim((string) $c));
        if (preg_match('/^[A-Z]{2}$/', $c) && !in_array($c, $countries, true)) { $countries[] = $c; }
    }
    $cfg['countries_block'] = $countries;

    if (isset($raw['rules']) && is_array($raw['rules'])) {
        if (array_key_exists('enabled', $raw['rules'])) {
            $cfg['rules']['enabled'] = (bool) $raw['rules']['enabled'];
        }
        if (array_key_exists('log_only', $raw['rules'])) {
            $cfg['rules']['log_only'] = (bool) $raw['rules']['log_only'];
        }
    }

    return $cfg;
}

/* =====================================================================
 * PURE — self-lockout path bypass.
 * ===================================================================== */

/**
 * PURE. Must this request path NEVER be firewalled? Matches /wp-admin,
 * /wp-login.php, /wp-json/mcp and /wp-json/wpultra at the path start OR after
 * a subdirectory prefix (subdir installs like /blog/wp-admin). Deliberately
 * broad — a false bypass is safe, a false block can lock the owner out.
 */
function wpultra_fw_bypass_path(string $path): bool {
    $path = strtolower(trim($path));
    if ($path === '') { return false; }
    // Strip any query fragment defensively; callers should pass the path part only.
    $q = strpos($path, '?');
    if ($q !== false) { $path = substr($path, 0, $q); }
    if ($path === '' || $path[0] !== '/') { $path = '/' . $path; }
    foreach (['/wp-admin', '/wp-login.php', '/wp-json/mcp', '/wp-json/wpultra'] as $needle) {
        $pos = strpos($path, $needle);
        if ($pos === false) { continue; }
        $after = substr($path, $pos + strlen($needle), 1);
        if ($after === '' || $after === '/') { return true; }
    }
    return false;
}

/* =====================================================================
 * PURE — IP / CIDR matching (IPv4 + IPv6).
 * ===================================================================== */

/**
 * PURE. Does $ip fall inside $cidr? $cidr may be a plain IP (equality via
 * inet_pton, so `::1` == `0:0:0:0:0:0:0:1`) or a CIDR (IPv4 or IPv6, bit
 * comparison over the prefix via inet_pton). Invalid input, an out-of-range
 * prefix or an address-family mismatch => false (never fatal).
 */
function wpultra_fw_ip_in_cidr(string $ip, string $cidr): bool {
    $ip   = trim($ip);
    $cidr = trim($cidr);
    if ($ip === '' || $cidr === '') { return false; }

    if (strpos($cidr, '/') === false) {
        $a = @inet_pton($ip);
        $b = @inet_pton($cidr);
        return $a !== false && $b !== false && $a === $b;
    }

    $parts = explode('/', $cidr, 2);
    if (count($parts) !== 2 || $parts[1] === '' || !ctype_digit($parts[1])) { return false; }
    $bits   = (int) $parts[1];
    $ipBin  = @inet_pton($ip);
    $netBin = @inet_pton(trim($parts[0]));
    if ($ipBin === false || $netBin === false) { return false; }
    if (strlen($ipBin) !== strlen($netBin)) { return false; } // v4 vs v6 mismatch
    $max = strlen($ipBin) * 8;
    if ($bits > $max) { return false; }
    if ($bits === 0) { return true; }

    $fullBytes = intdiv($bits, 8);
    $remainder = $bits % 8;
    if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($netBin, 0, $fullBytes)) { return false; }
    if ($remainder === 0) { return true; }
    $mask = (0xFF << (8 - $remainder)) & 0xFF;
    return ((ord($ipBin[$fullBytes]) ^ ord($netBin[$fullBytes])) & $mask) === 0;
}

/** PURE. Does $ip match ANY entry (plain IP or CIDR) in $list? */
function wpultra_fw_ip_in_list(string $ip, array $list): bool {
    foreach ($list as $entry) {
        if (wpultra_fw_ip_in_cidr($ip, (string) $entry)) { return true; }
    }
    return false;
}

/* =====================================================================
 * PURE — bad-bot user-agent matching.
 * ===================================================================== */

/**
 * PURE. Default bad-bot UA substrings — attack/scanner tooling ONLY.
 * Deliberately does NOT include legitimate crawlers (googlebot, bingbot,
 * semrush, ahrefs, …): blocking those would hurt SEO. Site-specific pests go
 * in bad_bots.extra.
 */
function wpultra_fw_bad_bot_defaults(): array {
    return [
        'sqlmap', 'nikto', 'wpscan', 'masscan', 'nessus', 'openvas',
        'acunetix', 'netsparker', 'dirbuster', 'joomscan', 'fimap', 'havij',
        'zgrab', 'arachni', 'w3af', 'hydra', 'zmeu', 'morfeus',
        'libwww-perl', 'commix', 'xsser', 'nuclei', 'gobuster', 'feroxbuster',
        'muieblackcat', 'jorgee',
    ];
}

/**
 * PURE. Case-insensitive substring match of $ua against the default bad-bot
 * list plus $extra. An EMPTY user-agent is never flagged (fail-open — plenty
 * of legitimate monitors and health checks send none).
 */
function wpultra_fw_bad_bot(string $ua, array $extra = []): bool {
    $ua = strtolower(trim($ua));
    if ($ua === '') { return false; }
    foreach (array_merge(wpultra_fw_bad_bot_defaults(), $extra) as $needle) {
        $needle = strtolower(trim((string) $needle));
        if ($needle !== '' && strpos($ua, $needle) !== false) { return true; }
    }
    return false;
}

/* =====================================================================
 * PURE — country blocking (FAIL-OPEN).
 * ===================================================================== */

/**
 * PURE. Is $country (ISO2) on the blocklist? FAIL-OPEN: an empty, malformed
 * or unresolvable country code NEVER blocks.
 */
function wpultra_fw_country_blocked(string $country, array $blocked): bool {
    $c = strtoupper(trim($country));
    if (!preg_match('/^[A-Z]{2}$/', $c)) { return false; } // unknown => allow
    foreach ($blocked as $b) {
        if (strtoupper(trim((string) $b)) === $c) { return true; }
    }
    return false;
}

/* =====================================================================
 * PURE — request-pattern rules (WAF-lite signatures).
 * ===================================================================== */

/**
 * PURE. High-signal attack patterns checked against the request path and/or
 * raw query string. Kept intentionally narrow — these are LOG-ONLY by default
 * even in block mode because pattern false positives are dangerous.
 *
 * @return array<int,array{id:string,regex:string,target:string,label:string}>
 */
function wpultra_fw_rule_patterns(): array {
    return [
        ['id' => 'traversal',   'regex' => '/\.\.[\/\\\\]/',                            'target' => 'both', 'label' => 'Path traversal (../)'],
        ['id' => 'wp_config',   'regex' => '/wp-config\.php/i',                         'target' => 'both', 'label' => 'wp-config.php probe'],
        ['id' => 'env_probe',   'regex' => '/\/\.env(?:$|[^a-z0-9])/i',                 'target' => 'path', 'label' => '.env file probe'],
        ['id' => 'sqli',        'regex' => '/\b(union\s+(all\s+)?select|information_schema|sleep\s*\(|benchmark\s*\()/i', 'target' => 'both', 'label' => 'SQL injection signature'],
        ['id' => 'xss',         'regex' => '/<script\b|javascript\s*:/i',               'target' => 'both', 'label' => 'Cross-site scripting signature'],
        ['id' => 'null_byte',   'regex' => '/%00/',                                     'target' => 'both', 'label' => 'Null-byte injection'],
        ['id' => 'uploads_php', 'regex' => '/\/uploads\/.*\.php\b/i',                   'target' => 'path', 'label' => 'PHP execution attempt under uploads'],
        ['id' => 'etc_passwd',  'regex' => '/etc\/passwd/i',                            'target' => 'both', 'label' => '/etc/passwd disclosure attempt'],
    ];
}

/**
 * PURE. Match the rule matrix against a request path + raw query string.
 * Both the raw and URL-decoded forms are inspected so %2e%2e%2f-style
 * encoding can't slip past.
 *
 * @return array<int,array{id:string,label:string}> matched rules (one hit per rule id)
 */
function wpultra_fw_match_rules(string $path, string $query, ?array $patterns = null): array {
    $patterns = $patterns ?? wpultra_fw_rule_patterns();
    $subjects = [
        'path'  => $path . "\n" . rawurldecode($path),
        'query' => $query . "\n" . rawurldecode($query),
    ];
    $hits = [];
    foreach ($patterns as $p) {
        $target = (string) ($p['target'] ?? 'both');
        if ($target === 'path') {
            $blob = $subjects['path'];
        } elseif ($target === 'query') {
            $blob = $subjects['query'];
        } else {
            $blob = $subjects['path'] . "\n" . $subjects['query'];
        }
        if (@preg_match((string) ($p['regex'] ?? ''), $blob) === 1) {
            $hits[] = ['id' => (string) ($p['id'] ?? ''), 'label' => (string) ($p['label'] ?? '')];
        }
    }
    return $hits;
}

/* =====================================================================
 * PURE — the evaluator.
 * ===================================================================== */

/**
 * PURE. Evaluate one request against the config. Order (first block wins):
 * allowlist -> blocklist -> bad bots -> country -> request rules -> rate limit.
 *
 * $ctx: {ip, ua, path, query, logged_in, country}. $current_rate: requests
 * from this IP in the current minute (0 = unknown / not counted).
 *
 * Returns {verdict:'allow'|'block', rule:string, detail:string, enforce:bool}.
 * 'enforce' is false for request-rule hits while rules.log_only is true —
 * those must be logged but never blocked, even in block mode. A log-only rule
 * hit does NOT shadow the rate limiter: if the request is also over the rate
 * limit, the enforceable rate-limit verdict is returned instead.
 *
 * Self-lockout invariants encoded here: the allowlist wins over everything,
 * the rate limit never applies to logged-in users, and country blocking fails
 * open on unknown countries. (SAPI/cron/admin/path/loopback bypasses happen
 * BEFORE this function is ever called — see wpultra_firewall_run().)
 */
function wpultra_fw_evaluate(array $cfg, array $ctx, int $current_rate): array {
    $cfg     = wpultra_fw_normalize_config($cfg);
    $ip      = trim((string) ($ctx['ip'] ?? ''));
    $ua      = (string) ($ctx['ua'] ?? '');
    $path    = (string) ($ctx['path'] ?? '/');
    $query   = (string) ($ctx['query'] ?? '');
    $logged  = !empty($ctx['logged_in']);
    $country = (string) ($ctx['country'] ?? '');

    // 1. Allowlist — ALWAYS bypasses everything below.
    if ($ip !== '' && wpultra_fw_ip_in_list($ip, $cfg['ip_allow'])) {
        return ['verdict' => 'allow', 'rule' => 'ip-allow', 'detail' => "IP $ip is on the allowlist", 'enforce' => false];
    }

    // 2. Blocklist.
    if ($ip !== '' && wpultra_fw_ip_in_list($ip, $cfg['ip_block'])) {
        return ['verdict' => 'block', 'rule' => 'ip-block', 'detail' => "IP $ip matches the IP blocklist", 'enforce' => true];
    }

    // 3. Bad bots.
    if (!empty($cfg['bad_bots']['enabled']) && wpultra_fw_bad_bot($ua, $cfg['bad_bots']['extra'])) {
        return ['verdict' => 'block', 'rule' => 'bad-bot', 'detail' => 'User-agent matches the bad-bot list', 'enforce' => true];
    }

    // 4. Country block — fail-open on unknown country.
    if ($cfg['countries_block'] !== [] && wpultra_fw_country_blocked($country, $cfg['countries_block'])) {
        return ['verdict' => 'block', 'rule' => 'country', 'detail' => 'Country ' . strtoupper(trim($country)) . ' is blocked', 'enforce' => true];
    }

    // 5. Request rules — possibly log-only (held pending so the rate limiter still runs).
    $pending = null;
    if (!empty($cfg['rules']['enabled'])) {
        $matches = wpultra_fw_match_rules($path, $query);
        if ($matches !== []) {
            $ids = implode(', ', array_column($matches, 'id'));
            $pending = [
                'verdict' => 'block',
                'rule'    => 'request-rules',
                'detail'  => "Matched request rule(s): $ids",
                'enforce' => empty($cfg['rules']['log_only']),
            ];
            if ($pending['enforce']) { return $pending; }
        }
    }

    // 6. Rate limit — NEVER for logged-in users. Block only strictly OVER the limit.
    if (!empty($cfg['rate_limit']['enabled']) && !$logged && $current_rate > (int) $cfg['rate_limit']['per_minute']) {
        return [
            'verdict' => 'block',
            'rule'    => 'rate-limit',
            'detail'  => "$current_rate requests this minute (limit {$cfg['rate_limit']['per_minute']})",
            'enforce' => true,
        ];
    }

    if ($pending !== null) { return $pending; } // log-only rule hit

    return ['verdict' => 'allow', 'rule' => '', 'detail' => '', 'enforce' => false];
}

/** PURE. HTTP status for an enforced block: 429 for rate limiting, 403 otherwise. */
function wpultra_fw_http_status(string $rule): int {
    return $rule === 'rate-limit' ? 429 : 403;
}

/* =====================================================================
 * PURE — verdict log ring helpers.
 * ===================================================================== */

/** PURE. Build one log entry (path truncated to 200 chars, ua to 120). */
function wpultra_fw_log_entry(array $ctx, string $rule, string $detail, string $mode, int $at = 0): array {
    return [
        'at'     => gmdate('Y-m-d H:i:s', $at > 0 ? $at : time()),
        'ip'     => (string) ($ctx['ip'] ?? ''),
        'rule'   => $rule,
        'detail' => $detail,
        'path'   => substr((string) ($ctx['path'] ?? ''), 0, 200),
        'ua'     => substr((string) ($ctx['ua'] ?? ''), 0, 120),
        'mode'   => $mode,
    ];
}

/** PURE. Prepend an entry to the ring (newest first), capped. */
function wpultra_fw_log_push(array $ring, array $entry, int $cap = WPULTRA_FW_LOG_CAP): array {
    array_unshift($ring, $entry);
    if ($cap > 0 && count($ring) > $cap) { $ring = array_slice($ring, 0, $cap); }
    return array_values($ring);
}

/* =====================================================================
 * WordPress wrappers (all guarded — the file loads under the pure test
 * harness, which never calls these).
 * ===================================================================== */

/** Read + normalize the persisted config. WP-touching. */
function wpultra_firewall_get_config(): array {
    $raw = function_exists('get_option') ? get_option(WPULTRA_FIREWALL_OPTION, []) : [];
    return wpultra_fw_normalize_config($raw);
}

/** Persist a config (normalized first). Autoloaded — boot reads it on every request. */
function wpultra_firewall_save_config(array $cfg): array {
    $cfg = wpultra_fw_normalize_config($cfg);
    if (function_exists('update_option')) { update_option(WPULTRA_FIREWALL_OPTION, $cfg, true); }
    return $cfg;
}

/** Read the verdict log ring (newest first). WP-touching. */
function wpultra_firewall_log_read(): array {
    $v = function_exists('get_option') ? get_option(WPULTRA_FIREWALL_LOG_OPTION, []) : [];
    return is_array($v) ? $v : [];
}

/** Append a verdict to the log ring. Non-autoloaded option. Best-effort. */
function wpultra_firewall_log_append(array $ctx, string $rule, string $detail, string $mode): void {
    if (!function_exists('update_option')) { return; }
    $ring = wpultra_fw_log_push(wpultra_firewall_log_read(), wpultra_fw_log_entry($ctx, $rule, $detail, $mode));
    update_option(WPULTRA_FIREWALL_LOG_OPTION, $ring, false);
}

/** Clear the verdict log. WP-touching. */
function wpultra_firewall_log_clear(): void {
    if (function_exists('update_option')) { update_option(WPULTRA_FIREWALL_LOG_OPTION, [], false); }
}

/** The client IP. REMOTE_ADDR only — proxy headers are trivially spoofable. */
function wpultra_firewall_client_ip(): string {
    return isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '';
}

/**
 * Resolve the request country from common edge/CDN headers (Cloudflare's
 * CF-IPCountry, mod_geoip, X-Country-Code). Returns '' when unknown —
 * country blocking then FAILS OPEN.
 */
function wpultra_firewall_country(): string {
    foreach (['HTTP_CF_IPCOUNTRY', 'GEOIP_COUNTRY_CODE', 'HTTP_X_COUNTRY_CODE', 'HTTP_X_GEO_COUNTRY'] as $key) {
        $v = isset($_SERVER[$key]) ? strtoupper(trim((string) $_SERVER[$key])) : '';
        if ($v !== 'XX' && $v !== 'T1' && preg_match('/^[A-Z]{2}$/', $v)) { return $v; }
    }
    return '';
}

/**
 * Increment + return this IP's request count for the current minute
 * (transient-backed, fixed one-minute window). 0 when transients unavailable
 * — which can never block, because the limit floor is 10.
 */
function wpultra_firewall_rate_count(string $ip): int {
    if (!function_exists('get_transient') || !function_exists('set_transient')) { return 0; }
    $key = 'wpultra_fw_' . md5($ip . '|' . gmdate('YmdHi'));
    $n = (int) get_transient($key) + 1;
    set_transient($key, $n, 120);
    return $n;
}

/**
 * Boot hook — the controller calls this on plugins_loaded (this file only
 * defines it). CHEAP: one autoloaded option read; when the mode is 'off' (the
 * default) it returns immediately and nothing else ever runs. Otherwise the
 * evaluator is hooked on 'init' priority 1 — after auth is available, so the
 * admin bypass and the logged-in rate-limit exemption see the current user.
 */
function wpultra_firewall_boot(): void {
    if (!function_exists('get_option') || !function_exists('add_action')) { return; }
    $raw  = get_option(WPULTRA_FIREWALL_OPTION, []);
    $mode = is_array($raw) ? strtolower((string) ($raw['mode'] ?? 'off')) : 'off';
    if ($mode !== 'log' && $mode !== 'block') { return; }
    add_action('init', 'wpultra_firewall_run', 1);
}

/**
 * The per-request evaluator (init prio 1). Wrapped in try/catch — the
 * firewall itself must NEVER fatal a request (fails open). Applies every
 * hard self-lockout bypass before computing a verdict; in 'log' mode
 * verdicts are only recorded, never enforced.
 */
function wpultra_firewall_run(): void {
    try {
        $cfg = wpultra_firewall_get_config();
        if ($cfg['mode'] === 'off') { return; }

        // ---- HARD BYPASSES (self-lockout safeguards) ----
        if (php_sapi_name() === 'cli') { return; }
        if (defined('DOING_CRON') && DOING_CRON) { return; }
        if (function_exists('is_admin') && is_admin()) { return; }

        $ip = wpultra_firewall_client_ip();
        if ($ip === '' || $ip === '127.0.0.1' || $ip === '::1') { return; }

        $uri  = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');
        if (wpultra_fw_bypass_path($path)) { return; }

        $logged = function_exists('is_user_logged_in') && is_user_logged_in();
        if ($logged && function_exists('current_user_can') && current_user_can('manage_options')) { return; }
        // ---- end bypasses ----

        $ctx = [
            'ip'        => $ip,
            'ua'        => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'path'      => $path,
            'query'     => (string) ($_SERVER['QUERY_STRING'] ?? ''),
            'logged_in' => $logged,
            'country'   => wpultra_firewall_country(),
        ];

        $rate = 0;
        if (!$logged && !empty($cfg['rate_limit']['enabled'])) {
            $rate = wpultra_firewall_rate_count($ip);
        }

        $v = wpultra_fw_evaluate($cfg, $ctx, $rate);
        if (($v['verdict'] ?? 'allow') !== 'block') { return; }

        wpultra_firewall_log_append($ctx, (string) $v['rule'], (string) $v['detail'], $cfg['mode']);

        if ($cfg['mode'] === 'block' && !empty($v['enforce'])) {
            $status  = wpultra_fw_http_status((string) $v['rule']);
            $message = $status === 429
                ? 'Too many requests. Please slow down and try again in a minute.'
                : 'This request was blocked by the site firewall.';
            if (function_exists('nocache_headers')) { nocache_headers(); }
            if (function_exists('wp_die')) {
                wp_die($message, 'Request blocked', ['response' => $status]);
            }
            exit; // wp_die exits; this is the no-WP fallback.
        }
    } catch (\Throwable $e) {
        // FAIL OPEN — never let the firewall break the site. Breadcrumb only.
        @error_log('wpultra firewall: ' . $e->getMessage());
    }
}
