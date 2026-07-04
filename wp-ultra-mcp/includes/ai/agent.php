<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * F2 — Agent mode / autonomous loop.
 *
 * The honest model: in MCP the CALLING AI (Claude) is the top-level agent. This
 * engine is a GOAL-ORIENTED EXECUTOR with a verify/retry loop layered on top of
 * the playbook engine (includes/playbooks/engine.php). It never re-implements
 * ability chaining — wpultra_playbook_run_steps() does that; here we add:
 *
 *   plan → execute (per step) → verify (check) → retry (bounded) → summarize.
 *
 * Two plan sources:
 *   (a) CLIENT-SUPPLIED plan (always available): the caller passes `steps`
 *       (playbook format) + optional per-step/global `checks`. This is the
 *       preferred mode because the calling AI is already an excellent planner.
 *   (b) SERVER-GENERATED plan (only when an OpenAI key is set): the caller passes
 *       a `goal` in natural language; we ask wpultra_ai_chat() (json mode) for a
 *       steps array constrained to the catalog of registered ability names, then
 *       run it. Without a key, `goal` alone returns a clear error asking the
 *       caller to supply explicit `steps`.
 *
 * Verify/retry: each step (or the whole run) may carry a `check`:
 *   {type: ability_ok | equals | contains | nonempty, path?, value?, needle?}.
 * A failed check retries the step up to max_retries (default 2). For AI-planned
 * runs the model may be re-asked with the failure to adjust (bounded, key-gated).
 *
 * Run record: CPT `wpultra_agent_run` storing {goal, steps, status, log, result,
 * created_at}. Long runs execute across cron ticks (like the jobs engine):
 * `wpultra_agent_tick` runs ONE step per tick then reschedules; cancel via meta.
 * Short runs (<= sync cap) execute inline.
 *
 * Everything above the "WordPress wrappers" divider is PURE (prefix
 * wpultra_agent_) and unit-tested in tests/agent.test.php.
 */

const WPULTRA_AGENT_CPT           = 'wpultra_agent_run';
const WPULTRA_AGENT_TICK_HOOK     = 'wpultra_agent_tick';
const WPULTRA_AGENT_MAX_STEPS     = 50;   // hard cap on any plan
const WPULTRA_AGENT_SYNC_CAP      = 10;   // inline `run` refuses plans longer than this
const WPULTRA_AGENT_DEFAULT_RETRY = 2;    // per-step retry budget
const WPULTRA_AGENT_RETRY_CAP     = 5;    // ceiling for caller-supplied max_retries
const WPULTRA_AGENT_LOG_CAP       = 200;  // ring-buffer of log entries

/* ================================================================== *
 * PURE core (no WordPress) — validated by tests/agent.test.php.
 * ================================================================== */

/** Valid run states. */
function wpultra_agent_states(): array {
    return ['planning', 'running', 'done', 'failed', 'cancelled'];
}

/** Valid check `type` values. */
function wpultra_agent_check_types(): array {
    return ['ability_ok', 'equals', 'contains', 'nonempty'];
}

/**
 * PURE. Read a dot-path out of a nested array/scalar. "a.b.0.c" walks arrays by
 * string OR integer key. Returns null on any missing segment (own copy so the
 * engine stays independent of the playbook engine at test time).
 */
function wpultra_agent_path($data, string $path) {
    $path = trim($path);
    if ($path === '') { return $data; }
    $cur = $data;
    foreach (explode('.', $path) as $seg) {
        if (is_array($cur) && array_key_exists($seg, $cur)) { $cur = $cur[$seg]; continue; }
        if (is_array($cur) && ctype_digit($seg) && array_key_exists((int) $seg, $cur)) { $cur = $cur[(int) $seg]; continue; }
        return null;
    }
    return $cur;
}

/**
 * PURE. Is this value the shape of a WP_Error / a failed ability result?
 * Recognises: an actual WP_Error object, an array with success===false, or an
 * array carrying an `errors` map (the array form WP_Error serialises to).
 */
function wpultra_agent_result_is_error($result): bool {
    if ($result instanceof \WP_Error) { return true; }
    if (is_array($result)) {
        if (array_key_exists('success', $result) && $result['success'] === false) { return true; }
        if (isset($result['errors']) && is_array($result['errors']) && $result['errors'] !== []) { return true; }
    }
    return false;
}

