<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

require_once WPULTRA_DIR . 'includes/skills/sources.php';

// ---------------------------------------------------------------------------
// Save handler — textarea or uploaded .md → parse frontmatter → CPT upsert.
// ---------------------------------------------------------------------------
add_action('admin_post_wpultra_save_skill', function () {
    if (!current_user_can('manage_options') || !check_admin_referer('wpultra_save_skill')) {
        wp_die('forbidden');
    }

    $raw = (string) ($_POST['skill'] ?? '');
    if (!empty($_FILES['skill_file']['tmp_name']) && is_uploaded_file($_FILES['skill_file']['tmp_name'])) {
        $raw = (string) file_get_contents($_FILES['skill_file']['tmp_name']);
    }

    $parsed = wpultra_skill_parse_frontmatter($raw);
    $slug   = sanitize_title((string) ($parsed['name'] ?? ''));
    if ($slug === '') {
        set_transient('wpultra_skill_err_' . get_current_user_id(), 'The skill name is required (set "name:" in the frontmatter).', 60);
        wp_safe_redirect(admin_url('admin.php?page=wpultra-skill-hub&err=1'));
        exit;
    }

    // Refuse overwriting built-in skills.
    $all = wpultra_skill_all();
    if (isset($all[$slug]) && $all[$slug]['source'] === 'built-in') {
        set_transient('wpultra_skill_err_' . get_current_user_id(), "Skill \"$slug\" is a built-in and cannot be replaced via the Hub.", 60);
        wp_safe_redirect(admin_url('admin.php?page=wpultra-skill-hub&err=1'));
        exit;
    }

    $existing = get_page_by_path($slug, OBJECT, 'wpultra_skill');
    $postarr  = [
        'post_type'    => 'wpultra_skill',
        'post_status'  => 'publish',
        'post_title'   => $slug,
        'post_name'    => $slug,
        'post_excerpt' => (string) ($parsed['description'] ?? ''),
        'post_content' => (string) ($parsed['body'] ?? ''),
    ];
    if ($existing) {
        $postarr['ID'] = $existing->ID;
    }
    $post_id = wp_insert_post($postarr, true);
    if (is_wp_error($post_id)) {
        set_transient('wpultra_skill_err_' . get_current_user_id(), $post_id->get_error_message(), 60);
        wp_safe_redirect(admin_url('admin.php?page=wpultra-skill-hub&err=1'));
        exit;
    }
    update_post_meta((int) $post_id, '_enable_prompt',  ($parsed['enable_prompt'] ?? true)  ? '1' : '0');
    update_post_meta((int) $post_id, '_enable_agentic', ($parsed['enable_agentic'] ?? true) ? '1' : '0');

    wp_safe_redirect(admin_url('admin.php?page=wpultra-skill-hub&saved=1'));
    exit;
});

// ---------------------------------------------------------------------------
// Delete handler.
// ---------------------------------------------------------------------------
add_action('admin_post_wpultra_delete_skill', function () {
    if (!current_user_can('manage_options') || !check_admin_referer('wpultra_delete_skill')) {
        wp_die('forbidden');
    }
    $slug    = sanitize_text_field((string) ($_POST['slug'] ?? ''));
    $post_id = (int) ($_POST['post_id'] ?? 0);

    // Refuse deleting built-ins.
    $all = wpultra_skill_all();
    if (isset($all[$slug]) && $all[$slug]['source'] === 'built-in') {
        wp_die('Built-in skills cannot be deleted.');
    }

    if ($post_id > 0) {
        wp_delete_post($post_id, true);
    }
    wp_safe_redirect(admin_url('admin.php?page=wpultra-skill-hub&deleted=1'));
    exit;
});

