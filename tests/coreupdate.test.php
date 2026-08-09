<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/system/coreupdate.php';

it('shape flags a newer upgrade offer', function () {
    $offer = (object) ['response' => 'upgrade', 'version' => '6.9.1', 'locale' => 'en_US', 'partial' => false];
    $r = wpultra_coreupdate_shape($offer, '6.9.0');
    assert_eq('upgrade', $r['response']);
    assert_eq('6.9.1', $r['version']);
    assert_true($r['is_newer']);
});

it('shape does not flag the current or older version as newer', function () {
    $same = wpultra_coreupdate_shape((object) ['response' => 'latest', 'version' => '6.9.0'], '6.9.0');
    assert_eq(false, $same['is_newer']);
    $older = wpultra_coreupdate_shape((object) ['response' => 'upgrade', 'version' => '6.8.0'], '6.9.0');
    assert_eq(false, $older['is_newer']);
});

it('dispatcher gates update behind confirm', function () {
    // No confirm → confirm_required, without ever touching the upgrader.
    $r = wpultra_core_update(['action' => 'update']);
    assert_wp_error($r);
    assert_eq('confirm_required', $r->get_error_code());
});

it('dispatcher rejects unknown actions', function () {
    assert_wp_error(wpultra_core_update(['action' => 'nope']));
});

run_tests();
