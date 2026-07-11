<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/elementor/classes.php';
require __DIR__ . '/../wp-ultra-mcp/includes/elementor/variants.php';

$ACTIVE_BPS = ['mobile', 'mobile_extra', 'tablet', 'tablet_extra', 'laptop', 'widescreen'];

/* ------------------------------------------------------------------ *
 * wpultra_el_variant_state_norm
 * ------------------------------------------------------------------ */

it('state_norm: normal maps to null (the base state)', function () {
    assert_eq(null, wpultra_el_variant_state_norm('normal'));
});
it('state_norm: empty string maps to null', function () {
    assert_eq(null, wpultra_el_variant_state_norm(''));
});
it('state_norm: hover passes through', function () {
    assert_eq('hover', wpultra_el_variant_state_norm('hover'));
});
it('state_norm: is case-insensitive and trims whitespace', function () {
    assert_eq('focus', wpultra_el_variant_state_norm(' Focus '));
    assert_eq('active', wpultra_el_variant_state_norm('ACTIVE'));
});

/* ------------------------------------------------------------------ *
 * wpultra_el_variant_breakpoint_norm
 * ------------------------------------------------------------------ */

it('breakpoint_norm: desktop maps to null (the base breakpoint)', function () use ($ACTIVE_BPS) {
    assert_eq(null, wpultra_el_variant_breakpoint_norm('desktop', $ACTIVE_BPS));
});
it('breakpoint_norm: empty string maps to null', function () use ($ACTIVE_BPS) {
    assert_eq(null, wpultra_el_variant_breakpoint_norm('', $ACTIVE_BPS));
});
it('breakpoint_norm: accepts an active breakpoint key (case-insensitive)', function () use ($ACTIVE_BPS) {
    assert_eq('tablet', wpultra_el_variant_breakpoint_norm('Tablet', $ACTIVE_BPS));
    assert_eq('mobile_extra', wpultra_el_variant_breakpoint_norm('mobile_extra', $ACTIVE_BPS));
});
it('breakpoint_norm: rejects a breakpoint not in the active set', function () use ($ACTIVE_BPS) {
    $r = wpultra_el_variant_breakpoint_norm('phablet', $ACTIVE_BPS);
    assert_wp_error($r, 'unknown breakpoint must error');
    assert_contains('phablet', $r->get_error_message());
    assert_contains('desktop', $r->get_error_message(), 'error should list desktop as a valid option');
    assert_contains('tablet', $r->get_error_message(), 'error should list active breakpoints');
});
it('breakpoint_norm: with no active breakpoints, only desktop is valid', function () {
    $r = wpultra_el_variant_breakpoint_norm('tablet', []);
    assert_wp_error($r, 'tablet is not valid when Elementor reports no active breakpoints');
});

/* ------------------------------------------------------------------ *
 * wpultra_el_variant_meta — friendly-name resolution + validation
 * ------------------------------------------------------------------ */

it('variant_meta: desktop+normal resolves to the base meta {null,null}', function () use ($ACTIVE_BPS) {
    $m = wpultra_el_variant_meta('desktop', 'normal', $ACTIVE_BPS);
    assert_eq(['state' => null, 'breakpoint' => null], $m);
});
it('variant_meta: omitted/empty names default to the base meta', function () use ($ACTIVE_BPS) {
    $m = wpultra_el_variant_meta('', '', $ACTIVE_BPS);
    assert_eq(['state' => null, 'breakpoint' => null], $m);
});
it('variant_meta: tablet+hover resolves correctly', function () use ($ACTIVE_BPS) {
    $m = wpultra_el_variant_meta('tablet', 'hover', $ACTIVE_BPS);
    assert_eq(['state' => 'hover', 'breakpoint' => 'tablet'], $m);
});
it('variant_meta: mobile+hover for "hover on mobile" resolves correctly', function () use ($ACTIVE_BPS) {
    $m = wpultra_el_variant_meta('mobile', 'hover', $ACTIVE_BPS);
    assert_eq(['state' => 'hover', 'breakpoint' => 'mobile'], $m);
});
it('variant_meta: is case-insensitive on both names', function () use ($ACTIVE_BPS) {
    $m = wpultra_el_variant_meta('DESKTOP', 'Hover', $ACTIVE_BPS);
    assert_eq(['state' => 'hover', 'breakpoint' => null], $m);
});
it('variant_meta: rejects an unknown breakpoint with a helpful error', function () use ($ACTIVE_BPS) {
    $m = wpultra_el_variant_meta('phablet', 'normal', $ACTIVE_BPS);
    assert_wp_error($m);
    assert_eq('invalid_breakpoint', $m->get_error_code());
});
it('variant_meta: rejects an unknown state with a helpful error', function () use ($ACTIVE_BPS) {
    $m = wpultra_el_variant_meta('desktop', 'glow', $ACTIVE_BPS);
    assert_wp_error($m);
    assert_eq('invalid_state', $m->get_error_code());
    assert_contains('hover', $m->get_error_message());
});
it('variant_meta: state is validated even when breakpoint is also invalid (state checked first)', function () {
    $m = wpultra_el_variant_meta('phablet', 'glow', []);
    assert_wp_error($m);
    assert_eq('invalid_state', $m->get_error_code());
});

