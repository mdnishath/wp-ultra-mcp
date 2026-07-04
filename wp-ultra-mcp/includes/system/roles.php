<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * User roles & capabilities editor engine (Roadmap G3).
 *
 * WordPress stores every role in the single `wp_user_roles` option:
 *   [ slug => ['name' => Display Name, 'capabilities' => [cap => bool]] ].
 * The runtime API is:
 *   add_role($slug, $name, $caps), remove_role($slug),
 *   get_role($slug)->add_cap($cap)/remove_cap($cap),
 *   wp_roles()->get_names() / wp_roles()->roles.
 * A role change persists in the DB option — there is no per-request runtime
 * hook to re-install, so wpultra_roles_boot() is a cheap no-op.
 *
 * The PURE functions (prefix wpultra_roles_) are the testable core and touch
 * NO WordPress state: slug/cap validation, the protected-role list, the
 * admin-lockout guard, the cap catalog, and the before/after diff. The WP
 * wrappers below them (guarded by function_exists) do the actual add_role/
 * remove_role/get_role work and enforce the one guard that needs live data —
 * never delete the role that the last remaining administrator user relies on.
 */

/* =====================================================================
 * PURE — validation, protection, guards, catalog, diff.
 * ===================================================================== */

/** PURE. The five WordPress core roles. Deleting these is blocked. */
function wpultra_roles_core_slugs(): array {
    return ['administrator', 'editor', 'author', 'contributor', 'subscriber'];
}

/**
 * PURE. True if $slug is a protected core role. Callers must block DELETE and
 * reset-to-default of these, and must refuse edits that would orphan admin
 * access (see wpultra_roles_guard_admin_caps).
 */
function wpultra_roles_is_protected(string $slug): bool {
    return in_array(strtolower(trim($slug)), wpultra_roles_core_slugs(), true);
}

/**
 * PURE. A role slug is [a-z0-9_-], 1..64 chars, must start with a letter or
 * digit (WordPress lowercases and this is what add_role() will accept cleanly).
 */
function wpultra_roles_valid_slug(string $slug): bool {
    return (bool) preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $slug);
}

/**
 * PURE. A capability name is a WordPress meta/primitive cap key: letters,
 * digits and underscores, 1..128 chars, starting with a letter. This rejects
 * spaces, dashes and punctuation that would make add_cap() silently useless.
 */
function wpultra_roles_valid_cap(string $cap): bool {
    return (bool) preg_match('/^[a-z][a-z0-9_]{0,127}$/i', $cap);
}

/**
 * PURE. Normalize a caps map to [cap => bool]. Accepts either an associative
 * map (cap => truthy) or a plain list of cap names (each granted true).
 */
function wpultra_roles_normalize_caps(array $caps): array {
    $out = [];
    $isList = array_keys($caps) === range(0, count($caps) - 1) && $caps !== [];
    if ($isList) {
        foreach ($caps as $cap) {
            $cap = (string) $cap;
            if ($cap !== '') { $out[$cap] = true; }
        }
        return $out;
    }
    foreach ($caps as $cap => $granted) {
        $cap = (string) $cap;
        if ($cap === '') { continue; }
        $out[$cap] = (bool) $granted;
    }
    return $out;
}

/** PURE. Caps that make a role administrator-equivalent (full control). */
function wpultra_roles_admin_caps(): array {
    return ['manage_options', 'edit_users', 'promote_users', 'delete_users'];
}

/**
 * PURE. True if this caps map grants effective admin control — i.e. it holds
 * manage_options (the canonical "can reach every settings screen" cap).
 */
function wpultra_roles_caps_are_admin(array $caps): bool {
    $caps = wpultra_roles_normalize_caps($caps);
    return !empty($caps['manage_options']);
}

/**
 * PURE. Guard an edit against locking every human out of wp-admin.
 *
 * @param string $slug     the role being edited.
 * @param array  $newCaps  the caps the edit would set on $slug (map or list).
 * @param array  $roleMap  the CURRENT full role map [slug => ['capabilities' => [...]]]
 *                         (or [slug => caps-map]); pass wp_roles()->roles in the
 *                         wrapper so this stays pure.
 * @return true|string     true if safe, else a human-readable refusal reason.
 *
 * Two rules:
 *   1. Never strip manage_options from the 'administrator' role.
 *   2. Never let an edit leave ZERO roles that still grant manage_options.
 */
