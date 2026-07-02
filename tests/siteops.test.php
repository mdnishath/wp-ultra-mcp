<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';

// --- Environment / stubs (mirror abilities-fs.test.php style) ---
if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/wpultra_siteops/'); }
if (!function_exists('wp_register_ability')) { function wp_register_ability($n, $a) { $GLOBALS['__ab'][$n] = $a; } }
if (!function_exists('get_option')) { function get_option($k, $d = false) { return $d; } }
if (!function_exists('update_option')) { function update_option($k, $v, $a = null) { return true; } }
if (!function_exists('delete_option')) { function delete_option($k) { return true; } }
if (!function_exists('current_time')) { function current_time($t, $g = 0) { return gmdate('Y-m-d H:i:s'); } }
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return 0; } }
if (!defined('YEAR_IN_SECONDS')) { define('YEAR_IN_SECONDS', 31536000); }

// maybe_(un)serialize mirror WordPress semantics closely enough for the round-trip test.
if (!function_exists('is_serialized')) {
    function is_serialized($data, $strict = true) {
        if (!is_string($data)) { return false; }
        $data = trim($data);
        if ('N;' === $data) { return true; }
        if (strlen($data) < 4 || ':' !== ($data[1] ?? '')) { return false; }
        return (bool) preg_match('/^[aOsbid]:/', $data);
    }
}
if (!function_exists('maybe_unserialize')) {
    function maybe_unserialize($original) {
        if (is_serialized($original)) {
            $v = @unserialize($original);
            return $v === false && $original !== 'b:0;' ? $original : $v;
        }
        return $original;
    }
}
if (!function_exists('maybe_serialize')) {
    function maybe_serialize($data) {
        if (is_array($data) || is_object($data)) { return serialize($data); }
        if (is_serialized($data, false)) { return serialize($data); }
        return $data;
    }
}

// Requiring siteops.php under the harness must never fatal.
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/system/siteops.php';

/* ============================================================
 * wpultra_sr_replace_value  (serialized-data-safe recursive replace)
 * ============================================================ */

it('sr_replace_value replaces plain strings and counts hits', function () {
    $c = 0;
    $r = wpultra_sr_replace_value('old.example.com/old', 'old', 'new', $c);
    assert_eq('new.example.com/new', $r);
    assert_eq(2, $c);
});

it('sr_replace_value leaves non-matching values untouched', function () {
    $c = 0;
    assert_eq('nothing here', wpultra_sr_replace_value('nothing here', 'zzz', 'q', $c));
    assert_eq(0, $c);
});

it('sr_replace_value passes non-string scalars through unchanged', function () {
    $c = 0;
    assert_eq(42, wpultra_sr_replace_value(42, 'a', 'b', $c));
    assert_eq(3.14, wpultra_sr_replace_value(3.14, 'a', 'b', $c));
    assert_eq(true, wpultra_sr_replace_value(true, 'a', 'b', $c));
    assert_eq(null, wpultra_sr_replace_value(null, 'a', 'b', $c));
    assert_eq(0, $c);
});

it('sr_replace_value recurses into nested arrays (values and keys)', function () {
    $c = 0;
    $in = ['url_old' => ['a' => 'old', 'b' => ['deep' => 'old-old']]];
    $out = wpultra_sr_replace_value($in, 'old', 'new', $c);
    assert_eq('new', $out['url_new']['a']);
    assert_eq('new-new', $out['url_new']['b']['deep']);
    // 'url_old' key (1) + 'old' value (1) + 'old-old' (2) = 4
    assert_eq(4, $c);
});

it('sr_replace_value recurses into stdClass objects', function () {
    $c = 0;
    $o = new stdClass();
    $o->host = 'old.test';
    $o->child = (object) ['inner' => 'keep-old'];
    $out = wpultra_sr_replace_value($o, 'old', 'new', $c);
    assert_true($out instanceof stdClass, 'still object');
    assert_eq('new.test', $out->host);
    assert_eq('keep-new', $out->child->inner);
    assert_eq(2, $c);
});

