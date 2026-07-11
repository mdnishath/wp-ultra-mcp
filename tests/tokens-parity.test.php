<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
if (!function_exists('wpultra_err')) { function wpultra_err($code, $msg, $data = '') { return new WP_Error($code, $msg, $data); } }
if (!function_exists('wpultra_ok')) { function wpultra_ok(array $fields) { return array_merge(['success' => true], $fields); } }
require __DIR__ . '/../wp-ultra-mcp/includes/fse/tokens.php';
require __DIR__ . '/../wp-ultra-mcp/includes/bricks/tokens.php';

/* ------------------------------------------------------------------ *
 * wpultra_tokens_slug
 * ------------------------------------------------------------------ */

it('slug: lowercases and hyphenates a normal title', function () {
    assert_eq('brand-primary', wpultra_tokens_slug('Brand Primary'));
});

it('slug: collapses punctuation/whitespace runs into a single hyphen', function () {
    assert_eq('heading-1-display', wpultra_tokens_slug('  Heading_1 -- Display!! '));
});

it('slug: trims leading/trailing hyphens after collapsing', function () {
    assert_eq('space-md', wpultra_tokens_slug('---space md---'));
});

it('slug: empty/whitespace-only input falls back to a non-empty default', function () {
    assert_eq('token', wpultra_tokens_slug(''));
    assert_eq('token', wpultra_tokens_slug('   '));
    assert_eq('token', wpultra_tokens_slug('###'));
});

it('slug: unicode/accented input degrades to alnum-only pieces (no crash, non-empty)', function () {
    $s = wpultra_tokens_slug('Café Noir');
    assert_true($s !== '', 'expected a non-empty slug');
});

/* ------------------------------------------------------------------ *
 * wpultra_tokens_unique_slug — duplicate roles within one brief
 * ------------------------------------------------------------------ */

it('unique_slug: first use of a base is unchanged', function () {
    $seen = [];
    assert_eq('primary', wpultra_tokens_unique_slug('primary', $seen));
});

it('unique_slug: repeats get numeric suffixes starting at -2', function () {
    $seen = [];
    assert_eq('primary', wpultra_tokens_unique_slug('primary', $seen));
    assert_eq('primary-2', wpultra_tokens_unique_slug('primary', $seen));
    assert_eq('primary-3', wpultra_tokens_unique_slug('primary', $seen));
});

/* ------------------------------------------------------------------ *
 * wpultra_tokens_theme_json_patch — full mixed-brief shape
 * ------------------------------------------------------------------ */

it('theme_json_patch: builds palette/fontFamilies/fontSizes/spacingSizes for a mixed brief', function () {
    $brief = [
        'colors' => [['role' => 'primary', 'title' => 'Brand Primary', 'hex' => '#0a84ff']],
        'fonts'  => [['role' => 'heading', 'title' => 'Display', 'family' => 'Inter']],
        'sizes'  => [['role' => 'space-md', 'title' => 'Space M', 'size' => 16, 'unit' => 'px']],
    ];
    $patch = wpultra_tokens_theme_json_patch($brief);
    assert_eq(
        ['slug' => 'primary', 'name' => 'Brand Primary', 'color' => '#0a84ff'],
        $patch['settings']['color']['palette'][0]
    );
    assert_eq(
        ['slug' => 'heading', 'name' => 'Display', 'fontFamily' => 'Inter'],
        $patch['settings']['typography']['fontFamilies'][0]
    );
    assert_eq(
        ['slug' => 'space-md', 'name' => 'Space M', 'size' => '16px'],
        $patch['settings']['typography']['fontSizes'][0]
    );
    // Sizes are dual-purposed: they also land in settings.spacing.spacingSizes (same slug)
    // so the token is usable from both the Typography and Spacing pickers.
    assert_eq(
        ['slug' => 'space-md', 'name' => 'Space M', 'size' => '16px'],
        $patch['settings']['spacing']['spacingSizes'][0]
    );
});

it('theme_json_patch: falls back to title for slugging when role is absent', function () {
    $patch = wpultra_tokens_theme_json_patch(['colors' => [['title' => 'Accent Color', 'hex' => '#ff0000']]]);
    assert_eq('accent-color', $patch['settings']['color']['palette'][0]['slug']);
});

