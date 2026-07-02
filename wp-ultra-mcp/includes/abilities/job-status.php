<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/job-status', [
    'label'       => __('Job Status', 'wp-ultra-mcp'),
    'description' => __('Get a background job\'s status, progress (processed/total/percent), result, and recent log by id. Poll this after wpultra/job-start until status is done, failed, or cancelled.', 'wp-ultra-mcp'),
    'category'    => 'jobs',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => ['id' => ['type' => 'integer']],
        'required'             => ['id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'  => ['type' => 'boolean'],
            'id'       => ['type' => 'integer'],
            'type'     => ['type' => 'string'],
            'status'   => ['type' => 'string'],
            'progress' => ['type' => 'object'],
            'result'   => ['type' => ['object', 'array', 'null']],
            'message'  => ['type' => 'string'],
            'log'      => ['type' => 'array'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_job_status_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_job_status_cb(array $input) {
    $id = (int) ($input['id'] ?? 0);
    $job = wpultra_jobs_load($id);
    if ($job === null) { return wpultra_err('not_found', "No job with id $id."); }
    return wpultra_ok(wpultra_jobs_shape($id, $job['status'], $job['blob'], $job['created'], $job['updated']));
}
