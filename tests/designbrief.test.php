<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';

if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/wpultra_designbrief/'); }
// helpers.php provides wpultra_err / wpultra_ok (uses WP_Error stub from harness).
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/ai/designbrief.php';

/* ============================================================
 * A well-formed baseline plan the tests can mutate.
 * ============================================================ */
function dfb_good_plan(): array {
    return [
        'site'   => ['name' => 'Acme Co', 'tagline' => 'We build things'],
        'tokens' => [
            'colors' => [['slug' => 'brand', 'name' => 'Brand', 'hex' => '#0055ff']],
            'fonts'  => [['slug' => 'body', 'name' => 'Body', 'family' => 'Inter, sans-serif']],
        ],
        'pages'  => [
            ['slug' => 'home', 'title' => 'Home', 'sections' => [
                ['type' => 'hero', 'heading' => 'Welcome', 'subheading' => 'Hi there', 'button' => ['label' => 'Go', 'url' => 'https://x.test']],
            ]],
            ['slug' => 'about', 'title' => 'About', 'sections' => [
                ['type' => 'text', 'heading' => 'About us', 'body' => 'Story'],
            ]],
        ],
        'menu'   => [
            ['title' => 'Home', 'page_slug' => 'home'],
            ['title' => 'Docs', 'url' => 'https://docs.test'],
        ],
    ];
}

/* ============================================================
 * slug
 * ============================================================ */
it('slug lowercases and hyphenates', function () {
    assert_eq('joes-plumbing', wpultra_dfb_slug('Joe\'s Plumbing'));
    assert_eq('hello-world', wpultra_dfb_slug('  Hello   World!!  '));
    assert_eq('a-b-c', wpultra_dfb_slug('a/b/c'));
});
it('slug strips leading/trailing separators and handles accents', function () {
    assert_eq('cafe-menu', wpultra_dfb_slug('Café Menu'));
    assert_eq('x', wpultra_dfb_slug('---x---'));
    assert_eq('', wpultra_dfb_slug('!!!'));
});

/* ============================================================
 * is_hex
 * ============================================================ */
it('is_hex validates #rgb and #rrggbb', function () {
    assert_true(wpultra_dfb_is_hex('#fff'));
    assert_true(wpultra_dfb_is_hex('#00AAff'));
    assert_true(wpultra_dfb_is_hex('#123abc'));
    assert_eq(false, wpultra_dfb_is_hex('fff'));
    assert_eq(false, wpultra_dfb_is_hex('#ggg'));
    assert_eq(false, wpultra_dfb_is_hex('#12345'));
});

/* ============================================================
 * validate_plan
 * ============================================================ */
