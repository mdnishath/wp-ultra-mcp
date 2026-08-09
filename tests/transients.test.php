<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }

// Stub the transient + option functions the engine touches.
$GLOBALS['__transients'] = ['live_key' => 'cached-value'];
if (!function_exists('get_transient'))    { function get_transient($k) { return $GLOBALS['__transients'][$k] ?? false; } }
if (!function_exists('delete_transient')) { function delete_transient($k) { if (isset($GLOBALS['__transients'][$k])) { unset($GLOBALS['__transients'][$k]); return true; } return false; } }
if (!function_exists('wp_json_encode'))   { function wp_json_encode($d, $f = 0) { return json_encode($d, $f); } }

require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/system/transients.php';

it('strip_prefix handles both value and timeout prefixes', function () {
    assert_eq('foo', wpultra_transients_strip_prefix('_transient_foo'));
    assert_eq('foo', wpultra_transients_strip_prefix('_transient_timeout_foo'));
    assert_eq('plain', wpultra_transients_strip_prefix('plain'));
    // Timeout prefix must win over the shorter value prefix.
    assert_eq('x', wpultra_transients_strip_prefix('_transient_timeout_x'));
});

it('get returns a live transient, errors on missing', function () {
    $r = wpultra_transients_get('live_key');
    assert_eq('cached-value', $r['value']);
    assert_wp_error(wpultra_transients_get('nope'));
    assert_wp_error(wpultra_transients_get(''));
});

it('delete removes a transient and reports it', function () {
    $GLOBALS['__transients']['tmp'] = 'x';
    $r = wpultra_transients_delete('tmp');
    assert_true($r['deleted']);
    assert_eq(false, isset($GLOBALS['__transients']['tmp']));
});

it('dispatcher gates delete behind confirm', function () {
    $GLOBALS['__transients']['g'] = 'x';
    assert_wp_error(wpultra_manage_transients(['action' => 'delete', 'key' => 'g'])); // no confirm
    $ok = wpultra_manage_transients(['action' => 'delete', 'key' => 'g', 'confirm' => true]);
    assert_true($ok['success']);
});

it('dispatcher rejects unknown actions', function () {
    assert_wp_error(wpultra_manage_transients(['action' => 'bogus']));
});

run_tests();
