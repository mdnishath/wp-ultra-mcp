<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Front-end JS error capture (Roadmap-4 Wave BF2.4): a tiny inline snippet
 * listens for window.onerror + unhandledrejection and POSTs a beacon to
 * /wp-json/wpultra/v1/jserror, which validates + sanitizes the payload and
 * pushes it into a capped ring buffer option (`wpultra_js_error_log`, cap 50)
 * — the same shape of pattern as the fatal-error ring in system/errors.php,
 * but for client-side JS instead of server-side PHP fatals.
 *
 * Zero front-end footprint by default: the snippet is only enqueued (on
 * wp_enqueue_scripts) when the `wpultra_jserrors_enabled` option is truthy.
 * The beacon REST route is ALWAYS registered (so toggling the option needs no
 * cache-bust / route re-registration) but no-ops with 204 while disabled.
 *
 * The controller hooks wpultra_jserrors_boot() into the always-on runtime
 * bootstrap (recommended: wpultra_load_monitors_runtime(), alongside
 * wpultra_errors_boot(), under the 'diagnostics' category gate); this file
 * only defines it.
 */

if (!defined('WPULTRA_JSERRORS_OPTION'))         { define('WPULTRA_JSERRORS_OPTION', 'wpultra_js_error_log'); }
if (!defined('WPULTRA_JSERRORS_ENABLED_OPTION')) { define('WPULTRA_JSERRORS_ENABLED_OPTION', 'wpultra_jserrors_enabled'); }
if (!defined('WPULTRA_JSERRORS_CAP'))            { define('WPULTRA_JSERRORS_CAP', 50); }
if (!defined('WPULTRA_JSERRORS_RATE_LIMIT'))     { define('WPULTRA_JSERRORS_RATE_LIMIT', 30); } // beacons/min per IP
if (!defined('WPULTRA_JSERRORS_DEDUPE_WINDOW'))  { define('WPULTRA_JSERRORS_DEDUPE_WINDOW', 30); } // seconds, server-side backstop

/* ------------------------------------------------------------------ *
 * PURE helpers — no WordPress. These are what tests/jserrors.test.php covers.
 * ------------------------------------------------------------------ */

/** Truncate a string to $n chars/bytes (mb-aware when available). Pure. */
function wpultra_jserrors_cap_str(string $s, int $n): string {
    return function_exists('mb_substr') ? mb_substr($s, 0, $n) : substr($s, 0, $n);
}

/**
 * Normalize + cap an incoming beacon payload. Sanitizers are passed in so this
 * stays testable without WordPress (in prod, pass 'sanitize_text_field' and
 * 'esc_url_raw'). Missing fields default to '' / 0. Caps: message 500, source
 * 300, stack 2000, url 500, ua 300 chars — applied AFTER sanitizing so a
 * sanitizer that shrinks/escapes text can't cause an over-long final value.
 *
 * @param array    $raw           Raw client payload (message, source, lineno, colno, stack, url, ua).
 * @param callable $sanitize_text fn(string): string — applied to message/source/stack/ua.
 * @param callable $esc_url       fn(string): string — applied to url.
 */
function wpultra_jserrors_sanitize_payload(array $raw, callable $sanitize_text, callable $esc_url): array {
    $message = $sanitize_text((string) ($raw['message'] ?? ''));
    $source  = $sanitize_text((string) ($raw['source'] ?? ''));
    $stack   = $sanitize_text((string) ($raw['stack'] ?? ''));
    $ua      = $sanitize_text((string) ($raw['ua'] ?? ''));
    $url     = $esc_url((string) ($raw['url'] ?? ''));

    $lineno = isset($raw['lineno']) && is_numeric($raw['lineno']) ? max(0, (int) $raw['lineno']) : 0;
    $colno  = isset($raw['colno'])  && is_numeric($raw['colno'])  ? max(0, (int) $raw['colno'])  : 0;

    return [
        'message' => wpultra_jserrors_cap_str($message, 500),
        'source'  => wpultra_jserrors_cap_str($source, 300),
        'lineno'  => $lineno,
        'colno'   => $colno,
        'stack'   => wpultra_jserrors_cap_str($stack, 2000),
        'url'     => wpultra_jserrors_cap_str($url, 500),
        'ua'      => wpultra_jserrors_cap_str($ua, 300),
    ];
}

/**
 * Stable dedupe key for an entry: same message+source+lineno+colno always
 * produces the same key, regardless of key order or extra fields (ts, ip,
 * etc. are ignored). Pure.
 */
function wpultra_jserrors_dedupe_key(array $entry): string {
    $message = (string) ($entry['message'] ?? '');
    $source  = (string) ($entry['source'] ?? '');
    $lineno  = (string) ($entry['lineno'] ?? '0');
    $colno   = (string) ($entry['colno'] ?? '0');
    return md5($message . '|' . $source . '|' . $lineno . '|' . $colno);
}

