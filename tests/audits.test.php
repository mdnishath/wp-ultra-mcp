<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/system/audits.php';

// ---------------------------------------------------------------------------
// Security audit — pure rule evaluator
// ---------------------------------------------------------------------------

it('security evaluate: clean context yields all pass', function () {
    $ctx = [
        'core_version'           => '6.5',
        'core_latest_version'    => '',
        'core_update_available'  => false,
        'disallow_file_edit'     => true,
        'wp_debug'               => false,
        'wp_debug_display'       => false,
        'is_ssl'                 => true,
        'table_prefix'           => 'wp_x7q9_',
        'admin_usernames'        => ['sitemanager'],
        'admin_count'            => 1,
        'uploads_index_exists'   => true,
        'xmlrpc_enabled'         => false,
        'plugin_updates_pending' => 0,
        'theme_updates_pending'  => 0,
        'inactive_plugins_count' => 0,
        'salts_defined'          => true,
        'salts_placeholder'      => false,
    ];
    $findings = wpultra_audits_security_evaluate($ctx);
    $statuses = array_unique(array_column($findings, 'status'));
    assert_eq(['pass'], array_values($statuses));
    assert_eq(12, count($findings));
});

it('security evaluate: flags admin username, weak prefix, xmlrpc, missing index', function () {
    $ctx = [
        'admin_usernames'      => ['Admin', 'other'],
        'admin_count'          => 1,
        'table_prefix'         => 'wp_',
        'xmlrpc_enabled'       => true,
        'uploads_index_exists' => false,
    ];
    $findings = wpultra_audits_security_evaluate($ctx);
    $byId = [];
    foreach ($findings as $f) { $byId[$f['id']] = $f; }
    assert_eq('warn', $byId['admin_username']['status']);
    assert_eq('warn', $byId['table_prefix']['status']);
    assert_eq('warn', $byId['xmlrpc']['status']);
    assert_eq('warn', $byId['uploads_index']['status']);
});

it('security evaluate: flags core update available, debug display, no ssl as fail-level', function () {
    $ctx = [
        'core_version'          => '6.4',
        'core_latest_version'   => '6.5',
        'core_update_available' => true,
        'wp_debug'              => true,
        'wp_debug_display'      => true,
        'is_ssl'                => false,
    ];
    $findings = wpultra_audits_security_evaluate($ctx);
    $byId = [];
    foreach ($findings as $f) { $byId[$f['id']] = $f; }
    assert_eq('fail', $byId['core_update']['status']);
    assert_contains('6.4', $byId['core_update']['detail']);
    assert_contains('6.5', $byId['core_update']['detail']);
    assert_eq('fail', $byId['debug_display']['status']);
    assert_eq('fail', $byId['ssl']['status']);
});

it('security evaluate: salts undefined is fail, placeholder text is fail', function () {
    $undefined = wpultra_audits_security_evaluate(['salts_defined' => false, 'salts_placeholder' => false]);
    $placeholder = wpultra_audits_security_evaluate(['salts_defined' => true, 'salts_placeholder' => true]);
    $ok = wpultra_audits_security_evaluate(['salts_defined' => true, 'salts_placeholder' => false]);
    $find = function ($findings) { foreach ($findings as $f) { if ($f['id'] === 'salts') { return $f; } } return null; };
    assert_eq('fail', $find($undefined)['status']);
    assert_eq('fail', $find($placeholder)['status']);
    assert_eq('pass', $find($ok)['status']);
});

it('security evaluate: admin_count edge cases (zero and too many)', function () {
    $zero = wpultra_audits_security_evaluate(['admin_count' => 0]);
    $many = wpultra_audits_security_evaluate(['admin_count' => 5]);
    $one  = wpultra_audits_security_evaluate(['admin_count' => 1]);
    $find = function ($findings) { foreach ($findings as $f) { if ($f['id'] === 'admin_count') { return $f; } } return null; };
    assert_eq('warn', $find($zero)['status']);
    assert_eq('warn', $find($many)['status']);
    assert_eq('pass', $find($one)['status']);
});

