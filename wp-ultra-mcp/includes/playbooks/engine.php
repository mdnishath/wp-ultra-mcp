<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Playbooks: chain many existing wpultra abilities into one declarative,
 * multi-step run. Each step calls an ability with params; a step's result can
 * be referenced by later steps via {steps.<save_as>.<path>} tokens, and the
 * playbook's own inputs via {input.<key>}. This turns "set up a whole blog"
 * (register a CPT, create several posts, set options, build a menu) into one
 * command, where today's recipe engine runs a single wp-cli/sql/php/http action.
 *
 * A playbook document is JSON (optionally inside a ```json fenced markdown
 * block with --- frontmatter, like recipes):
 *   { "name","description","inputs":{k:{type,required}}, "steps":[
 *       {"ability":"register-cpt","params":{...},"save_as":"cpt","continue_on_error":false}, ... ] }
 */

const WPULTRA_PLAYBOOK_CPT       = 'wpultra_playbook';
const WPULTRA_PLAYBOOK_MAX_STEPS = 100;

/* ------------------------------------------------------------------ *
 * PURE: token resolution + substitution + dot-path + validation.
 * ------------------------------------------------------------------ */

/**
 * Pure: read a dot-path out of a nested array/scalar. Returns [found, value].
 * "a.b.0.c" walks arrays by string OR integer key.
 */
function wpultra_playbook_path($data, string $path): array {
    if ($path === '') { return [true, $data]; }
    $cur = $data;
    foreach (explode('.', $path) as $seg) {
        if (is_array($cur) && array_key_exists($seg, $cur)) { $cur = $cur[$seg]; continue; }
        if (is_array($cur) && ctype_digit($seg) && array_key_exists((int) $seg, $cur)) { $cur = $cur[(int) $seg]; continue; }
        return [false, null];
    }
    return [true, $cur];
}

/**
 * Pure: resolve a single token name ("input.x" | "steps.y.z") against the
 * context {input:[...], steps:[save_as => result]}. Returns [found, value].
 */
function wpultra_playbook_resolve(string $token, array $ctx): array {
    $token = trim($token);
    if (str_starts_with($token, 'input.')) {
        return wpultra_playbook_path($ctx['input'] ?? [], substr($token, 6));
    }
    if (str_starts_with($token, 'steps.')) {
        return wpultra_playbook_path($ctx['steps'] ?? [], substr($token, 6));
    }
    return [false, null];
}

/**
 * Pure: substitute tokens through a params structure. A string that is EXACTLY
 * one "{token}" is replaced by the raw typed value (so an integer id stays an
 * int); tokens embedded in text interpolate as strings. Arrays recurse.
 * Unknown tokens: whole-value → null; embedded → empty string.
 */
function wpultra_playbook_subst($value, array $ctx) {
    if (is_array($value)) {
        $out = [];
        foreach ($value as $k => $v) { $out[$k] = wpultra_playbook_subst($v, $ctx); }
        return $out;
    }
    if (!is_string($value)) { return $value; }

    // Whole-value single token → preserve type.
    if (preg_match('/^\{([a-zA-Z0-9_.]+)\}$/', $value, $m)) {
        [$found, $resolved] = wpultra_playbook_resolve($m[1], $ctx);
        return $found ? $resolved : null;
    }
    // Embedded interpolation.
    return preg_replace_callback('/\{([a-zA-Z0-9_.]+)\}/', function ($m) use ($ctx) {
        [$found, $resolved] = wpultra_playbook_resolve($m[1], $ctx);
        if (!$found) { return ''; }
        if (is_array($resolved) || is_object($resolved)) { return (string) wp_json_encode($resolved); }
        if (is_bool($resolved)) { return $resolved ? 'true' : 'false'; }
        return (string) $resolved;
    }, $value);
}

/**
 * Pure: validate a steps array. @return true|string
 */
function wpultra_playbook_validate_steps($steps) {
    if (!is_array($steps) || $steps === []) { return 'steps must be a non-empty array.'; }
    if (count($steps) > WPULTRA_PLAYBOOK_MAX_STEPS) { return 'A playbook may have at most ' . WPULTRA_PLAYBOOK_MAX_STEPS . ' steps.'; }
    $seen = [];
    foreach ($steps as $i => $step) {
        if (!is_array($step)) { return "Step $i must be an object."; }
        $ability = (string) ($step['ability'] ?? '');
        if ($ability === '') { return "Step $i is missing 'ability'."; }
        // Playbooks may not nest playbook-run (recursion / fork-bomb guard).
        if (in_array(ltrim($ability, '/'), ['playbook-run', 'wpultra/playbook-run'], true)) {
            return "Step $i: playbooks cannot call playbook-run (no nesting).";
        }
        $save = (string) ($step['save_as'] ?? '');
        if ($save !== '') {
            if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $save)) { return "Step $i: save_as '$save' must be an identifier."; }
            if (isset($seen[$save])) { return "Step $i: duplicate save_as '$save'."; }
            $seen[$save] = true;
        }
    }
    return true;
}

/** Pure: normalize an ability slug to its full wpultra/ name. */
function wpultra_playbook_ability_name(string $ability): string {
    $a = ltrim(trim($ability), '/');
    return str_starts_with($a, 'wpultra/') ? $a : 'wpultra/' . $a;
}

/**
 * Pure: parse a playbook document — raw JSON, or the last ```json block of a
 * markdown doc (mirrors the recipe parser). @return array|WP_Error-shaped via caller.
 */
function wpultra_playbook_parse(string $raw): ?array {
    $raw = trim($raw);
    if ($raw === '') { return null; }
    $json = $raw;
    if (preg_match_all('/```json\s*\n(.*?)\n```/s', $raw, $jm) && !empty($jm[1])) {
        $json = trim((string) end($jm[1]));
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded) || !isset($decoded['steps'])) { return null; }
    return [
        'name'        => (string) ($decoded['name'] ?? ''),
        'description' => (string) ($decoded['description'] ?? ''),
        'inputs'      => is_array($decoded['inputs'] ?? null) ? $decoded['inputs'] : [],
        'steps'       => is_array($decoded['steps'] ?? null) ? $decoded['steps'] : [],
    ];
}

