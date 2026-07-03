<?php
declare(strict_types=1);
if (!defined('ABSPATH') && !defined('WPULTRA_TEST')) { /* allow harness load */ }

/**
 * IndexNow ping + 404 monitor.
 *
 * IndexNow: a lightweight ping protocol (https://www.indexnow.org/) — POST a JSON
 * body {host, key, keyLocation, urlList} to a search-engine endpoint so it knows
 * to (re)crawl the listed URLs. The protocol requires a random key that is also
 * published as a plaintext file at the site root (keyLocation) so the engine can
 * verify the pinging site actually controls that host.
 *
 * 404 monitor: a capped ring buffer of recent 404 hits (path/referer/ts), with a
 * pure grouping helper so an ability can surface "top 404s" and suggest pairing
 * them with seo-manage-redirects.
 */

// ---- IndexNow: key management ----

/** Where the IndexNow key verification file lives (site root, per protocol). */
function wpultra_indexnow_key_path(string $key): string {
    return rtrim(ABSPATH, '/\\') . '/' . $key . '.txt';
}

/**
 * Get (or generate + persist) the IndexNow key. A 32-char lowercase hex string,
 * stored in the 'wpultra_indexnow_key' option. The verification file at
 * ABSPATH/<key>.txt is (re)written whenever missing, since hosts occasionally
 * wipe stray root files (deploys, cache clears) and the protocol requires it
 * to be present for the key to validate.
 */
function wpultra_indexnow_key(): string {
    $key = (string) get_option('wpultra_indexnow_key', '');
    if (!preg_match('/^[a-f0-9]{32}$/', $key)) {
        $key = bin2hex(random_bytes(16));
        update_option('wpultra_indexnow_key', $key);
    }
    $path = wpultra_indexnow_key_path($key);
    if (!file_exists($path)) {
        // Best-effort: a read-only filesystem shouldn't fatal the whole request.
        @file_put_contents($path, $key);
    }
    return $key;
}

// ---- IndexNow: URL validation (pure) ----

/**
 * PURE. Split $urls into [valid, rejected]. Valid = well-formed http(s) URL whose
 * host matches $host (IndexNow only accepts same-host URLs in one batch), capped
 * at 100 entries (the protocol's per-request limit) — anything beyond the cap is
 * rejected too, since silently truncating would submit a shorter list without
 * telling the caller why.
 */
