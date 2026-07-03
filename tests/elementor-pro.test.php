<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/elementor/pro.php';

it('condition validator accepts Pro formats and rejects junk', function () {
    assert_eq(true, wpultra_epro_validate_condition('include/general'));
    assert_eq(true, wpultra_epro_validate_condition('include/singular/page/12'));
    assert_eq(true, wpultra_epro_validate_condition('exclude/archive/category'));
    assert_eq(true, wpultra_epro_validate_condition('include/singular/post'));
    assert_true(is_string(wpultra_epro_validate_condition('everywhere')));
    assert_true(is_string(wpultra_epro_validate_condition('include/nope')));
    assert_true(is_string(wpultra_epro_validate_condition('include/general; DROP TABLE')));
});

it('popup display builder maps friendly triggers to native settings', function () {
    $d = wpultra_epro_build_popup_display(['on_click' => true, 'page_load' => 3, 'scroll' => 50, 'exit_intent' => true, 'show_times' => 2]);
    assert_eq('yes', $d['triggers']['click']);
    assert_eq('yes', $d['triggers']['page_load']);
    assert_eq(3, $d['triggers']['page_load_delay']);
    assert_eq(50, $d['triggers']['scrolling_offset']);
    assert_eq('yes', $d['triggers']['exit_intent']);
    assert_eq(2, $d['timing']['times_times']);
    // clamps
    assert_eq(100, wpultra_epro_build_popup_display(['scroll' => 500])['triggers']['scrolling_offset']);
    assert_eq(0, wpultra_epro_build_popup_display(['page_load' => -5])['triggers']['page_load_delay']);
    // empty in → empty triggers (ability rejects)
    assert_eq([], wpultra_epro_build_popup_display([])['triggers']);
});

it('flatten_values builds key => value and skips blank keys', function () {
    $rows = [['key' => 'name', 'value' => 'Ali'], ['key' => 'email', 'value' => 'a@b.c'], ['key' => '', 'value' => 'x']];
    assert_eq(['name' => 'Ali', 'email' => 'a@b.c'], wpultra_epro_flatten_values($rows));
});

it('submissions SQL builder composes filters + pagination placeholders', function () {
    $q = wpultra_epro_submissions_sql('wp_e_submissions', ['form_name' => 'Quote', 'unread' => true, 'per_page' => 10, 'page' => 3]);
    assert_contains('form_name = %s', $q['sql']);
    assert_contains('is_read = 0', $q['sql']);
    assert_contains('LIMIT %d OFFSET %d', $q['sql']);
    assert_eq(['Quote', 10, 20], $q['args']); // page 3 → offset 20
    $q2 = wpultra_epro_submissions_sql('wp_e_submissions', []);
    assert_eq([20, 0], $q2['args']); // defaults
});

it('template types whitelist covers the real-world set', function () {
    foreach (['header', 'footer', 'single-page', 'loop-item', 'popup', 'container'] as $t) {
        assert_true(in_array($t, wpultra_epro_template_types(), true), $t);
    }
    assert_true(!in_array('kit', wpultra_epro_template_types(), true), 'kit must not be creatable');
});

run_tests();
