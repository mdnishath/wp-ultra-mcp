<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/elementor/customcss.php';

/* ------------------------------------------------------------------ *
 * wpultra_elcss_sanitize
 * ------------------------------------------------------------------ */

it('sanitize: passes through plain valid CSS unchanged', function () {
    $css = 'selector { color: red; font-size: 14px; }';
    assert_eq($css, wpultra_elcss_sanitize($css, 20000));
});

it('sanitize: allows the ">" child-combinator (must not be treated as breakout)', function () {
    $css = 'selector > .child { color: red }';
    assert_eq($css, wpultra_elcss_sanitize($css, 20000));
});

it('sanitize: strips a trailing </style> close tag and returns clean CSS', function () {
    $css = 'selector { color: red }</style>';
    assert_eq('selector { color: red }', wpultra_elcss_sanitize($css, 20000));
});

it('sanitize: strips a </script> close tag case-insensitively with attributes-like spacing', function () {
    $css = 'selector { color: red }</SCRIPT >';
    assert_eq('selector { color: red }', wpultra_elcss_sanitize($css, 20000));
});

it('sanitize: rejects a breakout attempt containing a literal opening tag', function () {
    $css = 'selector { color: red } <script>alert(1)</script>';
    assert_wp_error(wpultra_elcss_sanitize($css, 20000));
});

it('sanitize: rejects a bare "<" that survives stripping', function () {
    $css = 'selector { color: red } < img onerror=alert(1)>';
    assert_wp_error(wpultra_elcss_sanitize($css, 20000));
});

it('sanitize: rejects CSS longer than the cap', function () {
    $css = str_repeat('a', 25);
    $err = wpultra_elcss_sanitize($css, 20);
    assert_wp_error($err);
    assert_eq('css_too_long', $err->get_error_code());
});

it('sanitize: exactly at the cap is allowed', function () {
    $css = str_repeat('a', 20);
    assert_eq($css, wpultra_elcss_sanitize($css, 20));
});

/* ------------------------------------------------------------------ *
 * wpultra_elcss_validate_element_id
 * ------------------------------------------------------------------ */

it('validate_element_id: accepts a typical Elementor-style id', function () {
    assert_true(wpultra_elcss_validate_element_id('abc123') === true);
});

it('validate_element_id: accepts underscores and hyphens', function () {
    assert_true(wpultra_elcss_validate_element_id('abc_123-x') === true);
});

it('validate_element_id: rejects a double-quote breakout attempt', function () {
    $err = wpultra_elcss_validate_element_id('abc"onmouseover=1');
    assert_wp_error($err);
    assert_eq('bad_element_id', $err->get_error_code());
});

it('validate_element_id: rejects a marker-comment-closing "*/" payload', function () {
    $err = wpultra_elcss_validate_element_id('abc*/body{color:red}/*');
    assert_wp_error($err);
    assert_eq('bad_element_id', $err->get_error_code());
});

it('validate_element_id: rejects embedded whitespace', function () {
    assert_wp_error(wpultra_elcss_validate_element_id('abc 123'));
});

it('validate_element_id: rejects an empty string', function () {
    $err = wpultra_elcss_validate_element_id('');
    assert_wp_error($err);
    assert_eq('bad_element_id', $err->get_error_code());
});

it('validate_element_id: rejects a literal "<" character', function () {
    assert_wp_error(wpultra_elcss_validate_element_id('<script>'));
});

/* ------------------------------------------------------------------ *
 * wpultra_elcss_marker
 * ------------------------------------------------------------------ */

it('marker: builds deterministic start/end strings keyed by post+element', function () {
    $m = wpultra_elcss_marker(42, 'abc123');
    assert_eq('/* wpultra-el-css:42:abc123 START */', $m['start']);
    assert_eq('/* wpultra-el-css:42:abc123 END */', $m['end']);
});

it('marker: differs per post_id and per element_id', function () {
    $a = wpultra_elcss_marker(1, 'x');
    $b = wpultra_elcss_marker(2, 'x');
    $c = wpultra_elcss_marker(1, 'y');
    assert_true($a['start'] !== $b['start'], 'different post_id must differ');
    assert_true($a['start'] !== $c['start'], 'different element_id must differ');
});

/* ------------------------------------------------------------------ *
 * wpultra_elcss_upsert_block (idempotent insert / replace)
 * ------------------------------------------------------------------ */

it('upsert: inserts a new block into an empty stylesheet', function () {
    $m = wpultra_elcss_marker(1, 'a');
    $out = wpultra_elcss_upsert_block('', $m['start'], $m['end'], '.x{color:red}');
    assert_contains($m['start'], $out);
    assert_contains($m['end'], $out);
    assert_contains('.x{color:red}', $out);
});

it('upsert: appends after existing unrelated CSS, preserving it', function () {
    $m = wpultra_elcss_marker(1, 'a');
    $existing = "body{margin:0}";
    $out = wpultra_elcss_upsert_block($existing, $m['start'], $m['end'], '.x{color:red}');
    assert_contains('body{margin:0}', $out);
    assert_contains($m['start'], $out);
});

it('upsert: calling twice with a new body replaces the old block in place (idempotent)', function () {
    $m = wpultra_elcss_marker(1, 'a');
    $first = wpultra_elcss_upsert_block("body{margin:0}", $m['start'], $m['end'], '.x{color:red}');
    $second = wpultra_elcss_upsert_block($first, $m['start'], $m['end'], '.x{color:blue}');
    assert_true(!str_contains($second, 'color:red'), 'old body must be gone');
    assert_contains('color:blue', $second);
    assert_contains('body{margin:0}', $second);
    // Only one occurrence of the marker pair after the update.
    assert_eq(1, substr_count($second, $m['start']));
    assert_eq(1, substr_count($second, $m['end']));
});

