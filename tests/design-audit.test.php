<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/elementor/audit.php';

/* ------------------------------------------------------------------ *
 * wpultra_audit_hex_to_rgb
 * ------------------------------------------------------------------ */

it('hex_to_rgb: 6-digit hex with #', function () {
    assert_eq(['r' => 255, 'g' => 0, 'b' => 0], wpultra_audit_hex_to_rgb('#ff0000'));
});

it('hex_to_rgb: 6-digit hex without #', function () {
    assert_eq(['r' => 0, 'g' => 255, 'b' => 0], wpultra_audit_hex_to_rgb('00ff00'));
});

it('hex_to_rgb: 3-digit shorthand expands each nibble', function () {
    assert_eq(['r' => 255, 'g' => 255, 'b' => 255], wpultra_audit_hex_to_rgb('#fff'));
});

it('hex_to_rgb: 3-digit shorthand without # and mixed case', function () {
    assert_eq(['r' => 170, 'g' => 187, 'b' => 204], wpultra_audit_hex_to_rgb('AbC'));
});

it('hex_to_rgb: black', function () {
    assert_eq(['r' => 0, 'g' => 0, 'b' => 0], wpultra_audit_hex_to_rgb('#000000'));
});

it('hex_to_rgb: invalid length (5 chars) is null', function () {
    assert_eq(null, wpultra_audit_hex_to_rgb('#abcde'));
});

it('hex_to_rgb: invalid length (8 chars, e.g. #rrggbbaa) is null', function () {
    assert_eq(null, wpultra_audit_hex_to_rgb('#ff0000ff'));
});

it('hex_to_rgb: non-hex characters is null', function () {
    assert_eq(null, wpultra_audit_hex_to_rgb('#zzzzzz'));
});

it('hex_to_rgb: empty string is null', function () {
    assert_eq(null, wpultra_audit_hex_to_rgb(''));
});

it('hex_to_rgb: named CSS color (not hex) is null', function () {
    assert_eq(null, wpultra_audit_hex_to_rgb('red'));
});

it('hex_to_rgb: rgba() string is null', function () {
    assert_eq(null, wpultra_audit_hex_to_rgb('rgba(0,0,0,0.5)'));
});

it('hex_to_rgb: trims surrounding whitespace', function () {
    assert_eq(['r' => 0, 'g' => 0, 'b' => 0], wpultra_audit_hex_to_rgb('  #000  '));
});

/* ------------------------------------------------------------------ *
 * wpultra_audit_relative_luminance
 * ------------------------------------------------------------------ */

it('relative_luminance: black is 0', function () {
    assert_eq(0.0, round(wpultra_audit_relative_luminance(['r' => 0, 'g' => 0, 'b' => 0]), 6));
});

it('relative_luminance: white is 1', function () {
    assert_eq(1.0, round(wpultra_audit_relative_luminance(['r' => 255, 'g' => 255, 'b' => 255]), 6));
});

it('relative_luminance: mid gray #808080 is approximately 0.2159', function () {
    $l = wpultra_audit_relative_luminance(['r' => 128, 'g' => 128, 'b' => 128]);
    assert_true(abs($l - 0.21586) < 0.001, "expected ~0.21586, got $l");
});

it('relative_luminance: missing channel keys default to 0', function () {
    assert_eq(0.0, round(wpultra_audit_relative_luminance([]), 6));
});

/* ------------------------------------------------------------------ *
 * wpultra_audit_contrast_ratio
 * ------------------------------------------------------------------ */

it('contrast_ratio: black vs white is exactly 21', function () {
    $ratio = wpultra_audit_contrast_ratio(['r' => 0, 'g' => 0, 'b' => 0], ['r' => 255, 'g' => 255, 'b' => 255]);
    assert_eq(21.0, round($ratio, 4));
});

