<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';

// --- Environment / stubs ---
if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/wpultra_rules/'); }
if (!function_exists('wp_register_ability')) { function wp_register_ability($n, $a) { $GLOBALS['__ab'][$n] = $a; } }
if (!function_exists('get_option')) { function get_option($k, $d = false) { return $d; } }
if (!function_exists('update_option')) { function update_option($k, $v, $a = null) { return true; } }
if (!function_exists('current_time')) { function current_time($t, $g = 0) { return gmdate('Y-m-d H:i:s'); } }
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return 0; } }

require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/system/rules.php';

/* ============================================================
 * Preset builders — each non-empty + contains its key directive.
 * ============================================================ */

it('preset_security_headers is non-empty and sets X-Frame-Options', function () {
    $lines = wpultra_rules_preset_security_headers();
    assert_true(count($lines) > 0, 'expected non-empty lines');
    $joined = implode("\n", $lines);
    assert_contains('X-Frame-Options', $joined);
    assert_contains('mod_headers.c', $joined);
});

it('preset_security_headers sets Referrer-Policy and Permissions-Policy', function () {
    $joined = implode("\n", wpultra_rules_preset_security_headers());
    assert_contains('Referrer-Policy', $joined);
    assert_contains('Permissions-Policy', $joined);
    assert_contains('X-Content-Type-Options', $joined);
});

it('preset_browser_caching is non-empty and uses mod_expires', function () {
    $lines = wpultra_rules_preset_browser_caching();
    assert_true(count($lines) > 0, 'expected non-empty lines');
    $joined = implode("\n", $lines);
    assert_contains('mod_expires.c', $joined);
    assert_contains('ExpiresByType', $joined);
});

it('preset_gzip is non-empty and uses mod_deflate', function () {
    $lines = wpultra_rules_preset_gzip();
    assert_true(count($lines) > 0, 'expected non-empty lines');
    $joined = implode("\n", $lines);
    assert_contains('mod_deflate.c', $joined);
    assert_contains('AddOutputFilterByType DEFLATE', $joined);
});

it('preset_block_xmlrpc is non-empty and targets xmlrpc.php', function () {
    $lines = wpultra_rules_preset_block_xmlrpc();
    assert_true(count($lines) > 0, 'expected non-empty lines');
    $joined = implode("\n", $lines);
    assert_contains('xmlrpc.php', $joined);
    assert_contains('Deny from all', $joined);
});

it('preset_disable_indexes is non-empty and disables indexes', function () {
    $lines = wpultra_rules_preset_disable_indexes();
    assert_true(count($lines) > 0, 'expected non-empty lines');
    assert_contains('Options -Indexes', implode("\n", $lines));
});

/* ============================================================
 * compose() — dedupe + per-preset comments.
 * ============================================================ */

it('compose includes a comment header per preset', function () {
    $lines = wpultra_rules_compose(['security-headers'], []);
    assert_contains('# security-headers', implode("\n", $lines));
});

it('compose merges multiple presets in order', function () {
    $lines = wpultra_rules_compose(['security-headers', 'gzip'], []);
    $joined = implode("\n", $lines);
    $posHeaders = strpos($joined, '# security-headers');
    $posGzip = strpos($joined, '# gzip');
    assert_true($posHeaders !== false && $posGzip !== false, 'both preset comments present');
    assert_true($posHeaders < $posGzip, 'security-headers comes before gzip');
});

it('compose dedupes an identical line appearing in two presets', function () {
    // disable-indexes + a custom line that duplicates its only directive.
    $lines = wpultra_rules_compose(['disable-indexes'], ['Options -Indexes']);
    $count = count(array_filter($lines, static fn($l) => $l === 'Options -Indexes'));
    assert_eq(1, $count, 'Options -Indexes should appear exactly once');
});

it('compose appends a "# custom" comment only when custom_lines is non-empty', function () {
    $withCustom = wpultra_rules_compose([], ['Options -Indexes']);
    assert_contains('# custom', implode("\n", $withCustom));

    $withoutCustom = wpultra_rules_compose(['disable-indexes'], []);
    assert_true(!in_array('# custom', $withoutCustom, true), 'no custom comment when custom_lines is empty');
});

