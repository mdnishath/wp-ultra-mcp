<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

add_action('admin_menu', function () {
    add_menu_page('WP-Ultra-MCP', 'WP-Ultra-MCP', 'manage_options', 'wpultra', 'wpultra_connect_render', 'dashicons-rest-api', 80);
    add_submenu_page('wpultra', 'Abilities', 'Abilities', 'manage_options', 'wpultra-abilities', 'wpultra_abilities_render');
});

add_action('admin_post_wpultra_enable', function () {
    if (!current_user_can('manage_options') || !check_admin_referer('wpultra_enable')) { wp_die('forbidden'); }
    update_option('wpultra_enabled', '1');
    update_option('wpultra_domain', wp_parse_url(home_url(), PHP_URL_HOST));
    wp_safe_redirect(admin_url('admin.php?page=wpultra&enabled=1'));
    exit;
});

add_action('admin_post_wpultra_gen_password', function () {
    if (!current_user_can('manage_options') || !check_admin_referer('wpultra_gen_password')) { wp_die('forbidden'); }
    $user_id = get_current_user_id();
    [$password] = WP_Application_Passwords::create_new_application_password($user_id, ['name' => 'WP-Ultra-MCP']);
    set_transient('wpultra_app_password_' . $user_id, $password, 300);
    wp_safe_redirect(admin_url('admin.php?page=wpultra&pw=1'));
    exit;
});

function wpultra_connect_render(): void {
    $enabled = get_option('wpultra_enabled') === '1';
    $endpoint = rest_url('mcp/wpultra');
    $user = wp_get_current_user();
    $pw = get_transient('wpultra_app_password_' . get_current_user_id());
    echo '<div class="wrap"><h1>WP-Ultra-MCP</h1>';

    // Step 1: enable
    echo '<h2>1. Enable</h2>';
    if ($enabled) {
        echo '<p>✅ AI control is ON for ' . esc_html((string) get_option('wpultra_domain')) . '</p>';
    } else {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('wpultra_enable'); echo '<input type="hidden" name="action" value="wpultra_enable">';
        echo '<button class="button button-primary">Turn on AI control for this site</button></form>';
    }

    // Step 2: app password
    echo '<h2>2. Application Password</h2>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('wpultra_gen_password'); echo '<input type="hidden" name="action" value="wpultra_gen_password">';
    echo '<button class="button">Generate application password</button></form>';
    if ($pw) { echo '<p><strong>Copy now (shown once):</strong> <code>' . esc_html($pw) . '</code></p>'; }

    // Step 3: client config
    $shown_pw = $pw ?: 'YOUR_APP_PASSWORD';
    $http = [
        'mcpServers' => ['wp-ultra-mcp' => [
            'command' => 'npx', 'args' => ['-y', '@automattic/mcp-wordpress-remote@latest'],
            'env' => ['WP_API_URL' => $endpoint, 'WP_API_USERNAME' => $user->user_login, 'WP_API_PASSWORD' => $shown_pw],
        ]],
    ];
    echo '<h2>3. Connect your AI client</h2><p>Endpoint: <code>' . esc_html($endpoint) . '</code></p>';
    echo '<pre style="background:#1e1e1e;color:#ddd;padding:12px;overflow:auto">' . esc_html(wp_json_encode($http, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre>';
    echo '</div>';
}
