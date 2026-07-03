<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Multisite network engine: list/create/update/delete sites and read/write network
 * (site) options. Every function below first checks is_multisite() and returns a
 * `not_multisite` WP_Error on a single-site install — none of this is reachable there.
 */

/** Shared guard: return a WP_Error when the install is not a multisite network, else null. */
function wpultra_ms_require_multisite() {
    if (!is_multisite()) {
        return wpultra_err('not_multisite', 'This install is not a WordPress multisite network. Enable multisite (WP_ALLOW_MULTISITE + network setup) before using network abilities.');
    }
    return null;
}

/**
 * Pure: build the {domain, path} pair wp_insert_site()/wp_initialize_site() expect.
 *
 * $input may be a bare slug ("shop") or a full domain ("shop.example.com" /
 * "shop.example.com/sub/"). A bare slug is expanded relative to the network's own
 * domain/path according to the subdomain_install flag; anything that already looks
 * like a domain (contains a dot, or isn't a valid path-segment identifier) passes
 * through as an explicit domain with path '/'.
 */
function wpultra_ms_new_site_args(string $input, string $network_domain, string $network_path, bool $subdomain_install): array {
    $input = trim($input);
    $input = trim($input, "/");
    $looks_like_domain = $input !== '' && (str_contains($input, '.') || str_contains($input, ':'));

    if ($looks_like_domain) {
        // Full domain (optionally with a path) passed straight through.
        $parts = explode('/', $input, 2);
        $domain = $parts[0];
        $path = '/' . trim($parts[1] ?? '', '/');
        $path = $path === '/' ? '/' : trailingslashit($path);
        return ['domain' => $domain, 'path' => $path];
    }

    $slug = trim($input, '/');
    $net_path = '/' . trim($network_path, '/');
    $net_path = $net_path === '/' ? '/' : trailingslashit($net_path);

    if ($subdomain_install) {
        return [
            'domain' => $slug . '.' . $network_domain,
            'path'   => $net_path,
        ];
    }

    // Subdirectory install: nest the slug under the network's own path.
    $base = $net_path === '/' ? '' : rtrim($net_path, '/');
    return [
        'domain' => $network_domain,
        'path'   => trailingslashit($base . '/' . $slug),
    ];
}

/** Pure: the only status fields site-update-status may toggle. */
function wpultra_ms_valid_status_field(string $field): bool {
    return in_array($field, ['archived', 'deleted', 'spam', 'public'], true);
}

/** @return array|WP_Error */
function wpultra_ms_sites_list() {
    $err = wpultra_ms_require_multisite();
    if ($err) { return $err; }

    $sites = get_sites();
    $rows = [];
    foreach ($sites as $site) {
        $blog_id = (int) $site->blog_id;
        $rows[] = [
            'blog_id'    => $blog_id,
            'url'        => get_site_url($blog_id),
            'title'      => get_blog_option($blog_id, 'blogname'),
            'registered' => (string) $site->registered,
            'archived'   => (bool) ((int) $site->archived),
            'deleted'    => (bool) ((int) $site->deleted),
        ];
    }
    return ['sites' => $rows, 'count' => count($rows)];
}

/** @return array|WP_Error */
function wpultra_ms_site_create(string $slug_or_domain, string $title, ?int $admin_user_id) {
    $err = wpultra_ms_require_multisite();
    if ($err) { return $err; }

    $slug_or_domain = trim($slug_or_domain);
    if ($slug_or_domain === '') { return wpultra_err('missing_slug', 'slug is required.'); }
    if ($title === '') { return wpultra_err('missing_title', 'title is required.'); }

    $network = function_exists('get_network') ? get_network() : null;
    $network_domain = $network ? $network->domain : (string) wp_parse_url(network_home_url(), PHP_URL_HOST);
    $network_path = $network ? $network->path : '/';
    $subdomain_install = is_subdomain_install();

    $args = wpultra_ms_new_site_args($slug_or_domain, $network_domain, $network_path, $subdomain_install);

    $user_id = $admin_user_id ?: get_current_user_id();
    if (!$user_id) { return wpultra_err('missing_admin', 'admin_user_id is required (no current user to default to).'); }

    $site_data = [
        'domain'     => $args['domain'],
        'path'       => $args['path'],
        'title'      => $title,
        'user_id'    => $user_id,
        'network_id' => $network ? (int) $network->id : get_current_network_id(),
    ];

    $blog_id = wp_insert_site($site_data);
    if (is_wp_error($blog_id)) { return $blog_id; }

    $init = wp_initialize_site($blog_id, ['title' => $title, 'user_id' => $user_id]);
    if (is_wp_error($init)) { return $init; }

    $blog_id = (int) $blog_id;
    wpultra_audit_log('network-site-create', "blog_id=$blog_id domain={$args['domain']} path={$args['path']}", true);
    return [
        'blog_id' => $blog_id,
        'url'     => get_site_url($blog_id),
        'domain'  => $args['domain'],
        'path'    => $args['path'],
    ];
}

