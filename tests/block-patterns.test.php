<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }

$GLOBALS['__opts'] = [];
if (!function_exists('get_option'))    { function get_option($k, $d = false) { return $GLOBALS['__opts'][$k] ?? $d; } }
if (!function_exists('update_option')) { function update_option($k, $v, $a = null) { $GLOBALS['__opts'][$k] = $v; return true; } }

require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
// Only the pure/option-backed half is exercised here (no register_block_pattern).
require __DIR__ . '/../wp-ultra-mcp/includes/gutenberg/patterns.php';

it('normalize namespaces and sanitizes names', function () {
    assert_eq('wpultra/hero', wpultra_gb_pattern_normalize_name('hero'));
    assert_eq('wpultra/hero', wpultra_gb_pattern_normalize_name('Hero!'));
    assert_eq('acme/cta', wpultra_gb_pattern_normalize_name('acme/cta'));
    assert_eq('', wpultra_gb_pattern_normalize_name(''));
});

it('save creates then updates a pattern in the option', function () {
    $GLOBALS['__opts'] = [];
    // register hook is a no-op here (register_block_pattern undefined) — guarded.
    $r = wpultra_gb_pattern_save(['name' => 'hero', 'title' => 'Hero', 'content' => '<!-- wp:paragraph -->hi<!-- /wp:paragraph -->']);
    assert_true($r['saved']);
    assert_eq(false, $r['updated']);
    assert_true(isset($GLOBALS['__opts']['wpultra_block_patterns']['wpultra/hero']));
    $r2 = wpultra_gb_pattern_save(['name' => 'hero', 'content' => '<!-- wp:heading -->x<!-- /wp:heading -->']);
    assert_true($r2['updated']);
});

it('save rejects empty name or content', function () {
    assert_wp_error(wpultra_gb_pattern_save(['name' => '', 'content' => 'x']));
    assert_wp_error(wpultra_gb_pattern_save(['name' => 'hero', 'content' => '   ']));
});

it('delete removes a stored pattern, errors when absent', function () {
    $GLOBALS['__opts'] = [];
    wpultra_gb_pattern_save(['name' => 'gone', 'content' => '<!-- wp:spacer /-->']);
    $d = wpultra_gb_pattern_delete('gone');
    assert_true($d['deleted']);
    assert_wp_error(wpultra_gb_pattern_delete('gone'));
});

it('dispatcher gates delete behind confirm', function () {
    $GLOBALS['__opts'] = [];
    wpultra_gb_pattern_save(['name' => 'x', 'content' => '<!-- wp:spacer /-->']);
    assert_wp_error(wpultra_register_block_pattern_cb(['action' => 'delete', 'name' => 'x']));
    $ok = wpultra_register_block_pattern_cb(['action' => 'delete', 'name' => 'x', 'confirm' => true]);
    assert_true($ok['success']);
});

run_tests();
