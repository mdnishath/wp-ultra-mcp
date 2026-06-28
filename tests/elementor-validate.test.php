<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/elementor/validate.php';

function ev_tree(): array {
    return [[
        'id' => 'row0001', 'elType' => 'e-flexbox', 'settings' => ['gap' => 'BAD'], 'elements' => [
            ['id' => 'head001', 'elType' => 'widget', 'widgetType' => 'e-heading', 'settings' => ['tag' => 'h2'], 'elements' => []],
            ['id' => 'btn0001', 'elType' => 'widget', 'widgetType' => 'e-button', 'settings' => [], 'elements' => []],
        ],
    ]];
}

// Stub validator: marks any node whose settings contain a 'BAD' value invalid; otherwise normalizes
// settings by uppercasing the widgetType into a marker so we can prove normalized_tree is used.
function ev_stub_validator(array $node): array {
    $settings = is_array($node['settings'] ?? null) ? $node['settings'] : [];
    $bad = in_array('BAD', array_values($settings), true);
    return [
        'valid'    => !$bad,
        'errors'   => $bad ? ["invalid setting in {$node['id']}"] : [],
        'settings' => $bad ? $settings : array_merge($settings, ['_n' => 1]),
    ];
}

it('validate_tree aggregates a per-node report with summary', function () {
    $r = wpultra_el_validate_tree(ev_tree(), 'ev_stub_validator');
    assert_eq(3, $r['summary']['total']);
    assert_eq(1, $r['summary']['invalid']);
    assert_eq(false, $r['ok']);
    // node order is depth-first: row, head, btn
    assert_eq('row0001', $r['nodes'][0]['id']);
    assert_eq(false, $r['nodes'][0]['valid']);
    assert_eq('e-heading', $r['nodes'][1]['widgetType']);
    assert_eq(true, $r['nodes'][1]['valid']);
});

it('validate_tree returns ok=true when all nodes pass, and normalizes settings', function () {
    $clean = [['id' => 'a', 'elType' => 'widget', 'widgetType' => 'e-button', 'settings' => ['x' => 'y'], 'elements' => []]];
    $r = wpultra_el_validate_tree($clean, 'ev_stub_validator');
    assert_eq(true, $r['ok']);
    assert_eq(0, $r['summary']['invalid']);
    assert_eq(1, $r['normalized_tree'][0]['settings']['_n']); // validator-normalized settings used
});

it('collect_ids gathers every id depth-first', function () {
    assert_eq(['row0001', 'head001', 'btn0001'], wpultra_el_collect_ids(ev_tree()));
});

it('render_digest reports present and dropped ids from data-id markers', function () {
    $html = '<div class="elementor-element" data-id="row0001"><h2 data-id="head001">Hi</h2></div>';
    $d = wpultra_el_render_digest($html, ['row0001', 'head001', 'btn0001']);
    assert_eq(2, $d['rendered_count']);
    assert_eq(['btn0001'], $d['dropped_ids']);
    assert_eq(['row0001', 'head001'], $d['present_ids']);
});

it('validate_tree depth guard fails closed on very deep trees without fatal', function () {
    $node = ['id' => 'leaf', 'elType' => 'widget', 'widgetType' => 'e-button', 'settings' => [], 'elements' => []];
    for ($i = 0; $i < 130; $i++) {
        $node = ['id' => "n$i", 'elType' => 'e-flexbox', 'settings' => [], 'elements' => [$node]];
    }
    $r = wpultra_el_validate_tree([$node], 'ev_stub_validator');
    assert_eq(false, $r['ok']);                                    // truncation makes the whole tree fail closed
    assert_true($r['summary']['invalid'] >= 1, 'truncation marker counted as invalid');
    assert_true(count(wpultra_el_collect_ids([$node])) > 0, 'collect_ids does not fatal on deep trees');
});

run_tests();
