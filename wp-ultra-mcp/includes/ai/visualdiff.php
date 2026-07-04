<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Visual diff / regression engine (roadmap F3).
 *
 * HONEST SCOPE: the plugin bundles NO headless browser. A "visual diff" here is
 * a SERVER-SIDE rendered-HTML FINGERPRINT of a URL, captured before and after a
 * change, then compared STRUCTURALLY. It catches structural / content / asset
 * regressions — missing sections, changed headings/text, dropped images, a
 * rendered PHP error, big size swings, non-2xx status — NOT pixel-perfect
 * rendering. That is genuinely useful and honest.
 *
 * For true pixel comparison the calling AI (e.g. Claude Code, which CAN drive a
 * browser) captures before/after screenshots and passes their URLs; the ability
 * records them in the report and flags them for the AI to eyeball. The server
 * does the structural diff; the client does the eyeball diff.
 *
 * The PURE core (prefix wpultra_vdiff_, no WordPress calls) is unit-tested in
 * tests/visualdiff.test.php. WP wrappers (wp_remote_get + option store) come
 * after and are guarded so the file loads standalone in the test harness.
 */

/* =====================================================================
 * PURE core — HTML fingerprinting.
 * ===================================================================== */

/**
 * Strip <script>/<style> blocks and all tags, decode entities, collapse
 * whitespace: the human-visible text of an HTML document. Pure & deterministic.
 */
