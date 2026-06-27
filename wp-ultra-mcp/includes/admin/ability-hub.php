<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

// ---------------------------------------------------------------------------
// Save handler — textarea or uploaded file → parse → validate → CPT upsert.
// ---------------------------------------------------------------------------
add_action('admin_post_wpultra_save_recipe', function () {
    if (!current_user_can('manage_options') || !check_admin_referer('wpultra_save_recipe')) {
        wp_die('forbidden');
    }

    $raw = (string) ($_POST['recipe'] ?? '');
    if (!empty($_FILES['recipe_file']['tmp_name']) && is_uploaded_file($_FILES['recipe_file']['tmp_name'])) {
        $raw = (string) file_get_contents($_FILES['recipe_file']['tmp_name']);
    }

    $parsed = wpultra_recipe_parse($raw);
    $err = is_wp_error($parsed)
        ? $parsed
        : (wpultra_recipe_validate($parsed) === true ? null : wpultra_recipe_validate($parsed));

    if ($err) {
        set_transient('wpultra_recipe_err_' . get_current_user_id(), $err->get_error_message(), 60);
        wp_safe_redirect(admin_url('admin.php?page=wpultra-ability-hub&err=1'));
        exit;
    }

    $slug     = sanitize_title($parsed['name']);
    $existing = get_page_by_path($slug, OBJECT, 'wpultra_ability');
    $arr = [
        'post_type'    => 'wpultra_ability',
        'post_status'  => 'publish',
        'post_title'   => $slug,
        'post_name'    => $slug,
        'post_excerpt' => $parsed['description'],
        'post_content' => $raw,
    ];
    if ($existing) {
        $arr['ID'] = $existing->ID;
    }
    wp_insert_post($arr, true);
    wp_safe_redirect(admin_url('admin.php?page=wpultra-ability-hub&saved=1'));
    exit;
});

// ---------------------------------------------------------------------------
// Delete handler.
// ---------------------------------------------------------------------------
add_action('admin_post_wpultra_delete_recipe', function () {
    if (!current_user_can('manage_options') || !check_admin_referer('wpultra_delete_recipe')) {
        wp_die('forbidden');
    }
    $post_id = (int) ($_POST['post_id'] ?? 0);
    if ($post_id > 0) {
        wp_delete_post($post_id, true);
    }
    wp_safe_redirect(admin_url('admin.php?page=wpultra-ability-hub&deleted=1'));
    exit;
});

