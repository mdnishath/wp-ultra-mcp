<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/verticals/membership-adapters.php';

/* ---------------- driver resolution ---------------- */

it('memx driver honours explicit choice when installed', function () {
    assert_eq('pmpro', wpultra_memx_driver('pmpro', ['memberpress' => null, 'pmpro' => '3.1']));
});

it('memx driver errors when explicit plugin is not installed', function () {
    $err = wpultra_memx_driver('memberpress', ['memberpress' => null, 'pmpro' => '3.1']);
    assert_wp_error($err);
    assert_eq('membership_plugin_unavailable', $err->get_error_code());
});

it('memx driver errors on an unknown plugin key', function () {
    $err = wpultra_memx_driver('woocommerce-memberships', ['pmpro' => '3.1']);
    assert_eq('membership_unknown_plugin', $err->get_error_code());
});

it('memx driver auto-picks first detected in canonical order', function () {
    assert_eq('memberpress', wpultra_memx_driver('', ['memberpress' => '1.11', 'pmpro' => '3.1']));
    assert_eq('pmpro', wpultra_memx_driver('', ['memberpress' => null, 'pmpro' => '3.1']));
});

it('memx driver errors when nothing is installed', function () {
    $err = wpultra_memx_driver('', ['memberpress' => null, 'pmpro' => null]);
    assert_eq('membership_plugin_unavailable', $err->get_error_code());
});

it('memx detection never fatals with no plugins present', function () {
    $d = wpultra_memx_detect();
    assert_eq(['memberpress', 'pmpro'], array_keys($d));
    assert_eq(null, $d['memberpress']);
    assert_eq(null, $d['pmpro']);
});

/* ---------------- shaping ---------------- */

it('memx level shape stringifies ids and floats prices', function () {
    $l = wpultra_memx_shape_level(3, 'Gold', '19.99', 'month', 'pmpro');
    assert_eq('3', $l['id']);
    assert_eq(19.99, $l['price']);
    assert_eq('month', $l['period']);
    assert_eq('pmpro', $l['plugin']);
    // non-numeric price degrades to 0.0
    assert_eq(0.0, wpultra_memx_shape_level(1, 'X', 'free', 'lifetime', 'memberpress')['price']);
});

it('memx member shape derives status from level presence', function () {
    $none = wpultra_memx_shape_member(5, [], 'pmpro');
    assert_eq('none', $none['status']);
    $active = wpultra_memx_shape_member(5, [['id' => '2', 'name' => 'Gold']], 'pmpro');
    assert_eq('active', $active['status']);
    assert_eq(5, $active['user_id']);
});

it('memx status rows cover every known plugin with installed=false when absent', function () {
    $rows = wpultra_memx_status();
    assert_eq(2, count($rows));
    assert_eq('MemberPress', $rows[0]['label']);
    assert_eq('Paid Memberships Pro', $rows[1]['label']);
    assert_eq(false, $rows[0]['installed']);
});

run_tests();