function wpultra_roles_guard_admin_caps(string $slug, array $newCaps, array $roleMap): bool|string {
    $slug    = strtolower(trim($slug));
    $newCaps = wpultra_roles_normalize_caps($newCaps);

    // Rule 1: the administrator role must always keep manage_options.
    if ($slug === 'administrator' && empty($newCaps['manage_options'])) {
        return "Refusing to strip 'manage_options' from the administrator role — that would lock every admin out of wp-admin.";
    }

    // Rule 2: simulate the edit across the whole role map and ensure at least
    // one role still grants manage_options.
    $adminRolesAfter = 0;
    $sawTarget = false;
    foreach ($roleMap as $s => $def) {
        $s = strtolower((string) $s);
        if ($s === $slug) {
            $sawTarget = true;
            if (!empty($newCaps['manage_options'])) { $adminRolesAfter++; }
            continue;
        }
        // Accept both ['capabilities' => [...]] and a bare caps map.
        $caps = [];
        if (is_array($def) && isset($def['capabilities']) && is_array($def['capabilities'])) {
            $caps = $def['capabilities'];
        } elseif (is_array($def)) {
            $caps = $def;
        }
        if (!empty(wpultra_roles_normalize_caps($caps)['manage_options'] ?? false)) {
            $adminRolesAfter++;
        }
    }
    // Creating a brand-new role that isn't yet in the map: count it if admin.
    if (!$sawTarget && !empty($newCaps['manage_options'])) { $adminRolesAfter++; }

    if ($adminRolesAfter < 1) {
        return "Refusing this edit — it would leave NO role granting 'manage_options', locking every user out of wp-admin.";
    }
    return true;
}

/**
 * PURE. Grouped catalog of common WordPress capabilities so the AI knows what
 * it can grant. Groups are non-empty; manage_options and the woocommerce group
 * are always present.
 *
 * @return array<string, string[]>  group => [cap, cap, ...]
 */
function wpultra_roles_cap_catalog(): array {
    return [
        'content' => [
            'edit_posts', 'edit_others_posts', 'edit_published_posts', 'edit_private_posts',
            'publish_posts', 'delete_posts', 'delete_others_posts', 'delete_published_posts',
            'delete_private_posts', 'read_private_posts',
            'edit_pages', 'edit_others_pages', 'edit_published_pages',
            'publish_pages', 'delete_pages', 'delete_others_pages',
            'read', 'manage_categories', 'moderate_comments', 'edit_comment', 'unfiltered_html',
        ],
        'media' => [
            'upload_files',
        ],
        'users' => [
            'list_users', 'create_users', 'add_users', 'edit_users', 'delete_users',
            'promote_users', 'remove_users',
        ],
        'plugins' => [
            'activate_plugins', 'install_plugins', 'update_plugins', 'delete_plugins', 'edit_plugins',
        ],
        'themes' => [
            'switch_themes', 'install_themes', 'update_themes', 'delete_themes',
            'edit_themes', 'edit_theme_options', 'customize',
        ],
        'core' => [
            'manage_options', 'import', 'export', 'update_core', 'edit_files', 'edit_dashboard',
        ],
        'woocommerce' => [
            'manage_woocommerce',
            'edit_shop_orders', 'edit_others_shop_orders', 'publish_shop_orders',
            'read_shop_order', 'delete_shop_orders',
            'edit_products', 'edit_others_products', 'publish_products',
            'read_private_products', 'delete_products',
            'manage_product_terms', 'view_woocommerce_reports',
        ],
    ];
}

/**
 * PURE. Group a flat caps map by the catalog. Every granted cap lands in its
 * catalog group, or in 'other' if unknown. Returns group => [cap, ...] with
 * only the caps present (and granted-true) in $caps.
 */
