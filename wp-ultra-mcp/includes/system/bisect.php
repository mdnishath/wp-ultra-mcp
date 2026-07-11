<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * conflict-bisect engine (Roadmap-4, BF1.2) — automated plugin-conflict hunter.
 *
 * Snapshot the active plugin set -> deactivate everything except WP-Ultra-MCP
 * itself -> binary-search re-enable while probing the site over HTTP -> report
 * the culprit plugin (or theme/core) -> ALWAYS restore the original state.
 *
 * Plugin toggling writes the `active_plugins` option DIRECTLY (never
 * activate_plugin()/deactivate_plugins()) so (de)activation hooks never fire —
 * those hooks can drop tables or rewrite state, and this silent toggle only
 * needs to affect the NEXT request (a fresh probe), which is exactly what we
 * want. wp-ultra-mcp itself is in EVERY subset tested; we never deactivate
 * ourselves, because if the run dies mid-bisect with us deactivated, the MCP
 * connection is permanently dead.
 *
 * Restore-safety is the #1 requirement: wpultra_bisect_register_restore() is
 * called FIRST, before any mutation, arming both a register_shutdown_function
 * callback and (via try/finally in wpultra_bisect_run()) a normal-exit
 * restore. Both funnel through wpultra_bisect_do_restore(), which is
 * idempotent (a 'restored' guard flag) so a clean finish never double-writes.
 *
 * KNOWN CAVEAT: on single-PHP-worker dev hosts (e.g. Local by Flywheel) a
 * loopback probe issued from inside the very request that's running this
 * ability can deadlock the one worker waiting on itself. Run this against a
 * URL served by a multi-worker host, and keep max_probes low if a
 * single-worker host is unavoidable. (Documented again in the ability
 * description + returned as part of `note` when the target host is a
 * loopback address.)
 *
 * The PURE core (prefix wpultra_bisect_, no WordPress calls) is a step-wise
 * state machine unit-tested end-to-end in tests/bisect.test.php by simulating
 * a fake "broken plugin" and feeding probe results computed from whether it's
 * in the tested subset. WP-touching wrappers below are thin and guarded with
 * function_exists so this file still loads standalone in the test harness.
 */

// ---------------------------------------------------------------------------
// PURE: bisect state machine
// ---------------------------------------------------------------------------

/** Split a candidate list into a floor-half left + the remainder right. Pure. */
function wpultra_bisect_split(array $candidates): array {
    $mid = intdiv(count($candidates), 2);
    if ($mid < 1) { $mid = 1; }
    return [array_slice($candidates, 0, $mid), array_slice($candidates, $mid)];
}

/** Terminal state transition. Pure. */
function wpultra_bisect_finish(array $state, string $verdict, ?string $culprit, string $note): array {
    $state['done']    = true;
    $state['verdict'] = $verdict;
    $state['culprit'] = $culprit;
    $state['note']    = $note;
    $state['next']    = null;
    return $state;
}

/**
 * Arm the next probe subset, UNLESS the probe budget is already spent — in
 * which case finish as inconclusive with whatever partial info is on hand.
 * Pure (budget is just an int on $state).
 */
function wpultra_bisect_set_next(array $state, string $phase, array $next): array {
    if ($state['probes_used'] >= $state['max_probes']) {
        $remaining = $state['candidates'] ?? [];
        $note = 'probe budget (' . $state['max_probes'] . ') reached before further probing could complete.'
            . ($remaining !== [] ? ' Remaining candidates: ' . implode(', ', $remaining) . '.' : '');
        return wpultra_bisect_finish($state, 'inconclusive', null, $note);
    }
    $state['phase'] = $phase;
    $state['next']  = $next;
    return $state;
}

/** Decide the next bisect-phase subset (or conclude) from $state['candidates']. Pure. */
function wpultra_bisect_advance_bisect_step(array $state): array {
    $candidates = $state['candidates'];
    if ($candidates === []) {
        return wpultra_bisect_finish($state, 'inconclusive', null, 'no candidate plugins remained to test (unexpected state).');
    }
    if (count($candidates) === 1) {
        $culprit = $candidates[0];
        return wpultra_bisect_finish($state, 'plugin', $culprit, "isolated to a single plugin: $culprit");
    }
    [$left, $right] = wpultra_bisect_split($candidates);
    $state['pending_left']  = $left;
    $state['pending_right'] = $right;
    return wpultra_bisect_set_next($state, 'bisect', ['plugins' => array_values(array_merge([$state['self']], $left)), 'theme' => null]);
}