/** Pure: prepend an entry (newest first) and cap the ring length. */
function wpultra_jserrors_ring_push(array $ring, array $entry, int $cap = WPULTRA_JSERRORS_CAP): array {
    array_unshift($ring, $entry);
    if ($cap > 0 && count($ring) > $cap) { $ring = array_slice($ring, 0, $cap); }
    return array_values($ring);
}

/** Pure: short, non-reversible fingerprint of an IP (never store raw IPs in the ring). */
function wpultra_jserrors_hash_ip(string $ip): string {
    if ($ip === '') { return ''; }
    return substr(md5($ip), 0, 12);
}

/** Pure: build a full ring entry from a sanitized payload + server-side context. */
function wpultra_jserrors_make_entry(int $ts, array $sanitized, string $ip_hash): array {
    $entry         = $sanitized;
    $entry['ts']   = $ts;
    $entry['ip']   = $ip_hash;
    $entry['key']  = wpultra_jserrors_dedupe_key($sanitized);
    return $entry;
}

/**
 * Server-side backstop dedupe: true when $key matches the ring's newest entry
 * and it's within $window seconds. Only checks the newest entry (cheap) — the
 * client-side throttle in the snippet handles the general case; this just
 * stops a slow/broken client from double-firing the exact same error via two
 * requests seconds apart. Pure.
 */
function wpultra_jserrors_is_recent_dupe(array $ring, string $key, int $now, int $window = WPULTRA_JSERRORS_DEDUPE_WINDOW): bool {
    if (empty($ring)) { return false; }
    $newest = $ring[0];
    if ((string) ($newest['key'] ?? '') !== $key) { return false; }
    $prev_ts = (int) ($newest['ts'] ?? 0);
    return ($now - $prev_ts) <= $window;
}

/* ------------------------------------------------------------------ *
 * Store (thin WordPress wrappers).
 * ------------------------------------------------------------------ */

function wpultra_jserrors_load_ring(): array {
    $v = function_exists('get_option') ? get_option(WPULTRA_JSERRORS_OPTION, []) : [];
    return is_array($v) ? $v : [];
}

function wpultra_jserrors_save_ring(array $ring): void {
    if (function_exists('update_option')) { update_option(WPULTRA_JSERRORS_OPTION, $ring, false); }
}

function wpultra_jserrors_is_enabled(): bool {
    if (!function_exists('get_option')) { return false; }
    return get_option(WPULTRA_JSERRORS_ENABLED_OPTION, '') === '1';
}

function wpultra_jserrors_set_enabled(bool $on): void {
    if (function_exists('update_option')) { update_option(WPULTRA_JSERRORS_ENABLED_OPTION, $on ? '1' : '0', false); }
}

/** Read recent entries, newest-first. Filters: limit (<=WPULTRA_JSERRORS_CAP). */
function wpultra_jserrors_read(array $filters = []): array {
    $ring = wpultra_jserrors_load_ring();
    $limit = isset($filters['limit']) ? max(1, min(WPULTRA_JSERRORS_CAP, (int) $filters['limit'])) : WPULTRA_JSERRORS_CAP;
    if (count($ring) > $limit) { $ring = array_slice($ring, 0, $limit); }
    return $ring;
}

function wpultra_jserrors_clear(): void {
    wpultra_jserrors_save_ring([]);
}

/* ------------------------------------------------------------------ *
 * Beacon REST route.
 * ------------------------------------------------------------------ */

/** Soft per-IP rate limit: true when this beacon is allowed through. */
function wpultra_jserrors_within_limit(): bool {
    if (!function_exists('get_transient') || !function_exists('set_transient')) { return true; }
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $key = 'wpultra_jserr_rl_' . md5($ip);
    $count = (int) get_transient($key);
    if ($count >= WPULTRA_JSERRORS_RATE_LIMIT) { return false; }
    set_transient($key, $count + 1, 60);
    return true;
}

function wpultra_jserrors_register_routes(): void {
    if (!function_exists('register_rest_route')) { return; }
    register_rest_route('wpultra/v1', '/jserror', [
        'methods'             => 'POST',
        'permission_callback' => '__return_true', // public beacon — cached pages can't auth
        'callback'            => 'wpultra_jserrors_rest_cb',
    ]);
}

