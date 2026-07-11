<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/system/security.php';
require __DIR__ . '/../wp-ultra-mcp/includes/system/debugmode.php';

/* ------------------------------------------------------------------ *
 * wpultra_debugmode_whitelist
 * ------------------------------------------------------------------ */

it('whitelist contains exactly the 5 known debug constants', function () {
    assert_eq(
        ['WP_DEBUG', 'WP_DEBUG_LOG', 'WP_DEBUG_DISPLAY', 'SCRIPT_DEBUG', 'SAVEQUERIES'],
        wpultra_debugmode_whitelist()
    );
});

/* ------------------------------------------------------------------ *
 * wpultra_debugmode_read_defines — parsing wp-config.php source
 * ------------------------------------------------------------------ */

function wpultra_test_wpconfig(string $body): string {
    return "<?php\n$body\n/* That's all, stop editing! Happy publishing. */\nrequire ABSPATH . 'wp-settings.php';\n";
}

it('read_defines parses bool true/false with normal spacing', function () {
    $src = wpultra_test_wpconfig("define('WP_DEBUG', true);\ndefine('WP_DEBUG_DISPLAY', false);");
    $d = wpultra_debugmode_read_defines($src);
    assert_true($d['WP_DEBUG']['defined']);
    assert_eq(true, $d['WP_DEBUG']['value']);
    assert_true($d['WP_DEBUG_DISPLAY']['defined']);
    assert_eq(false, $d['WP_DEBUG_DISPLAY']['value']);
});

it('read_defines parses with irregular spacing and double quotes', function () {
    $src = wpultra_test_wpconfig("define(  \"WP_DEBUG\"   ,    true    ) ;");
    $d = wpultra_debugmode_read_defines($src);
    assert_true($d['WP_DEBUG']['defined']);
    assert_eq(true, $d['WP_DEBUG']['value']);
});

it('read_defines parses a quoted string value (WP_DEBUG_LOG path)', function () {
    $src = wpultra_test_wpconfig("define('WP_DEBUG_LOG', '/var/log/wp-debug.log');");
    $d = wpultra_debugmode_read_defines($src);
    assert_true($d['WP_DEBUG_LOG']['defined']);
    assert_eq('/var/log/wp-debug.log', $d['WP_DEBUG_LOG']['value']);
});

it('read_defines parses an int literal value', function () {
    $src = wpultra_test_wpconfig("define('SAVEQUERIES', 1);");
    $d = wpultra_debugmode_read_defines($src);
    assert_true($d['SAVEQUERIES']['defined']);
    assert_eq(1, $d['SAVEQUERIES']['value']);
});

it('read_defines reports defined=false, value=null for constants missing from source', function () {
    $src = wpultra_test_wpconfig("define('WP_DEBUG', true);");
    $d = wpultra_debugmode_read_defines($src);
    assert_true(!$d['SCRIPT_DEBUG']['defined']);
    assert_eq(null, $d['SCRIPT_DEBUG']['value']);
});

it('read_defines returns an entry for every whitelisted constant, keyed by name', function () {
    $d = wpultra_debugmode_read_defines(wpultra_test_wpconfig(''));
    assert_eq(wpultra_debugmode_whitelist(), array_keys($d));
});

it('read_defines unescapes a single-quoted string with an escaped quote/backslash', function () {
    $src = wpultra_test_wpconfig("define('WP_DEBUG_LOG', 'C:\\\\wp\\\\debug\\'s.log');");
    $d = wpultra_debugmode_read_defines($src);
    assert_eq("C:\\wp\\debug's.log", $d['WP_DEBUG_LOG']['value']);
});

/* ------------------------------------------------------------------ *
 * wpultra_debugmode_plan — validate + normalize the requested map
 * ------------------------------------------------------------------ */

it('plan accepts a valid whitelisted bool map', function () {
    $plan = wpultra_debugmode_plan(['WP_DEBUG' => true, 'SAVEQUERIES' => false]);
    assert_eq(['WP_DEBUG' => true, 'SAVEQUERIES' => false], $plan);
});

it('plan accepts WP_DEBUG_LOG as a bool', function () {
    $plan = wpultra_debugmode_plan(['WP_DEBUG_LOG' => true]);
    assert_eq(['WP_DEBUG_LOG' => true], $plan);
});

it('plan accepts WP_DEBUG_LOG as a non-empty string path', function () {
    $plan = wpultra_debugmode_plan(['WP_DEBUG_LOG' => '/var/log/wp-debug.log']);
    assert_eq(['WP_DEBUG_LOG' => '/var/log/wp-debug.log'], $plan);
});