it('contrast_ratio: is symmetric regardless of fg/bg order', function () {
    $a = wpultra_audit_contrast_ratio(['r' => 0, 'g' => 0, 'b' => 0], ['r' => 255, 'g' => 255, 'b' => 255]);
    $b = wpultra_audit_contrast_ratio(['r' => 255, 'g' => 255, 'b' => 255], ['r' => 0, 'g' => 0, 'b' => 0]);
    assert_eq(round($a, 6), round($b, 6));
});

it('contrast_ratio: identical colors is exactly 1', function () {
    $ratio = wpultra_audit_contrast_ratio(['r' => 100, 'g' => 100, 'b' => 100], ['r' => 100, 'g' => 100, 'b' => 100]);
    assert_eq(1.0, round($ratio, 6));
});

it('contrast_ratio: white text on light gray fails AA (below 4.5)', function () {
    $ratio = wpultra_audit_contrast_ratio(['r' => 255, 'g' => 255, 'b' => 255], ['r' => 200, 'g' => 200, 'b' => 200]);
    assert_true($ratio < 4.5, "expected < 4.5, got $ratio");
});

it('contrast_ratio: black text on white passes AA (above 4.5)', function () {
    $ratio = wpultra_audit_contrast_ratio(['r' => 0, 'g' => 0, 'b' => 0], ['r' => 255, 'g' => 255, 'b' => 255]);
    assert_true($ratio >= 4.5, "expected >= 4.5, got $ratio");
});

/* ------------------------------------------------------------------ *
 * wpultra_audit_hex_from_value
 * ------------------------------------------------------------------ */

it('hex_from_value: normalizes to uppercase 6-digit hex', function () {
    assert_eq('#FF0000', wpultra_audit_hex_from_value('#f00'));
});

it('hex_from_value: non-string value is null', function () {
    assert_eq(null, wpultra_audit_hex_from_value(['color' => '#fff']));
});

it('hex_from_value: unresolvable color string is null', function () {
    assert_eq(null, wpultra_audit_hex_from_value('rgba(0,0,0,.5)'));
});

/* ------------------------------------------------------------------ *
 * wpultra_audit_category
 * ------------------------------------------------------------------ */

it('category: prop containing "color" is color', function () {
    assert_eq('color', wpultra_audit_category('background.color'));
});

it('category: "title_color" is color, not typography, despite no font/typography substring', function () {
    assert_eq('color', wpultra_audit_category('title_color'));
});

it('category: prop containing "font" is typography', function () {
    assert_eq('typography', wpultra_audit_category('typography.font_family'));
});

it('category: margin/padding/gap are spacing', function () {
    assert_eq('spacing', wpultra_audit_category('margin.top'));
    assert_eq('spacing', wpultra_audit_category('padding'));
    assert_eq('spacing', wpultra_audit_category('gap'));
});

it('category: unrelated prop is other', function () {
    assert_eq('other', wpultra_audit_category('align'));
});

/* ------------------------------------------------------------------ *
 * wpultra_audit_tally
 * ------------------------------------------------------------------ */

it('tally: zero elements produces zeroed overall + all category buckets', function () {
    $t = wpultra_audit_tally([]);
    assert_eq(['token' => 0, 'hardcoded' => 0], $t['overall']);
    assert_eq(['token' => 0, 'hardcoded' => 0], $t['color']);
    assert_eq(['token' => 0, 'hardcoded' => 0], $t['typography']);
    assert_eq(['token' => 0, 'hardcoded' => 0], $t['spacing']);
    assert_eq(['token' => 0, 'hardcoded' => 0], $t['other']);
});