function wpultra_roles_group_caps(array $caps): array {
    $caps    = wpultra_roles_normalize_caps($caps);
    $catalog = wpultra_roles_cap_catalog();
    $index   = [];
    foreach ($catalog as $group => $list) {
        foreach ($list as $cap) { $index[$cap] = $group; }
    }
    $out = [];
    foreach ($caps as $cap => $granted) {
        if (!$granted) { continue; }
        $group = $index[$cap] ?? 'other';
        $out[$group][] = $cap;
    }
    return $out;
}

/**
 * PURE. Diff two caps maps for audit clarity.
 * @return array{added: string[], removed: string[]}
 *
 * "added"   = caps granted-true in $after that were not granted-true in $before.
 * "removed" = caps granted-true in $before that are absent or false in $after.
 */
function wpultra_roles_cap_diff(array $before, array $after): array {
    $b = wpultra_roles_normalize_caps($before);
    $a = wpultra_roles_normalize_caps($after);

    $granted = static function (array $m): array {
        $out = [];
        foreach ($m as $cap => $on) { if ($on) { $out[$cap] = true; } }
        return $out;
    };
    $bg = $granted($b);
    $ag = $granted($a);

    $added   = array_values(array_diff(array_keys($ag), array_keys($bg)));
    $removed = array_values(array_diff(array_keys($bg), array_keys($ag)));
    sort($added);
    sort($removed);
    return ['added' => $added, 'removed' => $removed];
}

/* =====================================================================
 * WP WRAPPERS — guarded; touch the live role map. Not unit-tested.
 * ===================================================================== */

/**
 * Runtime boot. Role edits persist in the wp_user_roles DB option, so there is
 * nothing to re-install per request. Kept for controller-contract symmetry.
 */
function wpultra_roles_boot(): void {
    // Intentionally empty — role changes are DB-backed and need no runtime hook.
}

/** Live full role map [slug => ['name'=>.., 'capabilities'=>[..]]]. */
function wpultra_roles_live_map(): array {
    if (!function_exists('wp_roles')) { return []; }
    $roles = wp_roles();
    return isset($roles->roles) && is_array($roles->roles) ? $roles->roles : [];
}

/** Live caps map for one role, or null if it doesn't exist. */
function wpultra_roles_live_caps(string $slug): ?array {
    if (!function_exists('get_role')) { return null; }
    $role = get_role($slug);
    if (!$role) { return null; }
    return is_array($role->capabilities) ? $role->capabilities : [];
}

/** Count users currently assigned to $slug (live). 0 if uncountable. */
function wpultra_roles_user_count(string $slug): int {
    if (!function_exists('count_users')) { return 0; }
    $c = count_users();
    return (int) ($c['avail_roles'][$slug] ?? 0);
}

/**
 * Live guard: true when $slug is the ONLY role that holds manage_options AND at
 * least one user is assigned to it — deleting it would leave the site with no
 * admin-capable user. Returns a refusal string, or false when the delete is
 * safe. This is the last-administrator check documented in the ability.
 */
function wpultra_roles_would_orphan_admins(string $slug): bool|string {
    $slug = strtolower(trim($slug));
    if (!wpultra_roles_caps_are_admin(wpultra_roles_live_caps($slug) ?? [])) {
        return false; // not an admin role — deleting it can't orphan admins.
    }
    $map = wpultra_roles_live_map();
    $otherAdminRoles = 0;
    foreach ($map as $s => $def) {
        if (strtolower((string) $s) === $slug) { continue; }
        $caps = (is_array($def) && isset($def['capabilities'])) ? $def['capabilities'] : [];
        if (!empty(wpultra_roles_normalize_caps((array) $caps)['manage_options'] ?? false)) {
            $otherAdminRoles++;
        }
    }
    if ($otherAdminRoles > 0) { return false; }
    // This is the sole admin role. Refuse if anyone actually uses it.
    if (wpultra_roles_user_count($slug) > 0) {
        return "Refusing to delete '$slug' — it is the only role granting 'manage_options' and " . wpultra_roles_user_count($slug) . " user(s) rely on it. That would leave the site with no administrator.";
    }
    return false;
}
