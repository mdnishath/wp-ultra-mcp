<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
if (!function_exists('wp_register_ability')) { function wp_register_ability($n, $a) {} }
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/abilities/create-post.php';
require __DIR__ . '/../wp-ultra-mcp/includes/abilities/delete-post.php';

it('create-post requires a title', function () {
    assert_wp_error(wpultra_create_post(['content' => 'x']), 'no title');
});
it('delete-post requires a post_id', function () {
    assert_wp_error(wpultra_delete_post([]), 'no id');
});

run_tests();
