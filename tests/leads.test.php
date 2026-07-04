<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';

if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/wpultra_leads/'); }
// helpers.php provides wpultra_err / wpultra_ok (uses WP_Error stub from harness).
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/marketing/leads.php';

/* ============================================================
 * default stages
 * ============================================================ */

it('default_stages returns the canonical funnel in order', function () {
    assert_eq(['new', 'contacted', 'qualified', 'won', 'lost'], wpultra_leads_default_stages());
});

/* ============================================================
 * extract — field-mapping heuristics
 * ============================================================ */

it('extract finds email under a key named your-email (CF7 style)', function () {
    $x = wpultra_leads_extract(['your-name' => 'Rahim', 'your-email' => 'rahim@example.com']);
    assert_eq('rahim@example.com', $x['email']);
    assert_eq('Rahim', $x['name']);
});

it('extract prefers an email-named key over an earlier regex match elsewhere', function () {
    $x = wpultra_leads_extract([
        'message' => 'please cc boss@corp.com on this',
        'Email'   => 'me@example.com',
    ]);
    assert_eq('me@example.com', $x['email']);
});

it('extract falls back to the first value matching the email regex', function () {
    $x = wpultra_leads_extract(['field_1' => 'hello', 'field_2' => 'contact me at karim@test.org please']);
    assert_eq('karim@test.org', $x['email']);
});

it('extract with an email key holding a non-email falls through to regex scan', function () {
    $x = wpultra_leads_extract(['email' => 'not-an-address', 'other' => 'real@example.net']);
    assert_eq('real@example.net', $x['email']);
});

it('extract returns empty email when nothing matches', function () {
    $x = wpultra_leads_extract(['name' => 'Rahim', 'message' => 'call me']);
    assert_eq('', $x['email']);
    assert_eq('Rahim', $x['name']);
});

it('extract passes a Bengali/unicode name through untouched', function () {
    $x = wpultra_leads_extract(['your-name' => 'রহিম উদ্দিন', 'your-email' => 'r@example.com']);
    assert_eq('রহিম উদ্দিন', $x['name']);
});

it('extract finds phone via phone- and tel-named keys', function () {
    assert_eq('01711-000000', wpultra_leads_extract(['phone_number' => '01711-000000'])['phone']);
    assert_eq('+8801700000000', wpultra_leads_extract(['your-tel' => '+8801700000000'])['phone']);
});

it('extract does not treat an email-named key as a name', function () {
    $x = wpultra_leads_extract(['email-name' => 'x@y.co', 'full name' => 'Karim']);
    assert_eq('Karim', $x['name']);
});

it('extract ignores empty values when picking name/phone', function () {
    $x = wpultra_leads_extract(['name' => '  ', 'last-name' => 'Sultana', 'phone' => '', 'telephone' => '123']);
    assert_eq('Sultana', $x['name']);
    assert_eq('123', $x['phone']);
});

/* ============================================================
 * flatten_fields — hostile payload normalization
 * ============================================================ */

it('flatten_fields casts scalars, joins arrays, drops objects', function () {
    $out = wpultra_leads_flatten_fields([
        'a' => 5,
        'b' => ['x', 'y', ['nested-dropped']],
        'c' => new stdClass(),
        'd' => ' padded ',
    ]);
    assert_eq(['a' => '5', 'b' => 'x, y', 'd' => 'padded'], $out);
});

it('flatten_fields caps keys and value length', function () {
    $raw = [];
    for ($i = 0; $i < 60; $i++) { $raw["k$i"] = str_repeat('x', 600); }
    $out = wpultra_leads_flatten_fields($raw);
    assert_eq(40, count($out));
    assert_eq(500, strlen($out['k0']));
});

/* ============================================================
 * validate
 * ============================================================ */

it('validate passes with only an email', function () {
    assert_eq(true, wpultra_leads_validate(['email' => 'a@b.co'], wpultra_leads_default_stages()));
});

it('validate passes with only a name', function () {
    assert_eq(true, wpultra_leads_validate(['name' => 'Rahim'], wpultra_leads_default_stages()));
});

it('validate fails when both name and email are missing', function () {
    $r = wpultra_leads_validate(['phone' => '123'], wpultra_leads_default_stages());
    assert_true(is_string($r), 'error string expected');
});

it('validate rejects a malformed email', function () {
    $r = wpultra_leads_validate(['email' => 'not-an-email'], wpultra_leads_default_stages());
    assert_true(is_string($r) && str_contains($r, 'email'), "got: " . var_export($r, true));
});

it('validate rejects a stage outside the configured list', function () {
    $r = wpultra_leads_validate(['name' => 'X', 'stage' => 'signed'], ['new', 'won']);
    assert_true(is_string($r) && str_contains($r, 'signed'));
});

it('validate accepts a stage from a CUSTOM configured list', function () {
    assert_eq(true, wpultra_leads_validate(['name' => 'X', 'stage' => 'proposal'], ['lead', 'proposal', 'closed']));
});

