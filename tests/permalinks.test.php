<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';

if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/wpultra_permalinks/'); }
// helpers.php provides wpultra_err / wpultra_ok (uses WP_Error stub from harness).
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/system/permalinks.php';

/* ------------------------------------------------------------------ *
 * wpultra_permalinks_validate_structure
 * ------------------------------------------------------------------ */

it('validate_structure accepts /%postname%/', function () {
    assert_true(wpultra_permalinks_validate_structure('/%postname%/') === true);
});

it('validate_structure accepts /%post_id%/ style', function () {
    assert_true(wpultra_permalinks_validate_structure('/%post_id%/') === true);
});

it('validate_structure accepts a structure with multiple tags', function () {
    assert_true(wpultra_permalinks_validate_structure('/%year%/%monthnum%/%day%/%postname%/') === true);
});

it('validate_structure accepts each known tag individually', function () {
    $tags = ['%year%', '%monthnum%', '%day%', '%hour%', '%minute%', '%second%', '%post_id%', '%postname%', '%category%', '%author%'];
    foreach ($tags as $tag) {
        assert_true(wpultra_permalinks_validate_structure("/$tag/") === true, "expected $tag to validate");
    }
});

it('validate_structure rejects missing leading slash', function () {
    assert_wp_error(wpultra_permalinks_validate_structure('%postname%/'));
});

it('validate_structure rejects a structure with no recognized tag', function () {
    assert_wp_error(wpultra_permalinks_validate_structure('/some-plain-path/'));
});

it('validate_structure rejects empty string', function () {
    assert_wp_error(wpultra_permalinks_validate_structure(''));
});

it('validate_structure error code is bad_structure', function () {
    $res = wpultra_permalinks_validate_structure('no-slash-%postname%');
    assert_eq('bad_structure', $res->get_error_code());
});

/* ------------------------------------------------------------------ *
 * wpultra_permalinks_htaccess_has_wp_block
 * ------------------------------------------------------------------ */

it('htaccess_has_wp_block detects the standard marker block', function () {
    $contents = "# BEGIN WordPress\n<IfModule mod_rewrite.c>\n</IfModule>\n# END WordPress\n";
    assert_true(wpultra_permalinks_htaccess_has_wp_block($contents));
});

it('htaccess_has_wp_block returns false when absent', function () {
    assert_true(!wpultra_permalinks_htaccess_has_wp_block("RewriteEngine On\nSomeOtherPlugin stuff\n"));
});

it('htaccess_has_wp_block returns false on empty contents', function () {
    assert_true(!wpultra_permalinks_htaccess_has_wp_block(''));
});

it('htaccess_has_wp_block is case-insensitive (lowercase marker)', function () {
    assert_true(wpultra_permalinks_htaccess_has_wp_block("# begin wordpress\nstuff\n# end wordpress\n"));
});

it('htaccess_has_wp_block is case-insensitive (mixed case, no space)', function () {
    assert_true(wpultra_permalinks_htaccess_has_wp_block("#BEGIN Wordpress\nstuff\n"));
});

it('htaccess_has_wp_block ignores the WPUltra-owned block (different marker)', function () {
    assert_true(!wpultra_permalinks_htaccess_has_wp_block("# BEGIN WPUltra\nstuff\n# END WPUltra\n"));
});

/* ------------------------------------------------------------------ *
 * wpultra_permalinks_extract_wp_block
 * ------------------------------------------------------------------ */

it('extract_wp_block returns the marker block including markers', function () {
    $contents = "RewriteEngine On\n# BEGIN WordPress\n<IfModule mod_rewrite.c>\nRewriteRule . /index.php [L]\n</IfModule>\n# END WordPress\n";
    $block = wpultra_permalinks_extract_wp_block($contents);
    assert_true(str_starts_with($block, '# BEGIN WordPress'));
    assert_true(str_ends_with($block, '# END WordPress'));
});

