<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/recipes/cpt.php';

it('input schema converts defs', function () {
    $s = wpultra_recipe_input_schema(['user_id' => ['type' => 'integer', 'required' => true], 'note' => ['type' => 'string']]);
    assert_eq('object', $s['type']);
    assert_eq(['user_id'], $s['required']);
});

run_tests();