/* ------------------------------------------------------------------ *
 * wpultra_el_variant_merge — the pure variant-array merge (the heart)
 * ------------------------------------------------------------------ */

function base_only_variants(): array {
    return [
        ['meta' => ['state' => null, 'breakpoint' => null], 'props' => ['color' => 'red']],
    ];
}

it('merge: appends a new hover variant to a base-only class, base untouched', function () {
    $variants = base_only_variants();
    $meta = ['state' => 'hover', 'breakpoint' => null];
    $out = wpultra_el_variant_merge($variants, $meta, ['color' => 'blue'], false);
    assert_eq(2, count($out));
    assert_eq(['state' => null, 'breakpoint' => null], $out[0]['meta']);
    assert_eq(['color' => 'red'], $out[0]['props'], 'base props must be untouched');
    assert_eq(['state' => 'hover', 'breakpoint' => null], $out[1]['meta']);
    assert_eq(['color' => 'blue'], $out[1]['props']);
});

it('merge: updates an existing tablet variant in place without disturbing others', function () {
    $variants = [
        ['meta' => ['state' => null, 'breakpoint' => null], 'props' => ['color' => 'red']],
        ['meta' => ['state' => null, 'breakpoint' => 'tablet'], 'props' => ['color' => 'green']],
        ['meta' => ['state' => 'hover', 'breakpoint' => null], 'props' => ['color' => 'yellow']],
    ];
    $meta = ['state' => null, 'breakpoint' => 'tablet'];
    $out = wpultra_el_variant_merge($variants, $meta, ['color' => 'purple'], false);
    assert_eq(3, count($out), 'update in place must not change the count');
    assert_eq(['color' => 'red'], $out[0]['props'], 'base must be untouched');
    assert_eq(['color' => 'purple'], $out[1]['props'], 'tablet variant must be updated');
    assert_eq(['state' => null, 'breakpoint' => 'tablet'], $out[1]['meta'], 'meta of updated variant unchanged');
    assert_eq(['color' => 'yellow'], $out[2]['props'], 'hover variant must be untouched');
});

it('merge: removes a matching hover variant', function () {
    $variants = [
        ['meta' => ['state' => null, 'breakpoint' => null], 'props' => ['color' => 'red']],
        ['meta' => ['state' => 'hover', 'breakpoint' => null], 'props' => ['color' => 'blue']],
    ];
    $meta = ['state' => 'hover', 'breakpoint' => null];
    $out = wpultra_el_variant_merge($variants, $meta, [], true);
    assert_eq(1, count($out));
    assert_eq(['state' => null, 'breakpoint' => null], $out[0]['meta']);
});

it('merge: refuses to remove the base variant, leaving variants unchanged', function () {
    $variants = base_only_variants();
    $meta = ['state' => null, 'breakpoint' => null];
    $out = wpultra_el_variant_merge($variants, $meta, [], true);
    assert_eq($variants, $out, 'base must survive a remove request unchanged');
});