it('validate rejects negative and non-numeric value, accepts numeric string', function () {
    $stages = wpultra_leads_default_stages();
    assert_true(is_string(wpultra_leads_validate(['name' => 'X', 'value' => -1], $stages)), 'negative rejected');
    assert_true(is_string(wpultra_leads_validate(['name' => 'X', 'value' => 'abc'], $stages)), 'non-numeric rejected');
    assert_eq(true, wpultra_leads_validate(['name' => 'X', 'value' => '49.99'], $stages));
    assert_eq(true, wpultra_leads_validate(['name' => 'X', 'value' => 0], $stages));
});

/* ============================================================
 * note_push — chronological, capped
 * ============================================================ */

it('note_push appends newest LAST with the given timestamp', function () {
    $notes = wpultra_leads_note_push([], 'first', 100);
    $notes = wpultra_leads_note_push($notes, 'second', 200);
    assert_eq(2, count($notes));
    assert_eq(['at' => 100, 'text' => 'first'], $notes[0]);
    assert_eq(['at' => 200, 'text' => 'second'], $notes[1]);
});

it('note_push drops the OLDEST notes past the cap', function () {
    $notes = [];
    for ($i = 1; $i <= 5; $i++) { $notes = wpultra_leads_note_push($notes, "n$i", $i, 3); }
    assert_eq(3, count($notes));
    assert_eq('n3', $notes[0]['text']);
    assert_eq('n5', $notes[2]['text']);
});

/* ============================================================
 * csv — RFC-4180 + formula-injection guard
 * ============================================================ */

it('csv emits the fixed header row and CRLF line endings', function () {
    $csv = wpultra_leads_csv([]);
    assert_eq("id,name,email,phone,stage,value,source,tags,created_at,notes_count\r\n", $csv);
});

it('csv quotes cells containing commas, quotes and newlines', function () {
    $csv = wpultra_leads_csv([[
        'id' => 1, 'name' => 'Doe, John', 'email' => 'j@x.co', 'phone' => '',
        'stage' => 'new', 'value' => 10, 'source' => "line1\nline2", 'tags' => [],
        'created_at' => '', 'notes_count' => 0,
    ]]);
    assert_contains('"Doe, John"', $csv);
    assert_contains("\"line1\nline2\"", $csv);
    $csv2 = wpultra_leads_csv([['id' => 2, 'name' => 'She said "hi"', 'notes_count' => 0]]);
    assert_contains('"She said ""hi"""', $csv2);
});

it('csv guards spreadsheet formula injection (= + - @ prefixes)', function () {
    foreach (['=cmd()', '+SUM(A1)', '-2+3', '@evil'] as $payload) {
        $csv = wpultra_leads_csv([['id' => 1, 'name' => $payload]]);
        assert_contains("'" . $payload, $csv, "payload $payload escaped");
    }
    // Plain text is NOT prefixed.
    assert_true(!str_contains(wpultra_leads_csv([['id' => 1, 'name' => 'Rahim']]), "'Rahim"));
});

it('csv joins tags with | and fills missing columns as empty', function () {
    $csv = wpultra_leads_csv([['id' => 7, 'tags' => ['a', 'b']]]);
    $lines = explode("\r\n", trim($csv));
    assert_eq('7,,,,,,,a|b,,', $lines[1]);
});

/* ============================================================
 * stats — pipeline overview
 * ============================================================ */

it('stats counts and sums value per stage plus totals', function () {
    $s = wpultra_leads_stats([
        ['stage' => 'new', 'value' => 100],
        ['stage' => 'new', 'value' => '50.5'],
        ['stage' => 'won', 'value' => 1000],
    ], ['new', 'won', 'lost']);
    assert_eq(2, $s['stages']['new']['count']);
    assert_eq(150.5, $s['stages']['new']['value']);
    assert_eq(1, $s['stages']['won']['count']);
    assert_eq(0, $s['stages']['lost']['count']);
    assert_eq(3, $s['total_count']);
    assert_eq(1150.5, $s['total_value']);
    assert_true(!isset($s['other']), 'no other bucket when all stages known');
});

it('stats routes unknown stages to an other bucket', function () {
    $s = wpultra_leads_stats([
        ['stage' => 'zombie', 'value' => 9],
        ['stage' => 'new', 'value' => 1],
    ], ['new']);
    assert_eq(1, $s['other']['count']);
    assert_eq(9.0, $s['other']['value']);
    assert_eq(2, $s['total_count']);
});

it('stats tolerates junk entries and missing values', function () {
    $s = wpultra_leads_stats([['stage' => 'new'], 'garbage', ['value' => 'NaN', 'stage' => 'new']], ['new']);
    assert_eq(2, $s['stages']['new']['count']);
    assert_eq(0.0, $s['stages']['new']['value']);
});

/* ============================================================
 * shape — output contract
 * ============================================================ */

it('shape trims notes to the last 5 in list mode, keeps all in full mode', function () {
    $notes = [];
    for ($i = 1; $i <= 8; $i++) { $notes[] = ['at' => $i, 'text' => "n$i"]; }
    $meta = ['name' => 'X', 'notes' => $notes];
    $short = wpultra_leads_shape($meta, 3, false);
    $full  = wpultra_leads_shape($meta, 3, true);
    assert_eq(5, count($short['notes']));
    assert_eq('n4', $short['notes'][0]['text']); // last 5, chronological
    assert_eq('n8', $short['notes'][4]['text']);
    assert_eq(8, count($full['notes']));
    assert_eq(8, $short['notes_count']);
    assert_eq(3, $short['id']);
});

