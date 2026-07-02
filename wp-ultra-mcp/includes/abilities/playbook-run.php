<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/playbook-run', [
    'label'       => __('Run Playbook', 'wp-ultra-mcp'),
    'description' => __('Run a multi-step playbook that chains other wpultra abilities in order. Provide inline `steps` OR a saved `slug`. Each step: {ability, params, save_as?, continue_on_error?}. Params may reference the playbook\'s `inputs` via {input.key} and any earlier step\'s result via {steps.<save_as>.<path>} (a lone {token} keeps its type, e.g. a post id stays an integer). Use dry_run:true to resolve tokens and validate abilities without executing. Example step: {"ability":"create-post","params":{"title":"{input.title}"},"save_as":"post"} then {"ability":"update-post","params":{"id":"{steps.post.id}","status":"publish"}}.', 'wp-ultra-mcp'),
    'category'    => 'playbooks',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'slug'          => ['type' => 'string', 'description' => 'Run a saved playbook by slug (alternative to steps).'],
            'steps'         => ['type' => 'array', 'description' => 'Inline steps: [{ability, params, save_as?, continue_on_error?}].'],
            'inputs'        => ['type' => 'object', 'description' => 'Values for {input.*} tokens.'],
            'dry_run'       => ['type' => 'boolean', 'description' => 'Resolve + validate without executing.'],
            'stop_on_error' => ['type' => 'boolean', 'description' => 'Halt on the first failing step (default true).'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'      => ['type' => 'boolean'],
            'completed'    => ['type' => 'boolean'],
            'steps_ok'     => ['type' => 'integer'],
            'steps_failed' => ['type' => 'integer'],
            'message'      => ['type' => 'string'],
            'steps'        => ['type' => 'array'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_playbook_run_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_playbook_run_cb(array $input) {
    $inputs        = (array) ($input['inputs'] ?? []);
    $dry_run       = ($input['dry_run'] ?? false) === true;
    $stop_on_error = array_key_exists('stop_on_error', $input) ? ($input['stop_on_error'] === true) : true;

    $steps = null;
    if (!empty($input['slug'])) {
        $doc = wpultra_playbook_load((string) $input['slug']);
        if ($doc === null) { return wpultra_err('not_found', "No saved playbook '{$input['slug']}'."); }
        $parsed = wpultra_playbook_parse($doc);
        if ($parsed === null) { return wpultra_err('bad_playbook', 'Saved playbook is not valid JSON with a steps array.'); }
        $steps = $parsed['steps'];
    } elseif (isset($input['steps'])) {
        $steps = (array) $input['steps'];
    } else {
        return wpultra_err('missing_steps', 'Provide inline steps or a saved slug.');
    }

    $res = wpultra_playbook_run_steps($steps, $inputs, $dry_run, $stop_on_error);
    if (is_wp_error($res)) { return $res; }
    if (!$dry_run) {
        wpultra_audit_log('playbook-run', "ran {$res['steps_ok']} ok / {$res['steps_failed']} failed", $res['completed']);
    }
    return wpultra_ok($res);
}