it('validate_plan accepts a good plan', function () {
    assert_true(wpultra_dfb_validate_plan(dfb_good_plan()) === true);
});
it('validate_plan rejects missing site.name', function () {
    $p = dfb_good_plan();
    unset($p['site']['name']);
    $r = wpultra_dfb_validate_plan($p);
    assert_true(is_string($r), 'returns error string');
    assert_contains('site.name', $r);
});
it('validate_plan rejects a page without a slug', function () {
    $p = dfb_good_plan();
    unset($p['pages'][0]['slug']);
    $r = wpultra_dfb_validate_plan($p);
    assert_true(is_string($r));
    assert_contains('slug', $r);
});
it('validate_plan rejects a page without a title', function () {
    $p = dfb_good_plan();
    $p['pages'][1]['title'] = '';
    $r = wpultra_dfb_validate_plan($p);
    assert_true(is_string($r));
    assert_contains('title', $r);
});
it('validate_plan rejects an empty pages array', function () {
    $p = dfb_good_plan();
    $p['pages'] = [];
    $r = wpultra_dfb_validate_plan($p);
    assert_true(is_string($r));
    assert_contains('pages', $r);
});
it('validate_plan rejects a bad section type', function () {
    $p = dfb_good_plan();
    $p['pages'][0]['sections'][0]['type'] = 'carousel';
    $r = wpultra_dfb_validate_plan($p);
    assert_true(is_string($r));
    assert_contains('carousel', $r);
});
it('validate_plan rejects a bad hex color', function () {
    $p = dfb_good_plan();
    $p['tokens']['colors'][0]['hex'] = 'blue';
    $r = wpultra_dfb_validate_plan($p);
    assert_true(is_string($r));
    assert_contains('hex', $r);
});
it('validate_plan rejects a font without a family', function () {
    $p = dfb_good_plan();
    $p['tokens']['fonts'][0]['family'] = '';
    $r = wpultra_dfb_validate_plan($p);
    assert_true(is_string($r));
    assert_contains('family', $r);
});
it('validate_plan rejects a menu item referencing an unknown page', function () {
    $p = dfb_good_plan();
    $p['menu'][0]['page_slug'] = 'nope';
    $r = wpultra_dfb_validate_plan($p);
    assert_true(is_string($r));
    assert_contains('nope', $r);
});
it('validate_plan rejects a menu item with neither page_slug nor url', function () {
    $p = dfb_good_plan();
    $p['menu'][] = ['title' => 'Orphan'];
    $r = wpultra_dfb_validate_plan($p);
    assert_true(is_string($r));
    assert_contains('page_slug', $r);
});
it('validate_plan rejects a non-absolute menu url', function () {
    $p = dfb_good_plan();
    $p['menu'][1]['url'] = '/relative';
    $r = wpultra_dfb_validate_plan($p);
    assert_true(is_string($r));
    assert_contains('absolute', $r);
});
it('validate_plan tolerates a plan with no tokens or menu', function () {
    $p = ['site' => ['name' => 'X'], 'pages' => [['slug' => 'home', 'title' => 'Home', 'sections' => []]]];
    assert_true(wpultra_dfb_validate_plan($p) === true);
});

/* ============================================================
 * section_blocks
 * ============================================================ */
it('section_blocks(hero) produces valid wp: comment-delimited blocks', function () {
    $m = wpultra_dfb_section_blocks(['type' => 'hero', 'heading' => 'Big', 'subheading' => 'small', 'button' => ['label' => 'Go', 'url' => 'https://x.test']]);
    assert_contains('<!-- wp:group', $m);
    assert_contains('<!-- wp:heading', $m);
    assert_contains('<h1', $m);
    assert_contains('<!-- wp:paragraph', $m);
    assert_contains('<!-- wp:buttons', $m);
    assert_contains('<!-- wp:button', $m);
    assert_contains('href="https://x.test"', $m);
});
it('section_blocks(features) builds a columns grid', function () {
    $m = wpultra_dfb_section_blocks(['type' => 'features', 'heading' => 'Why us', 'items' => [
        ['title' => 'Fast', 'text' => 'Very fast'],
        ['title' => 'Cheap', 'text' => 'Low cost'],
    ]]);
    assert_contains('<!-- wp:columns', $m);
    assert_contains('<!-- wp:column ', $m);
    assert_contains('Fast', $m);
    assert_contains('Cheap', $m);
    assert_contains('<h3', $m);
});
it('section_blocks(cta) has heading + button', function () {
    $m = wpultra_dfb_section_blocks(['type' => 'cta', 'heading' => 'Sign up', 'button' => ['label' => 'Join', 'url' => 'https://j.test']]);
    assert_contains('<!-- wp:heading', $m);
    assert_contains('<!-- wp:button', $m);
    assert_contains('Sign up', $m);
    assert_contains('Join', $m);
});
it('section_blocks(text) has heading + paragraph', function () {
    $m = wpultra_dfb_section_blocks(['type' => 'text', 'heading' => 'Story', 'body' => 'Once upon a time']);
    assert_contains('<!-- wp:heading', $m);
    assert_contains('<!-- wp:paragraph', $m);
    assert_contains('Once upon a time', $m);
});
it('section_blocks escapes a hostile heading', function () {
    $m = wpultra_dfb_section_blocks(['type' => 'text', 'heading' => '<script>alert(1)</script>']);
    assert_true(!str_contains($m, '<script>'), 'raw script tag not present');
    assert_contains('&lt;script&gt;', $m);
});
it('section_blocks escapes a hostile button url', function () {
    $m = wpultra_dfb_section_blocks(['type' => 'cta', 'heading' => 'x', 'button' => ['label' => 'Click', 'url' => 'https://x.test/"><script>']]);
    assert_true(!str_contains($m, '"><script>'), 'attribute breakout escaped');
});
it('section_blocks on an empty section still returns a valid group', function () {
    $m = wpultra_dfb_section_blocks(['type' => 'text']);
    assert_contains('<!-- wp:group', $m);
    assert_contains('<!-- /wp:group -->', $m);
});

