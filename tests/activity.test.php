<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/system/activity.php';

/* ------------------------------------------------------------------ *
 * wpultra_activity_ring_push
 * ------------------------------------------------------------------ */

it('ring_push prepends newest-first', function () {
    $ring = [];
    $ring = wpultra_activity_ring_push($ring, ['n' => 1], 100);
    $ring = wpultra_activity_ring_push($ring, ['n' => 2], 100);
    $ring = wpultra_activity_ring_push($ring, ['n' => 3], 100);
    assert_eq([3, 2, 1], array_map(fn($r) => $r['n'], $ring));
});

it('ring_push caps at the given size, keeping newest', function () {
    $ring = [];
    for ($i = 1; $i <= 5; $i++) { $ring = wpultra_activity_ring_push($ring, ['n' => $i], 3); }
    assert_eq(3, count($ring));
    assert_eq(5, $ring[0]['n']);
    assert_eq(3, $ring[2]['n']);
});

it('ring_push with cap 0 does not trim', function () {
    $ring = [];
    for ($i = 1; $i <= 5; $i++) { $ring = wpultra_activity_ring_push($ring, ['n' => $i], 0); }
    assert_eq(5, count($ring));
});

/* ------------------------------------------------------------------ *
 * wpultra_activity_filter — the pure filter engine matrix.
 * ------------------------------------------------------------------ */

function activity_audit_fixture(): array {
    // Newest-first, as callers pass it (already reversed from storage order).
    return [
        ['ts' => '2026-07-03 10:00:00', 'user' => 1, 'action' => 'content-set', 'summary' => 'updated post 9', 'ok' => true],
        ['ts' => '2026-07-02 09:00:00', 'user' => 2, 'action' => 'execute-php', 'summary' => 'ran script', 'ok' => false],
        ['ts' => '2026-07-01 08:00:00', 'user' => 1, 'action' => 'content-delete', 'summary' => 'deleted post 3', 'ok' => true],
        ['ts' => '2026-06-30 07:00:00', 'user' => 3, 'action' => 'db-query', 'summary' => 'SELECT 1', 'ok' => true],
        ['ts' => '2026-06-29 06:00:00', 'user' => 2, 'action' => 'db-query', 'summary' => 'DELETE bad', 'ok' => false],
    ];
}

function activity_login_fixture(): array {
    return [
        ['user_id' => 1, 'login' => 'alice', 'ip' => '1.1.1.1', 'ts' => '2026-07-03 11:00:00'],
        ['user_id' => 0, 'login' => 'admin', 'ip' => '2.2.2.2', 'ts' => '2026-07-03 10:30:00'],
        ['user_id' => 2, 'login' => 'bob',   'ip' => '3.3.3.3', 'ts' => '2026-07-02 08:00:00'],
        ['user_id' => 0, 'login' => 'alice', 'ip' => '4.4.4.4', 'ts' => '2026-07-01 05:00:00'],
    ];
}

it('filter: no filters returns everything (up to default limit)', function () {
    $out = wpultra_activity_filter(activity_audit_fixture(), []);
    assert_eq(5, count($out));
    assert_eq('content-set', $out[0]['action']);
});

it('filter: action prefix match', function () {
    $out = wpultra_activity_filter(activity_audit_fixture(), ['action' => 'content-']);
    assert_eq(2, count($out));
    assert_eq(['content-set', 'content-delete'], array_map(fn($r) => $r['action'], $out));
});

it('filter: action prefix with no matches', function () {
    $out = wpultra_activity_filter(activity_audit_fixture(), ['action' => 'nope-']);
    assert_eq(0, count($out));
});

it('filter: user_id exact match (audit "user" key)', function () {
    $out = wpultra_activity_filter(activity_audit_fixture(), ['user_id' => 2]);
    assert_eq(2, count($out));
    foreach ($out as $r) { assert_eq(2, $r['user']); }
});

it('filter: user_id exact match (login "user_id" key)', function () {
    $out = wpultra_activity_filter(activity_login_fixture(), ['user_id' => 1]);
    assert_eq(1, count($out));
    assert_eq('alice', $out[0]['login']);
});

it('filter: user needle matches login substring case-insensitively', function () {
    $out = wpultra_activity_filter(activity_login_fixture(), ['user' => 'ALI']);
    assert_eq(2, count($out));
    foreach ($out as $r) { assert_eq('alice', $r['login']); }
});

it('filter: ok_only keeps only ok===true entries', function () {
    $out = wpultra_activity_filter(activity_audit_fixture(), ['ok_only' => true]);
    assert_eq(3, count($out));
    foreach ($out as $r) { assert_true($r['ok']); }
});

it('filter: failed_only keeps only ok===false for audit entries', function () {
    $out = wpultra_activity_filter(activity_audit_fixture(), ['failed_only' => true]);
    assert_eq(2, count($out));
    assert_eq(['execute-php', 'db-query'], array_map(fn($r) => $r['action'], $out));
});

it('filter: failed_only keeps only user_id===0 for login entries', function () {
    $out = wpultra_activity_filter(activity_login_fixture(), ['failed_only' => true]);
    assert_eq(2, count($out));
    foreach ($out as $r) { assert_eq(0, $r['user_id']); }
});

it('filter: since keeps entries at/after the timestamp', function () {
    $out = wpultra_activity_filter(activity_audit_fixture(), ['since' => '2026-07-01 00:00:00']);
    assert_eq(3, count($out));
    assert_eq(['content-set', 'execute-php', 'content-delete'], array_map(fn($r) => $r['action'], $out));
});

it('filter: since excludes everything before an out-of-range date', function () {
    $out = wpultra_activity_filter(activity_audit_fixture(), ['since' => '2099-01-01']);
    assert_eq(0, count($out));
});

it('filter: limit caps output and is clamped to [1,200]', function () {
    $out = wpultra_activity_filter(activity_audit_fixture(), ['limit' => 2]);
    assert_eq(2, count($out));
    $out = wpultra_activity_filter(activity_audit_fixture(), ['limit' => 0]);
    assert_eq(1, count($out), 'limit 0 clamps up to 1');
    $out = wpultra_activity_filter(activity_audit_fixture(), ['limit' => 999]);
    assert_eq(5, count($out), 'limit above data size returns all');
});

it('filter: combines action + user_id + since', function () {
    $out = wpultra_activity_filter(activity_audit_fixture(), [
        'action'  => 'db-query',
        'user_id' => 2,
        'since'   => '2026-06-01',
    ]);
    assert_eq(1, count($out));
    assert_eq('DELETE bad', $out[0]['summary']);
});

it('filter: default limit is 200 when unspecified', function () {
    $big = [];
    for ($i = 0; $i < 250; $i++) { $big[] = ['ts' => '2026-01-01', 'user' => 1, 'action' => 'x', 'ok' => true]; }
    $out = wpultra_activity_filter($big, []);
    assert_eq(200, count($out));
});

run_tests();
