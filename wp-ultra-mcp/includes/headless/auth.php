<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Headless domain — authenticated GraphQL helpers (Roadmap-3, H2.3).
 *
 * Two paths for logged-in queries/mutations from the frontend:
 * - JWT (WPGraphQL-JWT): short-lived Bearer tokens, right for browser sessions.
 * - Application Passwords (WP core): long-lived Basic auth, right for
 *   server-side fetches (draft preview, ISR) — the same mechanism MCP uses.
 */

/** "Basic <b64 user:pass>" header value. App-password display spaces are stripped. Pure. */
function wpultra_headless_basic_header(string $user, string $password): string {
    return 'Basic ' . base64_encode($user . ':' . str_replace(' ', '', $password));
}

/**
 * Which auth modes this site supports right now. Pure over detection + flags.
 * @param array<string,?string> $detected  wpultra_headless_detect() map
 * @return array<int,array{mode:string,ready:bool,how:string}>
 */
function wpultra_headless_auth_modes(array $detected, bool $jwt_secret_defined, bool $app_passwords_available): array {
    $jwt_plugin = ($detected['wpgraphql-jwt'] ?? null) !== null;
    return [
        [
            'mode'  => 'jwt',
            'ready' => $jwt_plugin && $jwt_secret_defined,
            'how'   => $jwt_plugin && $jwt_secret_defined
                ? 'Browser flow: run the `login` GraphQL mutation with user credentials to get authToken (send as "Authorization: Bearer <token>") + refreshToken; or issue-token here for a server-held token.'
                : ($jwt_plugin
                    ? 'WPGraphQL-JWT is installed but no signing secret is set — run headless-setup to generate one.'
                    : 'Install WPGraphQL-JWT via headless-setup to enable short-lived Bearer tokens for browser sessions.'),
        ],
        [
            'mode'  => 'application-passwords',
            'ready' => $app_passwords_available,
            'how'   => $app_passwords_available
                ? 'Server-side flow: create-app-password here, then send "Authorization: Basic <b64 user:app-password>" from the frontend server (draft preview, ISR fetches). Never ship it to the browser.'
                : 'Application Passwords are unavailable (needs HTTPS or a local environment, WP 5.6+).',
        ],
    ];
}
