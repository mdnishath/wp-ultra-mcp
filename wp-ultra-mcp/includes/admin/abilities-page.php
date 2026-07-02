<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * AJAX: toggle a single ability on/off (instant save — no full-form submit).
 */
add_action('wp_ajax_wpultra_toggle_ability', function () {
    if (!current_user_can('manage_options') || !check_ajax_referer('wpultra_toggle', 'nonce', false)) {
        wp_send_json_error(['message' => 'forbidden'], 403);
    }
    $slug = sanitize_text_field((string) ($_POST['slug'] ?? ''));
    $disabled = ((string) ($_POST['disabled'] ?? '')) === '1';
    if (!in_array($slug, wpultra_ability_files(), true)) {
        wp_send_json_error(['message' => 'unknown ability'], 400);
    }
    $rules = (array) get_option('wpultra_ability_rules', []);
    $key = 'wpultra/' . $slug;
    if ($disabled) {
        $rules[$key] = ['disabled' => true];
    } else {
        unset($rules[$key]);
    }
    update_option('wpultra_ability_rules', $rules);
    wp_send_json_success(['slug' => $slug, 'disabled' => $disabled]);
});

/**
 * AJAX: toggle a whole capability category on/off. A disabled category's abilities
 * are never registered (enforced in wpultra_load_abilities).
 */
add_action('wp_ajax_wpultra_toggle_category', function () {
    if (!current_user_can('manage_options') || !check_ajax_referer('wpultra_toggle', 'nonce', false)) {
        wp_send_json_error(['message' => 'forbidden'], 403);
    }
    $cat = sanitize_text_field((string) ($_POST['category'] ?? ''));
    $disabled = ((string) ($_POST['disabled'] ?? '')) === '1';
    if (!array_key_exists($cat, wpultra_ability_category_map())) {
        wp_send_json_error(['message' => 'unknown category'], 400);
    }
    $list = wpultra_disabled_categories();
    if ($disabled) { if (!in_array($cat, $list, true)) { $list[] = $cat; } }
    else { $list = array_values(array_diff($list, [$cat])); }
    update_option('wpultra_disabled_categories', $list);
    wp_send_json_success(['category' => $cat, 'disabled' => $disabled]);
});

