<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * White-label / client mode engine (Roadmap G5).
 *
 * COSMETIC rebranding for client-facing polish only. This changes the admin
 * *appearance* of WP-Ultra-MCP (menu label, admin-bar, footer, login logo) and
 * can hide the plugin's admin menus / plugins-list row from non-privileged
 * roles ("client mode"). It is NOT a license or authorship hider: the plugin
 * stays GPL, its readme.txt / LICENSE / author headers are untouched, and any
 * admin who opens the Plugins screen with sufficient privileges still sees the
 * real thing. Do not represent this as a way to strip GPL attribution.
 *
 * Layering, as required by the repo:
 *   PURE helpers first (prefix wpultra_wlabel_ — the wishlist engine already
 *   owns wpultra_wl_, so we never collide with it). No WordPress calls; these
 *   are the tested core.
 *   WP wrappers after, each guarded by function_exists(), and only wired up by
 *   wpultra_wlabel_boot() which the controller calls on plugins_loaded.
 */

if (!defined('WPULTRA_WHITELABEL_OPTION')) {
    define('WPULTRA_WHITELABEL_OPTION', 'wpultra_whitelabel');
}

/* =====================================================================
 * PURE — defaults, merge, validation.
 * ===================================================================== */

/**
 * PURE. The WP-Ultra-MCP original branding — what every field falls back to
 * when the white-label config leaves it blank. reset() returns to this.
 *
 * @return array<string,mixed>
 */
function wpultra_wlabel_defaults(): array {
    return [
        'enabled' => false,
        'brand'   => [
            'plugin_name'       => 'WP Ultra MCP',
            'menu_title'        => 'WP Ultra MCP',
            'vendor_name'       => 'WP Ultra MCP',
            'vendor_url'        => '',
            'admin_footer_text' => '',
            'hide_wp_logo'      => false,
            'login_logo_url'    => '',
        ],
        'client_mode' => [
            'enabled'          => false,
            'allowed_roles'    => [],
            'hide_menus'       => [],
            'hide_plugin_from' => [],
        ],
    ];
}

/**
 * PURE. Roles that are always treated as trusted operators and are NEVER
 * restricted by client mode, no matter the allowed_roles list. Baking this in
 * prevents an admin from locking themselves (or the site owner) out of the
 * plugin by mis-configuring client mode.
 *
 * @return array<int,string>
 */
function wpultra_wlabel_privileged_roles(): array {
    return ['administrator', 'super_admin'];
}

/** PURE. Sanitize a single-line brand string: strip tags/controls, collapse WS, cap length. */
function wpultra_wlabel_clean_text(string $value, int $max = 120): string {
    $value = strip_tags($value);
    $value = preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $value) ?? '';
    $value = preg_replace('/\s+/u', ' ', $value) ?? '';
    $value = trim($value);
    if ($max > 0 && function_exists('mb_substr')) { $value = mb_substr($value, 0, $max); }
    elseif ($max > 0) { $value = substr($value, 0, $max); }
    return $value;
}

/**
 * PURE. Shape-validate a URL the way esc_url_raw would broadly accept it:
 * must be http(s) (or protocol-relative //), no spaces/control chars, no
 * javascript:/data: scheme. Returns '' for anything that fails so a bad value
 * simply disables that visual rather than injecting markup.
 */
function wpultra_wlabel_clean_url(string $url): string {
    $url = trim($url);
    if ($url === '') { return ''; }
    if (preg_match('/[\x00-\x1f\x7f<>"\s]/', $url)) { return ''; }
    if (preg_match('#^//[^/]#', $url)) { return $url; }                 // protocol-relative
    if (!preg_match('#^https?://[^/].*#i', $url)) { return ''; }        // must have a host
    return $url;
}

/**
 * PURE. A slug is a WordPress admin menu slug or page hook — accept plain
 * slugs and admin.php?page=… style strings; reject anything with markup/control
 * chars. Used for hide_menus. Returns cleaned string or '' if unusable.
 */
function wpultra_wlabel_clean_slug(string $slug): string {
    $slug = trim($slug);
    if ($slug === '') { return ''; }
    if (!preg_match('#^[A-Za-z0-9_./?=&%-]+$#', $slug)) { return ''; }
    return $slug;
}

