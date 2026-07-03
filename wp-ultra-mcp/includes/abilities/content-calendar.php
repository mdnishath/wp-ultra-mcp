<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/content-calendar', [
    'label'       => __('Content Calendar', 'wp-ultra-mcp'),
    'description' => __('See and reshape the publishing schedule — list upcoming, move one post, or spread a batch of drafts evenly from a start date.', 'wp-ultra-mcp'),
    'category'    => 'content',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action'         => ['type' => 'string', 'enum' => ['list', 'reschedule', 'spread'], 'default' => 'list'],
            'post_id'        => ['type' => 'integer'],
            'date'           => ['type' => 'string'],
            'post_ids'       => ['type' => 'array', 'items' => ['type' => 'integer']],
            'start_date'     => ['type' => 'string'],
            'interval_days'  => ['type' => 'number', 'default' => 1],
            'include_drafts' => ['type' => 'boolean', 'default' => false],
            'post_type'      => ['type' => 'string'],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'  => ['type' => 'boolean'],
            'calendar' => ['type' => 'object'],
            'total'    => ['type' => 'integer'],
            'results'  => ['type' => 'array'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_content_calendar',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_content_calendar(array $input) {
    $action = (string) ($input['action'] ?? 'list');

    if ($action === 'list') {
        $result = wpultra_calendar_list($input);
        if (is_wp_error($result)) { return $result; }
        return wpultra_ok($result);
    }

    if ($action === 'reschedule') {
        $post_id = (int) ($input['post_id'] ?? 0);
        if ($post_id <= 0) { return wpultra_err('missing_post_id', 'post_id is required.'); }
        $date = (string) ($input['date'] ?? '');
        if (trim($date) === '') { return wpultra_err('missing_date', 'date is required.'); }

        $post_type = get_post_type($post_id);
        if ($post_type !== false && in_array((string) $post_type, wpultra_reserved_post_types(), true)) {
            return wpultra_err('reserved_post_type', "Post $post_id is a plugin-internal '$post_type'; not manageable here.");
        }

        $result = wpultra_calendar_reschedule($post_id, $date, true);
        if (is_wp_error($result)) {
            wpultra_audit_log('content-calendar-reschedule', "reschedule of post $post_id to '$date' failed", false);
            return $result;
        }
        wpultra_audit_log('content-calendar-reschedule', "post $post_id rescheduled to {$result['date']} ({$result['status']})", true);
        return wpultra_ok(['results' => [array_merge(['success' => true], $result)]]);
    }

    if ($action === 'spread') {
        $post_ids = array_map('intval', (array) ($input['post_ids'] ?? []));
        if (empty($post_ids)) { return wpultra_err('missing_post_ids', 'post_ids is required.'); }
        $start_date = (string) ($input['start_date'] ?? '');
        if (trim($start_date) === '') { return wpultra_err('missing_start_date', 'start_date is required.'); }
        $interval_days = (float) ($input['interval_days'] ?? 1);

        foreach ($post_ids as $pid) {
            $post_type = get_post_type($pid);
            if ($post_type !== false && in_array((string) $post_type, wpultra_reserved_post_types(), true)) {
                return wpultra_err('reserved_post_type', "Post $pid is a plugin-internal '$post_type'; not manageable here.");
            }
        }

        $result = wpultra_calendar_spread($post_ids, $start_date, $interval_days);
        if (is_wp_error($result)) { return $result; }

        $ok_count = 0;
        foreach ($result['results'] as $r) { if (!empty($r['success'])) { $ok_count++; } }
        wpultra_audit_log('content-calendar-spread', sprintf('spread %d/%d posts from %s (interval %s days)', $ok_count, count($post_ids), $start_date, (string) $interval_days), $ok_count === count($post_ids));

        return wpultra_ok($result);
    }

    return wpultra_err('bad_action', "Unknown action '$action'.");
}
