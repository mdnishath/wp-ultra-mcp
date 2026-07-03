<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/manage-access', [
    'label'       => __('Manage Access Policy', 'wp-ultra-mcp'),
    'description' => __('View and edit the access-control policy: grant non-admin roles a limited set of abilities/categories, and set per-minute rate limits (per ability, per category, or a default). actions: get; grant-role (role + abilities[]/categories[]); revoke-role (role); set-limit (scope=default|ability|category, key?, per_minute); reset. This ability is always admin-only. A limit of 0 = unlimited. By default the policy is empty, so every ability stays admin-only.', 'wp-ultra-mcp'),
    'category'    => 'access',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action'     => ['type' => 'string', 'enum' => ['get', 'grant-role', 'revoke-role', 'set-limit', 'reset']],
            'role'       => ['type' => 'string'],
            'abilities'  => ['type' => 'array'],
            'categories' => ['type' => 'array'],
            'scope'      => ['type' => 'string', 'enum' => ['default', 'ability', 'category']],
            'key'        => ['type' => 'string'],
            'per_minute' => ['type' => 'integer'],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'policy'  => ['type' => 'object'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_manage_access_cb',
    // Deliberately NOT the relaxed baseline — policy editing stays admin-only so a
    // granted role can never widen its own access.
    'permission_callback' => 'wpultra_access_admin_only',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_access_admin_only(): bool {
    return wpultra_is_enabled() && wpultra_current_user_can_manage();
}

function wpultra_manage_access_cb(array $input) {
    $action = (string) ($input['action'] ?? 'get');
    $policy = wpultra_access_policy();

    if ($action === 'get') {
        return wpultra_ok(['policy' => $policy]);
    }

    if ($action === 'reset') {
        wpultra_access_policy_save([]);
        wpultra_audit_log('manage-access', 'reset access policy', true);
        return wpultra_ok(['policy' => wpultra_access_policy()]);
    }

    if ($action === 'grant-role') {
        $role = (string) ($input['role'] ?? '');
        if ($role === '') { return wpultra_err('missing_role', 'role is required.'); }
        $policy['roles'][$role] = [
            'abilities'  => array_values(array_unique(array_map('strval', (array) ($input['abilities'] ?? [])))),
            'categories' => array_values(array_unique(array_map('strval', (array) ($input['categories'] ?? [])))),
        ];
        wpultra_access_policy_save($policy);
        wpultra_audit_log('manage-access', "grant role $role", true);
        return wpultra_ok(['policy' => wpultra_access_policy()]);
    }

    if ($action === 'revoke-role') {
        $role = (string) ($input['role'] ?? '');
        if ($role === '') { return wpultra_err('missing_role', 'role is required.'); }
        unset($policy['roles'][$role]);
        wpultra_access_policy_save($policy);
        wpultra_audit_log('manage-access', "revoke role $role", true);
        return wpultra_ok(['policy' => wpultra_access_policy()]);
    }

    if ($action === 'set-limit') {
        $scope = (string) ($input['scope'] ?? 'default');
        $per   = max(0, (int) ($input['per_minute'] ?? 0));
        if ($scope === 'default') {
            $policy['limits']['default'] = $per;
        } else {
            $key = (string) ($input['key'] ?? '');
            if ($key === '') { return wpultra_err('missing_key', "scope '$scope' requires key (the ability or category slug)."); }
            $bucket = $scope === 'ability' ? 'abilities' : 'categories';
            $policy['limits'][$bucket][$key] = $per;
        }
        wpultra_access_policy_save($policy);
        wpultra_audit_log('manage-access', "set-limit $scope " . ($input['key'] ?? '') . " = $per/min", true);
        return wpultra_ok(['policy' => wpultra_access_policy()]);
    }

    return wpultra_err('bad_action', "Unknown action '$action'.");
}
