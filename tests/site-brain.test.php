<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/system/brain.php';

// ---- wpultra_brain_excerpt: codepoint-safe excerpt ----

it('excerpt returns the string unchanged when within the limit', function () {
    assert_eq('hello world', wpultra_brain_excerpt('hello world', 200));
});

it('excerpt trims and appends an ellipsis when over the limit', function () {
    $s = str_repeat('a', 10);
    assert_eq('aaaaa…', wpultra_brain_excerpt($s, 5));
});

it('excerpt strips tags and collapses whitespace before measuring length', function () {
    assert_eq('hello world', wpultra_brain_excerpt("<p>hello   \n world</p>", 200));
});

it('excerpt returns empty string for n <= 0', function () {
    assert_eq('', wpultra_brain_excerpt('anything', 0));
});

it('excerpt is Bengali-safe: never cuts a multi-byte character in half', function () {
    // "আমার সোনার বাংলা" — Bengali, each character is a 3-byte UTF-8 sequence.
    $s = 'আমার সোনার বাংলা আমি তোমায় ভালোবাসি';
    $out = wpultra_brain_excerpt($s, 8);
    // Result must be valid UTF-8 (no truncated multi-byte sequence) and end with the ellipsis.
    // preg_match with the /u modifier fails (returns false) on invalid UTF-8 byte sequences —
    // a truncated multi-byte character would trip this.
    assert_true(preg_match('/^.*$/us', $out) === 1, 'excerpt output is valid UTF-8');
    assert_true(str_ends_with($out, '…'), 'excerpt appends ellipsis when truncated');
    // 8 chars + the ellipsis char.
    $codepoint_count = function_exists('mb_strlen') ? mb_strlen($out, 'UTF-8') : preg_match_all('/./us', $out);
    assert_eq(9, $codepoint_count);
});

it('excerpt leaves a short Bengali string untouched', function () {
    $s = 'আমার বাংলা';
    assert_eq($s, wpultra_brain_excerpt($s, 200));
});

// ---- wpultra_brain_hotspots: top-5 by fails desc, then fail_rate desc ----

it('hotspots ranks by fail count descending', function () {
    $stats = [
        'a' => ['calls' => 10, 'fails' => 2],
        'b' => ['calls' => 10, 'fails' => 5],
        'c' => ['calls' => 10, 'fails' => 1],
    ];
    $out = wpultra_brain_hotspots($stats);
    assert_eq(['b', 'a', 'c'], array_column($out, 'action'));
});

it('hotspots ties on fail count broken by fail_rate descending', function () {
    $stats = [
        'low_rate'  => ['calls' => 100, 'fails' => 3],
        'high_rate' => ['calls' => 6, 'fails' => 3],
    ];
    $out = wpultra_brain_hotspots($stats);
    assert_eq(['high_rate', 'low_rate'], array_column($out, 'action'));
});

it('hotspots excludes actions with zero fails', function () {
    $stats = [
        'clean' => ['calls' => 50, 'fails' => 0],
        'bad'   => ['calls' => 5, 'fails' => 1],
    ];
    $out = wpultra_brain_hotspots($stats);
    assert_eq(['bad'], array_column($out, 'action'));
});

it('hotspots caps at top 5', function () {
    $stats = [];
    for ($i = 1; $i <= 8; $i++) { $stats["action$i"] = ['calls' => 10, 'fails' => $i]; }
    $out = wpultra_brain_hotspots($stats);
    assert_eq(5, count($out));
    assert_eq(['action8', 'action7', 'action6', 'action5', 'action4'], array_column($out, 'action'));
});

it('hotspots computes fail_rate as fails/calls rounded to 3 places', function () {
    $stats = ['x' => ['calls' => 3, 'fails' => 1]];
    $out = wpultra_brain_hotspots($stats);
    assert_eq(0.333, $out[0]['fail_rate']);
});

it('hotspots ignores non-array stat entries', function () {
    $stats = ['bogus' => 'not-an-array', 'real' => ['calls' => 4, 'fails' => 1]];
    $out = wpultra_brain_hotspots($stats);
    assert_eq(['real'], array_column($out, 'action'));
});

// ---- wpultra_brain_render_markdown: pure fixture-driven render ----

