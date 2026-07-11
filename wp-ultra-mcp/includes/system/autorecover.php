<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * auto-recover engine (Roadmap-4, BF2.1) — opt-in self-healing on a self-captured fatal.
 *
 * On a fatal PHP error, includes/system/errors.php already captures a structured report
 * (file/message/line/url/ts) into the `wpultra_error_log` ring. This engine is purely
 * ADVISORY by default: `status` reads the ring + diagnoses which plugin (if any) the
 * newest fatal's file path implicates, whether that plugin is currently active, and
 * whether an undo snapshot is available — but takes no action.
 *
 * `recover` is the only action that mutates anything, and only when opted in
 * (`wpultra_autorecover` option truthy, toggled via `arm`/`disarm`) AND `confirm:true` is
 * passed. Two strategies:
 *   - deactivate-plugin: resolve the plugin folder from the newest fatal's file path,
 *     confirm it maps to a CURRENTLY ACTIVE plugin, then drop it from `active_plugins`
 *     via a DIRECT option write (never deactivate_plugins()) — mirrors BF1's
 *     conflict-bisect (includes/system/bisect.php) silent-toggle pattern: no
 *     (de)activation hooks fire, and the write is read back to verify it landed.
 *   - undo-last: delegate to the undo engine's restore-last (includes/undo/engine.php).
 *   - auto: prefer deactivate-plugin when a fatal clearly implicates a specific,
 *     currently-active 3rd-party plugin; otherwise fall back to undo-last.
 *
 * Self-protection is mandatory and layered twice:
 *   1. wpultra_autorecover_culprit_from_path() hardcodes 'wp-ultra-mcp' as a non-culprit
 *      folder name, so the plugin can never even be DIAGNOSED as the culprit.
 *   2. wpultra_autorecover_plan()/wpultra_autorecover_silent_deactivate() independently
 *      compare against the live self basename (wpultra_system_self_plugin(), from
 *      includes/system/engine.php) on the folder segment, so a renamed/reinstalled
 *      self folder is still caught even if it no longer matches the literal string
 *      "wp-ultra-mcp".
 *
 * Everything `recover` does is reversible and reported back with how to reverse it
 * (reactivate the plugin / note that the undo entry is consumed on restore, with no
 * automatic redo). Activity logging is automatic via wp_before_execute_ability; this
 * file only adds an extra wpultra_audit_log() call per action, matching the pattern
 * conflict-bisect and optimize-database use for their own richer summaries.
 *
 * PURE functions (no WordPress calls): wpultra_autorecover_culprit_from_path(),
 * wpultra_autorecover_find_active_by_slug(), wpultra_autorecover_plan(). These are what
 * tests/autorecover.test.php exercises. WP-touching wrappers below are thin and guard
 * function_exists so this file still loads standalone in the test harness.
 */

const WPULTRA_AUTORECOVER_OPTION = 'wpultra_autorecover';

// ---------------------------------------------------------------------------
// PURE: path parsing / planning
// ---------------------------------------------------------------------------

/**
 * Given a fatal's file path and the plugins directory, return the plugin FOLDER slug
 * (e.g. '/wp-content/plugins/bad-plugin/includes/x.php' -> 'bad-plugin'), or null if the
 * path isn't under plugins (theme, mu-plugins, core, ...) or resolves to wp-ultra-mcp
 * itself. Handles Windows and Unix separators, a trailing slash on $plugins_dir, and is
 * case-insensitive on both the plugins_dir match and the self-folder check (the returned
 * slug itself preserves the original case of the path). Pure.
 */
function wpultra_autorecover_culprit_from_path(string $file, string $plugins_dir): ?string {
    $file_n = str_replace('\\', '/', trim($file));
    $dir_n  = rtrim(str_replace('\\', '/', trim($plugins_dir)), '/');

    $rel = null;
    if ($file_n === '') { return null; }
    if ($dir_n !== '' && strncasecmp($file_n, $dir_n . '/', strlen($dir_n) + 1) === 0) {
        $rel = substr($file_n, strlen($dir_n) + 1);
    } elseif (preg_match('#(?:^|/)wp-content/plugins/(.+)$#i', $file_n, $m)) {
        $rel = $m[1];
    } else {
        return null;
    }

    if ($rel === '' ) { return null; }
    $slug = strtok($rel, '/');
    if ($slug === false || $slug === '') { return null; }
    if (strcasecmp($slug, 'wp-ultra-mcp') === 0) { return null; }
    return $slug;
}