/** @return array|WP_Error */
function wpultra_ms_site_update_status(int $blog_id, string $field, bool $value) {
    $err = wpultra_ms_require_multisite();
    if ($err) { return $err; }

    if ($blog_id <= 0) { return wpultra_err('missing_blog_id', 'blog_id is required.'); }
    if (!wpultra_ms_valid_status_field($field)) {
        return wpultra_err('bad_field', "field must be one of archived, deleted, spam, public (got '$field').");
    }
    if ($blog_id === 1 && in_array($field, ['archived', 'deleted'], true) && $value) {
        return wpultra_err('main_site_protected', 'Refusing to archive or soft-delete the main site (blog_id 1).');
    }
    if (!get_site((int) $blog_id)) { return wpultra_err('not_found', "No site with blog_id $blog_id."); }

    update_blog_status($blog_id, $field, $value ? '1' : '0');
    wpultra_audit_log('network-site-status', "blog_id=$blog_id $field=" . ($value ? '1' : '0'), true);
    return ['blog_id' => $blog_id, 'field' => $field, 'value' => $value];
}

/** @return array|WP_Error */
function wpultra_ms_site_delete(int $blog_id, bool $confirm) {
    $err = wpultra_ms_require_multisite();
    if ($err) { return $err; }

    if ($blog_id <= 0) { return wpultra_err('missing_blog_id', 'blog_id is required.'); }
    if ($blog_id === 1) { return wpultra_err('main_site_protected', 'Refusing to delete the main site (blog_id 1).'); }
    if (!$confirm) { return wpultra_err('confirm_required', 'Deleting a site is permanent. Re-run with confirm: true.'); }

    $site = get_site((int) $blog_id);
    if (!$site) { return wpultra_err('not_found', "No site with blog_id $blog_id."); }

    $result = wp_delete_site($blog_id);
    if (is_wp_error($result)) { return $result; }

    wpultra_audit_log('network-site-delete', "blog_id=$blog_id", true);
    return ['blog_id' => $blog_id, 'deleted' => true];
}

/** @return array|WP_Error */
function wpultra_ms_network_option_get(string $name) {
    $err = wpultra_ms_require_multisite();
    if ($err) { return $err; }

    if ($name === '') { return wpultra_err('missing_name', 'name is required.'); }
    if (function_exists('wpultra_option_is_sensitive') && wpultra_option_is_sensitive($name)) {
        return wpultra_err('sensitive_option', "Refusing to read sensitive network option '$name'.");
    }

    $default = new stdClass(); // sentinel to distinguish "unset" from a real null/false value
    $value = get_site_option($name, $default);
    $exists = $value !== $default;
    if (!$exists) { $value = null; }

    return ['name' => $name, 'exists' => $exists, 'value' => $value];
}

/** @return array|WP_Error */
function wpultra_ms_network_option_set(string $name, $value) {
    $err = wpultra_ms_require_multisite();
    if ($err) { return $err; }

    if ($name === '') { return wpultra_err('missing_name', 'name is required.'); }
    if (function_exists('wpultra_option_is_sensitive') && wpultra_option_is_sensitive($name)) {
        return wpultra_err('sensitive_option', "Refusing to write sensitive network option '$name'.");
    }

    $ok = update_site_option($name, $value);
    wpultra_audit_log('network-option-set', "name=$name", $ok !== false);
    return ['name' => $name, 'value' => $value, 'updated' => true];
}