// ---------------------------------------------------------------------------
// Export handler — output .md file for download (built-in + user allowed).
// ---------------------------------------------------------------------------
add_action('admin_post_wpultra_export_skill', function () {
    if (!current_user_can('manage_options') || !check_admin_referer('wpultra_export_skill')) {
        wp_die('forbidden');
    }
    $slug = sanitize_text_field((string) ($_POST['slug'] ?? ''));
    $all  = wpultra_skill_all();
    if (!isset($all[$slug])) {
        wp_die('Skill not found.');
    }
    $skill = $all[$slug];
    $md    = wpultra_skill_render_md($skill);

    header('Content-Type: text/markdown; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . sanitize_file_name($slug) . '.md"');
    header('Content-Length: ' . strlen($md));
    echo $md; // phpcs:ignore WordPress.Security.EscapeOutput
    exit;
});

// ---------------------------------------------------------------------------
// AJAX: toggle enable_prompt / enable_agentic on a USER skill instantly.
// ---------------------------------------------------------------------------
add_action('wp_ajax_wpultra_toggle_skill', function () {
    if (!current_user_can('manage_options') || !check_ajax_referer('wpultra_toggle_skill', 'nonce', false)) {
        wp_send_json_error(['message' => 'forbidden'], 403);
    }
    $slug = sanitize_text_field((string) ($_POST['slug'] ?? ''));
    $flag = sanitize_text_field((string) ($_POST['flag'] ?? ''));  // 'prompt' | 'agentic'
    $on   = (string) ($_POST['on'] ?? '') === '1';

    if (!in_array($flag, ['prompt', 'agentic'], true)) {
        wp_send_json_error(['message' => 'invalid flag'], 400);
    }

    $all = wpultra_skill_all();
    if (!isset($all[$slug])) {
        wp_send_json_error(['message' => 'unknown skill'], 400);
    }
    if ($all[$slug]['source'] === 'built-in') {
        wp_send_json_error(['message' => 'built-in skills are read-only'], 403);
    }

    $post_id  = (int) ($all[$slug]['post_id'] ?? 0);
    $meta_key = $flag === 'prompt' ? '_enable_prompt' : '_enable_agentic';
    update_post_meta($post_id, $meta_key, $on ? '1' : '0');

    wp_send_json_success(['slug' => $slug, 'flag' => $flag, 'on' => $on]);
});

// ---------------------------------------------------------------------------
// Render.
// ---------------------------------------------------------------------------
function wpultra_skill_hub_render(): void {
    $post_url    = esc_url(admin_url('admin-post.php'));
    $ajax_url    = esc_url(admin_url('admin-ajax.php'));
    $all_skills  = wpultra_skill_all();
    $toggle_nonce = wp_create_nonce('wpultra_toggle_skill');

    $built_ins  = array_filter($all_skills, static fn ($s) => $s['source'] === 'built-in');
    $user_skills = array_filter($all_skills, static fn ($s) => $s['source'] === 'user-cpt');

    $has_err     = isset($_GET['err'])     && $_GET['err']     === '1';
    $has_saved   = isset($_GET['saved'])   && $_GET['saved']   === '1';
    $has_deleted = isset($_GET['deleted']) && $_GET['deleted'] === '1';
    $err_msg     = '';
    if ($has_err) {
        $err_msg = (string) get_transient('wpultra_skill_err_' . get_current_user_id());
        delete_transient('wpultra_skill_err_' . get_current_user_id());
    }

    // Pre-fill textarea for edit.
    $edit_slug = sanitize_text_field((string) ($_GET['edit_slug'] ?? ''));
    $edit_raw  = '';
    if ($edit_slug !== '' && isset($all_skills[$edit_slug]) && $all_skills[$edit_slug]['source'] === 'user-cpt') {
        $edit_raw = wpultra_skill_render_md($all_skills[$edit_slug]);
    }

    $example = <<<'SKILL'
---
name: My Custom Skill
description: A brief description of what this skill does.
enable_prompt: true
enable_agentic: true
---
# My Custom Skill

Write the skill body here in Markdown. This content is returned when an AI
client calls skill-get with the slug "my-custom-skill".
SKILL;

    $textarea_value = $edit_raw !== '' ? $edit_raw : $example;
    ?>
    <div class="wrap wpu-wrap">

        <!-- Page header -->
        <div class="wpu-head">
            <div>
                <h1 class="wpu-title"><span class="dashicons dashicons-welcome-learn-more"></span> Skill Hub</h1>
                <p class="wpu-sub">Upload, manage and export Markdown skill documents exposed to your AI client.</p>
            </div>
            <div class="wpu-counter">
                <span class="wpu-pill wpu-pill-on"><strong><?php echo count($built_ins); ?></strong> built-in</span>
                <span class="wpu-pill"><strong><?php echo count($user_skills); ?></strong> yours</span>
            </div>
        </div>

        <?php if ($has_saved) : ?>
            <div class="wpu-notice notice-success"><p>Skill saved successfully.</p></div>
        <?php endif; ?>
        <?php if ($has_deleted) : ?>
            <div class="wpu-notice notice-info"><p>Skill deleted.</p></div>
        <?php endif; ?>

        <!-- Upload / new skill card -->
        <div class="wpu-card wpu-pad">
            <div class="wpu-step">
                <span class="wpu-num"><?php echo $edit_slug !== '' ? '✎' : '+'; ?></span>
                <?php echo $edit_slug !== '' ? 'Edit skill' : 'Upload / new skill'; ?>
            </div>

            <?php if ($has_err && $err_msg !== '') : ?>
                <div class="wpu-notice notice-error">
                    <p><strong>Error:</strong> <?php echo esc_html($err_msg); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo $post_url; ?>" enctype="multipart/form-data">
                <?php wp_nonce_field('wpultra_save_skill'); ?>
                <input type="hidden" name="action" value="wpultra_save_skill">

                <p class="wpu-muted" style="margin:0 0 8px;">
                    Paste a <code>.md</code> skill below <em>or</em> upload a <code>.md</code> file.
                    Uploaded file takes priority over the textarea.
                </p>

                <textarea name="skill" id="wpu-skill-ta" class="wpu-skill-ta" spellcheck="false"><?php echo esc_textarea($textarea_value); ?></textarea>

                <div class="wpu-hub-row">
                    <label class="wpu-file-label">
                        <span class="dashicons dashicons-upload"></span>
                        <span>Upload .md file</span>
                        <input type="file" name="skill_file" accept=".md" style="display:none;" id="wpu-file-input">
                        <span id="wpu-file-name" class="wpu-muted" style="font-size:12px;"></span>
                    </label>
                    <button type="submit" class="button button-primary">Save skill</button>
                </div>
            </form>
        </div>

        <!-- Built-in skills -->
        <div class="wpu-card wpu-pad">
            <div class="wpu-step">
                <span class="wpu-num"><span class="dashicons dashicons-lock" style="font-size:13px;line-height:1.8;width:13px;height:13px;"></span></span>
                Built-in skills
                <span class="wpu-badge-builtin">read-only</span>
            </div>

            <?php if (empty($built_ins)) : ?>
                <div class="wpu-empty">
                    <p>No built-in skills found.</p>
                </div>
            <?php else : ?>
                <div class="wpu-skill-list">
                    <?php foreach ($built_ins as $skill) : ?>
                        <div class="wpu-skill-row">
                            <div class="wpu-info">
                                <div class="wpu-row-title">
                                    <?php echo esc_html((string) ($skill['name'] ?? $skill['slug'])); ?>
                                    <code class="wpu-slug"><?php echo esc_html($skill['slug']); ?></code>
                                    <span class="wpu-badge-builtin">built-in</span>
                                </div>
                                <?php if (!empty($skill['description'])) : ?>
                                    <div class="wpu-desc"><?php echo esc_html($skill['description']); ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="wpu-skill-actions">
                                <form method="post" action="<?php echo $post_url; ?>" style="display:inline;">
                                    <?php wp_nonce_field('wpultra_export_skill'); ?>
                                    <input type="hidden" name="action" value="wpultra_export_skill">
                                    <input type="hidden" name="slug" value="<?php echo esc_attr($skill['slug']); ?>">
                                    <button type="submit" class="button">Export</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- User skills -->
        <div class="wpu-card wpu-pad">
            <div class="wpu-step">
                <span class="wpu-num"><span class="dashicons dashicons-edit" style="font-size:13px;line-height:1.8;width:13px;height:13px;"></span></span>
                Your skills
            </div>

            <?php if (empty($user_skills)) : ?>
                <div class="wpu-empty">
                    <span class="dashicons dashicons-welcome-learn-more wpu-empty-icon"></span>
                    <p>No custom skills yet. Paste a skill above and click <strong>Save skill</strong> to create your first one.</p>
                </div>
            <?php else : ?>
                <div class="wpu-skill-list">
                    <?php foreach ($user_skills as $skill) :
                        $ep = !empty($skill['enable_prompt']);
                        $ea = !empty($skill['enable_agentic']);
                        ?>
                        <div class="wpu-skill-row">
                            <div class="wpu-info">
                                <div class="wpu-row-title">
                                    <?php echo esc_html((string) ($skill['name'] ?? $skill['slug'])); ?>
                                    <code class="wpu-slug"><?php echo esc_html($skill['slug']); ?></code>
                                </div>
                                <?php if (!empty($skill['description'])) : ?>
                                    <div class="wpu-desc"><?php echo esc_html($skill['description']); ?></div>
                                <?php endif; ?>
                                <div class="wpu-toggles">
                                    <label class="wpu-switch" title="<?php echo $ep ? 'Prompt enabled' : 'Prompt disabled'; ?>">
                                        <input type="checkbox" class="wpu-skill-toggle"
                                               data-slug="<?php echo esc_attr($skill['slug']); ?>"
                                               data-flag="prompt"
                                               <?php checked($ep); ?>>
                                        <span class="wpu-track"><span class="wpu-knob"></span></span>
                                    </label>
                                    <span class="wpu-toggle-label">Prompt</span>

                                    <label class="wpu-switch" title="<?php echo $ea ? 'Agentic enabled' : 'Agentic disabled'; ?>" style="margin-left:14px;">
                                        <input type="checkbox" class="wpu-skill-toggle"
                                               data-slug="<?php echo esc_attr($skill['slug']); ?>"
                                               data-flag="agentic"
                                               <?php checked($ea); ?>>
                                        <span class="wpu-track"><span class="wpu-knob"></span></span>
                                    </label>
                                    <span class="wpu-toggle-label">Agentic</span>
                                </div>
                            </div>
                            <div class="wpu-skill-actions">
                                <a href="<?php echo esc_url(admin_url('admin.php?page=wpultra-skill-hub&edit_slug=' . urlencode($skill['slug']))); ?>"
                                   class="button">Edit</a>
                                <form method="post" action="<?php echo $post_url; ?>" style="display:inline;">
                                    <?php wp_nonce_field('wpultra_export_skill'); ?>
                                    <input type="hidden" name="action" value="wpultra_export_skill">
                                    <input type="hidden" name="slug" value="<?php echo esc_attr($skill['slug']); ?>">
                                    <button type="submit" class="button">Export</button>
                                </form>
                                <form method="post" action="<?php echo $post_url; ?>" style="display:inline;"
                                      onsubmit="return confirm('Delete skill <?php echo esc_js((string) ($skill['name'] ?? $skill['slug'])); ?>? This cannot be undone.');">
                                    <?php wp_nonce_field('wpultra_delete_skill'); ?>
                                    <input type="hidden" name="action" value="wpultra_delete_skill">
                                    <input type="hidden" name="slug" value="<?php echo esc_attr($skill['slug']); ?>">
                                    <input type="hidden" name="post_id" value="<?php echo (int) ($skill['post_id'] ?? 0); ?>">
                                    <button type="submit" class="button button-link-delete">Delete</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <span id="wpu-toast" class="wpu-toast">Saved</span>
    </div>

    <style>
        .wpu-wrap { max-width: 920px; }
        .wpu-head { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; margin:8px 0 20px; flex-wrap:wrap; }
        .wpu-title { display:flex; align-items:center; gap:10px; font-size:23px; margin:0; }
        .wpu-title .dashicons { color:#6d4afe; font-size:26px; width:26px; height:26px; }
        .wpu-sub { margin:6px 0 0; color:#646970; font-size:13px; }
        .wpu-counter { display:flex; gap:8px; }
        .wpu-pill { background:#fff; border:1px solid #e2e4e9; border-radius:999px; padding:7px 16px; font-size:13px; color:#50575e; box-shadow:0 1px 2px rgba(0,0,0,.04); }
        .wpu-pill-on strong { color:#1a9d5a; }

        .wpu-card { background:#fff; border:1px solid #e6e7eb; border-radius:14px; margin:0 0 18px; overflow:hidden;
            box-shadow:0 6px 20px rgba(18,20,40,.06), 0 1px 3px rgba(18,20,40,.05); }
        .wpu-pad { padding:18px 22px; }
        .wpu-step { display:flex; align-items:center; gap:10px; font-weight:600; font-size:15px; color:#1d2327; margin:0 0 14px; }
        .wpu-num { display:inline-flex; align-items:center; justify-content:center; width:26px; height:26px; border-radius:50%;
            background:linear-gradient(135deg,#7b5cff,#5b34f2); color:#fff; font-size:13px; flex:0 0 auto; }
        .wpu-muted { color:#787c82; font-size:12.5px; }

        /* Notice banners */
        .wpu-notice { border-left:4px solid #6d4afe; background:#fafafa; border-radius:0 8px 8px 0; padding:10px 14px; margin:0 0 14px; }
        .wpu-notice.notice-success { border-color:#1a9d5a; background:#f0faf5; }
        .wpu-notice.notice-error   { border-color:#b3261e; background:#fff5f5; }
        .wpu-notice.notice-info    { border-color:#0072b1; background:#f0f7ff; }
        .wpu-notice p { margin:0; font-size:13px; }

        /* Skill textarea */
        .wpu-skill-ta {
            display:block; width:100%; min-height:240px; font-family:ui-monospace,SFMono-Regular,Menlo,monospace;
            font-size:12.5px; line-height:1.6; border:1px solid #d3d5db; border-radius:10px;
            padding:14px; resize:vertical; box-sizing:border-box; color:#1d2327; background:#fafbff;
            box-shadow:inset 0 1px 3px rgba(0,0,0,.06); transition:border-color .15s;
        }
        .wpu-skill-ta:focus { border-color:#6d4afe; outline:none; background:#fff; }

        .wpu-hub-row { display:flex; align-items:center; justify-content:space-between; gap:14px; margin-top:12px; flex-wrap:wrap; }

        .wpu-file-label { display:inline-flex; align-items:center; gap:7px; cursor:pointer;
            background:#f3f3f7; border:1px solid #e2e4e9; border-radius:10px; padding:8px 14px;
            font-size:13px; color:#50575e; transition:background .15s; }
        .wpu-file-label:hover { background:#ecebff; border-color:#a89cff; }
        .wpu-file-label .dashicons { color:#6d4afe; }

        /* Built-in badge */
        .wpu-badge-builtin { background:#fef3c7; color:#92400e; border-radius:6px; padding:2px 8px; font-size:11px; font-weight:600; }

        /* Skill list rows */
        .wpu-skill-list { display:flex; flex-direction:column; gap:0; }
        .wpu-skill-row { display:flex; align-items:center; justify-content:space-between; gap:16px;
            padding:14px 4px; border-bottom:1px solid #f1f2f5; transition:background .15s ease; flex-wrap:wrap; }
        .wpu-skill-row:last-child { border-bottom:0; }
        .wpu-skill-row:hover { background:#fafaff; }
        .wpu-info { flex:1; min-width:0; }
        .wpu-row-title { font-weight:600; color:#1d2327; font-size:14px; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
        .wpu-slug { background:#f0f0f4; color:#6d4afe; border-radius:6px; padding:2px 8px; font-size:11px; }
        .wpu-desc { color:#787c82; font-size:12.5px; margin-top:3px; }

        /* Toggles */
        .wpu-toggles { display:flex; align-items:center; gap:6px; margin-top:10px; flex-wrap:wrap; }
        .wpu-toggle-label { font-size:12px; color:#50575e; }
        .wpu-switch { position:relative; display:inline-block; flex:0 0 auto; cursor:pointer; }
        .wpu-switch input { position:absolute; opacity:0; width:0; height:0; }
        .wpu-track { display:block; width:40px; height:22px; border-radius:999px; background:#cfd2da;
            transition:background .25s ease; box-shadow:inset 0 1px 3px rgba(0,0,0,.18); }
        .wpu-knob { position:absolute; top:2px; left:2px; width:18px; height:18px; border-radius:50%; background:#fff;
            box-shadow:0 2px 5px rgba(0,0,0,.28); transition:transform .25s cubic-bezier(.4,.0,.2,1); }
        .wpu-switch input:checked + .wpu-track { background:linear-gradient(135deg,#7b5cff,#5b34f2); }
        .wpu-switch input:checked + .wpu-track .wpu-knob { transform:translateX(18px); }
        .wpu-switch input:focus-visible + .wpu-track { outline:2px solid #5b34f2; outline-offset:2px; }
        .wpu-switch.wpu-saving .wpu-track { opacity:.6; }

        /* Actions */
        .wpu-skill-actions { display:flex; align-items:center; gap:8px; flex:0 0 auto; }
        .button-link-delete { color:#b3261e !important; background:transparent; border:none; cursor:pointer; font-size:13px; padding:4px 8px; }
        .button-link-delete:hover { color:#7f1d1d !important; }

        /* Empty state */
        .wpu-empty { text-align:center; padding:32px 20px; color:#787c82; }
        .wpu-empty-icon { font-size:36px; width:36px; height:36px; color:#c5beff; display:block; margin:0 auto 10px; }
        .wpu-empty p { font-size:13.5px; }

        /* Toast */
        .wpu-toast { position:fixed; right:28px; bottom:28px; background:#1d2327; color:#fff; padding:11px 18px;
            border-radius:10px; font-size:13px; box-shadow:0 8px 24px rgba(0,0,0,.25); opacity:0; transform:translateY(10px);
            pointer-events:none; transition:opacity .2s ease, transform .2s ease; z-index:9999; }
        .wpu-toast.show { opacity:1; transform:translateY(0); }
        .wpu-toast.err { background:#b3261e; }
    </style>

    <script>
    (function () {
        // Show filename when file selected.
        var fi = document.getElementById('wpu-file-input');
        var fn = document.getElementById('wpu-file-name');
        if (fi && fn) {
            fi.addEventListener('change', function () {
                fn.textContent = fi.files.length ? fi.files[0].name : '';
            });
        }

        var ajaxurl = window.ajaxurl || '<?php echo esc_js($ajax_url); ?>';
        var nonce   = '<?php echo esc_js($toggle_nonce); ?>';
        var toast   = document.getElementById('wpu-toast');
        var toastTimer;

        function showToast(msg, isErr) {
            if (!toast) return;
            toast.textContent = msg;
            toast.classList.toggle('err', !!isErr);
            toast.classList.add('show');
            clearTimeout(toastTimer);
            toastTimer = setTimeout(function () { toast.classList.remove('show'); }, 1800);
        }

        // Auto-dismiss page-load toasts.
        <?php if ($has_saved) : ?>
        showToast('Skill saved!', false);
        <?php elseif ($has_deleted) : ?>
        showToast('Skill deleted.', false);
        <?php endif; ?>

        // AJAX toggles for user skill Prompt / Agentic flags.
        document.querySelectorAll('.wpu-skill-toggle').forEach(function (input) {
            input.addEventListener('change', function () {
                var slug = input.getAttribute('data-slug');
                var flag = input.getAttribute('data-flag');
                var on   = input.checked ? '1' : '0';
                var sw   = input.closest('.wpu-switch');
                sw.classList.add('wpu-saving');

                var body = new URLSearchParams();
                body.append('action', 'wpultra_toggle_skill');
                body.append('nonce', nonce);
                body.append('slug', slug);
                body.append('flag', flag);
                body.append('on', on);

                fetch(ajaxurl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    sw.classList.remove('wpu-saving');
                    if (res && res.success) {
                        sw.title = input.checked
                            ? (flag === 'prompt' ? 'Prompt enabled' : 'Agentic enabled')
                            : (flag === 'prompt' ? 'Prompt disabled' : 'Agentic disabled');
                        showToast(flag + (input.checked ? ' enabled' : ' disabled'), false);
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
    })();
    </script>
    <?php
}