/** Enter the bisect phase for the first time: candidates = original minus self. Pure. */
function wpultra_bisect_start_bisect(array $state): array {
    $state['candidates'] = array_values(array_diff($state['original'], [$state['self']]));
    return wpultra_bisect_advance_bisect_step($state);
}

/** Continue an in-progress bisect after a probe result. Pure. */
function wpultra_bisect_continue_bisect(array $state, bool $healthy): array {
    // Healthy with [self + left] active means the culprit isn't in left -> it's in right.
    // Broken means the culprit is in left. Single-culprit assumption, as specified.
    $state['candidates'] = $healthy ? $state['pending_right'] : $state['pending_left'];
    return wpultra_bisect_advance_bisect_step($state);
}

/**
 * Build the initial state. $original_active is the plugin set as read from
 * the live `active_plugins` option (self is force-included if the caller
 * forgot). Pure.
 */
function wpultra_bisect_init(array $original_active, string $self, int $max_probes, bool $theme_check_requested = false, ?string $theme_default = null): array {
    $original = array_values(array_unique(array_map('strval', $original_active)));
    if (!in_array($self, $original, true)) { $original[] = $self; }
    return [
        'phase'                  => 'original',
        'self'                   => $self,
        'original'               => $original,
        'candidates'             => [],
        'pending_left'           => [],
        'pending_right'          => [],
        'theme_check_requested'  => $theme_check_requested,
        'theme_default'          => $theme_default,
        'max_probes'             => max(1, $max_probes),
        'probes_used'            => 0,
        'retried'                => false,
        'steps'                  => [],
        'done'                   => false,
        'verdict'                => null,
        'culprit'                => null,
        'note'                   => null,
        'next'                   => ['plugins' => $original, 'theme' => null],
    ];
}

/**
 * Advance the state machine given the outcome of the probe that was just run
 * against $state['next']. $last_probe_result is one of 'healthy'|'broken'|
 * 'probe_failed'. Pure — the only inputs are $state and the probe outcome.
 */
function wpultra_bisect_next(array $state, string $last_probe_result): array {
    $state['probes_used'] = ($state['probes_used'] ?? 0) + 1;
    $subset = $state['next'] ?? ['plugins' => [], 'theme' => null];

    if ($last_probe_result === 'probe_failed') {
        if (empty($state['retried'])) {
            $state['retried'] = true;
            $state['steps'][] = ['active_count' => count($subset['plugins']), 'probe' => 'probe_failed', 'note' => 'probe failed; retrying once before treating it as inconclusive'];
            if ($state['probes_used'] >= $state['max_probes']) {
                return wpultra_bisect_finish($state, 'inconclusive', null, 'probe budget exhausted during the retry of a failed probe.');
            }
            // Keep phase + next unchanged: the caller re-probes the same subset.
            return $state;
        }
        $state['steps'][] = ['active_count' => count($subset['plugins']), 'probe' => 'probe_failed', 'note' => 'probe failed twice in a row; treated as inconclusive, no plugin marked guilty'];
        return wpultra_bisect_finish($state, 'inconclusive', null, 'the probe failed twice in a row (network/transport error); no plugin was marked guilty on an inconclusive probe.');
    }

    $state['retried'] = false;
    $healthy = ($last_probe_result === 'healthy');
    $state['steps'][] = [
        'active_count' => count($subset['plugins']),
        'probe'        => $last_probe_result,
        'note'         => $state['phase'] === 'theme_check' ? 'theme-swap probe' : '',
    ];

    switch ($state['phase']) {
        case 'original':
            if ($healthy) {
                return wpultra_bisect_finish($state, 'healthy', null, 'site is healthy with the original plugin set; nothing to bisect.');
            }
            return wpultra_bisect_set_next($state, 'self_only', ['plugins' => [$state['self']], 'theme' => null]);

        case 'self_only':
            if ($healthy) {
                return wpultra_bisect_start_bisect($state);
            }
            if (!empty($state['theme_check_requested'])) {
                $default_theme = $state['theme_default'];
                if ($default_theme === null || $default_theme === '') {
                    return wpultra_bisect_finish($state, 'theme_or_core', null, 'no default twenty* theme installed to test against; culprit is the active theme or WordPress core itself.');
                }
                return wpultra_bisect_set_next($state, 'theme_check', ['plugins' => [$state['self']], 'theme' => $default_theme]);
            }
            return wpultra_bisect_finish($state, 'theme_or_core', null, 'site is still broken with every plugin except wp-ultra-mcp deactivated; culprit is the active theme or WordPress core.');

        case 'theme_check':
            $note = $healthy
                ? 'switching to the default theme fixed the site; the active theme is the likely culprit.'
                : 'site remained broken even with a default theme active; this points to core/environment rather than the theme.';
            return wpultra_bisect_finish($state, 'theme_or_core', null, $note);

        case 'bisect':
            return wpultra_bisect_continue_bisect($state, $healthy);

        default:
            return wpultra_bisect_finish($state, 'inconclusive', null, 'unexpected internal state phase: ' . (string) $state['phase']);
    }
}

