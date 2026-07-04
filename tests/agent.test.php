<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';

// F2 agent engine — PURE unit tests. Load ABSPATH + helpers (wpultra_err/ok)
// before the engine, mirroring tests/security.test.php's require order.
if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/wpultra_agent/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/ai/agent.php';

/* ============================================================
 * wpultra_agent_path — dot-path resolver.
 * ============================================================ */

it('path resolves nested keys', function () {
    $d = ['a' => ['b' => ['c' => 42]]];
    assert_eq(42, wpultra_agent_path($d, 'a.b.c'));
});

it('path resolves numeric index', function () {
    $d = ['items' => [['id' => 7], ['id' => 9]]];
    assert_eq(9, wpultra_agent_path($d, 'items.1.id'));
});

it('path returns null on missing segment', function () {
    $d = ['a' => 1];
    assert_eq(null, wpultra_agent_path($d, 'a.b.c'));
    assert_eq(null, wpultra_agent_path($d, 'nope'));
});

it('path with empty string returns the whole data', function () {
    $d = ['x' => 1];
    assert_eq($d, wpultra_agent_path($d, ''));
});

/* ============================================================
 * wpultra_agent_result_is_error.
 * ============================================================ */

it('result_is_error detects WP_Error object', function () {
    assert_true(wpultra_agent_result_is_error(new WP_Error('x', 'boom')));
});

it('result_is_error detects success:false array', function () {
    assert_true(wpultra_agent_result_is_error(['success' => false]));
});

it('result_is_error detects WP_Error-shaped errors array', function () {
    assert_true(wpultra_agent_result_is_error(['errors' => ['code' => ['msg']]]));
});

it('result_is_error is false for a good result', function () {
    assert_eq(false, wpultra_agent_result_is_error(['success' => true, 'id' => 5]));
    assert_eq(false, wpultra_agent_result_is_error(['id' => 5]));
});

/* ============================================================
 * wpultra_agent_validate_plan.
 * ============================================================ */

it('validate_plan rejects empty', function () {
    assert_true(is_string(wpultra_agent_validate_plan([])));
    assert_true(is_string(wpultra_agent_validate_plan('nope')));
});

it('validate_plan rejects a step missing ability', function () {
    $r = wpultra_agent_validate_plan([['params' => []]]);
    assert_true(is_string($r));
    assert_contains('missing', $r);
});

it('validate_plan rejects a non-object step', function () {
    $r = wpultra_agent_validate_plan(['just-a-string']);
    assert_true(is_string($r));
});

it('validate_plan blocks nested agent-run', function () {
    $r = wpultra_agent_validate_plan([['ability' => 'agent-run', 'params' => []]]);
    assert_true(is_string($r));
    assert_contains('no nesting', $r);

    $r2 = wpultra_agent_validate_plan([['ability' => 'wpultra/agent-run']]);
    assert_true(is_string($r2));
});

it('validate_plan rejects a bad check on a step', function () {
    $r = wpultra_agent_validate_plan([['ability' => 'create-post', 'check' => ['type' => 'bogus']]]);
    assert_true(is_string($r));
    assert_contains('bogus', $r);
});

it('validate_plan accepts a valid plan', function () {
    $r = wpultra_agent_validate_plan([
        ['ability' => 'create-post', 'params' => ['title' => 'Hi'], 'save_as' => 'p', 'check' => ['type' => 'ability_ok']],
        ['ability' => 'get-post', 'params' => ['id' => '{steps.p.id}']],
    ]);
    assert_eq(true, $r);
});

it('validate_plan enforces the step cap', function () {
    $steps = array_fill(0, WPULTRA_AGENT_MAX_STEPS + 1, ['ability' => 'noop']);
    $r = wpultra_agent_validate_plan($steps);
    assert_true(is_string($r));
});

/* ============================================================
 * wpultra_agent_validate_check.
 * ============================================================ */

it('validate_check allows empty check', function () {
    assert_eq(true, wpultra_agent_validate_check([]));
});

it('validate_check rejects unknown type', function () {
    assert_true(is_string(wpultra_agent_validate_check(['type' => 'wat'])));
});

it('validate_check requires value for equals', function () {
    assert_true(is_string(wpultra_agent_validate_check(['type' => 'equals'])));
    assert_eq(true, wpultra_agent_validate_check(['type' => 'equals', 'value' => 3]));
});

