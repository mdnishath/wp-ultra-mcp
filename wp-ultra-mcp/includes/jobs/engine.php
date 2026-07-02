<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Async job runner. Long operations (big search-replace, site-wide bulk meta,
 * audits) are split into slices processed one-per-WP-Cron-tick, so they finish
 * in the background instead of dying on an MCP/HTTP request timeout.
 *
 * A job is a `wpultra_job` CPT post: post_content holds a JSON blob
 * {type, params, cursor, progress:{processed,total}, result, message, log[]},
 * meta `_wpultra_job_status` mirrors the state for querying, meta
 * `_wpultra_job_cancel` requests cancellation. The tick hook
 * `wpultra_jobs_tick` runs one slice of the oldest active job and reschedules
 * itself (kicked immediately via spawn_cron on start).
 */

const WPULTRA_JOBS_CPT      = 'wpultra_job';
const WPULTRA_JOBS_TICK_HOOK = 'wpultra_jobs_tick';
const WPULTRA_JOBS_LOG_CAP   = 50;

/* ------------------------------------------------------------------ *
 * PURE helpers (state machine, progress, shaping) — no WordPress.
 * ------------------------------------------------------------------ */

/** Valid job states. */
function wpultra_jobs_states(): array {
    return ['queued', 'running', 'done', 'failed', 'cancelled'];
}

/** A state from which the runner may still do work. */
function wpultra_jobs_is_active(string $status): bool {
    return $status === 'queued' || $status === 'running';
}

/**
 * Pure state machine. Events: start, slice_ok, slice_done, error, cancel.
 * Returns the resulting status, or the current status when the transition is
 * not allowed (terminal states never move).
 */
function wpultra_jobs_next_status(string $current, string $event): string {
    if (!wpultra_jobs_is_active($current)) { return $current; } // done/failed/cancelled are terminal
    switch ($event) {
        case 'cancel':     return 'cancelled';
        case 'error':      return 'failed';
        case 'slice_done': return 'done';
        case 'slice_ok':
        case 'start':      return 'running';
        default:           return $current;
    }
}

/** Pure: integer 0..100 progress; 0 total → 0 unless done is implied by caller. */
function wpultra_jobs_progress_pct(int $processed, int $total): int {
    if ($total <= 0) { return $processed > 0 ? 100 : 0; }
    $pct = (int) floor(($processed / $total) * 100);
    return max(0, min(100, $pct));
}

/** Pure: append a line to a capped log array (keeps the most recent). */
function wpultra_jobs_log_append(array $log, string $line): array {
    $log[] = $line;
    $n = count($log);
    if ($n > WPULTRA_JOBS_LOG_CAP) { $log = array_slice($log, $n - WPULTRA_JOBS_LOG_CAP); }
    return $log;
}

/**
 * Pure: default blob for a new job.
 */
function wpultra_jobs_new_blob(string $type, array $params): array {
    return [
        'type'     => $type,
        'params'   => $params,
        'cursor'   => [],
        'progress' => ['processed' => 0, 'total' => 0],
        'result'   => null,
        'message'  => '',
        'log'      => [],
    ];
}

/**
 * Pure: shape a job (id + status + blob) for ability output. Large result
 * payloads pass through; the log is already capped at write time.
 */
function wpultra_jobs_shape(int $id, string $status, array $blob, string $created = '', string $updated = ''): array {
    $p = $blob['progress'] ?? ['processed' => 0, 'total' => 0];
    return [
        'id'         => $id,
        'type'       => (string) ($blob['type'] ?? ''),
        'status'     => $status,
        'progress'   => [
            'processed' => (int) ($p['processed'] ?? 0),
            'total'     => (int) ($p['total'] ?? 0),
            'percent'   => wpultra_jobs_progress_pct((int) ($p['processed'] ?? 0), (int) ($p['total'] ?? 0)),
        ],
        'message'    => (string) ($blob['message'] ?? ''),
        'result'     => $blob['result'] ?? null,
        'log'        => array_values((array) ($blob['log'] ?? [])),
        'created'    => $created,
        'updated'    => $updated,
    ];
}

/**
 * Pure: validate a job-start request against the handler registry.
 * @return true|string  true when valid, else an error message.
 */
function wpultra_jobs_validate_start(string $type, array $params, array $registry) {
    if ($type === '') { return 'type is required.'; }
    if (!isset($registry[$type])) {
        return "Unknown job type '$type'. Available: " . implode(', ', array_keys($registry)) . '.';
    }
    $validate = $registry[$type]['validate'] ?? null;
    if (is_callable($validate)) {
        $res = $validate($params);
        if ($res !== true) { return is_string($res) ? $res : 'Invalid parameters for job type ' . $type . '.'; }
    }
    return true;
}

/* ------------------------------------------------------------------ *
 * CPT + persistence (thin WordPress wrappers).
 * ------------------------------------------------------------------ */

function wpultra_jobs_register_cpt(): void {
    register_post_type(WPULTRA_JOBS_CPT, [
        'public' => false, 'show_ui' => false, 'show_in_rest' => false,
        'supports' => ['title'], 'rewrite' => false,
    ]);
}