/**
 * PURE. Merge a partial white-label config over the defaults, so every field is
 * always present and typed. Unknown keys are dropped. Missing brand fields keep
 * the WP-Ultra-MCP originals. Roles/slugs are normalized to clean string lists.
 *
 * @param array<string,mixed> $config
 * @return array<string,mixed>
 */
function wpultra_wlabel_merge_config(array $config): array {
    $def = wpultra_wlabel_defaults();

    $out = $def;
    $out['enabled'] = !empty($config['enabled']);

    $brand = is_array($config['brand'] ?? null) ? $config['brand'] : [];
    $out['brand'] = [
        'plugin_name'       => wpultra_wlabel_clean_text((string) ($brand['plugin_name'] ?? '')) ?: $def['brand']['plugin_name'],
        'menu_title'        => wpultra_wlabel_clean_text((string) ($brand['menu_title'] ?? '')) ?: $def['brand']['menu_title'],
        'vendor_name'       => wpultra_wlabel_clean_text((string) ($brand['vendor_name'] ?? '')) ?: $def['brand']['vendor_name'],
        'vendor_url'        => wpultra_wlabel_clean_url((string) ($brand['vendor_url'] ?? '')),
        'admin_footer_text' => wpultra_wlabel_clean_text((string) ($brand['admin_footer_text'] ?? ''), 200),
        'hide_wp_logo'      => !empty($brand['hide_wp_logo']),
        'login_logo_url'    => wpultra_wlabel_clean_url((string) ($brand['login_logo_url'] ?? '')),
    ];

    $cm = is_array($config['client_mode'] ?? null) ? $config['client_mode'] : [];
    $out['client_mode'] = [
        'enabled'          => !empty($cm['enabled']),
        'allowed_roles'    => wpultra_wlabel_clean_role_list($cm['allowed_roles'] ?? []),
        'hide_menus'       => wpultra_wlabel_clean_slug_list($cm['hide_menus'] ?? []),
        'hide_plugin_from' => wpultra_wlabel_clean_role_list($cm['hide_plugin_from'] ?? []),
    ];

    return $out;
}

/**
 * PURE. Normalize a mixed value into a de-duplicated list of role-slug strings
 * (lowercase, [a-z0-9_-]). Non-conforming entries are dropped.
 *
 * @param mixed $roles
 * @return array<int,string>
 */
function wpultra_wlabel_clean_role_list($roles): array {
    if (!is_array($roles)) { return []; }
    $out = [];
    foreach ($roles as $r) {
        if (!is_string($r)) { continue; }
        $r = strtolower(trim($r));
        // WordPress role slugs start with a letter or underscore, then
        // [a-z0-9_-]. This also excludes purely-numeric strings, which would
        // otherwise coerce to integer array keys.
        if ($r === '' || !preg_match('/^[a-z_][a-z0-9_-]*$/', $r)) { continue; }
        if (!in_array($r, $out, true)) { $out[] = $r; }
    }
    return $out;
}

/**
 * PURE. Normalize a mixed value into a de-duplicated list of clean menu slugs.
 *
 * @param mixed $slugs
 * @return array<int,string>
 */
function wpultra_wlabel_clean_slug_list($slugs): array {
    if (!is_array($slugs)) { return []; }
    $out = [];
    foreach ($slugs as $s) {
        if (!is_string($s)) { continue; }
        $c = wpultra_wlabel_clean_slug($s);
        if ($c !== '') { $out[$c] = true; }
    }
    return array_keys($out);
}

/**
 * PURE. Validate a raw config patch, collecting human-readable warnings for
 * fields that were coerced/dropped (bad url, unknown-shaped role, non-string
 * slug). Returns ['config' => merged, 'warnings' => [...]]. Nothing here throws
 * — invalid values are neutralized (url -> '', bad role/slug -> dropped) and a
 * warning explains why, so a partial config never injects markup or crashes.
 *
 * @param array<string,mixed> $patch
 * @return array{config:array<string,mixed>,warnings:array<int,string>}
 */
