<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/system/optimize.php';

// ---------------------------------------------------------------------------
// valid_tasks — filter to known ids, preserve order, de-dup, drop unknown
// ---------------------------------------------------------------------------

it('valid_tasks keeps only known task ids in request order', function () {
    $out = wpultra_optimize_valid_tasks(['optimize_tables', 'revisions', 'bogus', 'spam_comments']);
    assert_eq(['optimize_tables', 'revisions', 'spam_comments'], $out);
});

it('valid_tasks de-duplicates and drops non-strings', function () {
    $out = wpultra_optimize_valid_tasks(['revisions', 'revisions', 5, null, 'auto_drafts']);
    assert_eq(['revisions', 'auto_drafts'], $out);
});

it('valid_tasks on empty / all-unknown returns empty list', function () {
    assert_eq([], wpultra_optimize_valid_tasks([]));
    assert_eq([], wpultra_optimize_valid_tasks(['nope', 'zzz']));
});

it('default_tasks are all valid known tasks and exclude the riskier ones', function () {
    $defaults = wpultra_optimize_default_tasks();
    assert_eq($defaults, wpultra_optimize_valid_tasks($defaults));
    // trashed_comments and orphan_termmeta are known but not in the safe default set.
    assert_true(!in_array('trashed_comments', $defaults, true));
    assert_true(!in_array('orphan_termmeta', $defaults, true));
});

// ---------------------------------------------------------------------------
// revisions_sql — keep-last-N subquery composition
// ---------------------------------------------------------------------------

it('revisions_sql with keep<=0 deletes all revisions (simple form, no subquery)', function () {
    $sql = wpultra_optimize_revisions_sql('wp_posts', 'wp_postmeta', 0);
    assert_contains("DELETE FROM `wp_posts`", $sql);
    assert_contains("post_type = 'revision'", $sql);
    assert_true(strpos($sql, 'SELECT') === false, 'keep=0 form has no subquery');
});

it('revisions_sql with keep>0 builds a keep-last-N subquery per post_parent', function () {
    $sql = wpultra_optimize_revisions_sql('wp_posts', 'wp_postmeta', 5);
    // deletes from posts aliased r, excluding a keep-set
    assert_contains('DELETE r FROM `wp_posts` r', $sql);
    assert_contains('NOT IN', $sql);
    // rank is per post_parent, keeping the newest (highest ID) rows
    assert_contains('k2.post_parent = k.post_parent', $sql);
    assert_contains('k2.ID >= k.ID', $sql);
    // the "<= keep" threshold uses the literal keep value
    assert_contains('<= 5', $sql);
    // uses the passed-in table name throughout
    assert_true(strpos($sql, 'wp_posts') !== false);
});

it('revisions_sql interpolates the provided table names, not a hardcoded prefix', function () {
    $sql = wpultra_optimize_revisions_sql('custom_posts', 'custom_meta', 3);
    assert_contains('custom_posts', $sql);
    assert_contains('<= 3', $sql);
    assert_true(strpos($sql, 'wp_posts') === false);
});

// ---------------------------------------------------------------------------
// pick_images — width/size boundaries, skip already-small, skip non-images
// ---------------------------------------------------------------------------

it('pick_images flags images over max_width', function () {
    $picks = wpultra_optimize_pick_images([
        ['id' => 1, 'width' => 3000, 'filesize' => 10_000, 'mime' => 'image/jpeg'],
    ], 1920, 300);
    assert_eq(1, count($picks));
    assert_eq(1, $picks[0]['id']);
    assert_eq(['width'], $picks[0]['reasons']);
});

it('pick_images flags images over the filesize threshold (KB -> bytes)', function () {
    // 400 KB > 300 KB threshold, but width is fine
    $picks = wpultra_optimize_pick_images([
        ['id' => 2, 'width' => 800, 'filesize' => 400 * 1024, 'mime' => 'image/png'],
    ], 1920, 300);
    assert_eq(1, count($picks));
    assert_eq(['filesize'], $picks[0]['reasons']);
});

