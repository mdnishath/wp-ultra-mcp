<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/headless-revalidate', [
    'label'       => __('Headless: Revalidate Bridge', 'wp-ultra-mcp'),
    'description' => __('Edit in WP → the static frontend updates itself. actions: `status` (config + recent fire log), `enable` (endpoint required — the scaffold\'s /api/revalidate URL or any Vercel/Netlify build hook; creates async webhook triggers on post publish/update that POST {secret, event, post_id, post_type, path}), `disable` (removes the triggers), `test` (POSTs to the endpoint right now and returns the response — instant end-to-end check). The secret matches the frontend\'s REVALIDATE_SECRET env; rides the triggers engine so dispatch is async and can never block publishing.', 'wp-ultra-mcp'),
    'category'    => 'headless',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action'   => ['type' => 'string', 'enum' => ['status', 'enable', 'disable', 'test'], 'default' => 'status'],
            'endpoint' => ['type' => 'string', 'description' => 'Full revalidate/build-hook URL (for action:enable).'],
            'secret'   => ['type' => 'string', 'description' => 'Shared secret; generated when omitted. Set the same value as REVALIDATE_SECRET on the frontend.'],
            'events'   => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['post_published', 'post_updated']], 'description' => 'Default: both.'],
        ],
        'required'             => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'  => ['type' => 'boolean'],
            'config'   => ['type' => 'object'],
            'triggers' => ['type' => 'array'],
            'log'      => ['type' => 'array'],
            'response' => ['type' => 'object'],
            'note'     => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_headless_revalidate_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_headless_revalidate_cb(array $input) {
    if (!function_exists('wpultra_triggers_create')) {
        return wpultra_err('triggers_disabled', 'The triggers category is disabled — headless-revalidate rides the triggers engine.');
    }
    $action = (string) ($input['action'] ?? 'status');
    $cfg    = wpultra_headless_reval_config();

    if ($action === 'enable') {
        $valid = wpultra_headless_reval_validate($input);
        if (is_string($valid)) { return wpultra_err('bad_endpoint', $valid); }
        // Re-enabling replaces the previous bridge triggers.
        foreach ($cfg['trigger_ids'] as $old) { wpultra_triggers_delete((int) $old); }
        $secret = (string) ($input['secret'] ?? '');
        if ($secret === '') { $secret = $cfg['secret'] !== '' ? $cfg['secret'] : wpultra_headless_generate_secret(); }
        $events = (array) ($input['events'] ?? ['post_published', 'post_updated']);
        $ids = [];
        foreach (wpultra_headless_reval_trigger_defs($valid['endpoint'], $secret, $events) as $def) {
            $check = wpultra_triggers_validate($def);
            if ($check !== true) { return wpultra_err('bad_trigger', (string) $check); }
            $ids[] = wpultra_triggers_create($def);
        }
        if ($ids === []) { return wpultra_err('no_events', 'No valid events — pass post_published and/or post_updated.'); }
        $cfg = ['enabled' => true, 'endpoint' => $valid['endpoint'], 'secret' => $secret, 'trigger_ids' => $ids];
        update_option('wpultra_headless_revalidate', $cfg, false);
    } elseif ($action === 'disable') {
        foreach ($cfg['trigger_ids'] as $old) { wpultra_triggers_delete((int) $old); }
        $cfg['enabled']     = false;
        $cfg['trigger_ids'] = [];
        update_option('wpultra_headless_revalidate', $cfg, false);
    } elseif ($action === 'test') {
        if (!$cfg['enabled'] || $cfg['endpoint'] === '') {
            return wpultra_err('not_enabled', 'Enable the bridge first (action:enable with the endpoint).');
        }
        $body = (string) wp_json_encode(['secret' => $cfg['secret'], 'event' => 'test', 'post_id' => '0', 'post_type' => 'post', 'path' => '/']);
        $res = wp_safe_remote_post($cfg['endpoint'], ['timeout' => 15, 'headers' => ['Content-Type' => 'application/json'], 'body' => $body]);
        if (is_wp_error($res)) { return wpultra_err('unreachable', 'POST failed: ' . $res->get_error_message()); }
        return wpultra_ok([
            'config'   => ['enabled' => true, 'endpoint' => $cfg['endpoint'], 'trigger_ids' => $cfg['trigger_ids']],
            'response' => [
                'code' => (int) wp_remote_retrieve_response_code($res),
                'body' => substr((string) wp_remote_retrieve_body($res), 0, 500),
            ],
        ]);
    } elseif ($action !== 'status') {
        return wpultra_err('bad_action', "Unknown action '$action'.");
    }

    $shaped = [];
    foreach (wpultra_triggers_load() as $t) {
        if (in_array((int) ($t['id'] ?? 0), $cfg['trigger_ids'], true)) { $shaped[] = wpultra_triggers_shape($t); }
    }
    $log = [];
    foreach (wpultra_triggers_log_load() as $entry) {
        if (in_array((int) ($entry['trigger_id'] ?? 0), $cfg['trigger_ids'], true)) { $log[] = $entry; }
        if (count($log) >= 10) { break; }
    }
    $out = ['config' => $cfg, 'triggers' => $shaped, 'log' => $log];
    if ($action === 'enable') {
        $out['note'] = 'Set REVALIDATE_SECRET=' . $cfg['secret'] . ' on the frontend. Then publish/update any post — the frontend refreshes within seconds. Use action:test for an instant check.';
    }
    return wpultra_ok($out);
}
