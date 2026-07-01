<?php
// Zero-dep harness style: define minimal stubs, require the file, assert.
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
$__fails = 0;
function ok($cond, $msg) { global $__fails; if ($cond) { echo "PASS: $msg\n"; } else { $__fails++; echo "FAIL: $msg\n"; } }

// setup.php must be requireable without any field plugin present and report zero providers.
require __DIR__ . '/../includes/fields/setup.php';
$providers = wpultra_fields_providers();
ok(is_array($providers), 'wpultra_fields_providers returns array');
ok(count($providers) === 0, 'no providers detected in bare CLI (no ACF/MB/Pods constants)');
$caps = wpultra_fields_provider_caps('acf');
ok(isset($caps['complex_types']) && $caps['complex_types'] === false, 'acf caps default complex_types false without ACF Pro');