it('merge: removing a variant that does not exist is a no-op', function () {
    $variants = base_only_variants();
    $meta = ['state' => 'focus', 'breakpoint' => 'mobile'];
    $out = wpultra_el_variant_merge($variants, $meta, [], true);
    assert_eq($variants, $out);
});

it('merge: appending to an empty variants array (brand-new class) produces one variant', function () {
    $meta = ['state' => null, 'breakpoint' => null];
    $out = wpultra_el_variant_merge([], $meta, ['color' => 'red'], false);
    assert_eq(1, count($out));
    assert_eq(['meta' => ['state' => null, 'breakpoint' => null], 'props' => ['color' => 'red']], $out[0]);
});

it('merge: deep-equal meta matching keeps mobile+hover, mobile+focus, and tablet+hover distinct', function () {
    $variants = base_only_variants();
    $variants = wpultra_el_variant_merge($variants, ['state' => 'hover', 'breakpoint' => 'mobile'], ['a' => 1], false);
    $variants = wpultra_el_variant_merge($variants, ['state' => 'focus', 'breakpoint' => 'mobile'], ['a' => 2], false);
    $variants = wpultra_el_variant_merge($variants, ['state' => 'hover', 'breakpoint' => 'tablet'], ['a' => 3], false);
    assert_eq(4, count($variants), 'base + 3 distinct variants must all coexist');

    // Now update mobile+hover only, and confirm the other two (and base) are untouched.
    $variants = wpultra_el_variant_merge($variants, ['state' => 'hover', 'breakpoint' => 'mobile'], ['a' => 99], false);
    assert_eq(4, count($variants), 'update must not add a new entry');

    $found = [];
    foreach ($variants as $v) {
        $key = ($v['meta']['state'] ?? 'null') . '|' . ($v['meta']['breakpoint'] ?? 'null');
        $found[$key] = $v['props'];
    }
    assert_eq(['a' => 99], $found['hover|mobile'], 'mobile+hover must be updated');
    assert_eq(['a' => 2], $found['focus|mobile'], 'mobile+focus must be untouched');
    assert_eq(['a' => 3], $found['hover|tablet'], 'tablet+hover must be untouched');
});

it('merge: removing one of several distinct variants leaves the rest intact', function () {
    $variants = base_only_variants();
    $variants = wpultra_el_variant_merge($variants, ['state' => 'hover', 'breakpoint' => 'mobile'], ['a' => 1], false);
    $variants = wpultra_el_variant_merge($variants, ['state' => 'focus', 'breakpoint' => 'mobile'], ['a' => 2], false);
    $variants = wpultra_el_variant_merge($variants, ['state' => 'hover', 'breakpoint' => 'mobile'], [], true);
    assert_eq(2, count($variants));
    foreach ($variants as $v) {
        assert_true(!($v['meta']['state'] === 'hover' && $v['meta']['breakpoint'] === 'mobile'), 'hover+mobile must be gone');
    }
});

/* ------------------------------------------------------------------ *
 * wpultra_el_variant_valid_states / wpultra_el_active_breakpoint_keys
 * ------------------------------------------------------------------ */

it('valid_states: lists normal, hover, focus, active', function () {
    assert_eq(['normal', 'hover', 'focus', 'active'], wpultra_el_variant_valid_states());
});

it('active_breakpoint_keys: returns [] when Elementor is not loaded', function () {
    assert_eq([], wpultra_el_active_breakpoint_keys());
});

/* ------------------------------------------------------------------ *
 * wpultra_el_variant_upsert — WP-touching wrapper: guard clauses only
 * (full repo-backed behavior is live-tested later; here we only confirm
 * it is wired up and refuses correctly without a real Elementor runtime)
 * ------------------------------------------------------------------ */

it('variant_upsert: refuses when the e_classes experiment is not active (no Elementor loaded)', function () {
    $r = wpultra_el_variant_upsert(null, 'My Class', ['state' => null, 'breakpoint' => null], ['color' => 'red'], false);
    assert_wp_error($r);
    assert_eq('classes_inactive', $r->get_error_code());
});

run_tests();