it('extract_wp_block returns empty string when block is absent', function () {
    assert_eq('', wpultra_permalinks_extract_wp_block("RewriteEngine On\nSomeOtherPlugin stuff\n"));
});

it('extract_wp_block returns empty string on empty contents', function () {
    assert_eq('', wpultra_permalinks_extract_wp_block(''));
});

it('extract_wp_block ignores the WPUltra-owned block (different marker)', function () {
    assert_eq('', wpultra_permalinks_extract_wp_block("# BEGIN WPUltra\nstuff\n# END WPUltra\n"));
});

it('extract_wp_block is stable across two identical inputs (used for before/after no_change comparison)', function () {
    $contents = "# BEGIN WordPress\n<IfModule mod_rewrite.c>\nRewriteRule . /index.php [L]\n</IfModule>\n# END WordPress\n";
    assert_eq(wpultra_permalinks_extract_wp_block($contents), wpultra_permalinks_extract_wp_block($contents));
});

it('extract_wp_block differs when the rule body changes (used to detect an actual rewrite)', function () {
    $before = "# BEGIN WordPress\nRewriteRule . /index.php [L]\n# END WordPress\n";
    $after  = "# BEGIN WordPress\nRewriteRule . /index.php?x=1 [L]\n# END WordPress\n";
    assert_true(wpultra_permalinks_extract_wp_block($before) !== wpultra_permalinks_extract_wp_block($after));
});

/* ------------------------------------------------------------------ *
 * wpultra_permalinks_status_shape
 * ------------------------------------------------------------------ */

it('status_shape reports pretty permalinks on with a structure set', function () {
    $s = wpultra_permalinks_status_shape(
        '/%postname%/', ['a' => 'b', 'c' => 'd'], true, false, true,
        '/var/www/html/.htaccess', true, true,
        "# BEGIN WordPress\nstuff\n# END WordPress\n",
        'https://example.com', 'https://example.com'
    );
    assert_eq('/%postname%/', $s['permalink_structure']);
    assert_true($s['pretty_permalinks']);
    assert_true($s['rewrite_rules_present']);
    assert_eq(2, $s['rewrite_rules_count']);
    assert_eq('apache', $s['server']);
    assert_true($s['mod_rewrite']);
    assert_eq('/var/www/html/.htaccess', $s['htaccess']['path']);
    assert_true($s['htaccess']['exists']);
    assert_true($s['htaccess']['writable']);
    assert_true($s['htaccess']['has_wp_block']);
    assert_true(!$s['home_siteurl_mismatch']);
});

it('status_shape reports plain permalinks off when structure is empty', function () {
    $s = wpultra_permalinks_status_shape('', null, false, true, false, '', false, false, '', 'https://example.com', 'https://example.com');
    assert_true(!$s['pretty_permalinks']);
    assert_true(!$s['rewrite_rules_present']);
    assert_eq(0, $s['rewrite_rules_count']);
    assert_eq('nginx', $s['server']);
    assert_true(!$s['mod_rewrite']);
    assert_true(!$s['htaccess']['has_wp_block']);
});

it('status_shape detects home/siteurl mismatch ignoring trailing slash', function () {
    $s = wpultra_permalinks_status_shape('/%postname%/', [], true, false, true, '/x/.htaccess', true, true, '', 'https://example.com/', 'https://example.com');
    assert_true(!$s['home_siteurl_mismatch']);
});

it('status_shape flags a real home/siteurl mismatch', function () {
    $s = wpultra_permalinks_status_shape('/%postname%/', [], true, false, true, '/x/.htaccess', true, true, '', 'https://www.example.com', 'https://example.com');
    assert_true($s['home_siteurl_mismatch']);
});

it('status_shape server is other when neither apache nor nginx', function () {
    $s = wpultra_permalinks_status_shape('', null, false, false, false, '', false, false, '', 'https://example.com', 'https://example.com');
    assert_eq('other', $s['server']);
});

run_tests();