it('validate_check requires needle for contains', function () {
    assert_true(is_string(wpultra_agent_validate_check(['type' => 'contains'])));
    assert_eq(true, wpultra_agent_validate_check(['type' => 'contains', 'needle' => 'x']));
});

it('validate_check accepts ability_ok and nonempty with no extra fields', function () {
    assert_eq(true, wpultra_agent_validate_check(['type' => 'ability_ok']));
    assert_eq(true, wpultra_agent_validate_check(['type' => 'nonempty']));
});

/* ============================================================
 * wpultra_agent_eval_check — each type, pass + fail.
 * ============================================================ */

it('eval_check empty always passes', function () {
    assert_true(wpultra_agent_eval_check([], ['anything'])['passed']);
});

it('eval_check ability_ok passes on good result, fails on error', function () {
    assert_true(wpultra_agent_eval_check(['type' => 'ability_ok'], ['success' => true, 'id' => 1])['passed']);
    assert_eq(false, wpultra_agent_eval_check(['type' => 'ability_ok'], ['success' => false])['passed']);
    assert_eq(false, wpultra_agent_eval_check(['type' => 'ability_ok'], new WP_Error('e', 'bad'))['passed']);
});

it('eval_check ability_ok on a WP_Error-shaped array fails', function () {
    $shaped = ['errors' => ['boom' => ['it broke']]];
    assert_eq(false, wpultra_agent_eval_check(['type' => 'ability_ok'], $shaped)['passed']);
});

it('eval_check ability_ok with a path narrows to a sub-result', function () {
    $r = ['data' => ['success' => true]];
    assert_true(wpultra_agent_eval_check(['type' => 'ability_ok', 'path' => 'data'], $r)['passed']);
});

it('eval_check equals passes and fails', function () {
    $r = ['post' => ['status' => 'draft']];
    assert_true(wpultra_agent_eval_check(['type' => 'equals', 'path' => 'post.status', 'value' => 'draft'], $r)['passed']);
    assert_eq(false, wpultra_agent_eval_check(['type' => 'equals', 'path' => 'post.status', 'value' => 'publish'], $r)['passed']);
});

it('eval_check equals coerces scalar types', function () {
    $r = ['count' => 5];
    assert_true(wpultra_agent_eval_check(['type' => 'equals', 'path' => 'count', 'value' => '5'], $r)['passed']);
});

it('eval_check contains works on strings and arrays', function () {
    $r = ['msg' => 'hello world', 'tags' => ['a', 'b']];
    assert_true(wpultra_agent_eval_check(['type' => 'contains', 'path' => 'msg', 'needle' => 'world'], $r)['passed']);
    assert_eq(false, wpultra_agent_eval_check(['type' => 'contains', 'path' => 'msg', 'needle' => 'zzz'], $r)['passed']);
    assert_true(wpultra_agent_eval_check(['type' => 'contains', 'path' => 'tags', 'needle' => 'b'], $r)['passed']);
    assert_eq(false, wpultra_agent_eval_check(['type' => 'contains', 'path' => 'tags', 'needle' => 'z'], $r)['passed']);
});

it('eval_check contains fails on non-string non-array', function () {
    $r = ['n' => 5];
    assert_eq(false, wpultra_agent_eval_check(['type' => 'contains', 'path' => 'n', 'needle' => '5'], $r)['passed']);
});

it('eval_check nonempty passes on present, fails on empty', function () {
    $r = ['id' => 7, 'blank' => '', 'zero' => 0, 'arr' => [], 'list' => [1]];
    assert_true(wpultra_agent_eval_check(['type' => 'nonempty', 'path' => 'id'], $r)['passed']);
    assert_true(wpultra_agent_eval_check(['type' => 'nonempty', 'path' => 'list'], $r)['passed']);
    assert_eq(false, wpultra_agent_eval_check(['type' => 'nonempty', 'path' => 'blank'], $r)['passed']);
    assert_eq(false, wpultra_agent_eval_check(['type' => 'nonempty', 'path' => 'zero'], $r)['passed']);
    assert_eq(false, wpultra_agent_eval_check(['type' => 'nonempty', 'path' => 'arr'], $r)['passed']);
    assert_eq(false, wpultra_agent_eval_check(['type' => 'nonempty', 'path' => 'missing'], $r)['passed']);
});