it('pick_images reports both reasons when width AND size exceed', function () {
    $picks = wpultra_optimize_pick_images([
        ['id' => 3, 'width' => 4000, 'filesize' => 900 * 1024, 'mime' => 'image/jpeg'],
    ], 1920, 300);
    assert_eq(['width', 'filesize'], $picks[0]['reasons']);
});

it('pick_images skips already-small images (boundary: exactly at limit is kept)', function () {
    // width == max_width and filesize == threshold: NOT over, so skipped
    $picks = wpultra_optimize_pick_images([
        ['id' => 4, 'width' => 1920, 'filesize' => 300 * 1024, 'mime' => 'image/jpeg'],
        ['id' => 5, 'width' => 1000, 'filesize' => 50 * 1024, 'mime' => 'image/webp'],
    ], 1920, 300);
    assert_eq(0, count($picks));
});

it('pick_images just-over boundary (max_width+1 / threshold+1 byte) is flagged', function () {
    $picks = wpultra_optimize_pick_images([
        ['id' => 6, 'width' => 1921, 'filesize' => 10, 'mime' => 'image/jpeg'],
        ['id' => 7, 'width' => 10,  'filesize' => 300 * 1024 + 1, 'mime' => 'image/jpeg'],
    ], 1920, 300);
    assert_eq(2, count($picks));
});

it('pick_images skips non-image mime types', function () {
    $picks = wpultra_optimize_pick_images([
        ['id' => 8, 'width' => 5000, 'filesize' => 999_999, 'mime' => 'application/pdf'],
    ], 1920, 300);
    assert_eq(0, count($picks));
});

it('pick_images treats missing/blank mime as an image (best-effort)', function () {
    $picks = wpultra_optimize_pick_images([
        ['id' => 9, 'width' => 3000, 'filesize' => 0],
    ], 1920, 300);
    assert_eq(1, count($picks));
});

it('pick_images with threshold_kb=0 ignores the size test but still checks width', function () {
    $picks = wpultra_optimize_pick_images([
        ['id' => 10, 'width' => 1000, 'filesize' => 5_000_000, 'mime' => 'image/jpeg'],
        ['id' => 11, 'width' => 3000, 'filesize' => 10,        'mime' => 'image/jpeg'],
    ], 1920, 0);
    assert_eq(1, count($picks));
    assert_eq(11, $picks[0]['id']);
});

// ---------------------------------------------------------------------------
// summary — totals across a results map
// ---------------------------------------------------------------------------

it('summary sums found/deleted across tasks and counts tasks run', function () {
    $summary = wpultra_optimize_summary([
        'revisions'      => ['found' => 40, 'deleted' => 35],
        'auto_drafts'    => ['found' => 5,  'deleted' => 5],
        'optimize_tables'=> ['found' => 12, 'deleted' => 12],
    ]);
    assert_eq(57, $summary['total_found']);
    assert_eq(52, $summary['total_deleted']);
    assert_eq(3, $summary['tasks_run']);
});

it('summary handles empty map and defensively ignores malformed entries', function () {
    assert_eq(['total_found' => 0, 'total_deleted' => 0, 'tasks_run' => 0], wpultra_optimize_summary([]));
    $summary = wpultra_optimize_summary(['x' => 'not-an-array', 'y' => ['found' => 3, 'deleted' => 1]]);
    assert_eq(3, $summary['total_found']);
    assert_eq(1, $summary['total_deleted']);
    assert_eq(1, $summary['tasks_run']);
});

// ---------------------------------------------------------------------------
// lazyload filter — pure-ish attr augmentation gated on the option flag
// ---------------------------------------------------------------------------

it('lazyload_filter no-ops when the flag option is off', function () {
    // get_option stub returns '' by default (see below) -> flag off
    $attr = ['src' => 'x.jpg'];
    assert_eq($attr, wpultra_optimize_lazyload_filter($attr));
});