it('tally: mixed token/hardcoded across categories is counted correctly', function () {
    $flat = [
        ['prop' => 'color', 'value' => '#fff', 'is_token' => false],
        ['prop' => 'background.color', 'value' => 'e-gv-1', 'is_token' => true],
        ['prop' => 'typography.font_family', 'value' => 'Arial', 'is_token' => false],
        ['prop' => 'margin.top', 'value' => 10, 'is_token' => false],
        ['prop' => 'margin.left', 'value' => 'e-gv-2', 'is_token' => true],
        ['prop' => 'align', 'value' => 'center', 'is_token' => false],
    ];
    $t = wpultra_audit_tally($flat);
    assert_eq(['token' => 2, 'hardcoded' => 4], $t['overall']);
    assert_eq(['token' => 1, 'hardcoded' => 1], $t['color']);
    assert_eq(['token' => 0, 'hardcoded' => 1], $t['typography']);
    assert_eq(['token' => 1, 'hardcoded' => 1], $t['spacing']);
    assert_eq(['token' => 0, 'hardcoded' => 1], $t['other']);
});

it('tally: non-array item is skipped without fatal', function () {
    $t = wpultra_audit_tally(['not-an-array', ['prop' => 'color', 'value' => '#000', 'is_token' => false]]);
    assert_eq(['token' => 0, 'hardcoded' => 1], $t['overall']);
});

/* ------------------------------------------------------------------ *
 * wpultra_audit_off_scale (+ implicitly wpultra_audit_parse_px)
 * ------------------------------------------------------------------ */

it('off_scale: on-scale bare-number value is not reported', function () {
    $out = wpultra_audit_off_scale([['element_id' => 'a', 'prop' => 'margin.top', 'value' => 16]], [0, 4, 8, 12, 16, 24]);
    assert_eq([], $out);
});

it('off_scale: off-scale numeric value is reported with resolved px number', function () {
    $out = wpultra_audit_off_scale([['element_id' => 'a', 'prop' => 'margin.top', 'value' => 17]], [0, 4, 8, 12, 16, 24]);
    assert_eq([['element_id' => 'a', 'prop' => 'margin.top', 'value' => 17.0]], $out);
});

it('off_scale: explicit "16px" string unit-suffixed value on scale is not reported', function () {
    $out = wpultra_audit_off_scale([['element_id' => 'a', 'prop' => 'padding.top', 'value' => '16px']], [0, 4, 8, 12, 16, 24]);
    assert_eq([], $out);
});

it('off_scale: explicit unit field "px" combined with numeric value works like bare number', function () {
    $out = wpultra_audit_off_scale([['element_id' => 'a', 'prop' => 'padding.top', 'value' => 17, 'unit' => 'px']], [0, 4, 8, 12, 16, 24]);
    assert_eq([['element_id' => 'a', 'prop' => 'padding.top', 'value' => 17.0]], $out);
});

it('off_scale: percent unit is ignored (not reported off-scale)', function () {
    $out = wpultra_audit_off_scale([['element_id' => 'a', 'prop' => 'margin.top', 'value' => 17, 'unit' => '%']], [0, 4, 8, 12, 16, 24]);
    assert_eq([], $out);
});

it('off_scale: "auto" value is ignored', function () {
    $out = wpultra_audit_off_scale([['element_id' => 'a', 'prop' => 'margin.top', 'value' => 'auto']], [0, 4, 8, 12, 16, 24]);
    assert_eq([], $out);
});

it('off_scale: em unit is ignored even though the numeric part might coincidentally match the scale', function () {
    $out = wpultra_audit_off_scale([['element_id' => 'a', 'prop' => 'margin.top', 'value' => 2, 'unit' => 'em']], [0, 4, 8, 12, 16, 24]);
    assert_eq([], $out);
});

it('off_scale: negative off-scale value is reported', function () {
    $out = wpultra_audit_off_scale([['element_id' => 'a', 'prop' => 'margin.top', 'value' => -5]], [0, 4, 8, 12, 16, 24]);
    assert_eq([['element_id' => 'a', 'prop' => 'margin.top', 'value' => -5.0]], $out);
});

