<?php
// Zero-dependency PHP test harness + minimal WordPress stubs for pure-logic unit tests.
// Run a test file with: php tests/<name>.test.php   (it requires this harness).

declare(strict_types=1);

error_reporting(E_ALL);

$GLOBALS['__tests'] = [];
$GLOBALS['__fail'] = 0;
$GLOBALS['__pass'] = 0;

function it(string $name, callable $fn): void { $GLOBALS['__tests'][] = [$name, $fn]; }

function assert_eq($expected, $actual, string $msg = ''): void {
    if ($expected === $actual) { return; }
    throw new Exception("assert_eq failed: $msg\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true));
}
function assert_true($cond, string $msg = ''): void {
    if ($cond === true) { return; }
    throw new Exception("assert_true failed: $msg (got " . var_export($cond, true) . ')');
}
function assert_contains(string $needle, string $haystack, string $msg = ''): void {
    if (str_contains($haystack, $needle)) { return; }
    throw new Exception("assert_contains failed: $msg\n  needle: $needle\n  haystack: $haystack");
}
function assert_throws(callable $fn, string $msg = ''): void {
    try { $fn(); } catch (\Throwable $e) { return; }
    throw new Exception("assert_throws failed: $msg (no throwable raised)");
}
function assert_wp_error($val, string $msg = ''): void {
    if (is_wp_error($val)) { return; }
    throw new Exception("assert_wp_error failed: $msg (got " . var_export($val, true) . ')');
}

function run_tests(): void {
    foreach ($GLOBALS['__tests'] as [$name, $fn]) {
        try { $fn(); $GLOBALS['__pass']++; echo "  PASS  $name\n"; }
        catch (\Throwable $e) { $GLOBALS['__fail']++; echo "  FAIL  $name\n    " . str_replace("\n", "\n    ", $e->getMessage()) . "\n"; }
    }
    $p = $GLOBALS['__pass']; $f = $GLOBALS['__fail'];
    echo "\n$p passed, $f failed\n";
    exit($f > 0 ? 1 : 0);
}

// ---- Minimal WordPress stubs (only what pure-logic code needs) ----
if (!class_exists('WP_Error')) {
    class WP_Error {
        public array $errors = [];
        public array $error_data = [];
        public function __construct($code = '', $message = '', $data = '') {
            if ($code !== '') { $this->errors[$code][] = $message; if ($data !== '') { $this->error_data[$code] = $data; } }
        }
        public function get_error_code() { return array_key_first($this->errors) ?? ''; }
        public function get_error_message() { $c = $this->get_error_code(); return $c ? ($this->errors[$c][0] ?? '') : ''; }
        public function get_error_data($code = '') { $c = $code ?: $this->get_error_code(); return $this->error_data[$c] ?? null; }
    }
}
if (!function_exists('is_wp_error')) { function is_wp_error($t): bool { return $t instanceof WP_Error; } }
if (!function_exists('__')) { function __($t, $d = 'default') { return $t; } }
if (!function_exists('apply_filters')) { function apply_filters($tag, $value, ...$a) { return $value; } }
if (!function_exists('add_action')) { function add_action(...$a) { return true; } }
if (!function_exists('add_filter')) { function add_filter(...$a) { return true; } }
if (!function_exists('trailingslashit')) { function trailingslashit($s) { return rtrim($s, "/\\") . '/'; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($d, $f = 0, $depth = 512) { return json_encode($d, $f, $depth); } }