/**
 * PURE. Validate a check descriptor. @return true|string
 * Empty/absent check is allowed (treated as "no check" by the caller) — pass []
 * to skip. A present check must have a known `type` and the fields that type needs.
 */
function wpultra_agent_validate_check($check) {
    if (!is_array($check) || $check === []) { return true; }
    $type = (string) ($check['type'] ?? '');
    if ($type === '') { return 'check.type is required.'; }
    if (!in_array($type, wpultra_agent_check_types(), true)) {
        return "Unknown check.type '$type'. Allowed: " . implode(', ', wpultra_agent_check_types()) . '.';
    }
    switch ($type) {
        case 'equals':
            if (!array_key_exists('value', $check)) { return "check.type 'equals' requires a 'value'."; }
            break;
        case 'contains':
            if (!array_key_exists('needle', $check)) { return "check.type 'contains' requires a 'needle'."; }
            break;
        // ability_ok and nonempty need no extra fields ('path' is optional for all).
    }
    return true;
}

/**
 * PURE. Validate a steps array (agent plan). @return true|string
 * Non-empty; each step is an object with a non-empty `ability` string; blocks
 * nesting agent-run (recursion / fork-bomb guard); any per-step `check` is valid.
 */
function wpultra_agent_validate_plan($steps) {
    if (!is_array($steps) || $steps === []) { return 'steps must be a non-empty array.'; }
    if (count($steps) > WPULTRA_AGENT_MAX_STEPS) {
        return 'A plan may have at most ' . WPULTRA_AGENT_MAX_STEPS . ' steps.';
    }
    foreach ($steps as $i => $step) {
        if (!is_array($step)) { return "Step $i must be an object."; }
        $ability = $step['ability'] ?? null;
        if (!is_string($ability) || trim($ability) === '') { return "Step $i is missing an 'ability' string."; }
        $norm = ltrim(strtolower(trim($ability)), '/');
        // Never let an agent plan call agent-run (infinite recursion).
        if (in_array($norm, ['agent-run', 'wpultra/agent-run'], true)) {
            return "Step $i: agent plans cannot call agent-run (no nesting).";
        }
        if (array_key_exists('check', $step)) {
            $cv = wpultra_agent_validate_check($step['check']);
            if ($cv !== true) { return "Step $i: $cv"; }
        }
    }
    return true;
}

/**
 * PURE. Evaluate a check against a step result. @return array {passed:bool, reason:string}
 * Types:
 *   ability_ok — result is NOT WP_Error-shaped (and, when it has a `success`
 *                key, that key is truthy). `path` narrows to a sub-result first.
 *   equals     — value at `path` strictly equals `value` (loose for scalars via ==).
 *   contains   — `needle` is a substring of a string OR an element of an array at `path`.
 *   nonempty   — value at `path` resolves to a non-empty value ('' / [] / null / 0 / false = empty).
 */
function wpultra_agent_eval_check(array $check, $result): array {
    if ($check === []) { return ['passed' => true, 'reason' => 'no check']; }
    $type = (string) ($check['type'] ?? '');
    $path = (string) ($check['path'] ?? '');

    if ($type === 'ability_ok') {
        $target = $path === '' ? $result : wpultra_agent_path($result, $path);
        if (wpultra_agent_result_is_error($target)) {
            return ['passed' => false, 'reason' => 'result is an error'];
        }
        // If a `success` flag is present it must be truthy.
        if (is_array($target) && array_key_exists('success', $target) && !$target['success']) {
            return ['passed' => false, 'reason' => 'success flag is false'];
        }
        return ['passed' => true, 'reason' => 'result ok'];
    }

    $value = wpultra_agent_path($result, $path);

    switch ($type) {
        case 'equals':
            $want = $check['value'] ?? null;
            // Strict where possible; fall back to loose for numeric/string coercion.
            $passed = ($value === $want) || ((is_scalar($value) && is_scalar($want)) && ((string) $value === (string) $want));
            return ['passed' => $passed, 'reason' => $passed ? 'equal' : 'value at path did not equal expected'];

        case 'contains':
            $needle = $check['needle'] ?? null;
            if (is_array($value)) {
                $passed = in_array($needle, $value, false);
                return ['passed' => $passed, 'reason' => $passed ? 'needle in array' : 'needle not in array'];
            }
            if (is_string($value) && (is_string($needle) || is_numeric($needle))) {
                $passed = ($needle === '' ) ? true : (strpos($value, (string) $needle) !== false);
                return ['passed' => $passed, 'reason' => $passed ? 'needle in string' : 'needle not in string'];
            }
            return ['passed' => false, 'reason' => 'value at path is not a string or array'];

        case 'nonempty':
            $passed = !wpultra_agent_is_empty($value);
            return ['passed' => $passed, 'reason' => $passed ? 'value present' : 'value at path is empty'];
    }

    return ['passed' => false, 'reason' => "unknown check type '$type'"];
}

