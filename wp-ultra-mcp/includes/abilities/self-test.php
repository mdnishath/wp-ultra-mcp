<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/self-test', [
    'label'       => __('Self Test & Diagnostics', 'wp-ultra-mcp'),
    'description' => __('Run a structural + environment health check on WP-Ultra-MCP and return per-ability usage/failure stats so the AI can detect broken wiring and self-correct. actions: `report` (default), `reset-stats`. Use this first when an ability behaves unexpectedly.', 'wp-ultra-mcp'),
    'category'    => 'diagnostics',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action' => ['type' => 'string', 'enum' => ['report', 'reset-stats'], 'default' => 'report'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'ok'      => ['type' => 'boolean'],
            'checks'  => ['type' => 'array'],
            'failed'  => ['type' => 'array'],
            'stats'   => ['type' => 'array'],
            'hints'   => ['type' => 'array'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_self_test',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_self_test(array $input) {
    $action = (string) ($input['action'] ?? 'report');
    if ($action === 'reset-stats') {
        update_option('wpultra_ability_stats', [], false);
        return wpultra_ok(['ok' => true, 'checks' => [], 'failed' => [], 'stats' => [], 'hints' => ['stats cleared']]);
    }

    $disabled = wpultra_disabled_categories();
    $exists   = 'function_exists';
    $checks   = [];
    $hints    = [];

    // 1. Core wiring.
    $checks[] = ['name' => 'mcp_enabled', 'ok' => wpultra_is_enabled(), 'detail' => 'AI control switch + domain lock'];
    $checks[] = ['name' => 'adapter_loaded', 'ok' => wpultra_mcp_adapter_available(), 'detail' => 'bundled MCP adapter class present'];

    // 1b. Access-gate wiring: non-admin grants are enforced by wp_before_execute_ability;
    //    if that hook isn't registered AND verifiably fired by core, grants are dead
    //    (the baseline callback fails closed, so this is a lockout — not a bypass).
    if (function_exists('wpultra_access_execute_hook_fires') && function_exists('wpultra_access_policy')) {
        $grants = wpultra_access_has_any_grant(array_keys((array) (wpultra_access_policy()['roles'] ?? [])), wpultra_access_policy());
        $hooked = function_exists('has_action') && has_action('wp_before_execute_ability', 'wpultra_access_gate') !== false;
        $fires  = wpultra_access_execute_hook_fires();
        $wired  = $hooked && $fires;
        $checks[] = [
            'name'   => 'access_gate_wired',
            'ok'     => $wired || !$grants,
            'detail' => $wired ? 'gate registered + hook verified' : ($hooked ? 'gate registered but wp_before_execute_ability not verifiable in WP_Ability' : 'gate not registered'),
        ];
        if (!$wired && $grants) { $hints[] = 'Role grants exist but the enforcement hook is unverified — non-admin access is denied (fail closed) until the Abilities API fires wp_before_execute_ability.'; }
    }

    // 2. Ability file <-> category integrity (every file maps to exactly one known category).
    $orphans = [];
    foreach (wpultra_ability_files() as $f) {
        if (wpultra_file_category($f) === '') { $orphans[] = $f; }
    }
    $checks[] = ['name' => 'ability_category_map', 'ok' => $orphans === [], 'detail' => $orphans ? ('uncategorized: ' . implode(', ', $orphans)) : 'all ability files categorized'];
    if ($orphans) { $hints[] = 'Add ' . implode(', ', $orphans) . ' to wpultra_ability_category_map().'; }

    // 2b. Manifest ≡ disk: every listed slug has a file, and every file on disk is
    //     listed. A drift here silently drops an ability or fatals on a missing require.
    $listed = wpultra_ability_files();
    $on_disk = [];
    foreach ((array) glob(WPULTRA_DIR . 'includes/abilities/*.php') as $p) { $on_disk[] = basename($p, '.php'); }
    $missing_file = array_values(array_diff($listed, $on_disk));   // listed but no file
    $unlisted     = array_values(array_diff($on_disk, $listed));   // file but not listed
    $manifest_ok  = $missing_file === [] && $unlisted === [];
    $checks[] = ['name' => 'ability_manifest', 'ok' => $manifest_ok, 'detail' => $manifest_ok
        ? (count($listed) . ' abilities: manifest matches disk')
        : trim(($missing_file ? 'missing files: ' . implode(', ', $missing_file) . '. ' : '') . ($unlisted ? 'unlisted files: ' . implode(', ', $unlisted) : ''))];
    if ($missing_file) { $hints[] = 'wpultra_ability_files() lists ' . implode(', ', $missing_file) . ' but no such file exists (require will fatal).'; }
    if ($unlisted) { $hints[] = 'These ability files are on disk but not in wpultra_ability_files() (they never register): ' . implode(', ', $unlisted); }

    // 3. Fields adapter matrix — catches router/adapter name drift (a whole provider going dead).
    if (!in_array('fields', $disabled, true) && function_exists('wpultra_fields_active_names')) {
        $providers = wpultra_fields_active_names();
        $missing   = wpultra_selftest_provider_matrix($providers, $exists);
        $checks[] = ['name' => 'fields_adapter_matrix', 'ok' => $missing === [], 'detail' => $providers ? ($missing ? ('missing: ' . implode(', ', $missing)) : ('ok for: ' . implode(', ', $providers))) : 'no field provider active'];
        if ($missing) { $hints[] = 'Field adapter functions missing (read/write will fail): ' . implode(', ', $missing); }
    }

    // 4. Subsystem entrypoints exist for every ENABLED subsystem.
    $required = [];
    if (!in_array('elementor', $disabled, true))   { $required['elementor'] = ['wpultra_el_read', 'wpultra_el_write']; }
    if (!in_array('gutenberg', $disabled, true))   { $required['gutenberg'] = ['wpultra_gb_load', 'wpultra_gb_save', 'wpultra_gb_insert']; }
    if (!in_array('woocommerce', $disabled, true)) { $required['woocommerce'] = ['wpultra_woo_active']; }
    if (!in_array('seo', $disabled, true))         { $required['seo'] = ['wpultra_seo_get_meta']; }
    $broken = wpultra_selftest_subsystem_matrix($required, $exists);
    $checks[] = ['name' => 'subsystem_entrypoints', 'ok' => $broken === [], 'detail' => $broken ? wp_json_encode($broken) : 'all enabled subsystem functions present'];
    if ($broken) { $hints[] = 'Some enabled subsystems are missing engine functions: ' . wp_json_encode($broken); }

    // 5. Sandbox writable + hardened (executable-file jail).
    if (function_exists('wpultra_sandbox_dir')) {
        $dir = wpultra_sandbox_dir();
        if (function_exists('wpultra_sandbox_harden')) { wpultra_sandbox_harden(); }
        $writable = is_dir($dir) && is_writable($dir);
        $checks[] = ['name' => 'sandbox_writable', 'ok' => $writable, 'detail' => $dir];
        if ($writable) {
            $hardened = file_exists(rtrim($dir, '/\\') . '/.htaccess');
            $checks[] = ['name' => 'sandbox_hardened', 'ok' => $hardened, 'detail' => $hardened ? 'deny rule present' : 'no .htaccess deny rule (nginx/IIS need server config)'];
            if (!$hardened) { $hints[] = 'Sandbox lacks a web-deny rule; on nginx/IIS confirm executable files under ' . $dir . ' are not web-reachable.'; }
        } else {
            $hints[] = 'Sandbox dir not writable — execute-php / write-file of .php will fail: ' . $dir;
        }
    }

    // 6. Environment awareness (informational, never fails the run).
    $checks[] = ['name' => 'debug_log', 'ok' => true, 'detail' => (function_exists('wpultra_debug_log_path') && is_readable(wpultra_debug_log_path())) ? 'present' : 'absent (enable WP_DEBUG_LOG to capture errors)'];
    if ($disabled) { $hints[] = 'Disabled categories: ' . implode(', ', $disabled); }

    $summary = wpultra_selftest_summarize($checks);

    // 7. Per-ability failure stats — the AI's own recent failure patterns.
    if (function_exists('wpultra_audit_flush')) { wpultra_audit_flush(); } // include same-request buffered tallies
    $rawStats = get_option('wpultra_ability_stats', []);
    $stats = wpultra_stats_rank(is_array($rawStats) ? $rawStats : [], 10);
    foreach ($stats as $s) {
        if ($s['fails'] > 0 && $s['fail_rate'] >= 0.5) {
            $hints[] = "Ability '{$s['action']}' is failing {$s['fails']}/{$s['calls']} calls; last error: {$s['last_error']}";
        }
    }

    return wpultra_ok([
        'ok'     => $summary['ok'],
        'checks' => $summary['checks'],
        'failed' => $summary['failed'],
        'stats'  => $stats,
        'hints'  => $hints,
    ]);
}