it('off_scale: multiple entries, mixed on/off scale + ignored units', function () {
    $items = [
        ['element_id' => 'a', 'prop' => 'margin.top', 'value' => 16],     // on scale
        ['element_id' => 'a', 'prop' => 'margin.right', 'value' => 20],   // off scale
        ['element_id' => 'b', 'prop' => 'padding', 'value' => '50%'],     // ignored
        ['element_id' => 'b', 'prop' => 'gap', 'value' => 5],             // off scale
    ];
    $out = wpultra_audit_off_scale($items, [0, 4, 8, 12, 16, 24, 32, 48, 64, 96]);
    assert_eq(2, count($out));
    assert_eq('margin.right', $out[0]['prop']);
    assert_eq(20.0, $out[0]['value']);
    assert_eq('gap', $out[1]['prop']);
    assert_eq(5.0, $out[1]['value']);
});

it('off_scale: non-array item in the list is skipped', function () {
    $out = wpultra_audit_off_scale(['not-an-array'], [0, 4]);
    assert_eq([], $out);
});

it('off_scale: empty scale means every resolvable value is off-scale', function () {
    $out = wpultra_audit_off_scale([['element_id' => 'a', 'prop' => 'gap', 'value' => 0]], []);
    assert_eq([['element_id' => 'a', 'prop' => 'gap', 'value' => 0.0]], $out);
});

/* ------------------------------------------------------------------ *
 * wpultra_audit_normalize_scale
 * ------------------------------------------------------------------ */

it('normalize_scale: filters non-numeric entries and casts to float', function () {
    assert_eq([4.0, 8.0], wpultra_audit_normalize_scale([4, 'x', 8, null]));
});

it('normalize_scale: empty input falls back to the documented default scale', function () {
    assert_eq([0.0, 4.0, 8.0, 12.0, 16.0, 24.0, 32.0, 48.0, 64.0, 96.0], wpultra_audit_normalize_scale([]));
});

it('normalize_scale: all-non-numeric input falls back to the default scale', function () {
    assert_eq([0.0, 4.0, 8.0, 12.0, 16.0, 24.0, 32.0, 48.0, 64.0, 96.0], wpultra_audit_normalize_scale(['a', 'b']));
});

/* ------------------------------------------------------------------ *
 * wpultra_audit_flatten_props
 * ------------------------------------------------------------------ */

it('flatten_props: atomic token prop is flagged is_token with token_id', function () {
    $out = wpultra_audit_flatten_props(['color' => ['$$type' => 'global-color-variable', 'value' => 'e-gv-1']]);
    assert_eq([['prop' => 'color', 'value' => 'e-gv-1', 'unit' => null, 'is_token' => true, 'token_id' => 'e-gv-1']], $out);
});

it('flatten_props: atomic hardcoded scalar prop', function () {
    $out = wpultra_audit_flatten_props(['color' => ['$$type' => 'color', 'value' => '#ff0000']]);
    assert_eq([['prop' => 'color', 'value' => '#ff0000', 'unit' => null, 'is_token' => false, 'token_id' => null]], $out);
});

it('flatten_props: atomic Size_Prop_Type {size,unit} splits into value+unit', function () {
    $out = wpultra_audit_flatten_props(['padding' => ['$$type' => 'size', 'value' => ['size' => 16, 'unit' => 'px']]]);
    assert_eq([['prop' => 'padding', 'value' => 16, 'unit' => 'px', 'is_token' => false, 'token_id' => null]], $out);
});

it('flatten_props: nested atomic compound (every entry a $$type node) recurses with dotted path', function () {
    $props = [
        'background' => [
            '$$type' => 'background',
            'value'  => [
                'color' => ['$$type' => 'color', 'value' => '#000000'],
            ],
        ],
    ];
    $out = wpultra_audit_flatten_props($props);
    assert_eq([['prop' => 'background.color', 'value' => '#000000', 'unit' => null, 'is_token' => false, 'token_id' => null]], $out);
});