/** PURE. Emptiness used by the nonempty check: '' / [] / null / 0 / '0' / false are empty. */
function wpultra_agent_is_empty($v): bool {
    if ($v === null || $v === false) { return true; }
    if (is_string($v)) { return trim($v) === '' || $v === '0'; }
    if (is_array($v)) { return $v === []; }
    if (is_int($v) || is_float($v)) { return $v === 0 || $v === 0.0; }
    return false;
}

/**
 * PURE. Should a failed step be retried again? attempt is 1-based (the attempt
 * just completed). We stop when the check passed, or when we've used the budget.
 * With max=2: attempt 1 failed → retry (true); attempt 2 failed → stop (false).
 */
function wpultra_agent_should_retry(int $attempt, int $max, bool $passed): bool {
    if ($passed) { return false; }
    if ($max < 0) { $max = 0; }
    return $attempt <= $max;
}

/** PURE. Clamp a caller-supplied max_retries into [0, RETRY_CAP], default DEFAULT_RETRY. */
function wpultra_agent_clamp_retries($raw): int {
    if ($raw === null || $raw === '') { return WPULTRA_AGENT_DEFAULT_RETRY; }
    $n = (int) $raw;
    if ($n < 0) { return 0; }
    if ($n > WPULTRA_AGENT_RETRY_CAP) { return WPULTRA_AGENT_RETRY_CAP; }
    return $n;
}

/**
 * PURE. Roll up a run log into a summary. Each log entry is
 * {step, attempt, ok, check?, at?}. `passed`/`failed` count DISTINCT steps by
 * their final attempt; `retries` counts attempts beyond the first per step.
 * @return array {total, passed, failed, retries}
 */
function wpultra_agent_summarize(array $log): array {
    $final = [];   // step index => final ok
    $attempts = []; // step index => attempt count
    foreach ($log as $entry) {
        if (!is_array($entry)) { continue; }
        $step = (int) ($entry['step'] ?? 0);
        $final[$step] = (bool) ($entry['ok'] ?? false);
        $attempts[$step] = max($attempts[$step] ?? 0, (int) ($entry['attempt'] ?? 1));
    }
    $passed = 0; $failed = 0;
    foreach ($final as $ok) { $ok ? $passed++ : $failed++; }
    $retries = 0;
    foreach ($attempts as $a) { if ($a > 1) { $retries += $a - 1; } }
    return [
        'total'   => count($final),
        'passed'  => $passed,
        'failed'  => $failed,
        'retries' => $retries,
    ];
}

/**
 * PURE. Parse the model's plan JSON into a steps array. Accepts a raw JSON
 * object/array, or JSON inside a ```json fenced block (uses the LAST such block).
 * The document may be {steps:[...]} or a bare [...] array of steps.
 * @return array|string  steps array, or an error message string.
 */
function wpultra_agent_parse_plan(string $ai_json) {
    $raw = trim($ai_json);
    if ($raw === '') { return 'Empty plan response.'; }
    $json = $raw;
    if (preg_match_all('/```(?:json)?\s*\n(.*?)\n```/s', $raw, $m) && !empty($m[1])) {
        $json = trim((string) end($m[1]));
    }
    $decoded = json_decode($json, true);
    if ($decoded === null && strtolower($json) !== 'null') {
        return 'Plan is not valid JSON: ' . json_last_error_msg();
    }
    $steps = null;
    if (is_array($decoded) && isset($decoded['steps']) && is_array($decoded['steps'])) {
        $steps = $decoded['steps'];
    } elseif (is_array($decoded) && array_is_list($decoded)) {
        $steps = $decoded;
    }
    if (!is_array($steps) || $steps === []) {
        return 'Plan JSON did not contain a non-empty steps array.';
    }
    $valid = wpultra_agent_validate_plan($steps);
    if ($valid !== true) { return (string) $valid; }
    return array_values($steps);
}