it('shape supplies safe defaults for a sparse meta blob', function () {
    $s = wpultra_leads_shape([], 9);
    assert_eq(9, $s['id']);
    assert_eq('', $s['name']);
    assert_eq('', $s['email']);
    assert_eq(0.0, $s['value']);
    assert_eq([], $s['tags']);
    assert_eq([], $s['notes']);
    assert_eq(0, $s['notes_count']);
    assert_eq(0, $s['created_at']);
});

it('shape casts value to float and tags to a string list', function () {
    $s = wpultra_leads_shape(['value' => '12.5', 'tags' => ['a', 5]], 1);
    assert_eq(12.5, $s['value']);
    assert_eq(['a', '5'], $s['tags']);
});

/* ============================================================
 * new_meta
 * ============================================================ */

it('new_meta builds a complete blob with lowercased email and given stage', function () {
    $m = wpultra_leads_new_meta(' Rahim@Example.COM ', ' Rahim ', '017', 'form:cf7:5', 12345, 'contacted');
    assert_eq('rahim@example.com', $m['email']);
    assert_eq('Rahim', $m['name']);
    assert_eq('017', $m['phone']);
    assert_eq('form:cf7:5', $m['source']);
    assert_eq('contacted', $m['stage']);
    assert_eq(0.0, $m['value']);
    assert_eq([], $m['tags']);
    assert_eq([], $m['notes']);
    assert_eq(12345, $m['created_at']);
    assert_eq(12345, $m['updated_at']);
});

/* ============================================================
 * normalize_tags
 * ============================================================ */

it('normalize_tags trims, dedupes, drops empties and non-arrays', function () {
    assert_eq(['a', 'b'], wpultra_leads_normalize_tags([' a ', 'b', 'a', '', ['x']]));
    assert_eq([], wpultra_leads_normalize_tags('not-an-array'));
});

it('normalize_tags caps at 30 tags and 50 chars each', function () {
    $tags = [];
    for ($i = 0; $i < 40; $i++) { $tags[] = "tag$i"; }
    assert_eq(30, count(wpultra_leads_normalize_tags($tags)));
    $long = wpultra_leads_normalize_tags([str_repeat('z', 80)]);
    assert_eq(50, strlen($long[0]));
});

/* ============================================================
 * filter — list/export selection
 * ============================================================ */

function leads_fixture(): array {
    return [
        ['id' => 1, 'meta' => ['name' => 'Rahim Uddin', 'email' => 'rahim@x.co', 'stage' => 'new', 'source' => 'form:cf7:5']],
        ['id' => 2, 'meta' => ['name' => 'Karim', 'email' => 'karim@y.co', 'stage' => 'won', 'source' => 'manual']],
        ['id' => 3, 'meta' => ['name' => 'Sultana', 'email' => 'S@z.co', 'stage' => 'new', 'source' => 'form:wpforms:9']],
    ];
}

it('filter by exact stage', function () {
    $out = wpultra_leads_filter(leads_fixture(), ['stage' => 'new']);
    assert_eq([1, 3], array_column($out, 'id'));
});

it('filter search matches name or email substring case-insensitively', function () {
    assert_eq([1], array_column(wpultra_leads_filter(leads_fixture(), ['search' => 'RAHIM']), 'id'));
    assert_eq([2], array_column(wpultra_leads_filter(leads_fixture(), ['search' => 'karim@']), 'id'));
    assert_eq([3], array_column(wpultra_leads_filter(leads_fixture(), ['search' => 's@z']), 'id'));
});

it('filter source is a PREFIX match', function () {
    assert_eq([1, 3], array_column(wpultra_leads_filter(leads_fixture(), ['source' => 'form:']), 'id'));
    assert_eq([1], array_column(wpultra_leads_filter(leads_fixture(), ['source' => 'form:cf7']), 'id'));
    assert_eq([2], array_column(wpultra_leads_filter(leads_fixture(), ['source' => 'manual']), 'id'));
});

it('filter combines stage + search + source and preserves order', function () {
    $out = wpultra_leads_filter(leads_fixture(), ['stage' => 'new', 'search' => 'sultana', 'source' => 'form:']);
    assert_eq([3], array_column($out, 'id'));
    assert_eq(leads_fixture(), wpultra_leads_filter(leads_fixture(), []));
});

/* ============================================================
 * email_valid
 * ============================================================ */

it('email_valid accepts normal addresses and rejects junk', function () {
    assert_true(wpultra_leads_email_valid('a.b+tag@sub.example.co'));
    assert_true(!wpultra_leads_email_valid('no-at-sign'));
    assert_true(!wpultra_leads_email_valid('x@no-tld'));
    assert_true(!wpultra_leads_email_valid('with space@x.co'));
});

run_tests();
