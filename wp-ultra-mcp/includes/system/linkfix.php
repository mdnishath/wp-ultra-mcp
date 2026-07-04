<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Broken-link + redirect auto-fixer engine (Roadmap-2 C2).
 *
 * Three jobs:
 *
 *  SCAN — crawl published post/page content (offset-paged, no HTTP for internal
 *  links): extract hrefs, classify them (internal/external/anchor/mailto/other),
 *  and flag internal paths that resolve to nothing (url_to_postid +
 *  get_page_by_path + term_exists all miss, and no redirect rule already covers
 *  the path). External links are optionally HEAD-checked (capped per call).
 *
 *  SUGGEST — read the wpultra_404_log ring (written by includes/seo/monitor.php),
 *  aggregate hit counts per path, and fuzzy-match each 404 path's last segment
 *  against all published post/page slugs to propose redirect targets.
 *
 *  FIX — apply 301 redirects through the EXISTING seo redirect store
 *  (wpultra_seo_add_redirect / option wpultra_seo_redirects — the same rules the
 *  seo-manage-redirects ability manages and template_redirect applies), and/or
 *  rewrite old URLs inside post_content directly.
 *
 * PURE functions (wpultra_lf_*) come first and are the unit-testable core: no
 * WordPress calls. WP-dependent wrappers follow, each guarded so nothing fatals
 * when loaded outside a full WordPress runtime.
 */

/* =====================================================================
 * PURE — href extraction + classification.
 * ===================================================================== */

/**
 * PURE. Extract href values from an HTML fragment. Matches href="..." and
 * href='...' (attribute-boundary anchored, so data-href= and xlink:href-ish
 * prefixed attributes are NOT matched), trims, drops empties, de-dupes while
 * preserving first-seen order.
 *
 * @return array<int,string>
 */
function wpultra_lf_extract_hrefs(string $html): array {
    if ($html === '' || stripos($html, 'href') === false) { return []; }
    if (!preg_match_all('/(?<![-\w])href\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $html, $m, PREG_SET_ORDER)) {
        return [];
    }
    $out = [];
    $seen = [];
    foreach ($m as $hit) {
        $href = trim($hit[1] !== '' ? $hit[1] : ($hit[2] ?? ''));
        if ($href === '' || isset($seen[$href])) { continue; }
        $seen[$href] = true;
        $out[] = $href;
    }
    return $out;
}

/**
 * PURE. Classify one href relative to the site host.
 *
 * Returns ['type' => 'internal'|'external'|'anchor'|'mailto'|'other'] plus a
 * 'path' key (query/fragment stripped, leading slash guaranteed) when internal.
 *
 *  - "#..."                          → anchor
 *  - "mailto:..."                    → mailto
 *  - "tel:", "javascript:", "data:"… → other (any non-http(s) scheme)
 *  - relative path ("/x", "x/")      → internal
 *  - absolute / protocol-relative URL: same host → internal, else external
 */
function wpultra_lf_classify(string $href, string $home_host): array {
    $href = trim($href);
    $home_host = strtolower(trim($home_host));
    if ($href === '') { return ['type' => 'other']; }
    if ($href[0] === '#') { return ['type' => 'anchor']; }
    if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $href, $m)) {
        $scheme = strtolower($m[1]);
        if ($scheme === 'mailto') { return ['type' => 'mailto']; }
        if (!in_array($scheme, ['http', 'https'], true)) { return ['type' => 'other']; }
    }
    $parts = parse_url($href); // handles protocol-relative //host/path too
    if ($parts === false) { return ['type' => 'other']; }
    $host = strtolower((string) ($parts['host'] ?? ''));
    if ($host !== '' && $host !== $home_host) { return ['type' => 'external']; }
    $path = (string) ($parts['path'] ?? '');
    if ($host === '' && $path === '') { return ['type' => 'other']; } // bare "?q=x" etc.
    if ($path === '') { $path = '/'; }
    if ($path[0] !== '/') { $path = '/' . $path; }
    return ['type' => 'internal', 'path' => $path];
}

/**
 * PURE. Paths the internal-resolution check can never validate (core endpoints,
 * bundled assets, feeds, static files) — skip them instead of flagging false
 * "broken" positives.
 */
