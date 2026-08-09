<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

require_once WPULTRA_DIR . 'includes/recipes/cpt.php';

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
    if (is_wp_error($parsed)) {
        $err = $parsed;
    } else {
        $valid = wpultra_recipe_validate($parsed);
        $err = $valid === true ? null : $valid;
    }

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

    // Must satisfy wpultra_recipe_validate(): kebab-case name, and for run:php
    // the code lives as a "code" string INSIDE the ```json block (the parser
    // only reads ```json fences — a ```php fence would be discarded).
    $example = <<<'RECIPE'
---
name: hello-world
description: Returns a greeting for the given name.
category: custom
run: php
---
```json
{
  "input": {
    "name": { "type": "string", "required": true, "description": "The name to greet." }
  },
  "code": "$name = $input['name'] ?? 'world';\nreturn ['greeting' => 'Hello, ' . $name . '!'];"
}
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
                                      onsubmit="return confirm('Delete ability <?php echo esc_js($row['name']); ?>? This cannot be undone.');">
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

        <?php if ($has_saved) : ?>
        wpuToast('Ability saved!');
        <?php elseif ($has_deleted) : ?>
        wpuToast('Ability deleted.');
        <?php endif; ?>
    })();
    </script>
    <?php
}
