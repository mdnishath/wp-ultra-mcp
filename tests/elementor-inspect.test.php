<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/elementor/inspect.php';

/* ------------------------------------------------------------------ *
 * wpultra_elinspect_flatten_props
 * ------------------------------------------------------------------ */

it('flatten: hardcoded scalar prop stays a single leaf', function () {
    $props = ['opacity' => ['$$type' => 'number', 'value' => 0.8]];
    $flat = wpultra_elinspect_flatten_props($props);
    assert_eq(1, count($flat));
    assert_eq('opacity', $flat[0]['prop']);
    assert_eq(0.8, $flat[0]['value']);
    assert_true(!$flat[0]['is_token'], 'scalar prop must not be a token');
    assert_eq(null, $flat[0]['token_id']);
});

it('flatten: global-color-variable ref is a token with token_id = e-gv id', function () {
    $props = ['color' => ['$$type' => 'global-color-variable', 'value' => 'e-gv-abc1234']];
    $flat = wpultra_elinspect_flatten_props($props);
    assert_eq(1, count($flat));
    assert_true($flat[0]['is_token'], 'expected token classification');
    assert_eq('e-gv-abc1234', $flat[0]['token_id']);
    assert_eq('e-gv-abc1234', $flat[0]['value']);
});

it('flatten: global-font-variable and global-size-variable are also tokens', function () {
    $props = [
        'font' => ['$$type' => 'global-font-variable', 'value' => 'e-gv-font1'],
        'size' => ['$$type' => 'global-size-variable', 'value' => 'e-gv-size1'],
    ];
    $flat = wpultra_elinspect_flatten_props($props);
    assert_eq(2, count($flat));
    foreach ($flat as $f) { assert_true($f['is_token'], "expected token for {$f['prop']}"); }
});

it('flatten: heading classes prop is passed through as a plain (non-token) list leaf', function () {
    $props = ['classes' => ['$$type' => 'classes', 'value' => ['e-gc-abc123', 'e-gc-def456']]];
    $flat = wpultra_elinspect_flatten_props($props);
    assert_eq(1, count($flat));
    assert_eq('classes', $flat[0]['prop']);
    assert_eq(['e-gc-abc123', 'e-gc-def456'], $flat[0]['value']);
    assert_true(!$flat[0]['is_token'], 'classes prop is a list, not a token ref');
});

it('flatten: background compound recurses into dotted paths', function () {
    $props = [
        'background' => ['$$type' => 'background', 'value' => [
            'color' => ['$$type' => 'color', 'value' => '#ffffff'],
            'color_b' => ['$$type' => 'global-color-variable', 'value' => 'e-gv-bg1'],
        ]],
    ];
    $flat = wpultra_elinspect_flatten_props($props);
    $byProp = [];
    foreach ($flat as $f) { $byProp[$f['prop']] = $f; }
    assert_eq(2, count($flat));
    assert_true(isset($byProp['background.color']), 'expected dotted path background.color');
    assert_eq('#ffffff', $byProp['background.color']['value']);
    assert_true(!$byProp['background.color']['is_token']);
    assert_true(isset($byProp['background.color_b']), 'expected dotted path background.color_b');
    assert_true($byProp['background.color_b']['is_token'], 'expected token classification for nested global ref');
    assert_eq('e-gv-bg1', $byProp['background.color_b']['token_id']);
});

it('flatten: dimensions compound (padding) recurses per side and stops at the size leaf', function () {
    $props = [
        'padding' => ['$$type' => 'dimensions', 'value' => [
            'top'    => ['$$type' => 'size', 'value' => ['size' => 10, 'unit' => 'px']],
            'right'  => ['$$type' => 'size', 'value' => ['size' => 5, 'unit' => 'px']],
            'bottom' => ['$$type' => 'size', 'value' => ['size' => 10, 'unit' => 'px']],
            'left'   => ['$$type' => 'size', 'value' => ['size' => 5, 'unit' => 'px']],
        ]],
    ];
    $flat = wpultra_elinspect_flatten_props($props);
    $byProp = [];
    foreach ($flat as $f) { $byProp[$f['prop']] = $f; }
    assert_eq(4, count($flat));
    assert_true(isset($byProp['padding.top']), 'expected dotted path padding.top');
    assert_eq(['size' => 10, 'unit' => 'px'], $byProp['padding.top']['value']);
    assert_true(!$byProp['padding.top']['is_token']);
    assert_true(isset($byProp['padding.left']));
    assert_eq(['size' => 5, 'unit' => 'px'], $byProp['padding.left']['value']);
});

it('flatten: deeply nested compounds keep accumulating the dotted path', function () {
    $props = [
        'background' => ['$$type' => 'background', 'value' => [
            'overlay' => ['$$type' => 'background-overlay', 'value' => [
                'color' => ['$$type' => 'color', 'value' => '#000000'],
            ]],
        ]],
    ];
    $flat = wpultra_elinspect_flatten_props($props);
    assert_eq(1, count($flat));
    assert_eq('background.overlay.color', $flat[0]['prop']);
    assert_eq('#000000', $flat[0]['value']);
});

it('flatten: malformed (non-array) node falls through as a hardcoded raw leaf', function () {
    $props = ['weird' => 'just-a-string'];
    $flat = wpultra_elinspect_flatten_props($props);
    assert_eq(1, count($flat));
    assert_eq('weird', $flat[0]['prop']);
    assert_eq('just-a-string', $flat[0]['value']);
    assert_true(!$flat[0]['is_token']);
});

it('flatten: empty props map yields an empty list', function () {
    assert_eq([], wpultra_elinspect_flatten_props([]));
});

/* ------------------------------------------------------------------ *
 * wpultra_elinspect_resolve_token
 * ------------------------------------------------------------------ */

