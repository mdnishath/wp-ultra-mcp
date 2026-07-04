<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';

if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/wpultra_whitelabel/'); }
// helpers.php provides wpultra_err / wpultra_ok; engine is pure-loadable.
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/system/whitelabel.php';

/* ============================================================
 * defaults + brand merge
 * ============================================================ */

it('defaults carry the WP-Ultra-MCP originals', function () {
    $d = wpultra_wlabel_defaults();
    assert_eq('WP Ultra MCP', $d['brand']['plugin_name']);
    assert_eq('WP Ultra MCP', $d['brand']['menu_title']);
    assert_true($d['enabled'] === false, 'disabled by default');
    assert_true($d['client_mode']['enabled'] === false, 'client mode off by default');
});

it('merge_config fills missing brand fields with the originals', function () {
    $m = wpultra_wlabel_merge_config(['enabled' => true, 'brand' => ['menu_title' => 'Acme Tools']]);
    assert_eq('Acme Tools', $m['brand']['menu_title']);
    assert_eq('WP Ultra MCP', $m['brand']['plugin_name']); // untouched -> default
    assert_true($m['enabled'] === true, 'enabled honored');
});

it('merge_config drops unknown keys and coerces types', function () {
    $m = wpultra_wlabel_merge_config(['enabled' => 'yes', 'brand' => ['hide_wp_logo' => 1], 'garbage' => 'x']);
    assert_true(!array_key_exists('garbage', $m), 'unknown top-level key dropped');
    assert_true($m['enabled'] === true, 'string truthy -> true');
    assert_true($m['brand']['hide_wp_logo'] === true, 'int 1 -> true');
});

it('merge_config sanitizes brand strings (tags/whitespace stripped)', function () {
    $m = wpultra_wlabel_merge_config(['brand' => ['vendor_name' => "  <b>Acme</b>\t Inc  "]]);
    assert_eq('Acme Inc', $m['brand']['vendor_name']);
});

it('merge_config keeps a valid url and blanks a bad one', function () {
    $good = wpultra_wlabel_merge_config(['brand' => ['vendor_url' => 'https://acme.example/x']]);
    assert_eq('https://acme.example/x', $good['brand']['vendor_url']);
    $bad = wpultra_wlabel_merge_config(['brand' => ['vendor_url' => 'javascript:alert(1)']]);
    assert_eq('', $bad['brand']['vendor_url']);
    $bad2 = wpultra_wlabel_merge_config(['brand' => ['login_logo_url' => 'not a url']]);
    assert_eq('', $bad2['brand']['login_logo_url']);
});

it('clean_url accepts protocol-relative, rejects data:/scheme-less', function () {
    assert_eq('//cdn.example/logo.png', wpultra_wlabel_clean_url('//cdn.example/logo.png'));
    assert_eq('', wpultra_wlabel_clean_url('data:image/png;base64,AAAA'));
    assert_eq('', wpultra_wlabel_clean_url('ftp://x/y'));
    assert_eq('https://a.b/c', wpultra_wlabel_clean_url('  https://a.b/c  '));
});

/* ============================================================
 * role/slug normalization
 * ============================================================ */

it('clean_role_list lowercases, dedupes, drops malformed', function () {
    $r = wpultra_wlabel_clean_role_list(['Administrator', 'editor', 'editor', 'bad role!', '', 3]);
    assert_eq(['administrator', 'editor'], $r);
});

it('clean_slug_list keeps admin.php?page= style, drops markup', function () {
    $s = wpultra_wlabel_clean_slug_list(['wpultra', 'admin.php?page=foo', '<script>', '', 5]);
    assert_eq(['wpultra', 'admin.php?page=foo'], $s);
});

/* ============================================================
 * should_restrict — admin exempt, intersection, empty allow-list
 * ============================================================ */

it('should_restrict: role intersects allowed -> false', function () {
    assert_true(wpultra_wlabel_should_restrict(['editor'], ['editor', 'author']) === false, 'editor allowed');
});

it('should_restrict: no intersection -> true', function () {
    assert_true(wpultra_wlabel_should_restrict(['subscriber'], ['editor']) === true, 'subscriber restricted');
});

it('should_restrict: administrator ALWAYS exempt even if not in allowed', function () {
    assert_true(wpultra_wlabel_should_restrict(['administrator'], ['editor']) === false, 'admin exempt');
    assert_true(wpultra_wlabel_should_restrict(['administrator'], []) === false, 'admin exempt with empty allow-list');
    assert_true(wpultra_wlabel_should_restrict(['super_admin'], []) === false, 'super_admin exempt');
});

it('should_restrict: empty allowed_roles -> restrict all non-privileged', function () {
    assert_true(wpultra_wlabel_should_restrict(['editor'], []) === true, 'editor restricted under empty allow-list');
    assert_true(wpultra_wlabel_should_restrict([], []) === true, 'no roles -> restricted');
});

it('should_restrict: case-insensitive role matching', function () {
    assert_true(wpultra_wlabel_should_restrict(['Editor'], ['EDITOR']) === false, 'case folded on both sides');
});

/* ============================================================
 * filter_menus — remove listed, keep others
 * ============================================================ */

function wpultra_wlabel_fixture_menu(): array {
    return [
        [ 'Dashboard', 'read', 'index.php' ],
        [ 'WP Ultra MCP', 'manage_options', 'wpultra' ],
        [ 'Posts', 'edit_posts', 'edit.php' ],
        [ 'Tools', 'edit_posts', 'tools.php' ],
    ];
}

