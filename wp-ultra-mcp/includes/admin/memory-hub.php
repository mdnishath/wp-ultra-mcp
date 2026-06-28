<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

require_once WPULTRA_DIR . 'includes/memory/cpt.php';

// ---------------------------------------------------------------------------
// Save handler — add or edit a memory (nonce + capability required).
// ---------------------------------------------------------------------------
add_action('admin_post_wpultra_save_memory', function () {
    if (!current_user_can('manage_options') || !check_admin_referer('wpultra_save_memory')) {
        wp_die('forbidden');
    }

    $valid_types = ['user', 'feedback', 'project', 'reference'];
    $type = (string) ($_POST['type'] ?? '');
    $name = trim((string) ($_POST['name'] ?? ''));

    if ($name === '' || !in_array($type, $valid_types, true)) {
        set_transient(
            'wpultra_memory_err_' . get_current_user_id(),
            $name === '' ? 'Name is required.' : 'Type must be one of: user, feedback, project, reference.',
            60
        );
        wp_safe_redirect(admin_url('admin.php?page=wpultra-memory-hub&err=1'));
        exit;
    }

    $postarr = [
        'post_type'    => 'wpultra_memory',
        'post_status'  => 'publish',
        'post_title'   => $name,
        'post_excerpt' => sanitize_text_field((string) ($_POST['description'] ?? '')),
        'post_content' => wp_kses_post((string) ($_POST['content'] ?? '')),
    ];

    $id_param = (int) ($_POST['id'] ?? 0);
    if ($id_param > 0) {
        $postarr['ID'] = $id_param;
    }

    $id = wp_insert_post($postarr, true);
    if (is_wp_error($id)) {
        set_transient('wpultra_memory_err_' . get_current_user_id(), $id->get_error_message(), 60);
        wp_safe_redirect(admin_url('admin.php?page=wpultra-memory-hub&err=1'));
        exit;
    }

    update_post_meta((int) $id, '_wpultra_memory_type', $type);

    wp_safe_redirect(admin_url('admin.php?page=wpultra-memory-hub&saved=1'));
    exit;
});

// ---------------------------------------------------------------------------
// Delete handler.
// ---------------------------------------------------------------------------
add_action('admin_post_wpultra_delete_memory', function () {
    if (!current_user_can('manage_options') || !check_admin_referer('wpultra_delete_memory')) {
        wp_die('forbidden');
    }
    $post_id = (int) ($_POST['id'] ?? 0);
    if ($post_id > 0) {
        wp_delete_post($post_id, true);
    }
    wp_safe_redirect(admin_url('admin.php?page=wpultra-memory-hub&deleted=1'));
    exit;
});