it('theme_json_patch: duplicate roles in one brief get suffixed slugs, not collisions', function () {
    $brief = ['colors' => [
        ['role' => 'brand', 'title' => 'Brand A', 'hex' => '#111111'],
        ['role' => 'brand', 'title' => 'Brand B', 'hex' => '#222222'],
    ]];
    $patch = wpultra_tokens_theme_json_patch($brief);
    $slugs = array_column($patch['settings']['color']['palette'], 'slug');
    assert_eq(['brand', 'brand-2'], $slugs);
});

it('theme_json_patch: skips colors missing a hex value', function () {
    $patch = wpultra_tokens_theme_json_patch(['colors' => [['title' => 'No Hex']]]);
    assert_true(!isset($patch['settings']['color']));
});

it('theme_json_patch: skips fonts missing a family and sizes missing a numeric size', function () {
    $patch = wpultra_tokens_theme_json_patch([
        'fonts' => [['title' => 'No Family']],
        'sizes' => [['title' => 'No Size']],
    ]);
    assert_true(!isset($patch['settings']));
    assert_true(in_array("font 'No Family' needs a family.", $patch['dropped'], true));
    assert_true(in_array("size 'No Size' needs a numeric size.", $patch['dropped'], true));
});

/* ------------------------------------------------------------------ *
 * wpultra_tokens_theme_json_patch — hex validation (parity fix: garbage hex used to be
 * written straight into theme.json as long as it wasn't an empty string)
 * ------------------------------------------------------------------ */

it('theme_json_patch: an invalid hex is dropped (not written) and reported in `dropped`', function () {
    $patch = wpultra_tokens_theme_json_patch(['colors' => [['role' => 'primary', 'title' => 'Brand', 'hex' => 'notacolor']]]);
    assert_true(!isset($patch['settings']));
    assert_eq(["color 'Brand' has invalid hex 'notacolor'."], $patch['dropped']);
});

it('theme_json_patch: a valid hex (with or without leading #) is still minted', function () {
    $patch = wpultra_tokens_theme_json_patch(['colors' => [
        ['role' => 'primary', 'title' => 'Brand', 'hex' => '#0a84ff'],
        ['role' => 'accent', 'title' => 'Accent', 'hex' => 'ff0000'],
    ]]);
    assert_true(!isset($patch['dropped']));
    assert_eq('#0a84ff', $patch['settings']['color']['palette'][0]['color']);
    assert_eq('#ff0000', $patch['settings']['color']['palette'][1]['color']);
});

it('theme_json_patch: a mixed brief mints the valid colors and reports the invalid ones', function () {
    $patch = wpultra_tokens_theme_json_patch(['colors' => [
        ['role' => 'primary', 'title' => 'Brand', 'hex' => '#0a84ff'],
        ['role' => 'bad', 'title' => 'Bad', 'hex' => 'notacolor'],
    ]]);
    $slugs = array_column($patch['settings']['color']['palette'], 'slug');
    assert_eq(['primary'], $slugs);
    assert_eq(["color 'Bad' has invalid hex 'notacolor'."], $patch['dropped']);
});

it('theme_json_patch: partial brief (fonts only) produces only the typography.fontFamilies branch', function () {
    $patch = wpultra_tokens_theme_json_patch(['fonts' => [['title' => 'Body', 'family' => 'Roboto']]]);
    assert_eq(['fontFamilies' => [['slug' => 'body', 'name' => 'Body', 'fontFamily' => 'Roboto']]], $patch['settings']['typography']);
    assert_true(!isset($patch['settings']['color']));
    assert_true(!isset($patch['settings']['spacing']));
});

it('theme_json_patch: empty brief returns an empty array', function () {
    assert_eq([], wpultra_tokens_theme_json_patch([]));
    assert_eq([], wpultra_tokens_theme_json_patch(['colors' => [], 'fonts' => [], 'sizes' => []]));
});

it('theme_json_patch: numeric size is stringified without a trailing ".0"', function () {
    $patch = wpultra_tokens_theme_json_patch(['sizes' => [['title' => 'Gap', 'size' => 24]]]);
    assert_eq('24px', $patch['settings']['typography']['fontSizes'][0]['size']);
});

/* ------------------------------------------------------------------ *
 * wpultra_tokens_css_var_names
 * ------------------------------------------------------------------ */