it('flatten_props: classic dimensions control emits one leaf per populated side with shared unit', function () {
    $props = ['margin' => ['unit' => 'px', 'top' => '10', 'right' => '20', 'bottom' => '', 'left' => '20', 'isLinked' => false]];
    $out = wpultra_audit_flatten_props($props);
    $byProp = [];
    foreach ($out as $leaf) { $byProp[$leaf['prop']] = $leaf; }
    assert_eq(['margin.top', 'margin.right', 'margin.left'], array_keys($byProp));
    assert_eq('10', $byProp['margin.top']['value']);
    assert_eq('px', $byProp['margin.top']['unit']);
});

it('flatten_props: classic slider control {unit,size} emits a single leaf', function () {
    $out = wpultra_audit_flatten_props(['gap' => ['unit' => 'px', 'size' => 24]]);
    assert_eq([['prop' => 'gap.size', 'value' => 24, 'unit' => 'px', 'is_token' => false, 'token_id' => null]], $out);
});

it('flatten_props: plain scalar prop is a hardcoded leaf', function () {
    $out = wpultra_audit_flatten_props(['align' => 'center']);
    assert_eq([['prop' => 'align', 'value' => 'center', 'unit' => null, 'is_token' => false, 'token_id' => null]], $out);
});

it('flatten_props: sequential list value (e.g. classes) is kept as a single hardcoded leaf', function () {
    $out = wpultra_audit_flatten_props(['classes' => ['e-gc-1', 'e-gc-2']]);
    assert_eq([['prop' => 'classes', 'value' => ['e-gc-1', 'e-gc-2'], 'unit' => null, 'is_token' => false, 'token_id' => null]], $out);
});

it('flatten_props: empty array value is a single hardcoded leaf, not a fatal', function () {
    $out = wpultra_audit_flatten_props(['classes' => []]);
    assert_eq([['prop' => 'classes', 'value' => [], 'unit' => null, 'is_token' => false, 'token_id' => null]], $out);
});

it('flatten_props: generic associative sub-map without a recognized shape recurses', function () {
    $out = wpultra_audit_flatten_props(['typography_group' => ['font_family' => 'Georgia', 'font_size' => '18']]);
    assert_eq([
        ['prop' => 'typography_group.font_family', 'value' => 'Georgia', 'unit' => null, 'is_token' => false, 'token_id' => null],
        ['prop' => 'typography_group.font_size', 'value' => '18', 'unit' => null, 'is_token' => false, 'token_id' => null],
    ], $out);
});

it('flatten_props: malformed atomic node (no "value" key) falls through as a null-valued hardcoded leaf without fataling', function () {
    $out = wpultra_audit_flatten_props(['weird' => ['$$type' => 'color']]);
    assert_eq([['prop' => 'weird', 'value' => null, 'unit' => null, 'is_token' => false, 'token_id' => null]], $out);
});

it('flatten_props: empty props map returns empty list', function () {
    assert_eq([], wpultra_audit_flatten_props([]));
});

/* ------------------------------------------------------------------ *
 * wpultra_audit_walk_all
 * ------------------------------------------------------------------ */

it('walk_all: visits every node including nested children', function () {
    $tree = [
        ['id' => 'a', 'elements' => [
            ['id' => 'b', 'elements' => []],
            ['id' => 'c'],
        ]],
        ['id' => 'd'],
    ];
    $seen = [];
    wpultra_audit_walk_all($tree, function (array $n) use (&$seen) { $seen[] = $n['id']; });
    assert_eq(['a', 'b', 'c', 'd'], $seen);
});

it('walk_all: non-array entries in the list are skipped', function () {
    $seen = [];
    wpultra_audit_walk_all(['not-an-array', ['id' => 'x']], function (array $n) use (&$seen) { $seen[] = $n['id']; });
    assert_eq(['x'], $seen);
});

