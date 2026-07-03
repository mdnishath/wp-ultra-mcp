<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/elementor/clone.php';

it('normalize_brief validates shape + section types', function () {
    $ok = wpultra_clone_normalize_brief(['sections' => [['type' => 'hero', 'heading' => 'Hi']]]);
    assert_true(is_array($ok));
    assert_eq('hero', $ok['sections'][0]['type']);
    assert_true(is_string(wpultra_clone_normalize_brief('nope')));
    assert_true(is_string(wpultra_clone_normalize_brief(['sections' => []])));
    assert_true(is_string(wpultra_clone_normalize_brief(['sections' => [['type' => 'wat']]])));
});

it('compose hero fills heading/sub/buttons in order', function () {
    $s = wpultra_clone_normalize_brief(['sections' => [[
        'type' => 'hero', 'heading' => 'Big Title', 'subheading' => 'Sub here', 'buttons' => ['Go'],
    ]]])['sections'][0];
    $tree = wpultra_clone_compose_section($s);
    assert_eq('e-flexbox', $tree['elType']);
    $kids = $tree['elements'];
    assert_eq('Big Title', $kids[0]['settings']['title']);
    assert_eq('h1', $kids[0]['settings']['tag']);
    assert_eq('Sub here', $kids[1]['settings']['paragraph']);
    assert_eq('Go', $kids[2]['settings']['text']);
});

it('compose feature-grid builds one column per item', function () {
    $s = wpultra_clone_normalize_brief(['sections' => [[
        'type' => 'feature-grid',
        'items' => [['heading' => 'Fast', 'paragraph' => 'So fast.'], ['heading' => 'Safe', 'paragraph' => 'So safe.'], ['heading' => 'Free', 'paragraph' => 'So free.']],
    ]]])['sections'][0];
    $tree = wpultra_clone_compose_section($s);
    assert_eq(3, count($tree['elements']));
    assert_eq('Fast', $tree['elements'][0]['elements'][0]['settings']['title']);
    assert_eq('So safe.', $tree['elements'][1]['elements'][1]['settings']['paragraph']);
});

it('compose navbar uses paragraphs as links and custom falls back sanely', function () {
    $nav = wpultra_clone_compose_section(wpultra_clone_normalize_brief(['sections' => [[
        'type' => 'navbar', 'heading' => 'Acme', 'paragraphs' => ['Home', 'Docs'], 'buttons' => ['Try'],
    ]]])['sections'][0]);
    assert_eq('Acme', $nav['elements'][0]['settings']['title']);
    assert_eq(2, count($nav['elements'][1]['elements']));
    $custom = wpultra_clone_compose_section(wpultra_clone_normalize_brief(['sections' => [['type' => 'custom']]])['sections'][0]);
    assert_true(count($custom['elements']) >= 1); // never an empty section
});

it('section class props build v4 shapes only for provided colors', function () {
    assert_eq([], wpultra_clone_section_class_props('', ''));
    $p = wpultra_clone_section_class_props('#112233', '#ffffff');
    assert_eq('background', $p['background']['$$type']);
    assert_eq('#112233', $p['background']['value']['color']['value']);
    assert_eq('#ffffff', $p['color']['value']);
});

$FIXTURE_HTML = '<html><head><title>Acme Rockets</title>'
    . '<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@700&display=swap" rel="stylesheet">'
    . '<style>.hero{background:#112244;color:#ffffff}.btn{background:#ff6600}</style></head>'
    . '<body><h1>Fly to the moon</h1><p>Rockets for everyone, affordable and safe.</p>'
    . '<h2>Why Acme</h2><h3>Fast</h3><p>Blistering speed.</p><h3>Safe</h3><p>Triple redundancy.</p>'
    . '<button>Book a flight</button><a href="/pricing">Pricing</a>'
    . '<script>var hidden = "#deadbe";</script></body></html>';

it('extractor pulls headings/paragraphs/buttons from static HTML', function () use ($FIXTURE_HTML) {
    assert_eq(['Fly to the moon'], wpultra_clone_tag_texts($FIXTURE_HTML, 'h1'));
    assert_eq(['Fast', 'Safe'], wpultra_clone_tag_texts($FIXTURE_HTML, 'h3'));
    assert_true(in_array('Book a flight', wpultra_clone_tag_texts($FIXTURE_HTML, 'button'), true));
});

it('palette + fonts extraction', function () use ($FIXTURE_HTML) {
    $palette = wpultra_clone_palette($FIXTURE_HTML);
    assert_true(in_array('#112244', $palette, true));
    assert_true(in_array('#ff6600', $palette, true));
    $fonts = wpultra_clone_fonts($FIXTURE_HTML);
    assert_true(in_array('Space Grotesk', $fonts, true) || in_array('Space Grotesk:wght@700', $fonts, true));
});

it('extract_brief derives normalizable sections incl. hero + feature-grid', function () use ($FIXTURE_HTML) {
    [$brief, $notes] = wpultra_clone_extract_brief($FIXTURE_HTML, 'https://acme.test');
    $norm = wpultra_clone_normalize_brief($brief);
    assert_true(is_array($norm), 'extracted brief must normalize cleanly: ' . (is_string($norm) ? $norm : ''));
    $types = array_column($norm['sections'], 'type');
    assert_true(in_array('hero', $types, true));
    assert_true(in_array('feature-grid', $types, true));
    $hero = $norm['sections'][array_search('hero', $types, true)];
    assert_eq('Fly to the moon', $hero['heading']);
});

it('js-rendered page produces a thin-brief warning note', function () {
    [, $notes] = wpultra_clone_extract_brief('<html><body><div id="app"></div><script src="x.js"></script></body></html>');
    assert_true((bool) preg_grep('/JS-rendered/', $notes));
});

run_tests();
