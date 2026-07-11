<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/system/dbrepair.php';

/* ------------------------------------------------------------------ *
 * wpultra_dbrepair_like_prefix
 * ------------------------------------------------------------------ */

it('like_prefix escapes underscore and appends wildcard suffix', function () {
    assert_eq('wp\\_%', wpultra_dbrepair_like_prefix('wp_'));
});

it('like_prefix escapes percent signs too', function () {
    assert_eq('wp\\%\\_%', wpultra_dbrepair_like_prefix('wp%_'));
});

it('like_prefix escapes literal backslashes exactly like $wpdb->esc_like (addcslashes contract)', function () {
    // wpdb::esc_like() is literally `addcslashes($text, '_%\\')`; verify our pure mirror
    // matches that exact contract (append the trailing wildcard on top) rather than
    // hand-deriving the escaped string, which is error-prone to eyeball.
    $prefix = 'wp\\_';
    assert_eq(addcslashes($prefix, '_%\\') . '%', wpultra_dbrepair_like_prefix($prefix));
});

it('like_prefix handles a multisite-style numeric prefix', function () {
    assert_eq('wp\\_2\\_%', wpultra_dbrepair_like_prefix('wp_2_'));
});

it('like_prefix with no special chars just appends wildcard', function () {
    assert_eq('wptest%', wpultra_dbrepair_like_prefix('wptest'));
});

/* ------------------------------------------------------------------ *
 * wpultra_dbrepair_parse_check
 * ------------------------------------------------------------------ */

it('parse_check: a clean OK row is status ok', function () {
    $rows = [
        ['Table' => 'wp_posts', 'Op' => 'check', 'Msg_type' => 'status', 'Msg_text' => 'OK'],
    ];
    $r = wpultra_dbrepair_parse_check($rows);
    assert_eq('ok', $r['status']);
    assert_eq(['status: OK'], $r['messages']);
});

it('parse_check: "Table is already up to date" counts as ok', function () {
    $rows = [
        ['Table' => 'wp_posts', 'Op' => 'repair', 'Msg_type' => 'status', 'Msg_text' => 'Table is already up to date'],
    ];
    $r = wpultra_dbrepair_parse_check($rows);
    assert_eq('ok', $r['status']);
});

it('parse_check: MyISAM corrupt table (error row) is status corrupt', function () {
    $rows = [
        ['Table' => 'wp_options', 'Op' => 'check', 'Msg_type' => 'error', 'Msg_text' => "Table './db/wp_options' is marked as crashed and should be repaired"],
        ['Table' => 'wp_options', 'Op' => 'check', 'Msg_type' => 'status', 'Msg_text' => 'Operation failed'],
    ];
    $r = wpultra_dbrepair_parse_check($rows);
    assert_eq('corrupt', $r['status']);
    assert_eq(2, count($r['messages']));
});

it('parse_check: a warning row (without error) is status warning', function () {
    $rows = [
        ['Table' => 'wp_postmeta', 'Op' => 'check', 'Msg_type' => 'warning', 'Msg_text' => 'Table is marked as corrupted (soft)'],
        ['Table' => 'wp_postmeta', 'Op' => 'check', 'Msg_type' => 'status', 'Msg_text' => 'OK'],
    ];
    $r = wpultra_dbrepair_parse_check($rows);
    assert_eq('warning', $r['status']);
});

it('parse_check: error always wins over a warning regardless of row order', function () {
    $rows = [
        ['Table' => 'wp_x', 'Op' => 'check', 'Msg_type' => 'warning', 'Msg_text' => 'minor issue'],
        ['Table' => 'wp_x', 'Op' => 'check', 'Msg_type' => 'error', 'Msg_text' => 'major issue'],
    ];
    $r = wpultra_dbrepair_parse_check($rows);
    assert_eq('corrupt', $r['status']);
});

it('parse_check: InnoDB "note" repair-unsupported row does not flip status to corrupt', function () {
    $rows = [
        ['Table' => 'wp_users', 'Op' => 'repair', 'Msg_type' => 'note', 'Msg_text' => "The storage engine for the table doesn't support repair"],
        ['Table' => 'wp_users', 'Op' => 'repair', 'Msg_type' => 'status', 'Msg_text' => 'OK'],
    ];
    $r = wpultra_dbrepair_parse_check($rows);
    assert_eq('ok', $r['status']);
    assert_true(str_contains($r['messages'][0], "doesn't support repair"), 'expected the note message to be preserved');
});

