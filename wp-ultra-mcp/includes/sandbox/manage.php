<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Sandbox safe-mode management: surface the `.crashed` sentinel (see
 * includes/sandbox/runtime.php, NOT modified here) as status/clear/arm so an
 * AI that trips safe mode isn't stuck needing wp-admin to recover.
 *
 * `clear` requires a diagnosed root-cause string (guarded by
 * wpultra_safemode_validate_cause()) and is recorded into a small capped
 * ring option (`wpultra_safemode_clears`) so there's an audit trail of who
 * cleared what and why — clearing re-enables code execution, which is a
 * security-relevant transition.
 */

const WPULTRA_SAFEMODE_CLEARS_OPTION = 'wpultra_safemode_clears';
const WPULTRA_SAFEMODE_CLEARS_CAP    = 20;
const WPULTRA_SAFEMODE_MIN_CAUSE_LEN = 10;

/* ------------------------------------------------------------------ *
 * PURE helpers — no WordPress.
 * ------------------------------------------------------------------ */

/** Pure: join a sandbox dir + sentinel filename (mirrors wpultra_sandbox_sentinel()'s logic). */
function wpultra_safemode_sentinel_path(string $dir): string {
    return rtrim($dir, '/\\') . '/.crashed';
}

/**
 * Pure: cause must be a string, trimmed length >= $min_len. Returns true or a WP_Error
 * so callers can `if ($v !== true) return $v;`.
 *
 * @return true|WP_Error
 */
function wpultra_safemode_validate_cause($cause, int $min_len = WPULTRA_SAFEMODE_MIN_CAUSE_LEN) {
    $cause = is_string($cause) ? trim($cause) : '';
    $len = function_exists('mb_strlen') ? mb_strlen($cause) : strlen($cause);
    if ($len < $min_len) {
        return wpultra_err(
            'cause_required',
            "cause is required and must describe the diagnosed root cause of the fatal (min $min_len characters)."
        );
    }
    return true;
}

/**
 * Pure: prepend an entry (newest first) and cap the ring length. Reuses the
 * shared ring helper from includes/system/activity.php when it's loaded;
 * otherwise falls back to the identical tiny logic so this file has no hard
 * dependency on that one (see common-context.md: "reuse if loadable").
 */
function wpultra_safemode_ring_push(array $ring, array $entry, int $cap = WPULTRA_SAFEMODE_CLEARS_CAP): array {
    if (function_exists('wpultra_activity_ring_push')) {
        return wpultra_activity_ring_push($ring, $entry, $cap);
    }
    array_unshift($ring, $entry);
    if ($cap > 0 && count($ring) > $cap) { $ring = array_slice($ring, 0, $cap); }
    return array_values($ring);
}

/** Pure: the "how to clear" guidance note surfaced by `status`. */
function wpultra_safemode_how_to_clear_note(): string {
    return 'Diagnose the root cause first (check error-reports / read-debug-log), then call '
        . 'safe-mode-manage action=clear with confirm:true and a cause string (>=10 chars) describing '
        . 'the diagnosed fix. Code-execution abilities (execute-php, run-wp-cli) resume on the NEXT '
        . 'request after clearing.';
}

/* ------------------------------------------------------------------ *
 * Store (thin WordPress wrappers). File ops go through native PHP with
 * error-suppressed calls + explicit failure checks — never fatal.
 * ------------------------------------------------------------------ */

function wpultra_safemode_load_clears(): array {
    $v = function_exists('get_option') ? get_option(WPULTRA_SAFEMODE_CLEARS_OPTION, []) : [];
    return is_array($v) ? $v : [];
}

function wpultra_safemode_save_clears(array $ring): void {
    if (function_exists('update_option')) { update_option(WPULTRA_SAFEMODE_CLEARS_OPTION, $ring, false); }
}

