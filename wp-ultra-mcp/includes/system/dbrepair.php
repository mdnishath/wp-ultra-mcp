<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * DB-repair engine (Roadmap-4 BF1.4): CHECK TABLE all site-prefixed tables, REPAIR TABLE the
 * broken ones, and an optional core-schema drift check/repair via dbDelta. A DB snapshot is
 * always created first via the existing db-snapshot engine (includes/system/siteops.php,
 * wpultra_siteops_db_snapshot()) before any repair action runs — repair NEVER proceeds without
 * a restore point. Pure logic (LIKE-pattern escaping, CHECK/REPAIR row normalization, and the
 * repair plan) is kept in small testable functions; anything that touches $wpdb/WordPress lives
 * in a thin wrapper around it.
 */

/* ============================================================
 * Pure functions
 * ============================================================ */

/**
 * Build the `LIKE '<prefix>%'` pattern for the site's table prefix, escaping `_`, `%`, and `\`
 * exactly like $wpdb->esc_like() (which is literally `addcslashes($text, '_%\\')`), then
 * appending the trailing wildcard. Pure — takes no WordPress dependency.
 */
function wpultra_dbrepair_like_prefix(string $prefix): string {
    return addcslashes($prefix, '_%\\') . '%';
}

/**
 * Normalize a CHECK TABLE / REPAIR TABLE result set (mysqli-style rows with Msg_type/Msg_text)
 * into a single {status, messages[]} verdict.
 *
 * Rules:
 *  - Any `error`-type row makes the table `corrupt` (highest priority, wins over everything).
 *  - A `status`-type row whose text is exactly "Operation failed" also means `corrupt`.
 *  - A `warning`-type row makes it `warning`, unless already `corrupt`.
 *  - "OK" and "Table is already up to date" status rows are informational-only (ok).
 *  - Any other row (e.g. an InnoDB `note` saying repair is unsupported) is recorded as a
 *    message but does not change the status by itself.
 *  - No rows at all => ok with no messages.
 *
 * @param array<int,array<string,mixed>> $rows
 * @return array{status:string,messages:array<int,string>}
 */
function wpultra_dbrepair_parse_check(array $rows): array {
    $status = 'ok';
    $messages = [];

    foreach ($rows as $row) {
        if (!is_array($row)) { continue; }

        $type = strtolower(trim((string) ($row['Msg_type'] ?? $row['msg_type'] ?? '')));
        $text = trim((string) ($row['Msg_text'] ?? $row['msg_text'] ?? ''));
        if ($type === '' && $text === '') { continue; }

        $messages[] = $type !== '' ? "{$type}: {$text}" : $text;

        $text_lc = strtolower($text);
        $is_ok_text = $text_lc === 'ok' || $text_lc === 'table is already up to date';

        if ($type === 'error') {
            $status = 'corrupt';
        } elseif ($type === 'warning' && $status !== 'corrupt') {
            $status = 'warning';
        } elseif ($type === 'status' && !$is_ok_text && $text_lc === 'operation failed') {
            $status = 'corrupt';
        }
    }

    return ['status' => $status, 'messages' => $messages];
}

/**
 * Decide, from a set of already-checked tables, which get REPAIR TABLE, which are skipped
 * because InnoDB doesn't support REPAIR TABLE, and which need nothing.
 *
 * @param array<int,array{table?:string,engine?:string,status?:string}> $checked  Per-table check results.
 * @param bool $all When true, every non-InnoDB table is scheduled for repair, not just ones
 *                  whose CHECK came back corrupt/warning (InnoDB tables are still just skipped).
 * @return array{repair:array<int,string>,skipped_innodb:array<int,string>,no_action:array<int,string>}
 */
