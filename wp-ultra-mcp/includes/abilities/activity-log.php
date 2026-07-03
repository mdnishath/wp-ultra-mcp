<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/activity-log', [
    'label'       => __('Activity Log', 'wp-ultra-mcp'),
    'description' => __('Who did what — the plugin\'s own audit trail (every wpultra mutation) plus WordPress login history (tracked from activation of this version). Set source to "audit" (default) for privileged AI-driven actions, or "logins" for successful/failed login attempts. Filter by action prefix, user, success/failure, since a timestamp, and limit (max 200).', 'wp-ultra-mcp'),
    'category'    => 'diagnostics',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'source'      => ['type' => 'string', 'enum' => ['audit', 'logins']],
            'action'      => ['type' => 'string'],
            'user_id'     => ['type' => 'integer'],
            'failed_only' => ['type' => 'boolean'],
            'since'       => ['type' => 'string'],
            'limit'       => ['type' => 'integer'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'source'  => ['type' => 'string'],
            'entries' => ['type' => 'array'],
            'count'   => ['type' => 'integer'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_activity_log_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_activity_log_cb(array $input) {
    $source = (string) ($input['source'] ?? 'audit');
    if (!in_array($source, ['audit', 'logins'], true)) { $source = 'audit'; }

    $filters = [
        'limit' => max(1, min(200, (int) ($input['limit'] ?? 50))),
    ];
    if (isset($input['action']) && $input['action'] !== '') { $filters['action'] = (string) $input['action']; }
    if (isset($input['user_id'])) { $filters['user_id'] = (int) $input['user_id']; }
    if (!empty($input['failed_only'])) { $filters['failed_only'] = true; }
    if (isset($input['since']) && $input['since'] !== '') { $filters['since'] = (string) $input['since']; }

    $entries = $source === 'logins' ? wpultra_activity_logins($filters) : wpultra_activity_read($filters);

    return wpultra_ok(['source' => $source, 'entries' => $entries, 'count' => count($entries)]);
}
