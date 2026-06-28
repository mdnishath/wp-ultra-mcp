<?php
/**
 * Plugin Name: WP-Ultra-MCP
 * Description: Turn this WordPress site into an MCP server for AI CLIs — Elementor, SQL, WP-CLI, files, and more.
 * Version: 0.7.0
 * Requires PHP: 8.0
 * Requires at least: 6.6
 * License: GPL-2.0-or-later
 * Text Domain: wp-ultra-mcp
 */

declare(strict_types=1);

if (!defined('ABSPATH')) { exit(); }

define('WPULTRA_VERSION', '0.7.0');
define('WPULTRA_FILE', __FILE__);
define('WPULTRA_DIR', plugin_dir_path(__FILE__));
define('WPULTRA_URL', plugin_dir_url(__FILE__));
define('WPULTRA_VENDOR_AUTOLOAD', WPULTRA_DIR . 'vendor/autoload_packages.php');
define('WPULTRA_MCP_ADAPTER_CLASS', 'WP\\MCP\\Core\\McpAdapter');
define('WPULTRA_SANDBOX_DIR', WP_CONTENT_DIR . '/wpultra-sandbox/');

// Load bundled dependencies (Jetpack autoloader → mcp-adapter).
if (is_readable(WPULTRA_VENDOR_AUTOLOAD)) {
    require_once WPULTRA_VENDOR_AUTOLOAD;
}

require_once WPULTRA_DIR . 'includes/helpers.php';
require_once WPULTRA_DIR . 'includes/sandbox/runtime.php';
require_once WPULTRA_DIR . 'includes/bootstrap-mcp.php';

/**
 * On activation, if Elementor is present, turn on the "Editor V4 / atomic elements" experiment —
 * every Elementor ability in this plugin requires it, and Elementor reads experiment state at boot
 * (so flipping it at activation makes it active on all subsequent requests with no manual step).
 */
register_activation_hook(__FILE__, function () {
    if (class_exists('\\Elementor\\Plugin')) {
        $state = class_exists('\\Elementor\\Core\\Experiments\\Manager')
            ? \Elementor\Core\Experiments\Manager::STATE_ACTIVE
            : 'active';
        update_option('elementor_experiment-e_atomic_elements', $state);
    }
});

add_action('admin_notices', function () {
    if (function_exists('wpultra_sandbox_crashed') && wpultra_sandbox_crashed()) {
        $url = wp_nonce_url(admin_url('admin-post.php?action=wpultra_clear_safe'), 'wpultra_clear_safe');
        echo '<div class="notice notice-error"><p><strong>WP-Ultra-MCP safe mode:</strong> AI-written sandbox code crashed and is suspended. <a href="' . esc_url($url) . '">Clear safe mode</a> after fixing.</p></div>';
    }
});
add_action('admin_post_wpultra_clear_safe', function () {
    if (current_user_can('manage_options') && check_admin_referer('wpultra_clear_safe')) { wpultra_sandbox_clear(); }
    wp_safe_redirect(admin_url('admin.php?page=wpultra')); exit;
});

if (is_admin()) {
    require_once WPULTRA_DIR . 'includes/admin/connect-page.php';
    require_once WPULTRA_DIR . 'includes/admin/abilities-page.php';
    require_once WPULTRA_DIR . 'includes/admin/ability-hub.php';
    require_once WPULTRA_DIR . 'includes/admin/skill-hub.php';
    require_once WPULTRA_DIR . 'includes/admin/memory-hub.php';
    require_once WPULTRA_DIR . 'includes/admin/activity-page.php';
}

// Boot the MCP adapter + abilities (guarded internally on enabled-flag and adapter availability).
add_action('plugins_loaded', 'wpultra_boot', 20);
