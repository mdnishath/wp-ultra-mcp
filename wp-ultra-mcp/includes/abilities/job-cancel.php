<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/job-cancel', [
    'label'       => __('Cancel Job', 'wp-ultra-mcp'),
    'description' => __('Request cancellation of a background job. A running job stops before its next slice; a queued job never starts. Already-committed work (rows already written) is not rolled back. Idempotent.', 'wp-ultra-mcp'),
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
            'success'   => ['type' => 'boolean'],
            'id'        => ['type' => 'integer'],
            'status'    => ['type' => 'string'],
            'cancelled' => ['type' => 'boolean'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_job_cancel_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_job_cancel_cb(array $input) {
    $id = (int) ($input['id'] ?? 0);
    $job = wpultra_jobs_load($id);
    if ($job === null) { return wpultra_err('not_found', "No job with id $id."); }

    // Terminal jobs are unchanged.
    if (!wpultra_jobs_is_active($job['status'])) {
        return wpultra_ok(['id' => $id, 'status' => $job['status'], 'cancelled' => false]);
    }

    update_post_meta($id, '_wpultra_job_cancel', '1');
    // A queued job that hasn't started can be cancelled immediately; a running
    // one flips at the top of its next tick (its in-flight slice already ran).
    $blob = $job['blob'];
    $blob['message'] = 'Cancellation requested.';
    wpultra_jobs_save($id, 'cancelled', $blob);
    wpultra_audit_log('job-cancel', "cancelled job #$id", true);
    return wpultra_ok(['id' => $id, 'status' => 'cancelled', 'cancelled' => true]);
}