function wpultra_lf_skip_path(string $path): bool {
    $p = strtolower(trim($path));
    if ($p === '' || $p === '/') { return true; }
    if (preg_match('#^/(wp-content|wp-admin|wp-includes|wp-json)(/|$)#', $p)) { return true; }
    if (str_starts_with($p, '/wp-login.php')) { return true; }
    if (preg_match('#/feed/?$#', $p)) { return true; }
    if (preg_match('/\.(png|jpe?g|gif|webp|svg|ico|css|js|mjs|woff2?|ttf|eot|otf|map|json|xml|txt|pdf|zip|gz|mp[34]|webm)$/', $p)) { return true; }
    return false;
}

/* =====================================================================
 * PURE — path helpers + 404 aggregation fallback.
 * ===================================================================== */

/** PURE. Same semantics as wpultra_seo_norm_path (leading slash, single trailing slash, lowercased) — local copy so the pure layer has no cross-file dependency. */
function wpultra_lf_norm_path(string $p): string {
    $p = strtolower(trim($p));
    if ($p === '') { return '/'; }
    if ($p[0] !== '/') { $p = '/' . $p; }
    return rtrim($p, '/') . '/';
}

/** PURE. Last path segment of a URL/path, query + fragment stripped ('' for root). */
function wpultra_lf_last_segment(string $path): string {
    $p = (string) parse_url($path, PHP_URL_PATH);
    $p = trim($p, '/');
    if ($p === '') { return ''; }
    $parts = explode('/', $p);
    return (string) end($parts);
}

/**
 * PURE. Fallback aggregator for the wpultra_404_log ring — identical output
 * contract to wpultra_404_top() in includes/seo/monitor.php ({path, hits, last}
 * sorted hits desc, ties by most-recent last). Used only when monitor.php isn't
 * loaded.
 */
