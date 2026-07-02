<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/job-list', [
    'label'       => __('List Jobs', 'wp-ultra-mcp'),
    'description' => __('List recent background jobs (newest first), optionally filtered by status (queued|running|done|failed|cancelled). Returns compact rows without full result payloads — use wpultra/job-status for one job\'s detail.', 'wp-ultra-mcp'),
    'category'    => 'jobs',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'status'   => ['type' => 'string', 'enum' => ['queued', 'running', 'done', 'failed', 'cancelled']],
            'per_page' => ['type' => 'integer'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'jobs'    => ['type' => 'array'],
            'total'   => ['type' => 'integer'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_job_list_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_job_list_cb(array $input) {
    $per_page = max(1, min(100, (int) ($input['per_page'] ?? 20)));
    $args = [
        'post_type'      => WPULTRA_JOBS_CPT,
        'post_status'    => 'private',
        'posts_per_page' => $per_page,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'fields'         => 'ids',
        'no_found_rows'  => false,
    ];
    if (!empty($input['status'])) {
        $args['meta_query'] = [['key' => '_wpultra_job_status', 'value' => (string) $input['status']]];
    }
    $q = new WP_Query($args);
    $jobs = [];
    foreach ((array) $q->posts as $id) {
        $job = wpultra_jobs_load((int) $id);
        if ($job === null) { continue; }
        $shaped = wpultra_jobs_shape((int) $id, $job['status'], $job['blob'], $job['created'], $job['updated']);
        unset($shaped['result'], $shaped['log']); // compact
        $jobs[] = $shaped;
    }
    return wpultra_ok(['jobs' => $jobs, 'total' => (int) $q->found_posts]);
}
