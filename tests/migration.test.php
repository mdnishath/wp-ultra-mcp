<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';

if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/wpultra_migration/'); }
// helpers.php provides wpultra_err / wpultra_ok (uses WP_Error stub from harness).
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/system/migration.php';

/* ============================================================
 * build_manifest — shape from a site array.
 * ============================================================ */

it('build_manifest produces the stable shape from a site array', function () {
    $m = wpultra_migrate_build_manifest([
        'home_url'    => 'https://old.example.com/',
        'site_url'    => 'https://old.example.com/wp/',
        'abspath'     => '/var/www/old/',
        'wp_version'  => '6.5.2',
        'php_version' => '8.2.30',
        'prefix'      => 'wp_',
        'multisite'   => false,
        'plugins'     => ['woocommerce/woocommerce.php', 'akismet/akismet.php'],
        'theme'       => 'twentytwentyfour',
        'package'     => 'migrate-1',
        'created'     => '2026-07-04T00:00:00+00:00',
    ]);
    assert_eq('wpultra-migrate/1', $m['schema']);
    assert_eq('2026-07-04T00:00:00+00:00', $m['created']);
    // Trailing slashes stripped on URLs.
    assert_eq('https://old.example.com', $m['home_url']);
    assert_eq('https://old.example.com/wp', $m['site_url']);
    assert_eq('wp_', $m['prefix']);
    assert_eq('twentytwentyfour', $m['theme']);
    assert_eq('migrate-1', $m['package']);
    assert_eq(false, $m['multisite']);
    // Plugins sorted + deduped.
    assert_eq(['akismet/akismet.php', 'woocommerce/woocommerce.php'], $m['plugins']);
});

it('build_manifest defaults + dedupes plugins and coerces multisite', function () {
    $m = wpultra_migrate_build_manifest([
        'home_url' => 'http://x.test',
        'plugins'  => ['a/a.php', 'a/a.php', '', 'b/b.php'],
        'multisite' => 1, // truthy but not === true
    ]);
    assert_eq(['a/a.php', 'b/b.php'], $m['plugins']);
    assert_eq(false, $m['multisite'], 'only strict true is multisite');
    assert_true(is_string($m['created']) && $m['created'] !== '', 'created defaulted to now');
    assert_eq('', $m['prefix']);
    assert_eq('', $m['package']);
});

/* ============================================================
 * url_pairs — http/https/// variants, self-pair, slashes, subdir.
 * ============================================================ */

it('url_pairs yields https/http/protocol-relative variants for a simple move', function () {
    $pairs = wpultra_migrate_url_pairs('https://old.com', 'https://new.com', 'https://old.com', 'https://new.com');
    // home == site, so deduped to the three variants of one host move.
    assert_eq([
        ['https://old.com', 'https://new.com'],
        ['http://old.com',  'http://new.com'],
        ['//old.com',       '//new.com'],
    ], $pairs);
});

it('url_pairs returns [] when old == new for both home and site', function () {
    $pairs = wpultra_migrate_url_pairs('https://same.com', 'https://same.com', 'https://same.com', 'https://same.com');
    assert_eq([], $pairs);
});

it('url_pairs is trailing-slash-safe (slashes stripped, no dangling //)', function () {
    $pairs = wpultra_migrate_url_pairs('https://old.com/', 'https://new.com/', 'https://old.com/', 'https://new.com/');
    assert_eq([
        ['https://old.com', 'https://new.com'],
        ['http://old.com',  'http://new.com'],
        ['//old.com',       '//new.com'],
    ], $pairs);
});

it('url_pairs derives all three variants regardless of the input scheme', function () {
    // Input given as http:// still yields https + http + // variants.
    $pairs = wpultra_migrate_url_pairs('http://old.com', 'https://new.com', 'http://old.com', 'https://new.com');
    assert_eq([
        ['https://old.com', 'https://new.com'],
        ['http://old.com',  'http://new.com'],
        ['//old.com',       '//new.com'],
    ], $pairs);
});