it('walk_all: depth guard stops beyond 100 levels without infinite recursion', function () {
    // Build a 105-deep linear chain; only the first 101 levels (depth 0..100) should be visited.
    $node = ['id' => 'leaf'];
    for ($i = 0; $i < 105; $i++) { $node = ['id' => "n$i", 'elements' => [$node]]; }
    $seen = [];
    wpultra_audit_walk_all([$node], function (array $n) use (&$seen) { $seen[] = $n['id']; });
    assert_true(count($seen) < 105, 'expected the depth guard to cut the walk short');
});

/* ------------------------------------------------------------------ *
 * wpultra_audit_recommendations
 * ------------------------------------------------------------------ */

it('recommendations: no issues produces the single "no issues" message', function () {
    assert_eq(['No major consistency issues detected.'], wpultra_audit_recommendations(10.0, 2, [], []));
});

it('recommendations: high hardcoded pct produces a tokenization callout', function () {
    $r = wpultra_audit_recommendations(75.0, 2, [], []);
    assert_eq(1, count($r));
    assert_contains('75', $r[0]);
});

it('recommendations: many distinct colors produces a palette callout', function () {
    $r = wpultra_audit_recommendations(10.0, 8, [], []);
    assert_contains('8 distinct hardcoded colors', $r[0]);
});

it('recommendations: off-scale spacing produces a snap-to-scale callout', function () {
    $r = wpultra_audit_recommendations(10.0, 2, [['element_id' => 'a', 'prop' => 'gap', 'value' => 5.0]], []);
    assert_contains('spacing value(s) fall off', $r[0]);
});

it('recommendations: contrast warnings produce a contrast callout', function () {
    $r = wpultra_audit_recommendations(10.0, 2, [], [['element_id' => 'a', 'fg' => '#fff', 'bg' => '#eee', 'ratio' => 1.1]]);
    assert_contains('WCAG AA contrast', $r[0]);
});

it('recommendations: all four conditions produce four distinct callouts', function () {
    $r = wpultra_audit_recommendations(90.0, 9, [['element_id' => 'a', 'prop' => 'gap', 'value' => 5.0]], [['element_id' => 'a', 'fg' => '#fff', 'bg' => '#eee', 'ratio' => 1.1]]);
    assert_eq(4, count($r));
});

/* ------------------------------------------------------------------ *
 * wpultra_audit_build_report — end-to-end pure aggregation
 * ------------------------------------------------------------------ */

it('build_report: zero elements/entries yields zeroed summary and empty lists', function () {
    $r = wpultra_audit_build_report([], 0, [0, 4, 8]);
    assert_eq(0, $r['summary']['elements']);
    assert_eq(0.0, $r['summary']['token_pct']);
    assert_eq(0.0, $r['summary']['hardcoded_pct']);
    assert_eq(0, $r['summary']['distinct_colors']);
    assert_eq(0, $r['summary']['distinct_fonts']);
    assert_eq(0, $r['summary']['distinct_sizes']);
    assert_eq([], $r['off_scale_spacing']);
    assert_eq([], $r['contrast_warnings']);
    assert_eq(['No major consistency issues detected.'], $r['recommendations']);
});

it('build_report: token_pct/hardcoded_pct sum to ~100 and reflect the token/hardcoded split', function () {
    $entries = [
        ['element_id' => 'a', 'prop' => 'color', 'value' => '#000', 'unit' => null, 'is_token' => false, 'token_id' => null],
        ['element_id' => 'a', 'prop' => 'background.color', 'value' => 'e-gv-1', 'unit' => null, 'is_token' => true, 'token_id' => 'e-gv-1'],
        ['element_id' => 'a', 'prop' => 'background.color', 'value' => 'e-gv-2', 'unit' => null, 'is_token' => true, 'token_id' => 'e-gv-2'],
        ['element_id' => 'a', 'prop' => 'background.color', 'value' => 'e-gv-3', 'unit' => null, 'is_token' => true, 'token_id' => 'e-gv-3'],
    ];
    $r = wpultra_audit_build_report($entries, 1, [0, 4, 8]);
    assert_eq(25.0, $r['summary']['hardcoded_pct']);
    assert_eq(75.0, $r['summary']['token_pct']);
});

