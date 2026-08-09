<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Transient management engine. Regular (single-site) transients live in the
 * options table as `_transient_<key>` (value) + `_transient_timeout_<key>`
 * (unix expiry, absent for non-expiring transients). Object-cache backends
 * short-circuit get/set/delete transparently via the core functions.
 */

/** PURE: strip the `_transient_` / `_transient_timeout_` prefix off an option name. */
function wpultra_transients_strip_prefix(string $option): string {
    foreach (['_transient_timeout_', '_transient_'] as $p) {
        if (strncmp($option, $p, strlen($p)) === 0) { return substr($option, strlen($p)); }
    }
    return $option;
}

/**
 * List transients with value size and expiry. @return array{transients:array,count:int,expired:int}
 */
function wpultra_transients_list(array $input): array {
    global $wpdb;
    $now = time();
    $like = $wpdb->esc_like('_transient_') . '%';
    $not  = $wpdb->esc_like('_transient_timeout_') . '%';
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT option_name, LENGTH(option_value) AS bytes FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s ORDER BY option_name",
            $like, $not
        ),
        ARRAY_A
    );
    // Fetch all timeout values in one pass.
    $timeouts = $wpdb->get_results(
        $wpdb->prepare("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $not),
        ARRAY_A
    );
    $tmap = [];
    foreach ((array) $timeouts as $t) { $tmap[wpultra_transients_strip_prefix((string) $t['option_name'])] = (int) $t['option_value']; }

    $out = []; $expired = 0;
    foreach ((array) $rows as $r) {
        $key = wpultra_transients_strip_prefix((string) $r['option_name']);
        $exp = $tmap[$key] ?? 0;
        $is_expired = $exp > 0 && $exp < $now;
        if ($is_expired) { $expired++; }
        $out[] = [
            'key'     => $key,
            'bytes'   => (int) $r['bytes'],
            'expires' => $exp > 0 ? gmdate('c', $exp) : null,
            'expired' => $is_expired,
        ];
    }
    return ['transients' => $out, 'count' => count($out), 'expired' => $expired];
}

/** Read one transient's value. @return array|WP_Error */
function wpultra_transients_get(string $key) {
    if ($key === '') { return wpultra_err('missing_key', 'key is required.'); }
    $val = get_transient($key);
    if ($val === false) { return wpultra_err('not_found', "No transient '$key' (or it has expired)."); }
    return ['key' => $key, 'value' => is_scalar($val) ? $val : wp_json_encode($val)];
}

/** Delete one transient by key. @return array|WP_Error */
function wpultra_transients_delete(string $key) {
    if ($key === '') { return wpultra_err('missing_key', 'key is required.'); }
    $ok = delete_transient($key);
    return ['key' => $key, 'deleted' => (bool) $ok];
}

/**
 * Delete all EXPIRED transients (value + timeout rows). Safe: never touches
 * live ones. @return array{deleted:int}
 */
function wpultra_transients_delete_expired(): array {
    global $wpdb;
    $now = time();
    $like_to = $wpdb->esc_like('_transient_timeout_') . '%';
    $expired_keys = $wpdb->get_col(
        $wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d", $like_to, $now)
    );
    $deleted = 0;
    foreach ((array) $expired_keys as $timeout_name) {
        $key = wpultra_transients_strip_prefix((string) $timeout_name);
        if (delete_transient($key)) { $deleted++; }
    }
    return ['deleted' => $deleted];
}

/** Ability dispatcher. @return array|WP_Error */
function wpultra_manage_transients(array $input) {
    $action = (string) ($input['action'] ?? 'list');
    switch ($action) {
        case 'list':
            return wpultra_ok(wpultra_transients_list($input));
        case 'get':
            $res = wpultra_transients_get((string) ($input['key'] ?? ''));
            return is_wp_error($res) ? $res : wpultra_ok($res);
        case 'delete':
            if ($e = wpultra_require_confirm($input, "Deleting a transient may force an expensive recompute on the next request.")) { return $e; }
            $res = wpultra_transients_delete((string) ($input['key'] ?? ''));
            if (is_wp_error($res)) { return $res; }
            wpultra_audit_log('manage-transients', 'delete ' . ($input['key'] ?? ''), true);
            return wpultra_ok($res);
        case 'delete-expired':
            $res = wpultra_transients_delete_expired();
            wpultra_audit_log('manage-transients', "delete-expired ({$res['deleted']})", true);
            return wpultra_ok($res);
        default:
            return wpultra_err('bad_action', "Unknown action '$action'.");
    }
}