it('upsert: does not disturb a different element\'s marker block', function () {
    $mA = wpultra_elcss_marker(1, 'a');
    $mB = wpultra_elcss_marker(1, 'b');
    $withA = wpultra_elcss_upsert_block('', $mA['start'], $mA['end'], '.a{color:red}');
    $withBoth = wpultra_elcss_upsert_block($withA, $mB['start'], $mB['end'], '.b{color:green}');
    $updatedA = wpultra_elcss_upsert_block($withBoth, $mA['start'], $mA['end'], '.a{color:pink}');
    assert_contains('.b{color:green}', $updatedA);
    assert_contains('.a{color:pink}', $updatedA);
    assert_true(!str_contains($updatedA, 'color:red'), 'old A body replaced');
});

/* ------------------------------------------------------------------ *
 * wpultra_elcss_remove_block
 * ------------------------------------------------------------------ */

it('remove: strips the marker block entirely, leaving unrelated CSS intact', function () {
    $m = wpultra_elcss_marker(1, 'a');
    $withBlock = wpultra_elcss_upsert_block("body{margin:0}", $m['start'], $m['end'], '.x{color:red}');
    $removed = wpultra_elcss_remove_block($withBlock, $m['start'], $m['end']);
    assert_true(!str_contains($removed, $m['start']), 'start marker must be gone');
    assert_true(!str_contains($removed, $m['end']), 'end marker must be gone');
    assert_true(!str_contains($removed, 'color:red'), 'body must be gone');
    assert_contains('body{margin:0}', $removed);
});

it('remove: is a no-op (returns input unchanged in substance) when the marker is absent', function () {
    $m = wpultra_elcss_marker(1, 'a');
    $css = "body{margin:0}";
    $out = wpultra_elcss_remove_block($css, $m['start'], $m['end']);
    assert_contains('body{margin:0}', $out);
    assert_true(!str_contains($out, $m['start']));
});

it('remove: only removes the targeted element\'s block, keeping a sibling block', function () {
    $mA = wpultra_elcss_marker(1, 'a');
    $mB = wpultra_elcss_marker(1, 'b');
    $withA = wpultra_elcss_upsert_block('', $mA['start'], $mA['end'], '.a{color:red}');
    $withBoth = wpultra_elcss_upsert_block($withA, $mB['start'], $mB['end'], '.b{color:green}');
    $out = wpultra_elcss_remove_block($withBoth, $mA['start'], $mA['end']);
    assert_true(!str_contains($out, '.a{color:red}'), 'A block must be gone');
    assert_contains('.b{color:green}', $out);
});

it('remove then upsert again re-inserts cleanly (round-trip idempotency)', function () {
    $m = wpultra_elcss_marker(1, 'a');
    $withBlock = wpultra_elcss_upsert_block('', $m['start'], $m['end'], '.x{color:red}');
    $removed = wpultra_elcss_remove_block($withBlock, $m['start'], $m['end']);
    $reinserted = wpultra_elcss_upsert_block($removed, $m['start'], $m['end'], '.x{color:blue}');
    assert_eq(1, substr_count($reinserted, $m['start']));
    assert_contains('color:blue', $reinserted);
});

/* ------------------------------------------------------------------ *
 * wpultra_elcss_rewrite_selector
 * ------------------------------------------------------------------ */

it('rewrite_selector: replaces the leading selector token in a single rule block', function () {
    $css = 'selector { color: red; }';
    assert_eq('.elementor-element[data-id="abc123"] { color: red; }', wpultra_elcss_rewrite_selector($css, '.elementor-element[data-id="abc123"]'));
});

it('rewrite_selector: replaces selector when combined with a descendant/pseudo suffix', function () {
    $css = 'selector .child:hover { color: blue; }';
    assert_eq('.elementor-element[data-id="abc123"] .child:hover { color: blue; }', wpultra_elcss_rewrite_selector($css, '.elementor-element[data-id="abc123"]'));
});

it('rewrite_selector: replaces every occurrence across multiple rule blocks', function () {
    $css = 'selector { color: red; } selector:hover { color: blue; }';
    $expected = '.el-x { color: red; } .el-x:hover { color: blue; }';
    assert_eq($expected, wpultra_elcss_rewrite_selector($css, '.el-x'));
});

it('rewrite_selector: does not touch "selector" when it is a substring of a larger word', function () {
    $css = '.myselectorclass { color: red; }';
    assert_eq('.myselectorclass { color: red; }', wpultra_elcss_rewrite_selector($css, '.el-x'));
});

it('rewrite_selector: handles a comma-separated selector list', function () {
    $css = 'selector, selector .child { color: red; }';
    assert_eq('.el-x, .el-x .child { color: red; }', wpultra_elcss_rewrite_selector($css, '.el-x'));
});

/* ------------------------------------------------------------------ *
 * wpultra_elcss_concrete_selector + extract_block (supporting pure helpers)
 * ------------------------------------------------------------------ */

it('concrete_selector: scopes via both the per-id class and the data-id attribute', function () {
    assert_eq(
        '.elementor-element-abc123, .elementor-element[data-id="abc123"]',
        wpultra_elcss_concrete_selector('abc123')
    );
});

it('extract_block: returns the body between markers', function () {
    $m = wpultra_elcss_marker(1, 'a');
    $blob = wpultra_elcss_upsert_block('', $m['start'], $m['end'], '.a{color:red}');
    assert_eq('.a{color:red}', wpultra_elcss_extract_block($blob, $m['start'], $m['end']));
});

it('extract_block: returns empty string when the marker is absent', function () {
    $m = wpultra_elcss_marker(1, 'a');
    assert_eq('', wpultra_elcss_extract_block('body{margin:0}', $m['start'], $m['end']));
});

run_tests();