it('security evaluate: pending updates and inactive plugins aggregate correctly', function () {
    $findings = wpultra_audits_security_evaluate(['plugin_updates_pending' => 2, 'theme_updates_pending' => 1, 'inactive_plugins_count' => 3]);
    $byId = [];
    foreach ($findings as $f) { $byId[$f['id']] = $f; }
    assert_eq('warn', $byId['updates_pending']['status']);
    assert_contains('3 plugin/theme update', $byId['updates_pending']['detail']);
    assert_eq('warn', $byId['inactive_plugins']['status']);
    assert_contains('3 inactive plugin', $byId['inactive_plugins']['detail']);
});

it('security evaluate: missing context keys fall back to safe defaults without error', function () {
    $findings = wpultra_audits_security_evaluate([]);
    assert_true(count($findings) > 0);
    foreach ($findings as $f) {
        assert_true(in_array($f['status'], ['pass', 'warn', 'fail'], true), 'status is one of pass|warn|fail for ' . $f['id']);
    }
});

// ---------------------------------------------------------------------------
// Performance audit — pure rule evaluator + scorer
// ---------------------------------------------------------------------------

it('performance evaluate: healthy context yields all pass and score 100', function () {
    $ctx = [
        'autoload_total_bytes'     => 100_000,
        'autoload_top10'           => [],
        'transient_count'          => 5,
        'transient_expired'        => 2,
        'posts_count'              => 500,
        'postmeta_count'           => 2000,
        'revisions_count'          => 100,
        'attachment_count'         => 200,
        'attachment_files_missing' => 0,
        'active_plugin_count'      => 15,
        'object_cache_present'     => true,
        'page_cache_detected'      => true,
        'cron_overdue_count'       => 0,
    ];
    $findings = wpultra_audits_performance_evaluate($ctx);
    foreach ($findings as $f) { assert_eq('pass', $f['status'], $f['id']); }
    assert_eq(100, wpultra_audits_performance_score($findings));
});

it('performance evaluate: large autoload size escalates warn then fail', function () {
    $warn = wpultra_audits_performance_evaluate(['autoload_total_bytes' => 600_000]);
    $fail = wpultra_audits_performance_evaluate(['autoload_total_bytes' => 1_500_000]);
    $find = function ($findings) { foreach ($findings as $f) { if ($f['id'] === 'autoload_size') { return $f; } } return null; };
    assert_eq('warn', $find($warn)['status']);
    assert_eq('fail', $find($fail)['status']);
});

it('performance evaluate: autoload_top10 emits an informational pass finding with names', function () {
    $findings = wpultra_audits_performance_evaluate(['autoload_top10' => [['name' => 'big_option', 'bytes' => 5000], ['name' => 'other_option', 'bytes' => 1000]]]);
    $find = function ($findings) { foreach ($findings as $f) { if ($f['id'] === 'autoload_top10') { return $f; } } return null; };
    $top = $find($findings);
    assert_true($top !== null, 'autoload_top10 finding present');
    assert_eq('pass', $top['status']);
    assert_contains('big_option', $top['detail']);
    assert_contains('other_option', $top['detail']);
});

it('performance evaluate: no autoload_top10 entries omits the informational finding', function () {
    $findings = wpultra_audits_performance_evaluate(['autoload_top10' => []]);
    $ids = array_column($findings, 'id');
    assert_true(!in_array('autoload_top10', $ids, true));
});

it('performance evaluate: revisions, missing attachments, cron overdue, no caches all warn', function () {
    $ctx = [
        'revisions_count'          => 5000,
        'attachment_files_missing' => 3,
        'cron_overdue_count'       => 2,
        'object_cache_present'     => false,
        'page_cache_detected'      => false,
        'active_plugin_count'      => 60,
        'transient_expired'        => 100,
    ];
    $findings = wpultra_audits_performance_evaluate($ctx);
    $byId = [];
    foreach ($findings as $f) { $byId[$f['id']] = $f; }
    assert_eq('warn', $byId['revisions']['status']);
    assert_eq('warn', $byId['attachment_files']['status']);
    assert_eq('warn', $byId['cron_overdue']['status']);
    assert_eq('warn', $byId['object_cache']['status']);
    assert_eq('warn', $byId['page_cache']['status']);
    assert_eq('warn', $byId['active_plugins']['status']);
    assert_eq('warn', $byId['transients_expired']['status']);
});