function wpultra_wlabel_validate(array $patch): array {
    $warnings = [];

    $brand = is_array($patch['brand'] ?? null) ? $patch['brand'] : [];
    foreach (['vendor_url' => 'vendor_url', 'login_logo_url' => 'login_logo_url'] as $key => $label) {
        if (isset($brand[$key]) && (string) $brand[$key] !== '' && wpultra_wlabel_clean_url((string) $brand[$key]) === '') {
            $warnings[] = "brand.$label is not a valid http(s) URL and was cleared.";
        }
    }

    $cm = is_array($patch['client_mode'] ?? null) ? $patch['client_mode'] : [];
    foreach (['allowed_roles', 'hide_plugin_from'] as $rk) {
        if (isset($cm[$rk]) && is_array($cm[$rk])) {
            $kept = wpultra_wlabel_clean_role_list($cm[$rk]);
            $dropped = count($cm[$rk]) - count($kept);
            if ($dropped > 0) { $warnings[] = "client_mode.$rk dropped $dropped malformed role entr" . ($dropped === 1 ? 'y' : 'ies') . '.'; }
        }
    }
    if (isset($cm['hide_menus']) && is_array($cm['hide_menus'])) {
        $kept = wpultra_wlabel_clean_slug_list($cm['hide_menus']);
        $dropped = count($cm['hide_menus']) - count($kept);
        if ($dropped > 0) { $warnings[] = "client_mode.hide_menus dropped $dropped malformed slug entr" . ($dropped === 1 ? 'y' : 'ies') . '.'; }
    }

    return ['config' => wpultra_wlabel_merge_config($patch), 'warnings' => $warnings];
}

/* =====================================================================
 * PURE — client-mode decisions.
 * ===================================================================== */

/**
 * PURE. Decide whether the current user should be RESTRICTED by client mode.
 *
 * Rules (baked-in, documented):
 *  - A user holding any privileged role (administrator / super_admin) is NEVER
 *    restricted — this prevents self-lockout even if allowed_roles omits admin.
 *  - Otherwise, restrict = the user's roles do NOT intersect allowed_roles.
 *  - Empty allowed_roles ⇒ restrict EVERY non-privileged user (the strict
 *    default: nobody but admins sees the plugin until you explicitly allow a
 *    role). This is intentional — an empty allow-list is a lock-down, not an
 *    open door.
 *
 * @param array<int,string> $user_roles
 * @param array<int,string> $allowed_roles
 */
function wpultra_wlabel_should_restrict(array $user_roles, array $allowed_roles): bool {
    $user_roles = wpultra_wlabel_clean_role_list($user_roles);
    if (array_intersect($user_roles, wpultra_wlabel_privileged_roles()) !== []) {
        return false; // privileged users are always exempt
    }
    $allowed = wpultra_wlabel_clean_role_list($allowed_roles);
    if ($allowed === []) {
        return true; // empty allow-list ⇒ lock down all non-privileged users
    }
    return array_intersect($user_roles, $allowed) === [];
}

/**
 * PURE. Given WordPress's $menu structure (list of rows where index 2 is the
 * menu slug) and a list of slugs to hide, return the filtered menu with the
 * matching rows removed. Non-listed rows are kept in order. An empty hide list
 * returns the menu unchanged.
 *
 * @param array<int|string,mixed> $menu
 * @param array<int,string>       $hide_slugs
 * @return array<int|string,mixed>
 */
function wpultra_wlabel_filter_menus(array $menu, array $hide_slugs): array {
    if ($hide_slugs === []) { return $menu; }
    $hide = array_fill_keys(array_map('strval', $hide_slugs), true);
    $out = [];
    foreach ($menu as $key => $row) {
        $slug = is_array($row) ? (string) ($row[2] ?? '') : '';
        if ($slug !== '' && isset($hide[$slug])) { continue; }
        $out[$key] = $row;
    }
    return $out;
}

/**
 * PURE. Relabel the menu row whose slug (index 2) equals $slug by setting its
 * title (index 0) to $new. Other rows are untouched; a missing slug leaves the
 * menu unchanged. Returns the new menu array (does not mutate the input).
 *
 * @param array<int|string,mixed> $menu
 * @return array<int|string,mixed>
 */
