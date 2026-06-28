<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/elementor/classes.php';

it('global-class id format', function () {
    assert_true((bool) preg_match('/^e-gc-[a-f0-9]{7}$/', wpultra_el_gc_id()), 'format');
});
it('builds a fade interaction structure', function () {
    $i = wpultra_el_fade_interaction('scrollIn', 'fade', 'in', 600);
    assert_eq(1, $i['version']);
    assert_eq(1, count($i['items']));
    $v = $i['items'][0]['value'];
    assert_eq('interaction-item', $i['items'][0]['$$type']);
    assert_eq('scrollIn', $v['trigger']['value']);
    assert_eq('fade', $v['animation']['value']['effect']['value']);
    assert_eq(600, $v['animation']['value']['timing_config']['value']['duration']['value']['size']);
});

run_tests();