it('sr_replace_value re-serializes a serialized-string leaf (length-changing) without corruption', function () {
    $c = 0;
    // A nested array whose leaf is ITSELF serialized data — a serialized payload
    // stored inside an option array. The replacement changes length, so a naive
    // str_replace on the leaf would corrupt the inner s:N:"..." prefixes.
    $inner = serialize(['host' => 'a.io', 'note' => 'visit a.io soon']);
    $in    = ['config' => ['payload' => $inner, 'plain' => 'a.io here']];
    $out   = wpultra_sr_replace_value($in, 'a.io', 'longer-domain.example', $c);

    // The plain sibling leaf is replaced normally.
    assert_eq('longer-domain.example here', $out['config']['plain']);
    // The serialized leaf stays valid serialized data with the replacement applied.
    $leaf = $out['config']['payload'];
    assert_true(is_serialized($leaf), 'leaf is still valid serialized data');
    $decoded = unserialize($leaf);
    assert_eq('longer-domain.example', $decoded['host']);
    assert_eq('visit longer-domain.example soon', $decoded['note']);
    // 2 hits inside the serialized leaf + 1 in the plain sibling.
    assert_eq(3, $c);
});

it('sr_replace_value leaves a corrupted/unserializable serialized-looking string untouched', function () {
    $c = 0;
    // Looks serialized (passes is_serialized) but the length prefix is wrong, so
    // unserialize() fails — must be left as-is rather than mangled.
    $broken = 'a:1:{s:3:"key";s:99:"old";}';
    assert_true(is_serialized($broken), 'precondition: looks serialized');
    $out = wpultra_sr_replace_value(['x' => $broken], 'old', 'new', $c);
    assert_eq($broken, $out['x']);
    assert_eq(0, $c);
});

/* ============================================================
 * wpultra_sr_replace_column  (maybe_serialize round-trip write path)
 * ============================================================ */

it('sr_replace_column round-trips a serialized array with corrected lengths', function () {
    $raw = serialize(['siteurl' => 'http://old.test', 'name' => 'old blog']);
    [$new, $hits] = wpultra_sr_replace_column($raw, 'old', 'new', $hits2 = 0);
    assert_eq(2, $hits);
    // Must still be valid serialized data with the new (longer) string preserved.
    $decoded = unserialize($new);
    assert_eq('http://new.test', $decoded['siteurl']);
    assert_eq('new blog', $decoded['name']);
});

it('sr_replace_column handles a length-changing replacement without corruption', function () {
    // 'a.io' (4) -> 'longer-domain.example' — naive str_replace would break s:N: prefixes.
    $raw = serialize(['url' => 'https://a.io/path']);
    [$new, $hits] = wpultra_sr_replace_column($raw, 'a.io', 'longer-domain.example', 0);
    assert_eq(1, $hits);
    $decoded = unserialize($new);
    assert_eq('https://longer-domain.example/path', $decoded['url']);
});

it('sr_replace_column returns the raw string unchanged when nothing matches', function () {
    $raw = serialize(['x' => 'zzz']);
    [$new, $hits] = wpultra_sr_replace_column($raw, 'nomatch', 'q', 0);
    assert_eq(0, $hits);
    assert_eq($raw, $new);
});

it('sr_replace_column handles a plain (non-serialized) string column', function () {
    [$new, $hits] = wpultra_sr_replace_column('old and old again', 'old', 'new', 0);
    assert_eq(2, $hits);
    assert_eq('new and new again', $new);
});

/* ============================================================
 * wpultra_siteops_split_sql  (quote/backtick-aware statement splitter)
 * ============================================================ */

it('split_sql splits simple statements on trailing semicolons', function () {
    $out = wpultra_siteops_split_sql("SELECT 1;\nSELECT 2;\nSELECT 3;");
    assert_eq(3, count($out));
    assert_eq('SELECT 1', $out[0]);
    assert_eq('SELECT 3', $out[2]);
});

it('split_sql ignores semicolons inside single-quoted string literals', function () {
    $sql = "INSERT INTO t VALUES ('a; b; c');\nSELECT 2;";
    $out = wpultra_siteops_split_sql($sql);
    assert_eq(2, count($out));
    assert_contains("'a; b; c'", $out[0]);
});

it('split_sql handles backslash-escaped and doubled quotes inside strings', function () {
    // A backslash-escaped quote and a doubled '' escape, both containing semicolons.
    $sql = "INSERT INTO t VALUES ('it\\'s; fine', 'two '';'' quotes'); SELECT 9;";
    $out = wpultra_siteops_split_sql($sql);
    assert_eq(2, count($out));
    assert_contains("it\\'s; fine", $out[0]);
    assert_contains("two '';'' quotes", $out[0]);
    assert_eq('SELECT 9', $out[1]);
});

