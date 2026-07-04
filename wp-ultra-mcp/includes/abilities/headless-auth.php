<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/headless-auth', [
    'label'       => __('Headless: Auth', 'wp-ultra-mcp'),
    'description' => __('Authenticated GraphQL for the frontend. actions: `status` (which auth modes are ready + how to use each), `create-app-password` (confirm-gated; mints a WP Application Password for a user and returns the ready-to-use "Authorization: Basic …" header — for SERVER-side fetches like draft preview/ISR), `issue-token` (confirm-gated; issues a WPGraphQL-JWT authToken + refreshToken for a user without needing their password — send as "Authorization: Bearer …"), `revoke-jwt` (invalidates all of a user\'s JWT tokens). user = ID, login, or email.', 'wp-ultra-mcp'),
    'category'    => 'headless',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['status', 'create-app-password', 'issue-token', 'revoke-jwt'], 'default' => 'status'],
            'user'    => ['type' => 'string', 'description' => 'User ID, login, or email (for the non-status actions).'],
            'name'    => ['type' => 'string', 'default' => 'headless-frontend', 'description' => 'Label for the created application password.'],
            'confirm' => ['type' => 'boolean', 'description' => 'Required true for create-app-password / issue-token (they mint credentials).'],
        ],
        'required'             => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'modes'   => ['type' => 'array'],
            'user'    => ['type' => 'object'],
            'header'  => ['type' => 'string'],
            'tokens'  => ['type' => 'object'],
            'note'    => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_headless_auth_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

/** Resolve a user by ID, login, or email. @return WP_User|WP_Error */
function wpultra_headless_auth_resolve_user(string $ref) {
    if ($ref === '') { return wpultra_err('missing_user', 'Pass user: an ID, login, or email.'); }
    $user = is_numeric($ref) ? get_user_by('id', (int) $ref) : (get_user_by('login', $ref) ?: get_user_by('email', $ref));
    return $user instanceof WP_User ? $user : wpultra_err('user_not_found', "No user matches '$ref'.");
}

function wpultra_headless_auth_cb(array $input) {
    $action = (string) ($input['action'] ?? 'status');

    if ($action === 'status') {
        $jwt_secret = (defined('GRAPHQL_JWT_AUTH_SECRET_KEY') && constant('GRAPHQL_JWT_AUTH_SECRET_KEY') !== '') || wpultra_headless_jwt_secret() !== '';
        $app_pw     = function_exists('wp_is_application_passwords_available') && wp_is_application_passwords_available();
        return wpultra_ok(['modes' => wpultra_headless_auth_modes(wpultra_headless_detect(), $jwt_secret, $app_pw)]);
    }

    $user = wpultra_headless_auth_resolve_user((string) ($input['user'] ?? ''));
    if (is_wp_error($user)) { return $user; }
    $shape = ['id' => $user->ID, 'login' => $user->user_login, 'email' => $user->user_email];

    if ($action === 'create-app-password') {
        if (($input['confirm'] ?? false) !== true) {
            return wpultra_err('unconfirmed', 'create-app-password mints a long-lived credential. Re-run with confirm:true.');
        }
        if (!class_exists('WP_Application_Passwords')) {
            return wpultra_err('unavailable', 'Application Passwords are not available on this WordPress.');
        }
        $created = WP_Application_Passwords::create_new_application_password($user->ID, ['name' => (string) ($input['name'] ?? 'headless-frontend')]);
        if (is_wp_error($created)) { return $created; }
        [$password] = $created;
        return wpultra_ok([
            'user'   => $shape,
            'header' => wpultra_headless_basic_header($user->user_login, (string) $password),
            'note'   => 'Shown ONCE — store it in the frontend server env (e.g. WORDPRESS_AUTH). Revoke any time from the user profile.',
        ]);
    }

    if ($action === 'issue-token') {
        if (($input['confirm'] ?? false) !== true) {
            return wpultra_err('unconfirmed', 'issue-token mints auth tokens for that user. Re-run with confirm:true.');
        }
        if (!class_exists('WPGraphQL\\JWT_Authentication\\Auth')) {
            return wpultra_err('jwt_missing', 'WPGraphQL-JWT is not active — run headless-setup first.');
        }
        try {
            $auth_token    = \WPGraphQL\JWT_Authentication\Auth::get_token($user);
            $refresh_token = \WPGraphQL\JWT_Authentication\Auth::get_refresh_token($user);
        } catch (\Throwable $e) {
            return wpultra_err('jwt_failed', 'Token issue failed: ' . $e->getMessage());
        }
        if (is_wp_error($auth_token)) { return $auth_token; }
        return wpultra_ok([
            'user'   => $shape,
            'tokens' => [
                'authToken'    => (string) $auth_token,
                'refreshToken' => is_wp_error($refresh_token) ? '' : (string) $refresh_token,
            ],
            'note'   => 'Send as "Authorization: Bearer <authToken>". Refresh via the refreshJwtAuthToken mutation; revoke-jwt invalidates everything.',
        ]);
    }

    if ($action === 'revoke-jwt') {
        if (!class_exists('WPGraphQL\\JWT_Authentication\\Auth')) {
            return wpultra_err('jwt_missing', 'WPGraphQL-JWT is not active — run headless-setup first.');
        }
        $res = \WPGraphQL\JWT_Authentication\Auth::revoke_user_secret($user->ID);
        if (is_wp_error($res)) { return $res; }
        return wpultra_ok(['user' => $shape, 'note' => 'All JWT tokens for this user are now invalid (a new secret is minted on next issue/login).']);
    }

    return wpultra_err('bad_action', "Unknown action '$action'.");
}