it('resolve_token: hit returns the concrete value', function () {
    $idx = ['e-gv-abc1234' => '#112233'];
    assert_eq('#112233', wpultra_elinspect_resolve_token('e-gv-abc1234', $idx));
});

it('resolve_token: miss returns null', function () {
    $idx = ['e-gv-abc1234' => '#112233'];
    assert_eq(null, wpultra_elinspect_resolve_token('e-gv-nope', $idx));
});

it('resolve_token: empty token id returns null even against a non-empty index', function () {
    $idx = ['' => 'should-not-match'];
    assert_eq(null, wpultra_elinspect_resolve_token('', $idx));
});

it('resolve_token: empty index always misses', function () {
    assert_eq(null, wpultra_elinspect_resolve_token('e-gv-x', []));
});

it('resolve_token: non-scalar index value resolves to null', function () {
    $idx = ['e-gv-x' => ['nested' => 'array']];
    assert_eq(null, wpultra_elinspect_resolve_token('e-gv-x', $idx));
});

it('resolve_token: numeric value is stringified', function () {
    $idx = ['e-gv-size1' => 16];
    assert_eq('16', wpultra_elinspect_resolve_token('e-gv-size1', $idx));
});

/* ------------------------------------------------------------------ *
 * wpultra_elinspect_count
 * ------------------------------------------------------------------ */

it('count: mixed flattened list tallies hardcoded vs token', function () {
    $flat = [
        ['prop' => 'a', 'value' => '#fff', 'is_token' => false, 'token_id' => null],
        ['prop' => 'b', 'value' => 'e-gv-1', 'is_token' => true, 'token_id' => 'e-gv-1'],
        ['prop' => 'c', 'value' => 'e-gv-2', 'is_token' => true, 'token_id' => 'e-gv-2'],
        ['prop' => 'd', 'value' => 10, 'is_token' => false, 'token_id' => null],
    ];
    assert_eq(['hardcoded_count' => 2, 'token_count' => 2], wpultra_elinspect_count($flat));
});

it('count: empty list yields zero/zero', function () {
    assert_eq(['hardcoded_count' => 0, 'token_count' => 0], wpultra_elinspect_count([]));
});

it('count: all-token list', function () {
    $flat = [
        ['prop' => 'a', 'value' => 'e-gv-1', 'is_token' => true, 'token_id' => 'e-gv-1'],
        ['prop' => 'b', 'value' => 'e-gv-2', 'is_token' => true, 'token_id' => 'e-gv-2'],
    ];
    assert_eq(['hardcoded_count' => 0, 'token_count' => 2], wpultra_elinspect_count($flat));
});

it('count: flatten -> count end-to-end on a mixed props map', function () {
    $props = [
        'opacity' => ['$$type' => 'number', 'value' => 0.8],
        'color'   => ['$$type' => 'global-color-variable', 'value' => 'e-gv-x'],
        'padding' => ['$$type' => 'dimensions', 'value' => [
            'top' => ['$$type' => 'size', 'value' => ['size' => 10, 'unit' => 'px']],
            'left' => ['$$type' => 'global-size-variable', 'value' => 'e-gv-y'],
        ]],
    ];
    $flat = wpultra_elinspect_flatten_props($props);
    // opacity (hardcoded), color (token), padding.top (hardcoded), padding.left (token)
    assert_eq(4, count($flat));
    assert_eq(['hardcoded_count' => 2, 'token_count' => 2], wpultra_elinspect_count($flat));
});

/* ------------------------------------------------------------------ *
 * wpultra_elinspect_props_to_output — empty-map object-cast serialization
 *
 * wpultra_elinspect_run() casts this function's output to (object) at both call sites
 * (own_settings, and each class variant's props) so an element/variant with zero props
 * serializes as JSON `{}` per the `type: object` schema, never `[]`. props_to_output()
 * itself is pure (no WP calls), so the cast pattern is exercised directly here.
 * ------------------------------------------------------------------ */

it('props_to_output: empty flattened list stays a plain empty array on its own', function () {
    $out = wpultra_elinspect_props_to_output([], 'settings', [], false);
    assert_eq([], $out);
});

it('props_to_output: empty flattened list, cast to object (as own_settings/variant props are), json-encodes as {} not []', function () {
    $out = wpultra_elinspect_props_to_output([], 'settings', [], false);
    $encoded = json_encode((object) $out);
    assert_eq('{}', $encoded, 'empty own_settings/variant props must encode as {} once cast to object');
});

it('props_to_output: same empty-map cast applied to an element with no own settings at all (flatten -> output -> object)', function () {
    $flat = wpultra_elinspect_flatten_props([]); // element/variant with zero own props
    $out = wpultra_elinspect_props_to_output($flat, 'settings', [], false);
    assert_eq('{}', json_encode((object) $out));
});

it('props_to_output: non-empty flattened list still serializes as a JSON object (not array) with string keys after the (object) cast', function () {
    $flat = [
        ['prop' => 'opacity', 'value' => 0.8, 'is_token' => false, 'token_id' => null],
        ['prop' => 'color', 'value' => 'e-gv-x', 'is_token' => true, 'token_id' => 'e-gv-x'],
    ];
    $out = wpultra_elinspect_props_to_output($flat, 'settings', ['e-gv-x' => '#112233'], true);
    $encoded = json_encode((object) $out);
    assert_true(str_starts_with($encoded, '{') && !str_starts_with($encoded, '[]'), 'non-empty map must encode as a JSON object');
    assert_contains('"opacity":{"value":0.8,"source":"settings","is_token":false}', $encoded);
    assert_contains('"color":{"value":"e-gv-x","source":"settings","is_token":true,"token_id":"e-gv-x","resolved":"#112233"}', $encoded);
});

run_tests();