/**
 * PURE. Build the {system, user} prompt that asks the model for a JSON steps
 * array given a goal + a catalog of available abilities. The system message
 * constrains the model to the provided ability names ONLY and to the playbook
 * step shape. $ability_catalog is [{name, summary}] or [name => summary].
 * @return array {system:string, user:string}
 */
function wpultra_agent_plan_prompt(string $goal, array $ability_catalog): array {
    $lines = [];
    foreach ($ability_catalog as $k => $v) {
        if (is_array($v)) {
            $name = (string) ($v['name'] ?? $k);
            $summary = (string) ($v['summary'] ?? '');
        } elseif (is_string($k)) {
            $name = $k; $summary = (string) $v;
        } else {
            $name = (string) $v; $summary = '';
        }
        $name = trim($name);
        if ($name === '') { continue; }
        $lines[] = $summary !== '' ? "- $name: $summary" : "- $name";
    }
    $catalog = implode("\n", $lines);

    $system = "You are a planning module for a WordPress automation agent. "
        . "Given a GOAL, output a JSON plan that chains the site's existing abilities. "
        . "Respond with ONLY a JSON object of the form "
        . '{"steps":[{"ability":"<name>","params":{...},"save_as":"<id?>","check":{"type":"ability_ok|equals|contains|nonempty","path":"..","value":"..","needle":".."}}]}. '
        . "Rules: use ONLY ability names from the provided catalog (verbatim); never invent abilities; "
        . "never use the 'agent-run' ability; keep the plan minimal (no more than " . WPULTRA_AGENT_MAX_STEPS . " steps); "
        . "reference an earlier step's output with {steps.<save_as>.<path>} tokens and inputs with {input.<key>}; "
        . "attach a 'check' to steps whose success should be verified. Output JSON only, no prose.";

    $user = "GOAL:\n" . trim($goal) . "\n\nAVAILABLE ABILITIES:\n" . ($catalog !== '' ? $catalog : '(none provided)');

    return ['system' => $system, 'user' => $user];
}

/**
 * PURE. Build one log entry for a step attempt.
 */
function wpultra_agent_log_entry(int $step, int $attempt, bool $ok, array $check_result = [], string $at = ''): array {
    return [
        'step'    => $step,
        'attempt' => $attempt,
        'ok'      => $ok,
        'check'   => $check_result,
        'at'      => $at,
    ];
}

/** PURE. Append to a capped log ring-buffer. */
function wpultra_agent_log_append(array $log, array $entry): array {
    $log[] = $entry;
    $n = count($log);
    if ($n > WPULTRA_AGENT_LOG_CAP) { $log = array_slice($log, $n - WPULTRA_AGENT_LOG_CAP); }
    return $log;
}

/** PURE. Extract the `check` for a step (or []). Normalises to an array. */
function wpultra_agent_step_check(array $step): array {
    $c = $step['check'] ?? [];
    return is_array($c) ? $c : [];
}

/**
 * PURE. Shape a run record for output.
 */
function wpultra_agent_shape(int $id, string $status, array $blob, string $created = '', string $updated = ''): array {
    $log = array_values((array) ($blob['log'] ?? []));
    return [
        'id'         => $id,
        'goal'       => (string) ($blob['goal'] ?? ''),
        'status'     => $status,
        'steps'      => is_array($blob['steps'] ?? null) ? $blob['steps'] : [],
        'log'        => $log,
        'result'     => $blob['result'] ?? null,
        'summary'    => wpultra_agent_summarize($log),
        'message'    => (string) ($blob['message'] ?? ''),
        'created_at' => $created,
        'updated_at' => $updated,
    ];
}

/**
 * PURE. Default blob for a new run.
 */
function wpultra_agent_new_blob(string $goal, array $steps, int $max_retries, array $inputs): array {
    return [
        'goal'        => $goal,
        'steps'       => $steps,
        'inputs'      => $inputs,
        'max_retries' => $max_retries,
        'cursor'      => 0,      // index of the next step to run (async)
        'log'         => [],
        'result'      => null,
        'message'     => '',
    ];
}