/* ============================================================
 * wpultra_agent_is_empty.
 * ============================================================ */

it('is_empty matrix', function () {
    assert_true(wpultra_agent_is_empty(''));
    assert_true(wpultra_agent_is_empty('  '));
    assert_true(wpultra_agent_is_empty('0'));
    assert_true(wpultra_agent_is_empty(null));
    assert_true(wpultra_agent_is_empty(false));
    assert_true(wpultra_agent_is_empty([]));
    assert_true(wpultra_agent_is_empty(0));
    assert_eq(false, wpultra_agent_is_empty('x'));
    assert_eq(false, wpultra_agent_is_empty(1));
    assert_eq(false, wpultra_agent_is_empty([0]));
    assert_eq(false, wpultra_agent_is_empty(true));
});

/* ============================================================
 * wpultra_agent_should_retry — boundary at max.
 * ============================================================ */

it('should_retry stops immediately when passed', function () {
    assert_eq(false, wpultra_agent_should_retry(1, 2, true));
});

it('should_retry boundary with max=2', function () {
    // attempt 1 failed -> retry; attempt 2 failed -> retry (still <= max); attempt 3 -> stop.
    assert_true(wpultra_agent_should_retry(1, 2, false));
    assert_true(wpultra_agent_should_retry(2, 2, false));
    assert_eq(false, wpultra_agent_should_retry(3, 2, false));
});

it('should_retry with max=0 never retries', function () {
    assert_eq(false, wpultra_agent_should_retry(1, 0, false));
});

/* ============================================================
 * wpultra_agent_clamp_retries.
 * ============================================================ */

it('clamp_retries defaults and clamps', function () {
    assert_eq(WPULTRA_AGENT_DEFAULT_RETRY, wpultra_agent_clamp_retries(null));
    assert_eq(WPULTRA_AGENT_DEFAULT_RETRY, wpultra_agent_clamp_retries(''));
    assert_eq(0, wpultra_agent_clamp_retries(-3));
    assert_eq(WPULTRA_AGENT_RETRY_CAP, wpultra_agent_clamp_retries(999));
    assert_eq(3, wpultra_agent_clamp_retries(3));
});

/* ============================================================
 * wpultra_agent_summarize.
 * ============================================================ */

it('summarize rolls up distinct steps and retries', function () {
    // step 0: attempt1 fail, attempt2 ok  -> passed, 1 retry
    // step 1: attempt1 ok                 -> passed
    // step 2: attempt1 fail, attempt2 fail -> failed, 1 retry
    $log = [
        ['step' => 0, 'attempt' => 1, 'ok' => false],
        ['step' => 0, 'attempt' => 2, 'ok' => true],
        ['step' => 1, 'attempt' => 1, 'ok' => true],
        ['step' => 2, 'attempt' => 1, 'ok' => false],
        ['step' => 2, 'attempt' => 2, 'ok' => false],
    ];
    $s = wpultra_agent_summarize($log);
    assert_eq(3, $s['total']);
    assert_eq(2, $s['passed']);
    assert_eq(1, $s['failed']);
    assert_eq(2, $s['retries']);
});

it('summarize handles an empty log', function () {
    $s = wpultra_agent_summarize([]);
    assert_eq(0, $s['total']);
    assert_eq(0, $s['passed']);
    assert_eq(0, $s['failed']);
    assert_eq(0, $s['retries']);
});

/* ============================================================
 * wpultra_agent_parse_plan.
 * ============================================================ */

it('parse_plan reads raw JSON object with steps', function () {
    $steps = wpultra_agent_parse_plan('{"steps":[{"ability":"create-post","params":{"title":"Hi"}}]}');
    assert_true(is_array($steps));
    assert_eq('create-post', $steps[0]['ability']);
});

it('parse_plan reads a bare JSON array of steps', function () {
    $steps = wpultra_agent_parse_plan('[{"ability":"a"},{"ability":"b"}]');
    assert_true(is_array($steps));
    assert_eq(2, count($steps));
});

it('parse_plan reads fenced ```json block (last one)', function () {
    $raw = "Here is the plan:\n```json\n{\"steps\":[{\"ability\":\"seo-audit\"}]}\n```\nDone.";
    $steps = wpultra_agent_parse_plan($raw);
    assert_true(is_array($steps));
    assert_eq('seo-audit', $steps[0]['ability']);
});

