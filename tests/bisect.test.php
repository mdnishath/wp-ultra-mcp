<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/system/bisect.php';

/* ------------------------------------------------------------------ *
 * Test helper: drives the pure state machine end-to-end against a fake
 * probe function, so the whole bisect algorithm can be simulated without
 * WordPress or HTTP. Asserts self is NEVER dropped from any probed subset.
 * ------------------------------------------------------------------ */
function bisect_drive(array $original, string $self, callable $probe_fn, int $max_probes = 20, bool $theme_check = false, ?string $theme_default = null): array {
    $state = wpultra_bisect_init($original, $self, $max_probes, $theme_check, $theme_default);
    $iterations = 0;
    while (!$state['done']) {
        $iterations++;
        if ($iterations > 50) { throw new Exception('runaway bisect loop — state machine did not converge'); }
        $next = $state['next'];
        if ($next === null) { throw new Exception('state not done but next is null'); }
        assert_true(in_array($self, $next['plugins'], true), 'self must always be present in the probed subset');
        $result = $probe_fn($next);
        $state = wpultra_bisect_next($state, $result);
    }
    return $state;
}

/* ------------------------------------------------------------------ *
 * wpultra_bisect_split
 * ------------------------------------------------------------------ */

it('split divides candidates into a floor-half left, remainder right', function () {
    [$l, $r] = wpultra_bisect_split(['a', 'b', 'c', 'd', 'e']);
    assert_eq(['a', 'b'], $l);
    assert_eq(['c', 'd', 'e'], $r);
});

it('split of an even list divides evenly', function () {
    [$l, $r] = wpultra_bisect_split(['a', 'b', 'c', 'd']);
    assert_eq(['a', 'b'], $l);
    assert_eq(['c', 'd'], $r);
});

/* ------------------------------------------------------------------ *
 * wpultra_bisect_ensure_self / wpultra_bisect_normalize_active_list
 * ------------------------------------------------------------------ */

it('ensure_self prepends self when missing from the list', function () {
    assert_eq(['self/self.php', 'a/a.php'], wpultra_bisect_ensure_self(['a/a.php'], 'self/self.php'));
});

it('ensure_self leaves the list unchanged when self is already present', function () {
    assert_eq(['a/a.php', 'self/self.php'], wpultra_bisect_ensure_self(['a/a.php', 'self/self.php'], 'self/self.php'));
});

it('normalize_active_list coerces a non-array to an empty array (malicious/invalid input)', function () {
    assert_eq([], wpultra_bisect_normalize_active_list('not-an-array'));
    assert_eq([], wpultra_bisect_normalize_active_list(null));
    assert_eq([], wpultra_bisect_normalize_active_list(42));
});

it('normalize_active_list stringifies non-string entries and reindexes', function () {
    assert_eq(['1', 'a/a.php'], wpultra_bisect_normalize_active_list([1, 'a/a.php']));
});

/* ------------------------------------------------------------------ *
 * wpultra_bisect_classify_probe (pure — takes already-fetched response meta)
 * ------------------------------------------------------------------ */

it('classify_probe: a WP_Error / transport failure is probe_failed', function () {
    assert_eq('probe_failed', wpultra_bisect_classify_probe(true, 200, 'irrelevant body'));
});

it('classify_probe: any 5xx status is broken', function () {
    assert_eq('broken', wpultra_bisect_classify_probe(false, 500, 'ok'));
    assert_eq('broken', wpultra_bisect_classify_probe(false, 503, 'ok'));
});

it('classify_probe: a fatal marker in the body is broken even with a 200 status', function () {
    assert_eq('broken', wpultra_bisect_classify_probe(false, 200, '<html>There has been a critical error on this website.</html>'));
    assert_eq('broken', wpultra_bisect_classify_probe(false, 200, 'Uncaught Error: foo in bar.php on line 3'));
});

it('classify_probe: a clean 200 body is healthy', function () {
    assert_eq('healthy', wpultra_bisect_classify_probe(false, 200, '<html><body>Hello world</body></html>'));
});

it('classify_probe: a 4xx status without a fatal marker is healthy (site is up, just a normal 4xx)', function () {
    assert_eq('healthy', wpultra_bisect_classify_probe(false, 404, '<html>Not Found</html>'));
});

/* ------------------------------------------------------------------ *
 * wpultra_bisect_init / wpultra_bisect_next — the pure state machine.
 * ------------------------------------------------------------------ */

