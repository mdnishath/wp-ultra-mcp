<?php
require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../wp-ultra-mcp/includes/seo/meta.php';

it('validate keeps known fields + coerces bool', function () {
    $r = wpultra_seo_validate_meta(['title' => 'Hi', 'robots_noindex' => 'yes']);
    assert_eq('Hi', $r['clean']['title']);
    assert_eq(true, $r['clean']['robots_noindex']);
    assert_eq([], $r['rejected']);
});

it('validate rejects unknown field', function () {
    $r = wpultra_seo_validate_meta(['title' => 'Hi', 'bogus' => 1]);
    assert_eq(1, count($r['rejected']));
    assert_eq('bogus', $r['rejected'][0]['field']);
    assert_eq('unknown_field', $r['rejected'][0]['reason']);
});

it('validate warns on long title and short description', function () {
    $long = str_repeat('a', 70);
    $r = wpultra_seo_validate_meta(['title' => $long, 'description' => 'short']);
    $warnFields = array_map(function ($w) { return $w['field']; }, $r['warnings']);
    assert_true(in_array('title', $warnFields, true));
    assert_true(in_array('description', $warnFields, true));
});

it('keymap maps yoast title key', function () {
    $m = wpultra_seo_keymap('yoast');
    assert_eq('_yoast_wpseo_title', $m['title']);
    assert_eq('_yoast_wpseo_metadesc', $m['description']);
});

it('keymap maps native title key', function () {
    $m = wpultra_seo_keymap('native');
    assert_eq('_wpultra_seo_title', $m['title']);
});

run_tests();
