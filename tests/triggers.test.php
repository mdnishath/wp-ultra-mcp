<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/triggers/engine.php';

it('supported events + action types are stable catalogues', function () {
    $ev = wpultra_triggers_supported_events();
    assert_true(isset($ev['post_published']) && isset($ev['order_placed']) && isset($ev['form_submitted']));
    assert_eq(['webhook', 'playbook', 'log'], wpultra_triggers_action_types());
});

it('next_id is max+1', function () {
    assert_eq(1, wpultra_triggers_next_id([]));
    assert_eq(4, wpultra_triggers_next_id([['id' => 3], ['id' => 1]]));
});

it('match returns only enabled triggers for the event', function () {
    $triggers = [
        ['id' => 1, 'event' => 'post_published', 'enabled' => true],
        ['id' => 2, 'event' => 'post_published', 'enabled' => false],
        ['id' => 3, 'event' => 'order_placed',  'enabled' => true],
        ['id' => 4, 'event' => 'post_published'], // enabled defaults true
    ];
    $m = wpultra_triggers_match($triggers, 'post_published');
    assert_eq([1, 4], array_map(fn($t) => $t['id'], $m));
    assert_eq([3], array_map(fn($t) => $t['id'], wpultra_triggers_match($triggers, 'order_placed')));
});

it('validate: event + action rules', function () {
    assert_eq(true, wpultra_triggers_validate(['event' => 'post_published', 'action_type' => 'log']));
    assert_true(is_string(wpultra_triggers_validate(['event' => 'nope', 'action_type' => 'log'])));
    assert_true(is_string(wpultra_triggers_validate(['event' => 'post_published', 'action_type' => 'nope'])));
    // webhook needs http(s) url
    assert_true(is_string(wpultra_triggers_validate(['event' => 'post_published', 'action_type' => 'webhook'])));
    assert_true(is_string(wpultra_triggers_validate(['event' => 'post_published', 'action_type' => 'webhook', 'url' => 'ftp://x'])));
    assert_eq(true, wpultra_triggers_validate(['event' => 'post_published', 'action_type' => 'webhook', 'url' => 'https://hook.test/x']));
    // playbook needs slug
    assert_true(is_string(wpultra_triggers_validate(['event' => 'post_published', 'action_type' => 'playbook'])));
    assert_eq(true, wpultra_triggers_validate(['event' => 'post_published', 'action_type' => 'playbook', 'playbook' => 'my-pb']));
});

it('build_payload shapes event/site/data', function () {
    $p = wpultra_triggers_build_payload('order_placed', ['order_id' => 42], 'https://s.test', '2026-07-02 00:00:00');
    assert_eq('order_placed', $p['event']);
    assert_eq('https://s.test', $p['site']);
    assert_eq(42, $p['data']['order_id']);
});

it('log_push prepends newest-first and caps', function () {
    $log = [];
    for ($i = 1; $i <= 5; $i++) { $log = wpultra_triggers_log_push($log, ['n' => $i], 3); }
    assert_eq(3, count($log));
    assert_eq(5, $log[0]['n']);
    assert_eq(3, $log[2]['n']);
});

it('summarize renders per-event one-liners', function () {
    assert_contains('#7', wpultra_triggers_summarize('post_published', ['post_id' => 7, 'title' => 'Hi', 'post_type' => 'post']));
    assert_contains('order #9', wpultra_triggers_summarize('order_placed', ['order_id' => 9, 'status' => 'processing']));
    assert_contains('wpforms', wpultra_triggers_summarize('form_submitted', ['plugin' => 'wpforms', 'form' => '3']));
});

it('shape exposes target from url or playbook, never a secret', function () {
    $w = wpultra_triggers_shape(['id' => 1, 'event' => 'order_placed', 'action_type' => 'webhook', 'url' => 'https://h.test', 'secret' => 'shh']);
    assert_eq('https://h.test', $w['target']);
    assert_true(!array_key_exists('secret', $w), 'secret must never appear in the shape');
    $p = wpultra_triggers_shape(['id' => 2, 'event' => 'post_published', 'action_type' => 'playbook', 'playbook' => 'setup']);
    assert_eq('setup', $p['target']);
});

run_tests();
