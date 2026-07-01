<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/selftest/engine.php';
// Load the three real adapters so we test the ACTUAL function names, not stubs.
require __DIR__ . '/../wp-ultra-mcp/includes/fields/adapters/acf.php';
require __DIR__ . '/../wp-ultra-mcp/includes/fields/adapters/metabox.php';
require __DIR__ . '/../wp-ultra-mcp/includes/fields/adapters/pods.php';

it('every provider defines the router-expected read/write/list_groups/get_group functions', function () {
    // wpultra_fields_route() calls "wpultra_fields_{provider}_{op}" — a name drift silently
    // kills a whole provider (this is exactly the Meta Box bug that shipped and was fixed).
    $missing = wpultra_selftest_provider_matrix(['acf', 'metabox', 'pods'], 'function_exists');
    assert_eq([], $missing, 'missing adapter functions: ' . implode(', ', $missing));
});

run_tests();
