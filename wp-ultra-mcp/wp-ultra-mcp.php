<?php
/**
 * Plugin Name: WP-Ultra-MCP
 * Description: Turn this WordPress site into an MCP server for AI CLIs — Elementor, SQL, WP-CLI, files, and more.
 * Version: 0.1.0
 * Requires PHP: 8.0
 * Requires at least: 6.6
 * License: GPL-2.0-or-later
 * Text Domain: wp-ultra-mcp
 */

declare(strict_types=1);

if (!defined('ABSPATH')) { exit(); }

define('WPULTRA_VERSION', '0.1.0');
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
require_once WPULTRA_DIR . 'includes/bootstrap-mcp.php';

if (is_admin()) {
    require_once WPULTRA_DIR . 'includes/admin/connect-page.php';
    require_once WPULTRA_DIR . 'includes/admin/abilities-page.php';
    require_once WPULTRA_DIR . 'includes/admin/ability-hub.php';
}

// Boot the MCP adapter + abilities (guarded internally on enabled-flag and adapter availability).
add_action('plugins_loaded', 'wpultra_boot', 20);