it('url_pairs handles subdir installs (siteurl differs from home)', function () {
    $pairs = wpultra_migrate_url_pairs(
        'https://old.com',      'https://new.com',
        'https://old.com/wp',   'https://new.com/wp'
    );
    // home host move (3) + site subdir move (3) = 6 distinct pairs, home first.
    assert_eq([
        ['https://old.com',    'https://new.com'],
        ['http://old.com',     'http://new.com'],
        ['//old.com',          '//new.com'],
        ['https://old.com/wp', 'https://new.com/wp'],
        ['http://old.com/wp',  'http://new.com/wp'],
        ['//old.com/wp',       '//new.com/wp'],
    ], $pairs);
});

it('url_pairs skips a URL whose old == new but keeps the other', function () {
    // home unchanged, only siteurl (a subdir) moves.
    $pairs = wpultra_migrate_url_pairs(
        'https://same.com', 'https://same.com',
        'https://same.com/blog', 'https://same.com/news'
    );
    assert_eq([
        ['https://same.com/blog', 'https://same.com/news'],
        ['http://same.com/blog',  'http://same.com/news'],
        ['//same.com/blog',       '//same.com/news'],
    ], $pairs);
});

/* ============================================================
 * version_parts / version_cmp_minor — building blocks.
 * ============================================================ */

it('version_parts extracts major.minor.patch and truncates junk', function () {
    assert_eq([8, 2, 30], wpultra_migrate_version_parts('8.2.30'));
    assert_eq([8, 2, 30], wpultra_migrate_version_parts('8.2.30+1'));
    assert_eq([6, 5, 0],  wpultra_migrate_version_parts('6.5'));
    assert_eq([7, 0, 0],  wpultra_migrate_version_parts('7'));
    assert_eq([0, 0, 0],  wpultra_migrate_version_parts('beta'));
});

it('version_cmp_minor ignores the patch level', function () {
    assert_eq(0,  wpultra_migrate_version_cmp_minor([8, 2, 5], [8, 2, 99]));
    assert_eq(-1, wpultra_migrate_version_cmp_minor([8, 1, 0], [8, 2, 0]));
    assert_eq(1,  wpultra_migrate_version_cmp_minor([8, 3, 0], [8, 2, 9]));
    assert_eq(-1, wpultra_migrate_version_cmp_minor([7, 9, 0], [8, 0, 0]));
});

/* ============================================================
 * compat — readiness findings.
 * ============================================================ */

/** helper: index findings by check name for easy assertions. */
function _mig_by_check(array $findings): array {
    $out = [];
    foreach ($findings as $f) { $out[$f['check']] = $f; }
    return $out;
}

it('compat flags a PHP downgrade as a blocker', function () {
    $src = wpultra_migrate_build_manifest(['php_version' => '8.2.0', 'wp_version' => '6.5', 'prefix' => 'wp_']);
    $f = _mig_by_check(wpultra_migrate_compat($src, ['php_version' => '8.0.30', 'wp_version' => '6.5', 'prefix' => 'wp_', 'plugins' => []]));
    assert_eq('blocker', $f['php']['status']);
    assert_true(wpultra_migrate_has_blocker(array_values($f)), 'has_blocker true');
});

it('compat treats a PHP upgrade (or same) as ok', function () {
    $src = wpultra_migrate_build_manifest(['php_version' => '8.0.0', 'wp_version' => '6.5', 'prefix' => 'wp_']);
    $f = _mig_by_check(wpultra_migrate_compat($src, ['php_version' => '8.2.10', 'wp_version' => '6.5', 'prefix' => 'wp_', 'plugins' => []]));
    assert_eq('ok', $f['php']['status']);

    // Same major.minor, higher patch → still ok.
    $f2 = _mig_by_check(wpultra_migrate_compat(
        wpultra_migrate_build_manifest(['php_version' => '8.2.30', 'wp_version' => '6.5', 'prefix' => 'wp_']),
        ['php_version' => '8.2.5', 'wp_version' => '6.5', 'prefix' => 'wp_', 'plugins' => []]
    ));
    assert_eq('ok', $f2['php']['status'], 'patch-only downgrade is not a blocker');
});

