<?php
// Pure unit tests for the ACF complex-field engine (fields/complex.php).
// Only the WP-free functions are exercised: wpultra_fields_rows_splice + _detect_kind.
// Run: php tests/fields-rows.test.php
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
$__fails = 0;
function ok($cond, $msg) { global $__fails; if ($cond) { echo "PASS: $msg\n"; } else { $__fails++; echo "FAIL: $msg\n"; } }

// Minimal WP stubs the pure functions need (complex.php calls wpultra_err in WP paths only,
// but define it anyway so the file loads cleanly under CLI).
if (!class_exists('WP_Error')) {
    class WP_Error {
        public $code; public $message; public $data;
        public function __construct($code = '', $message = '', $data = '') { $this->code = $code; $this->message = $message; $this->data = $data; }
        public function get_error_code() { return $this->code; }
    }
}
if (!function_exists('is_wp_error')) { function is_wp_error($t) { return $t instanceof WP_Error; } }
if (!function_exists('wpultra_err')) { function wpultra_err($c, $m, $d = '') { return new WP_Error($c, $m, $d); } }
if (!function_exists('__')) { function __($t, $d = 'default') { return $t; } }

require __DIR__ . '/../wp-ultra-mcp/includes/fields/complex.php';

// ---------------------------------------------------------------------------
// detect_kind fixtures
// ---------------------------------------------------------------------------
ok(wpultra_fields_rows_detect_kind([['a' => 1], ['a' => 2]]) === 'repeater',
    'detect_kind: list of assoc rows -> repeater');
ok(wpultra_fields_rows_detect_kind([['acf_fc_layout' => 'hero', 'h' => 1], ['b' => 2]]) === 'flexible',
    'detect_kind: any row with acf_fc_layout -> flexible');
ok(wpultra_fields_rows_detect_kind(['street' => 'Main', 'zip' => '1000']) === 'group',
    'detect_kind: plain assoc -> group');
ok(wpultra_fields_rows_detect_kind('hello') === 'scalar',
    'detect_kind: string -> scalar');
ok(wpultra_fields_rows_detect_kind([]) === 'scalar',
    'detect_kind: empty array -> scalar');
ok(wpultra_fields_rows_detect_kind([1, 2, 3]) === 'scalar',
    'detect_kind: list of scalars -> scalar (not rows)');
ok(wpultra_fields_rows_detect_kind([['a' => 1], 'oops']) === 'scalar',
    'detect_kind: mixed list (assoc + scalar) -> scalar');

// ---------------------------------------------------------------------------
// splice: add
// ---------------------------------------------------------------------------
$base = [['n' => 'a'], ['n' => 'b'], ['n' => 'c']];

$r = wpultra_fields_rows_splice($base, 'add', 1, ['n' => 'X'], false);
ok($r === [['n' => 'a'], ['n' => 'X'], ['n' => 'b'], ['n' => 'c']], 'add at index 1 inserts before b');

$r = wpultra_fields_rows_splice($base, 'add', null, ['n' => 'Z'], false);
ok($r === [['n' => 'a'], ['n' => 'b'], ['n' => 'c'], ['n' => 'Z']], 'add with null index appends');

$r = wpultra_fields_rows_splice($base, 'add', 99, ['n' => 'Z'], false);
ok($r === [['n' => 'a'], ['n' => 'b'], ['n' => 'c'], ['n' => 'Z']], 'add index past end clamps to append');

$r = wpultra_fields_rows_splice($base, 'add', -5, ['n' => 'Y'], false);
ok($r === [['n' => 'Y'], ['n' => 'a'], ['n' => 'b'], ['n' => 'c']], 'add negative index clamps to front');

$r = wpultra_fields_rows_splice([], 'add', 0, ['n' => 'first'], false);
ok($r === [['n' => 'first']], 'add into empty rows seeds the list');

// ---------------------------------------------------------------------------
// splice: update (merge vs replace)
// ---------------------------------------------------------------------------
$rows = [['n' => 'a', 'k' => 1], ['n' => 'b', 'k' => 2]];

$r = wpultra_fields_rows_splice($rows, 'update', 0, ['k' => 9], true);
ok($r === [['n' => 'a', 'k' => 9], ['n' => 'b', 'k' => 2]], 'update merge:true keeps other sub-values');

$r = wpultra_fields_rows_splice($rows, 'update', 0, ['k' => 9], false);
ok($r === [['k' => 9], ['n' => 'b', 'k' => 2]], 'update merge:false replaces the whole row');

$r = wpultra_fields_rows_splice($rows, 'update', 1, ['n' => 'B', 'extra' => 't'], true);
ok($r === [['n' => 'a', 'k' => 1], ['n' => 'B', 'k' => 2, 'extra' => 't']], 'update merge adds new keys + overwrites');

// ---------------------------------------------------------------------------
// splice: delete
// ---------------------------------------------------------------------------
$r = wpultra_fields_rows_splice($base, 'delete', 1, null, false);
ok($r === [['n' => 'a'], ['n' => 'c']], 'delete removes row at index and re-indexes');

$r = wpultra_fields_rows_splice($base, 'delete', 0, null, false);
ok($r === [['n' => 'b'], ['n' => 'c']], 'delete first row');

$r = wpultra_fields_rows_splice($base, 'delete', 2, null, false);
ok($r === [['n' => 'a'], ['n' => 'b']], 'delete last row');

// ---------------------------------------------------------------------------
// splice: replace
// ---------------------------------------------------------------------------
$r = wpultra_fields_rows_splice($base, 'replace', null, [['n' => 'only']], false);
ok($r === [['n' => 'only']], 'replace swaps in the full new rows set');

$r = wpultra_fields_rows_splice($base, 'replace', null, [], false);
ok($r === [], 'replace with empty rows clears all');

// ---------------------------------------------------------------------------
// splice: bounds + argument errors (throw)
// ---------------------------------------------------------------------------
function throws_ia(callable $fn): bool {
    try { $fn(); return false; } catch (\InvalidArgumentException $e) { return true; }
}
ok(throws_ia(fn() => wpultra_fields_rows_splice($base, 'update', 5, ['x' => 1], true)),
    'update out-of-range index throws');
ok(throws_ia(fn() => wpultra_fields_rows_splice($base, 'update', -1, ['x' => 1], true)),
    'update negative index throws');
ok(throws_ia(fn() => wpultra_fields_rows_splice($base, 'delete', 9, null, false)),
    'delete out-of-range index throws');
ok(throws_ia(fn() => wpultra_fields_rows_splice($base, 'update', null, ['x' => 1], true)),
    'update without index throws');
ok(throws_ia(fn() => wpultra_fields_rows_splice($base, 'update', 0, null, true)),
    'update without row throws');
ok(throws_ia(fn() => wpultra_fields_rows_splice($base, 'add', 0, null, false)),
    'add without row throws');
ok(throws_ia(fn() => wpultra_fields_rows_splice($base, 'delete', null, null, false)),
    'delete without index throws');
ok(throws_ia(fn() => wpultra_fields_rows_splice($base, 'replace', null, null, false)),
    'replace without rows throws');
ok(throws_ia(fn() => wpultra_fields_rows_splice($base, 'bogus', 0, ['x' => 1], false)),
    'unknown op throws');

// Base must never be mutated by any of the above (immutability check).
ok($base === [['n' => 'a'], ['n' => 'b'], ['n' => 'c']], 'splice does not mutate the input rows');

echo "\n" . ($__fails === 0 ? "ALL PASS" : "$__fails FAILED") . "\n";
exit($__fails === 0 ? 0 : 1);
