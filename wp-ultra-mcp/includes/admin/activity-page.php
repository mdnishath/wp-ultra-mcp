<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/** admin-post: clear the audit log. */
add_action('admin_post_wpultra_clear_audit', function () {
    if (!current_user_can('manage_options') || !check_admin_referer('wpultra_clear_audit')) {
        wp_die('forbidden');
    }
    update_option('wpultra_audit', []);
    wp_safe_redirect(admin_url('admin.php?page=wpultra-activity'));
    exit;
});

function wpultra_activity_render(): void {
    $log = get_option('wpultra_audit', []);
    if (!is_array($log)) { $log = []; }
    $log = array_reverse($log); // newest first
    $clear_url = wp_nonce_url(admin_url('admin-post.php?action=wpultra_clear_audit'), 'wpultra_clear_audit');
    ?>
    <div class="wrap wpu-wrap">
        <div class="wpu-head">
            <div>
                <h1 class="wpu-title"><span class="dashicons dashicons-list-view"></span> Activity log</h1>
                <p class="wpu-sub">The most recent privileged actions (PHP, WP-CLI, SQL writes, file writes/deletes, HTTP recipes) run by AI clients. Newest first; capped ring buffer.</p>
            </div>
            <?php if ($log) : ?>
                <a href="<?php echo esc_url($clear_url); ?>" class="button" onclick="return confirm('Clear the activity log?');">Clear log</a>
            <?php endif; ?>
        </div>

        <?php if (!$log) : ?>
            <div class="wpu-card" style="padding:24px;color:#646970;">No privileged actions recorded yet.</div>
        <?php else : ?>
            <table class="widefat striped" style="max-width:1100px;">
                <thead><tr>
                    <th style="width:160px;">Time (UTC)</th>
                    <th style="width:80px;">User</th>
                    <th style="width:150px;">Action</th>
                    <th style="width:70px;">Result</th>
                    <th>Summary</th>
                </tr></thead>
                <tbody>
                <?php foreach ($log as $row) :
                    $row = is_array($row) ? $row : [];
                    $uid = (int) ($row['user'] ?? 0);
                    $uname = $uid ? (function_exists('get_userdata') && ($u = get_userdata($uid)) ? $u->user_login : ('#' . $uid)) : '—';
                    $ok = !empty($row['ok']);
                    ?>
                    <tr>
                        <td><?php echo esc_html((string) ($row['ts'] ?? '')); ?></td>
                        <td><?php echo esc_html((string) $uname); ?></td>
                        <td><code><?php echo esc_html((string) ($row['action'] ?? '')); ?></code></td>
                        <td><?php echo $ok ? '<span style="color:#1a9d5a;">ok</span>' : '<span style="color:#c23b3b;">fail</span>'; ?></td>
                        <td><code style="white-space:pre-wrap;word-break:break-word;"><?php echo esc_html((string) ($row['summary'] ?? '')); ?></code></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}