/** Read a job's {status, blob, created, updated}. @return array|null */
function wpultra_jobs_load(int $id): ?array {
    $post = get_post($id);
    if (!$post || $post->post_type !== WPULTRA_JOBS_CPT) { return null; }
    $blob = json_decode((string) $post->post_content, true);
    if (!is_array($blob)) { $blob = wpultra_jobs_new_blob('', []); }
    return [
        'status'  => (string) (get_post_meta($id, '_wpultra_job_status', true) ?: 'queued'),
        'blob'    => $blob,
        'created' => $post->post_date_gmt,
        'updated' => $post->post_modified_gmt,
        'cancel'  => get_post_meta($id, '_wpultra_job_cancel', true) === '1',
    ];
}

/** Persist blob + status. */
function wpultra_jobs_save(int $id, string $status, array $blob): void {
    wp_update_post(['ID' => $id, 'post_content' => wp_slash((string) wp_json_encode($blob))]);
    update_post_meta($id, '_wpultra_job_status', $status);
}

/** Create a queued job. @return int post id */
function wpultra_jobs_create(string $type, array $params): int {
    $blob = wpultra_jobs_new_blob($type, $params);
    $id = wp_insert_post([
        'post_type'    => WPULTRA_JOBS_CPT,
        'post_status'  => 'private',
        'post_title'   => 'job:' . $type,
        'post_content' => wp_slash((string) wp_json_encode($blob)),
    ], true);
    if (is_wp_error($id)) { return 0; }
    update_post_meta((int) $id, '_wpultra_job_status', 'queued');
    return (int) $id;
}

/** Schedule + loopback-kick the tick processor. */
function wpultra_jobs_kick(): void {
    if (!wp_next_scheduled(WPULTRA_JOBS_TICK_HOOK)) {
        wp_schedule_single_event(time(), WPULTRA_JOBS_TICK_HOOK);
    }
    if (function_exists('spawn_cron')) { spawn_cron(); }
}

/** The oldest active job id, or 0. */
function wpultra_jobs_next_active_id(): int {
    $ids = get_posts([
        'post_type'   => WPULTRA_JOBS_CPT,
        'post_status' => 'private',
        'numberposts' => 1,
        'orderby'     => 'date',
        'order'       => 'ASC',
        'fields'      => 'ids',
        'meta_query'  => [[
            'key'     => '_wpultra_job_status',
            'value'   => ['queued', 'running'],
            'compare' => 'IN',
        ]],
    ]);
    return $ids ? (int) $ids[0] : 0;
}

/* ------------------------------------------------------------------ *
 * The tick: run one slice of one job, then reschedule if more remain.
 * ------------------------------------------------------------------ */

function wpultra_jobs_tick(): void {
    $id = wpultra_jobs_next_active_id();
    if ($id === 0) { return; }
    $job = wpultra_jobs_load($id);
    if ($job === null) { return; }
    $blob = $job['blob'];

    // Honour a cancellation request before doing any work.
    if ($job['cancel']) {
        $blob['message'] = 'Cancelled.';
        wpultra_jobs_save($id, 'cancelled', $blob);
        return;
    }

    $registry = wpultra_jobs_handlers();
    $type = (string) ($blob['type'] ?? '');
    $handler = $registry[$type]['handler'] ?? null;
    if (!is_callable($handler)) {
        $blob['message'] = "No handler for job type '$type'.";
        wpultra_jobs_save($id, 'failed', $blob);
        return;
    }

    try {
        $slice = $handler((array) ($blob['params'] ?? []), (array) ($blob['cursor'] ?? []));
    } catch (\Throwable $e) {
        $blob['message'] = 'Handler threw: ' . $e->getMessage();
        $blob['log'] = wpultra_jobs_log_append((array) $blob['log'], 'error: ' . $e->getMessage());
        wpultra_jobs_save($id, 'failed', $blob);
        return;
    }

    if (is_wp_error($slice)) {
        $blob['message'] = $slice->get_error_message();
        $blob['log'] = wpultra_jobs_log_append((array) $blob['log'], 'error: ' . $slice->get_error_code());
        wpultra_jobs_save($id, 'failed', $blob);
        return;
    }

    // Merge the slice result.
    $blob['cursor'] = (array) ($slice['cursor'] ?? $blob['cursor']);
    $processed = (int) ($slice['processed'] ?? ($blob['progress']['processed'] ?? 0));
    $total     = (int) ($slice['total'] ?? ($blob['progress']['total'] ?? 0));
    $blob['progress'] = ['processed' => $processed, 'total' => $total];
    if (isset($slice['result'])) { $blob['result'] = $slice['result']; }
    if (!empty($slice['message'])) {
        $blob['message'] = (string) $slice['message'];
        $blob['log'] = wpultra_jobs_log_append((array) $blob['log'], (string) $slice['message']);
    }

    $done = !empty($slice['done']);
    $status = wpultra_jobs_next_status('running', $done ? 'slice_done' : 'slice_ok');
    wpultra_jobs_save($id, $status, $blob);

    // More work (this job or another queued job) → reschedule + kick.
    if (!$done || wpultra_jobs_next_active_id() !== 0) {
        wp_schedule_single_event(time() + 1, WPULTRA_JOBS_TICK_HOOK);
        if (function_exists('spawn_cron')) { spawn_cron(); }
    }
}

/** Register the always-on tick hook (cron runs outside the REST/abilities loop). */
function wpultra_jobs_boot_runtime(): void {
    if (in_array('jobs', wpultra_disabled_categories(), true)) { return; }
    if (function_exists('did_action') && did_action('init')) { wpultra_jobs_register_cpt(); }
    else { add_action('init', 'wpultra_jobs_register_cpt'); }
    add_action(WPULTRA_JOBS_TICK_HOOK, 'wpultra_jobs_tick');
}
