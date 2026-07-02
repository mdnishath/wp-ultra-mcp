<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
if (!function_exists('plugin_basename')) { function plugin_basename($f) { return 'wp-ultra-mcp/wp-ultra-mcp.php'; } }
if (!defined('WPULTRA_FILE')) { define('WPULTRA_FILE', '/x/wp-ultra-mcp/wp-ultra-mcp.php'); }

// Requiring these engine files must never fatal, even without a real WordPress runtime —
// all WP-calling code lives inside function bodies, not at file scope.
require __DIR__ . '/../wp-ultra-mcp/includes/media/engine.php';
require __DIR__ . '/../wp-ultra-mcp/includes/users/engine.php';
require __DIR__ . '/../wp-ultra-mcp/includes/content/comments.php';
require __DIR__ . '/../wp-ultra-mcp/includes/system/options.php';

it('media engine loads without fatal and exposes the new list fn', function () {
    assert_true(function_exists('wpultra_media_list'), 'wpultra_media_list should be defined');
    assert_true(function_exists('wpultra_media_shape_detailed'), 'wpultra_media_shape_detailed should be defined');
});

it('users engine loads without fatal and exposes the new list fn', function () {
    assert_true(function_exists('wpultra_users_list'), 'wpultra_users_list should be defined');
    assert_true(function_exists('wpultra_users_list_shape'), 'wpultra_users_list_shape should be defined');
});

it('comment shaper maps comment_approved codes to friendly status strings', function () {
    $approved = wpultra_comment_shape_data([
        'comment_ID' => '5', 'comment_post_ID' => '10', 'comment_author' => 'Alice',
        'comment_author_email' => 'a@x.test', 'comment_content' => 'Nice post!',
        'comment_approved' => '1', 'comment_parent' => '0', 'comment_date' => '2026-01-01 00:00:00',
    ]);
    assert_eq(5, $approved['id']);
    assert_eq(10, $approved['post_id']);
    assert_eq('approved', $approved['status']);

    $held = wpultra_comment_shape_data(['comment_ID' => '6', 'comment_approved' => '0']);
    assert_eq('unapproved', $held['status']);

    $spam = wpultra_comment_shape_data(['comment_ID' => '7', 'comment_approved' => 'spam']);
    assert_eq('spam', $spam['status']);

    $trash = wpultra_comment_shape_data(['comment_ID' => '8', 'comment_approved' => 'trash']);
    assert_eq('trash', $trash['status']);
});

it('comment shaper defaults missing fields to safe empty values', function () {
    $shaped = wpultra_comment_shape_data([]);
    assert_eq(0, $shaped['id']);
    assert_eq('', $shaped['author']);
    assert_eq('unapproved', $shaped['status']);
});

it('option sensitivity matcher blocks auth keys and salts (case-insensitive)', function () {
    assert_true(wpultra_option_is_sensitive('auth_key'));
    assert_true(wpultra_option_is_sensitive('SECURE_AUTH_KEY'));
    assert_true(wpultra_option_is_sensitive('logged_in_salt'));
    assert_true(wpultra_option_is_sensitive('Nonce_Salt'));
    assert_true(wpultra_option_is_sensitive('nonce_key'));
});

it('option sensitivity matcher blocks *secret*, *password*, *_key patterns', function () {
    assert_true(wpultra_option_is_sensitive('stripe_secret_key'));
    assert_true(wpultra_option_is_sensitive('smtp_password'));
    assert_true(wpultra_option_is_sensitive('some_api_key'));
    assert_true(wpultra_option_is_sensitive('MY_SECRET'));
});

it('option sensitivity matcher allows ordinary option names', function () {
    assert_true(!wpultra_option_is_sensitive('blogname'));
    assert_true(!wpultra_option_is_sensitive('siteurl'));
    assert_true(!wpultra_option_is_sensitive('wpultra_enabled'));
    assert_true(!wpultra_option_is_sensitive(''));
});

it('option critical-names list protects wp-ultra self-lockout options', function () {
    $critical = wpultra_option_critical_names();
    assert_true(in_array('wpultra_enabled', $critical, true));
    assert_true(in_array('wpultra_ability_rules', $critical, true));
    assert_true(in_array('wpultra_disabled_categories', $critical, true));
});

run_tests();
