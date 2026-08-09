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
        'triggers'       => ['label' => 'Event Triggers', 'desc' => 'Fire a webhook / playbook / log on post/order/comment/form events.', 'icon' => 'megaphone'],
        'access'         => ['label' => 'Access Control', 'desc' => 'Grant non-admin roles a limited ability set; rate-limit abuse.', 'icon' => 'lock'],
        'builders'       => ['label' => 'Page Builders', 'desc' => 'Divi / Beaver Builder / Oxygen content read-write.', 'icon' => 'editor-table'],
        'jetengine'      => ['label' => 'JetEngine', 'desc' => 'CPTs, taxonomies, meta boxes, relations, listings.', 'icon' => 'database-add'],
        'newsletter'     => ['label' => 'Newsletter', 'desc' => 'MailPoet / Mailchimp for WP subscribers and lists.', 'icon' => 'email-alt'],
    ];
    // Derive rows from the ability map so every category — including ones added
    // by future waves — stays toggleable without touching this curated list.
    $out = [];
    foreach (array_keys(wpultra_ability_category_map()) as $cat) {
        $out[$cat] = $known[$cat] ?? ['label' => ucwords(str_replace('-', ' ', $cat)), 'desc' => '', 'icon' => 'admin-generic'];
    }
    return $out;
}

/** Label + short description for one ability row — the registered ability's own
 *  metadata when available, else a Title-Cased slug. */
function wpultra_ability_row_meta(string $slug): array {
    if (function_exists('wp_get_ability')) {
        $a = wp_get_ability('wpultra/' . $slug);
        if ($a) {
            $desc = function_exists('wp_trim_words') ? wp_trim_words((string) $a->get_description(), 20, '…') : (string) $a->get_description();
            return ['label' => (string) $a->get_label(), 'desc' => $desc];
        }
    }
    return ['label' => ucwords(str_replace('-', ' ', $slug)), 'desc' => ''];
}

/**
 * Every ability (all 305), grouped by capability category for the admin UI —
 * generated from the ability→category map so new waves appear automatically,
 * not from a hand-maintained Wave-1 list.
 *
 * @return array<string, array{icon:string, items:array<string, array{label:string, desc:string}>}>
 */
function wpultra_abilities_groups(): array {
    $labels = wpultra_category_ui_labels();
    $groups = [];
    foreach (wpultra_ability_category_map() as $cat => $slugs) {
        $ui = $labels[$cat] ?? ['label' => ucwords(str_replace('-', ' ', $cat)), 'icon' => 'admin-generic'];
        $items = [];
        foreach ((array) $slugs as $slug) { $items[$slug] = wpultra_ability_row_meta($slug); }
        if ($items === []) { continue; }
        $title = $ui['label'];
        // Disambiguate the rare case of two categories sharing a display label.
        if (isset($groups[$title])) { $title .= ' (' . $cat . ')'; }
        $groups[$title] = ['icon' => $ui['icon'] ?? 'admin-generic', 'items' => $items];
    }
    return $groups;
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

        <div class="wpu-card" style="margin-bottom:18px;">
            <div class="wpu-pad" style="padding:14px 20px;">
                <input type="search" id="wpu-ability-search" placeholder="Search <?php echo (int) $total; ?> abilities by name or slug…" style="width:100%;box-sizing:border-box;padding:9px 12px;border:1px solid #d3d5db;border-radius:10px;font-size:13px;">
            </div>
        </div>

        <div class="wpu-grid">
        <?php foreach ($groups as $title => $group) : ?>
            <div class="wpu-card wpu-ability-card">
                <div class="wpu-card-head">
                    <span class="dashicons dashicons-<?php echo esc_attr($group['icon']); ?>"></span>
                    <span><?php echo esc_html($title); ?></span>
                </div>
                <div class="wpu-list">
                    <?php foreach ($group['items'] as $slug => $item) :
                        $is_disabled = !empty($rules['wpultra/' . $slug]['disabled']);
                        ?>
                        <div class="wpu-row" data-search="<?php echo esc_attr(strtolower($item['label'] . ' ' . $slug)); ?>">
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
        /* Page-specific: wider masonry layout than the shared 920px default. */
        .wpu-wrap { max-width: 1280px; }
    </style>

    <script>
    (function () {
        var ajaxurl = window.ajaxurl || '<?php echo esc_url(admin_url('admin-ajax.php')); ?>';
        var nonce = '<?php echo esc_js($nonce); ?>';
        var enabledEl = document.getElementById('wpu-enabled');
        var disabledEl = document.getElementById('wpu-disabled');

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
                            wpuToast(input.checked ? slug + ' enabled' : slug + ' disabled', false);
                        } else {
                            input.checked = !input.checked; // revert
                            wpuToast('Could not save — try again', true);
                        }
                    })
                    .catch(function () {
                        sw.classList.remove('wpu-saving');
                        input.checked = !input.checked; // revert
                        wpuToast('Network error — not saved', true);
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
                            wpuToast(input.checked ? cat + ' enabled' : cat + ' disabled', false);
                        } else { input.checked = !input.checked; wpuToast('Could not save — try again', true); }
                    })
                    .catch(function () { sw.classList.remove('wpu-saving'); input.checked = !input.checked; wpuToast('Network error — not saved', true); });
            });
        });

        // Client-side search: filter ability rows (and hide empty category cards).
        var search = document.getElementById('wpu-ability-search');
        if (search) {
            search.addEventListener('input', function () {
                var q = search.value.trim().toLowerCase();
                document.querySelectorAll('.wpu-ability-card').forEach(function (card) {
                    var shown = 0;
                    card.querySelectorAll('.wpu-row[data-search]').forEach(function (row) {
                        var hit = q === '' || row.getAttribute('data-search').indexOf(q) !== -1;
                        row.style.display = hit ? '' : 'none';
                        if (hit) { shown++; }
                    });
                    card.style.display = shown ? '' : 'none';
                });
            });
        }
    })();
    </script>
    <?php
}