it('performance score: deducts 5 per warn and 12 per fail, floors at 0', function () {
    $findings = [
        ['id' => 'a', 'status' => 'pass', 'detail' => ''],
        ['id' => 'b', 'status' => 'warn', 'detail' => ''],
        ['id' => 'c', 'status' => 'warn', 'detail' => ''],
        ['id' => 'd', 'status' => 'fail', 'detail' => ''],
    ];
    assert_eq(100 - 5 - 5 - 12, wpultra_audits_performance_score($findings));

    $manyFails = array_fill(0, 20, ['id' => 'x', 'status' => 'fail', 'detail' => '']);
    assert_eq(0, wpultra_audits_performance_score($manyFails));
});

it('performance score: all pass scores 100, never exceeds 100', function () {
    $findings = array_fill(0, 5, ['id' => 'x', 'status' => 'pass', 'detail' => '']);
    assert_eq(100, wpultra_audits_performance_score($findings));
});

// ---------------------------------------------------------------------------
// Cron overdue counter — pure helper used by the performance collector
// ---------------------------------------------------------------------------

it('count_overdue_cron counts only past-timestamp events, summed across hooks', function () {
    $now = 1_000_000;
    $cron = [
        ($now - 100) => ['hook_a' => ['key1' => ['schedule' => false, 'args' => []]], 'hook_b' => ['key2' => [], 'key3' => []]],
        ($now + 100) => ['hook_c' => ['key4' => []]],
        'version'    => 2, // non-numeric key present in real _get_cron_array() output — must be ignored
    ];
    assert_eq(3, wpultra_audits_count_overdue_cron($cron, $now));
});

it('count_overdue_cron returns 0 for empty or all-future schedules', function () {
    assert_eq(0, wpultra_audits_count_overdue_cron([], 1000));
    assert_eq(0, wpultra_audits_count_overdue_cron([2000 => ['h' => ['k' => []]]], 1000));
});

// ---------------------------------------------------------------------------
// Context default merging sanity (guards against key-name drift between
// collector/evaluator/ability without needing WordPress).
// ---------------------------------------------------------------------------

it('security context defaults cover every key the evaluator can branch on', function () {
    $defaults = wpultra_audits_security_context_defaults();
    foreach (['core_version', 'core_latest_version', 'core_update_available', 'disallow_file_edit', 'wp_debug', 'wp_debug_display', 'is_ssl', 'table_prefix', 'admin_usernames', 'admin_count', 'uploads_index_exists', 'xmlrpc_enabled', 'plugin_updates_pending', 'theme_updates_pending', 'inactive_plugins_count', 'salts_defined', 'salts_placeholder'] as $key) {
        assert_true(array_key_exists($key, $defaults), "missing default for $key");
    }
});

it('performance context defaults cover every key the evaluator can branch on', function () {
    $defaults = wpultra_audits_performance_context_defaults();
    foreach (['autoload_total_bytes', 'autoload_top10', 'transient_count', 'transient_expired', 'posts_count', 'postmeta_count', 'revisions_count', 'attachment_count', 'attachment_files_missing', 'active_plugin_count', 'object_cache_present', 'page_cache_detected', 'cron_overdue_count'] as $key) {
        assert_true(array_key_exists($key, $defaults), "missing default for $key");
    }
});

// ---------------------------------------------------------------------------
// Autoload value vocabulary — WP 6.6+ replaced the sole 'yes' with several values;
// the collector's autoload-size query must match all of them, not just 'yes'.
// ---------------------------------------------------------------------------

it('autoload_yes_values includes the modern WP 6.6+ vocabulary, not just "yes"', function () {
    $vals = wpultra_audits_autoload_yes_values();
    foreach (['yes', 'on', 'auto', 'auto-on'] as $v) {
        assert_true(in_array($v, $vals, true), "autoload value set missing '$v'");
    }
    // No accidental inclusion of the "not-autoloaded" values WP uses for the negative side.
    assert_true(!in_array('no', $vals, true));
    assert_true(!in_array('off', $vals, true));
});

run_tests();