/** Friendly label + blurb + dashicon for each capability category. */
function wpultra_category_ui_labels(): array {
    $known = [
        'filesystem'     => ['label' => 'Filesystem', 'desc' => 'Read/write/delete files in the WP root.', 'icon' => 'portfolio'],
        'code-execution' => ['label' => 'Code Execution', 'desc' => 'Run PHP and WP-CLI. The most powerful group.', 'icon' => 'editor-code'],
        'database'       => ['label' => 'Database', 'desc' => 'Direct parameterized SQL, search-replace, DB snapshots.', 'icon' => 'database'],
        'diagnostics'    => ['label' => 'Diagnostics', 'desc' => 'Debug log, site health, security & performance audits.', 'icon' => 'visibility'],
        'content'        => ['label' => 'WordPress Content', 'desc' => 'Posts, pages, CPTs, terms, menus, media, comments.', 'icon' => 'admin-post'],
        'users'          => ['label' => 'Users', 'desc' => 'User accounts, roles, and meta.', 'icon' => 'admin-users'],
        'system'         => ['label' => 'System', 'desc' => 'Plugins/themes, options, cron, cache, email, import/export.', 'icon' => 'admin-tools'],
        'memory'         => ['label' => 'Memory', 'desc' => 'Persistent cross-session memory.', 'icon' => 'lightbulb'],
        'skills'         => ['label' => 'Skills', 'desc' => 'Reusable AI skill documents.', 'icon' => 'welcome-learn-more'],
        'custom'         => ['label' => 'Custom Abilities', 'desc' => 'Declarative recipe engine (ability-write).', 'icon' => 'admin-plugins'],
        'elementor'      => ['label' => 'Elementor', 'desc' => 'Elementor v4 layout & design engine.', 'icon' => 'layout'],
        'gutenberg'      => ['label' => 'Gutenberg', 'desc' => 'Block content, patterns, reusable blocks.', 'icon' => 'block-default'],
        'woocommerce'    => ['label' => 'WooCommerce', 'desc' => 'Products, orders, customers, shipping, tax, gateways.', 'icon' => 'cart'],
        'seo'            => ['label' => 'SEO', 'desc' => 'Meta, internal links, technical + local SEO.', 'icon' => 'search'],
        'fields'         => ['label' => 'Custom Fields', 'desc' => 'ACF / Meta Box / Pods field groups and values.', 'icon' => 'index-card'],
        'fse'            => ['label' => 'Block-Theme Design', 'desc' => 'theme.json global styles, FSE templates, custom CSS.', 'icon' => 'admin-appearance'],
        'forms'          => ['label' => 'Forms', 'desc' => 'CF7 / WPForms / Gravity / Fluent forms and entries.', 'icon' => 'feedback'],
        'bricks'         => ['label' => 'Bricks', 'desc' => 'Bricks builder page content.', 'icon' => 'hammer'],
        'multilingual'   => ['label' => 'Multilingual', 'desc' => 'WPML / Polylang translations.', 'icon' => 'translation'],
        'jobs'           => ['label' => 'Background Jobs', 'desc' => 'Long operations run via WP-Cron (bulk meta, audits, search-replace).', 'icon' => 'clock'],
        'undo'           => ['label' => 'Universal Undo', 'desc' => 'Auto-snapshot before option/CSS/theme.json/term changes; roll back on demand.', 'icon' => 'undo'],
        'playbooks'      => ['label' => 'Playbooks', 'desc' => 'Chain many abilities into one declarative multi-step run.', 'icon' => 'list-view'],
    ];
    // Derive rows from the ability map so every category — including ones added
    // by future waves — stays toggleable without touching this curated list.
    $out = [];
    foreach (array_keys(wpultra_ability_category_map()) as $cat) {
        $out[$cat] = $known[$cat] ?? ['label' => ucwords(str_replace('-', ' ', $cat)), 'desc' => '', 'icon' => 'admin-generic'];
    }
    return $out;
}

/**
 * Grouped, labelled view of the Wave 1 abilities for the admin UI.
 *
 * @return array<string, array{icon:string, items:array<string, array{label:string, desc:string}>}>
 */
function wpultra_abilities_groups(): array {
    return [
        'Filesystem' => [
            'icon' => 'portfolio',
            'items' => [
                'read-file'      => ['label' => 'Read File', 'desc' => 'Read a file inside the allowed base directory.'],
                'write-file'     => ['label' => 'Write File', 'desc' => 'Write or append to a file (atomic).'],
                'edit-file'      => ['label' => 'Edit File', 'desc' => 'Replace a unique substring in a file.'],
                'delete-file'    => ['label' => 'Delete File', 'desc' => 'Delete a file (protected paths refused).'],
                'list-directory' => ['label' => 'List Directory', 'desc' => 'List the entries of a directory.'],
            ],
        ],
        'Code & System' => [
            'icon' => 'editor-code',
            'items' => [
                'run-wp-cli'  => ['label' => 'Run WP-CLI', 'desc' => 'Execute a WP-CLI command in the site root.'],
                'execute-php' => ['label' => 'Execute PHP', 'desc' => 'Evaluate PHP and capture output + return value.'],
            ],
        ],
        'Database & Diagnostics' => [
            'icon' => 'database',
            'items' => [
                'execute-wp-query' => ['label' => 'Execute WP Query', 'desc' => 'Parameterized SQL with a destructive-confirm gate.'],
                'read-debug-log'   => ['label' => 'Read Debug Log', 'desc' => 'Tail the WordPress debug.log.'],
            ],
        ],
        'Memory' => [
            'icon' => 'lightbulb',
            'items' => [
                'memory-save'   => ['label' => 'Save Memory', 'desc' => 'Create or update a persistent memory.'],
                'memory-get'    => ['label' => 'Get Memory', 'desc' => 'Fetch one memory by id.'],
                'memory-list'   => ['label' => 'List Memories', 'desc' => 'List memories (optionally by type).'],
                'memory-delete' => ['label' => 'Delete Memory', 'desc' => 'Delete a memory entry.'],
            ],
        ],
        'WordPress Content' => [
            'icon' => 'admin-post',
            'items' => [
                'create-post' => ['label' => 'Create Post', 'desc' => 'Create a post, page, or CPT (+ meta, terms).'],
                'update-post' => ['label' => 'Update Post', 'desc' => 'Update fields, meta, terms, featured image.'],
                'delete-post' => ['label' => 'Delete Post', 'desc' => 'Trash or permanently delete content.'],
            ],
        ],
        'Skills' => [
            'icon' => 'welcome-learn-more',
            'items' => [
                'skill-get'    => ['label' => 'Get Skill', 'desc' => 'Read a skill body by slug.'],
                'skill-write'  => ['label' => 'Write Skill', 'desc' => 'Create or replace a user skill.'],
                'skill-edit'   => ['label' => 'Edit Skill', 'desc' => 'Surgically edit a skill body.'],
                'skill-delete' => ['label' => 'Delete Skill', 'desc' => 'Delete a user skill by slug.'],
            ],
        ],
    ];
}