it('lazyload_filter adds loading=lazy when the flag is on and none is set', function () {
    $GLOBALS['__opt']['wpultra_perf_lazyload'] = '1';
    $out = wpultra_optimize_lazyload_filter(['src' => 'x.jpg']);
    assert_eq('lazy', $out['loading']);
    // does not clobber an explicit existing loading value
    $out2 = wpultra_optimize_lazyload_filter(['src' => 'x.jpg', 'loading' => 'eager']);
    assert_eq('eager', $out2['loading']);
    $GLOBALS['__opt']['wpultra_perf_lazyload'] = '0';
});

it('lazyload_filter returns non-array input untouched', function () {
    assert_eq('nope', wpultra_optimize_lazyload_filter('nope'));
});

// ---- get_option stub for the lazyload flag tests ----
if (!function_exists('get_option')) {
    function get_option($name, $default = false) {
        return $GLOBALS['__opt'][$name] ?? '';
    }
}

// ---------------------------------------------------------------------------
// rules_sections — detect presets by their body lines (headers are stripped
// by the rules engine's get()), everything unclaimed becomes custom.
// ---------------------------------------------------------------------------

$PRESET_MAP = [
    'security-headers' => [
        '<IfModule mod_headers.c>',
        '    Header always set X-Frame-Options "SAMEORIGIN"',
        '    Header always set X-Content-Type-Options "nosniff"',
        '</IfModule>',
    ],
    'gzip' => [
        '<IfModule mod_deflate.c>',
        '    AddOutputFilterByType DEFLATE text/html',
        '</IfModule>',
    ],
    'disable-indexes' => ['Options -Indexes'],
];

it('rules_sections detects presets from their body lines (no # headers needed)', function () use ($PRESET_MAP) {
    $block = [
        '<IfModule mod_headers.c>',
        '    Header always set X-Frame-Options "SAMEORIGIN"',
        '    Header always set X-Content-Type-Options "nosniff"',
        '</IfModule>',
        '<IfModule mod_deflate.c>',
        '    AddOutputFilterByType DEFLATE text/html',
        '</IfModule>',
    ];
    $s = wpultra_optimize_rules_sections($block, $PRESET_MAP);
    assert_eq(['security-headers', 'gzip'], $s['presets']);
    assert_eq([], $s['custom']);
});

it('rules_sections: a partial preset (some lines missing) is NOT detected; its lines survive as custom', function () use ($PRESET_MAP) {
    $block = [
        '<IfModule mod_headers.c>',
        '    Header always set X-Frame-Options "SAMEORIGIN"',
        // nosniff line missing -> not the full security-headers preset
        '</IfModule>',
    ];
    $s = wpultra_optimize_rules_sections($block, $PRESET_MAP);
    assert_eq([], $s['presets']);
    assert_eq(['Header always set X-Frame-Options "SAMEORIGIN"'], $s['custom']);
});

it('rules_sections collects unclaimed non-structural lines as custom', function () use ($PRESET_MAP) {
    $block = [
        'Options -Indexes',
        'Header set X-Test "1"',
    ];
    $s = wpultra_optimize_rules_sections($block, $PRESET_MAP);
    assert_eq(['disable-indexes'], $s['presets']);
    assert_eq(['Header set X-Test "1"'], $s['custom']);
});

it('rules_sections ignores comments and blank lines, and dedupes custom', function () use ($PRESET_MAP) {
    $block = ['# a comment', '', 'MyDirective on', 'MyDirective on'];
    $s = wpultra_optimize_rules_sections($block, $PRESET_MAP);
    assert_eq([], $s['presets']);
    assert_eq(['MyDirective on'], $s['custom']);
});

it('rules_sections on an empty block returns empty sections', function () use ($PRESET_MAP) {
    assert_eq(['presets' => [], 'custom' => []], wpultra_optimize_rules_sections([], $PRESET_MAP));
});

it('rules_sections matches whitespace-insensitively (block lines may be re-indented)', function () use ($PRESET_MAP) {
    $block = ['Options -Indexes   ']; // trailing spaces
    $s = wpultra_optimize_rules_sections($block, $PRESET_MAP);
    assert_eq(['disable-indexes'], $s['presets']);
});

run_tests();
