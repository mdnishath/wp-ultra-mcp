<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
// Stub Wave 1 primitives to capture what the executor dispatches.
$GLOBALS['__last'] = null;
function wpultra_run_wp_cli($i) { $GLOBALS['__last'] = ['cli', $i]; return ['success' => true, 'exit_code' => 0, 'stdout' => 'ok', 'stderr' => '']; }
function wpultra_execute_wp_query($i) { $GLOBALS['__last'] = ['sql', $i]; return ['success' => true, 'verb' => 'SELECT', 'rows' => [], 'row_count' => 0]; }
function wpultra_execute_php($i) { $GLOBALS['__last'] = ['php', $i]; return ['success' => true, 'return_value' => 1, 'output' => '']; }
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/recipes/executor.php';

it('substitutes scalar tokens', function () {
    assert_eq('--user=42', wpultra_recipe_subst_scalar('--user={user_id}', ['user_id' => 42]));
});
it('substitutes array tokens', function () {
    assert_eq(['wc', '--user=42'], wpultra_recipe_subst_array(['wc', '--user={user_id}'], ['user_id' => 42]));
});
it('wp-cli recipe dispatches subst args to run_wp_cli', function () {
    $parsed = ['run' => 'wp-cli', 'input' => ['user_id' => ['type' => 'integer', 'required' => true]],
        'recipe' => ['command' => ['wc', 'cart', 'empty', '--user={user_id}']]];
    $r = wpultra_recipe_execute($parsed, ['user_id' => 7]);
    assert_true($r['success'], 'ok');
    assert_eq('cli', $GLOBALS['__last'][0]);
    assert_eq(['wc', 'cart', 'empty', '--user=7'], $GLOBALS['__last'][1]['args']);
});
it('sql recipe substitutes into params, not the query string', function () {
    $parsed = ['run' => 'sql', 'input' => ['id' => ['type' => 'integer', 'required' => true]],
        'recipe' => ['query' => 'SELECT * FROM wp_posts WHERE ID = %d', 'params' => ['{id}']]];
    wpultra_recipe_execute($parsed, ['id' => 5]);
    assert_eq('sql', $GLOBALS['__last'][0]);
    assert_eq('SELECT * FROM wp_posts WHERE ID = %d', $GLOBALS['__last'][1]['sql']);
    assert_eq(['5'], $GLOBALS['__last'][1]['params']);
});
it('rejects missing required input', function () {
    $parsed = ['run' => 'php', 'input' => ['x' => ['type' => 'string', 'required' => true]], 'recipe' => ['code' => 'return 1;']];
    assert_wp_error(wpultra_recipe_execute($parsed, []), 'missing input');
});

run_tests();
