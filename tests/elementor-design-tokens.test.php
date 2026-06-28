<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/elementor/design.php';

it('maps colors, fonts, sizes to variable instructions', function () {
    $brief = [
        'colors' => [['role' => 'primary', 'title' => 'Brand', 'hex' => '#0a84ff']],
        'fonts'  => [['role' => 'heading', 'title' => 'Display', 'family' => 'Inter']],
        'sizes'  => [['role' => 'space-md', 'title' => 'Space M', 'size' => 16, 'unit' => 'px']],
    ];
    $r = wpultra_el_build_token_plan($brief);
    assert_eq([], $r['errors']);
    assert_eq(3, count($r['plan']));
    assert_eq(['color', 'global-color-variable', 'Brand', '#0a84ff'], [$r['plan'][0]['family'], $r['plan'][0]['type'], $r['plan'][0]['title'], $r['plan'][0]['value']]);
    assert_eq('Inter', $r['plan'][1]['value']);
    assert_eq('global-font-variable', $r['plan'][1]['type']);
    assert_eq('16px', $r['plan'][2]['value']);
    assert_eq('global-size-variable', $r['plan'][2]['type']);
});

it('defaults size unit to px and stringifies numeric size', function () {
    $r = wpultra_el_build_token_plan(['sizes' => [['title' => 'Gap', 'size' => 24]]]);
    assert_eq('24px', $r['plan'][0]['value']);
});

it('reports errors for empty title, bad hex, missing family/size — and skips them', function () {
    $brief = [
        'colors' => [['title' => '', 'hex' => '#fff'], ['title' => 'Bad', 'hex' => 'nothex']],
        'fonts'  => [['title' => 'NoFam']],
        'sizes'  => [['title' => 'NoSize']],
    ];
    $r = wpultra_el_build_token_plan($brief);
    assert_eq([], $r['plan']);
    assert_eq(4, count($r['errors']));
});

it('handles a partial brief (only fonts)', function () {
    $r = wpultra_el_build_token_plan(['fonts' => [['title' => 'Body', 'family' => 'Roboto']]]);
    assert_eq([], $r['errors']);
    assert_eq(1, count($r['plan']));
    assert_eq('font', $r['plan'][0]['family']);
});

run_tests();