// ---------------------------------------------------------------------------
// PURE: probe classification (given an already-fetched response's metadata —
// callers pass in whether the request errored, the HTTP status, and the body;
// no WordPress/network calls happen here).
// ---------------------------------------------------------------------------

/** Fatal-error/critical-error markers. Reuses devtools.php's list when loaded; falls back to the same set otherwise. Pure. */
function wpultra_bisect_fatal_markers(): array {
    if (function_exists('wpultra_devtools_fatal_markers')) { return wpultra_devtools_fatal_markers(); }
    return ['Fatal error', 'There has been a critical error', 'Parse error', 'Uncaught Error', 'Uncaught Exception', 'Recoverable fatal error', 'Stack trace:'];
}

/** Classify one probe outcome as 'healthy'|'broken'|'probe_failed'. Pure. */
function wpultra_bisect_classify_probe(bool $is_error, int $status, string $body): string {
    if ($is_error) { return 'probe_failed'; }
    if ($status >= 500) { return 'broken'; }
    foreach (wpultra_bisect_fatal_markers() as $marker) {
        if (stripos($body, $marker) !== false) { return 'broken'; }
    }
    return 'healthy';
}

// ---------------------------------------------------------------------------
// PURE: small option-shape helpers
// ---------------------------------------------------------------------------

/** Coerce a raw `active_plugins` option value into a clean list of strings. Pure. */
function wpultra_bisect_normalize_active_list($raw): array {
    if (!is_array($raw)) { return []; }
    return array_values(array_map('strval', $raw));
}

/** Prepend self to a plugin list if it isn't already present. Pure. */
function wpultra_bisect_ensure_self(array $plugins, string $self): array {
    return in_array($self, $plugins, true) ? $plugins : array_merge([$self], $plugins);
}

// ---------------------------------------------------------------------------
// WP-touching wrappers (guarded; not exercised by the pure unit tests)
// ---------------------------------------------------------------------------

/** Our own plugin basename, so we never deactivate ourselves. Reuses the engine.php helper when present. */
function wpultra_bisect_self_plugin(): string {
    if (function_exists('wpultra_system_self_plugin')) { return wpultra_system_self_plugin(); }
    if (defined('WPULTRA_FILE') && function_exists('plugin_basename')) { return plugin_basename(WPULTRA_FILE); }
    return 'wp-ultra-mcp/wp-ultra-mcp.php';
}

/** Find an installed default twenty* theme stylesheet slug (newest first), or '' if none is installed. */
function wpultra_bisect_find_default_theme(): string {
    if (!function_exists('wp_get_themes')) { return ''; }
    $candidates = [];
    foreach (wp_get_themes() as $slug => $theme) {
        if (preg_match('/^twenty/i', (string) $slug)) { $candidates[] = (string) $slug; }
    }
    if ($candidates === []) { return ''; }
    rsort($candidates);
    return $candidates[0];
}

/**
 * Write `active_plugins` DIRECTLY (no activate_plugin()/deactivate_plugins())
 * so (de)activation hooks never fire. Validates the write round-trips.
 */
function wpultra_bisect_silent_set_active_plugins(array $plugins): bool {
    $plugins = array_values(array_map('strval', $plugins));
    update_option('active_plugins', $plugins);
    $read_back = wpultra_bisect_normalize_active_list(get_option('active_plugins', []));
    $expected = $plugins; sort($expected);
    $actual = $read_back; sort($actual);
    return $expected === $actual;
}

