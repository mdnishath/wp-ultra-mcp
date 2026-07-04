<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Shared front-end tracking endpoint for the marketing domain (Group A).
 *
 * POST /wp-json/wpultra/v1/track  (public — beacons from cached pages can't auth)
 *   body: { kind: 'ab'|'popup', event: string, id: string|int, variant?: string }
 *
 * Dispatches to the owning engine's handler when loaded:
 *   kind 'ab'    -> wpultra_ab_handle_track(array $payload)
 *   kind 'popup' -> wpultra_popup_handle_track(array $payload)
 *
 * Handlers must treat the payload as hostile: validate ids against their own
 * stores, increment counters only, never echo payload values back unescaped.
 * A soft per-IP rate limit (120 events/min) bounds abuse; counters are
 * best-effort analytics, not billing-grade data.
 */

function wpultra_track_register_routes(): void {
    if (!function_exists('register_rest_route')) { return; }
    register_rest_route('wpultra/v1', '/track', [
        'methods'             => 'POST',
        'permission_callback' => '__return_true',
        'callback'            => 'wpultra_track_rest_cb',
    ]);
}

/** Soft per-IP rate limit: true when this event is allowed. */
function wpultra_track_within_limit(): bool {
    if (!function_exists('get_transient')) { return true; }
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $key = 'wpultra_track_rl_' . md5($ip);
    $count = (int) get_transient($key);
    if ($count >= 120) { return false; }
    set_transient($key, $count + 1, 60);
    return true;
}

/** @param WP_REST_Request $req */
function wpultra_track_rest_cb($req) {
    if (!wpultra_track_within_limit()) {
        return new WP_REST_Response(['ok' => false, 'error' => 'rate_limited'], 429);
    }

    $kind    = sanitize_key((string) $req->get_param('kind'));
    $event   = sanitize_key((string) $req->get_param('event'));
    $id      = $req->get_param('id');
    $variant = sanitize_key((string) ($req->get_param('variant') ?? ''));

    if ($kind === '' || $event === '') {
        return new WP_REST_Response(['ok' => false, 'error' => 'bad_request'], 400);
    }

    $payload = [
        'event'   => $event,
        'id'      => is_numeric($id) ? (int) $id : sanitize_key((string) $id),
        'variant' => $variant,
    ];

    $handled = false;
    if ($kind === 'ab' && function_exists('wpultra_ab_handle_track')) {
        $handled = (bool) wpultra_ab_handle_track($payload);
    } elseif ($kind === 'popup' && function_exists('wpultra_popup_handle_track')) {
        $handled = (bool) wpultra_popup_handle_track($payload);
    }

    return new WP_REST_Response(['ok' => $handled], $handled ? 200 : 202);
}