/* ================================================================== *
 * WordPress wrappers (guarded) — persistence, execution, cron, planning.
 * ================================================================== */

/** Register the run CPT. */
function wpultra_agent_register_cpt(): void {
    if (!function_exists('register_post_type')) { return; }
    register_post_type(WPULTRA_AGENT_CPT, [
        'public' => false, 'show_ui' => false, 'show_in_rest' => false,
        'supports' => ['title'], 'rewrite' => false,
    ]);
}

/**
 * Runtime boot: register the CPT + the cron tick hook. Cheap — safe on every
 * request. Called by the controller (and defensively by the ability).
 */
function wpultra_agent_boot(): void {
    static $booted = false;
    if ($booted) { return; }
    $booted = true;
    if (function_exists('did_action') && did_action('init')) { wpultra_agent_register_cpt(); }
    elseif (function_exists('add_action')) { add_action('init', 'wpultra_agent_register_cpt'); }
    if (function_exists('add_action')) { add_action(WPULTRA_AGENT_TICK_HOOK, 'wpultra_agent_tick'); }
}

/** Create a run record. @return int post id (0 on failure). */
function wpultra_agent_create(array $blob, string $status = 'planning'): int {
    if (!function_exists('wp_insert_post')) { return 0; }
    $id = wp_insert_post([
        'post_type'    => WPULTRA_AGENT_CPT,
        'post_status'  => 'private',
        'post_title'   => 'agent:' . mb_substr((string) ($blob['goal'] ?? 'run'), 0, 80),
        'post_content' => wp_slash((string) wp_json_encode($blob)),
    ], true);
    if (is_wp_error($id)) { return 0; }
    update_post_meta((int) $id, '_wpultra_agent_status', $status);
    return (int) $id;
}

/** Load a run's {status, blob, created, updated, cancel}. @return array|null */
function wpultra_agent_load(int $id): ?array {
    if (!function_exists('get_post')) { return null; }
    $post = get_post($id);
    if (!$post || $post->post_type !== WPULTRA_AGENT_CPT) { return null; }
    $blob = json_decode((string) $post->post_content, true);
    if (!is_array($blob)) { $blob = wpultra_agent_new_blob('', [], WPULTRA_AGENT_DEFAULT_RETRY, []); }
    return [
        'status'  => (string) (get_post_meta($id, '_wpultra_agent_status', true) ?: 'planning'),
        'blob'    => $blob,
        'created' => $post->post_date_gmt,
        'updated' => $post->post_modified_gmt,
        'cancel'  => get_post_meta($id, '_wpultra_agent_cancel', true) === '1',
    ];
}

/** Persist blob + status. */
function wpultra_agent_save(int $id, string $status, array $blob): void {
    if (!function_exists('wp_update_post')) { return; }
    wp_update_post(['ID' => $id, 'post_content' => wp_slash((string) wp_json_encode($blob))]);
    update_post_meta($id, '_wpultra_agent_status', $status);
}

/** Request cancellation of an async run. */
function wpultra_agent_request_cancel(int $id): bool {
    if (!function_exists('update_post_meta')) { return false; }
    $job = wpultra_agent_load($id);
    if ($job === null) { return false; }
    if (in_array($job['status'], ['done', 'failed', 'cancelled'], true)) { return false; }
    update_post_meta($id, '_wpultra_agent_cancel', '1');
    return true;
}

/** Schedule + loopback-kick the tick processor. */
function wpultra_agent_kick(): void {
    if (function_exists('wp_next_scheduled') && !wp_next_scheduled(WPULTRA_AGENT_TICK_HOOK)) {
        wp_schedule_single_event(time(), WPULTRA_AGENT_TICK_HOOK);
    }
    if (function_exists('spawn_cron')) { spawn_cron(); }
}

/** Oldest active run id, or 0. */
function wpultra_agent_next_active_id(): int {
    if (!function_exists('get_posts')) { return 0; }
    $ids = get_posts([
        'post_type'   => WPULTRA_AGENT_CPT,
        'post_status' => 'private',
        'numberposts' => 1,
        'orderby'     => 'date',
        'order'       => 'ASC',
        'fields'      => 'ids',
        'meta_query'  => [[
            'key'     => '_wpultra_agent_status',
            'value'   => ['planning', 'running'],
            'compare' => 'IN',
        ]],
    ]);
    return $ids ? (int) $ids[0] : 0;
}