/** Silently swap the active theme via the template/stylesheet options directly (no switch_theme() hooks). */
function wpultra_bisect_silent_set_theme(string $stylesheet): bool {
    if ($stylesheet === '') { return false; }
    update_option('template', $stylesheet);
    update_option('stylesheet', $stylesheet);
    return (string) get_option('template') === $stylesheet && (string) get_option('stylesheet') === $stylesheet;
}

/**
 * Defensively allow the probe host/port through wp_safe_remote_* loopback
 * validation (mirrors includes/headless/revalidate.php's fix for the same
 * class of problem with webhook dispatch). The probe itself uses plain
 * wp_remote_get(), which isn't subject to this validation, but some hosting
 * environments layer their own safe-request wrappers on top — this keeps a
 * custom-port loopback probe from being silently blocked if that happens.
 */
function wpultra_bisect_allow_loopback_host(string $url): void {
    if (!function_exists('add_filter')) { return; }
    $host = parse_url($url, PHP_URL_HOST);
    if (!is_string($host) || $host === '') { return; }
    add_filter('http_request_host_is_external', function ($external, $h) use ($host) {
        if ($external) { return $external; }
        return strtolower((string) $h) === strtolower($host) ? true : $external;
    }, 10, 2);
    $port = parse_url($url, PHP_URL_PORT);
    if (is_int($port) && $port > 0) {
        add_filter('http_allowed_safe_ports', function ($ports) use ($port) {
            if (is_array($ports) && !in_array($port, $ports, true)) { $ports[] = $port; }
            return $ports;
        });
    }
}

/** Probe the target URL once. Returns 'healthy'|'broken'|'probe_failed'. */
function wpultra_bisect_probe(string $url): string {
    if (!function_exists('wp_remote_get')) { return 'probe_failed'; }
    $bust = (string) (function_exists('wp_rand') ? wp_rand() : mt_rand()) . '-' . (string) microtime(true);
    $probe_url = function_exists('add_query_arg') ? add_query_arg('wpultra_cb', $bust, $url) : $url . (str_contains($url, '?') ? '&' : '?') . 'wpultra_cb=' . rawurlencode($bust);
    $resp = wp_remote_get($probe_url, ['timeout' => 15, 'sslverify' => false, 'redirection' => 2]);
    if (is_wp_error($resp)) { return 'probe_failed'; }
    $status = (int) wp_remote_retrieve_response_code($resp);
    $body   = (string) wp_remote_retrieve_body($resp);
    return wpultra_bisect_classify_probe(false, $status, $body);
}

/** Apply one bisect-step subset to the live site: plugin set always, theme only when the step specifies one. */
function wpultra_bisect_apply_subset(array $next, string $self): void {
    $plugins = wpultra_bisect_ensure_self($next['plugins'] ?? [], $self);
    wpultra_bisect_silent_set_active_plugins($plugins);
    if (!empty($next['theme'])) {
        wpultra_bisect_silent_set_theme((string) $next['theme']);
    }
}

// ---------------------------------------------------------------------------
// Restore-safety: armed BEFORE any mutation, idempotent, covers every exit
// path (normal finish, exception, WP_Error, or a fatal that only the shutdown
// handler survives to see).
// ---------------------------------------------------------------------------

/** Arm the restore-on-shutdown safety net. Must be called before the first mutation. */
function wpultra_bisect_register_restore(array $original_plugins, string $original_template, string $original_stylesheet): void {
    $GLOBALS['wpultra_bisect_restore_state'] = [
        'plugins'     => $original_plugins,
        'template'    => $original_template,
        'stylesheet'  => $original_stylesheet,
        'restored'    => false,
    ];
    register_shutdown_function('wpultra_bisect_shutdown_restore');
}

/** Shutdown-time restore. No-ops if a clean finish already restored (idempotent guard). */
function wpultra_bisect_shutdown_restore(): void {
    $state = $GLOBALS['wpultra_bisect_restore_state'] ?? null;
    if ($state === null || !empty($state['restored'])) { return; }
    wpultra_bisect_do_restore();
}

/** Restore the original active_plugins (+ theme). Idempotent — safe to call from both `finally` and the shutdown hook. */
function wpultra_bisect_do_restore(): bool {
    $state = $GLOBALS['wpultra_bisect_restore_state'] ?? null;
    if ($state === null) { return true; }
    if (!empty($state['restored'])) { return true; }
    $ok = wpultra_bisect_silent_set_active_plugins($state['plugins']);
    if ($state['template'] !== '') { update_option('template', $state['template']); }
    if ($state['stylesheet'] !== '') { update_option('stylesheet', $state['stylesheet']); }
    $GLOBALS['wpultra_bisect_restore_state']['restored'] = true;
    return $ok;
}

