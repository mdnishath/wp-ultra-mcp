<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

// Defensive engine require so this ability works regardless of load order
// (mirrors health-monitor → includes/system/health.php).
if (!function_exists('wpultra_dbrepair_run_check') && defined('WPULTRA_DIR') && is_readable(WPULTRA_DIR . 'includes/system/dbrepair.php')) {
    require_once WPULTRA_DIR . 'includes/system/dbrepair.php';
}

wp_register_ability('wpultra/repair-database', [
    'label'       => __('Repair Database', 'wp-ultra-mcp'),
    'description' => __('First-class DB repair. actions: `check` (read-only, default) — CHECK TABLE every table under the site prefix, reporting per-table {table, engine, status: ok|corrupt|warning, messages[]} plus summary counts; `repair` (confirm-gated) — creates a DB snapshot FIRST via the db-snapshot engine (aborts if the snapshot fails, so repair never runs without a restore point), then REPAIR TABLEs only the tables CHECK flagged corrupt/warning (or every prefixed table when all:true); InnoDB tables cannot be REPAIR TABLE\'d and are reported as skipped_innodb (recover via restart/crash-recovery or restore from a backup) rather than attempted; `schema-check` (read-only) — previews the ALTER/CREATE statements WordPress\' own dbDelta() would run to fix core-schema drift, without executing them; `schema-repair` (confirm-gated) — same auto-snapshot rule, then actually runs dbDelta().', 'wp-ultra-mcp'),
    'category'    => 'database',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['check', 'repair', 'schema-check', 'schema-repair'], 'default' => 'check'],
            'all'     => ['type' => 'boolean', 'default' => false, 'description' => 'repair: REPAIR TABLE every prefixed table, not just the ones CHECK flagged.'],
            'confirm' => ['type' => 'boolean', 'description' => 'Required (true) for repair and schema-repair.'],
        ],
        'required'             => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'        => ['type' => 'boolean'],
            'tables'         => ['type' => 'array'],
            'summary'        => ['type' => 'object'],
            'snapshot'       => ['type' => 'object'],
            'repaired'       => ['type' => 'array'],
            'skipped_innodb' => ['type' => 'array'],
            'still_broken'   => ['type' => 'array'],
            'statements'     => ['type' => 'array'],
            'executed'       => ['type' => 'boolean'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_repair_database_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => true],
    ],
]);

/** @return array|WP_Error */
function wpultra_repair_database_cb(array $input) {
    if (!function_exists('wpultra_dbrepair_run_check')) {
        return wpultra_err('engine_unavailable', 'The DB repair engine (includes/system/dbrepair.php) is not loaded.');
    }

    $action = (string) ($input['action'] ?? 'check');

    switch ($action) {
        case 'check':
            return wpultra_ok(wpultra_dbrepair_run_check());

        case 'repair':
            if (($input['confirm'] ?? false) !== true) {
                return wpultra_err('confirm_required', 'Repairing tables is destructive. Re-run with confirm:true. A DB snapshot is taken automatically first.');
            }
            $all = ($input['all'] ?? false) === true;
            $res = wpultra_dbrepair_run_repair($all);
            if (is_wp_error($res)) { return $res; }
            return wpultra_ok($res);

        case 'schema-check':
            $res = wpultra_dbrepair_run_schema(false);
            if (is_wp_error($res)) { return $res; }
            return wpultra_ok($res);

        case 'schema-repair':
            if (($input['confirm'] ?? false) !== true) {
                return wpultra_err('confirm_required', 'Running the core-schema repair is destructive. Re-run with confirm:true. A DB snapshot is taken automatically first.');
            }
            $res = wpultra_dbrepair_run_schema_repair();
            if (is_wp_error($res)) { return $res; }
            return wpultra_ok($res);

        default:
            return wpultra_err('bad_action', "Unknown action '$action'. Use one of: check, repair, schema-check, schema-repair.");
    }
}