/**
 * Find the active_plugins entry whose folder segment matches $slug (case-insensitive),
 * or null if no active plugin matches. Pure.
 */
function wpultra_autorecover_find_active_by_slug(string $slug, array $active_plugins): ?string {
    $slug_l = strtolower($slug);
    foreach ($active_plugins as $p) {
        $p = (string) $p;
        $dir = strtolower(strtok($p, '/') ?: $p);
        if ($dir === $slug_l) { return $p; }
    }
    return null;
}

/**
 * Decide the concrete recovery action for $strategy, never selecting self. Returns:
 *   ['action' => 'deactivate-plugin', 'plugin' => <basename>, 'reason' => null, 'reverse' => <hint>]
 *   ['action' => 'undo-last',         'plugin' => null,       'reason' => null, 'reverse' => <hint>]
 *   ['action' => 'no-op',             'plugin' => <slug|null>,'reason' => 'culprit-unknown'|'culprit-not-active'|'unknown-strategy', 'reverse' => null]
 * Pure — $newest_fatal is a ring entry (or []), $active_plugins the live active_plugins
 * list, $self_basename this plugin's own basename (folder/file.php). $plugins_dir_rel is
 * the ABSPATH-relative plugins dir to resolve the culprit against — callers MUST pass the
 * same value used to produce any advisory diagnosis (e.g. wpultra_autorecover_status()'s
 * wpultra_autorecover_plugins_dir_rel()) so a customized WP_PLUGIN_DIR doesn't make
 * `status` and `recover` disagree on the culprit. Defaults to the conventional path for
 * callers/tests that don't care about a custom plugins dir.
 */
function wpultra_autorecover_plan(array $newest_fatal, array $active_plugins, string $self_basename, string $strategy, string $plugins_dir_rel = 'wp-content/plugins'): array {
    $strategy = strtolower(trim($strategy));
    $self_dir = strtolower(strtok($self_basename, '/') ?: $self_basename);

    $file = (string) ($newest_fatal['file'] ?? '');
    // Fatal-ring file paths are stored ABSPATH-relative (includes/system/errors.php).
    // $plugins_dir_rel must be resolved the same way wpultra_autorecover_status() resolves
    // it (via wpultra_autorecover_plugins_dir_rel()), so a customized WP_PLUGIN_DIR still
    // diagnoses the SAME culprit here as it does in status.
    $culprit_slug = $file !== '' ? wpultra_autorecover_culprit_from_path($file, $plugins_dir_rel) : null;

    $deactivate_plan = static function () use ($culprit_slug, $active_plugins, $self_dir): array {
        if ($culprit_slug === null || strtolower($culprit_slug) === $self_dir) {
            return ['action' => 'no-op', 'plugin' => null, 'reason' => 'culprit-unknown', 'reverse' => null];
        }
        $active_basename = wpultra_autorecover_find_active_by_slug($culprit_slug, $active_plugins);
        if ($active_basename === null) {
            return ['action' => 'no-op', 'plugin' => $culprit_slug, 'reason' => 'culprit-not-active', 'reverse' => null];
        }
        return ['action' => 'deactivate-plugin', 'plugin' => $active_basename, 'reason' => null, 'reverse' => "reactivate $active_basename"];
    };
    $undo_plan = static function (): array {
        return ['action' => 'undo-last', 'plugin' => null, 'reason' => null, 'reverse' => 'the undo entry is consumed on restore; there is no automatic redo'];
    };

    switch ($strategy) {
        case 'deactivate-plugin':
            return $deactivate_plan();
        case 'undo-last':
            return $undo_plan();
        case 'auto':
            $plan = $deactivate_plan();
            return $plan['action'] === 'deactivate-plugin' ? $plan : $undo_plan();
        default:
            return ['action' => 'no-op', 'plugin' => null, 'reason' => 'unknown-strategy', 'reverse' => null];
    }
}

