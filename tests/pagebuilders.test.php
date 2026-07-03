<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/builders/setup.php';
require __DIR__ . '/../wp-ultra-mcp/includes/builders/adapters/divi.php';
require __DIR__ . '/../wp-ultra-mcp/includes/builders/adapters/beaver.php';
require __DIR__ . '/../wp-ultra-mcp/includes/builders/adapters/oxygen.php';

/* ---- driver resolution ---- */

it('driver: explicit wins, single-installed auto, none/multiple error', function () {
    $none = ['divi' => ['installed' => false], 'beaver' => ['installed' => false], 'oxygen' => ['installed' => false]];
    $one  = ['divi' => ['installed' => true],  'beaver' => ['installed' => false], 'oxygen' => ['installed' => false]];
    $two  = ['divi' => ['installed' => true],  'beaver' => ['installed' => true],  'oxygen' => ['installed' => false]];
    assert_eq('divi', wpultra_builders_driver('', $one));
    assert_true(is_string(wpultra_builders_driver('', $none)) && str_contains(wpultra_builders_driver('', $none), 'No supported'));
    assert_true(str_contains((string) wpultra_builders_driver('', $two), 'Multiple'));
    assert_eq('beaver', wpultra_builders_driver('beaver', $two));
    assert_true(str_contains((string) wpultra_builders_driver('oxygen', $two), 'not installed'));
    assert_true(str_contains((string) wpultra_builders_driver('wix', $two), 'Unknown'));
});

/* ---- Divi shortcode parser ---- */

$DIVI = '[et_pb_section fb_built="1"][et_pb_row][et_pb_column type="4_4"]'
    . '[et_pb_text admin_label="Intro"]<p>Hello <b>world</b></p>[/et_pb_text]'
    . '[et_pb_button button_text="Go" button_url="https://x.test" /]'
    . '[/et_pb_column][/et_pb_row][/et_pb_section]';

it('divi parse builds the nested tree with attrs + leaf content', function () use ($DIVI) {
    $tree = wpultra_divi_parse($DIVI);
    assert_true(is_array($tree));
    assert_eq('et_pb_section', $tree[0]['type']);
    assert_eq('1', $tree[0]['attrs']['fb_built']);
    $col = $tree[0]['children'][0]['children'][0];
    assert_eq('4_4', $col['attrs']['type']);
    assert_eq('et_pb_text', $col['children'][0]['type']);
    assert_eq('<p>Hello <b>world</b></p>', $col['children'][0]['content']);
    assert_eq('et_pb_button', $col['children'][1]['type']); // self-closing
    assert_eq('Go', $col['children'][1]['attrs']['button_text']);
});

it('divi serialize round-trips the parse', function () use ($DIVI) {
    $tree = wpultra_divi_parse($DIVI);
    $markup = wpultra_divi_serialize($tree);
    $tree2 = wpultra_divi_parse($markup);
    // Compare normalized structures (self-closing becomes open/close — same tree).
    $strip = function ($nodes) use (&$strip) {
        return array_map(fn($n) => [
            'type' => $n['type'], 'attrs' => $n['attrs'],
            'content' => $n['content'] ?? '', 'children' => $strip($n['children'] ?? []),
        ], $nodes);
    };
    assert_eq($strip($tree), $strip($tree2));
});

it('divi parse rejects unbalanced markup', function () {
    assert_true(is_string(wpultra_divi_parse('[et_pb_section][et_pb_row][/et_pb_section]')));
    assert_true(is_string(wpultra_divi_parse('[et_pb_section]')));
});

it('divi validate enforces et_pb_* types', function () {
    assert_eq(true, wpultra_divi_validate([['type' => 'et_pb_section', 'children' => []]]));
    assert_true(is_string(wpultra_divi_validate([['type' => 'script', 'children' => []]])));
});

/* ---- Beaver Builder ---- */

$BB = [
    ['node' => 'r1', 'type' => 'row', 'parent' => null, 'position' => 0, 'settings' => []],
    ['node' => 'g1', 'type' => 'column-group', 'parent' => 'r1', 'position' => 0, 'settings' => []],
    ['node' => 'c1', 'type' => 'column', 'parent' => 'g1', 'position' => 0, 'settings' => []],
    ['node' => 'm2', 'type' => 'module', 'parent' => 'c1', 'position' => 1, 'settings' => ['type' => 'button', 'text' => 'Go']],
    ['node' => 'm1', 'type' => 'module', 'parent' => 'c1', 'position' => 0, 'settings' => ['type' => 'rich-text', 'text' => '<p>Hi</p>']],
];

it('beaver tree nests by parent and orders by position', function () use ($BB) {
    $tree = wpultra_bb_tree($BB);
    assert_eq('r1', $tree[0]['node']);
    $col = $tree[0]['children'][0]['children'][0];
    assert_eq('c1', $col['node']);
    assert_eq(['m1', 'm2'], array_column($col['children'], 'node')); // position sorted
    assert_eq('rich-text', $col['children'][0]['module']);
});

it('beaver validate catches bad type / missing parent / dup id / missing module slug', function () use ($BB) {
    assert_eq(true, wpultra_bb_validate($BB));
    assert_true(is_string(wpultra_bb_validate([])));
    assert_true(is_string(wpultra_bb_validate([['node' => 'x', 'type' => 'widget']])));
    assert_true(is_string(wpultra_bb_validate([['node' => 'x', 'type' => 'module', 'parent' => 'ghost', 'settings' => ['type' => 't']]])));
    assert_true(is_string(wpultra_bb_validate([
        ['node' => 'a', 'type' => 'row'], ['node' => 'a', 'type' => 'row'],
    ])));
    assert_true(is_string(wpultra_bb_validate([['node' => 'm', 'type' => 'module', 'settings' => []]])));
});

it('beaver storage normalization keys by id and deep-casts settings to objects', function () use ($BB) {
    if (!function_exists('wp_json_encode')) { function wp_json_encode($d) { return json_encode($d); } }
    $s = wpultra_bb_normalize_for_storage($BB);
    assert_true(isset($s['r1']) && isset($s['m1']));
    assert_true(is_object($s['m1']));
    assert_true(is_object($s['m1']->settings));
    assert_eq('rich-text', $s['m1']->settings->type);
});

/* ---- Oxygen ---- */

it('oxygen validate + root wrap + compact', function () {
    $tree = ['id' => 1, 'name' => 'ct_section', 'options' => ['original' => ['ct_content' => '']], 'children' => [
        ['id' => 2, 'name' => 'ct_headline', 'options' => ['original' => ['ct_content' => 'Hi there']], 'children' => []],
    ]];
    $wrapped = wpultra_oxy_wrap_root([$tree]);
    assert_eq('ct_document', $wrapped['name']);
    assert_eq(true, wpultra_oxy_validate($wrapped));
    assert_true(is_string(wpultra_oxy_validate(['name' => ''])));
    assert_true(is_string(wpultra_oxy_validate(['name' => 'ct_section', 'children' => [['id' => 9], ]]))); // child w/o name
    $compact = wpultra_oxy_compact($wrapped);
    assert_eq('Hi there', $compact['children'][0]['children'][0]['content']);
});

it('oxygen rejects duplicate ids', function () {
    $dup = ['id' => 1, 'name' => 'ct_section', 'children' => [['id' => 1, 'name' => 'ct_headline', 'children' => []]]];
    assert_true(is_string(wpultra_oxy_validate($dup)));
});

run_tests();