/** @param WP_REST_Request $req */
function wpultra_jserrors_rest_cb($req) {
    if (!wpultra_jserrors_is_enabled()) {
        return new WP_REST_Response(null, 204);
    }
    if (!wpultra_jserrors_within_limit()) {
        return new WP_REST_Response(['ok' => false, 'error' => 'rate_limited'], 429);
    }

    $raw = $req->get_json_params();
    if (!is_array($raw)) { $raw = []; }

    $sanitize_text = function_exists('sanitize_text_field')
        ? 'sanitize_text_field'
        : static function (string $s): string { return trim(strip_tags($s)); };
    $esc_url = function_exists('esc_url_raw')
        ? 'esc_url_raw'
        : static function (string $s): string { return $s; };

    $sanitized = wpultra_jserrors_sanitize_payload($raw, $sanitize_text, $esc_url);

    if ($sanitized['message'] === '') {
        return new WP_REST_Response(['ok' => false, 'error' => 'bad_request'], 400);
    }

    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $now = time();
    $entry = wpultra_jserrors_make_entry($now, $sanitized, wpultra_jserrors_hash_ip($ip));

    $ring = wpultra_jserrors_load_ring();
    if (!wpultra_jserrors_is_recent_dupe($ring, $entry['key'], $now)) {
        $ring = wpultra_jserrors_ring_push($ring, $entry);
        wpultra_jserrors_save_ring($ring);
    }

    return new WP_REST_Response(null, 204);
}

/* ------------------------------------------------------------------ *
 * Front-end snippet.
 * ------------------------------------------------------------------ */

/**
 * The inline snippet: window.onerror + unhandledrejection -> sendBeacon.
 * Client-side dedupe (5s window per message+lineno+colno key) avoids flooding
 * when the same error fires repeatedly (e.g. inside a render loop). No
 * dependencies, < 1KB minified.
 */
function wpultra_jserrors_snippet(): string {
    return <<<'JS'
(function(){
if(!window.navigator||!navigator.sendBeacon){return;}
var seen={};
function send(d){
var k=(d.message||'')+'|'+(d.lineno||0)+'|'+(d.colno||0);
var now=Date.now();
if(seen[k]&&(now-seen[k])<5000){return;}
seen[k]=now;
try{
navigator.sendBeacon(window.wpultraJsErrEndpoint||'/wp-json/wpultra/v1/jserror',JSON.stringify({
message:String(d.message||'').slice(0,500),
source:String(d.source||'').slice(0,300),
lineno:d.lineno||0,
colno:d.colno||0,
stack:String(d.stack||'').slice(0,2000),
url:location.href,
ua:navigator.userAgent
}));
}catch(e){}
}
window.addEventListener('error',function(e){
send({message:e.message,source:e.filename,lineno:e.lineno,colno:e.colno,stack:e.error&&e.error.stack});
});
window.addEventListener('unhandledrejection',function(e){
var r=e.reason;
send({message:'Unhandled rejection: '+(r&&r.message?r.message:String(r)),source:'',lineno:0,colno:0,stack:r&&r.stack});
});
})();
JS;
}

/**
 * Build the small "define the endpoint" inline script that must run BEFORE
 * the main snippet. Uses rest_url() (not a hardcoded '/wp-json/...' path) so
 * the beacon still resolves correctly on sites using Plain permalinks
 * (REST base becomes '/?rest_route=/...') or WordPress installed in a
 * subdirectory — same technique as ai/kb.php, marketing/ab.php and
 * marketing/popups.php.
 */
function wpultra_jserrors_endpoint_snippet(): string {
    $endpoint = function_exists('rest_url') ? rest_url('wpultra/v1/jserror') : '/wp-json/wpultra/v1/jserror';
    $endpoint = function_exists('esc_url_raw') ? esc_url_raw($endpoint) : $endpoint;
    $json     = function_exists('wp_json_encode') ? wp_json_encode($endpoint) : json_encode($endpoint);
    return 'window.wpultraJsErrEndpoint=' . $json . ';';
}

/** Enqueue the snippet on the front end — only when capture is enabled (zero footprint otherwise). */
function wpultra_jserrors_enqueue(): void {
    if (!wpultra_jserrors_is_enabled()) { return; }
    if (!function_exists('wp_register_script') || !function_exists('wp_enqueue_script') || !function_exists('wp_add_inline_script')) { return; }
    wp_register_script('wpultra-jserrors', '', [], defined('WPULTRA_VERSION') ? WPULTRA_VERSION : false, true);
    wp_enqueue_script('wpultra-jserrors');
    wp_add_inline_script('wpultra-jserrors', wpultra_jserrors_endpoint_snippet());
    wp_add_inline_script('wpultra-jserrors', wpultra_jserrors_snippet());
}

/* ------------------------------------------------------------------ *
 * Hook registration (always-on runtime) — controller wires this in.
 * ------------------------------------------------------------------ */

/**
 * Registers the (always-on, no-op-when-disabled) beacon route and the
 * (enabled-only) front-end snippet enqueue. Controller hooks this into the
 * always-on runtime bootstrap; this file only defines it.
 */
function wpultra_jserrors_boot(): void {
    if (!function_exists('add_action')) { return; }
    add_action('rest_api_init', 'wpultra_jserrors_register_routes');
    add_action('wp_enqueue_scripts', 'wpultra_jserrors_enqueue');
}