// ---------------------------------------------------------------------------
// Orchestrator — the only WP-runtime entry point. Ability file delegates here.
// ---------------------------------------------------------------------------

/** @return array|WP_Error */
function wpultra_bisect_run(array $input) {
    if (!function_exists('update_option') || !function_exists('get_option')) {
        return wpultra_err('wp_unavailable', 'The WordPress options API is unavailable.');
    }
    if (($input['confirm'] ?? false) !== true) {
        return wpultra_err('confirm_required', 'conflict-bisect temporarily deactivates plugins to isolate a conflict. Re-run with confirm:true.');
    }

    $self               = wpultra_bisect_self_plugin();
    $original_raw       = wpultra_bisect_normalize_active_list(get_option('active_plugins', []));
    $original           = wpultra_bisect_ensure_self($original_raw, $self);
    $original_template  = (string) get_option('template', '');
    $original_stylesheet = (string) get_option('stylesheet', '');

    // FIRST thing before any mutation: arm the restore path (shutdown hook).
    wpultra_bisect_register_restore($original, $original_template, $original_stylesheet);

    $url = trim((string) ($input['url'] ?? (function_exists('home_url') ? home_url('/') : '')));
    if ($url === '') {
        wpultra_bisect_do_restore();
        return wpultra_err('missing_url', 'No URL could be resolved to probe (pass `url` explicitly or ensure home_url() works).');
    }

    $max_probes            = max(1, min(25, (int) ($input['max_probes'] ?? 20)));
    $theme_check_requested  = ($input['theme_check'] ?? false) === true;
    $theme_default          = $theme_check_requested ? wpultra_bisect_find_default_theme() : null;

    wpultra_bisect_allow_loopback_host($url);

    $result = null;
    try {
        $state = wpultra_bisect_init($original, $self, $max_probes, $theme_check_requested, $theme_default);
        $safety_counter = 0;
        while (!$state['done']) {
            $next = $state['next'];
            if ($next === null) { break; }
            wpultra_bisect_apply_subset($next, $self);
            $probe_result = wpultra_bisect_probe($url);
            $state = wpultra_bisect_next($state, $probe_result);
            $safety_counter++;
            if ($safety_counter > 60) {
                $state = wpultra_bisect_finish($state, 'inconclusive', null, 'internal safety stop: exceeded the hard loop guard.');
                break;
            }
        }
        $result = $state;
    } finally {
        wpultra_bisect_do_restore();
    }

    // `restored` MUST be verified by re-reading the option(s), not just trusting the write.
    $after_plugins = wpultra_bisect_normalize_active_list(get_option('active_plugins', []));
    $expected_plugins = $original; sort($expected_plugins);
    $actual_plugins = $after_plugins; sort($actual_plugins);
    $restored_plugins_ok = ($actual_plugins === $expected_plugins);

    $restored_theme_ok = true;
    if ($theme_check_requested && $theme_default !== null && $theme_default !== '') {
        $restored_theme_ok = (string) get_option('template') === $original_template
            && (string) get_option('stylesheet') === $original_stylesheet;
    }
    $restored = $restored_plugins_ok && $restored_theme_ok;

    if ($result === null) {
        return wpultra_err('bisect_failed', 'Bisection did not complete and produced no result.');
    }

    $note = (string) ($result['note'] ?? '');
    if (str_starts_with(strtolower(parse_url($url, PHP_URL_HOST) ?: ''), '127.') || in_array(parse_url($url, PHP_URL_HOST), ['localhost', '::1'], true)) {
        $note = trim($note . ' Note: probing a loopback host from inside this request can deadlock on single-PHP-worker dev hosts (e.g. Local by Flywheel); prefer a multi-worker host and keep max_probes low.');
    }

    wpultra_audit_log(
        'conflict-bisect',
        ($result['verdict'] ?? 'unknown') . ' culprit=' . ($result['culprit'] ?? 'none') . " probes={$result['probes_used']} restored=" . ($restored ? 'yes' : 'NO'),
        $restored
    );

    return wpultra_ok([
        'verdict'     => $result['verdict'],
        'culprit'     => $result['culprit'],
        'steps'       => $result['steps'],
        'probes_used' => $result['probes_used'],
        'restored'    => $restored,
        'note'        => $note,
    ]);
}
