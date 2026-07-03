<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/error-reports', [
    'label'       => __('Error Reports', 'wp-ultra-mcp'),
    'description' => __('Structured fatal-error reports captured site-wide with actionable suggestions (undo-restore / plugin deactivate / widget regen) — check this when something breaks. Each report: ts, message, file, line, url, suggestions. Action "list" reads recent reports (filter by since/limit, max 50); "clear" empties the log.', 'wp-ultra-mcp'),
    'category'    => 'diagnostics',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action' => ['type' => 'string', 'enum' => ['list', 'clear']],
            'since'  => ['type' => 'integer'],
            'limit'  => ['type' => 'integer'],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'action'  => ['type' => 'string'],
            'reports' => ['type' => 'array'],
            'count'   => ['type' => 'integer'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_error_reports_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_error_reports_cb(array $input) {
    $action = (string) ($input['action'] ?? '');

    if ($action === 'clear') {
        wpultra_errors_clear();
        return wpultra_ok(['action' => 'clear', 'reports' => [], 'count' => 0]);
    }

    if ($action === 'list') {
        $filters = [];
        if (isset($input['since'])) { $filters['since'] = (int) $input['since']; }
        if (isset($input['limit'])) { $filters['limit'] = (int) $input['limit']; }
        $reports = wpultra_errors_read($filters);
        return wpultra_ok(['action' => 'list', 'reports' => $reports, 'count' => count($reports)]);
    }

    return wpultra_err('bad_action', "action must be 'list' or 'clear'.");
}