it('plan rejects a non-whitelisted constant name with bad_constant', function () {
    $plan = wpultra_debugmode_plan(['DISALLOW_FILE_EDIT' => true]);
    assert_wp_error($plan);
    assert_eq('bad_constant', $plan->get_error_code());
});

it('plan rejects a malicious constant name attempting code injection', function () {
    $plan = wpultra_debugmode_plan(["WP_DEBUG'); system('rm -rf /'); define('X" => true]);
    assert_wp_error($plan);
    assert_eq('bad_constant', $plan->get_error_code());
});

it('plan rejects WP_DEBUG_DISPLAY given a string value (non-log constants are bool only)', function () {
    $plan = wpultra_debugmode_plan(['WP_DEBUG_DISPLAY' => 'true']);
    assert_wp_error($plan);
    assert_eq('bad_value', $plan->get_error_code());
});

it('plan rejects WP_DEBUG_LOG given an empty string path', function () {
    $plan = wpultra_debugmode_plan(['WP_DEBUG_LOG' => '']);
    assert_wp_error($plan);
    assert_eq('bad_value', $plan->get_error_code());
});

it('plan rejects an int value for a bool-only constant', function () {
    $plan = wpultra_debugmode_plan(['SCRIPT_DEBUG' => 1]);
    assert_wp_error($plan);
    assert_eq('bad_value', $plan->get_error_code());
});

it('plan rejects an empty constants map', function () {
    $plan = wpultra_debugmode_plan([]);
    assert_wp_error($plan);
    assert_eq('no_constants', $plan->get_error_code());
});

/* ------------------------------------------------------------------ *
 * Round-trip through wpultra_security_wpconfig_set (the shared editor)
 * ------------------------------------------------------------------ */

it('round-trip: plan values written via wpconfig_set re-parse back to the same values', function () {
    $src = wpultra_test_wpconfig("define('WP_DEBUG', false);");
    $plan = wpultra_debugmode_plan(['WP_DEBUG' => true, 'WP_DEBUG_LOG' => true, 'SAVEQUERIES' => false]);
    assert_true(!is_wp_error($plan));

    foreach ($plan as $const => $value) {
        $src = wpultra_security_wpconfig_set($src, $const, $value);
        assert_true(!is_wp_error($src), "wpconfig_set failed for $const");
    }

    $after = wpultra_debugmode_read_defines($src);
    assert_eq(true, $after['WP_DEBUG']['value']);
    assert_eq(true, $after['WP_DEBUG_LOG']['value']);
    assert_eq(false, $after['SAVEQUERIES']['value']);
    // the sentinel line must survive the round-trip (still a valid insertion anchor)
    assert_true(wpultra_debugmode_has_sentinel($src));
});

it('round-trip: WP_DEBUG_LOG as a string path re-parses to the same path', function () {
    $src = wpultra_test_wpconfig('');
    $plan = wpultra_debugmode_plan(['WP_DEBUG_LOG' => '/var/log/wp-debug.log']);
    foreach ($plan as $const => $value) {
        $src = wpultra_security_wpconfig_set($src, $const, $value);
    }
    $after = wpultra_debugmode_read_defines($src);
    assert_eq('/var/log/wp-debug.log', $after['WP_DEBUG_LOG']['value']);
});

it('round-trip: re-applying a plan replaces the existing define instead of duplicating it', function () {
    $src = wpultra_test_wpconfig("define('WP_DEBUG', true);");
    $src = wpultra_security_wpconfig_set($src, 'WP_DEBUG', false);
    assert_eq(1, substr_count($src, "define('WP_DEBUG'"));
    $after = wpultra_debugmode_read_defines($src);
    assert_eq(false, $after['WP_DEBUG']['value']);
});

it('wpconfig_set (via debugmode plan value) errors when the sentinel is missing and constant not yet defined', function () {
    $src = "<?php\nrequire ABSPATH . 'wp-settings.php';\n"; // no sentinel, no existing define
    $result = wpultra_security_wpconfig_set($src, 'WP_DEBUG', true);
    assert_wp_error($result);
    assert_eq('anchor_missing', $result->get_error_code());
});

/* ------------------------------------------------------------------ *
 * wpultra_debugmode_has_sentinel
 * ------------------------------------------------------------------ */

it('has_sentinel true for a normal wp-config stop-editing line', function () {
    assert_true(wpultra_debugmode_has_sentinel(wpultra_test_wpconfig('')));
});

it('has_sentinel false when the sentinel line is absent', function () {
    assert_true(!wpultra_debugmode_has_sentinel("<?php\ndefine('WP_DEBUG', true);\n"));
});

run_tests();