// ---------------------------------------------------------------------------
// Render.
// ---------------------------------------------------------------------------
function wpultra_memory_hub_render(): void {
    $post_url    = esc_url(admin_url('admin-post.php'));
    $valid_types = ['user', 'feedback', 'project', 'reference'];

    $has_err     = isset($_GET['err'])     && $_GET['err']     === '1';
    $has_saved   = isset($_GET['saved'])   && $_GET['saved']   === '1';
    $has_deleted = isset($_GET['deleted']) && $_GET['deleted'] === '1';

    $err_msg = '';
    if ($has_err) {
        $err_msg = (string) get_transient('wpultra_memory_err_' . get_current_user_id());
        delete_transient('wpultra_memory_err_' . get_current_user_id());
    }

    // Pre-fill form from ?edit_id=.
    $edit_id          = (int) ($_GET['edit_id'] ?? 0);
    $edit_name        = '';
    $edit_type        = 'user';
    $edit_description = '';
    $edit_content     = '';

    if ($edit_id > 0) {
        $edit_post = get_post($edit_id);
        if ($edit_post && $edit_post->post_type === 'wpultra_memory') {
            $edit_name        = $edit_post->post_title;
            $edit_type        = (string) get_post_meta($edit_id, '_wpultra_memory_type', true);
            $edit_description = $edit_post->post_excerpt;
            $edit_content     = $edit_post->post_content;
            if (!in_array($edit_type, $valid_types, true)) {
                $edit_type = 'user';
            }
        } else {
            $edit_id = 0; // invalid / wrong post type — reset
        }
    }

    // Active type filter from GET.
    $filter_type = isset($_GET['type_filter']) && in_array($_GET['type_filter'], $valid_types, true)
        ? $_GET['type_filter'] : '';

    // Fetch memories.
    $memories = get_posts([
        'post_type'   => 'wpultra_memory',
        'numberposts' => 500,
        'post_status' => 'publish',
        'orderby'     => 'modified',
        'order'       => 'DESC',
    ]);

    // Apply filter.
    if ($filter_type !== '') {
        $memories = array_filter($memories, function (WP_Post $p) use ($filter_type) {
            return get_post_meta($p->ID, '_wpultra_memory_type', true) === $filter_type;
        });
    }

    // Badge color map.
    $type_colors = [
        'user'      => ['bg' => '#e0f2fe', 'fg' => '#0369a1'],
        'feedback'  => ['bg' => '#fef9c3', 'fg' => '#854d0e'],
        'project'   => ['bg' => '#f3e8ff', 'fg' => '#7c3aed'],
        'reference' => ['bg' => '#dcfce7', 'fg' => '#166534'],
    ];

    ?>
    <div class="wrap wpu-wrap">

        <!-- Page header -->
        <div class="wpu-head">
            <div>
                <h1 class="wpu-title"><span class="dashicons dashicons-database"></span> Memory Hub</h1>
                <p class="wpu-sub">View, create, edit, and delete persistent memories the MCP server can recall.</p>
            </div>
            <span class="wpu-pill wpu-pill-on">
                <strong><?php echo count(get_posts(['post_type' => 'wpultra_memory', 'numberposts' => -1, 'post_status' => 'publish', 'fields' => 'ids'])); ?></strong> memories
            </span>
        </div>

        <?php if ($has_saved) : ?>
            <div class="wpu-notice notice-success"><p>Memory saved successfully.</p></div>
        <?php endif; ?>
        <?php if ($has_deleted) : ?>
            <div class="wpu-notice notice-info"><p>Memory deleted.</p></div>
        <?php endif; ?>

        <!-- New / Edit memory card -->
        <div class="wpu-card wpu-pad">
            <div class="wpu-step">
                <span class="wpu-num"><?php echo $edit_id > 0 ? '✎' : '+'; ?></span>
                <?php echo $edit_id > 0 ? 'Edit memory' : 'New memory'; ?>
            </div>

            <?php if ($has_err && $err_msg !== '') : ?>
                <div class="wpu-notice notice-error">
                    <p><strong>Validation error:</strong> <?php echo esc_html($err_msg); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo $post_url; ?>">
                <?php wp_nonce_field('wpultra_save_memory'); ?>
                <input type="hidden" name="action" value="wpultra_save_memory">
                <?php if ($edit_id > 0) : ?>
                    <input type="hidden" name="id" value="<?php echo (int) $edit_id; ?>">
                <?php endif; ?>

                <table class="form-table wpu-form-table">
                    <tr>
                        <th><label for="wpu-mem-name">Name <span class="wpu-req">*</span></label></th>
                        <td><input type="text" id="wpu-mem-name" name="name" class="regular-text"
                                   value="<?php echo esc_attr($edit_name); ?>" required
                                   placeholder="e.g. User prefers dark mode"></td>
                    </tr>
                    <tr>
                        <th><label for="wpu-mem-type">Type <span class="wpu-req">*</span></label></th>
                        <td>
                            <select id="wpu-mem-type" name="type" class="wpu-select">
                                <?php foreach ($valid_types as $t) : ?>
                                    <option value="<?php echo esc_attr($t); ?>"<?php selected($edit_type, $t); ?>>
                                        <?php echo esc_html(ucfirst($t)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="wpu-mem-desc">Description</label></th>
                        <td><input type="text" id="wpu-mem-desc" name="description" class="regular-text"
                                   value="<?php echo esc_attr($edit_description); ?>"
                                   placeholder="Short summary (optional)"></td>
                    </tr>
                    <tr>
                        <th><label for="wpu-mem-content">Content</label></th>
                        <td><textarea id="wpu-mem-content" name="content" class="wpu-content-ta"
                                      placeholder="The memory content the AI will recall…"><?php echo esc_textarea($edit_content); ?></textarea></td>
                    </tr>
                </table>

                <div class="wpu-form-actions">
                    <button type="submit" class="button button-primary">
                        <?php echo $edit_id > 0 ? 'Update memory' : 'Save memory'; ?>
                    </button>
                    <?php if ($edit_id > 0) : ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=wpultra-memory-hub')); ?>"
                           class="button">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Memories list -->
        <div class="wpu-card wpu-pad">
            <div class="wpu-step">
                <span class="wpu-num"><span class="dashicons dashicons-list-view" style="font-size:14px;line-height:1.6;width:14px;height:14px;"></span></span>
                Memories
                <?php if ($filter_type !== '') : ?>
                    <span class="wpu-active-filter">— filtered: <?php echo esc_html($filter_type); ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=wpultra-memory-hub')); ?>" class="wpu-clear-filter">✕ clear</a>
                    </span>
                <?php endif; ?>
            </div>

            <!-- Type filter tabs -->
            <div class="wpu-type-tabs">
                <a href="<?php echo esc_url(admin_url('admin.php?page=wpultra-memory-hub')); ?>"
                   class="wpu-type-tab<?php echo $filter_type === '' ? ' active' : ''; ?>">All</a>
                <?php foreach ($valid_types as $t) :
                    $tab_url = admin_url('admin.php?page=wpultra-memory-hub&type_filter=' . urlencode($t));
                    ?>
                    <a href="<?php echo esc_url($tab_url); ?>"
                       class="wpu-type-tab<?php echo $filter_type === $t ? ' active' : ''; ?>">
                        <?php echo esc_html(ucfirst($t)); ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php if (empty($memories)) : ?>
                <div class="wpu-empty">
                    <span class="dashicons dashicons-database wpu-empty-icon"></span>
                    <p>
                        <?php echo $filter_type !== ''
                            ? 'No memories of type <strong>' . esc_html($filter_type) . '</strong>.'
                            : 'No memories yet. Use the form above to create your first memory.'; ?>
                    </p>
                </div>
            <?php else : ?>
                <div class="wpu-ability-list">
                    <?php foreach ($memories as $mem) :
                        $shape   = wpultra_memory_shape($mem);
                        $mem_id  = (int) $shape['id'];
                        $m_type  = $shape['type'] !== '' ? $shape['type'] : 'user';
                        $colors  = $type_colors[$m_type] ?? $type_colors['user'];
                        $edit_url = admin_url('admin.php?page=wpultra-memory-hub&edit_id=' . $mem_id);
                        ?>
                        <div class="wpu-ability-row">
                            <div class="wpu-info">
                                <div class="wpu-row-title">
                                    <?php echo esc_html($shape['name']); ?>
                                    <span class="wpu-type-badge"
                                          style="background:<?php echo esc_attr($colors['bg']); ?>;color:<?php echo esc_attr($colors['fg']); ?>;">
                                        <?php echo esc_html($m_type); ?>
                                    </span>
                                </div>
                                <?php if ($shape['description'] !== '') : ?>
                                    <div class="wpu-desc"><?php echo esc_html($shape['description']); ?></div>
                                <?php endif; ?>
                                <div class="wpu-updated">Updated <?php echo esc_html($shape['updated_at']); ?> UTC</div>
                            </div>
                            <div class="wpu-ability-actions">
                                <a href="<?php echo esc_url($edit_url); ?>" class="button">Edit</a>
                                <form method="post" action="<?php echo $post_url; ?>" style="display:inline;"
                                      onsubmit="return confirm('Delete memory &quot;<?php echo esc_js($shape['name']); ?>&quot;? This cannot be undone.');">
                                    <?php wp_nonce_field('wpultra_delete_memory'); ?>
                                    <input type="hidden" name="action" value="wpultra_delete_memory">
                                    <input type="hidden" name="id" value="<?php echo $mem_id; ?>">
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
        .wpu-req { color:#b3261e; }

        /* Notice banners */
        .wpu-notice { border-left:4px solid #6d4afe; background:#fafafa; border-radius:0 8px 8px 0; padding:10px 14px; margin:0 0 14px; }
        .wpu-notice.notice-success { border-color:#1a9d5a; background:#f0faf5; }
        .wpu-notice.notice-error   { border-color:#b3261e; background:#fff5f5; }
        .wpu-notice.notice-info    { border-color:#0072b1; background:#f0f7ff; }
        .wpu-notice p { margin:0; font-size:13px; }

        /* Form */
        .wpu-form-table td, .wpu-form-table th { padding:8px 10px 8px 0; vertical-align:middle; }
        .wpu-form-table th { width:130px; font-weight:600; font-size:13px; color:#3c434a; }
        .wpu-select { min-width:180px; }
        .wpu-content-ta { display:block; width:100%; min-height:140px; font-family:ui-monospace,SFMono-Regular,Menlo,monospace;
            font-size:12.5px; line-height:1.6; border:1px solid #d3d5db; border-radius:10px;
            padding:10px 12px; resize:vertical; box-sizing:border-box; color:#1d2327; background:#fafbff;
            box-shadow:inset 0 1px 3px rgba(0,0,0,.06); transition:border-color .15s; }
        .wpu-content-ta:focus { border-color:#6d4afe; outline:none; background:#fff; }
        .wpu-form-actions { margin-top:12px; display:flex; gap:8px; align-items:center; }

        /* Type filter tabs */
        .wpu-type-tabs { display:flex; gap:6px; flex-wrap:wrap; margin:0 0 16px; }
        .wpu-type-tab { display:inline-block; background:#f3f3f7; border:1px solid #e2e4e9; border-radius:8px;
            padding:5px 14px; font-size:12.5px; font-weight:600; color:#50575e; text-decoration:none; transition:all .15s ease; }
        .wpu-type-tab:hover { background:#ecebff; border-color:#a89cff; color:#3c434a; text-decoration:none; }
        .wpu-type-tab.active { background:linear-gradient(135deg,#7b5cff,#5b34f2); color:#fff; border-color:transparent;
            box-shadow:0 3px 10px rgba(91,52,242,.25); text-decoration:none; }
        .wpu-active-filter { font-size:13px; font-weight:400; color:#787c82; }
        .wpu-clear-filter { font-size:12px; color:#6d4afe; text-decoration:none; margin-left:4px; }
        .wpu-clear-filter:hover { text-decoration:underline; }

        /* Memory list */
        .wpu-ability-list { display:flex; flex-direction:column; gap:0; }
        .wpu-ability-row { display:flex; align-items:center; justify-content:space-between; gap:16px;
            padding:14px 4px; border-bottom:1px solid #f1f2f5; transition:background .15s ease; }
        .wpu-ability-row:last-child { border-bottom:0; }
        .wpu-ability-row:hover { background:#fafaff; }
        .wpu-info { flex:1; min-width:0; }
        .wpu-row-title { font-weight:600; color:#1d2327; font-size:14px; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
        .wpu-desc { color:#787c82; font-size:12.5px; margin-top:3px; }
        .wpu-updated { color:#b0b5bb; font-size:11.5px; margin-top:4px; }

        /* Type badge */
        .wpu-type-badge { border-radius:6px; padding:2px 9px; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.4px; }

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
        var toast = document.getElementById('wpu-toast'), tt;
        function showToast(m) {
            if (!toast) return;
            toast.textContent = m;
            toast.classList.add('show');
            clearTimeout(tt);
            tt = setTimeout(function () { toast.classList.remove('show'); }, 2200);
        }
        <?php if ($has_saved) : ?>
        showToast('Memory saved!');
        <?php elseif ($has_deleted) : ?>
        showToast('Memory deleted.');
        <?php endif; ?>
    })();
    </script>
    <?php
}