it('build_report: dedupes distinct hardcoded colors by normalized hex', function () {
    $entries = [
        ['element_id' => 'a', 'prop' => 'color', 'value' => '#FF0000', 'unit' => null, 'is_token' => false, 'token_id' => null],
        ['element_id' => 'b', 'prop' => 'color', 'value' => '#f00', 'unit' => null, 'is_token' => false, 'token_id' => null], // same color, different shorthand
        ['element_id' => 'c', 'prop' => 'background.color', 'value' => '#00ff00', 'unit' => null, 'is_token' => false, 'token_id' => null],
    ];
    $r = wpultra_audit_build_report($entries, 3, [0, 4, 8]);
    assert_eq(2, $r['summary']['distinct_colors']);
});

it('build_report: token colors are excluded from the distinct-color count', function () {
    $entries = [
        ['element_id' => 'a', 'prop' => 'color', 'value' => 'e-gv-1', 'unit' => null, 'is_token' => true, 'token_id' => 'e-gv-1'],
    ];
    $r = wpultra_audit_build_report($entries, 1, [0, 4, 8]);
    assert_eq(0, $r['summary']['distinct_colors']);
});

it('build_report: distinct fonts and sizes are counted separately from color/spacing', function () {
    $entries = [
        ['element_id' => 'a', 'prop' => 'typography.font_family', 'value' => 'Georgia', 'unit' => null, 'is_token' => false, 'token_id' => null],
        ['element_id' => 'b', 'prop' => 'typography.font_family', 'value' => 'Georgia', 'unit' => null, 'is_token' => false, 'token_id' => null], // dupe
        ['element_id' => 'c', 'prop' => 'typography.font_family', 'value' => 'Arial', 'unit' => null, 'is_token' => false, 'token_id' => null],
        ['element_id' => 'a', 'prop' => 'typography.font_size', 'value' => 16, 'unit' => 'px', 'is_token' => false, 'token_id' => null],
        ['element_id' => 'b', 'prop' => 'typography.font_size', 'value' => 18, 'unit' => 'px', 'is_token' => false, 'token_id' => null],
    ];
    $r = wpultra_audit_build_report($entries, 3, [0, 4, 8]);
    assert_eq(2, $r['summary']['distinct_fonts']);
    assert_eq(2, $r['summary']['distinct_sizes']);
});

it('build_report: off-scale spacing entries surface with element_id + prop + resolved px value', function () {
    $entries = [
        ['element_id' => 'x1', 'prop' => 'margin.top', 'value' => 17, 'unit' => null, 'is_token' => false, 'token_id' => null],
        ['element_id' => 'x1', 'prop' => 'margin.left', 'value' => 16, 'unit' => null, 'is_token' => false, 'token_id' => null], // on scale
    ];
    $r = wpultra_audit_build_report($entries, 1, [0, 4, 8, 12, 16, 24]);
    assert_eq([['element_id' => 'x1', 'prop' => 'margin.top', 'value' => 17.0]], $r['off_scale_spacing']);
});

it('build_report: token spacing is excluded from off-scale detection', function () {
    $entries = [
        ['element_id' => 'x1', 'prop' => 'margin.top', 'value' => 'e-gv-9', 'unit' => null, 'is_token' => true, 'token_id' => 'e-gv-9'],
    ];
    $r = wpultra_audit_build_report($entries, 1, [0, 4, 8]);
    assert_eq([], $r['off_scale_spacing']);
});

