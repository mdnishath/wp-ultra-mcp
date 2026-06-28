<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

add_action('admin_menu', function () {
    add_menu_page('WP-Ultra-MCP', 'WP-Ultra-MCP', 'manage_options', 'wpultra', 'wpultra_connect_render', 'dashicons-rest-api', 80);
    add_submenu_page('wpultra', 'Abilities', 'Abilities', 'manage_options', 'wpultra-abilities', 'wpultra_abilities_render');
    add_submenu_page('wpultra', 'Ability Hub', 'Ability Hub', 'manage_options', 'wpultra-ability-hub', 'wpultra_ability_hub_render');
    add_submenu_page('wpultra', 'Skill Hub', 'Skill Hub', 'manage_options', 'wpultra-skill-hub', 'wpultra_skill_hub_render');
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
    $name = 'WP-Ultra-MCP (' . wp_date('M j, H:i') . ')';
    [$password] = WP_Application_Passwords::create_new_application_password($user_id, ['name' => $name]);
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
    $endpoint = rest_url('mcp/wpultra');
    $user = wp_get_current_user();
    $pw = get_transient('wpultra_app_password_' . get_current_user_id());
    $app_pwds = class_exists('WP_Application_Passwords')
        ? (array) WP_Application_Passwords::get_user_application_passwords($user->ID) : [];
    $clients = wpultra_connect_clients($endpoint, $user->user_login);
    $post_url = esc_url(admin_url('admin-post.php'));
    ?>
    <div class="wrap wpu-wrap">
        <div class="wpu-head">
            <div>
                <h1 class="wpu-title"><span class="dashicons dashicons-rest-api"></span> WP-Ultra-MCP</h1>
                <p class="wpu-sub">Connect an AI client to control this WordPress site over MCP.</p>
            </div>
            <span class="wpu-pill <?php echo $enabled ? 'wpu-pill-on' : 'wpu-pill-off'; ?>">
                <strong><?php echo $enabled ? 'ON' : 'OFF'; ?></strong> AI control
            </span>
        </div>

        <!-- Step 1 -->
        <div class="wpu-card wpu-pad">
            <div class="wpu-step"><span class="wpu-num">1</span> Enable</div>
            <?php if ($enabled) : ?>
                <p class="wpu-ok">✅ AI control is ON for <code><?php echo esc_html((string) get_option('wpultra_domain')); ?></code></p>
            <?php else : ?>
                <form method="post" action="<?php echo $post_url; ?>">
                    <?php wp_nonce_field('wpultra_enable'); ?>
                    <input type="hidden" name="action" value="wpultra_enable">
                    <button class="button button-primary button-hero">Turn on AI control</button>
                </form>
            <?php endif; ?>
        </div>

        <!-- Step 2 -->
        <div class="wpu-card wpu-pad" id="credentials">
            <div class="wpu-step"><span class="wpu-num">2</span> Application Password</div>

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

        <span id="wpu-toast" class="wpu-toast">Copied</span>
    </div>

    <style>
        .wpu-wrap { max-width: 920px; }
        .wpu-head { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; margin:8px 0 20px; flex-wrap:wrap; }
        .wpu-title { display:flex; align-items:center; gap:10px; font-size:23px; margin:0; }
        .wpu-title .dashicons { color:#6d4afe; font-size:26px; width:26px; height:26px; }
        .wpu-sub { margin:6px 0 0; color:#646970; font-size:13px; }
        .wpu-pill { background:#fff; border:1px solid #e2e4e9; border-radius:999px; padding:7px 16px; font-size:13px; color:#50575e; box-shadow:0 1px 2px rgba(0,0,0,.04); }
        .wpu-pill-on strong { color:#1a9d5a; } .wpu-pill-off strong { color:#c23b3b; }

        .wpu-card { background:#fff; border:1px solid #e6e7eb; border-radius:14px; margin:0 0 18px; overflow:hidden;
            box-shadow:0 6px 20px rgba(18,20,40,.06), 0 1px 3px rgba(18,20,40,.05); }
        .wpu-pad { padding:18px 22px; }
        .wpu-step { display:flex; align-items:center; gap:10px; font-weight:600; font-size:15px; color:#1d2327; margin:0 0 14px; }
        .wpu-num { display:inline-flex; align-items:center; justify-content:center; width:26px; height:26px; border-radius:50%;
            background:linear-gradient(135deg,#7b5cff,#5b34f2); color:#fff; font-size:13px; }
        .wpu-ok { font-size:14px; } .wpu-ok code, .wpu-muted code { background:#f0f0f4; color:#6d4afe; border-radius:6px; padding:2px 8px; }
        .wpu-muted { color:#787c82; font-size:12.5px; }

        .wpu-reveal { background:#fff8e5; border:1px solid #f5d97a; border-radius:10px; padding:12px 14px; }
        .wpu-reveal-warn { color:#8a6d00; font-size:12.5px; margin-bottom:8px; }
        .wpu-reveal-row { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
        .wpu-pw { font-size:15px; letter-spacing:1px; background:#1d2327; color:#fff; border-radius:8px; padding:8px 14px; }

        .wpu-pwtable { width:100%; border-collapse:collapse; margin-top:10px; font-size:13px; }
        .wpu-pwtable th { text-align:left; color:#787c82; font-weight:600; padding:8px 10px; border-bottom:1px solid #eceef2; }
        .wpu-pwtable td { padding:9px 10px; border-bottom:1px solid #f1f2f5; }
        .button-link-delete { color:#b3261e; }

        .wpu-tabs { display:flex; gap:8px; flex-wrap:wrap; margin:6px 0 16px; }
        .wpu-tab { background:#f3f3f7; border:1px solid #e2e4e9; border-radius:10px; padding:8px 16px; cursor:pointer; font-size:13px; font-weight:600; color:#50575e; transition:all .15s ease; }
        .wpu-tab:hover { background:#ecebff; }
        .wpu-tab.active { background:linear-gradient(135deg,#7b5cff,#5b34f2); color:#fff; border-color:transparent; box-shadow:0 4px 12px rgba(91,52,242,.28); }
        .wpu-pane { display:none; }
        .wpu-pane.active { display:block; }
        .wpu-where { display:flex; align-items:flex-start; gap:6px; color:#3c434a; font-size:12.5px; background:#f7f7fb; border-radius:8px; padding:9px 12px; }
        .wpu-where .dashicons { color:#6d4afe; }

        .wpu-codewrap { position:relative; margin:12px 0; }
        .wpu-copybtn { position:absolute; top:10px; right:10px; z-index:2; }
        .wpu-code { background:#1e1e2e; color:#e6e6f0; padding:16px; border-radius:12px; overflow:auto; font-size:12.5px; line-height:1.55;
            box-shadow:inset 0 1px 4px rgba(0,0,0,.4); margin:0; white-space:pre; }
        .wpu-steps { margin:10px 0 0 4px; color:#3c434a; font-size:13px; }
        .wpu-steps li { margin:5px 0; }

        .wpu-toast { position:fixed; right:28px; bottom:28px; background:#1d2327; color:#fff; padding:11px 18px; border-radius:10px;
            font-size:13px; box-shadow:0 8px 24px rgba(0,0,0,.25); opacity:0; transform:translateY(10px); pointer-events:none;
            transition:opacity .2s ease, transform .2s ease; z-index:9999; }
        .wpu-toast.show { opacity:1; transform:translateY(0); }
    </style>

    <script>
    (function () {
        var toast = document.getElementById('wpu-toast'), tt;
        function showToast(m) { toast.textContent = m; toast.classList.add('show'); clearTimeout(tt); tt = setTimeout(function(){ toast.classList.remove('show'); }, 1400); }

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
                navigator.clipboard.writeText(text).then(function () { showToast('Copied to clipboard'); })
                    .catch(function () {
                        var r = document.createRange(); r.selectNode(el);
                        var sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(r);
                        try { document.execCommand('copy'); showToast('Copied'); } catch (e) { showToast('Press Ctrl+C to copy'); }
                        sel.removeAllRanges();
                    });
            });
        });
    })();
    </script>
    <?php
}