// ---------------------------------------------------------------------------
// WP-touching wrappers (guarded; not exercised by the pure unit tests)
// ---------------------------------------------------------------------------

function wpultra_autorecover_is_armed(): bool {
    return (bool) (function_exists('get_option') ? get_option(WPULTRA_AUTORECOVER_OPTION, false) : false);
}

function wpultra_autorecover_arm(bool $armed): void {
    if (function_exists('update_option')) { update_option(WPULTRA_AUTORECOVER_OPTION, $armed); }
}

function wpultra_autorecover_active_plugins(): array {
    $raw = function_exists('get_option') ? get_option('active_plugins', []) : [];
    return is_array($raw) ? array_values(array_map('strval', $raw)) : [];
}

/** ABSPATH-relative plugins dir, reusing errors.php's trim helper so a custom WP_PLUGIN_DIR still resolves. */
function wpultra_autorecover_plugins_dir_rel(): string {
    if (defined('WP_PLUGIN_DIR') && defined('ABSPATH') && function_exists('wpultra_errors_trim_path')) {
        $rel = wpultra_errors_trim_path((string) WP_PLUGIN_DIR, (string) ABSPATH);
        if ($rel !== '') { return $rel; }
    }
    return 'wp-content/plugins';
}

/**
 * Write `active_plugins` DIRECTLY (no deactivate_plugins()) so deactivation hooks never
 * fire — mirrors includes/system/bisect.php's silent-toggle pattern. Self-protection is
 * checked FIRST, before any option write, on the folder segment (tolerant of a differing
 * main-file name). The write is read back to verify it landed.
 */
function wpultra_autorecover_silent_deactivate(string $plugin, array $active_plugins, string $self): bool {
    $self_dir   = strtolower(strtok($self, '/') ?: $self);
    $plugin_dir = strtolower(strtok($plugin, '/') ?: $plugin);
    if ($plugin_dir === $self_dir) { return false; }

    if (!function_exists('update_option') || !function_exists('get_option')) { return false; }
    $new_list = array_values(array_filter($active_plugins, static fn($p) => (string) $p !== $plugin));
    update_option('active_plugins', $new_list);
    $read_back = get_option('active_plugins', []);
    $read_back = is_array($read_back) ? array_values(array_map('strval', $read_back)) : [];
    return !in_array($plugin, $read_back, true);
}

/** Advisory read-only snapshot: armed?, recent fatals, diagnosed culprit + its active state, last undo entry. */
function wpultra_autorecover_status(): array {
    $armed  = wpultra_autorecover_is_armed();
    $fatals = function_exists('wpultra_errors_read') ? wpultra_errors_read(['limit' => 5]) : [];
    $newest = $fatals[0] ?? null;

    $culprit = null;
    $culprit_active = null;
    if ($newest !== null) {
        $file = (string) ($newest['file'] ?? '');
        $culprit = $file !== '' ? wpultra_autorecover_culprit_from_path($file, wpultra_autorecover_plugins_dir_rel()) : null;
        if ($culprit !== null) {
            $culprit_active = wpultra_autorecover_find_active_by_slug($culprit, wpultra_autorecover_active_plugins()) !== null;
        }
    }

    $undo_stack = function_exists('wpultra_undo_load_stack') ? wpultra_undo_load_stack() : [];
    $last_undo  = $undo_stack[0] ?? null;
    if ($last_undo !== null && function_exists('wpultra_undo_shape')) { $last_undo = wpultra_undo_shape($last_undo); }

    return wpultra_ok([
        'armed'             => $armed,
        'recent_fatals'     => $fatals,
        'newest_fatal'      => $newest,
        'diagnosed_culprit' => $culprit,
        'culprit_active'    => $culprit_active,
        'last_undo'         => $last_undo,
    ]);
}

