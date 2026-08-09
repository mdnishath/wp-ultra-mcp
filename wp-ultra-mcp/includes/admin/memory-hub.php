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


    <script>
    (function () {
        <?php if ($has_saved) : ?>
        wpuToast('Memory saved!');
        <?php elseif ($has_deleted) : ?>
        wpuToast('Memory deleted.');
        <?php endif; ?>
    })();
    </script>
    <?php
}