it('build_report: contrast warning fires for a hardcoded text+background pair below 4.5:1', function () {
    $entries = [
        ['element_id' => 'w1', 'prop' => 'color', 'value' => '#ffffff', 'unit' => null, 'is_token' => false, 'token_id' => null],
        ['element_id' => 'w1', 'prop' => 'background.color', 'value' => '#eeeeee', 'unit' => null, 'is_token' => false, 'token_id' => null],
    ];
    $r = wpultra_audit_build_report($entries, 1, [0, 4, 8]);
    assert_eq(1, count($r['contrast_warnings']));
    assert_eq('w1', $r['contrast_warnings'][0]['element_id']);
    assert_eq('#FFFFFF', $r['contrast_warnings'][0]['fg']);
    assert_eq('#EEEEEE', $r['contrast_warnings'][0]['bg']);
    assert_true($r['contrast_warnings'][0]['ratio'] < 4.5, 'expected a low ratio');
});

it('build_report: no contrast warning when fg/bg pass AA (black on white)', function () {
    $entries = [
        ['element_id' => 'w1', 'prop' => 'color', 'value' => '#000000', 'unit' => null, 'is_token' => false, 'token_id' => null],
        ['element_id' => 'w1', 'prop' => 'background.color', 'value' => '#ffffff', 'unit' => null, 'is_token' => false, 'token_id' => null],
    ];
    $r = wpultra_audit_build_report($entries, 1, [0, 4, 8]);
    assert_eq([], $r['contrast_warnings']);
});

it('build_report: element with only a text color (no background) produces no contrast warning', function () {
    $entries = [
        ['element_id' => 'w1', 'prop' => 'color', 'value' => '#ffffff', 'unit' => null, 'is_token' => false, 'token_id' => null],
    ];
    $r = wpultra_audit_build_report($entries, 1, [0, 4, 8]);
    assert_eq([], $r['contrast_warnings']);
});

it('build_report: token-driven colors do not participate in contrast checks even when a hardcoded partner exists', function () {
    $entries = [
        ['element_id' => 'w1', 'prop' => 'color', 'value' => 'e-gv-1', 'unit' => null, 'is_token' => true, 'token_id' => 'e-gv-1'],
        ['element_id' => 'w1', 'prop' => 'background.color', 'value' => '#eeeeee', 'unit' => null, 'is_token' => false, 'token_id' => null],
    ];
    $r = wpultra_audit_build_report($entries, 1, [0, 4, 8]);
    assert_eq([], $r['contrast_warnings']);
});

it('build_report: unresolvable color value (rgba string) does not fatal and is excluded from contrast + distinct-color counts', function () {
    $entries = [
        ['element_id' => 'w1', 'prop' => 'color', 'value' => 'rgba(0,0,0,.5)', 'unit' => null, 'is_token' => false, 'token_id' => null],
        ['element_id' => 'w1', 'prop' => 'background.color', 'value' => '#ffffff', 'unit' => null, 'is_token' => false, 'token_id' => null],
    ];
    $r = wpultra_audit_build_report($entries, 1, [0, 4, 8]);
    assert_eq(1, $r['summary']['distinct_colors']); // only the resolvable white counted
    assert_eq([], $r['contrast_warnings']); // no resolvable fg, so no pair
});

it('build_report: multiple elements each get their own independent contrast evaluation', function () {
    $entries = [
        ['element_id' => 'bad', 'prop' => 'color', 'value' => '#ffffff', 'unit' => null, 'is_token' => false, 'token_id' => null],
        ['element_id' => 'bad', 'prop' => 'background.color', 'value' => '#fefefe', 'unit' => null, 'is_token' => false, 'token_id' => null],
        ['element_id' => 'good', 'prop' => 'color', 'value' => '#000000', 'unit' => null, 'is_token' => false, 'token_id' => null],
        ['element_id' => 'good', 'prop' => 'background.color', 'value' => '#ffffff', 'unit' => null, 'is_token' => false, 'token_id' => null],
    ];
    $r = wpultra_audit_build_report($entries, 2, [0, 4, 8]);
    assert_eq(1, count($r['contrast_warnings']));
    assert_eq('bad', $r['contrast_warnings'][0]['element_id']);
});

run_tests();