/* ------------------------------------------------------------------ *
 * Execution (thin — calls the abilities registry).
 * ------------------------------------------------------------------ */

/**
 * Run an ordered list of steps. Returns a per-step report; stops at the first
 * failure unless that step has continue_on_error:true. When $dry_run, tokens are
 * resolved and abilities are existence-checked but nothing executes.
 * @return array|WP_Error
 */
function wpultra_playbook_run_steps(array $steps, array $inputs, bool $dry_run = false, bool $stop_on_error = true) {
    $valid = wpultra_playbook_validate_steps($steps);
    if ($valid !== true) { return wpultra_err('invalid_playbook', (string) $valid); }

    $ctx = ['input' => $inputs, 'steps' => []];
    $report = [];
    $ok_count = 0; $fail_count = 0;

    foreach ($steps as $i => $step) {
        $name    = wpultra_playbook_ability_name((string) $step['ability']);
        $save_as = (string) ($step['save_as'] ?? '');
        $cont    = ($step['continue_on_error'] ?? false) === true;
        $params  = wpultra_playbook_subst((array) ($step['params'] ?? []), $ctx);

        $ability = function_exists('wp_get_ability') ? wp_get_ability($name) : null;
        if (!$ability) {
            $entry = ['step' => $i, 'ability' => $name, 'status' => 'error', 'error' => 'ability_not_found'];
            $report[] = $entry; $fail_count++;
            if (!$cont && $stop_on_error) { return wpultra_playbook_result($report, $ok_count, $fail_count, false, "Step $i: ability $name not found."); }
            continue;
        }

        if ($dry_run) {
            $report[] = ['step' => $i, 'ability' => $name, 'status' => 'planned', 'params' => $params, 'save_as' => $save_as];
            // Make a placeholder available so later {steps.x} tokens still resolve in the plan.
            if ($save_as !== '') { $ctx['steps'][$save_as] = ['__planned__' => true]; }
            $ok_count++;
            continue;
        }

        try {
            $result = $ability->execute($params);
        } catch (\Throwable $e) {
            $result = wpultra_err('step_threw', $e->getMessage());
        }

        if (is_wp_error($result)) {
            $report[] = ['step' => $i, 'ability' => $name, 'status' => 'error', 'error' => $result->get_error_code(), 'message' => $result->get_error_message()];
            $fail_count++;
            if (!$cont && $stop_on_error) { return wpultra_playbook_result($report, $ok_count, $fail_count, false, "Step $i ($name) failed: " . $result->get_error_message()); }
            continue;
        }

        $arr = is_array($result) ? $result : ['value' => $result];
        if ($save_as !== '') { $ctx['steps'][$save_as] = $arr; }
        $report[] = ['step' => $i, 'ability' => $name, 'status' => 'ok', 'save_as' => $save_as, 'result' => $arr];
        $ok_count++;
    }

    $all_ok = $fail_count === 0;
    return wpultra_playbook_result($report, $ok_count, $fail_count, $all_ok, $all_ok ? 'Playbook complete.' : "$fail_count step(s) failed.");
}