function wpultra_wlabel_relabel_menu(array $menu, string $slug, string $new): array {
    if ($slug === '' || $new === '') { return $menu; }
    foreach ($menu as $key => $row) {
        if (is_array($row) && (string) ($row[2] ?? '') === $slug) {
            $row[0] = $new;
            $menu[$key] = $row;
        }
    }
    return $menu;
}

/**
 * PURE. Compute the effective branding + a client-mode preview for a simulated
 * role, WITHOUT touching WordPress. Used by the ability's `preview` action.
 *
 * @param array<string,mixed> $config        already-merged config
 * @param array<int,string>   $sim_roles     roles to simulate (e.g. ['editor'])
 * @return array<string,mixed>
 */
function wpultra_wlabel_preview(array $config, array $sim_roles): array {
    $config = wpultra_wlabel_merge_config($config);
    $cm = $config['client_mode'];

    $restricted = false;
    $would_hide_menus = [];
    $would_hide_plugin = false;
    if (!empty($cm['enabled'])) {
        $restricted = wpultra_wlabel_should_restrict($sim_roles, $cm['allowed_roles']);
        if ($restricted) {
            $would_hide_menus = $cm['hide_menus'];
            $would_hide_plugin = array_intersect(
                wpultra_wlabel_clean_role_list($sim_roles),
                $cm['hide_plugin_from']
            ) !== [];
        }
    }

    return [
        'enabled'            => !empty($config['enabled']),
        'brand'              => $config['brand'],
        'simulated_roles'    => wpultra_wlabel_clean_role_list($sim_roles),
        'client_mode_active' => !empty($cm['enabled']),
        'restricted'         => $restricted,
        'would_hide_menus'   => $would_hide_menus,
        'would_hide_plugin'  => $would_hide_plugin,
    ];
}

/* =====================================================================
 * WP wrappers — option access + boot. All guarded by function_exists().
 * ===================================================================== */

/**
 * Read the stored white-label config, merged over defaults. Cheap: a single
 * get_option. Outside WordPress (tests) returns the defaults.
 *
 * @return array<string,mixed>
 */
function wpultra_wlabel_get_config(): array {
    $raw = function_exists('get_option') ? get_option(WPULTRA_WHITELABEL_OPTION, []) : [];
    if (!is_array($raw)) { $raw = []; }
    return wpultra_wlabel_merge_config($raw);
}

/** Persist a merged config. Returns the stored config. */
function wpultra_wlabel_save_config(array $config): array {
    $merged = wpultra_wlabel_merge_config($config);
    if (function_exists('update_option')) {
        update_option(WPULTRA_WHITELABEL_OPTION, $merged, false);
    }
    return $merged;
}

/** The current user's role slugs (empty outside WordPress / logged-out). */
function wpultra_wlabel_current_user_roles(): array {
    if (!function_exists('wp_get_current_user')) { return []; }
    $user = wp_get_current_user();
    if (!$user || empty($user->roles) || !is_array($user->roles)) { return []; }
    return wpultra_wlabel_clean_role_list($user->roles);
}

/** Our plugin's basename for the plugins-list filter. */
function wpultra_wlabel_plugin_basename(): string {
    if (defined('WPULTRA_FILE') && function_exists('plugin_basename')) {
        return plugin_basename(WPULTRA_FILE);
    }
    return 'wp-ultra-mcp/wp-ultra-mcp.php';
}

/**
 * Runtime contract: the controller calls this on plugins_loaded. When
 * white-label is enabled, wire the admin-side rebrand filters and (if client
 * mode is on) the role restrictions. We only hook admin-side things when
 * is_admin() so front-end requests stay untouched, EXCEPT the login logo which
 * lives on the login page (also technically wp-admin/wp-login.php).
 */
