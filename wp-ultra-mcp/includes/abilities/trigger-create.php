<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/trigger-create', [
    'label'       => __('Create Event Trigger', 'wp-ultra-mcp'),
    'description' => __('Register a trigger that fires on a WordPress event and runs an action asynchronously. events: post_published, post_updated, comment_posted, user_registered, order_placed, order_status, form_submitted. action_type: webhook (POST the event payload as JSON to `url`; optional `secret` adds an X-WPUltra-Signature HMAC), playbook (auto-run the saved playbook named by `playbook`, with the event payload as its inputs), or log (record only — poll wpultra/trigger-log). Delivery is async so it never blocks the checkout/publish request.', 'wp-ultra-mcp'),
    'category'    => 'triggers',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'event'       => ['type' => 'string', 'enum' => ['post_published', 'post_updated', 'comment_posted', 'user_registered', 'order_placed', 'order_status', 'form_submitted']],
            'action_type' => ['type' => 'string', 'enum' => ['webhook', 'playbook', 'log']],
            'url'         => ['type' => 'string', 'description' => 'webhook: the endpoint to POST to.'],
            'secret'      => ['type' => 'string', 'description' => 'webhook: optional HMAC signing secret.'],
            'playbook'    => ['type' => 'string', 'description' => 'playbook: saved playbook slug to run.'],
            'label'       => ['type' => 'string'],
        ],
        'required'             => ['event', 'action_type'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'id'      => ['type' => 'integer'],
            'event'   => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_trigger_create_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_trigger_create_cb(array $input) {
    $def = [
        'event'       => (string) ($input['event'] ?? ''),
        'action_type' => (string) ($input['action_type'] ?? ''),
        'label'       => (string) ($input['label'] ?? ''),
    ];
    if (isset($input['url']))      { $def['url'] = (string) $input['url']; }
    if (isset($input['secret']))   { $def['secret'] = (string) $input['secret']; }
    if (isset($input['playbook'])) { $def['playbook'] = (string) $input['playbook']; }

    $valid = wpultra_triggers_validate($def);
    if ($valid !== true) { return wpultra_err('invalid_trigger', (string) $valid); }

    $id = wpultra_triggers_create($def);
    wpultra_audit_log('trigger-create', "trigger #$id on {$def['event']} -> {$def['action_type']}", true);
    return wpultra_ok(['id' => $id, 'event' => $def['event']]);
}
