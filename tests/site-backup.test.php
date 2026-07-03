<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';

// --- Environment / stubs ---
if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/wpultra_backup/'); }
if (!function_exists('wp_register_ability')) { function wp_register_ability($n, $a) { $GLOBALS['__ab'][$n] = $a; } }

// The engine calls wpultra_err()/wpultra_ok() from helpers.php; load helpers first.
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/system/backup.php';

/* ============================================================
 * wpultra_backup_name_sanitize
 * ============================================================ */

it('name_sanitize accepts lowercase alnum + hyphens', function () {
    assert_eq('my-backup-1', wpultra_backup_name_sanitize('my-backup-1'));
    assert_eq('backup2024', wpultra_backup_name_sanitize('backup2024'));
});

it('name_sanitize lowercases and trims surrounding whitespace', function () {
    assert_eq('nightly', wpultra_backup_name_sanitize('  Nightly  '));
    assert_eq('pre-deploy', wpultra_backup_name_sanitize('PRE-DEPLOY'));
});

it('name_sanitize rejects illegal characters with WP_Error', function () {
    assert_wp_error(wpultra_backup_name_sanitize('my backup'));      // space
    assert_wp_error(wpultra_backup_name_sanitize('name/with/slash')); // slash
    assert_wp_error(wpultra_backup_name_sanitize('dot.name'));        // dot
    assert_wp_error(wpultra_backup_name_sanitize('under_score'));     // underscore not allowed
    assert_wp_error(wpultra_backup_name_sanitize('émoji'));           // non-ascii
});

it('name_sanitize rejects empty / all-hyphen names', function () {
    assert_wp_error(wpultra_backup_name_sanitize(''));
    assert_wp_error(wpultra_backup_name_sanitize('   '));
    assert_wp_error(wpultra_backup_name_sanitize('---'));
});

/* ============================================================
 * wpultra_backup_should_exclude  (exclusion matrix)
 * ============================================================ */

function _bx_excludes(): array { return wpultra_backup_default_excludes(); }

it('should_exclude excludes the backup dir itself (no recursion into own artifacts)', function () {
    $ex = _bx_excludes();
    assert_true(wpultra_backup_should_exclude('uploads/wpultra-backups', $ex, false));
    assert_true(wpultra_backup_should_exclude('uploads/wpultra-backups/nightly/files.zip', $ex, false));
});

it('should_exclude excludes snapshot and export artifact dirs', function () {
    $ex = _bx_excludes();
    assert_true(wpultra_backup_should_exclude('uploads/wpultra-snapshots/x.sql.gz', $ex, false));
    assert_true(wpultra_backup_should_exclude('uploads/wpultra-exports/export-1.xml', $ex, false));
});

it('should_exclude excludes cache dirs (top-level and uploads/cache)', function () {
    $ex = _bx_excludes();
    assert_true(wpultra_backup_should_exclude('cache/min/1.js', $ex, false));
    assert_true(wpultra_backup_should_exclude('uploads/cache/thumb.jpg', $ex, false));
});

it('should_exclude excludes node_modules anywhere in the tree (bare-name match)', function () {
    $ex = _bx_excludes();
    assert_true(wpultra_backup_should_exclude('node_modules/pkg/index.js', $ex, false));
    // Nested inside a plugin — must still be caught by the bare-name rule.
    assert_true(wpultra_backup_should_exclude('plugins/mytheme/node_modules/x/y.js', $ex, false));
});

it('should_exclude does NOT exclude ordinary plugin / theme / upload files by default', function () {
    $ex = _bx_excludes();
    assert_eq(false, wpultra_backup_should_exclude('plugins/akismet/akismet.php', $ex, false));
    assert_eq(false, wpultra_backup_should_exclude('themes/twentytwentyfour/style.css', $ex, false));
    assert_eq(false, wpultra_backup_should_exclude('uploads/2024/01/photo.jpg', $ex, false));
});

it('should_exclude honours skip_uploads: the whole uploads tree is excluded', function () {
    $ex = _bx_excludes();
    // With skip_uploads OFF, a normal upload is kept.
    assert_eq(false, wpultra_backup_should_exclude('uploads/2024/01/photo.jpg', $ex, false));
    // With skip_uploads ON, the same file (and the uploads dir) is excluded.
    assert_true(wpultra_backup_should_exclude('uploads/2024/01/photo.jpg', $ex, true));
    assert_true(wpultra_backup_should_exclude('uploads', $ex, true));
    // ...but plugins outside uploads are still kept.
    assert_eq(false, wpultra_backup_should_exclude('plugins/akismet/akismet.php', $ex, true));
});

it('should_exclude normalizes backslashes and leading ./ or / (nested path robustness)', function () {
    $ex = _bx_excludes();
    assert_true(wpultra_backup_should_exclude('uploads\\wpultra-backups\\a\\b.zip', $ex, false));
    assert_true(wpultra_backup_should_exclude('./node_modules/x.js', $ex, false));
    assert_true(wpultra_backup_should_exclude('/cache/y.css', $ex, false));
    assert_eq(false, wpultra_backup_should_exclude('', $ex, false));
});

it('should_exclude does not over-match on a prefix that is not a path boundary', function () {
    $ex = _bx_excludes();
    // "cache-plugin" starts with "cache" but is a distinct dir — must NOT be excluded.
    assert_eq(false, wpultra_backup_should_exclude('plugins/cache-plugin/main.php', $ex, false));
    // "uploads-backup" is not the uploads dir.
    assert_eq(false, wpultra_backup_should_exclude('uploads-backup/file.txt', $ex, false));
});

/* ============================================================
 * wpultra_backup_shape
 * ============================================================ */

it('shape computes total_bytes from db+files when total is absent', function () {
    $row = wpultra_backup_shape([
        'name'        => 'nightly',
        'path'        => '/x/nightly',
        'db_bytes'    => 100,
        'files_bytes' => 250,
        'modified'    => 1700000000,
    ]);
    assert_eq('nightly', $row['name']);
    assert_eq(100, $row['db_bytes']);
    assert_eq(250, $row['files_bytes']);
    assert_eq(350, $row['total_bytes']);
    assert_eq(gmdate('c', 1700000000), $row['modified']);
});

it('shape honours an explicit total_bytes override', function () {
    $row = wpultra_backup_shape(['db_bytes' => 10, 'files_bytes' => 20, 'total_bytes' => 999]);
    assert_eq(999, $row['total_bytes']);
});

it('shape fills sane defaults for missing fields', function () {
    $row = wpultra_backup_shape([]);
    assert_eq('', $row['name']);
    assert_eq('', $row['path']);
    assert_eq(0, $row['db_bytes']);
    assert_eq(0, $row['files_bytes']);
    assert_eq(0, $row['total_bytes']);
    assert_eq(null, $row['modified']); // 0 epoch => null
});

run_tests();