it('css_var_names: derives --wp--preset--* handles for every minted preset', function () {
    $brief = [
        'colors' => [['role' => 'primary', 'title' => 'Brand', 'hex' => '#0a84ff']],
        'fonts'  => [['role' => 'heading', 'title' => 'Display', 'family' => 'Inter']],
        'sizes'  => [['role' => 'space-md', 'title' => 'Space M', 'size' => 16, 'unit' => 'px']],
    ];
    $patch = wpultra_tokens_theme_json_patch($brief);
    $vars = wpultra_tokens_css_var_names($patch);
    assert_eq([
        '--wp--preset--color--primary',
        '--wp--preset--font-family--heading',
        '--wp--preset--font-size--space-md',
        '--wp--preset--spacing--space-md',
    ], $vars);
});

it('css_var_names: empty patch yields no variables', function () {
    assert_eq([], wpultra_tokens_css_var_names([]));
});

/* ------------------------------------------------------------------ *
 * wpultra_tokens_upsert_preset_list / wpultra_tokens_upsert_settings — idempotent-by-slug
 * ------------------------------------------------------------------ */

it('upsert_preset_list: appends a brand-new slug', function () {
    $existing = [['slug' => 'a', 'name' => 'A', 'color' => '#111']];
    $incoming = [['slug' => 'b', 'name' => 'B', 'color' => '#222']];
    $result = wpultra_tokens_upsert_preset_list($existing, $incoming);
    assert_eq([
        ['slug' => 'a', 'name' => 'A', 'color' => '#111'],
        ['slug' => 'b', 'name' => 'B', 'color' => '#222'],
    ], $result);
});

it('upsert_preset_list: re-applying the same slug REPLACES in place (no duplicate)', function () {
    $existing = [['slug' => 'a', 'name' => 'A', 'color' => '#111']];
    $incoming = [['slug' => 'a', 'name' => 'A Renamed', 'color' => '#999']];
    $result = wpultra_tokens_upsert_preset_list($existing, $incoming);
    assert_eq([['slug' => 'a', 'name' => 'A Renamed', 'color' => '#999']], $result);
});

it('upsert_settings: only touches the preset branches present in $incoming, leaves the rest of settings alone', function () {
    $existing = ['color' => ['palette' => [['slug' => 'a', 'name' => 'A', 'color' => '#111']]], 'custom' => ['untouched' => true]];
    $incoming = ['color' => ['palette' => [['slug' => 'b', 'name' => 'B', 'color' => '#222']]]];
    $result = wpultra_tokens_upsert_settings($existing, $incoming);
    assert_eq([
        ['slug' => 'a', 'name' => 'A', 'color' => '#111'],
        ['slug' => 'b', 'name' => 'B', 'color' => '#222'],
    ], $result['color']['palette']);
    assert_eq(['untouched' => true], $result['custom']);
});

/* ------------------------------------------------------------------ *
 * wpultra_tokens_bricks_colors
 * ------------------------------------------------------------------ */

it('bricks_colors: maps brief colors to {id,name,color} with the id equal to the deterministic slug', function () {
    $brief = ['colors' => [['role' => 'primary', 'title' => 'Brand Primary', 'hex' => '#0a84ff']]];
    $out = wpultra_tokens_bricks_colors($brief);
    assert_eq([['id' => 'primary', 'name' => 'Brand Primary', 'color' => '#0a84ff']], $out['items']);
    assert_eq([], $out['dropped']);
});

it('bricks_colors: re-deriving from the SAME role/title produces the SAME id (idempotent-by-slug)', function () {
    $brief = ['colors' => [['role' => 'primary', 'title' => 'Brand Primary', 'hex' => '#0a84ff']]];
    $first = wpultra_tokens_bricks_colors($brief);
    $second = wpultra_tokens_bricks_colors($brief);
    assert_eq($first, $second);
});

it('bricks_colors: duplicate roles in one brief get suffixed ids', function () {
    $brief = ['colors' => [
        ['role' => 'brand', 'title' => 'Brand A', 'hex' => '#111111'],
        ['role' => 'brand', 'title' => 'Brand B', 'hex' => '#222222'],
    ]];
    $out = wpultra_tokens_bricks_colors($brief);
    assert_eq(['brand', 'brand-2'], array_column($out['items'], 'id'));
});

it('bricks_colors: skips entries missing a hex value', function () {
    $out = wpultra_tokens_bricks_colors(['colors' => [['title' => 'No Hex']]]);
    assert_eq([], $out['items']);
    assert_eq(1, count($out['dropped']));
});