function wpultra_dbrepair_plan(array $checked, bool $all): array {
    $repair = [];
    $skipped_innodb = [];
    $no_action = [];

    foreach ($checked as $entry) {
        if (!is_array($entry)) { continue; }
        $table = (string) ($entry['table'] ?? '');
        if ($table === '') { continue; }

        $engine = strtolower((string) ($entry['engine'] ?? ''));
        $status = (string) ($entry['status'] ?? 'ok');
        $needs_repair = $all || $status === 'corrupt' || $status === 'warning';

        if (!$needs_repair) {
            $no_action[] = $table;
            continue;
        }
        if ($engine === 'innodb') {
            $skipped_innodb[] = $table;
        } else {
            $repair[] = $table;
        }
    }

    return ['repair' => $repair, 'skipped_innodb' => $skipped_innodb, 'no_action' => $no_action];
}

/* ============================================================
 * WordPress-touching wrappers
 * ============================================================ */

/** Enumerate every table under the site's prefix. @return array<int,string> */
function wpultra_dbrepair_list_tables(): array {
    global $wpdb;
    if (!isset($wpdb)) { return []; }
    $like = wpultra_dbrepair_like_prefix((string) $wpdb->prefix);
    $rows = $wpdb->get_col("SHOW TABLES LIKE '{$like}'");
    return is_array($rows) ? array_map('strval', $rows) : [];
}

/** Look up a table's storage engine via information_schema. @return string ('' when unknown) */
function wpultra_dbrepair_table_engine(string $table): string {
    global $wpdb;
    if (!isset($wpdb)) { return ''; }
    $row = $wpdb->get_row(
        $wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table),
        ARRAY_A
    );
    return is_array($row) && isset($row['ENGINE']) ? (string) $row['ENGINE'] : '';
}

/** Run CHECK TABLE on one table (name comes from SHOW TABLES, not user input). @return array */
function wpultra_dbrepair_check_table(string $table): array {
    global $wpdb;
    if (!isset($wpdb)) { return []; }
    $escaped = str_replace('`', '``', $table);
    $rows = $wpdb->get_results("CHECK TABLE `{$escaped}`", ARRAY_A);
    return is_array($rows) ? $rows : [];
}

/** Run REPAIR TABLE on one table. @return array */
function wpultra_dbrepair_repair_table(string $table): array {
    global $wpdb;
    if (!isset($wpdb)) { return []; }
    $escaped = str_replace('`', '``', $table);
    $rows = $wpdb->get_results("REPAIR TABLE `{$escaped}`", ARRAY_A);
    return is_array($rows) ? $rows : [];
}

/**
 * Read-only `check` action: CHECK TABLE every prefixed table + its engine.
 * @return array{tables:array<int,array>,summary:array}
 */
function wpultra_dbrepair_run_check(): array {
    $tables = wpultra_dbrepair_list_tables();
    $out = [];
    $summary = ['total' => 0, 'ok' => 0, 'corrupt' => 0, 'warnings' => 0, 'innodb' => 0];

    foreach ($tables as $table) {
        $engine = wpultra_dbrepair_table_engine($table);
        $parsed = wpultra_dbrepair_parse_check(wpultra_dbrepair_check_table($table));

        $out[] = [
            'table'    => $table,
            'engine'   => $engine,
            'status'   => $parsed['status'],
            'messages' => $parsed['messages'],
        ];

        $summary['total']++;
        if ($parsed['status'] === 'corrupt') {
            $summary['corrupt']++;
        } elseif ($parsed['status'] === 'warning') {
            $summary['warnings']++;
        } else {
            $summary['ok']++;
        }
        if (strtolower($engine) === 'innodb') { $summary['innodb']++; }
    }

    return ['tables' => $out, 'summary' => $summary];
}

/**
 * Create the mandatory pre-repair DB snapshot via the existing db-snapshot engine
 * (wp-ultra-mcp/includes/system/siteops.php, wpultra_siteops_db_snapshot()).
 * @return array|WP_Error
 */
