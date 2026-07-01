<?php
require_once __DIR__ . '/harness.php';
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

run_tests();