function wpultra_vdiff_visible_text(string $html): string {
    // Drop script/style/noscript/template bodies entirely — they are not visible text.
    $html = preg_replace('#<(script|style|noscript|template)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
    // Comments too.
    $html = preg_replace('#<!--.*?-->#s', ' ', $html) ?? $html;
    // Tags -> space so adjacent words don't fuse.
    $text = preg_replace('#<[^>]+>#', ' ', $html) ?? $html;
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Collapse all whitespace runs to a single space.
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    return trim($text);
}

/**
 * Ordered outline of block-level structural tag names in document order — the
 * "DOM skeleton". Ignores inline tags (span/a/b/img/...) so cosmetic edits don't
 * churn the skeleton, but captures section/div/header/nav/h1-6/ul/li/table/form
 * etc. Pure & deterministic.
 */
function wpultra_vdiff_tag_skeleton(string $html): string {
    // Remove script/style bodies first — their inner markup is not structure.
    $html = preg_replace('#<(script|style|noscript|template)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
    $block = [
        'html', 'head', 'body', 'header', 'footer', 'nav', 'main', 'section',
        'article', 'aside', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p',
        'ul', 'ol', 'li', 'table', 'thead', 'tbody', 'tr', 'td', 'th', 'form',
        'fieldset', 'figure', 'blockquote', 'pre', 'hr',
    ];
    $set = array_fill_keys($block, true);
    $out = [];
    // Match opening tags only (skip closing tags and self-terminated where irrelevant).
    if (preg_match_all('#<([a-zA-Z][a-zA-Z0-9]*)\b#', $html, $m)) {
        foreach ($m[1] as $tag) {
            $tag = strtolower($tag);
            if (isset($set[$tag])) { $out[] = $tag; }
        }
    }
    return implode('>', $out);
}

/**
 * Extract the inner text of every occurrence of a given tag (e.g. 'h1', 'h2',
 * 'title'), trimmed & entity-decoded, in document order. Pure.
 */
function wpultra_vdiff_extract(string $html, string $tag): array {
    $tag = strtolower(preg_replace('/[^a-z0-9]/i', '', $tag) ?? '');
    if ($tag === '') { return []; }
    $out = [];
    if (preg_match_all('#<' . $tag . '\b[^>]*>(.*?)</' . $tag . '>#is', $html, $m)) {
        foreach ($m[1] as $inner) {
            $t = preg_replace('#<[^>]+>#', ' ', $inner) ?? $inner;
            $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $t = trim(preg_replace('/\s+/u', ' ', $t) ?? $t);
            if ($t !== '') { $out[] = $t; }
        }
    }
    return $out;
}

/**
 * All <img src="..."> values in document order (deduped by preserving order of
 * first appearance is NOT done — order matters for diffing; we keep them raw).
 * Pure.
 */
function wpultra_vdiff_img_srcs(string $html): array {
    $out = [];
    if (preg_match_all('#<img\b[^>]*?\ssrc\s*=\s*(["\'])(.*?)\1#is', $html, $m)) {
        foreach ($m[2] as $src) {
            $src = trim(html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($src !== '') { $out[] = $src; }
        }
    }
    return $out;
}

/**
 * Scan a rendered body for PHP-error / warning / stack-trace markers. A rendered
 * PHP error on a page IS a regression, so the fingerprint records them. Returns
 * the distinct marker labels found. Pure.
 */
function wpultra_vdiff_error_markers(string $html): array {
    $markers = [
        'fatal_error'      => '/Fatal error/i',
        'parse_error'      => '/Parse error/i',
        'php_warning'      => '/\bWarning:/',
        'php_notice'       => '/\bNotice:/',
        'php_deprecated'   => '/Deprecated:/i',
        'uncaught'         => '/Uncaught\s+(?:Error|Exception|\\\\?[A-Za-z_]+Exception)/i',
        'stack_trace'      => '/Stack trace:/i',
        'call_to'          => '/Call to (?:undefined|a member) /i',
        'db_error'         => '/Error establishing a database connection/i',
        'wsod'             => '/There has been a critical error on this website/i',
        'xdebug'           => '/xdebug-error/i',
    ];
    $found = [];
    foreach ($markers as $id => $re) {
        if (preg_match($re, $html)) { $found[] = $id; }
    }
    return $found;
}

/**
 * Compute the full fingerprint of a rendered HTML body. $status is the HTTP
 * status observed when fetching it (0 when unknown / fetch failed). Pure &
 * deterministic — the whole point is that the same HTML always yields the same
 * fingerprint so before/after are comparable.
 */
function wpultra_vdiff_fingerprint(string $html, int $status = 200): array {
    $visible = wpultra_vdiff_visible_text($html);
    $skeleton = wpultra_vdiff_tag_skeleton($html);
    $imgs = wpultra_vdiff_img_srcs($html);
    $titles = wpultra_vdiff_extract($html, 'title');

    $link_count   = preg_match_all('#<a\b[^>]*\shref\s*=#i', $html);
    $script_count = preg_match_all('#<script\b#i', $html);
    $form_count   = preg_match_all('#<form\b#i', $html);

    return [
        'status'            => $status,
        'byte_size'         => strlen($html),
        'text_len'          => function_exists('mb_strlen') ? mb_strlen($visible, 'UTF-8') : strlen($visible),
        'title'             => $titles[0] ?? '',
        'h1s'               => wpultra_vdiff_extract($html, 'h1'),
        'h2s'               => wpultra_vdiff_extract($html, 'h2'),
        'img_count'         => count($imgs),
        'img_srcs'          => array_slice($imgs, 0, 100),
        'link_count'        => (int) $link_count,
        'script_count'      => (int) $script_count,
        'form_count'        => (int) $form_count,
        'text_hash'         => md5($visible),
        'dom_skeleton_hash' => md5($skeleton),
        'error_markers'     => wpultra_vdiff_error_markers($html),
    ];
}

/* =====================================================================
 * PURE core — comparison + severity.
 * ===================================================================== */

/**
 * Percent delta from $a (before) to $b (after), rounded 2dp. Zero-before guard:
 * when $a is 0 we report 100.0 if $b grew from nothing, 0.0 if both are 0. Pure.
 */
function wpultra_vdiff_pct_delta(int $a, int $b): float {
    if ($a === $b) { return 0.0; }
    if ($a === 0) { return 100.0; }
    return round((($b - $a) / abs($a)) * 100, 2);
}

/**
 * Severity from a diff list + newly-appeared error markers. Pure — mirrors the
 * spec's rules exactly so the matrix can be unit-tested:
 *   - any new error markers                                    -> critical
 *   - non-2xx status, missing title/h1, img drop >30%,
 *     dom_skeleton change                                      -> major
 *   - text change, small size delta, h2 change                 -> minor
 *   - nothing                                                  -> none
 */
function wpultra_vdiff_severity(array $diffs, array $new_errors): string {
    if (!empty($new_errors)) { return 'critical'; }

    $rank = ['none' => 0, 'minor' => 1, 'major' => 2, 'critical' => 3];
    $level = 'none';
    $bump = static function (string $to) use (&$level, $rank): void {
        if ($rank[$to] > $rank[$level]) { $level = $to; }
    };

    foreach ($diffs as $d) {
        $field = (string) ($d['field'] ?? '');
        switch ($field) {
            case 'status':
            case 'title':
            case 'h1s':
            case 'dom_skeleton_hash':
                $bump('major');
                break;
            case 'img_count':
                // Major only on a >30% DROP; a rise or small drop is minor.
                $delta = (float) ($d['delta'] ?? 0);
                $bump($delta < -30.0 ? 'major' : 'minor');
                break;
            case 'text_hash':
            case 'text_len':
            case 'byte_size':
            case 'h2s':
            case 'link_count':
            case 'script_count':
            case 'form_count':
            case 'img_srcs':
                $bump('minor');
                break;
            default:
                $bump('minor');
                break;
        }
    }
    return $level;
}

/**
 * Compare two fingerprints (before -> after) and return:
 *   { changed: bool, severity, diffs: [{field, before, after, delta?}], new_errors: [] }
 * Pure & deterministic.
 */
function wpultra_vdiff_compare(array $before, array $after): array {
    $diffs = [];

    // status
    $sb = (int) ($before['status'] ?? 0);
    $sa = (int) ($after['status'] ?? 0);
    $status_bad = $sa < 200 || $sa >= 300;
    // Flag when it changed OR it is currently non-2xx (a persistent 500 is still
    // a regression worth surfacing).
    if ($sb !== $sa || $status_bad) {
        $diffs[] = ['field' => 'status', 'before' => $sb, 'after' => $sa];
    }

    // title / h1s / h2s (content structure)
    foreach (['title', 'h1s', 'h2s'] as $f) {
        $vb = $before[$f] ?? ($f === 'title' ? '' : []);
        $va = $after[$f] ?? ($f === 'title' ? '' : []);
        if ($vb !== $va) {
            $diffs[] = ['field' => $f, 'before' => $vb, 'after' => $va];
        }
    }

    // dom skeleton
    if (($before['dom_skeleton_hash'] ?? '') !== ($after['dom_skeleton_hash'] ?? '')) {
        $diffs[] = [
            'field'  => 'dom_skeleton_hash',
            'before' => $before['dom_skeleton_hash'] ?? '',
            'after'  => $after['dom_skeleton_hash'] ?? '',
        ];
    }

    // visible text hash
    if (($before['text_hash'] ?? '') !== ($after['text_hash'] ?? '')) {
        $diffs[] = [
            'field'  => 'text_hash',
            'before' => $before['text_hash'] ?? '',
            'after'  => $after['text_hash'] ?? '',
            'delta'  => wpultra_vdiff_pct_delta((int) ($before['text_len'] ?? 0), (int) ($after['text_len'] ?? 0)),
        ];
    }

    // img_count (with percent delta so severity can judge a big drop)
    $ib = (int) ($before['img_count'] ?? 0);
    $ia = (int) ($after['img_count'] ?? 0);
    if ($ib !== $ia) {
        $diffs[] = [
            'field'  => 'img_count',
            'before' => $ib,
            'after'  => $ia,
            'delta'  => wpultra_vdiff_pct_delta($ib, $ia),
        ];
    }

    // byte_size (informational size swing)
    $bb = (int) ($before['byte_size'] ?? 0);
    $ba = (int) ($after['byte_size'] ?? 0);
    if ($bb !== $ba) {
        $diffs[] = [
            'field'  => 'byte_size',
            'before' => $bb,
            'after'  => $ba,
            'delta'  => wpultra_vdiff_pct_delta($bb, $ba),
        ];
    }

    // counts
    foreach (['link_count', 'script_count', 'form_count'] as $f) {
        $vb = (int) ($before[$f] ?? 0);
        $va = (int) ($after[$f] ?? 0);
        if ($vb !== $va) {
            $diffs[] = ['field' => $f, 'before' => $vb, 'after' => $va, 'delta' => wpultra_vdiff_pct_delta($vb, $va)];
        }
    }

    // NEW error markers (present in after, not in before)
    $eb = is_array($before['error_markers'] ?? null) ? $before['error_markers'] : [];
    $ea = is_array($after['error_markers'] ?? null) ? $after['error_markers'] : [];
    $new_errors = array_values(array_diff($ea, $eb));
    if (!empty($new_errors)) {
        $diffs[] = ['field' => 'error_markers', 'before' => $eb, 'after' => $ea];
    }

    $severity = wpultra_vdiff_severity($diffs, $new_errors);

    return [
        'changed'    => !empty($diffs),
        'severity'   => $severity,
        'diffs'      => $diffs,
        'new_errors' => $new_errors,
    ];
}

/* =====================================================================
 * WP wrappers — fetch + baseline store. Guarded for the test harness.
 * ===================================================================== */

/** Autoloaded option name for stored baselines. */
if (!defined('WPULTRA_VDIFF_OPTION')) { define('WPULTRA_VDIFF_OPTION', 'wpultra_vdiff_baselines'); }
/** Max distinct URLs we keep a baseline for (oldest evicted). */
if (!defined('WPULTRA_VDIFF_MAX_URLS')) { define('WPULTRA_VDIFF_MAX_URLS', 50); }

/**
 * Fetch a URL and return its fingerprint plus fetch metadata:
 *   { url, fetched_at, fingerprint, http_status }  — or WP_Error on transport
 *   failure. WordPress-dependent.
 */
function wpultra_vdiff_snapshot(string $url) {
    $url = trim($url);
    if ($url === '' || !function_exists('wp_http_validate_url') || !wp_http_validate_url($url)) {
        return wpultra_err('invalid_url', 'A valid absolute http(s) URL is required.');
    }
    if (!function_exists('wp_remote_get')) {
        return wpultra_err('http_unavailable', 'wp_remote_get is not available.');
    }

    $resp = wp_remote_get($url, [
        'timeout'     => 15,
        'redirection' => 3,
        'sslverify'   => true,
        'user-agent'  => 'wp-ultra-mcp/visual-diff',
    ]);
    if (is_wp_error($resp)) {
        return wpultra_err('fetch_failed', 'Could not fetch URL: ' . $resp->get_error_message());
    }

    $status = (int) wp_remote_retrieve_response_code($resp);
    $body   = (string) wp_remote_retrieve_body($resp);
    $fp     = wpultra_vdiff_fingerprint($body, $status);

    return [
        'url'         => $url,
        'fetched_at'  => function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s'),
        'http_status' => $status,
        'fingerprint' => $fp,
    ];
}

/** Read the stored baseline map. WordPress-dependent (guarded). */
function wpultra_vdiff_get_baselines(): array {
    $b = function_exists('get_option') ? get_option(WPULTRA_VDIFF_OPTION, []) : [];
    return is_array($b) ? $b : [];
}

/** Persist the baseline map (capped to WPULTRA_VDIFF_MAX_URLS, oldest first). */
function wpultra_vdiff_save_baselines(array $baselines): void {
    if (count($baselines) > WPULTRA_VDIFF_MAX_URLS) {
        // Evict oldest by captured_at; PHP preserves insertion order so slicing
        // from the end after a captured_at sort keeps the freshest.
        uasort($baselines, static function ($x, $y): int {
            return strcmp((string) ($x['captured_at'] ?? ''), (string) ($y['captured_at'] ?? ''));
        });
        $baselines = array_slice($baselines, -WPULTRA_VDIFF_MAX_URLS, null, true);
    }
    if (function_exists('update_option')) {
        update_option(WPULTRA_VDIFF_OPTION, $baselines, false);
    }
}

/**
 * Capture $url now and store it as the baseline ("before"). Returns the stored
 * record or WP_Error.
 */
function wpultra_vdiff_capture_baseline(string $url, string $label = '') {
    $snap = wpultra_vdiff_snapshot($url);
    if (is_wp_error($snap)) { return $snap; }

    $baselines = wpultra_vdiff_get_baselines();
    $baselines[$snap['url']] = [
        'captured_at' => $snap['fetched_at'],
        'fingerprint' => $snap['fingerprint'],
        'label'       => $label,
    ];
    wpultra_vdiff_save_baselines($baselines);
    return $baselines[$snap['url']];
}

/**
 * Controller entry point. Cheap: no work at boot beyond scheduling an optional
 * cleanup. We do NOT need a heavy cron — baselines are capped on write — so this
 * is intentionally a near-no-op. Kept for the runtime contract.
 */
function wpultra_vdiff_boot(): void {
    // Baselines are capped on write (wpultra_vdiff_save_baselines), so there is
    // no background growth to garbage-collect. Nothing to schedule; keep cheap.
}
