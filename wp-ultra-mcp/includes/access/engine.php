<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Access control: per-role ability grants + per-ability/category rate limits.
 *
 * By default every ability requires `manage_options` (admin) — unchanged. An
 * admin can additionally grant specific non-admin roles a limited set of
 * abilities/categories, and throttle any ability to N calls per minute (admins
 * included, to cap runaway loops).
 *
 * Enforcement is two-layer:
 *   1. Baseline (wpultra_permission_callback, no ability name): enabled AND
 *      (admin OR the user's role has at least one grant) — lets granted
 *      non-admins reach the execution layer, blocks everyone else cleanly.
 *   2. Per-ability gate on core's `wp_before_execute_ability` (has the name):
 *      denies an ungranted ability for a non-admin, and enforces rate limits,
 *      by throwing — which the MCP adapter turns into a clean error response.
 *
 * Policy lives in option `wpultra_access_policy`:
 *   { "roles": { "<role>": {"abilities":[...],"categories":[...]} },
 *     "limits": { "default":0, "abilities":{"execute-php":5}, "categories":{"code-execution":10} } }
 * A limit of 0 means unlimited.
 */

const WPULTRA_ACCESS_OPTION = 'wpultra_access_policy';

/* ------------------------------------------------------------------ *
 * PURE helpers — no WordPress.
 * ------------------------------------------------------------------ */

/**
 * Pure: may a user with $roles run $ability (in $category) under $policy?
 * Admins always may. Otherwise a role must grant the ability by name or its
 * whole category.
 */
function wpultra_access_role_can(array $roles, string $ability, string $category, array $policy, bool $is_admin): bool {
    if ($is_admin) { return true; }
    $rolemap = is_array($policy['roles'] ?? null) ? $policy['roles'] : [];
    foreach ($roles as $role) {
        $grant = $rolemap[$role] ?? null;
        if (!is_array($grant)) { continue; }
        $abilities  = array_map('strval', (array) ($grant['abilities'] ?? []));
        $categories = array_map('strval', (array) ($grant['categories'] ?? []));
        if (in_array($ability, $abilities, true)) { return true; }
        if ($category !== '' && in_array($category, $categories, true)) { return true; }
    }
    return false;
}

/** Pure: does any role hold at least one grant (the baseline-door check)? */
function wpultra_access_has_any_grant(array $roles, array $policy): bool {
    $rolemap = is_array($policy['roles'] ?? null) ? $policy['roles'] : [];
    foreach ($roles as $role) {
        $grant = $rolemap[$role] ?? null;
        if (is_array($grant) && (!empty($grant['abilities']) || !empty($grant['categories']))) { return true; }
    }
    return false;
}

/**
 * Pure: the per-minute call limit for $ability — the ability-specific override,
 * else its category override, else the default. 0 = unlimited.
 */
function wpultra_access_limit_for(string $ability, string $category, array $policy): int {
    $limits = is_array($policy['limits'] ?? null) ? $policy['limits'] : [];
    $byAbility  = is_array($limits['abilities'] ?? null) ? $limits['abilities'] : [];
    $byCategory = is_array($limits['categories'] ?? null) ? $limits['categories'] : [];
    if (array_key_exists($ability, $byAbility))  { return max(0, (int) $byAbility[$ability]); }
    if ($category !== '' && array_key_exists($category, $byCategory)) { return max(0, (int) $byCategory[$category]); }
    return max(0, (int) ($limits['default'] ?? 0));
}

/** Pure: is a call allowed given the count already made this window and the limit? */
function wpultra_access_within_limit(int $current, int $limit): bool {
    if ($limit <= 0) { return true; } // unlimited
    return $current < $limit;
}

/** Pure: normalize/sanitize a submitted policy into the canonical shape. */
function wpultra_access_policy_normalize(array $in): array {
    $out = ['roles' => [], 'limits' => ['default' => 0, 'abilities' => [], 'categories' => []]];
    foreach ((array) ($in['roles'] ?? []) as $role => $grant) {
        $role = (string) $role;
        if ($role === '') { continue; }
        $out['roles'][$role] = [
            'abilities'  => array_values(array_unique(array_map('strval', (array) ($grant['abilities'] ?? [])))),
            'categories' => array_values(array_unique(array_map('strval', (array) ($grant['categories'] ?? [])))),
        ];
    }
    $limits = (array) ($in['limits'] ?? []);
    $out['limits']['default'] = max(0, (int) ($limits['default'] ?? 0));
    foreach ((array) ($limits['abilities'] ?? []) as $a => $n)  { $out['limits']['abilities'][(string) $a] = max(0, (int) $n); }
    foreach ((array) ($limits['categories'] ?? []) as $c => $n) { $out['limits']['categories'][(string) $c] = max(0, (int) $n); }
    return $out;
}

/* ------------------------------------------------------------------ *
 * Store + WordPress-facing helpers.
 * ------------------------------------------------------------------ */

function wpultra_access_policy(): array {
    $v = function_exists('get_option') ? get_option(WPULTRA_ACCESS_OPTION, []) : [];
    return wpultra_access_policy_normalize(is_array($v) ? $v : []);
}
function wpultra_access_policy_save(array $policy): void {
    if (function_exists('update_option')) { update_option(WPULTRA_ACCESS_OPTION, wpultra_access_policy_normalize($policy), false); }
}

/** Current user's roles (empty when logged out). */
function wpultra_access_current_roles(): array {
    if (!function_exists('wp_get_current_user')) { return []; }
    $u = wp_get_current_user();
    return ($u && !empty($u->roles)) ? array_map('strval', (array) $u->roles) : [];
}

function wpultra_access_current_is_admin(): bool {
    return wpultra_current_user_can_manage();
}

/**
 * Relaxed baseline permission: enabled AND (admin OR the user's role holds at
 * least one grant). Wired from wpultra_permission_callback. Falls back to
 * admin-only whenever no grants exist, so default behaviour is unchanged.
 */
function wpultra_access_baseline_user(): bool {
    if (wpultra_access_current_is_admin()) { return true; }
    return wpultra_access_has_any_grant(wpultra_access_current_roles(), wpultra_access_policy());
}

/** Map a full ability name to its category slug (''=unknown). */
function wpultra_access_ability_category(string $ability_name): string {
    $slug = str_starts_with($ability_name, 'wpultra/') ? substr($ability_name, 8) : $ability_name;
    return function_exists('wpultra_file_category') ? wpultra_file_category($slug) : '';
}

/** Read+increment the per-user/per-ability per-minute counter. Returns the count BEFORE this call. */
function wpultra_access_rate_touch(int $user_id, string $ability): int {
    if (!function_exists('get_transient')) { return 0; }
    $window = (int) floor((function_exists('time') ? time() : 0) / 60);
    $key = 'wpultra_rl_' . $user_id . '_' . md5($ability) . '_' . $window;
    $count = (int) get_transient($key);
    set_transient($key, $count + 1, 120);
    return $count;
}

/**
 * The per-ability gate, hooked on core's `wp_before_execute_ability` (fires
 * after permission + input validation, before the callback). Throws to deny —
 * the MCP adapter catches \Throwable and returns the message as the error.
 */
function wpultra_access_gate(string $ability_name, $input): void {
    if (!str_starts_with($ability_name, 'wpultra/')) { return; } // only our abilities
    // The access-management ability is always admin-only and never throttled (no self-lockout).
    if ($ability_name === 'wpultra/manage-access') { return; }

    $category = wpultra_access_ability_category($ability_name);
    $slug     = substr($ability_name, 8);
    $policy   = wpultra_access_policy();
    $is_admin = wpultra_access_current_is_admin();

    // Role gate.
    if (!wpultra_access_role_can(wpultra_access_current_roles(), $slug, $category, $policy, $is_admin)) {
        throw new \RuntimeException("Access denied: your role may not run '$slug'.");
    }
    // Rate limit (applies to admins too).
    $limit = wpultra_access_limit_for($slug, $category, $policy);
    if ($limit > 0) {
        $uid = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        $current = wpultra_access_rate_touch($uid, $slug);
        if (!wpultra_access_within_limit($current, $limit)) {
            throw new \RuntimeException("Rate limit reached for '$slug' ($limit/min). Try again shortly.");
        }
    }
}

/** Register the gate. Called from the abilities loader once access.php is present. */
function wpultra_access_register_gate(): void {
    if (function_exists('add_action')) { add_action('wp_before_execute_ability', 'wpultra_access_gate', 10, 2); }
}
