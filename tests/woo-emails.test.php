<?php
require_once __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
if (!function_exists('get_option')) { function get_option($k, $d = false) { return $GLOBALS['__opts'][$k] ?? $d; } }
if (!function_exists('update_option')) { function update_option($k, $v, $a = null) { $GLOBALS['__opts'][$k] = $v; return true; } }
require_once __DIR__ . '/../wp-ultra-mcp/includes/woocommerce/emails.php';

// ---- whitelist filter ----

it('whitelist filter accepts known keys', function () {
    $r = wpultra_woo_email_whitelist_filter([
        'enabled' => 'yes',
        'subject' => 'Your order is processing',
        'heading' => 'Thanks for your order',
        'additional_content' => 'See you soon.',
        'recipient' => 'admin@example.com',
        'email_type' => 'html',
    ]);
    assert_eq(6, count($r['accepted']));
    assert_eq(0, count($r['rejected']));
    assert_eq('yes', $r['accepted']['enabled']);
});

it('whitelist filter rejects unknown keys', function () {
    $r = wpultra_woo_email_whitelist_filter([
        'subject' => 'ok',
        'from_name' => 'Attacker',
        'template_html' => '<script>evil()</script>',
    ]);
    assert_eq(1, count($r['accepted']));
    assert_eq(2, count($r['rejected']));
    $reasons = array_column($r['rejected'], 'reason');
    assert_true(in_array('not_whitelisted', $reasons, true));
    $keys = array_column($r['rejected'], 'key');
    assert_true(in_array('from_name', $keys, true));
    assert_true(in_array('template_html', $keys, true));
});

it('whitelist filter accepts empty input', function () {
    $r = wpultra_woo_email_whitelist_filter([]);
    assert_eq(0, count($r['accepted']));
    assert_eq(0, count($r['rejected']));
});

// ---- per-email validator ----

it('validate accepts good enabled values', function () {
    assert_true(wpultra_woo_email_validate(['enabled' => 'yes']) === true);
    assert_true(wpultra_woo_email_validate(['enabled' => 'no']) === true);
    assert_true(wpultra_woo_email_validate(['enabled' => true]) === true);
    assert_true(wpultra_woo_email_validate(['enabled' => false]) === true);
});

it('validate rejects bad enabled value', function () {
    $r = wpultra_woo_email_validate(['enabled' => 'maybe']);
    assert_eq('invalid_enabled', $r);
});

it('validate accepts good email_type values and rejects bad ones', function () {
    assert_true(wpultra_woo_email_validate(['email_type' => 'plain']) === true);
    assert_true(wpultra_woo_email_validate(['email_type' => 'html']) === true);
    assert_true(wpultra_woo_email_validate(['email_type' => 'multipart']) === true);
    $r = wpultra_woo_email_validate(['email_type' => 'rtf']);
    assert_eq('invalid_email_type', $r);
});

it('validate accepts a good single recipient and a good comma list', function () {
    assert_true(wpultra_woo_email_validate(['recipient' => 'owner@example.com']) === true);
    assert_true(wpultra_woo_email_validate(['recipient' => 'a@example.com, b@example.com']) === true);
});

it('validate rejects a malformed recipient list', function () {
    $r1 = wpultra_woo_email_validate(['recipient' => 'not-an-email']);
    assert_eq('invalid_recipient', $r1);
    $r2 = wpultra_woo_email_validate(['recipient' => 'good@example.com, bad']);
    assert_eq('invalid_recipient', $r2);
    $r3 = wpultra_woo_email_validate(['recipient' => '']);
    assert_eq('invalid_recipient', $r3);
});

it('validate rejects non-string subject/heading/additional_content', function () {
    $r = wpultra_woo_email_validate(['subject' => ['not', 'a', 'string']]);
    assert_eq('invalid_subject', $r);
});

it('validate passes a full good settings map', function () {
    $r = wpultra_woo_email_validate([
        'enabled' => 'yes',
        'subject' => 'Order #{order_number} received',
        'heading' => 'Thank you',
        'additional_content' => 'We appreciate your business.',
        'recipient' => 'admin@example.com',
        'email_type' => 'html',
    ]);
    assert_true($r === true);
});

// ---- hex color helper ----

it('hex color validator accepts 3 and 6 digit hex', function () {
    assert_true(wpultra_woo_email_is_hex_color('#fff'));
    assert_true(wpultra_woo_email_is_hex_color('#FFAA00'));
});

it('hex color validator rejects bad formats', function () {
    assert_true(!wpultra_woo_email_is_hex_color('red'));
    assert_true(!wpultra_woo_email_is_hex_color('#ffff'));
    assert_true(!wpultra_woo_email_is_hex_color('ffffff'));
    assert_true(!wpultra_woo_email_is_hex_color(''));
});

// ---- globals validator ----

it('globals validate accepts good hex colors and from_address', function () {
    $r = wpultra_woo_email_globals_validate([
        'woocommerce_email_base_color' => '#96588a',
        'woocommerce_email_background_color' => '#f7f7f7',
        'woocommerce_email_body_background_color' => '#fdfdfd',
        'woocommerce_email_text_color' => '#3c3c3c',
        'woocommerce_email_from_name' => 'My Store',
        'woocommerce_email_from_address' => 'store@example.com',
        'woocommerce_email_footer_text' => 'Thanks for shopping!',
    ]);
    assert_true($r === true);
});

it('globals validate rejects bad hex color', function () {
    $r = wpultra_woo_email_globals_validate(['woocommerce_email_base_color' => 'purple']);
    assert_eq('invalid_color:woocommerce_email_base_color', $r);
});

it('globals validate rejects malformed from_address', function () {
    $r = wpultra_woo_email_globals_validate(['woocommerce_email_from_address' => 'not-an-email']);
    assert_eq('invalid_from_address', $r);
});

it('globals update rejects non-whitelisted keys', function () {
    $r = wpultra_woo_email_globals_update(['siteurl' => 'http://evil.example']);
    assert_eq(0, count($r['updated']));
    assert_eq(1, count($r['rejected']));
    assert_eq('not_whitelisted', $r['rejected'][0]['reason']);
});

run_tests();