it('filter_menus removes the listed slugs, keeps the rest in order', function () {
    $out = wpultra_wlabel_filter_menus(wpultra_wlabel_fixture_menu(), ['wpultra', 'tools.php']);
    $slugs = array_map(fn($r) => $r[2], array_values($out));
    assert_eq(['index.php', 'edit.php'], $slugs);
});

it('filter_menus with an empty hide-list returns the menu unchanged', function () {
    $menu = wpultra_wlabel_fixture_menu();
    assert_eq($menu, wpultra_wlabel_filter_menus($menu, []));
});

it('filter_menus ignores a slug that is not present', function () {
    $menu = wpultra_wlabel_fixture_menu();
    $out = wpultra_wlabel_filter_menus($menu, ['does-not-exist']);
    assert_eq(count($menu), count($out));
});

/* ============================================================
 * relabel_menu — rename matching slug only
 * ============================================================ */

it('relabel_menu renames the matching slug row title in place', function () {
    $out = wpultra_wlabel_relabel_menu(wpultra_wlabel_fixture_menu(), 'wpultra', 'Acme Tools');
    $byslug = [];
    foreach ($out as $r) { $byslug[$r[2]] = $r[0]; }
    assert_eq('Acme Tools', $byslug['wpultra']);
    assert_eq('Dashboard', $byslug['index.php']); // others untouched
});

it('relabel_menu leaves the menu unchanged when the slug is missing', function () {
    $menu = wpultra_wlabel_fixture_menu();
    assert_eq($menu, wpultra_wlabel_relabel_menu($menu, 'nope', 'X'));
});

it('relabel_menu is a no-op with an empty new title', function () {
    $menu = wpultra_wlabel_fixture_menu();
    assert_eq($menu, wpultra_wlabel_relabel_menu($menu, 'wpultra', ''));
});

/* ============================================================
 * validate — warnings for coerced/dropped fields
 * ============================================================ */

it('validate warns on a bad url and clears it', function () {
    $v = wpultra_wlabel_validate(['brand' => ['vendor_url' => 'javascript:x']]);
    assert_eq('', $v['config']['brand']['vendor_url']);
    assert_true(count($v['warnings']) >= 1, 'a warning was emitted');
    assert_contains('vendor_url', $v['warnings'][0]);
});

it('validate warns when malformed roles are dropped', function () {
    $v = wpultra_wlabel_validate(['client_mode' => ['allowed_roles' => ['editor', 'bad role!', '']]]);
    assert_eq(['editor'], $v['config']['client_mode']['allowed_roles']);
    $joined = implode(' ', $v['warnings']);
    assert_contains('allowed_roles', $joined);
});

it('validate warns when malformed menu slugs are dropped', function () {
    $v = wpultra_wlabel_validate(['client_mode' => ['hide_menus' => ['wpultra', '<script>']]]);
    assert_eq(['wpultra'], $v['config']['client_mode']['hide_menus']);
    $joined = implode(' ', $v['warnings']);
    assert_contains('hide_menus', $joined);
});

it('validate on a fully-clean patch returns no warnings', function () {
    $v = wpultra_wlabel_validate([
        'enabled' => true,
        'brand'   => ['menu_title' => 'Acme', 'vendor_url' => 'https://acme.example'],
        'client_mode' => ['enabled' => true, 'allowed_roles' => ['administrator', 'editor'], 'hide_menus' => ['wpultra']],
    ]);
    assert_eq([], $v['warnings']);
    assert_eq('Acme', $v['config']['brand']['menu_title']);
});

/* ============================================================
 * preview — computed strings + role simulation, no side effects
 * ============================================================ */

it('preview: restricted editor sees hidden menus/plugin', function () {
    $config = wpultra_wlabel_merge_config([
        'enabled' => true,
        'brand'   => ['menu_title' => 'Acme Tools'],
        'client_mode' => [
            'enabled' => true,
            'allowed_roles' => ['administrator'],
            'hide_menus' => ['wpultra'],
            'hide_plugin_from' => ['editor'],
        ],
    ]);
    $p = wpultra_wlabel_preview($config, ['editor']);
    assert_true($p['restricted'] === true, 'editor is restricted');
    assert_eq(['wpultra'], $p['would_hide_menus']);
    assert_true($p['would_hide_plugin'] === true, 'plugin hidden from editor');
    assert_eq('Acme Tools', $p['brand']['menu_title']);
});

it('preview: allowed role is not restricted', function () {
    $config = wpultra_wlabel_merge_config([
        'enabled' => true,
        'client_mode' => ['enabled' => true, 'allowed_roles' => ['editor'], 'hide_menus' => ['wpultra']],
    ]);
    $p = wpultra_wlabel_preview($config, ['editor']);
    assert_true($p['restricted'] === false, 'editor allowed');
    assert_eq([], $p['would_hide_menus']);
});

it('preview: client_mode disabled -> nobody restricted', function () {
    $config = wpultra_wlabel_merge_config(['enabled' => true, 'client_mode' => ['enabled' => false, 'hide_menus' => ['wpultra']]]);
    $p = wpultra_wlabel_preview($config, ['subscriber']);
    assert_true($p['client_mode_active'] === false, 'client mode reported off');
    assert_true($p['restricted'] === false, 'not restricted when client mode off');
});

it('preview: administrator never restricted', function () {
    $config = wpultra_wlabel_merge_config([
        'enabled' => true,
        'client_mode' => ['enabled' => true, 'allowed_roles' => ['editor'], 'hide_menus' => ['wpultra'], 'hide_plugin_from' => ['administrator']],
    ]);
    $p = wpultra_wlabel_preview($config, ['administrator']);
    assert_true($p['restricted'] === false, 'admin exempt');
    assert_true($p['would_hide_plugin'] === false, 'plugin never hidden from admin');
});

run_tests();
