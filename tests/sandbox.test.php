<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
$tmp = sys_get_temp_dir() . '/wpu_sb_' . uniqid();
mkdir($tmp, 0777, true);
if (!defined('ABSPATH')) { define('ABSPATH', $tmp . '/'); }
if (!defined('WPULTRA_SANDBOX_DIR')) { define('WPULTRA_SANDBOX_DIR', $tmp . '/sandbox/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/sandbox/runtime.php';

it('starts not crashed', function () { assert_eq(false, wpultra_sandbox_crashed()); });
it('mark + detect + clear crashed', function () {
    wpultra_sandbox_mark_crashed('boom in widget.php');
    assert_eq(true, wpultra_sandbox_crashed());
    wpultra_sandbox_clear();
    assert_eq(false, wpultra_sandbox_crashed());
});

run_tests();