it('parse_plan rejects garbage', function () {
    assert_true(is_string(wpultra_agent_parse_plan('not json at all')));
    assert_true(is_string(wpultra_agent_parse_plan('')));
    assert_true(is_string(wpultra_agent_parse_plan('{"nope":1}')));
});

it('parse_plan rejects a plan that nests agent-run', function () {
    $r = wpultra_agent_parse_plan('{"steps":[{"ability":"agent-run"}]}');
    assert_true(is_string($r));
    assert_contains('no nesting', $r);
});

/* ============================================================
 * wpultra_agent_plan_prompt.
 * ============================================================ */

it('plan_prompt embeds the goal and catalog names', function () {
    $p = wpultra_agent_plan_prompt('Publish a welcome post', [
        ['name' => 'create-post', 'summary' => 'Create a post'],
        ['name' => 'update-menu', 'summary' => 'Edit a menu'],
    ]);
    assert_true(is_array($p));
    assert_contains('Publish a welcome post', $p['user']);
    assert_contains('create-post', $p['user']);
    assert_contains('update-menu', $p['user']);
    // System constrains to catalog + forbids agent-run.
    assert_contains('ONLY', $p['system']);
    assert_contains('agent-run', $p['system']);
});

it('plan_prompt accepts an assoc name=>summary catalog', function () {
    $p = wpultra_agent_plan_prompt('Do a thing', ['seo-audit' => 'Audit SEO']);
    assert_contains('seo-audit', $p['user']);
    assert_contains('Audit SEO', $p['user']);
});

it('plan_prompt tolerates a flat name-only catalog', function () {
    $p = wpultra_agent_plan_prompt('Goal here', ['create-post', 'get-post']);
    assert_contains('create-post', $p['user']);
    assert_contains('get-post', $p['user']);
});

/* ============================================================
 * wpultra_agent_log_entry / append / shape helpers.
 * ============================================================ */

it('log_entry shapes an attempt record', function () {
    $e = wpultra_agent_log_entry(2, 1, true, ['passed' => true, 'reason' => 'ok'], '2026-01-01');
    assert_eq(2, $e['step']);
    assert_eq(1, $e['attempt']);
    assert_eq(true, $e['ok']);
    assert_eq('2026-01-01', $e['at']);
});

it('log_append caps the ring buffer', function () {
    $log = [];
    for ($i = 0; $i < WPULTRA_AGENT_LOG_CAP + 10; $i++) {
        $log = wpultra_agent_log_append($log, wpultra_agent_log_entry($i, 1, true));
    }
    assert_eq(WPULTRA_AGENT_LOG_CAP, count($log));
    // Oldest were dropped: first remaining entry is step 10.
    assert_eq(10, $log[0]['step']);
});

it('step_check normalises a missing check to an empty array', function () {
    assert_eq([], wpultra_agent_step_check(['ability' => 'x']));
    assert_eq(['type' => 'ability_ok'], wpultra_agent_step_check(['ability' => 'x', 'check' => ['type' => 'ability_ok']]));
});

it('shape builds a run record with a summary', function () {
    $blob = wpultra_agent_new_blob('my goal', [['ability' => 'a']], 2, ['k' => 1]);
    $blob['log'] = [['step' => 0, 'attempt' => 1, 'ok' => true]];
    $shaped = wpultra_agent_shape(42, 'done', $blob, '2026-01-01', '2026-01-02');
    assert_eq(42, $shaped['id']);
    assert_eq('my goal', $shaped['goal']);
    assert_eq('done', $shaped['status']);
    assert_eq(1, $shaped['summary']['passed']);
});

it('new_blob has the expected shape', function () {
    $b = wpultra_agent_new_blob('g', [['ability' => 'a']], 3, ['x' => 1]);
    assert_eq('g', $b['goal']);
    assert_eq(3, $b['max_retries']);
    assert_eq(0, $b['cursor']);
    assert_eq([], $b['log']);
    assert_eq(['x' => 1], $b['inputs']);
});

it('states and check_types are the documented sets', function () {
    assert_eq(['planning', 'running', 'done', 'failed', 'cancelled'], wpultra_agent_states());
    assert_eq(['ability_ok', 'equals', 'contains', 'nonempty'], wpultra_agent_check_types());
});

run_tests();