it('compat warns on a WordPress major mismatch', function () {
    $src = wpultra_migrate_build_manifest(['php_version' => '8.2.0', 'wp_version' => '5.9', 'prefix' => 'wp_']);
    $f = _mig_by_check(wpultra_migrate_compat($src, ['php_version' => '8.2.0', 'wp_version' => '6.5', 'prefix' => 'wp_', 'plugins' => []]));
    assert_eq('warn', $f['wp']['status']);
});

it('compat warns when the table prefix differs', function () {
    $src = wpultra_migrate_build_manifest(['php_version' => '8.2.0', 'wp_version' => '6.5', 'prefix' => 'wp_']);
    $f = _mig_by_check(wpultra_migrate_compat($src, ['php_version' => '8.2.0', 'wp_version' => '6.5', 'prefix' => 'xy_', 'plugins' => []]));
    assert_eq('warn', $f['prefix']['status']);
    assert_contains('xy_', $f['prefix']['detail']);
});

it('compat warns about plugins missing on the destination', function () {
    $src = wpultra_migrate_build_manifest([
        'php_version' => '8.2.0', 'wp_version' => '6.5', 'prefix' => 'wp_',
        'plugins' => ['woocommerce/woocommerce.php', 'akismet/akismet.php'],
    ]);
    $f = _mig_by_check(wpultra_migrate_compat($src, [
        'php_version' => '8.2.0', 'wp_version' => '6.5', 'prefix' => 'wp_',
        'plugins' => ['akismet/akismet.php'],
    ]));
    assert_eq('warn', $f['plugins']['status']);
    assert_contains('woocommerce/woocommerce.php', $f['plugins']['detail']);
});

it('compat reports all-ok for an identical environment', function () {
    $src = wpultra_migrate_build_manifest([
        'php_version' => '8.2.0', 'wp_version' => '6.5', 'prefix' => 'wp_',
        'plugins' => ['akismet/akismet.php'],
    ]);
    $findings = wpultra_migrate_compat($src, [
        'php_version' => '8.2.0', 'wp_version' => '6.5', 'prefix' => 'wp_',
        'plugins' => ['akismet/akismet.php'],
    ]);
    foreach ($findings as $f) { assert_eq('ok', $f['status'], 'all ' . $f['check'] . ' ok'); }
    assert_true(!wpultra_migrate_has_blocker($findings), 'no blocker for identical env');
});

it('compat warns (not blocks) when a version is unknown', function () {
    $src = wpultra_migrate_build_manifest(['php_version' => '', 'wp_version' => '', 'prefix' => 'wp_']);
    $f = _mig_by_check(wpultra_migrate_compat($src, ['php_version' => '', 'wp_version' => '', 'prefix' => 'wp_', 'plugins' => []]));
    assert_eq('warn', $f['php']['status']);
    assert_eq('warn', $f['wp']['status']);
});

/* ============================================================
 * name_sanitize — package name rules.
 * ============================================================ */

it('name_sanitize lowercases and accepts [a-z0-9-]', function () {
    assert_eq('my-site-1', wpultra_migrate_name_sanitize('My-Site-1'));
});

it('name_sanitize rejects empty, illegal chars, and all-hyphens', function () {
    assert_wp_error(wpultra_migrate_name_sanitize(''));
    assert_wp_error(wpultra_migrate_name_sanitize('bad name'));
    assert_wp_error(wpultra_migrate_name_sanitize('a/b'));
    assert_wp_error(wpultra_migrate_name_sanitize('---'));
});

/* ============================================================
 * sr_tables — the rewrite sweep set.
 * ============================================================ */

it('sr_tables includes the URL-bearing tables', function () {
    $t = wpultra_migrate_sr_tables();
    foreach (['posts', 'postmeta', 'options', 'comments'] as $needed) {
        assert_true(in_array($needed, $t, true), "$needed in sweep set");
    }
});

run_tests();