it('bricks_colors: empty/absent colors yields an empty array', function () {
    assert_eq(['items' => [], 'dropped' => []], wpultra_tokens_bricks_colors([]));
    assert_eq(['items' => [], 'dropped' => []], wpultra_tokens_bricks_colors(['colors' => []]));
});

/* ------------------------------------------------------------------ *
 * wpultra_tokens_bricks_colors — hex validation (same parity fix as the FSE side)
 * ------------------------------------------------------------------ */

it('bricks_colors: an invalid hex is dropped (not written) and reported', function () {
    $out = wpultra_tokens_bricks_colors(['colors' => [['role' => 'primary', 'title' => 'Brand', 'hex' => 'notacolor']]]);
    assert_eq([], $out['items']);
    assert_eq(["color 'Brand' has invalid hex 'notacolor'."], $out['dropped']);
});

it('bricks_colors: a valid hex is still minted', function () {
    $out = wpultra_tokens_bricks_colors(['colors' => [['role' => 'primary', 'title' => 'Brand', 'hex' => '#0a84ff']]]);
    assert_eq([['id' => 'primary', 'name' => 'Brand', 'color' => '#0a84ff']], $out['items']);
    assert_eq([], $out['dropped']);
});

it('bricks_colors: a mixed brief mints the valid colors and reports the invalid ones', function () {
    $out = wpultra_tokens_bricks_colors(['colors' => [
        ['role' => 'primary', 'title' => 'Brand', 'hex' => '#0a84ff'],
        ['role' => 'bad', 'title' => 'Bad', 'hex' => 'notacolor'],
    ]]);
    assert_eq(['primary'], array_column($out['items'], 'id'));
    assert_eq(["color 'Bad' has invalid hex 'notacolor'."], $out['dropped']);
});

/* ------------------------------------------------------------------ *
 * wpultra_tokens_bricks_variables — fonts + sizes as Bricks global variables
 * ------------------------------------------------------------------ */

it('bricks_variables: maps fonts to {id,name,value=family}', function () {
    $out = wpultra_tokens_bricks_variables(['fonts' => [['role' => 'heading', 'title' => 'Display', 'family' => 'Inter']]]);
    assert_eq([['id' => 'heading', 'name' => 'Display', 'value' => 'Inter']], $out['items']);
    assert_eq([], $out['dropped']);
});

it('bricks_variables: maps sizes to {id,name,value="<size><unit>"}', function () {
    $out = wpultra_tokens_bricks_variables(['sizes' => [['role' => 'space-md', 'title' => 'Space M', 'size' => 16, 'unit' => 'px']]]);
    assert_eq([['id' => 'space-md', 'name' => 'Space M', 'value' => '16px']], $out['items']);
});

it('bricks_variables: defaults size unit to px when omitted', function () {
    $out = wpultra_tokens_bricks_variables(['sizes' => [['title' => 'Gap', 'size' => 24]]]);
    assert_eq('24px', $out['items'][0]['value']);
});

it('bricks_variables: skips fonts missing a family and sizes missing a numeric size', function () {
    $out = wpultra_tokens_bricks_variables(['fonts' => [['title' => 'No Family']], 'sizes' => [['title' => 'No Size']]]);
    assert_eq([], $out['items']);
    assert_true(in_array("font 'No Family' needs a family.", $out['dropped'], true));
    assert_true(in_array("size 'No Size' needs a numeric size.", $out['dropped'], true));
});

it('bricks_variables: empty brief yields an empty array', function () {
    assert_eq(['items' => [], 'dropped' => []], wpultra_tokens_bricks_variables([]));
});

/* ------------------------------------------------------------------ *
 * wpultra_tokens_bricks_upsert — idempotent-by-id for the Bricks side
 * ------------------------------------------------------------------ */

it('bricks_upsert: appends a new id and replaces a matching id in place', function () {
    $existing = [['id' => 'a', 'name' => 'A', 'color' => '#111']];
    $incoming = [
        ['id' => 'a', 'name' => 'A Renamed', 'color' => '#999'],
        ['id' => 'b', 'name' => 'B', 'color' => '#222'],
    ];
    $result = wpultra_tokens_bricks_upsert($existing, $incoming);
    assert_eq([
        ['id' => 'a', 'name' => 'A Renamed', 'color' => '#999'],
        ['id' => 'b', 'name' => 'B', 'color' => '#222'],
    ], $result);
});

run_tests();