it('compose skips unknown preset names silently', function () {
    $lines = wpultra_rules_compose(['not-a-real-preset'], []);
    assert_eq([], $lines);
});

it('compose returns empty array when given no presets and no custom lines', function () {
    assert_eq([], wpultra_rules_compose([], []));
});

it('compose keeps <IfModule> blocks balanced across multiple presets (no structural dedupe)', function () {
    // Regression: line-level dedupe used to swallow the 2nd/3rd preset's
    // closing </IfModule> (identical to the 1st's), breaking Apache.
    $lines = wpultra_rules_compose(['security-headers', 'browser-caching', 'gzip'], []);
    $open  = count(array_filter($lines, static fn($l) => stripos(trim($l), '<IfModule') === 0));
    $close = count(array_filter($lines, static fn($l) => strcasecmp(trim($l), '</IfModule>') === 0));
    assert_true($open >= 3, 'each preset opens its own IfModule');
    assert_eq($open, $close, 'every <IfModule> has a matching </IfModule>');
});

it('compose de-duplicates repeated preset names', function () {
    $once  = wpultra_rules_compose(['gzip'], []);
    $twice = wpultra_rules_compose(['gzip', 'gzip'], []);
    assert_eq($once, $twice, 'requesting a preset twice emits it once');
});

/* ============================================================
 * validate_lines() — matrix.
 * ============================================================ */

it('validator rejects php_flag directives', function () {
    $res = wpultra_rules_validate_lines(['php_flag display_errors off']);
    assert_true(is_string($res), 'expected a rejection string');
    assert_contains('php_', (string) $res);
});

it('validator rejects php_value directives', function () {
    $res = wpultra_rules_validate_lines(['php_value upload_max_filesize 64M']);
    assert_true(is_string($res));
});

it('validator rejects php_admin_value / php_admin_flag directives', function () {
    assert_true(is_string(wpultra_rules_validate_lines(['php_admin_value open_basedir none'])));
    assert_true(is_string(wpultra_rules_validate_lines(['php_admin_flag engine on'])));
});

it('validator rejects a null byte in a line', function () {
    $res = wpultra_rules_validate_lines(["Options -Indexes\0evil"]);
    assert_true(is_string($res), 'expected a rejection string for null byte');
});

it('validator rejects other control characters', function () {
    $res = wpultra_rules_validate_lines(["Options -Indexes\x01"]);
    assert_true(is_string($res));
});

it('validator rejects AddHandler mapping to php execution', function () {
    $res = wpultra_rules_validate_lines(['AddHandler application/x-httpd-php .jpg']);
    assert_true(is_string($res));
});

it('validator rejects SetHandler mapping to a cgi script', function () {
    $res = wpultra_rules_validate_lines(['SetHandler cgi-script']);
    assert_true(is_string($res));
});

it('validator allows standard Header directives', function () {
    assert_eq(true, wpultra_rules_validate_lines(wpultra_rules_preset_security_headers()));
});

it('validator allows ExpiresByType (browser caching) directives', function () {
    assert_eq(true, wpultra_rules_validate_lines(wpultra_rules_preset_browser_caching()));
});

it('validator allows mod_deflate directives', function () {
    assert_eq(true, wpultra_rules_validate_lines(wpultra_rules_preset_gzip()));
});

it('validator allows Options -Indexes and Files/Deny blocks', function () {
    assert_eq(true, wpultra_rules_validate_lines(wpultra_rules_preset_disable_indexes()));
    assert_eq(true, wpultra_rules_validate_lines(wpultra_rules_preset_block_xmlrpc()));
});

it('validator allows a composed multi-preset block end to end', function () {
    $lines = wpultra_rules_compose(['security-headers', 'browser-caching', 'gzip', 'block-xmlrpc', 'disable-indexes'], ['# a harmless custom comment']);
    assert_eq(true, wpultra_rules_validate_lines($lines));
});

/* ============================================================
 * known_presets()
 * ============================================================ */

it('known_presets lists exactly the five documented presets', function () {
    $expected = ['security-headers', 'browser-caching', 'gzip', 'block-xmlrpc', 'disable-indexes'];
    assert_eq($expected, wpultra_rules_known_presets());
});

run_tests();
