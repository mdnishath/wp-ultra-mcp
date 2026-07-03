<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/triggers/engine.php';

/* ---------- sanitize_fields (roadmap #27) ---------- */

it('sanitize_fields: strings only, scalars cast, objects dropped', function () {
    $obj = new stdClass();
    $out = wpultra_triggers_sanitize_fields([
        'name'   => 'Alice',
        'age'    => 30,
        'agree'  => true,
        'ok'     => null,
        'blob'   => $obj,       // dropped
        'tags'   => ['a', 'b'], // joined
    ]);
    assert_eq('Alice', $out['name']);
    assert_eq('30', $out['age']);            // int -> string
    assert_eq('1', $out['agree']);           // true -> "1"
    assert_eq('', $out['ok']);               // null -> ""
    assert_true(!array_key_exists('blob', $out), 'object value dropped');
    assert_eq('a, b', $out['tags']);         // array joined
});

it('sanitize_fields: truncates values to 500 chars and drops empty keys', function () {
    $long = str_repeat('x', 900);
    $out = wpultra_triggers_sanitize_fields(['msg' => $long, '' => 'no-key', '  ' => 'blank']);
    assert_eq(500, strlen($out['msg']));
    assert_true(!array_key_exists('', $out), 'empty key dropped');
    assert_true(!isset($out['  ']), 'whitespace-only key dropped');
});

it('sanitize_fields: caps at 40 keys', function () {
    $raw = [];
    for ($i = 0; $i < 60; $i++) { $raw["k$i"] = "v$i"; }
    $out = wpultra_triggers_sanitize_fields($raw);
    assert_eq(40, count($out));
    assert_true(isset($out['k0']) && isset($out['k39']), 'keeps first 40 in order');
    assert_true(!isset($out['k40']), 'drops 41st');
});

/* ---------- filter_match (roadmap #27/#31) ---------- */

it('filter_match: empty filter matches everything', function () {
    assert_eq(true, wpultra_triggers_filter_match([], ['plugin' => 'wpforms']));
});

it('filter_match: subset match (all filter keys equal)', function () {
    $data = ['plugin' => 'wpforms', 'form' => '5', 'extra' => 'ignored'];
    assert_eq(true, wpultra_triggers_filter_match(['plugin' => 'wpforms', 'form' => '5'], $data));
    assert_eq(true, wpultra_triggers_filter_match(['form' => '5'], $data));
});

it('filter_match: string-coerced comparison', function () {
    // trigger def stores '5' (string), event data may carry 5 (int) or vice-versa
    assert_eq(true, wpultra_triggers_filter_match(['form' => '5'], ['form' => 5]));
    assert_eq(true, wpultra_triggers_filter_match(['form' => 5], ['form' => '5']));
});

it('filter_match: value mismatch fails', function () {
    assert_eq(false, wpultra_triggers_filter_match(['plugin' => 'cf7'], ['plugin' => 'wpforms']));
    assert_eq(false, wpultra_triggers_filter_match(['form' => '5'], ['form' => '6']));
});

it('filter_match: missing key in data fails', function () {
    assert_eq(false, wpultra_triggers_filter_match(['form' => '5'], ['plugin' => 'wpforms']));
});

it('match honours a trigger filter against event data', function () {
    $triggers = [
        ['id' => 1, 'event' => 'form_submitted', 'filter' => ['plugin' => 'wpforms', 'form' => '5']],
        ['id' => 2, 'event' => 'form_submitted', 'filter' => ['plugin' => 'cf7']],
        ['id' => 3, 'event' => 'form_submitted'], // no filter -> always matches
    ];
    $data = ['plugin' => 'wpforms', 'form' => '5'];
    assert_eq([1, 3], array_map(fn($t) => $t['id'], wpultra_triggers_match($triggers, 'form_submitted', $data)));
    // no data supplied -> filtered triggers are skipped, unfiltered still matches
    assert_eq([3], array_map(fn($t) => $t['id'], wpultra_triggers_match($triggers, 'form_submitted')));
});

/* ---------- render_template (roadmap #27/#31) ---------- */

it('render_template: dot paths into payload', function () {
    $payload = wpultra_triggers_build_payload('post_published', [
        'title' => 'Hello World', 'permalink' => 'https://s.test/hello',
        'fields' => ['Email' => 'a@b.test'],
    ], 'https://s.test', '2026-07-03 00:00:00');
    $tpl = [
        'text'  => '{data.title} {data.permalink}',
        'email' => '{data.fields.Email}',
        'event' => '{event}',
        'site'  => '{site}',
    ];
    $out = wpultra_triggers_render_template($tpl, $payload);
    assert_eq('Hello World https://s.test/hello', $out['text']);
    assert_eq('a@b.test', $out['email']);
    assert_eq('post_published', $out['event']);
    assert_eq('https://s.test', $out['site']);
});

it('render_template: unknown path renders empty, literal text kept', function () {
    $payload = wpultra_triggers_build_payload('post_published', ['title' => 'T'], 's', 'w');
    $out = wpultra_triggers_render_template([
        'a' => 'before {data.missing} after',
        'b' => 'no tokens here',
        'c' => '{data.title}!',
    ], $payload);
    assert_eq('before  after', $out['a']);
    assert_eq('no tokens here', $out['b']);
    assert_eq('T!', $out['c']);
});

it('render_template: array value joined, missing featured_image empty', function () {
    $payload = wpultra_triggers_build_payload('post_published', [
        'title' => 'P', 'tags' => ['x', 'y', 'z'],
    ], 's', 'w');
    $out = wpultra_triggers_render_template([
        'tags'  => '{data.tags}',
        'image' => '{data.featured_image}',
    ], $payload);
    assert_eq('x, y, z', $out['tags']);
    assert_eq('', $out['image']);
});

run_tests();
