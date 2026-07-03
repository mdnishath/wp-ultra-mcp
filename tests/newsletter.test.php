<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }

// Load the real newsletter domain so we exercise the ACTUAL function names the ability
// router dispatches to, not stubs. Requiring this under the bare harness must never
// fatal (no WP beyond the harness stubs is loaded, and MailPoet/MC4WP classes don't exist).
require __DIR__ . '/../wp-ultra-mcp/includes/newsletter/engine.php';

/* ---------------- driver resolution (pure over a detection map) ---------------- */

it('driver resolution errors when nothing is installed (none case)', function () {
    $detected = ['mailpoet' => null, 'mc4wp' => null];
    $err = wpultra_news_driver('', $detected);
    assert_wp_error($err);
    assert_eq('news_unavailable', $err->get_error_code());
});

it('driver resolution auto-picks the single detected plugin (one case)', function () {
    $detected = ['mailpoet' => '4.5.0', 'mc4wp' => null];
    assert_eq('mailpoet', wpultra_news_driver('', $detected));

    $detected2 = ['mailpoet' => null, 'mc4wp' => '4.8.0'];
    assert_eq('mc4wp', wpultra_news_driver('', $detected2));
});

it('driver resolution auto-picks first detected in canonical order when both installed (two case)', function () {
    $detected = ['mailpoet' => '4.5.0', 'mc4wp' => '4.8.0'];
    assert_eq('mailpoet', wpultra_news_driver('', $detected));
});

it('driver resolution honours explicit choice when installed', function () {
    $detected = ['mailpoet' => null, 'mc4wp' => '4.8.0'];
    assert_eq('mc4wp', wpultra_news_driver('mc4wp', $detected));
});

it('driver resolution errors when explicit plugin is not installed (explicit-missing case)', function () {
    $detected = ['mailpoet' => null, 'mc4wp' => null];
    $err = wpultra_news_driver('mailpoet', $detected);
    assert_wp_error($err);
    assert_eq('news_unavailable', $err->get_error_code());
});

it('driver resolution errors on an unknown plugin key', function () {
    $err = wpultra_news_driver('constantcontact', ['mailpoet' => '4.5.0']);
    assert_wp_error($err);
    assert_eq('news_unknown_plugin', $err->get_error_code());
});

it('detection never fatals with no plugins present and returns both keys null', function () {
    $d = wpultra_news_detect();
    assert_eq(['mailpoet', 'mc4wp'], array_keys($d));
    assert_eq(null, $d['mailpoet']);
    assert_eq(null, $d['mc4wp']);
});

/* ---------------- email validator (pure, filter_var based) ---------------- */

it('valid_email accepts well-formed addresses', function () {
    assert_true(wpultra_news_valid_email('a@b.co'));
    assert_true(wpultra_news_valid_email('first.last+tag@example.com'));
});

it('valid_email rejects empty and malformed addresses', function () {
    assert_true(!wpultra_news_valid_email(''));
    assert_true(!wpultra_news_valid_email('not-an-email'));
    assert_true(!wpultra_news_valid_email('missing@'));
    assert_true(!wpultra_news_valid_email('@missing-local.com'));
});

/* ---------------- list shaper (pure) ---------------- */

it('shape_list normalizes id/name and preserves subscriber_count when present', function () {
    $shaped = wpultra_news_shape_list(['id' => 3, 'name' => 'Newsletter', 'subscriber_count' => 120]);
    assert_eq(3, $shaped['id']);
    assert_eq('Newsletter', $shaped['name']);
    assert_eq(120, $shaped['subscriber_count']);
});

it('shape_list handles mc4wp-style list_id key and subscribers_count alias', function () {
    $shaped = wpultra_news_shape_list(['list_id' => '7', 'name' => 'Updates', 'subscribers_count' => 42]);
    assert_eq('7', $shaped['id']);
    assert_eq(42, $shaped['subscriber_count']);
});

it('shape_list omits subscriber_count entirely when the source has none', function () {
    $shaped = wpultra_news_shape_list(['id' => 1, 'name' => 'Bare List']);
    assert_true(!isset($shaped['subscriber_count']));
});

/* ---------------- plugin label ---------------- */

it('plugin label maps known keys and falls back to the key itself', function () {
    assert_eq('MailPoet', wpultra_news_plugin_label('mailpoet'));
    assert_eq('MC4WP (Mailchimp for WordPress)', wpultra_news_plugin_label('mc4wp'));
    assert_eq('unknownplugin', wpultra_news_plugin_label('unknownplugin'));
});

run_tests();