it('init never drops self even if the caller omits it from the original list', function () {
    $state = wpultra_bisect_init(['a/a.php'], 'self/self.php', 20);
    assert_true(in_array('self/self.php', $state['original'], true));
    assert_true(in_array('self/self.php', $state['next']['plugins'], true));
});

it('init clamps a zero or negative max_probes up to at least 1', function () {
    $state = wpultra_bisect_init(['self/self.php'], 'self/self.php', 0);
    assert_eq(1, $state['max_probes']);
    $state2 = wpultra_bisect_init(['self/self.php'], 'self/self.php', -5);
    assert_eq(1, $state2['max_probes']);
});

it('healthy original set exits early — nothing to bisect', function () {
    $state = wpultra_bisect_init(['self/self.php', 'a/a.php'], 'self/self.php', 20);
    $state = wpultra_bisect_next($state, 'healthy');
    assert_true($state['done']);
    assert_eq('healthy', $state['verdict']);
    assert_eq(null, $state['culprit']);
    assert_eq(1, $state['probes_used']);
});

it('bisect converges on the single guilty plugin (7 candidates) and never drops self', function () {
    $self = 'wp-ultra-mcp/wp-ultra-mcp.php';
    $others = ['p1/p1.php', 'p2/p2.php', 'p3/p3.php', 'p4/p4.php', 'p5/p5.php', 'p6/p6.php', 'p7/p7.php'];
    $guilty = 'p5/p5.php';
    $original = array_merge([$self], $others);
    $probe = function (array $subset) use ($guilty) {
        return in_array($guilty, $subset['plugins'], true) ? 'broken' : 'healthy';
    };
    $state = bisect_drive($original, $self, $probe);
    assert_eq('plugin', $state['verdict']);
    assert_eq($guilty, $state['culprit']);
    // 1 (original) + 1 (self-only) + ceil(log2(7))=3 bisect rounds = 5 max.
    assert_true($state['probes_used'] <= 5, 'expected convergence within budget, got ' . $state['probes_used']);
});

it('bisect converges within ceil(log2(n)) rounds for a power-of-two candidate count (8)', function () {
    $self = 'wp-ultra-mcp/wp-ultra-mcp.php';
    $others = array_map(fn($i) => "p$i/p$i.php", range(1, 8));
    $guilty = 'p6/p6.php';
    $original = array_merge([$self], $others);
    $probe = function (array $subset) use ($guilty) {
        return in_array($guilty, $subset['plugins'], true) ? 'broken' : 'healthy';
    };
    $state = bisect_drive($original, $self, $probe);
    assert_eq('plugin', $state['verdict']);
    assert_eq($guilty, $state['culprit']);
    assert_true($state['probes_used'] <= 5, 'expected <=5 probes, got ' . $state['probes_used']);
});

it('the self plugin can never be reported as the culprit', function () {
    $self = 'wp-ultra-mcp/wp-ultra-mcp.php';
    $original = [$self, 'p1/p1.php'];
    $state = wpultra_bisect_init($original, $self, 20);
    $state = wpultra_bisect_next($state, 'broken');  // original broken
    $state = wpultra_bisect_next($state, 'healthy'); // self-only heals it -> bisect candidates=[p1]
    assert_eq('plugin', $state['verdict']);
    assert_eq('p1/p1.php', $state['culprit']);
    assert_true($state['culprit'] !== $self);
});

it('self-only still broken and no theme_check requested verdicts theme_or_core immediately', function () {
    $self = 'wp-ultra-mcp/wp-ultra-mcp.php';
    $original = [$self, 'p1/p1.php'];
    $state = wpultra_bisect_init($original, $self, 20, false, null);
    $state = wpultra_bisect_next($state, 'broken'); // original
    assert_true(!$state['done']);
    assert_eq('self_only', $state['phase']);
    $state = wpultra_bisect_next($state, 'broken'); // self-only still broken
    assert_true($state['done']);
    assert_eq('theme_or_core', $state['verdict']);
    assert_eq(null, $state['culprit']);
    assert_eq(2, $state['probes_used']);
});

it('theme_check requested + default theme found: swap probe healthy points at the theme', function () {
    $self = 'wp-ultra-mcp/wp-ultra-mcp.php';
    $original = [$self, 'p1/p1.php'];
    $state = wpultra_bisect_init($original, $self, 20, true, 'twentytwentyfour');
    $state = wpultra_bisect_next($state, 'broken'); // original
    $state = wpultra_bisect_next($state, 'broken'); // self-only still broken
    assert_eq('theme_check', $state['phase']);
    assert_eq('twentytwentyfour', $state['next']['theme']);
    assert_true(in_array($self, $state['next']['plugins'], true));
    $state = wpultra_bisect_next($state, 'healthy'); // theme swap fixed it
    assert_true($state['done']);
    assert_eq('theme_or_core', $state['verdict']);
    assert_eq(3, $state['probes_used']);
    assert_true(str_contains($state['note'], 'theme'), 'expected note to reference the theme');
});