function wpultra_wlabel_boot(): void {
    $config = wpultra_wlabel_get_config();
    if (empty($config['enabled'])) { return; }

    $brand = $config['brand'];

    // Login logo — fires on wp-login.php (login_head). Cheap to always register;
    // the callback no-ops without a URL.
    if ($brand['login_logo_url'] !== '' && function_exists('add_action')) {
        add_action('login_head', 'wpultra_wlabel_render_login_logo');
    }

    $admin = function_exists('is_admin') ? is_admin() : false;
    if (!$admin) { return; }

    // Admin footer text.
    if ($brand['admin_footer_text'] !== '' && function_exists('add_filter')) {
        add_filter('admin_footer_text', 'wpultra_wlabel_admin_footer_text');
    }

    // Admin bar: drop the WP logo node.
    if (!empty($brand['hide_wp_logo']) && function_exists('add_action')) {
        add_action('admin_bar_menu', 'wpultra_wlabel_strip_wp_logo', 999);
    }

    // Menu relabel + client-mode menu/plugin hiding: both act on the admin menu.
    if (function_exists('add_action')) {
        add_action('admin_menu', 'wpultra_wlabel_apply_admin_menu', 999);
    }
    $cm = $config['client_mode'];
    if (!empty($cm['enabled']) && function_exists('add_filter')) {
        add_filter('all_plugins', 'wpultra_wlabel_filter_plugins_list');
    }
}

/** login_head callback: inline CSS swapping the login logo. URL already validated. */
function wpultra_wlabel_render_login_logo(): void {
    $config = wpultra_wlabel_get_config();
    $url = $config['brand']['login_logo_url'];
    if ($url === '') { return; }
    $safe = function_exists('esc_url') ? esc_url($url) : $url;
    echo "<style>#login h1 a{background-image:url('" . $safe . "') !important;background-size:contain !important;width:auto !important;}</style>\n";
}

/** admin_footer_text filter: replace with the branded string (escaped). */
function wpultra_wlabel_admin_footer_text($text) {
    $config = wpultra_wlabel_get_config();
    $custom = $config['brand']['admin_footer_text'];
    if ($custom === '') { return $text; }
    return function_exists('esc_html') ? esc_html($custom) : $custom;
}

/** admin_bar_menu action: remove the WP logo node when hide_wp_logo. */
function wpultra_wlabel_strip_wp_logo($wp_admin_bar): void {
    if (is_object($wp_admin_bar) && method_exists($wp_admin_bar, 'remove_node')) {
        $wp_admin_bar->remove_node('wp-logo');
    }
}

/**
 * admin_menu (prio 999) callback: apply the branded menu title in place, then —
 * if client mode restricts the current user — remove the configured menus.
 *
 * The menu title is applied via the pure relabel helper on the global $menu so
 * we don't need to edit connect-page.php. See the note in the ability about a
 * proper hook connect-page.php could read instead.
 */
function wpultra_wlabel_apply_admin_menu(): void {
    global $menu;
    $config = wpultra_wlabel_get_config();

    // Relabel our own menu row (slug 'wpultra') to the branded menu_title.
    if (is_array($menu ?? null)) {
        $title = $config['brand']['menu_title'];
        if ($title !== '') {
            $menu = wpultra_wlabel_relabel_menu($menu, 'wpultra', $title);
        }
    }

    // Client-mode: hide configured menus from restricted users.
    $cm = $config['client_mode'];
    if (!empty($cm['enabled']) && !empty($cm['hide_menus'])) {
        $roles = wpultra_wlabel_current_user_roles();
        if (wpultra_wlabel_should_restrict($roles, $cm['allowed_roles']) && function_exists('remove_menu_page')) {
            foreach ($cm['hide_menus'] as $slug) {
                remove_menu_page($slug);
            }
        }
    }
}

/** all_plugins filter: unset our row for roles listed in hide_plugin_from. */
function wpultra_wlabel_filter_plugins_list($plugins) {
    if (!is_array($plugins)) { return $plugins; }
    $config = wpultra_wlabel_get_config();
    $cm = $config['client_mode'];
    if (empty($cm['enabled']) || empty($cm['hide_plugin_from'])) { return $plugins; }

    $roles = wpultra_wlabel_current_user_roles();
    // Privileged users always keep visibility (self-lockout guard).
    if (array_intersect($roles, wpultra_wlabel_privileged_roles()) !== []) { return $plugins; }
    if (array_intersect($roles, $cm['hide_plugin_from']) === []) { return $plugins; }

    $basename = wpultra_wlabel_plugin_basename();
    unset($plugins[$basename]);
    return $plugins;
}
