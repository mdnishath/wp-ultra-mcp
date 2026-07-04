<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Headless domain — on-content-change revalidation bridge (Roadmap-3, H2.4).
 *
 * Rides the triggers engine: enable creates webhook triggers on
 * post_published / post_updated that POST {secret, event, post_id, post_type,
 * path} to the frontend's revalidate endpoint (the scaffold's /api/revalidate
 * refreshes its ISR tags on that body) or any generic build webhook. Dispatch
 * is async (cron) so a slow frontend can never block publishing.
 */

/**
 * Shape the stored revalidate option. Pure.
 * @param mixed $raw
 * @return array{enabled:bool,endpoint:string,secret:string,trigger_ids:array<int,int>}
 */
function wpultra_headless_reval_shape($raw): array {
    $out = ['enabled' => false, 'endpoint' => '', 'secret' => '', 'trigger_ids' => []];
    if (is_array($raw)) {
        $out['enabled']     = !empty($raw['enabled']);
        $out['endpoint']    = (string) ($raw['endpoint'] ?? '');
        $out['secret']      = (string) ($raw['secret'] ?? '');
        $out['trigger_ids'] = array_values(array_map('intval', (array) ($raw['trigger_ids'] ?? [])));
    }
    return $out;
}

/**
 * Validate enable-input. Pure. @return array{endpoint:string}|string
 */
function wpultra_headless_reval_validate(array $input) {
    $endpoint = (string) ($input['endpoint'] ?? '');
    if (!preg_match('#^https?://#i', $endpoint)) {
        return "Invalid endpoint '$endpoint' — pass the full revalidate URL, e.g. http://localhost:3000/api/revalidate or a Vercel/Netlify build hook.";
    }
    return ['endpoint' => $endpoint];
}

/**
 * The trigger definitions enable creates. Pure. Unknown events are dropped.
 * The flat template matches the scaffold's /api/revalidate body: secret +
 * context; with no explicit tags the frontend refreshes its default tag set.
 * @return array<int,array<string,mixed>>
 */
function wpultra_headless_reval_trigger_defs(string $endpoint, string $secret, array $events): array {
    $known = ['post_published', 'post_updated'];
    $defs = [];
    foreach ($events as $event) {
        if (!in_array($event, $known, true)) { continue; }
        $defs[] = [
            'event'       => $event,
            'action_type' => 'webhook',
            'url'         => $endpoint,
            'label'       => "headless-revalidate:$event",
            'template'    => [
                'secret'    => $secret,
                'event'     => '{event}',
                'post_id'   => '{data.post_id}',
                'post_type' => '{data.post_type}',
                'path'      => '{data.permalink}',
            ],
        ];
    }
    return $defs;
}

/** The live revalidate config. */
function wpultra_headless_reval_config(): array {
    return wpultra_headless_reval_shape(function_exists('get_option') ? get_option('wpultra_headless_revalidate', []) : []);
}

/** Host of the configured endpoint ('' when unset/invalid). Pure. */
function wpultra_headless_reval_allowed_host(string $endpoint): string {
    $host = parse_url($endpoint, PHP_URL_HOST);
    return is_string($host) ? strtolower($host) : '';
}

/** Explicit port of the configured endpoint (0 when default/unset). Pure. */
function wpultra_headless_reval_allowed_port(string $endpoint): int {
    $port = parse_url($endpoint, PHP_URL_PORT);
    return is_int($port) ? $port : 0;
}

/**
 * Runtime boot: wp_safe_remote_post refuses loopback hosts AND non-standard
 * ports (only 80/443/8080 pass) — localhost:3000, the normal dev frontend,
 * fails both checks, which would silently kill action:test and the cron
 * webhook dispatch. The admin explicitly configured this endpoint, so allow
 * exactly that host + port. Called from wpultra_headless_boot().
 */
function wpultra_headless_reval_boot(): void {
    add_filter('http_request_host_is_external', function ($external, $host) {
        if ($external) { return $external; }
        $allowed = wpultra_headless_reval_allowed_host(wpultra_headless_reval_config()['endpoint']);
        return $allowed !== '' && strtolower((string) $host) === $allowed ? true : $external;
    }, 10, 2);
    add_filter('http_allowed_safe_ports', function ($ports) {
        $port = wpultra_headless_reval_allowed_port(wpultra_headless_reval_config()['endpoint']);
        if ($port > 0 && is_array($ports) && !in_array($port, $ports, true)) { $ports[] = $port; }
        return $ports;
    });
}
