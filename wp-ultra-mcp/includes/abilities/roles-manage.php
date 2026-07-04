<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

// Defensively load the engine (pure fns + WP wrappers).
require_once __DIR__ . '/../system/roles.php';

wp_register_ability('wpultra/roles-manage', [
    'label'       => __('Manage Roles & Capabilities', 'wp-ultra-mcp'),
    'description' => __(
        'Create, edit, clone, reset and delete WordPress user roles and toggle their granular capabilities. '
        . 'Roles live in the wp_user_roles option; each role is a slug (a-z0-9_-) plus a display name and a '
        . 'map of capability => bool.'
        . "\n\n"
        . 'actions:'
        . "\n- list — every role with its user count and cap count."
        . "\n- get {slug} — one role's caps, grouped by the capability catalog."
        . "\n- create {slug, name, caps?, from_slug?} — add a role. caps may be a list or a cap=>bool map; "
        . 'if from_slug is given the new role starts from that base role\'s caps.'
        . "\n- clone {from_slug, slug, name} — copy an existing role\'s caps into a new slug."
        . "\n- update {slug, caps} — REPLACE the role\'s cap set. Guarded against admin lockout. "
        . 'confirm:true required when the role currently has users.'
        . "\n- add-cap {slug, cap} / remove-cap {slug, cap} — toggle a single capability."
        . "\n- delete {slug, confirm:true, reassign_to?} — remove a role. Core roles are protected; the "
        . 'last-administrator role is protected; confirm-gated when the role has users. reassign_to (a '
        . 'fallback role slug) is documented but reassignment of the affected users is left to manage-user.'
        . "\n- reset-role {slug, confirm:true} — restore a CORE role to its WordPress default caps."
        . "\n- cap-catalog — the grouped catalog of common caps (content, media, users, plugins, themes, core, woocommerce)."
        . "\n\n"
        . 'SAFETY: never strips manage_options from administrator; never leaves zero roles with manage_options; '
        . 'never deletes the sole admin role while users depend on it. Every mutation is confirm-gated when it '
        . 'affects a role that currently has users, and is audit-logged with the added/removed cap diff.'
        . "\n\n"
        . 'examples: {action:"create", slug:"shop_manager2", name:"Shop Manager 2", from_slug:"editor"}; '
        . '{action:"add-cap", slug:"editor", cap:"manage_woocommerce"}; '
        . '{action:"update", slug:"contributor", caps:["read","edit_posts","upload_files"], confirm:true}; '
        . '{action:"delete", slug:"old_role", confirm:true}.',
        'wp-ultra-mcp'
    ),
    'category'    => 'users',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action'      => ['type' => 'string', 'enum' => ['list', 'get', 'create', 'update', 'delete', 'clone', 'add-cap', 'remove-cap', 'cap-catalog', 'reset-role'], 'default' => 'list'],
            'slug'        => ['type' => 'string'],
            'name'        => ['type' => 'string'],
            // A cap list ["read","edit_posts"] OR a cap=>bool map {"read":true};
            // the engine's normalize_caps accepts both, so the schema must too.
            'caps'        => ['type' => ['array', 'object']],
            'from_slug'   => ['type' => 'string'],
            'cap'         => ['type' => 'string'],
            'reassign_to' => ['type' => 'string'],
            'confirm'     => ['type' => 'boolean'],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_roles_manage_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

/**
 * Default cap sets for WordPress core roles — used by reset-role. Mirrors the
 * caps populate_roles() installs on a fresh site.
 */
function wpultra_roles_core_defaults(): array {
    $subscriber  = ['read' => true];
    $contributor = $subscriber + ['edit_posts' => true, 'delete_posts' => true];
    $author      = $contributor + [
        'upload_files' => true, 'publish_posts' => true,
        'edit_published_posts' => true, 'delete_published_posts' => true,
    ];
    $editor = $author + [
        'moderate_comments' => true, 'manage_categories' => true, 'manage_links' => true,
        'edit_others_posts' => true, 'edit_private_posts' => true, 'read_private_posts' => true,
        'delete_others_posts' => true, 'delete_private_posts' => true, 'unfiltered_html' => true,
        'edit_pages' => true, 'edit_others_pages' => true, 'edit_published_pages' => true,
        'edit_private_pages' => true, 'read_private_pages' => true,
        'publish_pages' => true, 'delete_pages' => true, 'delete_others_pages' => true,
        'delete_published_pages' => true, 'delete_private_pages' => true,
    ];
    $administrator = $editor + [
        'switch_themes' => true, 'edit_themes' => true, 'activate_plugins' => true, 'edit_plugins' => true,
        'edit_users' => true, 'edit_files' => true, 'manage_options' => true, 'import' => true,
        'list_users' => true, 'remove_users' => true, 'add_users' => true, 'promote_users' => true,
        'edit_theme_options' => true, 'delete_themes' => true, 'export' => true, 'delete_users' => true,
        'create_users' => true, 'install_plugins' => true, 'update_plugins' => true, 'delete_plugins' => true,
        'install_themes' => true, 'update_themes' => true, 'update_core' => true, 'edit_dashboard' => true,
        'customize' => true, 'delete_site' => true,
    ];
    return [
        'subscriber'    => $subscriber,
        'contributor'   => $contributor,
        'author'        => $author,
        'editor'        => $editor,
        'administrator' => $administrator,
    ];
}

function wpultra_roles_manage_cb(array $input) {
    $action = (string) ($input['action'] ?? 'list');
    $confirm = ($input['confirm'] ?? false) === true;

    if (!function_exists('wp_roles')) {
        return wpultra_err('no_roles_api', 'The WordPress roles API is unavailable in this context.');
    }

    switch ($action) {
        case 'cap-catalog':
            return wpultra_ok(['catalog' => wpultra_roles_cap_catalog()]);

        case 'list':
            return wpultra_ok(['roles' => wpultra_roles_manage_list()]);

        case 'get':
            return wpultra_roles_manage_get((string) ($input['slug'] ?? ''));

        case 'create':
            return wpultra_roles_manage_create($input);

        case 'clone':
            return wpultra_roles_manage_clone($input);

        case 'update':
            return wpultra_roles_manage_update($input, $confirm);

        case 'add-cap':
        case 'remove-cap':
            return wpultra_roles_manage_toggle_cap($input, $action === 'add-cap', $confirm);

        case 'delete':
            return wpultra_roles_manage_delete($input, $confirm);

        case 'reset-role':
            return wpultra_roles_manage_reset($input, $confirm);
    }

    return wpultra_err('bad_action', "Unknown action '$action'.");
}

function wpultra_roles_manage_list(): array {
    $out = [];
    foreach (wpultra_roles_live_map() as $slug => $def) {
        $caps = (is_array($def) && isset($def['capabilities'])) ? (array) $def['capabilities'] : [];
        $granted = array_filter(wpultra_roles_normalize_caps($caps));
        $out[] = [
            'slug'       => (string) $slug,
            'name'       => (string) ($def['name'] ?? $slug),
            'protected'  => wpultra_roles_is_protected((string) $slug),
            'users'      => wpultra_roles_user_count((string) $slug),
            'cap_count'  => count($granted),
            'is_admin'   => wpultra_roles_caps_are_admin($caps),
        ];
    }
    return $out;
}

function wpultra_roles_manage_get(string $slug) {
    $slug = strtolower(trim($slug));
    if ($slug === '') { return wpultra_err('missing_slug', 'slug is required.'); }
    $caps = wpultra_roles_live_caps($slug);
    if ($caps === null) { return wpultra_err('not_found', "No role with slug '$slug'."); }
    $map = wpultra_roles_live_map();
    return wpultra_ok([
        'slug'    => $slug,
        'name'    => (string) ($map[$slug]['name'] ?? $slug),
        'caps'    => wpultra_roles_normalize_caps($caps),
        'grouped' => wpultra_roles_group_caps($caps),
        'users'   => wpultra_roles_user_count($slug),
    ]);
}

function wpultra_roles_manage_create(array $input) {
    $slug = strtolower(trim((string) ($input['slug'] ?? '')));
    $name = trim((string) ($input['name'] ?? ''));
    if ($slug === '' || $name === '') { return wpultra_err('missing_fields', 'create requires slug and name.'); }
    if (!wpultra_roles_valid_slug($slug)) { return wpultra_err('bad_slug', "Invalid slug '$slug' — use a-z, 0-9, _ and - (start with a letter or digit)."); }
    if (wpultra_roles_live_caps($slug) !== null) { return wpultra_err('exists', "Role '$slug' already exists."); }

    // Base caps: from_slug if given, else the provided caps, else empty.
    $caps = [];
    $from = strtolower(trim((string) ($input['from_slug'] ?? '')));
    if ($from !== '') {
        $base = wpultra_roles_live_caps($from);
        if ($base === null) { return wpultra_err('bad_from', "from_slug '$from' does not exist."); }
        $caps = wpultra_roles_normalize_caps($base);
    }
    if (isset($input['caps']) && is_array($input['caps'])) {
        $caps = array_merge($caps, wpultra_roles_normalize_caps($input['caps']));
    }

    $err = wpultra_roles_validate_caps($caps);
    if ($err !== null) { return $err; }

    // Guard: creating an admin role never breaks the "zero admin" rule, but keep
    // the check for symmetry / future edits.
    $guard = wpultra_roles_guard_admin_caps($slug, $caps, wpultra_roles_live_map());
    if ($guard !== true) { return wpultra_err('admin_guard', $guard); }

    $granted = array_keys(array_filter($caps));
    $role = add_role($slug, $name, $caps);
    if ($role === null && wpultra_roles_live_caps($slug) === null) {
        return wpultra_err('create_failed', "add_role() returned null for '$slug'.");
    }
    $diff = wpultra_roles_cap_diff([], $caps);
    wpultra_audit_log('roles-manage', "create role $slug (+" . count($diff['added']) . ' caps)', true);
    return wpultra_ok(['slug' => $slug, 'name' => $name, 'caps' => $caps, 'diff' => $diff, 'cap_count' => count($granted)]);
}

function wpultra_roles_manage_clone(array $input) {
    $from = strtolower(trim((string) ($input['from_slug'] ?? '')));
    if ($from === '') { return wpultra_err('missing_from', 'clone requires from_slug.'); }
    if (wpultra_roles_live_caps($from) === null) { return wpultra_err('bad_from', "from_slug '$from' does not exist."); }
    // Delegate to create with from_slug set.
    $input['from_slug'] = $from;
    return wpultra_roles_manage_create($input);
}

function wpultra_roles_manage_update(array $input, bool $confirm) {
    $slug = strtolower(trim((string) ($input['slug'] ?? '')));
    if ($slug === '') { return wpultra_err('missing_slug', 'update requires slug.'); }
    $before = wpultra_roles_live_caps($slug);
    if ($before === null) { return wpultra_err('not_found', "No role with slug '$slug'."); }
    if (!isset($input['caps']) || !is_array($input['caps'])) { return wpultra_err('missing_caps', 'update requires caps (a list or cap=>bool map).'); }

    $after = wpultra_roles_normalize_caps($input['caps']);
    $err = wpultra_roles_validate_caps($after);
    if ($err !== null) { return $err; }

    $guard = wpultra_roles_guard_admin_caps($slug, $after, wpultra_roles_live_map());
    if ($guard !== true) { return wpultra_err('admin_guard', $guard); }

    $users = wpultra_roles_user_count($slug);
    if ($users > 0 && !$confirm) {
        return wpultra_err('needs_confirm', "Role '$slug' has $users user(s). Re-run with confirm:true to apply the change.");
    }

    $role = get_role($slug);
    $beforeN = wpultra_roles_normalize_caps($before);
    // Remove caps that are gone or now false; add/keep the rest.
    foreach ($beforeN as $cap => $on) {
        if (empty($after[$cap])) { $role->remove_cap($cap); }
    }
    foreach ($after as $cap => $on) {
        if ($on) { $role->add_cap($cap); } else { $role->remove_cap($cap); }
    }

    $diff = wpultra_roles_cap_diff($before, $after);
    wpultra_audit_log('roles-manage', "update role $slug (+" . count($diff['added']) . '/-' . count($diff['removed']) . ')', true);
    return wpultra_ok(['slug' => $slug, 'caps' => $after, 'diff' => $diff]);
}

function wpultra_roles_manage_toggle_cap(array $input, bool $add, bool $confirm) {
    $slug = strtolower(trim((string) ($input['slug'] ?? '')));
    $cap  = trim((string) ($input['cap'] ?? ''));
    if ($slug === '' || $cap === '') { return wpultra_err('missing_fields', 'add-cap/remove-cap require slug and cap.'); }
    if (!wpultra_roles_valid_cap($cap)) { return wpultra_err('bad_cap', "Invalid capability name '$cap'."); }
    $before = wpultra_roles_live_caps($slug);
    if ($before === null) { return wpultra_err('not_found', "No role with slug '$slug'."); }

    $after = wpultra_roles_normalize_caps($before);
    if ($add) { $after[$cap] = true; } else { unset($after[$cap]); }

    // Removing a cap can trip the admin-lockout guard (e.g. remove manage_options from admin).
    if (!$add) {
        $guard = wpultra_roles_guard_admin_caps($slug, $after, wpultra_roles_live_map());
        if ($guard !== true) { return wpultra_err('admin_guard', $guard); }
    }

    $users = wpultra_roles_user_count($slug);
    if ($users > 0 && !$confirm) {
        return wpultra_err('needs_confirm', "Role '$slug' has $users user(s). Re-run with confirm:true to toggle the capability.");
    }

    $role = get_role($slug);
    if ($add) { $role->add_cap($cap); } else { $role->remove_cap($cap); }

    $diff = wpultra_roles_cap_diff($before, $after);
    wpultra_audit_log('roles-manage', ($add ? 'add-cap ' : 'remove-cap ') . "$cap on $slug", true);
    return wpultra_ok(['slug' => $slug, 'cap' => $cap, 'granted' => $add, 'diff' => $diff]);
}

function wpultra_roles_manage_delete(array $input, bool $confirm) {
    $slug = strtolower(trim((string) ($input['slug'] ?? '')));
    if ($slug === '') { return wpultra_err('missing_slug', 'delete requires slug.'); }
    if (wpultra_roles_live_caps($slug) === null) { return wpultra_err('not_found', "No role with slug '$slug'."); }
    if (wpultra_roles_is_protected($slug)) { return wpultra_err('protected', "Role '$slug' is a protected WordPress core role and cannot be deleted."); }

    // Live last-administrator guard.
    $orphan = wpultra_roles_would_orphan_admins($slug);
    if ($orphan !== false) { return wpultra_err('last_admin', is_string($orphan) ? $orphan : 'Deleting this role would orphan admin access.'); }

    $users = wpultra_roles_user_count($slug);
    if ($users > 0 && !$confirm) {
        $note = '';
        $reassign = strtolower(trim((string) ($input['reassign_to'] ?? '')));
        if ($reassign !== '') { $note = " Note: reassign_to='$reassign' is recorded but user reassignment must be done via manage-user."; }
        return wpultra_err('needs_confirm', "Role '$slug' has $users user(s). Re-run with confirm:true to delete it.$note");
    }
    if (!$confirm) {
        return wpultra_err('needs_confirm', "Deleting a role is destructive. Re-run with confirm:true.");
    }

    remove_role($slug);
    wpultra_audit_log('roles-manage', "delete role $slug (had $users users)", true);
    return wpultra_ok(['slug' => $slug, 'deleted' => true, 'had_users' => $users]);
}

function wpultra_roles_manage_reset(array $input, bool $confirm) {
    $slug = strtolower(trim((string) ($input['slug'] ?? '')));
    if ($slug === '') { return wpultra_err('missing_slug', 'reset-role requires slug.'); }
    $defaults = wpultra_roles_core_defaults();
    if (!isset($defaults[$slug])) { return wpultra_err('not_core', "reset-role only restores WordPress core roles (" . implode(', ', array_keys($defaults)) . ")."); }
    if (!$confirm) { return wpultra_err('needs_confirm', "Resetting '$slug' to its default caps is destructive. Re-run with confirm:true."); }

    $before = wpultra_roles_live_caps($slug) ?? [];
    $after  = $defaults[$slug];

    $role = get_role($slug);
    if (!$role) {
        // Role missing entirely — recreate it from defaults.
        $names = ['subscriber' => 'Subscriber', 'contributor' => 'Contributor', 'author' => 'Author', 'editor' => 'Editor', 'administrator' => 'Administrator'];
        add_role($slug, $names[$slug] ?? ucfirst($slug), $after);
    } else {
        foreach (wpultra_roles_normalize_caps($before) as $cap => $on) {
            if (empty($after[$cap])) { $role->remove_cap($cap); }
        }
        foreach ($after as $cap => $on) {
            if ($on) { $role->add_cap($cap); }
        }
    }

    $diff = wpultra_roles_cap_diff($before, $after);
    wpultra_audit_log('roles-manage', "reset-role $slug (+" . count($diff['added']) . '/-' . count($diff['removed']) . ')', true);
    return wpultra_ok(['slug' => $slug, 'caps' => $after, 'diff' => $diff]);
}

/** Validate every cap key in a caps map; returns a WP_Error or null. */
function wpultra_roles_validate_caps(array $caps) {
    foreach (array_keys($caps) as $cap) {
        if (!wpultra_roles_valid_cap((string) $cap)) {
            return wpultra_err('bad_cap', "Invalid capability name '$cap' — use letters, digits and underscores.");
        }
    }
    return null;
}
