<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

require_once __DIR__ . '/../system/queryprofiler.php';

wp_register_ability('wpultra/query-profiler', [
    'label'       => __('Query Profiler', 'wp-ultra-mcp'),
    'description' => __('SAVEQUERIES-based profiling of one WordPress request: total query count/time, the top-N slowest queries (excerpt + ms + caller), and duplicate-query detection via normalized-SQL grouping. Query-Monitor-lite. actions: `analyze-current` (default, read-only) analyzes the CURRENT request\'s already-captured $wpdb->queries — this requires SAVEQUERIES to already be true from BEFORE the request started (toggle it via the debug-mode ability, then profile a fresh request); if SAVEQUERIES is off, returns a note explaining that instead of an error. `profile-url` (read-only) makes ONE wp_remote_get probe of a front-end url (default home) and reports request timing + HTTP status only — it cannot capture per-query data cross-request and never tries to enable SAVEQUERIES itself.', 'wp-ultra-mcp'),
    'category'    => 'diagnostics',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action' => ['type' => 'string', 'enum' => ['analyze-current', 'profile-url'], 'default' => 'analyze-current'],
            'top'    => ['type' => 'integer', 'default' => 10, 'minimum' => 1],
            'url'    => ['type' => 'string'],
        ],
        'required'             => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'       => ['type' => 'boolean'],
            'mode'          => ['type' => 'string'],
            'captured'      => ['type' => 'boolean'],
            'total_queries' => ['type' => 'integer'],
            'total_time_ms' => ['type' => 'number'],
            'slowest'       => ['type' => 'array'],
            'duplicates'    => ['type' => 'array'],
            'note'          => ['type' => 'string'],
            'url'           => ['type' => 'string'],
            'status'        => ['type' => 'integer'],
            'elapsed_ms'    => ['type' => 'number'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_query_profiler_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

/** @return array|WP_Error */
function wpultra_query_profiler_cb(array $input) {
    $action = (string) ($input['action'] ?? 'analyze-current');
    $top = max(1, min(200, (int) ($input['top'] ?? 10)));

    switch ($action) {
        case 'analyze-current':
            return wpultra_qprof_analyze_current($top);
        case 'profile-url':
            return wpultra_qprof_profile_url($input);
        default:
            return wpultra_err('bad_action', "Unknown action '$action'. Use analyze-current or profile-url.");
    }
}
