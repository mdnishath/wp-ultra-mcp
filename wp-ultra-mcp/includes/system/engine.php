<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/** Plugin & theme management engine. Install/update pull in the wp-admin upgrader on demand. */

/** Pure: the plugin basename of WP-Ultra-MCP itself, so we never deactivate/delete our own bridge. */
function wpultra_system_self_plugin(): string {
    return defined('WPULTRA_FILE') ? plugin_basename(WPULTRA_FILE) : 'wp-ultra-mcp/wp-ultra-mcp.php';
}

/** Pure: true when $plugin is this plugin (compared on the directory segment, tolerant of file name). */
function wpultra_system_is_self(string $plugin): bool {
    $self = wpultra_system_self_plugin();
    $dir = static fn($p) => strtok($p, '/');
    return $plugin === $self || $dir($plugin) === $dir($self);
}

function wpultra_system_require_plugin_admin(): void {
    if (!function_exists('get_plugins')) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
}
function wpultra_system_require_upgrader(): void {
    foreach (['file.php', 'misc.php', 'plugin.php', 'class-wp-upgrader.php'] as $f) {
        $p = ABSPATH . 'wp-admin/includes/' . $f;
        if (is_readable($p)) { require_once $p; }
    }
    if (!class_exists('Plugin_Upgrader') && is_readable(ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php')) {
        require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
    }
    if (!class_exists('Theme_Upgrader') && is_readable(ABSPATH . 'wp-admin/includes/class-theme-upgrader.php')) {
        require_once ABSPATH . 'wp-admin/includes/class-theme-upgrader.php';
    }
}

/** @return array */
function wpultra_system_list_plugins(): array {
    wpultra_system_require_plugin_admin();
    $rows = [];
    foreach (get_plugins() as $file => $data) {
        $rows[] = [
            'plugin'  => $file,
            'name'    => $data['Name'] ?? $file,
            'version' => $data['Version'] ?? '',
            'active'  => is_plugin_active($file),
        ];
    }
    return ['plugins' => $rows, 'count' => count($rows)];
}

/** @return array|WP_Error */
function wpultra_system_activate_plugin(string $plugin) {
    wpultra_system_require_plugin_admin();
    // Undo coverage (BF2.6): snapshot the active_plugins option before activating,
    // so undo-restore can revert the toggle. Guarded — never blocks activation.
    if (function_exists('wpultra_undo_capture') && function_exists('get_option')) {
        wpultra_undo_capture('active_plugins', 'active_plugins', get_option('active_plugins', []), 'activate-plugin ' . $plugin);
    }
    $res = activate_plugin($plugin);
    if (is_wp_error($res)) { return $res; }
    return ['plugin' => $plugin, 'active' => true];
}

/** @return array|WP_Error */
function wpultra_system_deactivate_plugin(string $plugin) {
    if (wpultra_system_is_self($plugin)) { return wpultra_err('self_protected', 'Refusing to deactivate WP-Ultra-MCP itself (would cut off AI control).'); }
    wpultra_system_require_plugin_admin();
    // Undo coverage (BF2.6): snapshot the active_plugins option before deactivating,
    // so undo-restore can revert the toggle. Guarded — never blocks deactivation.
    if (function_exists('wpultra_undo_capture') && function_exists('get_option')) {
        wpultra_undo_capture('active_plugins', 'active_plugins', get_option('active_plugins', []), 'deactivate-plugin ' . $plugin);
    }
    deactivate_plugins([$plugin]);
    return ['plugin' => $plugin, 'active' => false];
}

/** @return array|WP_Error */
function wpultra_system_install_plugin(string $source) {
    wpultra_system_require_upgrader();
    if (!class_exists('Plugin_Upgrader')) { return wpultra_err('upgrader_unavailable', 'Plugin_Upgrader unavailable.'); }
    // A bare slug installs from the wp.org repo; a URL installs from a zip.
    $package = $source;
    if (!preg_match('#^https?://#i', $source)) {
        if (!function_exists('plugins_api')) { require_once ABSPATH . 'wp-admin/includes/plugin-install.php'; }
        $api = plugins_api('plugin_information', ['slug' => sanitize_key($source), 'fields' => ['sections' => false]]);
        if (is_wp_error($api)) { return $api; }
        $package = $api->download_link;
    }
    $upgrader = new Plugin_Upgrader(new WP_Upgrader_Skin());
    $ok = $upgrader->install($package);
    if (is_wp_error($ok)) { return $ok; }
    if (!$ok) { return wpultra_err('install_failed', 'Plugin install failed.'); }
    return ['installed' => true, 'plugin' => (string) $upgrader->plugin_info()];
}

/** @return array|WP_Error */
function wpultra_system_update_plugin(string $plugin) {
    wpultra_system_require_upgrader();
    if (!class_exists('Plugin_Upgrader')) { return wpultra_err('upgrader_unavailable', 'Plugin_Upgrader unavailable.'); }
    if (function_exists('wp_update_plugins')) { wp_update_plugins(); }
    $upgrader = new Plugin_Upgrader(new WP_Upgrader_Skin());
    $ok = $upgrader->upgrade($plugin);
    if (is_wp_error($ok)) { return $ok; }
    return ['plugin' => $plugin, 'updated' => (bool) $ok];
}

/** @return array|WP_Error */
function wpultra_system_delete_plugin(string $plugin) {
    if (wpultra_system_is_self($plugin)) { return wpultra_err('self_protected', 'Refusing to delete WP-Ultra-MCP itself.'); }
    // delete_plugins() needs the filesystem API (file.php: request_filesystem_credentials,
    // WP_Filesystem) — plugin.php alone fatals outside wp-admin requests.
    wpultra_system_require_upgrader();
    if (is_plugin_active($plugin)) { return wpultra_err('active_plugin', 'Deactivate the plugin before deleting it.'); }
    $res = delete_plugins([$plugin]);
    if (is_wp_error($res)) { return $res; }
    return ['plugin' => $plugin, 'deleted' => true];
}

/** @return array */
function wpultra_system_list_themes(): array {
    $rows = [];
    $active = function_exists('wp_get_theme') ? wp_get_theme()->get_stylesheet() : '';
    foreach (wp_get_themes() as $slug => $theme) {
        $rows[] = ['stylesheet' => $slug, 'name' => $theme->get('Name'), 'version' => $theme->get('Version'), 'active' => $slug === $active];
    }
    return ['themes' => $rows, 'count' => count($rows), 'active' => $active];
}

/** @return array|WP_Error */
function wpultra_system_activate_theme(string $stylesheet) {
    if (!wp_get_theme($stylesheet)->exists()) { return wpultra_err('theme_not_found', "Theme '$stylesheet' is not installed."); }
    // Undo coverage (BF2.6): snapshot the current template/stylesheet options before
    // switching, so undo-restore can revert the theme change. Guarded — never blocks activation.
    if (function_exists('wpultra_undo_capture') && function_exists('get_option')) {
        wpultra_undo_capture('active_theme', 'active_theme', [
            'template'   => (string) get_option('template', ''),
            'stylesheet' => (string) get_option('stylesheet', ''),
        ], 'activate-theme ' . $stylesheet);
    }
    switch_theme($stylesheet);
    return ['stylesheet' => $stylesheet, 'active' => true];
}
