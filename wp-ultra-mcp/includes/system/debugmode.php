<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Debug mode engine (Roadmap-4 BF1.1).
 *
 * Safely toggles the five WordPress debug constants (WP_DEBUG, WP_DEBUG_LOG,
 * WP_DEBUG_DISPLAY, SCRIPT_DEBUG, SAVEQUERIES) by editing wp-config.php through
 * the shared PURE editor `wpultra_security_wpconfig_set()` (includes/system/security.php).
 * This file never re-implements that editor — it whitelists the 5 constants,
 * validates/normalizes requested values, and drives the WP-touching read/write/
 * backup/restore flow around it.
 *
 * PURE functions (unit-tested, no WordPress calls):
 *   wpultra_debugmode_whitelist()
 *   wpultra_debugmode_parse_literal()
 *   wpultra_debugmode_read_defines()
 *   wpultra_debugmode_plan()
 *   wpultra_debugmode_has_sentinel()
 *
 * WP-touching wrappers (live-tested by the controller, not unit tests):
 *   wpultra_debugmode_config_path(), wpultra_debugmode_log_path(),
 *   wpultra_debugmode_status(), wpultra_debugmode_set(), wpultra_debugmode_restore_backup()
 */

require_once __DIR__ . '/security.php';

/* =====================================================================
 * PURE
 * ===================================================================== */

/** PURE. The exact 5 constants this ability is allowed to touch, in report order. */
function wpultra_debugmode_whitelist(): array {
    return ['WP_DEBUG', 'WP_DEBUG_LOG', 'WP_DEBUG_DISPLAY', 'SCRIPT_DEBUG', 'SAVEQUERIES'];
}

/**
 * PURE. Parse a single PHP value literal (the right-hand side of a define() call)
 * into its PHP value: true/false/null keywords, an integer, or a single/double
 * quoted string (with \\ and the matching quote unescaped). Anything else is
 * returned as the trimmed raw literal text (best-effort fallback).
 *
 * @return bool|int|string|null
 */
function wpultra_debugmode_parse_literal(string $literal) {
    $trim = trim($literal);
    $lower = strtolower($trim);
    if ($lower === 'true') { return true; }
    if ($lower === 'false') { return false; }
    if ($lower === 'null') { return null; }
    if (preg_match('/^-?\d+$/', $trim)) { return (int) $trim; }
    if (preg_match('/^\'((?:[^\'\\\\]|\\\\.)*)\'$/s', $trim, $m)) {
        return stripcslashes($m[1]);
    }
    if (preg_match('/^"((?:[^"\\\\]|\\\\.)*)"$/s', $trim, $m)) {
        return stripcslashes($m[1]);
    }
    return $trim;
}

/**
 * PURE. Parse the current define() value for each whitelisted constant out of a
 * wp-config.php source string. Handles any spacing and either quote style
 * (mirrors the pattern `wpultra_security_wpconfig_set()` uses to find an
 * existing define). Always returns one entry per whitelisted constant.
 *
 * @return array<string,array{defined:bool,value:mixed}>
 */
function wpultra_debugmode_read_defines(string $config): array {
    $out = [];
    foreach (wpultra_debugmode_whitelist() as $const) {
        $pattern = '/define\s*\(\s*([\'"])' . preg_quote($const, '/') . '\1\s*,(.*?)\)\s*;/is';
        if (preg_match($pattern, $config, $m)) {
            $out[$const] = ['defined' => true, 'value' => wpultra_debugmode_parse_literal($m[2])];
        } else {
            $out[$const] = ['defined' => false, 'value' => null];
        }
    }
    return $out;
}

/**
 * PURE. True when the wp-config "stop editing" sentinel line is present — the
 * same anchor `wpultra_security_wpconfig_set()` requires to insert a new define.
 */
function wpultra_debugmode_has_sentinel(string $config): bool {
    return (bool) preg_match('/^.*stop editing.*$/mi', $config);
}

/**
 * PURE. Validate + normalize a requested `{CONST: value}` map:
 *  - every key MUST be one of the 5 whitelisted constants (else `bad_constant`)
 *  - WP_DEBUG_LOG may be bool or a non-empty string path; the other 4 are bool only
 *    (else `bad_value`)
 *  - the map must not be empty (else `no_constants`)
 *
 * @param array $requested
 * @return array<string,bool|string>|WP_Error
 */
