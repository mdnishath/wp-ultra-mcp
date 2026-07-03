<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Content calendar engine: plan/list/reschedule/spread scheduled posts.
 *
 * Pure functions (slot-time generator, date validator, day-grouping) take/return
 * plain values and never call WordPress functions directly, so they're testable
 * under the zero-dependency harness. Thin wrapper functions do the actual
 * WP_Query / wp_update_post calls.
 */

// ---------------------------------------------------------------------------
// Pure: slot-time generator, date validation, day-grouping.
// ---------------------------------------------------------------------------

/**
 * Pure: generate $count evenly-spaced 'Y-m-d H:i:s' slots starting at
 * $start_ymd_his, $interval_days apart. $interval_days may be fractional
 * (e.g. 0.5 = 12h). Uses integer-second math throughout (no float drift when
 * accumulating over many slots): the interval is converted to seconds once
 * (rounded to the nearest second) and each slot is start + i * interval_seconds.
 */
function wpultra_calendar_slots(string $start_ymd_his, float $interval_days, int $count): array {
    $start_ts = strtotime($start_ymd_his);
    if ($start_ts === false) { return []; }
    if ($count <= 0) { return []; }

    // Convert the (possibly fractional) day interval to whole seconds once, up
    // front, then step with integer addition only — avoids accumulating float
    // rounding error across many slots (e.g. 30 slots at a 0.5-day interval).
    $day_seconds = defined('DAY_IN_SECONDS') ? (int) DAY_IN_SECONDS : 86400;
    $interval_seconds = (int) round($interval_days * $day_seconds);

    $slots = [];
    for ($i = 0; $i < $count; $i++) {
        $slots[] = gmdate('Y-m-d H:i:s', $start_ts + ($i * $interval_seconds));
    }
    return $slots;
}

/** Pure: true if $date is strtotime-parseable, else an error-message string. */
function wpultra_calendar_validate_date(string $date) {
    $date = trim($date);
    if ($date === '') { return 'date is required.'; }
    if (strtotime($date) === false) { return "Could not parse date: '$date'."; }
    return true;
}

/**
 * Pure: group a flat list of row-arrays (each expected to have a 'date' key,
 * 'Y-m-d H:i:s' or any strtotime-parseable string) by their calendar day
 * ('Y-m-d'). Rows with an unparseable/missing date are bucketed under ''.
 */
