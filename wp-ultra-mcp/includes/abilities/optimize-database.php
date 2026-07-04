<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/optimize-database', [
    'label'       => __('Optimize Database', 'wp-ultra-mcp'),
    'description' => __('Clean up database cruft: post revisions (keeping the newest N per post), auto-drafts, trashed posts, spam/trashed comments, expired transients, orphan post/term meta, and OPTIMIZE TABLE on all prefixed tables. Each task reports {found, deleted}. Runs as a dry-run by default (counts only); pass dry_run:false + confirm:true to actually delete. tasks[] selects which cleanups to run (default: revisions, auto_drafts, trashed_posts, spam_comments, expired_transients, orphan_postmeta, optimize_tables). Known tasks: revisions, auto_drafts, trashed_posts, spam_comments, trashed_comments, expired_transients, orphan_postmeta, orphan_termmeta, optimize_tables.', 'wp-ultra-mcp'),
    'category'    => 'system',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'tasks' => [
                'type'  => 'array',
                'items' => ['type' => 'string', 'enum' => [
                    'revisions', 'auto_drafts', 'trashed_posts', 'spam_comments',
                    'trashed_comments', 'expired_transients', 'orphan_postmeta',
                    'orphan_termmeta', 'optimize_tables',
                ]],
            ],
            'keep_revisions' => ['type' => 'integer', 'default' => 5, 'minimum' => 0],
            'dry_run'        => ['type' => 'boolean', 'default' => true],
            'confirm'        => ['type' => 'boolean'],
        ],
        'required'             => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'       => ['type' => 'boolean'],
            'dry_run'       => ['type' => 'boolean'],
            'tasks'         => ['type' => 'array'],
            'results'       => ['type' => 'object'],
            'summary'       => ['type' => 'object'],
            'keep_revisions'=> ['type' => 'integer'],
        ],
        'required' => ['success', 'dry_run', 'results', 'summary'],
    ],
    'execute_callback'    => 'wpultra_optimize_database_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

/** @return array|WP_Error */
function wpultra_optimize_database_cb(array $input) {
    $requested = isset($input['tasks']) && is_array($input['tasks']) ? $input['tasks'] : [];
    $tasks = $requested === [] ? wpultra_optimize_default_tasks() : wpultra_optimize_valid_tasks($requested);
    if ($tasks === []) {
        return wpultra_err('no_tasks', 'No valid tasks selected. Known tasks: ' . implode(', ', wpultra_optimize_known_tasks()));
    }

    $keep    = max(0, (int) ($input['keep_revisions'] ?? 5));
    $dry_run = ($input['dry_run'] ?? true) !== false; // default true; only explicit false disables

    if (!$dry_run) {
        $confirm = ($input['confirm'] ?? false) === true;
        if (!$confirm) {
            return wpultra_err('unconfirmed', 'Live database cleanup deletes rows. Re-run with dry_run:false and confirm:true.');
        }
    }

    $results = wpultra_optimize_database($tasks, $dry_run, $keep);
    $summary = wpultra_optimize_summary($results);

    wpultra_audit_log(
        'optimize-database',
        ($dry_run ? 'dry-run ' : 'LIVE ') . 'tasks=' . implode(',', array_keys($results))
            . " found={$summary['total_found']} deleted={$summary['total_deleted']}",
        true
    );

    return wpultra_ok([
        'dry_run'        => $dry_run,
        'tasks'          => array_keys($results),
        'results'        => $results,
        'summary'        => $summary,
        'keep_revisions' => $keep,
    ]);
}