/* ============================================================
 * page_content
 * ============================================================ */
it('page_content concatenates multiple sections', function () {
    $page = ['slug' => 'home', 'title' => 'Home', 'sections' => [
        ['type' => 'hero', 'heading' => 'A'],
        ['type' => 'text', 'heading' => 'B', 'body' => 'body b'],
        ['type' => 'cta', 'heading' => 'C', 'button' => ['label' => 'Go', 'url' => 'https://x.test']],
    ]];
    $c = wpultra_dfb_page_content($page);
    assert_eq(3, substr_count($c, '<!-- wp:group'), 'three group wrappers');
    assert_contains('>A</h1>', $c);
    assert_contains('body b', $c);
    assert_contains('Go', $c);
});
it('page_content of a page with no sections is empty', function () {
    assert_eq('', wpultra_dfb_page_content(['slug' => 'x', 'title' => 'X', 'sections' => []]));
});

/* ============================================================
 * plan_prompt
 * ============================================================ */
it('plan_prompt returns a system+user pair stating the shape and limits', function () {
    $p = wpultra_dfb_plan_prompt('A bakery site');
    assert_true(isset($p['system'], $p['user']), 'has system + user');
    assert_contains('hero', $p['system']);
    assert_contains('features', $p['system']);
    assert_contains('6 pages', $p['system']);
    assert_contains('JSON', $p['system']);
    assert_contains('A bakery site', $p['user']);
});

/* ============================================================
 * parse_plan
 * ============================================================ */
it('parse_plan decodes plain JSON', function () {
    $r = wpultra_dfb_parse_plan('{"site":{"name":"X"},"pages":[]}');
    assert_true(is_array($r));
    assert_eq('X', $r['site']['name']);
});
it('parse_plan decodes a ```json fenced block', function () {
    $r = wpultra_dfb_parse_plan("Here you go:\n```json\n{\"site\":{\"name\":\"Y\"}}\n```\nEnjoy!");
    assert_true(is_array($r));
    assert_eq('Y', $r['site']['name']);
});
it('parse_plan recovers JSON embedded in prose', function () {
    $r = wpultra_dfb_parse_plan('Sure! {"site":{"name":"Z"}} done.');
    assert_true(is_array($r));
    assert_eq('Z', $r['site']['name']);
});
it('parse_plan returns an error string on garbage', function () {
    $r = wpultra_dfb_parse_plan('not json at all');
    assert_true(is_string($r));
});
it('parse_plan returns an error string on empty input', function () {
    $r = wpultra_dfb_parse_plan('   ');
    assert_true(is_string($r));
});

/* ============================================================
 * tokens_to_settings
 * ============================================================ */
it('tokens_to_settings maps colors to a palette and fonts to fontFamilies', function () {
    $s = wpultra_dfb_tokens_to_settings([
        'colors' => [['slug' => 'Brand X', 'name' => 'Brand', 'hex' => '#abc']],
        'fonts'  => [['slug' => 'Body Font', 'name' => 'Body', 'family' => 'Inter']],
    ]);
    assert_eq('brand-x', $s['color']['palette'][0]['slug']);
    assert_eq('#abc', $s['color']['palette'][0]['color']);
    assert_eq('body-font', $s['typography']['fontFamilies'][0]['slug']);
    assert_eq('Inter', $s['typography']['fontFamilies'][0]['fontFamily']);
});
it('tokens_to_settings skips invalid colors and returns [] for no tokens', function () {
    $s = wpultra_dfb_tokens_to_settings(['colors' => [['slug' => 'x', 'hex' => 'nope']]]);
    assert_true(!isset($s['color']), 'invalid color dropped');
    assert_eq([], wpultra_dfb_tokens_to_settings([]));
});

/* ============================================================
 * boot contract
 * ============================================================ */
it('boot is callable and cheap', function () {
    wpultra_dfb_boot();
    assert_true(true);
});

run_tests();
