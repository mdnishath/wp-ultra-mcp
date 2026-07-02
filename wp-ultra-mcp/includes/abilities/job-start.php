<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/job-start', [
    'label'       => __('Start Background Job', 'wp-ultra-mcp'),
    'description' => __('Queue a long-running operation to run in the background via WP-Cron, returning immediately with a job id. Poll wpultra/job-status for progress. Types: search-replace (params: search, replace, tables[], confirm:true), bulk-post-meta (params: set{key:value}, post_type[], status[], only_missing, confirm:true), site-audit (params: post_type[]). Use this instead of the synchronous ability when a site is large enough to hit request timeouts.', 'wp-ultra-mcp'),
    'category'    => 'jobs',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'type'   => ['type' => 'string', 'description' => 'Job type: search-replace | bulk-post-meta | site-audit.'],
            'params' => ['type' => 'object', 'description' => 'Type-specific parameters.'],
        ],
        'required'             => ['type'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'id'      => ['type' => 'integer'],
            'status'  => ['type' => 'string'],
            'type'    => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_job_start_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_job_start_cb(array $input) {
    $type   = (string) ($input['type'] ?? '');
    $params = (array) ($input['params'] ?? []);
    $valid = wpultra_jobs_validate_start($type, $params, wpultra_jobs_handlers());
    if ($valid !== true) { return wpultra_err('invalid_job', (string) $valid); }

    $id = wpultra_jobs_create($type, $params);
    if ($id === 0) { return wpultra_err('create_failed', 'Could not create the job record.'); }
    wpultra_jobs_kick();
    wpultra_audit_log('job-start', "queued $type job #$id", true);
    return wpultra_ok(['id' => $id, 'status' => 'queued', 'type' => $type]);
}