function wpultra_abilities_render(): void {
    $rules = (array) get_option('wpultra_ability_rules', []);
    $groups = wpultra_abilities_groups();
    $total = 0;
    $disabled_count = 0;
    foreach ($groups as $g) {
        foreach ($g['items'] as $slug => $_i) {
            $total++;
            if (!empty($rules['wpultra/' . $slug]['disabled'])) { $disabled_count++; }
        }
    }
    $enabled_count = $total - $disabled_count;
    $nonce = wp_create_nonce('wpultra_toggle');
    ?>
    <div class="wrap wpu-wrap">
        <div class="wpu-head">
            <div>
                <h1 class="wpu-title"><span class="dashicons dashicons-superhero"></span> Abilities</h1>
                <p class="wpu-sub">Toggle which MCP abilities your AI client can use. Changes save instantly — no Save button.</p>
            </div>
            <div class="wpu-counter">
                <span class="wpu-pill wpu-pill-on"><strong id="wpu-enabled"><?php echo (int) $enabled_count; ?></strong> enabled</span>
                <span class="wpu-pill wpu-pill-off"><strong id="wpu-disabled"><?php echo (int) $disabled_count; ?></strong> disabled</span>
            </div>
        </div>

        <?php $cat_labels = wpultra_category_ui_labels(); $disabled_cats = wpultra_disabled_categories(); ?>
        <div class="wpu-card" style="margin-bottom:22px;">
            <div class="wpu-card-head"><span class="dashicons dashicons-shield-alt"></span><span>Capability categories</span>
                <span style="margin-left:auto;font-weight:400;color:#787c82;font-size:12px;">Turn a whole group off — its abilities won't load at all.</span></div>
            <div class="wpu-list">
            <?php foreach ($cat_labels as $cat => $meta) : $coff = in_array($cat, $disabled_cats, true); ?>
                <div class="wpu-row">
                    <div class="wpu-info">
                        <div class="wpu-row-title"><span class="dashicons dashicons-<?php echo esc_attr($meta['icon']); ?>" style="color:#6d4afe;"></span> <?php echo esc_html($meta['label']); ?>
                            <code class="wpu-slug"><?php echo esc_html($cat); ?></code></div>
                        <div class="wpu-desc"><?php echo esc_html($meta['desc']); ?></div>
                    </div>
                    <label class="wpu-switch" title="<?php echo $coff ? 'Disabled' : 'Enabled'; ?>">
                        <input type="checkbox" class="wpu-cat-toggle" data-cat="<?php echo esc_attr($cat); ?>" <?php checked(!$coff); ?>>
                        <span class="wpu-track"><span class="wpu-knob"></span></span>
                    </label>
                </div>
            <?php endforeach; ?>
            </div>
        </div>

        <div class="wpu-grid">
        <?php foreach ($groups as $title => $group) : ?>
            <div class="wpu-card">
                <div class="wpu-card-head">
                    <span class="dashicons dashicons-<?php echo esc_attr($group['icon']); ?>"></span>
                    <span><?php echo esc_html($title); ?></span>
                </div>
                <div class="wpu-list">
                    <?php foreach ($group['items'] as $slug => $item) :
                        $is_disabled = !empty($rules['wpultra/' . $slug]['disabled']);
                        ?>
                        <div class="wpu-row">
                            <div class="wpu-info">
                                <div class="wpu-row-title">
                                    <?php echo esc_html($item['label']); ?>
                                    <code class="wpu-slug">wpultra/<?php echo esc_html($slug); ?></code>
                                </div>
                                <div class="wpu-desc"><?php echo esc_html($item['desc']); ?></div>
                            </div>
                            <label class="wpu-switch" title="<?php echo $is_disabled ? 'Disabled' : 'Enabled'; ?>">
                                <input type="checkbox" class="wpu-toggle" data-slug="<?php echo esc_attr($slug); ?>" <?php checked(!$is_disabled); ?>>
                                <span class="wpu-track"><span class="wpu-knob"></span></span>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
        </div>

        <span id="wpu-toast" class="wpu-toast">Saved</span>
    </div>

    <style>
        .wpu-wrap { max-width: 1280px; }
        .wpu-grid { column-count:3; column-gap:18px; }
        @media (max-width:1180px) { .wpu-grid { column-count:2; } }
        @media (max-width:782px)  { .wpu-grid { column-count:1; } }
        .wpu-head { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; margin:8px 0 22px; flex-wrap:wrap; }
        .wpu-title { display:flex; align-items:center; gap:10px; font-size:23px; margin:0; }
        .wpu-title .dashicons { color:#6d4afe; font-size:26px; width:26px; height:26px; }
        .wpu-sub { margin:6px 0 0; color:#646970; font-size:13px; }
        .wpu-counter { display:flex; gap:8px; }
        .wpu-pill { background:#fff; border:1px solid #e2e4e9; border-radius:999px; padding:6px 14px; font-size:13px; color:#50575e; box-shadow:0 1px 2px rgba(0,0,0,.04); }
        .wpu-pill strong { font-size:14px; }
        .wpu-pill-on strong { color:#1a9d5a; }
        .wpu-pill-off strong { color:#c23b3b; }

        .wpu-card { background:#fff; border:1px solid #e6e7eb; border-radius:14px; margin:0 0 18px; overflow:hidden;
            display:inline-block; width:100%; break-inside:avoid; -webkit-column-break-inside:avoid;
            box-shadow:0 6px 20px rgba(18,20,40,.06), 0 1px 3px rgba(18,20,40,.05); transition:box-shadow .2s ease; }
        .wpu-card:hover { box-shadow:0 10px 30px rgba(18,20,40,.10), 0 2px 6px rgba(18,20,40,.06); }
        .wpu-card-head { display:flex; align-items:center; gap:10px; padding:15px 20px; font-weight:600; font-size:14px;
            color:#1d2327; background:linear-gradient(180deg,#fbfbfd,#f5f6f9); border-bottom:1px solid #eceef2; letter-spacing:.2px; }
        .wpu-card-head .dashicons { color:#6d4afe; }

        .wpu-row { display:flex; align-items:center; justify-content:space-between; gap:16px; padding:14px 20px; border-bottom:1px solid #f1f2f5; transition:background .15s ease; }
        .wpu-row:last-child { border-bottom:0; }
        .wpu-row:hover { background:#fafaff; }
        .wpu-row-title { font-weight:600; color:#1d2327; font-size:14px; display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
        .wpu-slug { background:#f0f0f4; color:#6d4afe; border-radius:6px; padding:2px 8px; font-size:11px; }
        .wpu-desc { color:#787c82; font-size:12.5px; margin-top:3px; }

        /* Toggle switch */
        .wpu-switch { position:relative; display:inline-block; flex:0 0 auto; cursor:pointer; }
        .wpu-switch input { position:absolute; opacity:0; width:0; height:0; }
        .wpu-track { display:block; width:46px; height:26px; border-radius:999px; background:#cfd2da;
            transition:background .25s ease; box-shadow:inset 0 1px 3px rgba(0,0,0,.18); }
        .wpu-knob { position:absolute; top:3px; left:3px; width:20px; height:20px; border-radius:50%; background:#fff;
            box-shadow:0 2px 5px rgba(0,0,0,.28); transition:transform .25s cubic-bezier(.4,.0,.2,1); }
        .wpu-switch input:checked + .wpu-track { background:linear-gradient(135deg,#7b5cff,#5b34f2); }
        .wpu-switch input:checked + .wpu-track .wpu-knob { transform:translateX(20px); }
        .wpu-switch input:focus-visible + .wpu-track { outline:2px solid #5b34f2; outline-offset:2px; }
        .wpu-switch.wpu-saving .wpu-track { opacity:.6; }

        /* Toast */
        .wpu-toast { position:fixed; right:28px; bottom:28px; background:#1d2327; color:#fff; padding:11px 18px;
            border-radius:10px; font-size:13px; box-shadow:0 8px 24px rgba(0,0,0,.25); opacity:0; transform:translateY(10px);
            pointer-events:none; transition:opacity .2s ease, transform .2s ease; z-index:9999; }
        .wpu-toast.show { opacity:1; transform:translateY(0); }
        .wpu-toast.err { background:#b3261e; }
    </style>

    <script>
    (function () {
        var ajaxurl = window.ajaxurl || '<?php echo esc_url(admin_url('admin-ajax.php')); ?>';
        var nonce = '<?php echo esc_js($nonce); ?>';
        var toast = document.getElementById('wpu-toast');
        var enabledEl = document.getElementById('wpu-enabled');
        var disabledEl = document.getElementById('wpu-disabled');
        var toastTimer;

        function showToast(msg, isErr) {
            toast.textContent = msg;
            toast.classList.toggle('err', !!isErr);
            toast.classList.add('show');
            clearTimeout(toastTimer);
            toastTimer = setTimeout(function () { toast.classList.remove('show'); }, 1600);
        }

        function recount() {
            var on = 0, off = 0;
            document.querySelectorAll('.wpu-toggle').forEach(function (t) { t.checked ? on++ : off++; });
            enabledEl.textContent = on;
            disabledEl.textContent = off;
        }

        document.querySelectorAll('.wpu-toggle').forEach(function (input) {
            input.addEventListener('change', function () {
                var slug = input.getAttribute('data-slug');
                var disabled = input.checked ? '0' : '1';
                var sw = input.closest('.wpu-switch');
                sw.classList.add('wpu-saving');

                var body = new URLSearchParams();
                body.append('action', 'wpultra_toggle_ability');
                body.append('nonce', nonce);
                body.append('slug', slug);
                body.append('disabled', disabled);

                fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        sw.classList.remove('wpu-saving');
                        if (res && res.success) {
                            sw.title = input.checked ? 'Enabled' : 'Disabled';
                            recount();
                            showToast(input.checked ? slug + ' enabled' : slug + ' disabled', false);
                        } else {
                            input.checked = !input.checked; // revert
                            showToast('Could not save — try again', true);
                        }
                    })
                    .catch(function () {
                        sw.classList.remove('wpu-saving');
                        input.checked = !input.checked; // revert
                        showToast('Network error — not saved', true);
                    });
            });
        });

        // Category-level toggles (turn a whole group off).
        document.querySelectorAll('.wpu-cat-toggle').forEach(function (input) {
            input.addEventListener('change', function () {
                var cat = input.getAttribute('data-cat');
                var disabled = input.checked ? '0' : '1';
                var sw = input.closest('.wpu-switch');
                sw.classList.add('wpu-saving');
                var body = new URLSearchParams();
                body.append('action', 'wpultra_toggle_category');
                body.append('nonce', nonce);
                body.append('category', cat);
                body.append('disabled', disabled);
                fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        sw.classList.remove('wpu-saving');
                        if (res && res.success) {
                            sw.title = input.checked ? 'Enabled' : 'Disabled';
                            showToast(input.checked ? cat + ' enabled' : cat + ' disabled', false);
                        } else { input.checked = !input.checked; showToast('Could not save — try again', true); }
                    })
                    .catch(function () { sw.classList.remove('wpu-saving'); input.checked = !input.checked; showToast('Network error — not saved', true); });
            });
        });
    })();
    </script>
    <?php
}
