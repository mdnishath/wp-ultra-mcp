<?php
// Zero-dep harness style: define minimal stubs, require the file, assert.
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
$__fails = 0;
function ok($cond, $msg) { global $__fails; if ($cond) { echo "PASS: $msg\n"; } else { $__fails++; echo "FAIL: $msg\n"; } }

// setup.php must be requireable without any field plugin present and report zero providers.
require __DIR__ . '/../wp-ultra-mcp/includes/fields/setup.php';
$providers = wpultra_fields_providers();
ok(is_array($providers), 'wpultra_fields_providers returns array');
ok(count($providers) === 0, 'no providers detected in bare CLI (no ACF/MB/Pods constants)');
$caps = wpultra_fields_provider_caps('acf');
ok(isset($caps['complex_types']) && $caps['complex_types'] === false, 'acf caps default complex_types false without ACF Pro');

// --- Task 3: values.php (pure) ---
if (!class_exists('WP_Error')) {
    class WP_Error {
        public $code; public $message; public $data;
        public function __construct($code = '', $message = '', $data = '') { $this->code = $code; $this->message = $message; $this->data = $data; }
        public function get_error_code() { return $this->code; }
    }
}
function is_wp_error($t) { return $t instanceof WP_Error; }
require __DIR__ . '/../wp-ultra-mcp/includes/fields/values.php';

$t = wpultra_fields_resolve_target(['type' => 'post', 'id' => '42']);
ok(is_array($t) && $t['type'] === 'post' && $t['id'] === 42, 'resolve_target coerces post id to int');
$t2 = wpultra_fields_resolve_target(['type' => 'options']);
ok(is_array($t2) && $t2['type'] === 'options' && $t2['id'] === '', 'resolve_target options allows empty id');
$bad = wpultra_fields_resolve_target(['type' => 'bogus']);
ok(is_wp_error($bad) && $bad->get_error_code() === 'target_invalid', 'resolve_target rejects unknown type');

$n = wpultra_fields_normalize_batch(['subtitle' => 'Hi', 'features' => ['value' => [1, 2], 'mode' => 'replace']]);
ok(is_array($n) && $n['atomic']['subtitle'] === 'Hi', 'normalize keeps atomic scalar');
ok(isset($n['complex']['features']) && $n['complex']['features']['value'] === [1, 2], 'normalize routes consent-wrapped value to complex');

// Regression: a genuine atomic array carrying a 'mode' key alongside real data (e.g. an ACF
// group value) must NOT be mistaken for a malformed consent wrapper.
$n2 = wpultra_fields_normalize_batch(['cfg' => ['mode' => 'dark', 'color' => '#000']]);
ok(is_array($n2) && ($n2['atomic']['cfg'] ?? null) === ['mode' => 'dark', 'color' => '#000'], 'normalize treats {mode,color} array as atomic, not a consent wrapper');
// Truncated wrappers (only one sentinel key) are still rejected.
$n3 = wpultra_fields_normalize_batch(['x' => ['mode' => 'replace']]);
ok(is_wp_error($n3) && $n3->get_error_code() === 'complex_consent', 'normalize rejects a lone {mode} truncated wrapper');
$n4 = wpultra_fields_normalize_batch(['x' => ['value' => 1]]);
ok(is_wp_error($n4) && $n4->get_error_code() === 'complex_consent', 'normalize rejects a lone {value} truncated wrapper');

// --- C1 regression: router/adapter name matrix ---
// wpultra_fields_route() dispatches to "wpultra_fields_{provider}_{op}"; a name drift silently
// kills a whole provider (this was the Meta Box read/write bug). Loading the plain-function
// adapter files must expose every provider×op the router expects.
require __DIR__ . '/../wp-ultra-mcp/includes/fields/adapters/acf.php';
require __DIR__ . '/../wp-ultra-mcp/includes/fields/adapters/metabox.php';
require __DIR__ . '/../wp-ultra-mcp/includes/fields/adapters/pods.php';
foreach (['acf', 'metabox', 'pods'] as $p) {
    foreach (['read', 'write'] as $op) {
        ok(function_exists("wpultra_fields_{$p}_{$op}"), "router target wpultra_fields_{$p}_{$op} exists");
    }
}

echo "\n" . ($__fails === 0 ? "ALL PASS" : "$__fails FAILED") . "\n";
exit($__fails === 0 ? 0 : 1);