function wpultra_debugmode_plan(array $requested) {
    $whitelist = wpultra_debugmode_whitelist();
    $plan = [];
    foreach ($requested as $const => $value) {
        $const = (string) $const;
        if (!in_array($const, $whitelist, true)) {
            return wpultra_err('bad_constant', "Unknown constant '$const'. Allowed: " . implode(', ', $whitelist) . '.');
        }
        if ($const === 'WP_DEBUG_LOG') {
            if (!is_bool($value) && !is_string($value)) {
                return wpultra_err('bad_value', 'WP_DEBUG_LOG must be a boolean or a string path.');
            }
            if (is_string($value) && trim($value) === '') {
                return wpultra_err('bad_value', 'WP_DEBUG_LOG string path cannot be empty.');
            }
        } else {
            if (!is_bool($value)) {
                return wpultra_err('bad_value', "$const must be a boolean.");
            }
        }
        $plan[$const] = $value;
    }
    if ($plan === []) {
        return wpultra_err('no_constants', 'No constants supplied. `constants` must be a non-empty map using only: ' . implode(', ', $whitelist) . '.');
    }
    return $plan;
}

/* =====================================================================
 * WP-touching
 * ===================================================================== */

/** Locate wp-config.php — mirrors/reuses security.php's own lookup (ABSPATH or one dir up). */
function wpultra_debugmode_config_path(): string {
    if (function_exists('wpultra_security_wpconfig_path')) { return wpultra_security_wpconfig_path(); }
    return rtrim(ABSPATH, '/\\') . '/wp-config.php';
}

/** Path to the debug.log WordPress would write to (honors a WP_DEBUG_LOG string path). */
function wpultra_debugmode_log_path(): string {
    if (defined('WP_DEBUG_LOG') && is_string(WP_DEBUG_LOG) && WP_DEBUG_LOG !== '') { return WP_DEBUG_LOG; }
    $content_dir = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : (rtrim(ABSPATH, '/\\') . '/wp-content');
    return rtrim($content_dir, '/\\') . '/debug.log';
}

/** status: runtime constant() state + what wp-config.php source currently says + debug.log info. */
function wpultra_debugmode_status(): array {
    $runtime = [];
    foreach (wpultra_debugmode_whitelist() as $const) {
        $runtime[$const] = ['defined' => defined($const), 'value' => defined($const) ? constant($const) : null];
    }

    $path = wpultra_debugmode_config_path();
    $source = array_fill_keys(wpultra_debugmode_whitelist(), ['defined' => false, 'value' => null]);
    if ($path !== '' && is_file($path) && is_readable($path)) {
        $source = wpultra_debugmode_read_defines((string) file_get_contents($path));
    }

    $log_path = wpultra_debugmode_log_path();
    $log_exists = is_file($log_path);

    return wpultra_ok([
        'runtime'     => $runtime,
        'source'      => $source,
        'config_path' => $path,
        'debug_log'   => [
            'path'   => $log_path,
            'exists' => $log_exists,
            'size'   => $log_exists ? (int) filesize($log_path) : 0,
        ],
    ]);
}

/**
 * set: validate the requested constants, back up wp-config.php, write via the
 * shared pure editor, verify the write by re-reading, and roll back on any
 * failure. Requires confirm:true (checked by the ability wrapper's caller —
 * this function itself also refuses without it, mirroring manage-server-rules).
 *
 * @return array|WP_Error
 */