it('theme_check requested + default theme still broken: note points at core, not theme', function () {
    $self = 'wp-ultra-mcp/wp-ultra-mcp.php';
    $original = [$self, 'p1/p1.php'];
    $state = wpultra_bisect_init($original, $self, 20, true, 'twentytwentyfour');
    $state = wpultra_bisect_next($state, 'broken');
    $state = wpultra_bisect_next($state, 'broken');
    $state = wpultra_bisect_next($state, 'broken'); // still broken with default theme
    assert_true($state['done']);
    assert_eq('theme_or_core', $state['verdict']);
    assert_true(str_contains($state['note'], 'core'), 'expected note to reference core when theme swap did not fix it');
});

it('theme_check requested but no default twenty* theme installed skips the extra probe', function () {
    $self = 'wp-ultra-mcp/wp-ultra-mcp.php';
    $original = [$self, 'p1/p1.php'];
    $state = wpultra_bisect_init($original, $self, 20, true, null);
    $state = wpultra_bisect_next($state, 'broken');
    $state = wpultra_bisect_next($state, 'broken');
    assert_true($state['done']);
    assert_eq('theme_or_core', $state['verdict']);
    assert_eq(2, $state['probes_used']);
    assert_true(str_contains($state['note'], 'no default'), 'expected a note explaining no default theme was available');
});

it('probe_failed retries once against the same subset, then proceeds normally', function () {
    $self = 'wp-ultra-mcp/wp-ultra-mcp.php';
    $original = [$self, 'p1/p1.php'];
    $state = wpultra_bisect_init($original, $self, 20);
    $state = wpultra_bisect_next($state, 'probe_failed'); // network blip on the original probe
    assert_true(!$state['done']);
    assert_eq('original', $state['phase']);
    assert_eq($original, $state['next']['plugins']);
    $state = wpultra_bisect_next($state, 'healthy'); // retry succeeds
    assert_true($state['done']);
    assert_eq('healthy', $state['verdict']);
    assert_eq(2, $state['probes_used']);
});

it('probe_failed twice in a row is reported inconclusive — never marks a plugin guilty on a network error', function () {
    $self = 'wp-ultra-mcp/wp-ultra-mcp.php';
    $original = [$self, 'p1/p1.php'];
    $state = wpultra_bisect_init($original, $self, 20);
    $state = wpultra_bisect_next($state, 'probe_failed');
    $state = wpultra_bisect_next($state, 'probe_failed');
    assert_true($state['done']);
    assert_eq('inconclusive', $state['verdict']);
    assert_eq(null, $state['culprit']);
});

it('hitting max_probes mid-bisect stops with inconclusive + a note naming the remaining candidates', function () {
    $self = 'wp-ultra-mcp/wp-ultra-mcp.php';
    $others = ['p1/p1.php', 'p2/p2.php', 'p3/p3.php', 'p4/p4.php'];
    $guilty = 'p3/p3.php';
    $original = array_merge([$self], $others);
    $probe = function (array $subset) use ($guilty) {
        return in_array($guilty, $subset['plugins'], true) ? 'broken' : 'healthy';
    };
    // Budget of 2: exactly enough for original + self-only, none left to bisect.
    $state = wpultra_bisect_init($original, $self, 2);
    $state = wpultra_bisect_next($state, $probe(['plugins' => $original])); // original -> broken
    assert_true(!$state['done']);
    $state = wpultra_bisect_next($state, $probe(['plugins' => [$self]])); // self-only -> healthy
    assert_true($state['done']);
    assert_eq('inconclusive', $state['verdict']);
    assert_eq(null, $state['culprit']);
    assert_true(str_contains($state['note'], 'budget'), 'expected a note about the exhausted probe budget');
});

it('bisect phase with zero non-self candidates reports inconclusive (contrived edge case)', function () {
    $self = 'wp-ultra-mcp/wp-ultra-mcp.php';
    $state = wpultra_bisect_init([$self], $self, 20);
    $state = wpultra_bisect_next($state, 'broken');  // original
    $state = wpultra_bisect_next($state, 'healthy'); // self-only reports healthy (contrived: no candidates left)
    assert_true($state['done']);
    assert_eq('inconclusive', $state['verdict']);
});

run_tests();