/** Resolve the sentinel path, reusing the runtime's own dir logic when it's loaded. */
function wpultra_safemode_sentinel_file(): string {
    if (function_exists('wpultra_sandbox_sentinel')) { return wpultra_sandbox_sentinel(); }
    $dir = function_exists('wpultra_sandbox_dir')
        ? wpultra_sandbox_dir()
        : (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/wpultra-sandbox/' : sys_get_temp_dir() . '/wpultra-sandbox/');
    return wpultra_safemode_sentinel_path($dir);
}

/**
 * status: read sentinel existence/content/mtime + the newest captured fatal
 * (from includes/system/errors.php's ring, loaded on demand if needed).
 * Never fatal — every filesystem call is error-suppressed with an explicit check.
 */
function wpultra_safemode_status(): array {
    $path = wpultra_safemode_sentinel_file();
    $exists = file_exists($path);
    $content = null;
    $mtime = null;
    if ($exists) {
        $raw = @file_get_contents($path);
        $content = $raw === false ? null : $raw;
        $mt = @filemtime($path);
        $mtime = $mt === false ? null : $mt;
    }

    $last_fatal = null;
    if (!function_exists('wpultra_errors_read') && defined('WPULTRA_DIR')) {
        $errors_file = WPULTRA_DIR . 'includes/system/errors.php';
        if (is_readable($errors_file)) { require_once $errors_file; }
    }
    if (function_exists('wpultra_errors_read')) {
        $reports = wpultra_errors_read(['limit' => 1]);
        $last_fatal = $reports[0] ?? null;
    }

    return [
        'active'           => $exists,
        'sentinel_path'    => $path,
        'sentinel_exists'  => $exists,
        'sentinel_content' => $content,
        'sentinel_mtime'   => $mtime,
        'last_fatal'       => $last_fatal,
        'how_to_clear'     => wpultra_safemode_how_to_clear_note(),
    ];
}

/**
 * clear: requires a validated cause, deletes the sentinel, records an audit
 * ring entry (timestamp, cause, the sentinel content that was cleared).
 *
 * @return array|WP_Error {cleared, was_active, note}
 */
function wpultra_safemode_do_clear(string $cause) {
    $valid = wpultra_safemode_validate_cause($cause);
    if ($valid !== true) { return $valid; }

    $path = wpultra_safemode_sentinel_file();
    $was_active = file_exists($path);
    $prior_content = null;
    if ($was_active) {
        $raw = @file_get_contents($path);
        $prior_content = $raw === false ? '' : $raw;
        if (!@unlink($path) && file_exists($path)) {
            return wpultra_err('unlink_failed', "Could not delete the sentinel file (permissions?): $path");
        }
    }

    $ring = wpultra_safemode_load_clears();
    $ring = wpultra_safemode_ring_push($ring, [
        'ts'      => function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s'),
        'cause'   => trim($cause),
        'content' => $prior_content,
    ], WPULTRA_SAFEMODE_CLEARS_CAP);
    wpultra_safemode_save_clears($ring);

    return [
        'cleared'    => true,
        'was_active' => $was_active,
        'note'       => 'Safe mode cleared. Code-execution abilities (execute-php, run-wp-cli) resume on the NEXT request.',
    ];
}

/**
 * arm: deliberately create the sentinel to proactively block execute-php/run-wp-cli.
 *
 * @return array|WP_Error {armed: true}
 */
function wpultra_safemode_do_arm(string $reason = '') {
    if (function_exists('wpultra_sandbox_harden')) { wpultra_sandbox_harden(); }
    $path = wpultra_safemode_sentinel_file();
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return wpultra_err('mkdir_failed', "Could not create the sandbox directory: $dir");
    }

    $payload = [
        'armed_by' => 'safe-mode-manage',
        'reason'   => $reason,
        'ts'       => function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s'),
    ];
    $json = function_exists('wp_json_encode') ? wp_json_encode($payload) : json_encode($payload);
    if (@file_put_contents($path, (string) $json) === false) {
        return wpultra_err('write_failed', "Could not write the sentinel file (permissions?): $path");
    }

    return ['armed' => true];
}
