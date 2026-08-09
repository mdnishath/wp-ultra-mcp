<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }

$GLOBALS['__mods'] = ['color' => '#fff', 'logo' => 5];
if (!function_exists('get_theme_mods'))   { function get_theme_mods() { return $GLOBALS['__mods']; } }
if (!function_exists('get_stylesheet'))   { function get_stylesheet() { return 'twentytwentyfour'; } }
if (!function_exists('get_theme_mod'))    { function get_theme_mod($k, $d = false) { return array_key_exists($k, $GLOBALS['__mods']) ? $GLOBALS['__mods'][$k] : $d; } }
if (!function_exists('set_theme_mod'))    { function set_theme_mod($k, $v) { $GLOBALS['__mods'][$k] = $v; } }
if (!function_exists('remove_theme_mod')) { function remove_theme_mod($k) { unset($GLOBALS['__mods'][$k]); } }

require __DIR__ . '/../wp-ultra-mcp/includes/system/options.php';
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/system/thememods.php';

it('list returns all mods with the theme name', function () {
    $r = wpultra_thememods_list();
    assert_eq('twentytwentyfour', $r['theme']);
    assert_eq(2, $r['count']);
    assert_eq('#fff', $r['theme_mods']['color']);
});

it('list redacts sensitive-looking keys', function () {
    $GLOBALS['__mods']['api_secret'] = 'shhh';
    $r = wpultra_thememods_list();
    assert_eq('«redacted»', $r['theme_mods']['api_secret']);
    unset($GLOBALS['__mods']['api_secret']);
});

it('get returns a value, errors when unset or sensitive', function () {
    assert_eq('#fff', wpultra_thememod_get('color')['value']);
    assert_wp_error(wpultra_thememod_get('missing'));
    assert_wp_error(wpultra_thememod_get('stripe_secret'));
});

it('set writes, refuses sensitive keys', function () {
    $r = wpultra_thememod_set('layout', 'wide');
    assert_true($r['saved']);
    assert_eq('wide', $GLOBALS['__mods']['layout']);
    assert_wp_error(wpultra_thememod_set('my_password', 'x'));
});

it('dispatcher gates remove behind confirm', function () {
    $GLOBALS['__mods']['temp'] = 1;
    assert_wp_error(wpultra_manage_theme_mods(['action' => 'remove', 'key' => 'temp']));
    $ok = wpultra_manage_theme_mods(['action' => 'remove', 'key' => 'temp', 'confirm' => true]);
    assert_true($ok['success']);
    assert_eq(false, isset($GLOBALS['__mods']['temp']));
});

run_tests();
