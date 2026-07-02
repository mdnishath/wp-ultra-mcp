<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/system/snapshot.php';

// ---- wpultra_snapshot_detect: pure probe-map evaluator ----

it('detect flags a class-probe as true when the class exists', function () {
    $probes = [['label' => 'wp_error', 'class' => 'WP_Error']];
    assert_eq(['wp_error' => true], wpultra_snapshot_detect($probes));
});

it('detect flags a class-probe as false when the class is absent', function () {
    $probes = [['label' => 'nope', 'class' => 'Totally\\Fake\\ClassName']];
    assert_eq(['nope' => false], wpultra_snapshot_detect($probes));
});

it('detect flags a function-probe correctly', function () {
    $probes = [
        ['label' => 'yes_fn', 'function' => 'is_wp_error'], // defined by harness.php
        ['label' => 'no_fn', 'function' => 'wpultra_totally_fake_fn_xyz'],
    ];
    assert_eq(['yes_fn' => true, 'no_fn' => false], wpultra_snapshot_detect($probes));
});

it('detect flags a constant-probe correctly', function () {
    define('WPULTRA_TEST_PROBE_CONST', '1');
    $probes = [
        ['label' => 'yes_const', 'constant' => 'WPULTRA_TEST_PROBE_CONST'],
        ['label' => 'no_const', 'constant' => 'WPULTRA_TEST_PROBE_CONST_UNDEFINED'],
    ];
    assert_eq(['yes_const' => true, 'no_const' => false], wpultra_snapshot_detect($probes));
});

it('detect uses the probe value itself as the key when no label is given', function () {
    $probes = [['class' => 'WP_Error']];
    assert_eq(['WP_Error' => true], wpultra_snapshot_detect($probes));
});

it('detect skips malformed probes (no class/function/constant key, or non-array entries)', function () {
    $probes = [
        'not-an-array',
        [],
        ['unknown_key' => 'x'],
        ['label' => 'ok', 'class' => 'WP_Error'],
    ];
    assert_eq(['ok' => true], wpultra_snapshot_detect($probes));
});

it('detect skips a probe whose resolved key is empty', function () {
    $probes = [['label' => '', 'class' => 'WP_Error'], ['class' => '']];
    assert_eq([], wpultra_snapshot_detect($probes));
});

it('ecosystem probe map is well-formed (each entry has a label + exactly one probe kind)', function () {
    foreach (wpultra_snapshot_ecosystem_probes() as $probe) {
        assert_true(isset($probe['label']) && $probe['label'] !== '', 'every probe has a non-empty label');
        $kinds = array_intersect(['class', 'function', 'constant'], array_keys($probe));
        assert_eq(1, count($kinds), 'probe has exactly one of class/function/constant: ' . $probe['label']);
    }
});

// ---- wpultra_snapshot_resolve_sections: pure include[] normalizer ----

it('resolve_sections returns all sections when include is omitted (null)', function () {
    assert_eq(wpultra_snapshot_all_sections(), wpultra_snapshot_resolve_sections(null));
});

it('resolve_sections returns all sections when include is an empty array', function () {
    assert_eq(wpultra_snapshot_all_sections(), wpultra_snapshot_resolve_sections([]));
});

it('resolve_sections filters to only the requested known sections, de-duplicated, order-stable', function () {
    assert_eq(['users', 'menus'], wpultra_snapshot_resolve_sections(['users', 'menus', 'users']));
});

it('resolve_sections drops unknown values and falls back to all when nothing valid remains', function () {
    assert_eq(wpultra_snapshot_all_sections(), wpultra_snapshot_resolve_sections(['not_a_real_section']));
});

it('resolve_sections ignores unknown values mixed with known ones', function () {
    assert_eq(['content'], wpultra_snapshot_resolve_sections(['content', 'bogus']));
});

// ---- wpultra_snapshot_build: WP-calling dispatcher, stubbed ----

if (!function_exists('get_bloginfo')) { function get_bloginfo($k = '') { return $k === 'version' ? '6.5' : 'Test Site'; } }
if (!function_exists('home_url')) { function home_url($p = '/') { return 'https://example.test' . $p; } }
if (!function_exists('get_locale')) { function get_locale() { return 'en_US'; } }
if (!function_exists('wp_timezone_string')) { function wp_timezone_string() { return 'UTC'; } }
if (!function_exists('get_option')) { function get_option($k, $d = false) { return $d; } }
if (!function_exists('is_multisite')) { function is_multisite() { return false; } }

it('snapshot_build always includes the site block, and only requested sections', function () {
    // Use a section list with no matching branch in the dispatcher's switch (an already-filtered
    // custom list) so this exercises the dispatcher without needing the heavier WP stubs
    // (get_post_types/wp_count_posts/get_taxonomies/wp_get_nav_menus/get_plugins/wp_get_theme).
    $out = wpultra_snapshot_build([]);
    assert_eq(['site'], array_keys($out), 'no sections requested => only the always-on site block');
    assert_true(isset($out['site']['name']), 'site block has expected shape');
});

run_tests();
