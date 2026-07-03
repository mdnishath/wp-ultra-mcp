<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Self-healing v2: structured fatal-error reports + actionable undo suggestions.
 *
 * On a fatal PHP error (E_ERROR/E_PARSE/E_COMPILE_ERROR/E_CORE_ERROR) a shutdown
 * handler captures error_get_last() and pushes a structured report — timestamp,
 * message, file (relative to ABSPATH), line, request URL, and a list of
 * plain-English suggestions — into a capped ring buffer option
 * (`wpultra_error_log`, newest first, cap 50). This complements the sandbox
 * crash sentinel (includes/sandbox/runtime.php, NOT modified here) which only
 * flips a boolean; this file gives the AI something to actually read and act on.
 *
 * Suggestions reuse the undo stack (option `wpultra_undo_stack`, shape defined
 * in includes/undo/engine.php) read-only: if the newest snapshot is recent, the
 * fatal probably followed that mutation, so "undo-restore id N" is surfaced.
 * Widget/plugin file paths get more specific remediation hints.
 *
 * The controller hooks wpultra_errors_boot() into the always-on runtime
 * bootstrap; this file only defines it.
 */

const WPULTRA_ERROR_LOG_OPTION = 'wpultra_error_log';
const WPULTRA_ERROR_LOG_CAP    = 50;
const WPULTRA_ERROR_DUPE_WINDOW = 60; // seconds
const WPULTRA_ERROR_RECENT_UNDO_WINDOW = 300; // seconds

/** Fatal error types worth capturing — mirrors the sandbox crash sentinel's list. */
function wpultra_errors_fatal_types(): array {
    return [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR];
}

/* ------------------------------------------------------------------ *
 * PURE helpers — no WordPress.
 * ------------------------------------------------------------------ */

/**
 * Strip $abspath off the front of an absolute path so reports don't leak the
 * full server filesystem layout. Falls back to the original path if it isn't
 * actually under $abspath. Pure.
 */
function wpultra_errors_trim_path(string $abs, string $abspath): string {
    $abs_n = str_replace('\\', '/', $abs);
    $base_n = rtrim(str_replace('\\', '/', $abspath), '/');
    if ($base_n !== '' && str_starts_with($abs_n, $base_n . '/')) {
        return substr($abs_n, strlen($base_n) + 1);
    }
    if ($base_n !== '' && $abs_n === $base_n) { return ''; }
    return $abs_n;
}

/**
 * Build the suggestions list for a captured error. Pure — takes the undo stack
 * and "now" as plain data so it's fully testable without WordPress.
 *
 * @param array $error       ['message'=>string, 'file'=>string (already trimmed), 'line'=>int, ...]
 * @param array $undo_stack_fixture newest-first list of undo entries (shape per includes/undo/engine.php)
 * @param int   $now_ts      current unix timestamp
 */
function wpultra_errors_suggest(array $error, array $undo_stack_fixture, int $now_ts): array {
    $suggestions = [];

    // Recent undo snapshot: the fatal likely followed that mutation.
    if (!empty($undo_stack_fixture)) {
        $newest = $undo_stack_fixture[0];
        $created = (string) ($newest['created'] ?? '');
        $created_ts = $created !== '' ? strtotime($created) : false;
        if ($created_ts !== false && ($now_ts - $created_ts) < WPULTRA_ERROR_RECENT_UNDO_WINDOW) {
            $id = (int) ($newest['id'] ?? 0);
            $suggestions[] = "undo-restore id $id may revert the change that broke this";
        }
    }

    $file = (string) ($error['file'] ?? '');
    $file_n = str_replace('\\', '/', $file);

    if (str_contains($file_n, 'wpultra-widgets/')) {
        $suggestions[] = 'widget quarantine will auto-skip it; regenerate via create-atomic-widget';
    } elseif (preg_match('#wp-content/plugins/([^/]+)#', $file_n, $m)) {
        $plugin = $m[1];
        $suggestions[] = "deactivate plugin $plugin via manage-plugin-theme";
    }

    if (empty($suggestions)) {
        $suggestions[] = 'review the error message/file/line and check recent changes via activity-log or undo-list';
    }

    return $suggestions;
}

/**
 * True if $entry duplicates an existing ring entry (same message + file) within
 * $window seconds of the newest matching one. Pure.
 */