// ---------------------------------------------------------------------------
// Render.
// ---------------------------------------------------------------------------
function wpultra_ability_hub_render(): void {
    $post_url   = esc_url(admin_url('admin-post.php'));
    $recipes    = wpultra_recipe_all();
    $has_err    = isset($_GET['err']) && $_GET['err'] === '1';
    $has_saved  = isset($_GET['saved']) && $_GET['saved'] === '1';
    $has_deleted = isset($_GET['deleted']) && $_GET['deleted'] === '1';
    $err_msg    = '';
    if ($has_err) {
        $err_msg = (string) get_transient('wpultra_recipe_err_' . get_current_user_id());
        delete_transient('wpultra_recipe_err_' . get_current_user_id());
    }

    // Pre-fill: if editing an existing recipe, load its raw content via GET param.
    $edit_id  = (int) ($_GET['edit_id'] ?? 0);
    $edit_raw = '';
    if ($edit_id > 0) {
        $edit_post = get_post($edit_id);
        if ($edit_post && $edit_post->post_type === 'wpultra_ability') {
            $edit_raw = $edit_post->post_content;
        }
    }

    $example = <<<'RECIPE'
---
name: Hello World
description: Returns a greeting for the given name.
category: custom
run: php
---
```json
{
  "input": {
    "name": { "type": "string", "required": true, "description": "The name to greet." }
  }
}
```
```php
$name = $input['name'] ?? 'world';
return ['greeting' => 'Hello, ' . $name . '!'];
```
RECIPE;

    $textarea_value = $edit_raw !== '' ? $edit_raw : $example;

    ?>
    <div class="wrap wpu-wrap">

        <!-- Page header -->
        <div class="wpu-head">
            <div>
                <h1 class="wpu-title"><span class="dashicons dashicons-hammer"></span> Ability Hub</h1>
                <p class="wpu-sub">Create or upload declarative custom abilities (Markdown recipe files) that the MCP server exposes as tools.</p>
            </div>
            <span class="wpu-pill wpu-pill-on"><strong><?php echo count($recipes); ?></strong> custom</span>
        </div>

        <?php if ($has_saved) : ?>
            <div class="wpu-notice notice-success"><p>Ability saved successfully.</p></div>
        <?php endif; ?>
        <?php if ($has_deleted) : ?>
            <div class="wpu-notice notice-info"><p>Ability deleted.</p></div>
        <?php endif; ?>

        <!-- New / Edit card -->
        <div class="wpu-card wpu-pad">
            <div class="wpu-step">
                <span class="wpu-num"><?php echo $edit_id > 0 ? '✎' : '+'; ?></span>
                <?php echo $edit_id > 0 ? 'Edit ability' : 'New custom ability'; ?>
            </div>

            <?php if ($has_err && $err_msg !== '') : ?>
                <div class="wpu-notice notice-error">
                    <p><strong>Parse / validation error:</strong> <?php echo esc_html($err_msg); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo $post_url; ?>" enctype="multipart/form-data">
                <?php wp_nonce_field('wpultra_save_recipe'); ?>
                <input type="hidden" name="action" value="wpultra_save_recipe">

                <p class="wpu-muted" style="margin:0 0 8px;">
                    Paste a recipe below <em>or</em> upload a <code>.md</code> / <code>.json</code> / <code>.txt</code> file.
                    Uploaded file takes priority over the textarea.
                </p>

                <textarea name="recipe" id="wpu-recipe-ta" class="wpu-recipe-ta" spellcheck="false"><?php echo esc_textarea($textarea_value); ?></textarea>

                <div class="wpu-hub-row">
                    <label class="wpu-file-label">
                        <span class="dashicons dashicons-upload"></span>
                        <span>Upload file</span>
                        <input type="file" name="recipe_file" accept=".md,.json,.txt" style="display:none;" id="wpu-file-input">
                        <span id="wpu-file-name" class="wpu-muted" style="font-size:12px;"></span>
                    </label>
                    <button type="submit" class="button button-primary">Save ability</button>
                </div>
            </form>
        </div>

        <!-- Custom abilities list -->
        <div class="wpu-card wpu-pad">
            <div class="wpu-step"><span class="wpu-num"><span class="dashicons dashicons-list-view" style="font-size:14px;line-height:1.6;width:14px;height:14px;"></span></span> Custom abilities</div>

            <?php if (empty($recipes)) : ?>
                <div class="wpu-empty">
                    <span class="dashicons dashicons-lightbulb wpu-empty-icon"></span>
                    <p>No custom abilities yet. Paste a recipe above and click <strong>Save ability</strong> to create your first one.</p>
                </div>
            <?php else : ?>
                <div class="wpu-ability-list">
                    <?php foreach ($recipes as $row) : ?>
                        <div class="wpu-ability-row">
                            <div class="wpu-info">
                                <div class="wpu-row-title">
                                    <?php echo esc_html($row['name']); ?>
                                    <code class="wpu-slug">wpultra/<?php echo esc_html($row['slug']); ?></code>
                                    <?php if ($row['category'] !== '') : ?>
                                        <span class="wpu-cat-badge"><?php echo esc_html($row['category']); ?></span>
                                    <?php endif; ?>
                                    <?php if ($row['run'] !== '') : ?>
                                        <span class="wpu-run-badge wpu-run-<?php echo esc_attr($row['run']); ?>"><?php echo esc_html($row['run']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($row['description'] !== '') : ?>
                                    <div class="wpu-desc"><?php echo esc_html($row['description']); ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="wpu-ability-actions">
                                <a href="<?php echo esc_url(admin_url('admin.php?page=wpultra-ability-hub&edit_id=' . (int) $row['post_id'])); ?>"
                                   class="button">Edit</a>
                                <form method="post" action="<?php echo $post_url; ?>" style="display:inline;"
                                      onsubmit="return confirm('Delete ability <?php echo esc_attr($row['name']); ?>? This cannot be undone.');">
                                    <?php wp_nonce_field('wpultra_delete_recipe'); ?>
                                    <input type="hidden" name="action" value="wpultra_delete_recipe">
                                    <input type="hidden" name="post_id" value="<?php echo (int) $row['post_id']; ?>">
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

        /* Recipe textarea */
        .wpu-recipe-ta {
            display:block; width:100%; min-height:260px; font-family:ui-monospace,SFMono-Regular,Menlo,monospace;
            font-size:12.5px; line-height:1.6; border:1px solid #d3d5db; border-radius:10px;
            padding:14px; resize:vertical; box-sizing:border-box; color:#1d2327; background:#fafbff;
            box-shadow:inset 0 1px 3px rgba(0,0,0,.06); transition:border-color .15s;
        }
        .wpu-recipe-ta:focus { border-color:#6d4afe; outline:none; background:#fff; }

        .wpu-hub-row { display:flex; align-items:center; justify-content:space-between; gap:14px; margin-top:12px; flex-wrap:wrap; }

        .wpu-file-label { display:inline-flex; align-items:center; gap:7px; cursor:pointer;
            background:#f3f3f7; border:1px solid #e2e4e9; border-radius:10px; padding:8px 14px;
            font-size:13px; color:#50575e; transition:background .15s; }
        .wpu-file-label:hover { background:#ecebff; border-color:#a89cff; }
        .wpu-file-label .dashicons { color:#6d4afe; }

        /* Ability list */
        .wpu-ability-list { display:flex; flex-direction:column; gap:0; }
        .wpu-ability-row { display:flex; align-items:center; justify-content:space-between; gap:16px;
            padding:14px 4px; border-bottom:1px solid #f1f2f5; transition:background .15s ease; }
        .wpu-ability-row:last-child { border-bottom:0; }
        .wpu-ability-row:hover { background:#fafaff; }
        .wpu-info { flex:1; min-width:0; }
        .wpu-row-title { font-weight:600; color:#1d2327; font-size:14px; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
        .wpu-slug { background:#f0f0f4; color:#6d4afe; border-radius:6px; padding:2px 8px; font-size:11px; }
        .wpu-desc { color:#787c82; font-size:12.5px; margin-top:3px; }

        /* Badges */
        .wpu-cat-badge { background:#eef2ff; color:#4338ca; border-radius:6px; padding:2px 8px; font-size:11px; font-weight:600; }
        .wpu-run-badge { border-radius:6px; padding:2px 8px; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.4px; }
        .wpu-run-php    { background:#f3e8ff; color:#7c3aed; }
        .wpu-run-wp-cli { background:#e0f2fe; color:#0369a1; }
        .wpu-run-http   { background:#fef9c3; color:#854d0e; }

        /* Actions */
        .wpu-ability-actions { display:flex; align-items:center; gap:8px; flex:0 0 auto; }
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

        // Auto-dismiss toast for saved/deleted notices.
        var toast = document.getElementById('wpu-toast');
        var tt;
        function showToast(m) {
            if (!toast) return;
            toast.textContent = m;
            toast.classList.add('show');
            clearTimeout(tt);
            tt = setTimeout(function () { toast.classList.remove('show'); }, 2200);
        }
        <?php if ($has_saved) : ?>
        showToast('Ability saved!');
        <?php elseif ($has_deleted) : ?>
        showToast('Ability deleted.');
        <?php endif; ?>
    })();
    </script>
    <?php
}
