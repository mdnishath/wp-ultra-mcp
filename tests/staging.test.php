<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';

// --- Environment / stubs (mirror siteops.test.php style) ---
if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/wpultra_staging/'); }
if (!function_exists('wp_register_ability')) { function wp_register_ability($n, $a) { $GLOBALS['__ab'][$n] = $a; } }
if (!function_exists('get_option')) { function get_option($k, $d = false) { return $d; } }
if (!function_exists('update_option')) { function update_option($k, $v, $a = null) { return true; } }
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return 0; } }
if (!function_exists('current_time')) { function current_time($t, $g = 0) { return gmdate('Y-m-d H:i:s'); } }

// Requiring staging.php under the harness must never fatal. It depends on helpers.php
// (wpultra_err / wpultra_ok) but its pure helpers don't touch WP or $wpdb.
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/system/staging.php';

/* ============================================================
 * wpultra_staging_prefix  (deterministic short prefix)
 * ============================================================ */

it('staging_prefix is deterministic and has the stg<hex>_ shape', function () {
    $a = wpultra_staging_prefix('demo');
    $b = wpultra_staging_prefix('demo');
    assert_eq($a, $b, 'deterministic for the same name');
    assert_true((bool) preg_match('/^stg[0-9a-f]{4}_$/', $a), "shape stg<4hex>_, got $a");
});

it('staging_prefix differs for different names', function () {
    assert_true(wpultra_staging_prefix('alpha') !== wpultra_staging_prefix('beta'), 'distinct names → distinct prefixes');
});

it('staging_prefix matches the md5-derived value exactly', function () {
    $expected = 'stg' . substr(md5('my-site'), 0, 4) . '_';
    assert_eq($expected, wpultra_staging_prefix('my-site'));
});

/* ============================================================
 * wpultra_staging_validate_name
 * ============================================================ */

it('validate_name accepts a valid slug', function () {
    assert_eq('', wpultra_staging_validate_name('my-staging-1'));
    assert_eq('', wpultra_staging_validate_name('abc'));
    assert_eq('', wpultra_staging_validate_name('a1b2'));
});

it('validate_name rejects empty, uppercase, spaces, symbols', function () {
    assert_true(wpultra_staging_validate_name('') !== '', 'empty rejected');
    assert_true(wpultra_staging_validate_name('MyStage') !== '', 'uppercase rejected');
    assert_true(wpultra_staging_validate_name('has space') !== '', 'space rejected');
    assert_true(wpultra_staging_validate_name('under_score') !== '', 'underscore rejected');
    assert_true(wpultra_staging_validate_name('dot.name') !== '', 'dot rejected');
});

it('validate_name rejects leading/trailing hyphen and over-long names', function () {
    assert_true(wpultra_staging_validate_name('-lead') !== '', 'leading hyphen rejected');
    assert_true(wpultra_staging_validate_name('trail-') !== '', 'trailing hyphen rejected');
    assert_true(wpultra_staging_validate_name(str_repeat('a', 41)) !== '', 'too long rejected');
    assert_eq('', wpultra_staging_validate_name(str_repeat('a', 40)), '40 chars ok');
});

/* ============================================================
 * wpultra_staging_rewrite_config  ($table_prefix rewrite)
 * ============================================================ */

it('rewrite_config replaces a typical single-quoted $table_prefix line', function () {
    $cfg = "<?php\n\$table_prefix = 'wp_';\n\ndefine('DB_NAME', 'x');\n";
    $out = wpultra_staging_rewrite_config($cfg, 'stg1a2b_');
    assert_contains("\$table_prefix = 'stg1a2b_';", $out);
    // The rest of the file is preserved untouched.
    assert_contains("define('DB_NAME', 'x');", $out);
});

it('rewrite_config preserves double-quote style', function () {
    $cfg = "<?php\n\$table_prefix = \"wp_\";\n";
    $out = wpultra_staging_rewrite_config($cfg, 'stgcd34_');
    assert_contains("\$table_prefix = \"stgcd34_\";", $out);
});

it('rewrite_config tolerates odd spacing and trailing whitespace', function () {
    $cfg = "<?php\n\$table_prefix   =    'wp_'   ;\n";
    $out = wpultra_staging_rewrite_config($cfg, 'stgabcd_');
    // Spacing before ; is preserved; only the quoted value changes.
    assert_contains("'stgabcd_'", $out);
    assert_true(strpos($out, "'wp_'") === false, 'old prefix value gone');
});

it('rewrite_config only rewrites the first assignment', function () {
    $cfg = "<?php\n\$table_prefix = 'wp_';\n// commentary\n\$table_prefix = 'other_';\n";
    $out = wpultra_staging_rewrite_config($cfg, 'stgnew0_');
    // First occurrence rewritten; the second (unusual) line left as-is.
    assert_contains("\$table_prefix = 'stgnew0_';", $out);
    assert_contains("\$table_prefix = 'other_';", $out);
});

it('rewrite_config returns an error string when no $table_prefix line exists', function () {
    $out = wpultra_staging_rewrite_config("<?php\ndefine('DB_NAME', 'x');\n", 'stg0000_');
    assert_true(is_string($out), 'returns a string');
    assert_true(str_starts_with($out, 'error:'), "error-prefixed, got: $out");
});

/* ============================================================
 * wpultra_staging_exclude  (file-copy exclusion)
 * ============================================================ */

it('exclude skips any top-level staging-* directory', function () {
    assert_true(wpultra_staging_exclude('staging-demo/wp-config.php', 'staging-*'));
    assert_true(wpultra_staging_exclude('staging-other/index.php', 'staging-*'));
    // Exact-glob form (targeting one staging) also matches its own tree.
    assert_true(wpultra_staging_exclude('staging-foo/x', 'staging-foo'));
});

it('exclude skips backup/snapshot/cache/node_modules/vcs at any depth', function () {
    assert_true(wpultra_staging_exclude('wp-content/uploads/wpultra-backups/b.zip', 'staging-*'));
    assert_true(wpultra_staging_exclude('wp-content/uploads/wpultra-snapshots/s.sql.gz', 'staging-*'));
    assert_true(wpultra_staging_exclude('wp-content/cache/min/x.css', 'staging-*'));
    assert_true(wpultra_staging_exclude('wp-content/themes/x/node_modules/pkg/index.js', 'staging-*'));
    assert_true(wpultra_staging_exclude('.git/config', 'staging-*'));
});

it('exclude keeps ordinary site files', function () {
    assert_true(!wpultra_staging_exclude('wp-config.php', 'staging-*'));
    assert_true(!wpultra_staging_exclude('wp-content/themes/twentytwentyfour/style.css', 'staging-*'));
    assert_true(!wpultra_staging_exclude('wp-content/plugins/wp-ultra-mcp/plugin.php', 'staging-*'));
    assert_true(!wpultra_staging_exclude('', 'staging-*'));
});

/* ============================================================
 * wpultra_staging_home_url
 * ============================================================ */

it('home_url appends /staging-<name> and tolerates a trailing slash', function () {
    assert_eq('https://example.com/staging-demo', wpultra_staging_home_url('https://example.com', 'demo'));
    assert_eq('https://example.com/staging-demo', wpultra_staging_home_url('https://example.com/', 'demo'));
    assert_eq('http://localhost/site/staging-x', wpultra_staging_home_url('http://localhost/site', 'x'));
});

run_tests();
