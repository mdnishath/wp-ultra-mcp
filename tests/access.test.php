<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/access/engine.php';

$POLICY = [
    'roles' => [
        'editor'        => ['abilities' => ['form-list'], 'categories' => ['content', 'seo']],
        'shop_manager'  => ['abilities' => [], 'categories' => ['woocommerce']],
    ],
    'limits' => ['default' => 0, 'abilities' => ['execute-php' => 5], 'categories' => ['code-execution' => 10]],
];

it('admins may run anything regardless of policy', function () use ($POLICY) {
    assert_true(wpultra_access_role_can(['subscriber'], 'execute-php', 'code-execution', $POLICY, true));
    assert_true(wpultra_access_role_can([], 'anything', '', [], true));
});

it('non-admin role grants match by ability OR category', function () use ($POLICY) {
    assert_true(wpultra_access_role_can(['editor'], 'form-list', 'forms', $POLICY, false));       // by ability
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

it('RCE-class abilities/categories are never delegatable to a non-admin', function () {
    // Even a policy that explicitly grants them must not enable a non-admin.
    $bad = ['roles' => ['subscriber' => [
        'abilities'  => ['execute-php', 'run-wp-cli', 'execute-wp-query', 'write-file'],
        'categories' => ['code-execution', 'database', 'filesystem'],
    ]]];
    assert_true(!wpultra_access_role_can(['subscriber'], 'execute-php', 'code-execution', $bad, false));
    assert_true(!wpultra_access_role_can(['subscriber'], 'run-wp-cli', 'code-execution', $bad, false));
    assert_true(!wpultra_access_role_can(['subscriber'], 'execute-wp-query', 'database', $bad, false));
    assert_true(!wpultra_access_role_can(['subscriber'], 'write-file', 'filesystem', $bad, false));
    // Admins are unaffected (they may run anything).
    assert_true(wpultra_access_role_can(['subscriber'], 'execute-php', 'code-execution', $bad, true));
    // Non-RCE grants still work as before.
    $ok = ['roles' => ['editor' => ['abilities' => [], 'categories' => ['content']]]];
    assert_true(wpultra_access_role_can(['editor'], 'create-post', 'content', $ok, false));
    // The deny helper is stable.
    assert_true(wpultra_access_is_rce_class('execute-php', ''));
    assert_true(wpultra_access_is_rce_class('anything', 'filesystem'));
    assert_true(!wpultra_access_is_rce_class('create-post', 'content'));
});

it('privilege-escalation abilities/categories are never delegatable either', function () {
    // install-plugin from a zip URL = arbitrary PHP; manage-user/roles-manage = mint an admin.
    $bad = ['roles' => ['subscriber' => [
        'abilities'  => ['manage-plugin-theme', 'manage-user', 'roles-manage', 'site-migrate', 'staging-clone', 'option-set'],
        'categories' => ['system', 'users'],
    ]]];
    assert_true(!wpultra_access_role_can(['subscriber'], 'manage-plugin-theme', 'system', $bad, false));
    assert_true(!wpultra_access_role_can(['subscriber'], 'manage-user', 'users', $bad, false));
    assert_true(!wpultra_access_role_can(['subscriber'], 'roles-manage', 'users', $bad, false));
    assert_true(!wpultra_access_role_can(['subscriber'], 'option-set', 'system', $bad, false));
    // Category grant on system/users gives nothing, even for a mild system ability.
    assert_true(!wpultra_access_role_can(['subscriber'], 'php-env-info', 'system', $bad, false));
    // Ability-name backstop holds even if the category map drifts to ''.
    assert_true(wpultra_access_is_rce_class('manage-plugin-theme', ''));
    assert_true(wpultra_access_is_rce_class('manage-user', ''));
    assert_true(wpultra_access_is_rce_class('site-migrate', ''));
    // Admins are unaffected.
    assert_true(wpultra_access_role_can(['subscriber'], 'manage-plugin-theme', 'system', $bad, true));
});

run_tests();
