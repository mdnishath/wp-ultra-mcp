<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/** Users engine: list/get/create/update/delete + role changes, with an escalation guard. */

/** Pure: roles that grant site-wide control and must not be assigned without explicit opt-in. */
function wpultra_user_privileged_roles(): array {
    return ['administrator', 'super-admin'];
}

/** Pure: does this role/capability set require an explicit allow_admin opt-in? */
function wpultra_user_role_is_privileged(string $role): bool {
    return in_array(strtolower(trim($role)), wpultra_user_privileged_roles(), true);
}

/** Shape a user for output (no password hashes). */
function wpultra_user_shape(int $id): array {
    $u = get_userdata($id);
    if (!$u) { return ['id' => $id]; }
    return [
        'id'           => $u->ID,
        'login'        => $u->user_login,
        'email'        => $u->user_email,
        'display_name' => $u->display_name,
        'roles'        => array_values($u->roles),
        'registered'   => $u->user_registered,
    ];
}

/** @return array|WP_Error */
function wpultra_user_list(int $per_page, int $page, string $role, string $search) {
    $args = ['number' => max(1, min(200, $per_page)), 'paged' => max(1, $page), 'fields' => 'ID'];
    if ($role !== '')   { $args['role'] = $role; }
    if ($search !== '') { $args['search'] = '*' . $search . '*'; }
    $ids = get_users($args);
    $rows = array_map(fn($id) => wpultra_user_shape((int) $id), $ids);
    return ['users' => $rows, 'count' => count($rows)];
}

/** @return array|WP_Error */
function wpultra_user_create(array $in, bool $allow_admin) {
    $login = sanitize_user((string) ($in['login'] ?? ''), true);
    $email = sanitize_email((string) ($in['email'] ?? ''));
    if ($login === '') { return wpultra_err('missing_login', 'login is required.'); }
    if ($email === '' || !is_email($email)) { return wpultra_err('bad_email', 'A valid email is required.'); }
    $role = (string) ($in['role'] ?? get_option('default_role', 'subscriber'));
    if (wpultra_user_role_is_privileged($role) && !$allow_admin) {
        return wpultra_err('escalation_blocked', "Assigning '$role' needs allow_admin: true.");
    }
    $password = (string) ($in['password'] ?? wp_generate_password(20));
    $id = wp_insert_user(wp_slash([
        'user_login'   => $login,
        'user_email'   => $email,
        'user_pass'    => $password,
        'display_name' => (string) ($in['display_name'] ?? $login),
        'first_name'   => (string) ($in['first_name'] ?? ''),
        'last_name'    => (string) ($in['last_name'] ?? ''),
        'role'         => $role,
    ]));
    if (is_wp_error($id)) { return $id; }
    return wpultra_user_shape((int) $id);
}

/** @return array|WP_Error */
function wpultra_user_update(array $in, bool $allow_admin) {
    $id = (int) ($in['id'] ?? 0);
    if (!get_userdata($id)) { return wpultra_err('not_found', "No user with id $id."); }
    $data = ['ID' => $id];
    foreach (['email' => 'user_email', 'display_name' => 'display_name', 'first_name' => 'first_name', 'last_name' => 'last_name'] as $k => $col) {
        if (isset($in[$k])) { $data[$col] = (string) $in[$k]; }
    }
    if (isset($in['password']) && $in['password'] !== '') { $data['user_pass'] = (string) $in['password']; }
    if (isset($in['role'])) {
        $role = (string) $in['role'];
        if (wpultra_user_role_is_privileged($role) && !$allow_admin) {
            return wpultra_err('escalation_blocked', "Assigning '$role' needs allow_admin: true.");
        }
        $data['role'] = $role;
    }
    $res = wp_update_user(wp_slash($data));
    if (is_wp_error($res)) { return $res; }
    if (!empty($in['meta']) && is_array($in['meta'])) {
        foreach ($in['meta'] as $mk => $mv) { update_user_meta($id, (string) $mk, wp_slash($mv)); }
    }
    return wpultra_user_shape($id);
}

/** Shape a user for the list-users ability: adds post_count on top of the base shape. Pure given a userdata-like object is not possible (needs WP lookups), so this is a thin WP-calling wrapper. */
function wpultra_users_list_shape(int $id): array {
    $base = wpultra_user_shape($id);
    if (!isset($base['login'])) { return $base; }
    $base['post_count'] = function_exists('count_user_posts') ? (int) count_user_posts($id) : 0;
    return $base;
}

/** @return array|WP_Error */
function wpultra_users_list(array $q) {
    $per_page = max(1, min(200, (int) ($q['per_page'] ?? 20)));
    $page     = max(1, (int) ($q['page'] ?? 1));
    $args = ['number' => $per_page, 'paged' => $page, 'fields' => 'ID'];
    if (!empty($q['role']))   { $args['role'] = (string) $q['role']; }
    if (!empty($q['search'])) { $args['search'] = '*' . (string) $q['search'] . '*'; }

    $query = new WP_User_Query($args);
    $ids = (array) $query->get_results();
    $rows = array_map(static fn($id) => wpultra_users_list_shape((int) $id), $ids);

    return [
        'users' => $rows,
        'total' => (int) $query->get_total(),
        'pages' => (int) ceil(max(1, (int) $query->get_total()) / $per_page),
    ];
}

/** @return array|WP_Error */
function wpultra_user_delete(int $id, int $reassign_to) {
    if (!get_userdata($id)) { return wpultra_err('not_found', "No user with id $id."); }
    if ($id === get_current_user_id()) { return wpultra_err('cannot_delete_self', 'Refusing to delete the acting user.'); }
    // Never delete the last administrator.
    if (in_array('administrator', (array) get_userdata($id)->roles, true)) {
        $admins = get_users(['role' => 'administrator', 'fields' => 'ID', 'number' => 2]);
        if (count($admins) <= 1) { return wpultra_err('last_admin', 'Refusing to delete the last administrator.'); }
    }
    if (!function_exists('wp_delete_user')) { require_once ABSPATH . 'wp-admin/includes/user.php'; }
    $ok = $reassign_to > 0 ? wp_delete_user($id, $reassign_to) : wp_delete_user($id);
    if (!$ok) { return wpultra_err('delete_failed', "Could not delete user $id."); }
    return ['id' => $id, 'deleted' => true];
}
