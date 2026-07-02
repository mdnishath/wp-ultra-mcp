<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/trigger-log', [
    'label'       => __('Trigger Event Log', 'wp-ultra-mcp'),
    'description' => __('Read the recent fired-event log (newest first): which triggers fired, for which event, when, with a one-line summary. This is how the AI polls what has happened on the site since it last looked. Optionally filter by event or limit.', 'wp-ultra-mcp'),
    'category'    => 'triggers',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'event' => ['type' => 'string'],
            'limit' => ['type' => 'integer'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'events'  => ['type' => 'array'],
            'count'   => ['type' => 'integer'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_trigger_log_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_trigger_log_cb(array $input) {
    $filter = (string) ($input['event'] ?? '');
    $limit  = max(1, min(100, (int) ($input['limit'] ?? 50)));
    $log = wpultra_triggers_log_load();
    $rows = [];
    foreach ($log as $e) {
        if ($filter !== '' && (string) ($e['event'] ?? '') !== $filter) { continue; }
        $rows[] = $e;
        if (count($rows) >= $limit) { break; }
    }
    return wpultra_ok(['events' => $rows, 'count' => count($rows)]);
}
