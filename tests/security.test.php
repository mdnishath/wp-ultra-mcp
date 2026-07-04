<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';

if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/wpultra_security/'); }
// helpers.php provides wpultra_err / wpultra_ok (uses WP_Error stub from harness).
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/system/security.php';

/* ============================================================
 * scan_content — pattern matrix.
 * ============================================================ */

it('scan_content flags eval() as high', function () {
    $hits = wpultra_security_scan_content('<?php eval($x); ');
    $byId = [];
    foreach ($hits as $h) { $byId[$h['id']] = $h; }
    assert_true(isset($byId['eval']), 'eval hit present');
    assert_eq('high', $byId['eval']['severity']);
});

it('scan_content flags base64_decode, gzinflate, str_rot13', function () {
    $hits = wpultra_security_scan_content('$a = base64_decode(gzinflate(str_rot13($p)));');
    $ids = array_column($hits, 'id');
    assert_true(in_array('base64_decode', $ids, true), 'base64_decode');
    assert_true(in_array('gzinflate', $ids, true), 'gzinflate');
    assert_true(in_array('str_rot13', $ids, true), 'str_rot13');
});

it('scan_content flags superglobal invoked as a callable', function () {
    $hits = wpultra_security_scan_content('$_POST["cmd"]($_POST["arg"]);');
    $byId = [];
    foreach ($hits as $h) { $byId[$h['id']] = $h; }
    assert_true(isset($byId['superglobal_call']), 'superglobal_call hit present');
    assert_eq('high', $byId['superglobal_call']['severity']);

    // Also matches _GET / _REQUEST.
    assert_true(count(wpultra_security_scan_content('$_GET[$k]();')) >= 1);
    assert_true(count(wpultra_security_scan_content('$_REQUEST["x"]();')) >= 1);
});

it('scan_content flags preg_replace with the /e modifier', function () {
    $hits = wpultra_security_scan_content('preg_replace("/.*/e", $evil, $subject);');
    $ids = array_column($hits, 'id');
    assert_true(in_array('preg_replace_e', $ids, true), 'preg_replace_e hit present');
    // Combined modifiers too.
    $ids2 = array_column(wpultra_security_scan_content("preg_replace('/x/ise', \$r, \$s);"), 'id');
    assert_true(in_array('preg_replace_e', $ids2, true), 'preg_replace_e with combined modifiers');
});

it('scan_content does NOT flag preg_replace without the /e modifier (no cross-argument match)', function () {
    // Plain sanitization call — the old lazy regex crossed into the 2nd arg and false-positived.
    $ids = array_column(wpultra_security_scan_content("preg_replace('/[^a-z0-9_-]/', '', \$name);"), 'id');
    assert_true(!in_array('preg_replace_e', $ids, true), 'clean preg_replace not flagged');
    // Replacement text containing an "e" right before its closing quote must not trigger.
    $ids2 = array_column(wpultra_security_scan_content("preg_replace('/foo/', 'we', \$s);"), 'id');
    assert_true(!in_array('preg_replace_e', $ids2, true), 'cross-arg "we" replacement not flagged');
});

it('scan_content flags assert() as medium', function () {
    $hits = wpultra_security_scan_content('assert($code);');
    $byId = [];
    foreach ($hits as $h) { $byId[$h['id']] = $h; }
    assert_true(isset($byId['assert']));
    assert_eq('medium', $byId['assert']['severity']);
});

it('scan_content flags shell execution functions as high', function () {
    foreach (['system("id");', 'exec($c);', 'shell_exec($c);', 'passthru($c);'] as $code) {
        $byId = [];
        foreach (wpultra_security_scan_content($code) as $h) { $byId[$h['id']] = $h; }
        assert_true(isset($byId['shell_exec']), "shell_exec hit for: $code");
        assert_eq('high', $byId['shell_exec']['severity']);
    }
});

it('scan_content returns no hits for clean code', function () {
    $hits = wpultra_security_scan_content('<?php function add($a, $b) { return $a + $b; } echo add(1, 2);');
    assert_eq([], $hits);
});

it('scan_content dedupes multiple occurrences into a single hit with a count', function () {
    $hits = wpultra_security_scan_content('eval($a); eval($b); eval($c);');
    $evals = array_values(array_filter($hits, static fn($h) => $h['id'] === 'eval'));
    assert_eq(1, count($evals), 'a single eval hit');
    assert_eq(3, $evals[0]['count'], 'count reflects all three occurrences');
});

it('highest_severity picks high over medium over none', function () {
    assert_eq('high', wpultra_security_highest_severity([['severity' => 'medium'], ['severity' => 'high']]));
    assert_eq('medium', wpultra_security_highest_severity([['severity' => 'low'], ['severity' => 'medium']]));
    assert_eq('', wpultra_security_highest_severity([]));
});