/**
 * Execute ONE step (all retries) synchronously and return
 * [$log_entries, $step_result, $ok]. Reuses the playbook engine to run the
 * single ability (so token interpolation + ability lookup are shared), then
 * evaluates the step's check and retries up to $max_retries.
 */
function wpultra_agent_run_one_step(array $step, array $inputs, array $prior, int $step_index, int $max_retries): array {
    $check = wpultra_agent_step_check($step);
    $entries = [];
    $result = null;
    $ok = false;
    $attempt = 0;

    do {
        $attempt++;
        // Run just this step through the playbook engine (shares ability lookup +
        // {input.*} interpolation). Cross-step {steps.*} tokens are already
        // resolved by the driver (wpultra_agent_execute / _tick) before we run.
        $report = wpultra_playbook_run_steps([$step], $inputs, false, true);

        if (is_wp_error($report)) {
            $result = $report;
        } else {
            $srep = $report['steps'][0] ?? [];
            $result = ($srep['status'] ?? '') === 'ok' ? ($srep['result'] ?? []) : wpultra_err('step_failed', (string) ($srep['message'] ?? ($srep['error'] ?? 'step failed')));
        }

        $cres = wpultra_agent_eval_check($check, $result);
        $ok = (bool) $cres['passed'];
        $at = function_exists('current_time') ? (string) current_time('mysql', true) : gmdate('Y-m-d H:i:s');
        $entries[] = wpultra_agent_log_entry($step_index, $attempt, $ok, $cres, $at);

    } while (wpultra_agent_should_retry($attempt, $max_retries, $ok));

    return [$entries, $result, $ok];
}

/**
 * Drive a whole plan synchronously: resolve cross-step {steps.*} tokens as we go,
 * run each step with verify/retry, stop at the first step that fails its check
 * after exhausting retries. @return array blob-ready {log, result, message, status}
 */
function wpultra_agent_execute(array $steps, array $inputs, int $max_retries): array {
    $log = [];
    $saved = [];              // save_as => result (for {steps.*} tokens)
    $status = 'running';
    $message = '';
    $final_result = null;

    foreach ($steps as $i => $step) {
        // Resolve {steps.*} tokens in this step's params against prior saved results.
        $ctx = ['input' => $inputs, 'steps' => $saved];
        $resolved_step = $step;
        $resolved_step['params'] = wpultra_playbook_subst((array) ($step['params'] ?? []), $ctx);

        [$entries, $result, $ok] = wpultra_agent_run_one_step($resolved_step, $inputs, $saved, (int) $i, $max_retries);
        foreach ($entries as $e) { $log = wpultra_agent_log_append($log, $e); }
        $final_result = $result;

        if (!$ok) {
            $status = 'failed';
            $message = "Step $i failed its check after " . ($max_retries + 1) . ' attempt(s).';
            break;
        }
        $save_as = (string) ($step['save_as'] ?? '');
        if ($save_as !== '') {
            $saved[$save_as] = is_array($result) ? $result : ['value' => $result];
        }
    }

    if ($status === 'running') { $status = 'done'; $message = 'Agent run complete.'; }
    return ['log' => $log, 'result' => $final_result, 'message' => $message, 'status' => $status];
}

/**
 * The cron tick: run ONE step of the oldest active run, then reschedule if more
 * remain. Honours a cancellation request first.
 */