function wpultra_errors_is_dupe(array $ring, array $entry, int $window): bool {
    $msg = (string) ($entry['message'] ?? '');
    $file = (string) ($entry['file'] ?? '');
    $ts = (int) ($entry['ts'] ?? 0);
    foreach ($ring as $e) {
        if ((string) ($e['message'] ?? '') !== $msg) { continue; }
        if ((string) ($e['file'] ?? '') !== $file) { continue; }
        $prev_ts = (int) ($e['ts'] ?? 0);
        if (abs($ts - $prev_ts) <= $window) { return true; }
    }
    return false;
}

/** Pure: prepend an entry (newest first) and cap the ring length. */
function wpultra_errors_ring_push(array $ring, array $entry, int $cap = WPULTRA_ERROR_LOG_CAP): array {
    array_unshift($ring, $entry);
    if ($cap > 0 && count($ring) > $cap) { $ring = array_slice($ring, 0, $cap); }
    return array_values($ring);
}

/** Pure: build a structured report entry, truncating message/url per the caps. */
function wpultra_errors_make_entry(int $ts, string $message, string $file, int $line, string $url, array $suggestions): array {
    return [
        'ts'          => $ts,
        'message'     => function_exists('mb_substr') ? mb_substr($message, 0, 500) : substr($message, 0, 500),
        'file'        => $file,
        'line'        => $line,
        'url'         => function_exists('mb_substr') ? mb_substr($url, 0, 200) : substr($url, 0, 200),
        'suggestions' => $suggestions,
    ];
}

/* ------------------------------------------------------------------ *
 * Store (thin WordPress wrappers).
 * ------------------------------------------------------------------ */

function wpultra_errors_load_ring(): array {
    $v = function_exists('get_option') ? get_option(WPULTRA_ERROR_LOG_OPTION, []) : [];
    return is_array($v) ? $v : [];
}

function wpultra_errors_save_ring(array $ring): void {
    if (function_exists('update_option')) { update_option(WPULTRA_ERROR_LOG_OPTION, $ring, false); }
}

/**
 * Capture one fatal error (as returned by error_get_last()) into the ring,
 * deduped, with suggestions attached. Never throws — shutdown handlers must
 * not themselves fatal.
 */
function wpultra_errors_capture(array $last_error): void {
    try {
        $abspath = defined('ABSPATH') ? ABSPATH : '';
        $file = wpultra_errors_trim_path((string) ($last_error['file'] ?? ''), $abspath);
        $line = (int) ($last_error['line'] ?? 0);
        $message = (string) ($last_error['message'] ?? '');
        $url = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $now = time();

        $ring = wpultra_errors_load_ring();
        $undo_stack = function_exists('wpultra_undo_load_stack') ? wpultra_undo_load_stack() : [];
        $suggestions = wpultra_errors_suggest(['message' => $message, 'file' => $file, 'line' => $line], $undo_stack, $now);
        $entry = wpultra_errors_make_entry($now, $message, $file, $line, $url, $suggestions);

        if (wpultra_errors_is_dupe($ring, $entry, WPULTRA_ERROR_DUPE_WINDOW)) { return; }

        $ring = wpultra_errors_ring_push($ring, $entry);
        wpultra_errors_save_ring($ring);
    } catch (\Throwable $e) {
        // Never let error capture itself break the shutdown sequence.
    }
}

/**
 * Read reports, newest-first. Filters: since (unix ts, keep >= since), limit (<=50).
 */
function wpultra_errors_read(array $filters = []): array {
    $ring = wpultra_errors_load_ring();

    $since = isset($filters['since']) ? (int) $filters['since'] : null;
    if ($since !== null) {
        $ring = array_values(array_filter($ring, static fn($e) => (int) ($e['ts'] ?? 0) >= $since));
    }

    $limit = isset($filters['limit']) ? max(1, min(WPULTRA_ERROR_LOG_CAP, (int) $filters['limit'])) : WPULTRA_ERROR_LOG_CAP;
    if (count($ring) > $limit) { $ring = array_slice($ring, 0, $limit); }

    return $ring;
}

/** Clear all captured reports. */
function wpultra_errors_clear(): void {
    wpultra_errors_save_ring([]);
}

/* ------------------------------------------------------------------ *
 * Hook registration (always-on runtime) — controller wires this in.
 * ------------------------------------------------------------------ */

/**
 * Registers a shutdown handler that captures fatal errors site-wide (distinct
 * from the sandbox's own guard, which only instruments code run through
 * wpultra_sandbox_guard()). Controller hooks this into the always-on runtime
 * bootstrap; this file only defines it.
 */
function wpultra_errors_boot(): void {
    register_shutdown_function(function () {
        $e = error_get_last();
        if ($e && in_array($e['type'], wpultra_errors_fatal_types(), true)) {
            wpultra_errors_capture($e);
        }
    });
}