function wpultra_calendar_group_by_day(array $rows): array {
    $out = [];
    foreach ($rows as $row) {
        $date = (string) ($row['date'] ?? '');
        $ts = $date !== '' ? strtotime($date) : false;
        $day = $ts !== false ? gmdate('Y-m-d', $ts) : '';
        $out[$day][] = $row;
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Thin wrappers: the only functions in this file that call WordPress directly.
// ---------------------------------------------------------------------------

/**
 * Pure-ish shaper: turn a WP_Post into the calendar row format. Takes a plain
 * array (already extracted by the caller) so it stays unit-testable.
 */
function wpultra_calendar_shape_row(array $p): array {
    return [
        'id'       => (int) ($p['id'] ?? 0),
        'title'    => (string) ($p['title'] ?? ''),
        'type'     => (string) ($p['type'] ?? ''),
        'status'   => (string) ($p['status'] ?? ''),
        'date'     => (string) ($p['date'] ?? ''),
        'date_gmt' => (string) ($p['date_gmt'] ?? ''),
        'author'   => (int) ($p['author'] ?? 0),
    ];
}

/**
 * List future/scheduled posts (post_status 'future'), ordered by date ASC,
 * optionally including drafts. @return array|WP_Error ['calendar'=>[...], 'total'=>int]
 */
function wpultra_calendar_list(array $input) {
    $post_type = (string) ($input['post_type'] ?? 'post');
    if ($post_type === '') { $post_type = 'post'; }
    if ($post_type !== 'any' && in_array($post_type, wpultra_reserved_post_types(), true)) {
        return wpultra_err('reserved_post_type', "'$post_type' is managed by a dedicated ability; use that instead.");
    }

    $statuses = ['future'];
    if (!empty($input['include_drafts'])) { $statuses[] = 'draft'; }

    $args = [
        'post_type'      => $post_type,
        'post_status'    => $statuses,
        'posts_per_page' => -1,
        'orderby'        => 'date',
        'order'          => 'ASC',
        'no_found_rows'  => false,
        'ignore_sticky_posts' => true,
    ];

    $query = new WP_Query($args);

    $rows = [];
    foreach ($query->posts as $post) {
        $rows[] = wpultra_calendar_shape_row([
            'id'       => (int) $post->ID,
            'title'    => get_the_title($post),
            'type'     => (string) $post->post_type,
            'status'   => (string) $post->post_status,
            'date'     => (string) $post->post_date,
            'date_gmt' => (string) $post->post_date_gmt,
            'author'   => (int) $post->post_author,
        ]);
    }

    return [
        'calendar' => wpultra_calendar_group_by_day($rows),
        'total'    => count($rows),
    ];
}

/**
 * Reschedule a single post to $date. Validates the date parses; for posts
 * currently in 'future' status the new date must itself be in the future
 * (rescheduling a scheduled post to the past makes no sense). A 'draft' can be
 * promoted to 'future' by passing $schedule = true; otherwise its status is
 * left untouched (only the date field moves, matching the source status).
 *
 * @return array|WP_Error
 */
function wpultra_calendar_reschedule(int $post_id, string $date, bool $schedule = true) {
    $valid = wpultra_calendar_validate_date($date);
    if ($valid !== true) { return wpultra_err('invalid_date', (string) $valid); }

    $post = get_post($post_id);
    if (!$post) { return wpultra_err('not_found', "No post with id $post_id."); }
    if (in_array((string) $post->post_type, wpultra_reserved_post_types(), true)) {
        return wpultra_err('reserved_post_type', "Post $post_id is a plugin-internal '{$post->post_type}'; not manageable here.");
    }

    $ts = strtotime($date);
    $current_status = (string) $post->post_status;

    if ($current_status === 'future' && $ts < time()) {
        return wpultra_err('date_not_future', "New date '$date' must be in the future to keep post $post_id scheduled.");
    }

    $new_status = $current_status;
    if ($current_status === 'draft' && $schedule) {
        $new_status = ($ts > time()) ? 'future' : 'publish';
    }

    $post_date = gmdate('Y-m-d H:i:s', $ts);
    $postarr = [
        'ID'            => $post_id,
        'post_date'     => $post_date,
        'post_date_gmt' => get_gmt_from_date($post_date),
        'post_status'   => $new_status,
        'edit_date'     => true,
    ];

    $result = wp_update_post(wp_slash($postarr), true);
    if (is_wp_error($result)) { return $result; }

    return [
        'post_id' => $post_id,
        'date'    => $post_date,
        'status'  => $new_status,
    ];
}

/**
 * Spread a batch of posts evenly starting at $start_date, $interval_days apart,
 * scheduling each one (draft -> future, or future -> new date). Returns a
 * per-post results array; individual failures don't stop the batch.
 *
 * @return array ['results' => [...]]
 */
function wpultra_calendar_spread(array $post_ids, string $start_date, float $interval_days) {
    $valid = wpultra_calendar_validate_date($start_date);
    if ($valid !== true) { return wpultra_err('invalid_date', (string) $valid); }
    if (empty($post_ids)) { return wpultra_err('missing_post_ids', 'post_ids is required.'); }

    $slots = wpultra_calendar_slots($start_date, $interval_days, count($post_ids));

    $results = [];
    foreach (array_values($post_ids) as $i => $post_id) {
        $post_id = (int) $post_id;
        $slot = $slots[$i] ?? null;
        if ($slot === null) {
            $results[] = ['post_id' => $post_id, 'success' => false, 'error' => 'no_slot'];
            continue;
        }
        $r = wpultra_calendar_reschedule($post_id, $slot, true);
        if (is_wp_error($r)) {
            $results[] = ['post_id' => $post_id, 'success' => false, 'error' => $r->get_error_code(), 'message' => $r->get_error_message()];
            continue;
        }
        $results[] = array_merge(['success' => true], $r);
    }

    return ['results' => $results];
}