/** @return array|WP_Error */
function wpultra_autorecover_recover(array $input) {
    if (($input['confirm'] ?? false) !== true) {
        return wpultra_err('confirm_required', 'auto-recover deactivates a plugin or reverts the last change. Re-run with confirm:true.');
    }
    if (!wpultra_autorecover_is_armed()) {
        return wpultra_err('not_armed', 'auto-recover is not armed. Run action:arm (with confirm:true) to opt in first.');
    }

    $strategy = (string) ($input['strategy'] ?? 'auto');
    if (!in_array($strategy, ['deactivate-plugin', 'undo-last', 'auto'], true)) {
        return wpultra_err('bad_strategy', "Unknown strategy '$strategy'. Use deactivate-plugin, undo-last, or auto.");
    }

    $fatals = function_exists('wpultra_errors_read') ? wpultra_errors_read(['limit' => 1]) : [];
    $newest = $fatals[0] ?? [];

    $self   = function_exists('wpultra_system_self_plugin') ? wpultra_system_self_plugin() : 'wp-ultra-mcp/wp-ultra-mcp.php';
    $active = wpultra_autorecover_active_plugins();

    // Resolve the plugins-dir prefix the SAME way status() does, so a customized
    // WP_PLUGIN_DIR can't make recover() diagnose a different culprit than status()
    // already advised (see wpultra_autorecover_plan()'s doc comment).
    $plan = wpultra_autorecover_plan($newest, $active, $self, $strategy, wpultra_autorecover_plugins_dir_rel());

    switch ($plan['action']) {
        case 'deactivate-plugin':
            $plugin = (string) $plan['plugin'];
            $ok = wpultra_autorecover_silent_deactivate($plugin, $active, $self);
            wpultra_audit_log('auto-recover', "recover deactivate-plugin=$plugin ok=" . ($ok ? 'yes' : 'no'), $ok);
            if (!$ok) { return wpultra_err('deactivate_failed', "Could not deactivate $plugin (the option write did not verify, or it resolved to the self plugin)."); }
            return wpultra_ok([
                'action'  => 'deactivate-plugin',
                'plugin'  => $plugin,
                'reverse' => "Reactivate $plugin (e.g. via manage-plugin-theme) once the underlying issue is fixed.",
            ]);

        case 'undo-last':
            $stack = function_exists('wpultra_undo_load_stack') ? wpultra_undo_load_stack() : [];
            $last  = $stack[0] ?? null;
            if ($last === null) {
                wpultra_audit_log('auto-recover', 'recover undo-last: no undo entries available', false);
                return wpultra_err('no_undo_entry', 'No undo snapshot is available to restore.');
            }
            $res = function_exists('wpultra_undo_restore') ? wpultra_undo_restore((int) $last['id']) : wpultra_err('undo_unavailable', 'Undo engine is unavailable.');
            if (is_wp_error($res)) {
                wpultra_audit_log('auto-recover', 'recover undo-last failed: ' . $res->get_error_message(), false);
                return $res;
            }
            wpultra_audit_log('auto-recover', 'recover undo-last id=' . $last['id'], true);
            return wpultra_ok([
                'action'   => 'undo-last',
                'restored' => $res,
                'reverse'  => 'This consumed the undo entry; there is no automatic redo — re-apply the change manually if it turns out to be unwanted.',
            ]);

        default: // no-op
            $reason = (string) ($plan['reason'] ?? 'no-op');
            wpultra_audit_log('auto-recover', "recover no-op reason=$reason", false);
            return wpultra_err('no_action', "Could not determine a safe recovery action ($reason).");
    }
}

/** @return array|WP_Error */
function wpultra_autorecover_run(array $input) {
    $action = (string) ($input['action'] ?? 'status');
    switch ($action) {
        case 'status':
            return wpultra_autorecover_status();

        case 'arm':
            if (($input['confirm'] ?? false) !== true) { return wpultra_err('confirm_required', 'Pass confirm:true to arm auto-recover.'); }
            wpultra_autorecover_arm(true);
            wpultra_audit_log('auto-recover', 'armed', true);
            return wpultra_ok(['armed' => true]);

        case 'disarm':
            if (($input['confirm'] ?? false) !== true) { return wpultra_err('confirm_required', 'Pass confirm:true to disarm auto-recover.'); }
            wpultra_autorecover_arm(false);
            wpultra_audit_log('auto-recover', 'disarmed', true);
            return wpultra_ok(['armed' => false]);

        case 'recover':
            return wpultra_autorecover_recover($input);

        default:
            return wpultra_err('unknown_action', "Unknown action '$action'. Use status, arm, disarm, or recover.");
    }
}
