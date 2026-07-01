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
it('php substitution encodes inputs as safe literals, not raw code', function () {
    // A malicious string is emitted as a quoted PHP literal — it can't break out of the expression.
    assert_eq("return get_post(5);", wpultra_recipe_subst_php('return get_post({id});', ['id' => 5]));
    assert_eq("return get_post('1); evil(); (');", wpultra_recipe_subst_php('return get_post({id});', ['id' => '1); evil(); (']));
});
it('scalar substitution json-encodes non-scalars instead of "Array"', function () {
    assert_eq('x=[1,2]', wpultra_recipe_subst_scalar('x={v}', ['v' => [1, 2]]));
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
it('allows all run types by default', function () {
    assert_eq(['wp-cli', 'sql', 'php', 'http'], wpultra_recipe_allowed_run_types());
});
it('blocks a run type disabled via WPULTRA_RECIPE_RUN_TYPES (defined last)', function () {
    // Lock down to http+sql only; php and wp-cli become unavailable.
    define('WPULTRA_RECIPE_RUN_TYPES', 'http, sql');
    assert_eq(['sql', 'http'], wpultra_recipe_allowed_run_types());
    $php = ['run' => 'php', 'input' => [], 'recipe' => ['code' => 'return 1;']];
    $r = wpultra_recipe_execute($php, []);
    assert_wp_error($r, 'php disabled');
    assert_eq('recipe_run_disabled', $r->get_error_code());
    // An allowed type still dispatches.
    $sql = ['run' => 'sql', 'input' => [], 'recipe' => ['query' => 'SELECT 1', 'params' => []]];
    wpultra_recipe_execute($sql, []);
    assert_eq('sql', $GLOBALS['__last'][0]);
});

run_tests();
