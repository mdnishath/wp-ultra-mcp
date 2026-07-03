<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * wpultra/usage-stats — Roadmap #35 (usage analytics dashboard, ability side).
 *
 * Reads the same store as wpultra/self-test (option `wpultra_ability_stats`,
 * written by wpultra_stats_bump() in includes/selftest/engine.php) and
 * returns it as sorted rows + totals, via the pure helpers in
 * includes/system/usage.php (shared with the wp-admin dashboard).
 */

require_once WPULTRA_DIR . 'includes/system/usage.php';

wp_register_ability('wpultra/usage-stats', [
    'label'       => __('Usage Stats', 'wp-ultra-mcp'),
    'description' => __('Per-ability usage/failure analytics: calls, fails, fail rate, and last error for every ability that has been invoked. Sort by calls (default), fails, or fail_rate; optionally cap the number of rows returned. Backed by the same stats store as wpultra/self-test.', 'wp-ultra-mcp'),
    'category'    => 'diagnostics',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'sort'  => ['type' => 'string', 'enum' => ['calls', 'fails', 'fail_rate'], 'default' => 'calls'],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'rows'    => ['type' => 'array'],
            'totals'  => [
                'type'       => 'object',
                'properties' => [
                    'calls'      => ['type' => 'integer'],
                    'fails'      => ['type' => 'integer'],
                    'abilities'  => ['type' => 'integer'],
                    'top_action' => ['type' => 'string'],
                ],
            ],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_usage_stats_execute',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_usage_stats_execute(array $input) {
    $sort  = (string) ($input['sort'] ?? 'calls');
    if (!in_array($sort, ['calls', 'fails', 'fail_rate'], true)) { $sort = 'calls'; }
    $limit = isset($input['limit']) ? max(1, min(100, (int) $input['limit'])) : null;

    $raw = function_exists('get_option') ? get_option('wpultra_ability_stats', []) : [];
    if (!is_array($raw)) { $raw = []; }

    // wpultra_stats_rank() (includes/selftest/engine.php) turns the raw
    // {action => {calls,fails,last_error}} map into the row shape
    // {action, calls, fails, fail_rate, last_error}; ask for all of them
    // (large cap) since our own sort/limit is applied below.
    $rows = function_exists('wpultra_stats_rank') ? wpultra_stats_rank($raw, 1000) : [];

    $rows = wpultra_usage_sort($rows, $sort);
    $totals = wpultra_usage_totals($rows); // totals reflect ALL abilities, before any limit is applied

    if ($limit !== null && count($rows) > $limit) {
        $rows = array_slice($rows, 0, $limit);
    }

    return wpultra_ok(['rows' => $rows, 'totals' => $totals]);
}