function wpultra_dbrepair_snapshot() {
    if (!function_exists('wpultra_siteops_db_snapshot') && defined('WPULTRA_DIR') && is_readable(WPULTRA_DIR . 'includes/system/siteops.php')) {
        require_once WPULTRA_DIR . 'includes/system/siteops.php';
    }
    if (!function_exists('wpultra_siteops_db_snapshot')) {
        return wpultra_err('snapshot_engine_unavailable', 'The DB snapshot engine (includes/system/siteops.php) is not loaded; cannot create a restore point before repair.');
    }

    $name = 'pre-repair-' . gmdate('Ymd-His');
    return wpultra_siteops_db_snapshot(['action' => 'create', 'snapshot' => $name]);
}

/**
 * `repair` action: snapshot first (abort on failure), CHECK all tables, plan which need
 * REPAIR TABLE, run it, and report what happened.
 * @return array|WP_Error
 */
function wpultra_dbrepair_run_repair(bool $all) {
    $snapshot = wpultra_dbrepair_snapshot();
    if (is_wp_error($snapshot)) {
        return wpultra_err('snapshot_failed', 'Aborting repair: could not create a DB snapshot restore point first. ' . $snapshot->get_error_message());
    }

    $checked = wpultra_dbrepair_run_check();
    $plan = wpultra_dbrepair_plan($checked['tables'], $all);

    $repaired = [];
    $still_broken = [];
    foreach ($plan['repair'] as $table) {
        $parsed = wpultra_dbrepair_parse_check(wpultra_dbrepair_repair_table($table));
        $entry = ['table' => $table, 'status' => $parsed['status'], 'messages' => $parsed['messages']];
        if ($parsed['status'] === 'ok') {
            $repaired[] = $entry;
        } else {
            $still_broken[] = $entry;
        }
    }

    $skipped_innodb = array_map(static function (string $table): array {
        return [
            'table'  => $table,
            'repair' => 'unsupported_innodb',
            'note'   => "InnoDB does not support REPAIR TABLE; it self-recovers via crash recovery on restart, or restore from a backup/snapshot.",
        ];
    }, $plan['skipped_innodb']);

    return [
        'snapshot'       => $snapshot,
        'repaired'       => $repaired,
        'skipped_innodb' => $skipped_innodb,
        'still_broken'   => $still_broken,
    ];
}

/**
 * Core-schema drift check/repair via dbDelta(). Loads wp-admin/includes/upgrade.php on demand.
 * @return array{statements:array<int,string>,executed:bool}|WP_Error
 */
function wpultra_dbrepair_run_schema(bool $execute) {
    if (!function_exists('dbDelta') && defined('ABSPATH') && is_readable(ABSPATH . 'wp-admin/includes/upgrade.php')) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    }
    if (!function_exists('dbDelta') || !function_exists('wp_get_db_schema')) {
        return wpultra_err('schema_engine_unavailable', 'wp-admin/includes/upgrade.php (dbDelta/wp_get_db_schema) is unavailable in this environment.');
    }

    try {
        $schema = wp_get_db_schema('all');
        $result = dbDelta($schema, $execute);
        return ['statements' => is_array($result) ? array_values($result) : [], 'executed' => $execute];
    } catch (\Throwable $e) {
        return wpultra_err('schema_check_failed', 'Core-schema ' . ($execute ? 'repair' : 'check') . ' unavailable: ' . $e->getMessage());
    }
}

/**
 * `schema-repair` action: snapshot first (abort on failure), then dbDelta($schema, true).
 * @return array|WP_Error
 */
function wpultra_dbrepair_run_schema_repair() {
    $snapshot = wpultra_dbrepair_snapshot();
    if (is_wp_error($snapshot)) {
        return wpultra_err('snapshot_failed', 'Aborting schema repair: could not create a DB snapshot restore point first. ' . $snapshot->get_error_message());
    }

    $res = wpultra_dbrepair_run_schema(true);
    if (is_wp_error($res)) { return $res; }

    return array_merge(['snapshot' => $snapshot], $res);
}