function wpultra_brain_test_fixture(): array {
    return [
        'generated_at' => '2026-07-03 12:00:00',
        'site' => [
            'name' => 'Example Site',
            'url'  => 'https://example.test/',
            'wp_version' => '6.5',
            'plugins' => ['active' => [
                ['plugin' => 'elementor/elementor.php', 'name' => 'Elementor', 'version' => '3.20'],
            ], 'active_count' => 1, 'inactive_count' => 0],
        ],
        'memories' => [
            ['title' => 'Client prefers blue CTAs', 'type' => 'user', 'excerpt' => 'Always use the brand blue for buttons.'],
            ['title' => 'Homepage rebuilt 2026-06', 'type' => 'project', 'excerpt' => ''],
        ],
        'recent_activity' => [
            ['ts' => '2026-07-03 11:50:00', 'action' => 'create-post', 'summary' => 'created post #42', 'ok' => true],
            ['ts' => '2026-07-03 11:40:00', 'action' => 'execute-wp-query', 'summary' => 'SELECT failed: syntax', 'ok' => false],
        ],
        'failure_hotspots' => [
            ['action' => 'execute-wp-query', 'calls' => 10, 'fails' => 4, 'fail_rate' => 0.4, 'last_error' => 'syntax error'],
        ],
        'triggers' => [
            ['id' => 1, 'event' => 'post_published', 'action_type' => 'webhook', 'target' => 'https://hook.example/x', 'enabled' => true, 'label' => '', 'created' => ''],
        ],
        'custom' => [
            'abilities' => ['my-custom-ability'],
            'playbooks' => ['weekly-report'],
            'widgets'   => ['pricing-table'],
        ],
    ];
}

it('render_markdown includes all top-level sections', function () {
    $md = wpultra_brain_render_markdown(wpultra_brain_test_fixture());
    assert_contains('# Site Brain', $md);
    assert_contains('## Site', $md);
    assert_contains('## Memories', $md);
    assert_contains('## Recent Activity', $md);
    assert_contains('## Failure Hotspots', $md);
    assert_contains('## Active Triggers', $md);
    assert_contains('## Custom Tools', $md);
});

it('render_markdown lists memories as bullets with title/type/excerpt', function () {
    $md = wpultra_brain_render_markdown(wpultra_brain_test_fixture());
    assert_contains('**Client prefers blue CTAs** (user) — Always use the brand blue for buttons.', $md);
    assert_contains('**Homepage rebuilt 2026-06** (project)', $md);
});

it('render_markdown renders the failure-hotspots section as a markdown table', function () {
    $md = wpultra_brain_render_markdown(wpultra_brain_test_fixture());
    assert_contains('| Action | Calls | Fails | Fail rate | Last error |', $md);
    assert_contains('|---|---|---|---|---|', $md);
    assert_contains('| execute-wp-query | 10 | 4 | 0.4 | syntax error |', $md);
});

it('render_markdown shows plugin names in the site section', function () {
    $md = wpultra_brain_render_markdown(wpultra_brain_test_fixture());
    assert_contains('Elementor', $md);
});

it('render_markdown shows recent activity with OK/FAIL markers', function () {
    $md = wpultra_brain_render_markdown(wpultra_brain_test_fixture());
    assert_contains('[OK] 2026-07-03 11:50:00 — **create-post** — created post #42', $md);
    assert_contains('[FAIL] 2026-07-03 11:40:00 — **execute-wp-query** — SELECT failed: syntax', $md);
});

it('render_markdown lists custom abilities/playbooks/widgets', function () {
    $md = wpultra_brain_render_markdown(wpultra_brain_test_fixture());
    assert_contains('my-custom-ability', $md);
    assert_contains('weekly-report', $md);
    assert_contains('pricing-table', $md);
});

it('render_markdown lists active triggers as event -> action bullets', function () {
    $md = wpultra_brain_render_markdown(wpultra_brain_test_fixture());
    assert_contains('`post_published` → webhook (https://hook.example/x)', $md);
});

it('render_markdown handles a fully-empty brain without error, showing empty-state copy', function () {
    $md = wpultra_brain_render_markdown([]);
    assert_contains('# Site Brain', $md);
    assert_contains('_No saved memories yet._', $md);
    assert_contains('_No recorded actions yet._', $md);
    assert_contains('_No failures recorded._', $md);
    assert_contains('_No active triggers._', $md);
    assert_contains('_none_', $md);
});

it('render_markdown ordering is deterministic: Site, Memories, Recent Activity, Failure Hotspots, Active Triggers, Custom Tools', function () {
    $md = wpultra_brain_render_markdown(wpultra_brain_test_fixture());
    $posSite = strpos($md, '## Site');
    $posMem = strpos($md, '## Memories');
    $posAct = strpos($md, '## Recent Activity');
    $posHot = strpos($md, '## Failure Hotspots');
    $posTrig = strpos($md, '## Active Triggers');
    $posCustom = strpos($md, '## Custom Tools');
    assert_true($posSite < $posMem, 'site before memories');
    assert_true($posMem < $posAct, 'memories before recent activity');
    assert_true($posAct < $posHot, 'recent activity before hotspots');
    assert_true($posHot < $posTrig, 'hotspots before triggers');
    assert_true($posTrig < $posCustom, 'triggers before custom tools');
});

it('render_markdown escapes pipe characters in table cells', function () {
    $fixture = wpultra_brain_test_fixture();
    $fixture['failure_hotspots'][0]['last_error'] = 'error | with pipe';
    $md = wpultra_brain_render_markdown($fixture);
    assert_contains('error \\| with pipe', $md);
});

run_tests();
