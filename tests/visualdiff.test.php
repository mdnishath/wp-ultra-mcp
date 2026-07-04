<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';

if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/wpultra_visualdiff/'); }
// helpers.php provides wpultra_err / wpultra_ok (uses WP_Error stub from harness).
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/ai/visualdiff.php';

/* ============================================================
 * visible_text — stripping + whitespace collapse + determinism.
 * ============================================================ */

it('visible_text strips tags, script and style; collapses whitespace', function () {
    $html = "<html><head><style>.x{color:red}</style></head><body>\n  <h1>Hello</h1>\n\n  <script>var a=1;</script>  <p>World   here</p></body></html>";
    $t = wpultra_vdiff_visible_text($html);
    assert_eq('Hello World here', $t);
});

it('visible_text hash is stable across whitespace differences', function () {
    $a = wpultra_vdiff_visible_text('<p>Hello   World</p>');
    $b = wpultra_vdiff_visible_text("<p>Hello\n\n\tWorld</p>");
    assert_eq(md5($a), md5($b));
});

it('visible_text decodes entities', function () {
    assert_eq('Fish & Chips', wpultra_vdiff_visible_text('<p>Fish &amp; Chips</p>'));
});

it('visible_text is deterministic', function () {
    $html = '<div><span>a</span><b>b</b> c</div>';
    assert_eq(wpultra_vdiff_visible_text($html), wpultra_vdiff_visible_text($html));
});

/* ============================================================
 * tag_skeleton — structural outline + determinism.
 * ============================================================ */

it('tag_skeleton captures block-level structure in order, ignores inline tags', function () {
    $html = '<body><header><nav></nav></header><main><section><h1>x</h1><p><span>s</span><a href="#">l</a></p></section></main></body>';
    $sk = wpultra_vdiff_tag_skeleton($html);
    // span / a should NOT appear; header/nav/main/section/h1/p should.
    assert_eq('body>header>nav>main>section>h1>p', $sk);
});

it('tag_skeleton ignores script/style inner markup', function () {
    $html = '<div><script><div></div></script><p>x</p></div>';
    assert_eq('div>p', wpultra_vdiff_tag_skeleton($html));
});

it('tag_skeleton is deterministic and differs when structure changes', function () {
    $a = wpultra_vdiff_tag_skeleton('<div><p></p></div>');
    $b = wpultra_vdiff_tag_skeleton('<div><p></p></div>');
    assert_eq($a, $b);
    $c = wpultra_vdiff_tag_skeleton('<div><p></p><p></p></div>');
    assert_true($a !== $c, 'added <p> changes skeleton');
});

/* ============================================================
 * extract — title / h1 / h2.
 * ============================================================ */

it('extract pulls title, h1s and h2s (multiple, in order, trimmed)', function () {
    $html = '<title> My Page </title><h1>Head One</h1><h2>Sub A</h2><h2>Sub <b>B</b></h2>';
    assert_eq(['My Page'], wpultra_vdiff_extract($html, 'title'));
    assert_eq(['Head One'], wpultra_vdiff_extract($html, 'h1'));
    assert_eq(['Sub A', 'Sub B'], wpultra_vdiff_extract($html, 'h2'));
});

it('extract returns empty for absent tag and sanitizes tag name', function () {
    assert_eq([], wpultra_vdiff_extract('<p>x</p>', 'h1'));
    assert_eq([], wpultra_vdiff_extract('<p>x</p>', 'h1; drop'));
});

/* ============================================================
 * img_srcs.
 * ============================================================ */

it('img_srcs extracts src values in order', function () {
    $html = '<img src="a.png"><img alt="x" src=\'b.jpg\'><img>';
    assert_eq(['a.png', 'b.jpg'], wpultra_vdiff_img_srcs($html));
});

/* ============================================================
 * error_markers — a rendered PHP error is a regression.
 * ============================================================ */

it('error_markers detects Fatal error', function () {
    assert_true(in_array('fatal_error', wpultra_vdiff_error_markers('<b>Fatal error</b>: boom'), true));
});

it('error_markers detects Warning: and Notice:', function () {
    $m = wpultra_vdiff_error_markers('Warning: something. Notice: else.');
    assert_true(in_array('php_warning', $m, true));
    assert_true(in_array('php_notice', $m, true));
});

it('error_markers detects stack trace and Uncaught', function () {
    $m = wpultra_vdiff_error_markers('Uncaught Error: x in file Stack trace: #0 ...');
    assert_true(in_array('uncaught', $m, true));
    assert_true(in_array('stack_trace', $m, true));
});

it('error_markers detects WSOD critical error and DB error', function () {
    assert_true(in_array('wsod', wpultra_vdiff_error_markers('There has been a critical error on this website.'), true));
    assert_true(in_array('db_error', wpultra_vdiff_error_markers('Error establishing a database connection'), true));
});

