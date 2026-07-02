<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
if (!function_exists('wpultra_err')) { function wpultra_err($code, $msg, $data = '') { return new WP_Error($code, $msg, $data); } }
if (!function_exists('wpultra_ok')) { function wpultra_ok(array $fields) { return array_merge(['success' => true], $fields); } }
require __DIR__ . '/../wp-ultra-mcp/includes/fse/engine.php';

it('deep_merge overwrites scalar leaves with the override value', function () {
    $base = ['color' => 'red', 'size' => 10];
    $over = ['color' => 'blue'];
    assert_eq(['color' => 'blue', 'size' => 10], wpultra_fse_deep_merge($base, $over));
});

it('deep_merge recurses into nested associative arrays', function () {
    $base = ['settings' => ['color' => ['palette' => ['a' => '#111', 'b' => '#222']], 'layout' => ['contentSize' => '800px']]];
    $over = ['settings' => ['color' => ['palette' => ['b' => '#333']]]];
    $expected = ['settings' => ['color' => ['palette' => ['a' => '#111', 'b' => '#333']], 'layout' => ['contentSize' => '800px']]];
    assert_eq($expected, wpultra_fse_deep_merge($base, $over));
});

it('deep_merge adds new keys present only in override', function () {
    $base = ['a' => 1];
    $over = ['b' => 2];
    assert_eq(['a' => 1, 'b' => 2], wpultra_fse_deep_merge($base, $over));
});

it('deep_merge keeps base keys absent from override untouched', function () {
    $base = ['a' => 1, 'b' => ['c' => 2, 'd' => 3]];
    $over = ['b' => ['c' => 99]];
    assert_eq(['a' => 1, 'b' => ['c' => 99, 'd' => 3]], wpultra_fse_deep_merge($base, $over));
});

it('deep_merge replaces list arrays wholesale instead of merging by index', function () {
    $base = ['sources' => ['a.woff', 'b.woff']];
    $over = ['sources' => ['c.woff']];
    assert_eq(['sources' => ['c.woff']], wpultra_fse_deep_merge($base, $over));
});

it('deep_merge replaces an associative value with a list value from override (type change)', function () {
    $base = ['x' => ['k' => 'v']];
    $over = ['x' => ['only-item']];
    assert_eq(['x' => ['only-item']], wpultra_fse_deep_merge($base, $over));
});

it('deep_merge handles empty override as a no-op', function () {
    $base = ['a' => 1, 'b' => ['c' => 2]];
    assert_eq($base, wpultra_fse_deep_merge($base, []));
});

it('deep_merge handles empty base by returning override', function () {
    $over = ['a' => 1, 'b' => ['c' => 2]];
    assert_eq($over, wpultra_fse_deep_merge([], $over));
});

it('deep_merge does not mutate the caller-supplied base array', function () {
    $base = ['a' => ['b' => 1]];
    $snapshot = $base;
    wpultra_fse_deep_merge($base, ['a' => ['b' => 2]]);
    assert_eq($snapshot, $base);
});

it('deep_merge is a nested three-level theme.json style merge (styles.color + settings.spacing)', function () {
    $base = [
        'version'  => 2,
        'settings' => ['spacing' => ['units' => ['px', 'em'], 'spacingScale' => ['steps' => 7]]],
        'styles'   => ['color' => ['background' => '#fff', 'text' => '#000']],
    ];
    $over = [
        'settings' => ['spacing' => ['spacingScale' => ['steps' => 5]]],
        'styles'   => ['color' => ['text' => '#111']],
    ];
    $expected = [
        'version'  => 2,
        'settings' => ['spacing' => ['units' => ['px', 'em'], 'spacingScale' => ['steps' => 5]]],
        'styles'   => ['color' => ['background' => '#fff', 'text' => '#111']],
    ];
    assert_eq($expected, wpultra_fse_deep_merge($base, $over));
});

it('fse_resolver_available reflects WP_Theme_JSON_Resolver class presence (absent under harness)', function () {
    assert_true(!wpultra_fse_resolver_available());
});

it('fse_block_theme_available is false when wp_is_block_theme() is undefined', function () {
    assert_true(!wpultra_fse_block_theme_available());
});

it('theme_json_get returns fse_unavailable WP_Error when resolver class is missing', function () {
    $res = wpultra_fse_theme_json_get('merged');
    assert_wp_error($res);
    assert_eq('fse_unavailable', $res->get_error_code());
});

it('theme_json_set returns fse_unavailable WP_Error when resolver class is missing', function () {
    $res = wpultra_fse_theme_json_set(['a' => 1], [], true);
    assert_wp_error($res);
    assert_eq('fse_unavailable', $res->get_error_code());
});

it('template_list returns fse_unavailable WP_Error when not a block theme', function () {
    $res = wpultra_fse_template_list('wp_template');
    assert_wp_error($res);
    assert_eq('fse_unavailable', $res->get_error_code());
});

it('template_get returns fse_unavailable WP_Error when not a block theme', function () {
    $res = wpultra_fse_template_get('single', 'wp_template');
    assert_wp_error($res);
    assert_eq('fse_unavailable', $res->get_error_code());
});

it('template_upsert returns fse_unavailable WP_Error when not a block theme', function () {
    $res = wpultra_fse_template_upsert('single', 'wp_template', '<!-- wp:paragraph --><!-- /wp:paragraph -->', 'Single');
    assert_wp_error($res);
    assert_eq('fse_unavailable', $res->get_error_code());
});

it('template_delete returns fse_unavailable WP_Error when not a block theme', function () {
    $res = wpultra_fse_template_delete('single', 'wp_template');
    assert_wp_error($res);
    assert_eq('fse_unavailable', $res->get_error_code());
});

it('template_reset delegates to delete and returns fse_unavailable when not a block theme', function () {
    $res = wpultra_fse_template_reset('single', 'wp_template');
    assert_wp_error($res);
    assert_eq('fse_unavailable', $res->get_error_code());
});

it('custom_css_get returns fse_unavailable WP_Error when wp_get_custom_css() is undefined', function () {
    $res = wpultra_fse_custom_css_get();
    assert_wp_error($res);
    assert_eq('fse_unavailable', $res->get_error_code());
});

it('custom_css_set returns fse_unavailable WP_Error when wp_update_custom_css_post() is undefined', function () {
    $res = wpultra_fse_custom_css_set('body { color: red; }', false);
    assert_wp_error($res);
    assert_eq('fse_unavailable', $res->get_error_code());
});

run_tests();