it('split_sql ignores semicolons inside backtick identifiers', function () {
    $sql = "CREATE TABLE `weird;name` (id INT); SELECT 1;";
    $out = wpultra_siteops_split_sql($sql);
    assert_eq(2, count($out));
    assert_contains('`weird;name`', $out[0]);
});

it('split_sql keeps a trailing statement without a terminating semicolon', function () {
    $out = wpultra_siteops_split_sql("SELECT 1;\nSELECT 2");
    assert_eq(2, count($out));
    assert_eq('SELECT 2', $out[1]);
});

it('split_sql drops empty statements from stray semicolons', function () {
    $out = wpultra_siteops_split_sql("SELECT 1;;;\n;SELECT 2;");
    assert_eq(2, count($out));
});

/* ============================================================
 * wpultra_siteops_shape_cron  (cron array flattener)
 * ============================================================ */

it('shape_cron flattens the _get_cron_array() structure into a flat event list', function () {
    $ts1 = 1700000000;
    $ts2 = 1700003600;
    $cron = [
        $ts1 => [
            'wp_scheduled_delete' => [
                'md5sig1' => ['schedule' => 'daily', 'args' => [], 'interval' => 86400],
            ],
        ],
        $ts2 => [
            'my_custom_hook' => [
                'md5sig2' => ['schedule' => false, 'args' => ['foo', 42]],
            ],
        ],
        'version' => 2, // WP stores a non-timestamp 'version' key — must be ignored.
    ];
    $out = wpultra_siteops_shape_cron($cron);
    assert_eq(2, count($out));
    // Sorted by timestamp ascending.
    assert_eq('wp_scheduled_delete', $out[0]['hook']);
    assert_eq('daily', $out[0]['schedule']);
    assert_eq(86400, $out[0]['interval']);
    assert_eq($ts1, $out[0]['timestamp']);
    assert_eq(gmdate('c', $ts1), $out[0]['next_run']);
    // schedule:false becomes null (one-off event).
    assert_eq(null, $out[1]['schedule']);
    assert_eq(['foo', 42], $out[1]['args']);
});

it('shape_cron returns an empty list for an empty cron array', function () {
    assert_eq([], wpultra_siteops_shape_cron([]));
});

/* ============================================================
 * site-health shaping
 * ============================================================ */

it('shape_health_test normalizes status and label', function () {
    $r = wpultra_siteops_shape_health_test('https_status', ['status' => 'critical', 'label' => 'Not HTTPS']);
    assert_eq('critical', $r['status']);
    assert_eq('Not HTTPS', $r['label']);
    // Unknown status falls back to 'recommended'.
    $r2 = wpultra_siteops_shape_health_test('x', ['status' => 'weird']);
    assert_eq('recommended', $r2['status']);
    assert_eq('x', $r2['label']);
});

it('health_critical_count counts only critical rows', function () {
    $tests = [
        ['status' => 'good'],
        ['status' => 'critical'],
        ['status' => 'recommended'],
        ['status' => 'critical'],
    ];
    assert_eq(2, wpultra_siteops_health_critical_count($tests));
});

/* ============================================================
 * maintenance-mode + snapshot-name pure helpers
 * ============================================================ */

it('maintenance persistent timestamp is far in the future', function () {
    $ts = wpultra_siteops_maintenance_persistent_ts();
    assert_true($ts > time() + 5 * YEAR_IN_SECONDS, 'far future so time()-upgrading stays under 600');
});

it('snapshot_name sanitizes to a safe filename stem', function () {
    assert_eq('my-snap-1', wpultra_siteops_snapshot_name('my snap/1'));
    assert_eq('keep_underscore', wpultra_siteops_snapshot_name('keep_underscore'));
    assert_eq('a--b', wpultra_siteops_snapshot_name('a..b'));
    assert_eq('lead-trail', wpultra_siteops_snapshot_name('--lead-trail--'));
    assert_true(str_starts_with(wpultra_siteops_snapshot_name('///'), 'snapshot-'), 'empty -> generated');
});

it('sr_default_tables and column map are wired', function () {
    assert_eq(['posts', 'postmeta', 'options'], wpultra_siteops_sr_default_tables());
    $spec = wpultra_siteops_sr_table_columns('posts');
    assert_eq('ID', $spec['id']);
    assert_true(in_array('post_content', $spec['cols'], true));
    assert_eq([], wpultra_siteops_sr_table_columns('unknown_table'));
});

run_tests();