it('error_markers empty on clean page', function () {
    assert_eq([], wpultra_vdiff_error_markers('<html><body><h1>All good</h1></body></html>'));
});

/* ============================================================
 * fingerprint — all fields.
 * ============================================================ */

it('fingerprint captures counts, title, headings, hashes', function () {
    $html = '<html><head><title>T</title></head><body>'
        . '<h1>H</h1><h2>A</h2><h2>B</h2>'
        . '<img src="1.png"><img src="2.png">'
        . '<a href="/x">l1</a><a href="/y">l2</a>'
        . '<script>1</script><form></form>'
        . '<p>hello world</p></body></html>';
    $fp = wpultra_vdiff_fingerprint($html, 200);
    assert_eq(200, $fp['status']);
    assert_eq('T', $fp['title']);
    assert_eq(['H'], $fp['h1s']);
    assert_eq(['A', 'B'], $fp['h2s']);
    assert_eq(2, $fp['img_count']);
    assert_eq(['1.png', '2.png'], $fp['img_srcs']);
    assert_eq(2, $fp['link_count']);
    assert_eq(1, $fp['script_count']);
    assert_eq(1, $fp['form_count']);
    assert_true($fp['byte_size'] === strlen($html), 'byte_size = raw length');
    assert_true(is_string($fp['text_hash']) && strlen($fp['text_hash']) === 32, 'text_hash is md5');
    assert_true(is_string($fp['dom_skeleton_hash']) && strlen($fp['dom_skeleton_hash']) === 32, 'skeleton hash is md5');
    assert_eq([], $fp['error_markers']);
});

it('fingerprint records error markers when the body has a rendered error', function () {
    $fp = wpultra_vdiff_fingerprint('<body>Fatal error: Call to undefined function foo()</body>', 500);
    assert_true(in_array('fatal_error', $fp['error_markers'], true));
    assert_eq(500, $fp['status']);
});

it('fingerprint img_srcs cap at 100', function () {
    $html = str_repeat('<img src="x.png">', 130);
    $fp = wpultra_vdiff_fingerprint($html);
    assert_eq(130, $fp['img_count']);
    assert_eq(100, count($fp['img_srcs']));
});

it('fingerprint text_hash stable across whitespace in identical content', function () {
    $a = wpultra_vdiff_fingerprint('<p>same   text</p>');
    $b = wpultra_vdiff_fingerprint("<p>same\ntext</p>");
    assert_eq($a['text_hash'], $b['text_hash']);
});

/* ============================================================
 * pct_delta — incl. zero-before guard.
 * ============================================================ */

it('pct_delta computes signed percent', function () {
    assert_eq(-40.0, wpultra_vdiff_pct_delta(10, 6));
    assert_eq(50.0, wpultra_vdiff_pct_delta(10, 15));
});

it('pct_delta zero-before guard: 0->0 is 0, 0->n is 100', function () {
    assert_eq(0.0, wpultra_vdiff_pct_delta(0, 0));
    assert_eq(100.0, wpultra_vdiff_pct_delta(0, 5));
});

it('pct_delta identical is 0', function () {
    assert_eq(0.0, wpultra_vdiff_pct_delta(42, 42));
});

/* ============================================================
 * severity matrix (pure function).
 * ============================================================ */

it('severity none when no diffs', function () {
    assert_eq('none', wpultra_vdiff_severity([], []));
});

it('severity critical on new errors regardless of diffs', function () {
    assert_eq('critical', wpultra_vdiff_severity([], ['fatal_error']));
    assert_eq('critical', wpultra_vdiff_severity([['field' => 'text_hash']], ['php_warning']));
});

it('severity major on status/title/h1/skeleton', function () {
    assert_eq('major', wpultra_vdiff_severity([['field' => 'status']], []));
    assert_eq('major', wpultra_vdiff_severity([['field' => 'title']], []));
    assert_eq('major', wpultra_vdiff_severity([['field' => 'h1s']], []));
    assert_eq('major', wpultra_vdiff_severity([['field' => 'dom_skeleton_hash']], []));
});

it('severity major on img_count drop >30%, minor on smaller/positive change', function () {
    assert_eq('major', wpultra_vdiff_severity([['field' => 'img_count', 'delta' => -40.0]], []));
    assert_eq('minor', wpultra_vdiff_severity([['field' => 'img_count', 'delta' => -10.0]], []));
    assert_eq('minor', wpultra_vdiff_severity([['field' => 'img_count', 'delta' => 25.0]], []));
});

it('severity minor on text/h2/byte changes', function () {
    assert_eq('minor', wpultra_vdiff_severity([['field' => 'text_hash']], []));
    assert_eq('minor', wpultra_vdiff_severity([['field' => 'h2s']], []));
    assert_eq('minor', wpultra_vdiff_severity([['field' => 'byte_size']], []));
});

it('severity takes the max across mixed diffs', function () {
    $diffs = [['field' => 'text_hash'], ['field' => 'title'], ['field' => 'h2s']];
    assert_eq('major', wpultra_vdiff_severity($diffs, []));
});

