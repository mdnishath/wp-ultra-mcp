<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/playbooks/engine.php';

it('path reads dot-paths incl. numeric indexes', function () {
    $data = ['post' => ['id' => 5, 'tags' => ['a', 'b']]];
    assert_eq([true, 5], wpultra_playbook_path($data, 'post.id'));
    assert_eq([true, 'b'], wpultra_playbook_path($data, 'post.tags.1'));
    assert_eq([false, null], wpultra_playbook_path($data, 'post.missing'));
    assert_eq([true, $data], wpultra_playbook_path($data, ''));
});

it('resolve dispatches input.* and steps.*', function () {
    $ctx = ['input' => ['title' => 'Hi'], 'steps' => ['post' => ['id' => 9]]];
    assert_eq([true, 'Hi'], wpultra_playbook_resolve('input.title', $ctx));
    assert_eq([true, 9], wpultra_playbook_resolve('steps.post.id', $ctx));
    assert_eq([false, null], wpultra_playbook_resolve('steps.post.nope', $ctx));
    assert_eq([false, null], wpultra_playbook_resolve('other.x', $ctx));
});

it('subst: a lone token preserves the raw type', function () {
    $ctx = ['input' => [], 'steps' => ['post' => ['id' => 9, 'ok' => true]]];
    assert_true(wpultra_playbook_subst('{steps.post.id}', $ctx) === 9);       // int, not "9"
    assert_true(wpultra_playbook_subst('{steps.post.ok}', $ctx) === true);    // bool preserved
    assert_eq(null, wpultra_playbook_subst('{steps.post.missing}', $ctx));    // unknown whole-token → null
});

it('subst: embedded tokens interpolate as strings', function () {
    $ctx = ['input' => ['name' => 'World'], 'steps' => ['s' => ['n' => 3, 'flag' => false]]];
    assert_eq('Hello World!', wpultra_playbook_subst('Hello {input.name}!', $ctx));
    assert_eq('n=3 flag=false', wpultra_playbook_subst('n={steps.s.n} flag={steps.s.flag}', $ctx));
    assert_eq('x=', wpultra_playbook_subst('x={input.missing}', $ctx)); // unknown embedded → empty
});

it('subst recurses through nested params', function () {
    $ctx = ['input' => ['id' => 7], 'steps' => []];
    $params = ['post_id' => '{input.id}', 'meta' => ['ref' => 'post-{input.id}']];
    $out = wpultra_playbook_subst($params, $ctx);
    assert_true($out['post_id'] === 7);
    assert_eq('post-7', $out['meta']['ref']);
});

it('validate_steps: happy path + failure modes', function () {
    assert_eq(true, wpultra_playbook_validate_steps([
        ['ability' => 'create-post', 'params' => [], 'save_as' => 'a'],
        ['ability' => 'update-post', 'params' => [], 'save_as' => 'b'],
    ]));
    assert_true(is_string(wpultra_playbook_validate_steps([])));                       // empty
    assert_true(is_string(wpultra_playbook_validate_steps([['params' => []]])));       // no ability
    assert_true(is_string(wpultra_playbook_validate_steps([                            // dup save_as
        ['ability' => 'x', 'save_as' => 'a'], ['ability' => 'y', 'save_as' => 'a'],
    ])));
    assert_true(is_string(wpultra_playbook_validate_steps([                            // bad save_as
        ['ability' => 'x', 'save_as' => '1bad'],
    ])));
});

it('validate_steps forbids nesting playbook-run', function () {
    assert_true(is_string(wpultra_playbook_validate_steps([['ability' => 'playbook-run']])));
    assert_true(is_string(wpultra_playbook_validate_steps([['ability' => 'wpultra/playbook-run']])));
});

it('ability_name normalizes to the wpultra/ prefix', function () {
    assert_eq('wpultra/create-post', wpultra_playbook_ability_name('create-post'));
    assert_eq('wpultra/create-post', wpultra_playbook_ability_name('wpultra/create-post'));
    assert_eq('wpultra/create-post', wpultra_playbook_ability_name('/create-post'));
});

it('parse accepts raw JSON and a fenced markdown block', function () {
    $json = '{"name":"demo","steps":[{"ability":"create-post"}]}';
    assert_eq('demo', wpultra_playbook_parse($json)['name']);
    $md = "---\nname: x\n---\nprose\n```json\n{\"steps\":[{\"ability\":\"a\"}]}\n```\n";
    assert_eq(1, count(wpultra_playbook_parse($md)['steps']));
    assert_eq(null, wpultra_playbook_parse('not json'));
    assert_eq(null, wpultra_playbook_parse('{"no":"steps"}'));
});

run_tests();
