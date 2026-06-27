<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
if (!function_exists('wp_register_ability')) { function wp_register_ability($n, $a) {} }
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/abilities/memory-save.php';

it('memory-save rejects an invalid type', function () {
    assert_wp_error(wpultra_memory_save(['name' => 'n', 'description' => 'd', 'content' => 'c', 'type' => 'bogus']), 'bad type');
});
it('memory-save rejects a missing name', function () {
    assert_wp_error(wpultra_memory_save(['description' => 'd', 'content' => 'c', 'type' => 'user']), 'no name');
});

run_tests();
