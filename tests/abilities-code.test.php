<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/'); }
if (!defined('WPULTRA_CLI_TIMEOUT')) { define('WPULTRA_CLI_TIMEOUT', 30); }
if (!function_exists('wp_register_ability')) { function wp_register_ability($n, $a) {} }
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/abilities/execute-php.php';

it('execute-php returns the return value and captured output', function () {
    $r = wpultra_execute_php(['code' => 'echo "hi"; return 1 + 2;']);
    assert_true($r['success'], 'ok');
    assert_eq('hi', $r['output'], 'echo captured');
    assert_eq('3', (string) $r['return_value'], 'return value');
});
it('execute-php catches a thrown error as success=false', function () {
    $r = wpultra_execute_php(['code' => 'throw new Exception("boom");']);
    assert_eq(false, $r['success'], 'failure flagged');
    assert_contains('boom', (string) $r['error'], 'error message');
});

run_tests();
