<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

// Shared design system for every WP-Ultra-MCP screen. The hook suffix is
// 'toplevel_page_wpultra' or '<menu>_page_wpultra-*', so a substring match
// covers all current and future subpages. Page-specific overrides stay inline
// in the templates and win because body <style> blocks come after this sheet.
add_action('admin_enqueue_scripts', function ($hook) {
    if (strpos((string) $hook, 'wpultra') === false) { return; }
    wp_enqueue_style('wpultra-admin', WPULTRA_URL . 'assets/admin.css', [], WPULTRA_VERSION);
    wp_enqueue_script('wpultra-admin', WPULTRA_URL . 'assets/admin.js', [], WPULTRA_VERSION, false);
});

add_action('admin_menu', function () {
    add_menu_page('WP-Ultra-MCP', 'WP-Ultra-MCP', 'manage_options', 'wpultra', 'wpultra_connect_render', 'dashicons-rest-api', 80);
    add_submenu_page('wpultra', 'Abilities', 'Abilities', 'manage_options', 'wpultra-abilities', 'wpultra_abilities_render');
    add_submenu_page('wpultra', 'Ability Hub', 'Ability Hub', 'manage_options', 'wpultra-ability-hub', 'wpultra_ability_hub_render');
    add_submenu_page('wpultra', 'Skill Hub', 'Skill Hub', 'manage_options', 'wpultra-skill-hub', 'wpultra_skill_hub_render');
    add_submenu_page('wpultra', 'Memory Hub', 'Memory Hub', 'manage_options', 'wpultra-memory-hub', 'wpultra_memory_hub_render');
    add_submenu_page('wpultra', 'Activity', 'Activity', 'manage_options', 'wpultra-activity', 'wpultra_activity_render');
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
    // Include seconds so two clicks in the same minute don't collide on a duplicate name.
    $name = 'WP-Ultra-MCP (' . wp_date('M j, H:i:s') . ')';
    $result = WP_Application_Passwords::create_new_application_password($user_id, ['name' => $name]);
    if (is_wp_error($result)) {
        set_transient('wpultra_app_password_error_' . $user_id, $result->get_error_message(), 60);
        wp_safe_redirect(admin_url('admin.php?page=wpultra&pw_error=1#credentials'));
        exit;
    }
    $password = $result[0];
    // One-time, short-lived reveal only. Never persisted by us beyond this transient.
    set_transient('wpultra_app_password_' . $user_id, $password, 180);
    wp_safe_redirect(admin_url('admin.php?page=wpultra&pw=1#credentials'));
    exit;
});

add_action('admin_post_wpultra_revoke_password', function () {
    if (!current_user_can('manage_options') || !check_admin_referer('wpultra_revoke_password')) { wp_die('forbidden'); }
    $uuid = sanitize_text_field((string) ($_POST['uuid'] ?? ''));
    if ($uuid !== '') { WP_Application_Passwords::delete_application_password(get_current_user_id(), $uuid); }
    wp_safe_redirect(admin_url('admin.php?page=wpultra&revoked=1#credentials'));
    exit;
});

add_action('wp_ajax_wpultra_toggle_enabled', function () {
    if (!current_user_can('manage_options') || !check_ajax_referer('wpultra_toggle_enabled', 'nonce', false)) {
        wp_send_json_error(['message' => 'forbidden'], 403);
    }
    $on = ((string) ($_POST['on'] ?? '')) === '1';
    if ($on) {
        update_option('wpultra_enabled', '1');
        update_option('wpultra_domain', wp_parse_url(home_url(), PHP_URL_HOST));
    } else {
        update_option('wpultra_enabled', '0');
    }
    wp_send_json_success(['enabled' => $on]);
});