function wpultra_lf_top_paths(array $ring): array {
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

/* =====================================================================
 * PURE — slug similarity + ranking (suggestion core).
 * ===================================================================== */

/** PURE. Normalize for slug comparison: lowercase, underscores/whitespace → hyphens, collapse runs, trim. */
function wpultra_lf_norm_slug(string $s): string {
    $s = strtolower(trim($s));
    $s = (string) preg_replace('/[_\s]+/', '-', $s);
    $s = (string) preg_replace('/-{2,}/', '-', $s);
    return trim($s, '-');
}

/** PURE. similar_text percentage (0–100) on hyphen-normalized lowercase strings; exact match short-circuits to 100.0. */
function wpultra_lf_similarity(string $a, string $b): float {
    $a = wpultra_lf_norm_slug($a);
    $b = wpultra_lf_norm_slug($b);
    if ($a === '' || $b === '') { return 0.0; }
    if ($a === $b) { return 100.0; }
    $pct = 0.0;
    similar_text($a, $b, $pct);
    return round($pct, 2);
}

/**
 * PURE. Score every slug-map row ({post_id, slug, ...}) against a 404 path's
 * last segment and return the rows (with a 'score' key added) sorted by score
 * desc. Rows without a slug are dropped. The caller applies its own threshold.
 *
 * @param array<int,array> $slug_map rows like ['post_id'=>1,'slug'=>'hello-world','post_type'=>'post']
 */
function wpultra_lf_rank(array $slug_map, string $path_segment): array {
    $scored = [];
    foreach ($slug_map as $row) {
        if (!is_array($row)) { continue; }
        $slug = (string) ($row['slug'] ?? '');
        if ($slug === '') { continue; }
        $row['score'] = wpultra_lf_similarity($slug, $path_segment);
        $scored[] = $row;
    }
    usort($scored, fn($x, $y) => $y['score'] <=> $x['score']);
    return $scored;
}

/* =====================================================================
 * PURE — content replacement + redirect-row validation.
 * ===================================================================== */

/**
 * PURE. Exact-string replacement with a count. Deliberately a plain str_replace
 * (no quote-context awareness): the caller passes a full old URL, and replacing
 * every exact occurrence — href, plain text, srcset — is the desired behavior.
 *
 * @return array{content:string,count:int}
 */
function wpultra_lf_replace(string $content, string $old, string $new): array {
    if ($old === '' || $old === $new) { return ['content' => $content, 'count' => 0]; }
    $count = 0;
    $content = str_replace($old, $new, $content, $count);
    return ['content' => $content, 'count' => $count];
}

/**
 * PURE. Validate one apply-redirects row {from, to}. Returns true, or an error
 * string. Checks: from is a path starting '/', to is present (positive post_id,
 * a path starting '/', or an absolute http(s) URL), and a relative `to` path is
 * not the same path as `from` (self-loop). Same-site absolute-URL loops and
 * multi-hop loops are caught later by wpultra_seo_add_redirect's guards.
 */
function wpultra_lf_validate_redirect(array $r) {
    $from = trim((string) ($r['from'] ?? ''));
    if ($from === '' || $from[0] !== '/') { return 'from must be a path starting with "/" (e.g. "/old-page/")'; }
    $to = $r['to'] ?? '';
    if (is_int($to) || (is_string($to) && preg_match('/^\d+$/', trim($to)))) {
        return ((int) $to > 0) ? true : 'to post_id must be a positive integer';
    }
    $to = trim((string) $to);
    if ($to === '') { return 'to is required (a post_id, a path starting with "/", or an absolute http(s) URL)'; }
    $is_path = $to[0] === '/';
    $is_url  = (bool) preg_match('#^https?://\S+$#i', $to);
    if (!$is_path && !$is_url) { return 'to must be a post_id, a path starting with "/", or an absolute http(s) URL'; }
    if ($is_path) {
        $to_path = (string) parse_url($to, PHP_URL_PATH);
        if ($to_path !== '' && wpultra_lf_norm_path($to_path) === wpultra_lf_norm_path($from)) {
            return 'self-loop: from and to resolve to the same path';
        }
    }
    return true;
}

/* =====================================================================
 * WP — internal resolution + external HEAD check.
 * ===================================================================== */

/**
 * Best-effort: does an internal path resolve to SOMETHING (post/page/attachment
 * via url_to_postid or get_page_by_path, any term slug via term_exists, or an
 * existing redirect rule)? False = broken candidate. No HTTP involved.
 */
function wpultra_lf_resolve_internal(string $path): bool {
    $path = trim($path);
    if ($path === '' || $path === '/') { return true; }
    // A redirect rule already covers this path → it won't 404.
    if (function_exists('wpultra_seo_match_redirect') && function_exists('get_option')) {
        $map = get_option('wpultra_seo_redirects', []);
        if (is_array($map) && $map && wpultra_seo_match_redirect($path, $map)) { return true; }
    }
    if (function_exists('url_to_postid') && function_exists('home_url')) {
        if ((int) url_to_postid(home_url($path)) > 0) { return true; }
    }
    if (function_exists('get_page_by_path')) {
        $rel = trim($path, '/');
        if ($rel !== '' && get_page_by_path($rel, OBJECT, ['page', 'post', 'attachment'])) { return true; }
    }
    if (function_exists('term_exists')) {
        $seg = wpultra_lf_last_segment($path);
        if ($seg !== '' && term_exists($seg)) { return true; }
    }
    return false;
}

/** HEAD-check an external URL. Returns '' when it looks alive, else a broken-reason string (404/410/5xx/transport error). */
function wpultra_lf_check_external(string $url): string {
    if (!function_exists('wp_safe_remote_head')) { return ''; }
    $res = wp_safe_remote_head($url, ['timeout' => 5, 'redirection' => 3]);
    if (is_wp_error($res)) { return 'request failed: ' . $res->get_error_message(); }
    $code = (int) wp_remote_retrieve_response_code($res);
    if ($code === 404 || $code === 410 || $code >= 500) { return "HTTP $code"; }
    return '';
}

/* =====================================================================
 * WP — scan-links (offset-paged crawl of published content).
 * ===================================================================== */

/**
 * Crawl published posts + pages (ordered by ID, $limit per call, capped 500)
 * and report broken links. Internal links are resolved without HTTP; external
 * links are HEAD-checked only when $check_external (max 20 checks per call).
 *
 * @return array{scanned_posts:int,links_found:int,external_checked:int,broken:array,next_offset:int|null}
 */
function wpultra_lf_scan_links(int $offset = 0, int $limit = 100, bool $check_external = false): array {
    $limit  = max(1, min(500, $limit));
    $offset = max(0, $offset);
    $posts  = get_posts([
        'post_type'        => ['post', 'page'],
        'post_status'      => 'publish',
        'numberposts'      => $limit,
        'offset'           => $offset,
        'orderby'          => 'ID',
        'order'            => 'ASC',
        'suppress_filters' => true,
    ]);
    $home_host = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));

    $broken = [];
    $links_found = 0;
    $ext_checked = 0;
    $cache_internal = []; // path => bool resolves
    $cache_external = []; // url  => '' ok | reason

    foreach ($posts as $post) {
        foreach (wpultra_lf_extract_hrefs((string) $post->post_content) as $href) {
            $links_found++;
            $c = wpultra_lf_classify($href, $home_host);
            if ($c['type'] === 'internal') {
                $path = (string) $c['path'];
                if (wpultra_lf_skip_path($path)) { continue; }
                if (!array_key_exists($path, $cache_internal)) {
                    $cache_internal[$path] = wpultra_lf_resolve_internal($path);
                }
                if (!$cache_internal[$path]) {
                    $broken[] = [
                        'post_id'    => (int) $post->ID,
                        'post_title' => (string) $post->post_title,
                        'href'       => $href,
                        'type'       => 'internal',
                        'reason'     => "no post/page/attachment/term resolves for path $path",
                    ];
                }
            } elseif ($c['type'] === 'external' && $check_external) {
                if (!array_key_exists($href, $cache_external)) {
                    if ($ext_checked >= 20) { continue; } // per-call HTTP budget
                    $ext_checked++;
                    $cache_external[$href] = wpultra_lf_check_external($href);
                }
                if ($cache_external[$href] !== '') {
                    $broken[] = [
                        'post_id'    => (int) $post->ID,
                        'post_title' => (string) $post->post_title,
                        'href'       => $href,
                        'type'       => 'external',
                        'reason'     => $cache_external[$href],
                    ];
                }
            }
        }
    }

    return [
        'scanned_posts'    => count($posts),
        'links_found'      => $links_found,
        'external_checked' => $ext_checked,
        'broken'           => $broken,
        'next_offset'      => count($posts) === $limit ? $offset + $limit : null,
    ];
}

