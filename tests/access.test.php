<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/access/engine.php';

$POLICY = [
    'roles' => [
        'editor'        => ['abilities' => ['site-snapshot'], 'categories' => ['content', 'seo']],
        'shop_manager'  => ['abilities' => [], 'categories' => ['woocommerce']],
    ],
    'limits' => ['default' => 0, 'abilities' => ['execute-php' => 5], 'categories' => ['code-execution' => 10]],
];

it('admins may run anything regardless of policy', function () use ($POLICY) {
    assert_true(wpultra_access_role_can(['subscriber'], 'execute-php', 'code-execution', $POLICY, true));
    assert_true(wpultra_access_role_can([], 'anything', '', [], true));
});

it('non-admin role grants match by ability OR category', function () use ($POLICY) {
    assert_true(wpultra_access_role_can(['editor'], 'site-snapshot', 'system', $POLICY, false));  // by ability
    assert_true(wpultra_access_role_can(['editor'], 'create-post', 'content', $POLICY, false));   // by category
    assert_true(wpultra_access_role_can(['shop_manager'], 'woo-get-order', 'woocommerce', $POLICY, false));
    assert_true(!wpultra_access_role_can(['editor'], 'execute-php', 'code-execution', $POLICY, false)); // not granted
    assert_true(!wpultra_access_role_can(['subscriber'], 'create-post', 'content', $POLICY, false));    // ungranted role
});

it('has_any_grant is the baseline door', function () use ($POLICY) {
    assert_true(wpultra_access_has_any_grant(['editor'], $POLICY));
    assert_true(!wpultra_access_has_any_grant(['subscriber'], $POLICY));
    assert_true(!wpultra_access_has_any_grant(['editor'], ['roles' => ['editor' => ['abilities' => [], 'categories' => []]]]));
});

it('limit resolution: ability > category > default', function () use ($POLICY) {
    assert_eq(5, wpultra_access_limit_for('execute-php', 'code-execution', $POLICY)); // ability wins over category
    assert_eq(10, wpultra_access_limit_for('run-wp-cli', 'code-execution', $POLICY)); // category
    assert_eq(0, wpultra_access_limit_for('create-post', 'content', $POLICY));        // default (unlimited)
});

it('within_limit: 0 is unlimited, else strictly below', function () {
    assert_true(wpultra_access_within_limit(999, 0));   // unlimited
    assert_true(wpultra_access_within_limit(4, 5));     // 5th call ok (count before = 4)
    assert_true(!wpultra_access_within_limit(5, 5));    // 6th call blocked
});

it('policy normalize coerces shapes + clamps negatives', function () {
    $n = wpultra_access_policy_normalize([
        'roles' => ['editor' => ['abilities' => ['a', 'a', 'b']]],
        'limits' => ['default' => -3, 'abilities' => ['x' => '7']],
    ]);
    assert_eq(['a', 'b'], $n['roles']['editor']['abilities']);
    assert_eq([], $n['roles']['editor']['categories']);
    assert_eq(0, $n['limits']['default']); // clamped
    assert_eq(7, $n['limits']['abilities']['x']);
});

run_tests();