// Opt-in: wipe all WP-Ultra-MCP data (options, CPT posts, sandbox/snapshot/backup
// dirs) when the plugin is deleted. Read by uninstall.php. Default off.
add_action('wp_ajax_wpultra_toggle_wipe', function () {
    if (!current_user_can('manage_options') || !check_ajax_referer('wpultra_toggle_enabled', 'nonce', false)) {
        wp_send_json_error(['message' => 'forbidden'], 403);
    }
    $on = ((string) ($_POST['on'] ?? '')) === '1';
    update_option('wpultra_delete_data_on_uninstall', $on ? '1' : '0');
    wp_send_json_success(['wipe' => $on]);
});

/**
 * Build the per-AI-client connection guide.
 *
 * @return array<string, array{label:string, where:string, lang:string, body:string, steps:string[]}>
 */
function wpultra_connect_clients(string $endpoint, string $username): array {
    $pw = 'YOUR_APP_PASSWORD'; // placeholder — never the real password
    $bridge = wp_json_encode([
        'mcpServers' => ['wp-ultra-mcp' => [
            'command' => 'npx',
            'args' => ['-y', '@automattic/mcp-wordpress-remote@latest'],
            'env' => ['WP_API_URL' => $endpoint, 'WP_API_USERNAME' => $username, 'WP_API_PASSWORD' => $pw],
        ]],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    $basic = base64_encode($username . ':' . $pw);
    $httpForm = wp_json_encode([
        'mcpServers' => ['wp-ultra-mcp' => [
            'type' => 'http', 'url' => $endpoint,
            'headers' => ['Authorization' => 'Basic ' . $basic],
        ]],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    $cliCmd = "claude mcp add wp-ultra-mcp \\\n"
        . "  --env WP_API_URL=" . $endpoint . " \\\n"
        . "  --env WP_API_USERNAME=" . $username . " \\\n"
        . "  --env WP_API_PASSWORD=" . $pw . " \\\n"
        . "  -- npx -y @automattic/mcp-wordpress-remote@latest";

    return [
        'claude-desktop' => [
            'label' => 'Claude Desktop',
            'where' => 'Settings → Developer → Edit Config, or the file:  Windows: %APPDATA%\\Claude\\claude_desktop_config.json   ·   macOS: ~/Library/Application Support/Claude/claude_desktop_config.json',
            'lang' => 'json', 'body' => $bridge,
            'steps' => [
                'Open the config file (or Settings → Developer → Edit Config).',
                'Merge the "mcpServers" block below into it (keep any existing servers).',
                'Replace YOUR_APP_PASSWORD with the password you copied above.',
                'Fully quit and reopen Claude Desktop. The tools icon should show wp-ultra-mcp.',
            ],
        ],
        'claude-code' => [
            'label' => 'Claude Code',
            'where' => 'Run this in your terminal (one line):',
            'lang' => 'bash', 'body' => $cliCmd,
            'steps' => [
                'Replace YOUR_APP_PASSWORD with the password you copied above.',
                'Run the command. It registers the server in Claude Code.',
                'Start a new session (or /mcp) — wp-ultra-mcp tools are available.',
            ],
        ],
        'cursor' => [
            'label' => 'Cursor',
            'where' => 'Project:  .cursor/mcp.json   ·   Global:  ~/.cursor/mcp.json',
            'lang' => 'json', 'body' => $bridge,
            'steps' => [
                'Create/open the mcp.json file and paste the block below.',
                'Replace YOUR_APP_PASSWORD with the password you copied above.',
                'Reload Cursor; enable the server in Settings → MCP if prompted.',
            ],
        ],
        'gemini' => [
            'label' => 'Gemini CLI',
            'where' => 'File:  ~/.gemini/settings.json',
            'lang' => 'json', 'body' => $bridge,
            'steps' => [
                'Open ~/.gemini/settings.json and merge the "mcpServers" block.',
                'Replace YOUR_APP_PASSWORD with the password you copied above.',
                'Restart Gemini CLI.',
            ],
        ],
        'http' => [
            'label' => 'Generic (HTTP)',
            'where' => 'For any client that supports remote HTTP MCP with a header:',
            'lang' => 'json', 'body' => $httpForm,
            'steps' => [
                'Use this if your client speaks HTTP MCP directly (no npx bridge).',
                'Replace YOUR_APP_PASSWORD inside the Base64 token, OR regenerate the header as base64("' . $username . ':<app-password>").',
            ],
        ],
    ];
}

function wpultra_connect_render(): void {
    $enabled = get_option('wpultra_enabled') === '1';
    $wipe = get_option('wpultra_delete_data_on_uninstall') === '1';
    $endpoint = rest_url('mcp/wpultra');
    $user = wp_get_current_user();
    $pw = get_transient('wpultra_app_password_' . get_current_user_id());
    $pw_error = get_transient('wpultra_app_password_error_' . get_current_user_id());
    if ($pw_error) { delete_transient('wpultra_app_password_error_' . get_current_user_id()); }
    $app_pwds = class_exists('WP_Application_Passwords')
        ? (array) WP_Application_Passwords::get_user_application_passwords($user->ID) : [];
    $clients = wpultra_connect_clients($endpoint, $user->user_login);
    $post_url = esc_url(admin_url('admin-post.php'));
    $toggle_nonce = wp_create_nonce('wpultra_toggle_enabled');
    $domain = (string) (get_option('wpultra_domain') ?: wp_parse_url(home_url(), PHP_URL_HOST));
    ?>
    <div class="wrap wpu-wrap">
        <div class="wpu-head">
            <div>
                <h1 class="wpu-title"><span class="dashicons dashicons-rest-api"></span> WP-Ultra-MCP</h1>
                <p class="wpu-sub">Connect an AI client to control this WordPress site over MCP.</p>
            </div>
            <span class="wpu-pill <?php echo $enabled ? 'wpu-pill-on' : 'wpu-pill-off'; ?>" id="wpu-status-pill">
                <strong><?php echo $enabled ? 'ON' : 'OFF'; ?></strong> AI control
            </span>
        </div>

        <!-- Step 1 -->
        <div class="wpu-card wpu-pad">
            <div class="wpu-step"><span class="wpu-num">1</span> Enable</div>
            <div class="wpu-enable-row">
                <label class="wpu-switch" title="Toggle AI control">
                    <input type="checkbox" id="wpu-enable-toggle" <?php checked($enabled); ?>>
                    <span class="wpu-track"><span class="wpu-knob"></span></span>
                </label>
                <div>
                    <strong id="wpu-enable-label"><?php echo $enabled ? 'AI control is ON' : 'AI control is OFF'; ?></strong>
                    <div class="wpu-muted">When off, the MCP endpoint rejects requests and no abilities run. Site: <code><?php echo esc_html($domain); ?></code></div>
                </div>
            </div>
        </div>

        <!-- Step 2 -->
        <div class="wpu-card wpu-pad" id="credentials">
            <div class="wpu-step"><span class="wpu-num">2</span> Application Password</div>

            <?php if ($pw_error) : ?>
                <div class="notice notice-error inline"><p><?php echo esc_html($pw_error); ?></p></div>
            <?php endif; ?>

            <?php if ($pw) : ?>
                <div class="wpu-reveal">
                    <div class="wpu-reveal-warn">⚠️ Copy this now — it is shown only once and is not stored in plain text.</div>
                    <div class="wpu-reveal-row">
                        <code id="wpu-pw" class="wpu-pw"><?php echo esc_html($pw); ?></code>
                        <button type="button" class="button button-primary" data-copy="#wpu-pw">Copy password</button>
                    </div>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo $post_url; ?>" style="margin:12px 0 4px;">
                <?php wp_nonce_field('wpultra_gen_password'); ?>
                <input type="hidden" name="action" value="wpultra_gen_password">
                <button class="button">+ Generate new application password</button>
            </form>

            <p class="wpu-muted">These are standard WordPress Application Passwords. Manage them anytime in
                <a href="<?php echo esc_url(admin_url('profile.php#application-passwords')); ?>">your profile</a>. Revoke one below to instantly cut off that client.</p>

            <?php if ($app_pwds) : ?>
                <table class="wpu-pwtable">
                    <thead><tr><th>Name</th><th>Created</th><th>Last used</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($app_pwds as $ap) :
                        $created = !empty($ap['created']) ? wp_date('M j, Y', (int) $ap['created']) : '—';
                        $last = !empty($ap['last_used']) ? wp_date('M j, Y', (int) $ap['last_used']) : 'never';
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html((string) ($ap['name'] ?? '')); ?></strong></td>
                            <td><?php echo esc_html($created); ?></td>
                            <td><?php echo esc_html($last); ?></td>
                            <td style="text-align:right;">
                                <form method="post" action="<?php echo $post_url; ?>" onsubmit="return confirm('Revoke this application password? Any client using it will be disconnected.');">
                                    <?php wp_nonce_field('wpultra_revoke_password'); ?>
                                    <input type="hidden" name="action" value="wpultra_revoke_password">
                                    <input type="hidden" name="uuid" value="<?php echo esc_attr((string) ($ap['uuid'] ?? '')); ?>">
                                    <button class="button button-link-delete">Revoke</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p class="wpu-muted">No application passwords yet — generate one to connect a client.</p>
            <?php endif; ?>
        </div>

        <!-- Step 3 -->
        <div class="wpu-card wpu-pad">
            <div class="wpu-step"><span class="wpu-num">3</span> Connect your AI client</div>
            <p class="wpu-muted">Endpoint: <code><?php echo esc_html($endpoint); ?></code> · pick your client:</p>

            <div class="wpu-tabs">
                <?php $first = true; foreach ($clients as $key => $c) : ?>
                    <button type="button" class="wpu-tab<?php echo $first ? ' active' : ''; ?>" data-tab="<?php echo esc_attr($key); ?>"><?php echo esc_html($c['label']); ?></button>
                <?php $first = false; endforeach; ?>
            </div>

            <?php $first = true; foreach ($clients as $key => $c) : ?>
                <div class="wpu-pane<?php echo $first ? ' active' : ''; ?>" data-pane="<?php echo esc_attr($key); ?>">
                    <p class="wpu-where"><span class="dashicons dashicons-location"></span> <?php echo esc_html($c['where']); ?></p>
                    <div class="wpu-codewrap">
                        <button type="button" class="button wpu-copybtn" data-copy="#wpu-code-<?php echo esc_attr($key); ?>">Copy</button>
                        <pre id="wpu-code-<?php echo esc_attr($key); ?>" class="wpu-code"><?php echo esc_html($c['body']); ?></pre>
                    </div>
                    <ol class="wpu-steps">
                        <?php foreach ($c['steps'] as $s) : ?><li><?php echo esc_html($s); ?></li><?php endforeach; ?>
                    </ol>
                </div>
            <?php $first = false; endforeach; ?>
        </div>

        <!-- Data retention -->
        <div class="wpu-card wpu-pad">
            <div class="wpu-step"><span class="dashicons dashicons-trash" style="color:#b3261e;"></span> Data on uninstall</div>
            <div class="wpu-enable-row">
                <label class="wpu-switch" title="<?php echo $wipe ? 'Will delete data' : 'Will keep data'; ?>">
                    <input type="checkbox" id="wpu-wipe-toggle" <?php checked($wipe); ?>>
                    <span class="wpu-track"><span class="wpu-knob"></span></span>
                </label>
                <span id="wpu-wipe-label"><?php echo $wipe ? 'Delete all plugin data when the plugin is deleted' : 'Keep plugin data when the plugin is deleted'; ?></span>
            </div>
            <p class="wpu-muted" style="margin-top:8px;">When on, deleting the plugin removes every setting, saved memory/skill/custom ability, access policy, and the sandbox/snapshot/backup folders. Cron events and transients are always cleaned up regardless.</p>
        </div>

        <span id="wpu-toast" class="wpu-toast">Copied</span>
    </div>

    <style>
        /* Page-specific: helper text sits tighter under its row here. */
        .wpu-muted { margin-top: 3px; }
    </style>

    <script>
    (function () {
        var ajaxurl = window.ajaxurl || '<?php echo esc_url(admin_url('admin-ajax.php')); ?>';
        var enableNonce = '<?php echo esc_js($toggle_nonce); ?>';

        var enableToggle = document.getElementById('wpu-enable-toggle');
        if (enableToggle) {
            enableToggle.addEventListener('change', function () {
                var on = enableToggle.checked;
                var sw = enableToggle.closest('.wpu-switch');
                sw.classList.add('wpu-saving');
                var body = new URLSearchParams();
                body.append('action', 'wpultra_toggle_enabled');
                body.append('nonce', enableNonce);
                body.append('on', on ? '1' : '0');
                fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        sw.classList.remove('wpu-saving');
                        if (res && res.success) {
                            document.getElementById('wpu-enable-label').textContent = on ? 'AI control is ON' : 'AI control is OFF';
                            var pill = document.getElementById('wpu-status-pill');
                            pill.classList.toggle('wpu-pill-on', on);
                            pill.classList.toggle('wpu-pill-off', !on);
                            pill.querySelector('strong').textContent = on ? 'ON' : 'OFF';
                            wpuToast(on ? 'AI control enabled' : 'AI control disabled');
                        } else {
                            enableToggle.checked = !on;
                            wpuToast('Could not change — try again');
                        }
                    })
                    .catch(function () { sw.classList.remove('wpu-saving'); enableToggle.checked = !on; wpuToast('Network error'); });
            });
        }

        var wipeToggle = document.getElementById('wpu-wipe-toggle');
        if (wipeToggle) {
            wipeToggle.addEventListener('change', function () {
                var on = wipeToggle.checked;
                var sw = wipeToggle.closest('.wpu-switch');
                sw.classList.add('wpu-saving');
                var body = new URLSearchParams();
                body.append('action', 'wpultra_toggle_wipe');
                body.append('nonce', enableNonce);
                body.append('on', on ? '1' : '0');
                fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        sw.classList.remove('wpu-saving');
                        if (res && res.success) {
                            document.getElementById('wpu-wipe-label').textContent = on ? 'Delete all plugin data when the plugin is deleted' : 'Keep plugin data when the plugin is deleted';
                            wpuToast(on ? 'Data will be deleted on uninstall' : 'Data will be kept on uninstall');
                        } else {
                            wipeToggle.checked = !on;
                            wpuToast('Could not change — try again');
                        }
                    })
                    .catch(function () { sw.classList.remove('wpu-saving'); wipeToggle.checked = !on; wpuToast('Network error'); });
            });
        }

        document.querySelectorAll('.wpu-tab').forEach(function (tab) {
            tab.addEventListener('click', function () {
                var key = tab.getAttribute('data-tab');
                document.querySelectorAll('.wpu-tab').forEach(function (t){ t.classList.toggle('active', t === tab); });
                document.querySelectorAll('.wpu-pane').forEach(function (p){ p.classList.toggle('active', p.getAttribute('data-pane') === key); });
            });
        });

        document.querySelectorAll('[data-copy]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var el = document.querySelector(btn.getAttribute('data-copy'));
                if (!el) return;
                var text = el.textContent;
                function fallbackCopy() {
                    var r = document.createRange(); r.selectNode(el);
                    var sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(r);
                    try { document.execCommand('copy'); wpuToast('Copied'); } catch (e) { wpuToast('Press Ctrl+C to copy'); }
                    sel.removeAllRanges();
                }
                // navigator.clipboard is only defined in secure contexts (https, or localhost).
                // On a plain-http .local domain it's undefined, so calling .writeText() throws
                // synchronously *before* the promise chain — .catch() never sees it and the
                // execCommand fallback never runs. Guard for its existence first.
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(function () { wpuToast('Copied to clipboard'); }).catch(fallbackCopy);
                } else {
                    fallbackCopy();
                }
            });
        });
    })();
    </script>
    <?php
}