/* =====================================================================
 * WP — suggest (404 log → redirect proposals).
 * ===================================================================== */

/**
 * Read the wpultra_404_log ring (written by the 404 monitor in
 * includes/seo/monitor.php), aggregate per path, and fuzzy-match each path's
 * last segment against all published post/page slugs. Paths already covered by
 * a redirect rule are skipped; matches under $min_score are dropped.
 *
 * @return array{suggestions:array,log_entries:int,distinct_404_paths:int}
 */
function wpultra_lf_suggest(float $min_score = 55.0): array {
    $ring = get_option('wpultra_404_log', []);
    if (!is_array($ring)) { $ring = []; }
    $top = function_exists('wpultra_404_top') ? wpultra_404_top($ring) : wpultra_lf_top_paths($ring);

    $posts = get_posts([
        'post_type'        => ['post', 'page'],
        'post_status'      => 'publish',
        'numberposts'      => 500,
        'orderby'          => 'ID',
        'order'            => 'ASC',
        'suppress_filters' => true,
    ]);
    $slug_map = [];
    foreach ($posts as $p) {
        $slug_map[] = ['post_id' => (int) $p->ID, 'slug' => (string) $p->post_name, 'post_type' => (string) $p->post_type];
    }

    $existing = get_option('wpultra_seo_redirects', []);
    if (!is_array($existing)) { $existing = []; }

    $suggestions = [];
    foreach ($top as $g) {
        $path = (string) wp_parse_url((string) $g['path'], PHP_URL_PATH); // ring stores REQUEST_URI — strip query
        if ($path === '' || $path === '/') { continue; }
        if ($existing && function_exists('wpultra_seo_match_redirect') && wpultra_seo_match_redirect($path, $existing)) { continue; }
        $seg = wpultra_lf_last_segment($path);
        if ($seg === '') { continue; }
        $ranked = array_values(array_filter(
            wpultra_lf_rank($slug_map, $seg),
            fn($r) => (float) $r['score'] >= $min_score
        ));
        if (!$ranked) { continue; }
        $best = $ranked[0];
        $best['url'] = (string) get_permalink((int) $best['post_id']);
        $alts = array_slice($ranked, 1, 3);
        foreach ($alts as $i => $a) { $alts[$i]['url'] = (string) get_permalink((int) $a['post_id']); }
        $suggestions[] = [
            'from_path'    => $path,
            'hits'         => (int) ($g['hits'] ?? 0),
            'best'         => $best,
            'alternatives' => $alts,
        ];
    }

    return [
        'suggestions'        => $suggestions,
        'log_entries'        => count($ring),
        'distinct_404_paths' => count($top),
    ];
}

/* =====================================================================
 * WP — apply-redirects (through the EXISTING seo redirect store).
 * ===================================================================== */