it('parse_check: empty rows is status ok with no messages', function () {
    $r = wpultra_dbrepair_parse_check([]);
    assert_eq('ok', $r['status']);
    assert_eq([], $r['messages']);
});

it('parse_check: non-array rows in the list are skipped defensively', function () {
    $rows = ['not-an-array', ['Msg_type' => 'status', 'Msg_text' => 'OK']];
    $r = wpultra_dbrepair_parse_check($rows);
    assert_eq('ok', $r['status']);
    assert_eq(1, count($r['messages']));
});

/* ------------------------------------------------------------------ *
 * wpultra_dbrepair_plan
 * ------------------------------------------------------------------ */

it('plan: ok tables need no action when all=false', function () {
    $checked = [
        ['table' => 'wp_posts', 'engine' => 'InnoDB', 'status' => 'ok'],
    ];
    $p = wpultra_dbrepair_plan($checked, false);
    assert_eq([], $p['repair']);
    assert_eq([], $p['skipped_innodb']);
    assert_eq(['wp_posts'], $p['no_action']);
});

it('plan: corrupt MyISAM table is scheduled for repair', function () {
    $checked = [
        ['table' => 'wp_options', 'engine' => 'MyISAM', 'status' => 'corrupt'],
    ];
    $p = wpultra_dbrepair_plan($checked, false);
    assert_eq(['wp_options'], $p['repair']);
    assert_eq([], $p['skipped_innodb']);
    assert_eq([], $p['no_action']);
});

it('plan: corrupt InnoDB table is skipped (unsupported), not repaired', function () {
    $checked = [
        ['table' => 'wp_users', 'engine' => 'InnoDB', 'status' => 'corrupt'],
    ];
    $p = wpultra_dbrepair_plan($checked, false);
    assert_eq([], $p['repair']);
    assert_eq(['wp_users'], $p['skipped_innodb']);
    assert_eq([], $p['no_action']);
});

it('plan: warning-status table is also scheduled for repair', function () {
    $checked = [
        ['table' => 'wp_postmeta', 'engine' => 'MyISAM', 'status' => 'warning'],
    ];
    $p = wpultra_dbrepair_plan($checked, false);
    assert_eq(['wp_postmeta'], $p['repair']);
});

it('plan: all=true forces every non-innodb table into repair even if ok', function () {
    $checked = [
        ['table' => 'wp_posts', 'engine' => 'MyISAM', 'status' => 'ok'],
        ['table' => 'wp_users', 'engine' => 'InnoDB', 'status' => 'ok'],
    ];
    $p = wpultra_dbrepair_plan($checked, true);
    assert_eq(['wp_posts'], $p['repair']);
    assert_eq(['wp_users'], $p['skipped_innodb']);
    assert_eq([], $p['no_action']);
});

it('plan: engine comparison is case-insensitive', function () {
    $checked = [
        ['table' => 'wp_users', 'engine' => 'innodb', 'status' => 'corrupt'],
    ];
    $p = wpultra_dbrepair_plan($checked, false);
    assert_eq(['wp_users'], $p['skipped_innodb']);
});

it('plan: mixed batch sorts each table into the correct bucket', function () {
    $checked = [
        ['table' => 'wp_posts', 'engine' => 'InnoDB', 'status' => 'ok'],
        ['table' => 'wp_options', 'engine' => 'MyISAM', 'status' => 'corrupt'],
        ['table' => 'wp_users', 'engine' => 'InnoDB', 'status' => 'corrupt'],
        ['table' => 'wp_postmeta', 'engine' => 'MyISAM', 'status' => 'warning'],
    ];
    $p = wpultra_dbrepair_plan($checked, false);
    assert_eq(['wp_options', 'wp_postmeta'], $p['repair']);
    assert_eq(['wp_users'], $p['skipped_innodb']);
    assert_eq(['wp_posts'], $p['no_action']);
});

it('plan: entries missing a table name are skipped defensively', function () {
    $checked = [
        ['engine' => 'InnoDB', 'status' => 'corrupt'],
        ['table' => 'wp_posts', 'engine' => 'MyISAM', 'status' => 'ok'],
    ];
    $p = wpultra_dbrepair_plan($checked, false);
    assert_eq(['wp_posts'], $p['no_action']);
    assert_eq([], $p['repair']);
    assert_eq([], $p['skipped_innodb']);
});

run_tests();
