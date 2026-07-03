<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * User activity log reader: who did what, surfaced to the AI. Two sources:
 *
 *  - "audit"  — the plugin's own privileged-action audit trail, already written
 *    by wpultra_audit_log() into option `wpultra_audit` (oldest-last / append
 *    order; see includes/helpers.php — NOT modified here). read() reverses it
 *    to newest-first and applies filters.
 *  - "logins" — real WordPress login/login-failure history, tracked by this
 *    file's own hooks (wp_login / wp_login_failed) into a capped ring option
 *    `wpultra_login_log` (newest-first), plus a per-user meta
 *    `wpultra_last_login` (mysql gmt) updated on each successful login.
 *
 * All filtering logic is pure (wpultra_activity_filter / wpultra_activity_ring_push)
 * so it can be exercised without WordPress in tests/activity.test.php.
 */

const WPULTRA_LOGIN_LOG_OPTION = 'wpultra_login_log';
const WPULTRA_LOGIN_LOG_CAP    = 100;
const WPULTRA_LAST_LOGIN_META  = 'wpultra_last_login';

/* ------------------------------------------------------------------ *
 * PURE helpers — no WordPress.
 * ------------------------------------------------------------------ */

/**
 * Prepend an entry to a capped ring buffer (newest first). Reused by both the
 * login ring and (if ever needed) other ring-shaped logs. Pure.
 */
function wpultra_activity_ring_push(array $ring, array $entry, int $cap): array {
    array_unshift($ring, $entry);
    if ($cap > 0 && count($ring) > $cap) { $ring = array_slice($ring, 0, $cap); }
    return array_values($ring);
}

/**
 * Pure filter engine over a flat list of entries (already newest-first).
 * Supported filters:
 *   - action    : string prefix match against $entry['action'] (audit) or ''
 *                 (ignored for entries without an 'action' key, e.g. logins).
 *   - user      : string prefix match against $entry['login']/$entry['user_login']
 *                 when present (case-insensitive), else against numeric user_id.
 *   - user_id   : int exact match against $entry['user'] or $entry['user_id'].
 *   - ok_only   : bool — keep only entries where $entry['ok'] === true.
 *   - failed_only: bool — keep only entries where $entry['ok'] === false.
 *   - since     : string timestamp (any format strtotime() parses); keep entries
 *                 whose $entry['ts']/$entry['login']... timestamp field is >= since.
 *   - limit     : int, applied last, caps the returned count (<=200 enforced by caller).
 */
function wpultra_activity_filter(array $entries, array $filters): array {
    $action_prefix = isset($filters['action']) ? (string) $filters['action'] : '';
    $user_id       = isset($filters['user_id']) ? (int) $filters['user_id'] : null;
    $user_needle   = isset($filters['user']) ? strtolower((string) $filters['user']) : '';
    $ok_only       = !empty($filters['ok_only']);
    $failed_only   = !empty($filters['failed_only']);
    $since_ts      = null;
    if (!empty($filters['since']) && is_string($filters['since'])) {
        $parsed = strtotime($filters['since']);
        if ($parsed !== false) { $since_ts = $parsed; }
    }

    $out = [];
    foreach ($entries as $e) {
        if (!is_array($e)) { continue; }

        if ($action_prefix !== '') {
            $action = (string) ($e['action'] ?? '');
            if (!str_starts_with($action, $action_prefix)) { continue; }
        }

        if ($user_id !== null) {
            $eid = (int) ($e['user'] ?? $e['user_id'] ?? -1);
            if ($eid !== $user_id) { continue; }
        }

        if ($user_needle !== '') {
            $login = strtolower((string) ($e['login'] ?? $e['user_login'] ?? ''));
            $eid   = (string) ($e['user'] ?? $e['user_id'] ?? '');
            if (!str_contains($login, $user_needle) && $eid !== $user_needle) { continue; }
        }

        if ($ok_only && ($e['ok'] ?? null) !== true) { continue; }
        if ($failed_only) {
            // Login entries mark failure via user_id === 0 (no 'ok' key); audit
            // entries mark it via ok === false.
            if (array_key_exists('ok', $e)) {
                if ($e['ok'] !== false) { continue; }
            } elseif ((int) ($e['user_id'] ?? -1) !== 0) {
                continue;
            }
        }

        if ($since_ts !== null) {
            $ts_raw = (string) ($e['ts'] ?? '');
            if ($ts_raw !== '') {
                $entry_ts = strtotime($ts_raw);
                if ($entry_ts !== false && $entry_ts < $since_ts) { continue; }
            }
        }

        $out[] = $e;
    }

    $limit = isset($filters['limit']) ? max(1, min(200, (int) $filters['limit'])) : 200;
    if (count($out) > $limit) { $out = array_slice($out, 0, $limit); }
    return $out;
}

