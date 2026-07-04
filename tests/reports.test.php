<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';

if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/wpultra_reports/'); }
// helpers.php provides wpultra_err / wpultra_ok (uses WP_Error stub from harness).
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/system/reports.php';

/* ============================================================
 * period — window boundaries + label for each recurrence.
 * ============================================================ */

$NOW = 1700000000; // fixed injected clock

it('period weekly spans 7 days ending at now, labeled weekly', function () use ($NOW) {
    $p = wpultra_reports_period('weekly', $NOW);
    assert_eq($NOW, $p['to']);
    assert_eq($NOW - 7 * 86400, $p['from']);
    assert_eq('weekly', $p['label']);
});

it('period daily spans 1 day', function () use ($NOW) {
    $p = wpultra_reports_period('daily', $NOW);
    assert_eq($NOW - 86400, $p['from']);
    assert_eq('daily', $p['label']);
});

it('period monthly spans 30 days', function () use ($NOW) {
    $p = wpultra_reports_period('monthly', $NOW);
    assert_eq($NOW - 30 * 86400, $p['from']);
    assert_eq('monthly', $p['label']);
});

it('period defaults unknown recurrence to weekly', function () use ($NOW) {
    $p = wpultra_reports_period('bogus', $NOW);
    assert_eq($NOW - 7 * 86400, $p['from']);
    assert_eq('weekly', $p['label']);
});

/* ============================================================
 * subject — "[Site] weekly report — <date>".
 * ============================================================ */

it('subject renders site, label and to-date', function () {
    $report = [
        'site'   => 'My Shop',
        'period' => ['from' => 1699395200, 'to' => 1700000000, 'label' => 'weekly'],
    ];
    $subj = wpultra_reports_subject($report);
    assert_contains('[My Shop]', $subj);
    assert_contains('weekly report', $subj);
    assert_contains(gmdate('Y-m-d', 1700000000), $subj);
});

/* ============================================================
 * render_html — escaping + structure.
 * ============================================================ */

$SAMPLE = [
    'generated_at' => 1700000000,
    'site'         => '<b>Evil</b> "Shop"',
    'period'       => ['from' => 1699395200, 'to' => 1700000000, 'label' => 'weekly'],
    'sections'     => [
        ['key' => 'content', 'title' => 'Content', 'rows' => [['Published posts', 42], ['Draft posts', 3]]],
        ['key' => 'store', 'title' => 'Store', 'rows' => [['Orders', 7]], 'note' => 'note <script>x</script>'],
    ],
];

it('render_html escapes a hostile site name', function () use ($SAMPLE) {
    $html = wpultra_reports_render_html($SAMPLE);
    assert_true(!str_contains($html, '<b>Evil</b>'), 'raw <b> must not survive');
    assert_contains('&lt;b&gt;Evil&lt;/b&gt;', $html);
});

it('render_html escapes hostile section notes', function () use ($SAMPLE) {
    $html = wpultra_reports_render_html($SAMPLE);
    assert_true(!str_contains($html, '<script>x</script>'), 'raw script must not survive');
    assert_contains('&lt;script&gt;', $html);
});

it('render_html contains each section title and its rows', function () use ($SAMPLE) {
    $html = wpultra_reports_render_html($SAMPLE);
    assert_contains('Content', $html);
    assert_contains('Store', $html);
    assert_contains('Published posts', $html);
    assert_contains('42', $html);
    assert_contains('Orders', $html);
});

it('render_html handles a report with no sections gracefully', function () {
    $html = wpultra_reports_render_html([
        'generated_at' => 1700000000,
        'site'         => 'Bare',
        'period'       => ['from' => 0, 'to' => 1700000000, 'label' => 'weekly'],
        'sections'     => [],
    ]);
    assert_contains('No sections available', $html);
});

/* ============================================================
 * render_text — sections present, no HTML tags.
 * ============================================================ */

it('render_text lists section titles and rows without emitting HTML tags', function () {
    // Clean input (no angle brackets in the data itself) so any '<'/'>' would come
    // from the renderer — a plain-text renderer must emit none.
    $report = [
        'generated_at' => 1700000000,
        'site'         => 'My Shop',
        'period'       => ['from' => 1699395200, 'to' => 1700000000, 'label' => 'weekly'],
        'sections'     => [
            ['key' => 'content', 'title' => 'Content', 'rows' => [['Published posts', 42]]],
            ['key' => 'store', 'title' => 'Store', 'rows' => [['Orders', 7]], 'note' => 'net of refunds'],
        ],
    ];
    $text = wpultra_reports_render_text($report);
    assert_contains('CONTENT', $text);
    assert_contains('STORE', $text);
    assert_contains('Published posts: 42', $text);
    assert_true(!str_contains($text, '<'), 'renderer must emit no < tags');
    assert_true(!str_contains($text, '>'), 'renderer must emit no > tags');
});

/* ============================================================
 * delta — up / down / new / removed matched by section+label.
 * ============================================================ */

