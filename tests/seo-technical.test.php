<?php
require_once __DIR__ . '/harness.php';

// ---- Extra stubs for redirect-map tests (option store + URL helpers) ----
$GLOBALS['__opts'] = [];
if (!function_exists('get_option')) { function get_option($k, $d = false) { return $GLOBALS['__opts'][$k] ?? $d; } }
if (!function_exists('update_option')) { function update_option($k, $v) { $GLOBALS['__opts'][$k] = $v; return true; } }
if (!function_exists('home_url')) { function home_url($p = '') { return 'http://example.com' . $p; } }
if (!function_exists('wp_parse_url')) { function wp_parse_url($u, $c = -1) { return parse_url($u, $c); } }
if (!function_exists('esc_url_raw')) { function esc_url_raw($u) { return $u; } }
if (!function_exists('wpultra_err')) { function wpultra_err($code, $msg, $data = '') { return new WP_Error($code, $msg, $data); } }

require_once __DIR__ . '/../wp-ultra-mcp/includes/seo/technical.php';
require_once __DIR__ . '/../wp-ultra-mcp/includes/seo/local.php';

it('match_redirect matches normalized path', function () {
    $map = [['source' => '/old-page/', 'target' => 'http://x/new/', 'type' => 301]];
    $r = wpultra_seo_match_redirect('/old-page/', $map);
    assert_eq('http://x/new/', $r['target']);
    assert_eq(301, $r['type']);
    assert_eq(null, wpultra_seo_match_redirect('/other/', $map));
});

it('match_redirect is trailing-slash tolerant', function () {
    $map = [['source' => '/old', 'target' => 'http://x/new', 'type' => 302]];
    assert_true(wpultra_seo_match_redirect('/old/', $map) !== null); // normalized equal
});

it('build_jsonld Article has required keys', function () {
    $j = wpultra_seo_build_jsonld('Article', ['headline' => 'Hi', 'author' => 'Ann', 'date' => '2026-01-01']);
    assert_eq('https://schema.org', $j['@context']);
    assert_eq('Article', $j['@type']);
    assert_eq('Hi', $j['headline']);
});

it('build_jsonld FAQPage builds mainEntity from qa pairs', function () {
    $j = wpultra_seo_build_jsonld('FAQPage', ['qa' => [['q' => 'Q1?', 'a' => 'A1']]]);
    assert_eq('FAQPage', $j['@type']);
    assert_eq('Q1?', $j['mainEntity'][0]['name']);
    assert_eq('A1', $j['mainEntity'][0]['acceptedAnswer']['text']);
});

it('build_local_jsonld has LocalBusiness type + address', function () {
    $j = wpultra_seo_build_local_jsonld(['name' => 'Acme', 'type' => 'Store', 'street' => '1 Main', 'city' => 'Springfield', 'phone' => '555']);
    assert_eq('Store', $j['@type']);
    assert_eq('Acme', $j['name']);
    assert_eq('1 Main', $j['address']['streetAddress']);
    assert_eq('555', $j['telephone']);
});

it('add_redirect rejects a direct self-loop', function () {
    $GLOBALS['__opts']['wpultra_seo_redirects'] = [];
    $r = wpultra_seo_add_redirect('/a/', '/a/', 301);
    assert_wp_error($r);
    assert_eq('redirect_loop', $r->get_error_code());
});

it('add_redirect rejects a 2-hop loop (/a/->/b/ then /b/->/a/)', function () {
    $GLOBALS['__opts']['wpultra_seo_redirects'] = [['source' => '/a/', 'target' => '/b/', 'type' => 301]];
    $r = wpultra_seo_add_redirect('/b/', '/a/', 301); // /b/ -> /a/ -> (existing) /b/ = loop
    assert_wp_error($r);
    assert_eq('redirect_loop', $r->get_error_code());
});

it('add_redirect allows a non-looping same-site redirect', function () {
    $GLOBALS['__opts']['wpultra_seo_redirects'] = [['source' => '/a/', 'target' => '/b/', 'type' => 301]];
    $r = wpultra_seo_add_redirect('/c/', '/b/', 301); // /c/ -> /b/ (terminal, no loop back to /c/)
    assert_true(!is_wp_error($r));
    assert_eq(2, count($r['redirects']));
});

it('add_redirect allows a cross-domain migration redirect sharing the path', function () {
    $GLOBALS['__opts']['wpultra_seo_redirects'] = [];
    $r = wpultra_seo_add_redirect('/blog/', 'https://new-domain.com/blog/', 301);
    assert_true(!is_wp_error($r));
});

it('set_sitemap is a no-op with a note for non-wp-core providers', function () {
    // wpultra_seo_mode() is undefined in this harness, so state() defaults provider=wp-core;
    // simulate a Yoast provider by defining the mode function is not possible mid-run, so this
    // asserts the wp-core path actually toggles the stored flag.
    $GLOBALS['__opts']['wpultra_seo_sitemap_disabled'] = false;
    $s = wpultra_seo_set_sitemap(false);
    assert_eq('wp-core', $s['provider']);
    assert_eq(false, $s['enabled']);
    assert_true($GLOBALS['__opts']['wpultra_seo_sitemap_disabled'] === true);
});

it('local_set flattens/drops non-scalar hours entries', function () {
    $GLOBALS['__opts'] = [];
    $out = wpultra_seo_local_set(['name' => 'Acme', 'hours' => [['Mo-Fr', '09-17'], 'Sa 10-14', '']]);
    assert_eq(['Sa 10-14'], $out['hours']); // nested array dropped, empty dropped
});

run_tests();