function wpultra_agent_tick(): void {
    $id = wpultra_agent_next_active_id();
    if ($id === 0) { return; }
    $job = wpultra_agent_load($id);
    if ($job === null) { return; }
    $blob = $job['blob'];

    if ($job['cancel']) {
        $blob['message'] = 'Cancelled.';
        wpultra_agent_save($id, 'cancelled', $blob);
        return;
    }

    $steps  = is_array($blob['steps'] ?? null) ? $blob['steps'] : [];
    $cursor = (int) ($blob['cursor'] ?? 0);
    $inputs = (array) ($blob['inputs'] ?? []);
    $max    = wpultra_agent_clamp_retries($blob['max_retries'] ?? WPULTRA_AGENT_DEFAULT_RETRY);

    if ($cursor >= count($steps)) {
        $blob['message'] = 'Agent run complete.';
        wpultra_agent_save($id, 'done', $blob);
        return;
    }

    // Rebuild saved context from prior steps' logged results is not stored per-step;
    // instead we persist a running `saved` map in the blob.
    $saved = (array) ($blob['saved'] ?? []);
    $step  = $steps[$cursor];
    $ctx   = ['input' => $inputs, 'steps' => $saved];
    $resolved_step = $step;
    $resolved_step['params'] = wpultra_playbook_subst((array) ($step['params'] ?? []), $ctx);

    [$entries, $result, $ok] = wpultra_agent_run_one_step($resolved_step, $inputs, $saved, $cursor, $max);
    foreach ($entries as $e) { $blob['log'] = wpultra_agent_log_append((array) ($blob['log'] ?? []), $e); }
    $blob['result'] = $result;

    if (!$ok) {
        $blob['message'] = "Step $cursor failed its check after " . ($max + 1) . ' attempt(s).';
        wpultra_agent_save($id, 'failed', $blob);
        return;
    }

    $save_as = (string) ($step['save_as'] ?? '');
    if ($save_as !== '') { $saved[$save_as] = is_array($result) ? $result : ['value' => $result]; }
    $blob['saved']  = $saved;
    $blob['cursor'] = $cursor + 1;

    $done = $blob['cursor'] >= count($steps);
    if ($done) {
        $blob['message'] = 'Agent run complete.';
        wpultra_agent_save($id, 'done', $blob);
    } else {
        wpultra_agent_save($id, 'running', $blob);
        if (function_exists('wp_schedule_single_event')) {
            wp_schedule_single_event(time() + 1, WPULTRA_AGENT_TICK_HOOK);
        }
        if (function_exists('spawn_cron')) { spawn_cron(); }
    }
}

/**
 * Build the catalog of registered ability names + short summaries for the planner.
 * Excludes agent-run itself. @return array<int,array{name:string,summary:string}>
 */
function wpultra_agent_ability_catalog(): array {
    $out = [];
    if (!function_exists('wp_get_abilities')) { return $out; }
    $abilities = wp_get_abilities();
    if (!is_array($abilities)) { return $out; }
    foreach ($abilities as $ability) {
        $name = '';
        $desc = '';
        if (is_object($ability) && method_exists($ability, 'get_name')) {
            $name = (string) $ability->get_name();
            if (method_exists($ability, 'get_description')) { $desc = (string) $ability->get_description(); }
        } elseif (is_array($ability)) {
            $name = (string) ($ability['name'] ?? '');
            $desc = (string) ($ability['description'] ?? '');
        }
        $short = trim(ltrim($name, '/'));
        if ($short === '' || in_array($short, ['agent-run', 'wpultra/agent-run'], true)) { continue; }
        // First sentence / 160 chars of the description keeps the prompt lean.
        $summary = trim(preg_replace('/\s+/', ' ', $desc));
        if (mb_strlen($summary) > 160) { $summary = mb_substr($summary, 0, 157) . '...'; }
        $out[] = ['name' => ltrim($short, ''), 'summary' => $summary];
    }
    return $out;
}

/**
 * Generate a plan from a natural-language goal using the shared AI helper.
 * @return array|WP_Error  a validated steps array, or an error.
 */
function wpultra_agent_generate_plan(string $goal) {
    if (!function_exists('wpultra_ai_has_key') || !wpultra_ai_has_key()) {
        return wpultra_err(
            'no_api_key',
            "Goal-mode planning needs a server-side OpenAI key (option 'wpultra_openai_api_key' or WPULTRA_OPENAI_API_KEY). "
            . 'Without one, supply an explicit `steps` array instead — the calling AI can plan the steps itself.'
        );
    }
    $catalog = wpultra_agent_ability_catalog();
    $prompt  = wpultra_agent_plan_prompt($goal, $catalog);
    $raw = wpultra_ai_chat($prompt['system'], $prompt['user'], ['json' => true, 'temperature' => 0.2]);
    if (is_wp_error($raw)) { return $raw; }

    $steps = wpultra_agent_parse_plan((string) $raw);
    if (is_string($steps)) { return wpultra_err('plan_parse_failed', 'Could not parse the AI plan: ' . $steps); }
    return $steps;
}