it('delta reports up and down against a previous report', function () {
    $prev = ['sections' => [['key' => 'content', 'rows' => [['Published posts', 40], ['Draft posts', 5]]]]];
    $curr = ['sections' => [['key' => 'content', 'rows' => [['Published posts', 43], ['Draft posts', 3]]]]];
    $d = wpultra_reports_delta($curr, $prev);
    assert_eq('up', $d['content.Published posts']['dir']);
    assert_eq(3.0, $d['content.Published posts']['delta']);
    assert_eq('down', $d['content.Draft posts']['dir']);
    assert_eq(-2.0, $d['content.Draft posts']['delta']);
});

it('delta marks a row missing in prev as new', function () {
    $prev = ['sections' => [['key' => 'store', 'rows' => []]]];
    $curr = ['sections' => [['key' => 'store', 'rows' => [['Orders', 7]]]]];
    $d = wpultra_reports_delta($curr, $prev);
    assert_eq('new', $d['store.Orders']['dir']);
    assert_eq(null, $d['store.Orders']['from']);
    assert_eq(7.0, $d['store.Orders']['to']);
});

it('delta marks a row missing in curr as removed', function () {
    $prev = ['sections' => [['key' => 'store', 'rows' => [['Orders', 4]]]]];
    $curr = ['sections' => [['key' => 'store', 'rows' => []]]];
    $d = wpultra_reports_delta($curr, $prev);
    assert_eq('removed', $d['store.Orders']['dir']);
    assert_eq(4.0, $d['store.Orders']['from']);
    assert_eq(null, $d['store.Orders']['to']);
});

it('delta ignores non-numeric row values', function () {
    $prev = ['sections' => [['key' => 'health', 'rows' => [['Overall status', 'ok']]]]];
    $curr = ['sections' => [['key' => 'health', 'rows' => [['Overall status', 'warn']]]]];
    $d = wpultra_reports_delta($curr, $prev);
    assert_eq([], $d, 'string-valued rows produce no deltas');
});

it('delta matches rows only within the same section', function () {
    $prev = ['sections' => [['key' => 'content', 'rows' => [['Count', 1]]]]];
    $curr = ['sections' => [['key' => 'store', 'rows' => [['Count', 5]]]]];
    $d = wpultra_reports_delta($curr, $prev);
    assert_eq('new', $d['store.Count']['dir']);
    assert_eq('removed', $d['content.Count']['dir']);
});

/* ============================================================
 * validate — recurrence / recipients / sections / format.
 * ============================================================ */

it('validate accepts a well-formed config', function () {
    $cfg = wpultra_reports_defaults();
    $cfg['recipients'] = ['a@b.com'];
    assert_true(wpultra_reports_validate($cfg) === true, 'valid config passes');
});

it('validate rejects a bad recurrence', function () {
    $cfg = wpultra_reports_defaults();
    $cfg['recurrence'] = 'fortnightly';
    $r = wpultra_reports_validate($cfg);
    assert_true(is_string($r), 'returns an error string');
    assert_contains('recurrence', $r);
});

it('validate rejects an invalid recipient email', function () {
    $cfg = wpultra_reports_defaults();
    $cfg['recipients'] = ['ok@b.com', 'not-an-email'];
    $r = wpultra_reports_validate($cfg);
    assert_true(is_string($r));
    assert_contains('not-an-email', $r);
});

it('validate rejects an empty-string recipient', function () {
    $cfg = wpultra_reports_defaults();
    $cfg['recipients'] = [''];
    assert_true(is_string(wpultra_reports_validate($cfg)));
});

it('validate rejects a bad format', function () {
    $cfg = wpultra_reports_defaults();
    $cfg['format'] = 'pdf';
    $r = wpultra_reports_validate($cfg);
    assert_true(is_string($r));
    assert_contains('format', $r);
});

it('validate rejects a non-array recipients', function () {
    $cfg = wpultra_reports_defaults();
    $cfg['recipients'] = 'a@b.com';
    assert_true(is_string(wpultra_reports_validate($cfg)));
});

it('defaults have weekly recurrence, html format and empty recipients', function () {
    $d = wpultra_reports_defaults();
    assert_eq('weekly', $d['recurrence']);
    assert_eq('html', $d['format']);
    assert_eq([], $d['recipients']);
    assert_true($d['sections']['content'] === true, 'content on by default');
    assert_true($d['sections']['analytics'] === false, 'analytics off by default');
});

/* ============================================================
 * history_push — newest first + cap.
 * ============================================================ */

it('history_push prepends newest and caps at 12', function () {
    $hist = [];
    for ($i = 1; $i <= 15; $i++) {
        $hist = wpultra_reports_history_push($hist, ['ts' => $i]);
    }
    assert_eq(12, count($hist));
    assert_eq(15, $hist[0]['ts'], 'newest first');
    assert_eq(4, $hist[11]['ts'], 'oldest kept is #4 (1-3 dropped)');
});

run_tests();