function wpultra_indexnow_validate_urls(array $urls, string $host): array {
    $host = strtolower(trim($host));
    $valid = [];
    $rejected = [];
    $seen = [];
    foreach ($urls as $u) {
        $u = trim((string) $u);
        if ($u === '') { continue; }
        if (count($valid) >= 100) { $rejected[] = $u; continue; }
        $parts = parse_url($u);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $uHost = strtolower((string) ($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $uHost === '' || $uHost !== $host) {
            $rejected[] = $u;
            continue;
        }
        if (isset($seen[$u])) { continue; } // silent de-dupe, not a rejection
        $seen[$u] = true;
        $valid[] = $u;
    }
    return [$valid, $rejected];
}

// ---- IndexNow: submit ----

/**
 * Submit $urls to the IndexNow API. Validates same-host + cap via
 * wpultra_indexnow_validate_urls(), then POSTs JSON to api.indexnow.org.
 * 200/202 = accepted. Returns WP_Error on transport failure or if there are
 * no valid URLs to submit.
 *
 * @return array|WP_Error {submitted:int, rejected:array, valid:array}
 */
function wpultra_indexnow_submit(array $urls) {
    if (!function_exists('wp_remote_post')) { return wpultra_err('wp_unavailable', 'wp_remote_post() is unavailable.'); }
    $home = function_exists('home_url') ? home_url() : '';
    $host = (string) (function_exists('wp_parse_url') ? wp_parse_url($home, PHP_URL_HOST) : parse_url($home, PHP_URL_HOST));
    if ($host === '') { return wpultra_err('no_host', 'Could not determine the site host.'); }

    [$valid, $rejected] = wpultra_indexnow_validate_urls($urls, $host);
    if (!$valid) { return wpultra_err('no_valid_urls', 'No valid same-host URLs to submit.', ['rejected' => $rejected]); }

    $key = wpultra_indexnow_key();
    $body = [
        'host'        => $host,
        'key'         => $key,
        'keyLocation' => (function_exists('home_url') ? home_url('/' . $key . '.txt') : "https://$host/$key.txt"),
        'urlList'     => $valid,
    ];

    $response = wp_remote_post('https://api.indexnow.org/indexnow', [
        'timeout' => 15,
        'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
        'body'    => function_exists('wp_json_encode') ? wp_json_encode($body) : json_encode($body),
    ]);
    if (is_wp_error($response)) { return $response; }

    $status = (int) wp_remote_retrieve_response_code($response);
    if ($status !== 200 && $status !== 202) {
        $raw = (string) wp_remote_retrieve_body($response);
        return wpultra_err('api_error', "IndexNow API error (HTTP $status): $raw");
    }

    return [
        'submitted'   => count($valid),
        'valid'       => $valid,
        'rejected'    => $rejected,
        'key'         => $key,
        'keyLocation' => $body['keyLocation'],
    ];
}

// ---- IndexNow: optional auto-ping on publish ----

add_action('transition_post_status', 'wpultra_indexnow_auto_hook', 10, 3);
/**
 * Auto-submit the permalink to IndexNow whenever a post transitions into
 * 'publish' (new publish or re-publish), gated by the 'wpultra_indexnow_auto'
 * option so it stays fully opt-in.
 */
function wpultra_indexnow_auto_hook($new_status, $old_status, $post) {
    if ($new_status !== 'publish' || $old_status === 'publish') { return; }
    if (!get_option('wpultra_indexnow_auto', false)) { return; }
    if (!is_object($post) || empty($post->ID)) { return; }
    $permalink = function_exists('get_permalink') ? get_permalink($post) : '';
    if (!$permalink) { return; }
    wpultra_indexnow_submit([$permalink]);
}

// ---- 404 monitor ----

/**
 * PURE. Should a 404 for $path be recorded? Skips common static-asset paths
 * and stray junk requests (bot floods hammering favicon/apple-touch-icon/
 * source-maps/backup files) so the ring buffer stays useful for real broken
 * links rather than being drowned out by noise.
 */
function wpultra_404_should_log(string $path): bool {
    $path = strtolower(trim($path));
    if ($path === '') { return false; }
    $base = (string) parse_url($path, PHP_URL_PATH);
    if ($base === '') { $base = $path; }
    // Well-known noise paths.
    if (preg_match('#/(favicon\.ico|apple-touch-icon(-\d+x\d+)?(-precomposed)?\.png|wp-content/(uploads|plugins|themes)/.*)$#', $base)) {
        return false;
    }
    // Junk / backup / source-map extensions and trailing tilde/backup markers.
    if (preg_match('/\.(map|php~|php\.bak|bak|old|orig|swp|log)$/', $base)) { return false; }
    if (preg_match('/~$/', $base)) { return false; }
    // Static asset extensions.
    if (preg_match('/\.(png|jpe?g|gif|webp|svg|ico|css|js|mjs|woff2?|ttf|eot|otf|map|json|xml|txt|pdf|zip)$/', $base)) {
        return false;
    }
    return true;
}

add_action('template_redirect', 'wpultra_404_boot');
/** Record the current request as a 404 hit if it qualifies (see wpultra_404_should_log). */
function wpultra_404_boot() {
    if (!function_exists('is_404') || !is_404()) { return; }
    $path = (string) ($_SERVER['REQUEST_URI'] ?? '');
    if (!wpultra_404_should_log($path)) { return; }
    $path = function_exists('esc_url_raw') ? esc_url_raw($path) : $path;
    if (function_exists('mb_substr')) { $path = mb_substr($path, 0, 200); } else { $path = substr($path, 0, 200); }

    $refHost = '';
    $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    if ($referer !== '') {
        $refHost = (string) (function_exists('wp_parse_url') ? wp_parse_url($referer, PHP_URL_HOST) : parse_url($referer, PHP_URL_HOST));
    }

    $ring = get_option('wpultra_404_log', []);
    if (!is_array($ring)) { $ring = []; }
    $ring[] = [
        'path'    => $path,
        'referer' => $refHost,
        'ts'      => function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s'),
    ];
    $cap = 200;
    if (count($ring) > $cap) { $ring = array_slice($ring, -$cap); }
    update_option('wpultra_404_log', $ring, false);
}

/**
 * PURE. Group ring entries by path: {path, hits, last (most recent ts)},
 * sorted by hits desc (ties broken by most-recent last-seen desc).
 */
function wpultra_404_top(array $ring): array {
    $groups = [];
    foreach ($ring as $entry) {
        $path = (string) ($entry['path'] ?? '');
        if ($path === '') { continue; }
        $ts = (string) ($entry['ts'] ?? '');
        if (!isset($groups[$path])) { $groups[$path] = ['path' => $path, 'hits' => 0, 'last' => $ts]; }
        $groups[$path]['hits']++;
        if ($ts > $groups[$path]['last']) { $groups[$path]['last'] = $ts; }
    }
    $out = array_values($groups);
    usort($out, function ($a, $b) {
        if ($a['hits'] !== $b['hits']) { return $b['hits'] <=> $a['hits']; }
        return strcmp((string) $b['last'], (string) $a['last']);
    });
    return $out;
}