/* ------------------------------------------------------------------ *
 * Store (thin WordPress wrappers).
 * ------------------------------------------------------------------ */

function wpultra_login_log_load(): array {
    $v = function_exists('get_option') ? get_option(WPULTRA_LOGIN_LOG_OPTION, []) : [];
    return is_array($v) ? $v : [];
}

function wpultra_login_log_save(array $log): void {
    if (function_exists('update_option')) { update_option(WPULTRA_LOGIN_LOG_OPTION, $log, false); }
}

/* ------------------------------------------------------------------ *
 * Readers.
 * ------------------------------------------------------------------ */

/**
 * Read the audit trail (option `wpultra_audit`, written by wpultra_audit_log()),
 * newest-first, with filters applied and each row enriched with the acting
 * user's login/display name (best-effort — guarded so a deleted user never
 * breaks the read).
 *
 * Filters: action (prefix), user_id, ok_only, failed_only, since, limit (<=200).
 */
function wpultra_activity_read(array $filters = []): array {
    $log = function_exists('get_option') ? get_option('wpultra_audit', []) : [];
    if (!is_array($log)) { $log = []; }
    $log = array_reverse($log); // stored oldest-last by wpultra_audit_log(); we want newest-first

    $rows = wpultra_activity_filter($log, $filters);

    foreach ($rows as &$row) {
        $uid = (int) ($row['user'] ?? 0);
        $row['user_id'] = $uid;
        $row['user_login'] = '';
        $row['user_display_name'] = '';
        if ($uid > 0 && function_exists('get_userdata')) {
            $u = get_userdata($uid);
            if ($u) {
                $row['user_login'] = (string) $u->user_login;
                $row['user_display_name'] = (string) $u->display_name;
            }
        }
    }
    unset($row);

    return $rows;
}

/**
 * Read the login/login-failure ring (option `wpultra_login_log`), newest-first,
 * with filters applied. Filters: failed_only, user, limit (<=200).
 */
function wpultra_activity_logins(array $filters = []): array {
    $log = wpultra_login_log_load();
    return wpultra_activity_filter($log, $filters);
}

/* ------------------------------------------------------------------ *
 * Hook registration (login tracking) — always-on runtime.
 * ------------------------------------------------------------------ */

/**
 * Registers wp_login / wp_login_failed tracking. The controller hooks this
 * into the always-on runtime bootstrap; this file only defines it.
 */
function wpultra_activity_boot(): void {
    if (function_exists('wpultra_category_enabled') && !wpultra_category_enabled('diagnostics')) { return; }

    add_action('wp_login', function ($user_login, $user = null) {
        try {
            $uid = 0;
            if (is_object($user) && isset($user->ID)) {
                $uid = (int) $user->ID;
            } elseif (function_exists('get_user_by')) {
                $u = get_user_by('login', (string) $user_login);
                $uid = $u ? (int) $u->ID : 0;
            }
            $now = function_exists('current_time') ? (string) current_time('mysql', true) : gmdate('Y-m-d H:i:s');
            if ($uid > 0 && function_exists('update_user_meta')) {
                update_user_meta($uid, WPULTRA_LAST_LOGIN_META, $now);
            }
            $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
            $ring = wpultra_login_log_load();
            $ring = wpultra_activity_ring_push($ring, [
                'user_id' => $uid,
                'login'   => (string) $user_login,
                'ip'      => $ip,
                'ts'      => $now,
            ], WPULTRA_LOGIN_LOG_CAP);
            wpultra_login_log_save($ring);
        } catch (\Throwable $e) {
            // Never let login tracking break the login itself.
        }
    }, 10, 2);

    add_action('wp_login_failed', function ($username, $error = null) {
        try {
            $now = function_exists('current_time') ? (string) current_time('mysql', true) : gmdate('Y-m-d H:i:s');
            $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
            $ring = wpultra_login_log_load();
            $ring = wpultra_activity_ring_push($ring, [
                'user_id' => 0,
                'login'   => (string) $username,
                'ip'      => $ip,
                'ts'      => $now,
            ], WPULTRA_LOGIN_LOG_CAP);
            wpultra_login_log_save($ring);
        } catch (\Throwable $e) {
            // swallow
        }
    }, 10, 2);
}