/** Shape the run result. */
function wpultra_playbook_result(array $report, int $ok, int $fail, bool $completed, string $message): array {
    return [
        'completed' => $completed,
        'steps_ok'  => $ok,
        'steps_failed' => $fail,
        'message'   => $message,
        'steps'     => $report,
    ];
}

/* ------------------------------------------------------------------ *
 * Saved-playbook CPT store.
 * ------------------------------------------------------------------ */

function wpultra_playbook_register_cpt(): void {
    register_post_type(WPULTRA_PLAYBOOK_CPT, [
        'public' => false, 'show_ui' => false, 'show_in_rest' => false,
        'supports' => ['title', 'editor', 'excerpt'], 'rewrite' => false,
    ]);
}

/** @return int post id (0 on failure) */
function wpultra_playbook_save(string $slug, string $doc, string $description = ''): int {
    $existing = get_page_by_path($slug, OBJECT, WPULTRA_PLAYBOOK_CPT);
    $postarr = [
        'post_type'    => WPULTRA_PLAYBOOK_CPT,
        'post_name'    => $slug,
        'post_title'   => $slug,
        'post_excerpt' => $description,
        'post_status'  => 'publish',
        'post_content' => wp_slash($doc),
    ];
    if ($existing) { $postarr['ID'] = $existing->ID; }
    $id = wp_insert_post($postarr, true);
    return is_wp_error($id) ? 0 : (int) $id;
}

/** @return string|null raw doc */
function wpultra_playbook_load(string $slug): ?string {
    $p = get_page_by_path($slug, OBJECT, WPULTRA_PLAYBOOK_CPT);
    return $p ? (string) $p->post_content : null;
}

function wpultra_playbook_list(): array {
    $posts = get_posts(['post_type' => WPULTRA_PLAYBOOK_CPT, 'post_status' => 'publish', 'numberposts' => 200]);
    $out = [];
    foreach ($posts as $p) {
        $parsed = wpultra_playbook_parse((string) $p->post_content);
        $out[] = [
            'slug'        => $p->post_name,
            'name'        => $parsed['name'] ?? $p->post_name,
            'description' => $p->post_excerpt,
            'steps'       => is_array($parsed['steps'] ?? null) ? count($parsed['steps']) : 0,
        ];
    }
    return $out;
}

function wpultra_playbook_delete(string $slug): bool {
    $p = get_page_by_path($slug, OBJECT, WPULTRA_PLAYBOOK_CPT);
    if (!$p) { return false; }
    return (bool) wp_delete_post($p->ID, true);
}