/* ============================================================
 * compare — end-to-end fingerprint diffs.
 * ============================================================ */

it('compare identical -> none, not changed', function () {
    $fp = wpultra_vdiff_fingerprint('<html><title>T</title><body><h1>H</h1><p>text</p></body></html>', 200);
    $r = wpultra_vdiff_compare($fp, $fp);
    assert_true($r['changed'] === false, 'not changed');
    assert_eq('none', $r['severity']);
    assert_eq([], $r['diffs']);
    assert_eq([], $r['new_errors']);
});

it('compare added error marker -> critical + new_errors populated', function () {
    $before = wpultra_vdiff_fingerprint('<html><title>T</title><body><h1>H</h1></body></html>', 200);
    $after  = wpultra_vdiff_fingerprint('<html><title>T</title><body><h1>H</h1>Fatal error: boom</body></html>', 200);
    $r = wpultra_vdiff_compare($before, $after);
    assert_eq('critical', $r['severity']);
    assert_true(in_array('fatal_error', $r['new_errors'], true));
});

it('compare h1 removed -> major', function () {
    $before = wpultra_vdiff_fingerprint('<html><title>T</title><body><h1>Head</h1><p>x</p></body></html>', 200);
    $after  = wpultra_vdiff_fingerprint('<html><title>T</title><body><p>x</p></body></html>', 200);
    $r = wpultra_vdiff_compare($before, $after);
    assert_eq('major', $r['severity']);
    $fields = array_column($r['diffs'], 'field');
    assert_true(in_array('h1s', $fields, true), 'h1s diffed');
});

it('compare image drop 40% -> major', function () {
    $before = wpultra_vdiff_fingerprint(str_repeat('<img src="a.png">', 10) . '<title>T</title>', 200);
    $after  = wpultra_vdiff_fingerprint(str_repeat('<img src="a.png">', 6) . '<title>T</title>', 200);
    $r = wpultra_vdiff_compare($before, $after);
    assert_eq('major', $r['severity']);
    $img = null;
    foreach ($r['diffs'] as $d) { if ($d['field'] === 'img_count') { $img = $d; } }
    assert_true($img !== null, 'img_count diff present');
    assert_eq(-40.0, $img['delta']);
});

it('compare text change only -> minor', function () {
    $before = wpultra_vdiff_fingerprint('<html><title>T</title><body><h1>H</h1><p>old text</p></body></html>', 200);
    $after  = wpultra_vdiff_fingerprint('<html><title>T</title><body><h1>H</h1><p>new text</p></body></html>', 200);
    $r = wpultra_vdiff_compare($before, $after);
    assert_eq('minor', $r['severity']);
    assert_true($r['changed'] === true, 'changed');
});

it('compare status 500 -> at least major', function () {
    $before = wpultra_vdiff_fingerprint('<html><title>T</title><body><h1>H</h1></body></html>', 200);
    $after  = wpultra_vdiff_fingerprint('<html><title>T</title><body><h1>H</h1></body></html>', 500);
    $r = wpultra_vdiff_compare($before, $after);
    $rank = ['none' => 0, 'minor' => 1, 'major' => 2, 'critical' => 3];
    assert_true($rank[$r['severity']] >= $rank['major'], 'status 500 is major+');
    $fields = array_column($r['diffs'], 'field');
    assert_true(in_array('status', $fields, true), 'status diffed');
});

it('compare persistent non-2xx flagged even when status unchanged', function () {
    $fp = wpultra_vdiff_fingerprint('<html><title>T</title><body><h1>H</h1></body></html>', 503);
    $r = wpultra_vdiff_compare($fp, $fp);
    $fields = array_column($r['diffs'], 'field');
    assert_true(in_array('status', $fields, true), 'persistent 503 surfaced');
});

it('compare title change -> major', function () {
    $before = wpultra_vdiff_fingerprint('<title>Old Title</title><h1>H</h1>', 200);
    $after  = wpultra_vdiff_fingerprint('<title>New Title</title><h1>H</h1>', 200);
    $r = wpultra_vdiff_compare($before, $after);
    assert_eq('major', $r['severity']);
});

it('compare skeleton change (section removed) -> major', function () {
    $before = wpultra_vdiff_fingerprint('<body><section><h1>H</h1></section><section><p>x</p></section></body>', 200);
    $after  = wpultra_vdiff_fingerprint('<body><section><h1>H</h1></section></body>', 200);
    $r = wpultra_vdiff_compare($before, $after);
    $rank = ['none' => 0, 'minor' => 1, 'major' => 2, 'critical' => 3];
    assert_true($rank[$r['severity']] >= $rank['major'], 'dropped section is major+');
    $fields = array_column($r['diffs'], 'field');
    assert_true(in_array('dom_skeleton_hash', $fields, true), 'skeleton diffed');
});

run_tests();