/* ============================================================
 * wpconfig_set — insert / replace / idempotent / missing anchor.
 * ============================================================ */

$SENTINEL = "/* That's all, stop editing! Happy publishing. */";

it('wpconfig_set inserts a new define before the stop-editing line', function () use ($SENTINEL) {
    $config = "<?php\ndefine('DB_NAME', 'wp');\n\n$SENTINEL\nrequire_once ABSPATH . 'wp-settings.php';\n";
    $out = wpultra_security_wpconfig_set($config, 'DISALLOW_FILE_EDIT', true);
    assert_true(is_string($out), 'returns the rewritten string');
    assert_contains("define('DISALLOW_FILE_EDIT', true);", $out);
    // The new define must sit before the sentinel.
    $posDefine = strpos($out, "define('DISALLOW_FILE_EDIT', true);");
    $posStop   = strpos($out, 'stop editing');
    assert_true($posDefine !== false && $posStop !== false && $posDefine < $posStop, 'define inserted before stop-editing line');
});

it('wpconfig_set replaces an existing define in place', function () use ($SENTINEL) {
    $config = "<?php\ndefine('WP_DEBUG', false);\n$SENTINEL\n";
    $out = wpultra_security_wpconfig_set($config, 'WP_DEBUG', true);
    assert_true(is_string($out));
    assert_contains("define('WP_DEBUG', true);", $out);
    // Old value gone, and only one WP_DEBUG define remains.
    assert_eq(1, substr_count($out, 'WP_DEBUG'));
});

it('wpconfig_set is idempotent — re-running yields identical output', function () use ($SENTINEL) {
    $config = "<?php\n$SENTINEL\n";
    $once  = wpultra_security_wpconfig_set($config, 'DISALLOW_FILE_EDIT', true);
    assert_true(is_string($once));
    $twice = wpultra_security_wpconfig_set($once, 'DISALLOW_FILE_EDIT', true);
    assert_eq($once, $twice, 'second application is a no-op');
});

it('wpconfig_set errors when the stop-editing anchor is missing', function () {
    $config = "<?php\ndefine('DB_NAME', 'wp');\nrequire_once ABSPATH . 'wp-settings.php';\n";
    $out = wpultra_security_wpconfig_set($config, 'DISALLOW_FILE_EDIT', true);
    assert_wp_error($out, 'missing anchor returns WP_Error');
    assert_eq('anchor_missing', $out->get_error_code());
});

it('wpconfig_set quotes string values and escapes them', function () use ($SENTINEL) {
    $config = "<?php\n$SENTINEL\n";
    $out = wpultra_security_wpconfig_set($config, 'MY_KEY', "a'b\\c");
    assert_true(is_string($out));
    assert_contains("define('MY_KEY', 'a\\'b\\\\c');", $out);
});

it('wpconfig_set renders int values unquoted', function () use ($SENTINEL) {
    $config = "<?php\n$SENTINEL\n";
    $out = wpultra_security_wpconfig_set($config, 'WP_MEMORY', 256);
    assert_contains("define('WP_MEMORY', 256);", $out);
});

it('wpconfig_set rejects an invalid constant name', function () use ($SENTINEL) {
    $out = wpultra_security_wpconfig_set("<?php\n$SENTINEL\n", 'bad name', true);
    assert_wp_error($out);
    assert_eq('bad_const', $out->get_error_code());
});

/* ============================================================
 * harden_plan — split requested vs already-done.
 * ============================================================ */

it('harden_plan splits to_apply and already_done', function () {
    $plan = wpultra_security_harden_plan(
        ['disable-file-edit', 'disable-xmlrpc', 'hide-version'],
        ['disable-file-edit' => true, 'disable-xmlrpc' => false, 'hide-version' => false]
    );
    assert_eq(['disable-file-edit'], $plan['already_done']);
    assert_eq(['disable-xmlrpc', 'hide-version'], $plan['to_apply']);
});

it('harden_plan drops unknown measures and dedupes', function () {
    $plan = wpultra_security_harden_plan(
        ['disable-xmlrpc', 'not-real', 'disable-xmlrpc'],
        []
    );
    assert_eq(['disable-xmlrpc'], $plan['to_apply']);
    assert_eq([], $plan['already_done']);
});

it('harden_plan treats a missing current-state key as not-done', function () {
    $plan = wpultra_security_harden_plan(['limit-login'], []);
    assert_eq(['limit-login'], $plan['to_apply']);
});

it('known_measures lists exactly the five documented measures', function () {
    assert_eq(
        ['disable-file-edit', 'disable-xmlrpc', 'limit-login', 'security-headers', 'hide-version'],
        wpultra_security_known_measures()
    );
});

run_tests();