/**
 * Add 301 rules via wpultra_seo_add_redirect() — the exact store the
 * seo-manage-redirects ability manages (option wpultra_seo_redirects), so its
 * source normalization, self/multi-hop loop guards, and the front-end
 * template_redirect applier all keep working unchanged. Per-row results.
 *
 * @param array<int,array{from:string,to:mixed}> $redirects
 * @return array{applied:int,results:array}
 */
function wpultra_lf_apply_redirects(array $redirects): array {
    if (!function_exists('wpultra_seo_add_redirect')) {
        $f = dirname(__DIR__) . '/seo/technical.php';
        if (is_file($f)) { require_once $f; }
    }
    $results = [];
    $applied = 0;
    foreach ($redirects as $r) {
        if (!is_array($r)) {
            $results[] = ['status' => 'error', 'error' => 'each redirect must be an object {from, to}'];
            continue;
        }
        $from = trim((string) ($r['from'] ?? ''));
        $v = wpultra_lf_validate_redirect($r);
        if ($v !== true) {
            $results[] = ['from' => $from, 'status' => 'error', 'error' => (string) $v];
            continue;
        }
        $to = $r['to'];
        if (is_int($to) || (is_string($to) && preg_match('/^\d+$/', trim($to)))) {
            $pid = (int) $to;
            $target = get_post($pid) ? (string) get_permalink($pid) : '';
            if ($target === '') {
                $results[] = ['from' => $from, 'status' => 'error', 'error' => "to post_id $pid does not resolve to a post"];
                continue;
            }
        } else {
            $to = trim((string) $to);
            $target = ($to[0] === '/') ? (string) home_url($to) : $to;
        }
        if (!function_exists('wpultra_seo_add_redirect')) {
            $results[] = ['from' => $from, 'to' => $target, 'status' => 'error', 'error' => 'redirect engine (includes/seo/technical.php) unavailable'];
            continue;
        }
        $res = wpultra_seo_add_redirect($from, $target, 301);
        if (is_wp_error($res)) {
            $results[] = ['from' => $from, 'to' => $target, 'status' => 'error', 'error' => $res->get_error_message()];
            continue;
        }
        $applied++;
        $results[] = ['from' => $from, 'to' => $target, 'status' => 'applied', 'type' => 301];
    }
    return ['applied' => $applied, 'results' => $results];
}

/* =====================================================================
 * WP — fix-in-content (rewrite old URLs inside post_content).
 * ===================================================================== */

/**
 * Replace every exact occurrence of $old_url with $new_url in published
 * post/page content. Candidate posts are found with a prepared LIKE on
 * post_content (WP_Query 's' searches titles/excerpts too and tokenizes — not
 * reliable for URL matching), then updated one by one via wp_update_post so
 * revisions + cache invalidation behave normally.
 *
 * @return array{posts_matched:int,posts_updated:int,replacements:int,post_ids:array}
 */
function wpultra_lf_fix_in_content(string $old_url, string $new_url, int $limit = 100): array {
    global $wpdb;
    $limit = max(1, min(500, $limit));
    $like  = '%' . $wpdb->esc_like($old_url) . '%';
    $ids   = $wpdb->get_col($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('post','page') AND post_content LIKE %s ORDER BY ID ASC LIMIT %d",
        $like,
        $limit
    ));

    $updated = 0;
    $replacements = 0;
    $post_ids = [];
    foreach ((array) $ids as $id) {
        $post = get_post((int) $id);
        if (!$post) { continue; }
        $r = wpultra_lf_replace((string) $post->post_content, $old_url, $new_url);
        if ($r['count'] < 1) { continue; }
        $res = wp_update_post(['ID' => (int) $id, 'post_content' => $r['content']], true);
        if (is_wp_error($res)) { continue; }
        $updated++;
        $replacements += (int) $r['count'];
        $post_ids[] = (int) $id;
    }
    return [
        'posts_matched' => count((array) $ids),
        'posts_updated' => $updated,
        'replacements'  => $replacements,
        'post_ids'      => $post_ids,
    ];
}

/* =====================================================================
 * Boot — nothing needed: everything is ability-driven. The 404 log is
 * populated by the monitor (includes/seo/monitor.php) and the redirect rules
 * are applied by the seo engine (includes/seo/technical.php).
 * ===================================================================== */
function wpultra_linkfix_boot(): void {}
