<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

// Defensively require the engine + the shared AI helper + the playbook engine we
// reuse, regardless of bootstrap load order (mirrors how F1 abilities load
// includes/ai/setup.php and how woo-bulk-edit loads its engine file).
if (defined('WPULTRA_DIR')) {
    if (!function_exists('wpultra_agent_validate_plan') && is_readable(WPULTRA_DIR . 'includes/ai/agent.php')) {
        require_once WPULTRA_DIR . 'includes/ai/agent.php';
    }
    if (!function_exists('wpultra_ai_chat') && is_readable(WPULTRA_DIR . 'includes/ai/setup.php')) {
        require_once WPULTRA_DIR . 'includes/ai/setup.php';
    }
    if (!function_exists('wpultra_playbook_run_steps') && is_readable(WPULTRA_DIR . 'includes/playbooks/engine.php')) {
        require_once WPULTRA_DIR . 'includes/playbooks/engine.php';
    }
}
// Register the cron tick hook + CPT as soon as the engine is available.
if (function_exists('wpultra_agent_boot')) { wpultra_agent_boot(); }

wp_register_ability('wpultra/agent-run', [
    'label'       => __('AI Agent: Goal-Oriented Autonomous Run', 'wp-ultra-mcp'),
    'description' => __(
        'Agent mode — give a goal (or an explicit plan) and the agent executes it with a plan -> execute -> verify -> retry loop, on top of the playbook engine (it chains your EXISTING abilities, it does not invent new capabilities). '
        . 'HONEST MODEL: in MCP the calling AI (you) is the top-level agent; this ability is a goal-oriented executor with a verify/retry loop. '
        . 'TWO PLAN SOURCES: '
        . '(a) STEPS mode (always works, preferred) — pass `steps` in playbook format: [{ability, params, save_as?, continue_on_error?, check?}]. Reference earlier results with {steps.<save_as>.<path>} tokens and run inputs with {input.<key>}. You (the calling AI) supply the plan directly. '
        . '(b) GOAL mode (needs a server-side OpenAI key) — pass a natural-language `goal`; the server asks the model for a steps array constrained to your registered ability names, then runs it. Without a key, `goal` alone returns a clear error asking you to pass `steps` (which you can plan yourself). '
        . 'VERIFY/RETRY: attach a `check` to any step = {type: ability_ok | equals | contains | nonempty, path?, value?, needle?}. A failed check retries that step up to `max_retries` (default 2, cap 5). '
        . 'ACTIONS: '
        . 'plan-only {goal} -> return the AI-generated (or echoed client) plan WITHOUT executing (always safe, no confirm). '
        . 'run {steps|goal, inputs?, max_retries?, confirm:true} -> execute inline with verify/retry and return the run record + summary. Inline runs are capped at ' . WPULTRA_AGENT_SYNC_CAP . ' steps; longer plans must use run-async. '
        . 'run-async {steps|goal, inputs?, max_retries?, confirm:true} -> persist a run record and process it one step per WP-Cron tick in the background; returns the run id (poll with status). '
        . 'status {id} -> the run record (goal, steps, status, per-attempt log, result, summary). list -> recent runs. cancel {id} -> request cancellation (checked before each async tick). '
        . 'SAFETY: run/run-async execute REAL abilities that may mutate the site, so they are confirm-gated (confirm:true). plan-only never executes. Nesting agent-run inside a plan is blocked (recursion guard). '
        . 'EXAMPLES: {action:"run", confirm:true, steps:[{ability:"create-post", params:{title:"Hi", status:"draft"}, save_as:"p", check:{type:"ability_ok"}}, {ability:"get-post", params:{id:"{steps.p.id}"}, check:{type:"equals", path:"post.status", value:"draft"}}]}. '
        . '{action:"plan-only", goal:"Publish a welcome post and add it to the main menu"}.',
        'wp-ultra-mcp'
    ),
    'category'     => 'ai',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'action'      => ['type' => 'string', 'enum' => ['run', 'run-async', 'plan-only', 'status', 'list', 'cancel']],
            'goal'        => ['type' => 'string'],
            'steps'       => [
                'type'  => 'array',
                'items' => [
                    'type'       => 'object',
                    'properties' => [
                        'ability'           => ['type' => 'string'],
                        'params'            => ['type' => 'object'],
                        'save_as'           => ['type' => 'string'],
                        'continue_on_error' => ['type' => 'boolean'],
                        'check'             => [
                            'type'       => 'object',
                            'properties' => [
                                'type'   => ['type' => 'string', 'enum' => ['ability_ok', 'equals', 'contains', 'nonempty']],
                                'path'   => ['type' => 'string'],
                                // Free-form comparison operands (any JSON scalar/array).
                                'value'  => ['type' => ['string', 'number', 'integer', 'boolean', 'array', 'null']],
                                'needle' => ['type' => ['string', 'number', 'integer', 'boolean', 'array', 'null']],
                            ],
                        ],
                    ],
                ],
            ],
            'inputs'      => ['type' => 'object'],
            'max_retries' => ['type' => 'integer'],
            'id'          => ['type' => 'integer'],
            'confirm'     => ['type' => 'boolean'],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'action'  => ['type' => 'string'],
            'run'     => ['type' => 'object'],
            'runs'    => ['type' => 'array'],
            'plan'    => ['type' => 'array'],
            'summary' => ['type' => 'object'],
            'id'      => ['type' => 'integer'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_agent_run_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

/**
 * Resolve the plan from the input: explicit `steps` (validated) or an
 * AI-generated plan from `goal`. @return array|WP_Error  a steps array.
 */
function wpultra_agent_resolve_plan(array $input) {
    $steps = $input['steps'] ?? null;
    if (is_array($steps) && $steps !== []) {
        $valid = wpultra_agent_validate_plan($steps);
        if ($valid !== true) { return wpultra_err('invalid_plan', (string) $valid); }
        return array_values($steps);
    }
    $goal = trim((string) ($input['goal'] ?? ''));
    if ($goal === '') {
        return wpultra_err('missing_plan', 'Pass either an explicit `steps` array (preferred) or a natural-language `goal` (goal-mode needs a server-side OpenAI key).');
    }
    return wpultra_agent_generate_plan($goal);
}

function wpultra_agent_run_cb(array $input) {
    if (!function_exists('wpultra_agent_validate_plan')) {
        return wpultra_err('agent_engine_missing', 'The agent engine (includes/ai/agent.php) is not loaded.');
    }
    if (!function_exists('wpultra_playbook_run_steps')) {
        return wpultra_err('playbook_engine_missing', 'The playbook engine (includes/playbooks/engine.php) is not loaded; the agent executor depends on it.');
    }
    wpultra_agent_boot();

    $action  = (string) ($input['action'] ?? '');
    $inputs  = is_array($input['inputs'] ?? null) ? $input['inputs'] : [];
    $retries = wpultra_agent_clamp_retries($input['max_retries'] ?? null);

    switch ($action) {

        case 'plan-only': {
            $plan = wpultra_agent_resolve_plan($input);
            if (is_wp_error($plan)) { return $plan; }
            $source = (is_array($input['steps'] ?? null) && $input['steps'] !== []) ? 'client' : 'ai';
            wpultra_audit_log('agent-run', "plan-only ($source): " . count($plan) . ' step(s)', true);
            return wpultra_ok([
                'action'  => 'plan-only',
                'plan'    => $plan,
                'summary' => ['steps' => count($plan), 'source' => $source, 'executed' => false],
            ]);
        }

        case 'run': {
            if (($input['confirm'] ?? false) !== true) {
                return wpultra_err('agent_unconfirmed', 'Running an agent executes real abilities that may mutate the site. Re-run with confirm:true (use action:"plan-only" first to preview).');
            }
            $plan = wpultra_agent_resolve_plan($input);
            if (is_wp_error($plan)) { return $plan; }
            if (count($plan) > WPULTRA_AGENT_SYNC_CAP) {
                return wpultra_err('plan_too_long_for_sync', 'Inline run is capped at ' . WPULTRA_AGENT_SYNC_CAP . ' steps (' . count($plan) . ' supplied). Use action:"run-async" for longer plans.');
            }

            $goal = trim((string) ($input['goal'] ?? ''));
            $exec = wpultra_agent_execute($plan, $inputs, $retries);
            $blob = wpultra_agent_new_blob($goal, $plan, $retries, $inputs);
            $blob['log']     = $exec['log'];
            $blob['result']  = $exec['result'];
            $blob['message'] = $exec['message'];

            // Persist a record (best-effort) so it also shows up under status/list.
            $id = wpultra_agent_create($blob, $exec['status']);
            $summary = wpultra_agent_summarize($exec['log']);
            $ok = $exec['status'] === 'done';
            wpultra_audit_log('agent-run', "run: {$exec['status']} steps_ok={$summary['passed']} failed={$summary['failed']} retries={$summary['retries']}", $ok);

            $created = function_exists('current_time') ? (string) current_time('mysql', true) : gmdate('Y-m-d H:i:s');
            return wpultra_ok([
                'action'  => 'run',
                'id'      => $id,
                'run'     => wpultra_agent_shape($id, $exec['status'], $blob, $created, $created),
                'summary' => $summary,
            ]);
        }

        case 'run-async': {
            if (($input['confirm'] ?? false) !== true) {
                return wpultra_err('agent_unconfirmed', 'Running an agent executes real abilities that may mutate the site. Re-run with confirm:true.');
            }
            $plan = wpultra_agent_resolve_plan($input);
            if (is_wp_error($plan)) { return $plan; }

            $goal = trim((string) ($input['goal'] ?? ''));
            $blob = wpultra_agent_new_blob($goal, $plan, $retries, $inputs);
            $id = wpultra_agent_create($blob, 'running');
            if ($id === 0) { return wpultra_err('agent_persist_failed', 'Could not persist the agent run record.'); }
            wpultra_agent_kick();
            wpultra_audit_log('agent-run', "run-async: queued id=$id (" . count($plan) . ' steps)', true);
            return wpultra_ok([
                'action'  => 'run-async',
                'id'      => $id,
                'run'     => wpultra_agent_shape($id, 'running', $blob),
                'summary' => ['steps' => count($plan), 'status' => 'running'],
            ]);
        }

        case 'status': {
            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) { return wpultra_err('missing_id', 'Pass the run `id` to check status.'); }
            $job = wpultra_agent_load($id);
            if ($job === null) { return wpultra_err('run_not_found', "No agent run with id $id."); }
            return wpultra_ok([
                'action' => 'status',
                'id'     => $id,
                'run'    => wpultra_agent_shape($id, $job['status'], $job['blob'], (string) $job['created'], (string) $job['updated']),
            ]);
        }

        case 'list': {
            $runs = [];
            if (function_exists('get_posts')) {
                $posts = get_posts([
                    'post_type'   => WPULTRA_AGENT_CPT,
                    'post_status' => 'private',
                    'numberposts' => 30,
                    'orderby'     => 'date',
                    'order'       => 'DESC',
                ]);
                foreach ($posts as $p) {
                    $blob = json_decode((string) $p->post_content, true);
                    if (!is_array($blob)) { $blob = []; }
                    $status = (string) (get_post_meta($p->ID, '_wpultra_agent_status', true) ?: 'planning');
                    $shaped = wpultra_agent_shape((int) $p->ID, $status, $blob, (string) $p->post_date_gmt, (string) $p->post_modified_gmt);
                    // Trim heavy fields for the list view.
                    $runs[] = [
                        'id'         => $shaped['id'],
                        'goal'       => $shaped['goal'],
                        'status'     => $shaped['status'],
                        'summary'    => $shaped['summary'],
                        'created_at' => $shaped['created_at'],
                        'updated_at' => $shaped['updated_at'],
                    ];
                }
            }
            return wpultra_ok(['action' => 'list', 'runs' => $runs]);
        }

        case 'cancel': {
            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) { return wpultra_err('missing_id', 'Pass the run `id` to cancel.'); }
            $ok = wpultra_agent_request_cancel($id);
            if (!$ok) { return wpultra_err('cancel_failed', "Run $id was not found or is already finished."); }
            wpultra_audit_log('agent-run', "cancel requested id=$id", true);
            return wpultra_ok(['action' => 'cancel', 'id' => $id, 'summary' => ['cancel_requested' => true]]);
        }

        default:
            return wpultra_err('unknown_action', "Unknown action '$action'. Use one of: run, run-async, plan-only, status, list, cancel.");
    }
}