function wpultra_debugmode_set(array $input) {
    $confirm = ($input['confirm'] ?? false) === true;
    if (!$confirm) {
        return wpultra_err('unconfirmed', 'Editing wp-config.php is a live config change. Re-run with confirm: true.');
    }

    if (!function_exists('wpultra_security_wpconfig_set')) {
        return wpultra_err('engine_unavailable', "security.php's wpconfig editor is unavailable.");
    }

    $requested = isset($input['constants']) && is_array($input['constants']) ? $input['constants'] : [];
    $plan = wpultra_debugmode_plan($requested);
    if (is_wp_error($plan)) { return $plan; }

    $path = wpultra_debugmode_config_path();
    if ($path === '' || !is_file($path) || !is_readable($path)) {
        return wpultra_err('config_not_found', "wp-config.php not found or unreadable at $path.");
    }

    $src = (string) file_get_contents($path);
    $current = wpultra_debugmode_read_defines($src);

    $results = [];
    $working = $src;
    $changed = false;
    foreach ($plan as $const => $value) {
        $cur = $current[$const] ?? ['defined' => false, 'value' => null];
        if ($cur['defined'] && $cur['value'] === $value) {
            $results[] = ['constant' => $const, 'status' => 'skipped', 'value' => $value, 'detail' => 'Already set to this value in wp-config.php.'];
            continue;
        }
        $next = wpultra_security_wpconfig_set($working, $const, $value);
        if (is_wp_error($next)) { return $next; }
        $working = $next;
        $changed = true;
        $results[] = ['constant' => $const, 'status' => 'applied', 'value' => $value];
    }

    $note = 'Constants take effect on the NEXT request (PHP constants are fixed for the lifetime of the current request).';

    if (!$changed) {
        return wpultra_ok(['applied' => $results, 'backup_path' => '', 'note' => 'No changes needed; all requested constants already match wp-config.php.']);
    }

    if (!is_writable($path)) {
        return wpultra_err('not_writable', "wp-config.php is not writable at $path.");
    }

    $backup_path = $path . '.wpultra-backup';
    if (@copy($path, $backup_path) === false) {
        return wpultra_err('backup_failed', "Could not create a backup at $backup_path; aborting for safety.");
    }

    if (@file_put_contents($path, $working) === false) {
        $restored = @copy($backup_path, $path);
        $restore_note = $restored
            ? 'wp-config.php was restored from backup.'
            : 'the restore from backup ALSO failed; wp-config.php may be left truncated/corrupted at ' . $path . ' — restore manually from ' . $backup_path . '.';
        return wpultra_err('write_failed', "Failed to write wp-config.php at $path; $restore_note");
    }

    // Verify: re-read the file we just wrote and confirm it parses back correctly.
    $reread = (string) file_get_contents($path);
    $after = wpultra_debugmode_read_defines($reread);
    $mismatches = [];
    foreach ($plan as $const => $value) {
        if (($after[$const]['value'] ?? null) !== $value) { $mismatches[] = $const; }
    }
    if (!empty($mismatches) || !wpultra_debugmode_has_sentinel($reread)) {
        $restored = @copy($backup_path, $path);
        $reason = $mismatches !== [] ? ('values did not verify for: ' . implode(', ', $mismatches)) : 'the "stop editing" sentinel is missing after the write';
        if ($restored) {
            return wpultra_err('verify_failed', "Post-write verification failed ($reason); wp-config.php was restored from backup.");
        }
        return wpultra_err('restore_failed', "Post-write verification failed ($reason); the restore from backup ALSO failed — wp-config.php may be left in a bad state at $path; restore manually from $backup_path.");
    }

    wpultra_audit_log('debug-mode', 'set ' . implode(',', array_map(static fn($r) => $r['constant'] . '=' . var_export($r['value'], true), $results)), true);

    return wpultra_ok(['applied' => $results, 'backup_path' => $backup_path, 'note' => $note]);
}

/**
 * restore-backup: restore wp-config.php from the .wpultra-backup sibling file
 * written by wpultra_debugmode_set(). Requires confirm:true.
 *
 * @return array|WP_Error
 */
function wpultra_debugmode_restore_backup(array $input) {
    $confirm = ($input['confirm'] ?? false) === true;
    if (!$confirm) {
        return wpultra_err('unconfirmed', 'Restoring wp-config.php overwrites the live file. Re-run with confirm: true.');
    }

    $path = wpultra_debugmode_config_path();
    if ($path === '') {
        return wpultra_err('config_not_found', 'wp-config.php location unknown.');
    }
    $backup_path = $path . '.wpultra-backup';
    if (!is_file($backup_path) || !is_readable($backup_path)) {
        return wpultra_err('no_backup', "No backup found at $backup_path.");
    }
    if (is_file($path) && !is_writable($path)) {
        return wpultra_err('not_writable', "wp-config.php is not writable at $path.");
    }

    if (!@copy($backup_path, $path)) {
        return wpultra_err('restore_failed', "Failed to restore wp-config.php from $backup_path.");
    }

    wpultra_audit_log('debug-mode', "restore-backup from $backup_path", true);
    return wpultra_ok(['restored_from' => $backup_path, 'config_path' => $path]);
}
