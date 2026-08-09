<?php
/**
 * Uninstall cleanup for WP-Ultra-MCP.
 *
 * Runs only when the plugin is DELETED from wp-admin. Cron events and expired
 * transients are always cleared (they are pure runtime cruft). The full data
 * wipe — every wpultra_* option, all plugin CPT posts, and the sandbox /
 * snapshot / backup directories — happens ONLY when the operator opted in by
 * setting option `wpultra_delete_data_on_uninstall` to '1' (a toggle on the
 * Connect page). Default is to preserve data, so an accidental delete/reinstall
 * keeps memories, skills, custom abilities, and access policy intact.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) { exit(); }

global $wpdb;

/** Always: unschedule every wpultra_* cron event. */
$crons = function_exists('_get_cron_array') ? (array) _get_cron_array() : [];
foreach ($crons as $events) {
    foreach ((array) $events as $hook => $_) {
        if (is_string($hook) && strpos($hook, 'wpultra_') === 0) {
            wp_clear_scheduled_hook($hook);
        }
    }
}

/** Always: delete our transients (and their timeouts), site-wide. */
$like_t  = $wpdb->esc_like('_transient_wpultra_') . '%';
$like_tt = $wpdb->esc_like('_transient_timeout_wpultra_') . '%';
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $like_t, $like_tt));

// ---- Opt-in destructive wipe ----
if (get_option('wpultra_delete_data_on_uninstall') !== '1') {
    return;
}

/** All wpultra_* options (rate-limit counters, policy, stats, keys, config…). */
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('wpultra_') . '%'));

/** All posts of any wpultra_* CPT (memory, skill, ability, job, playbook, and the vertical CPTs), plus their meta. */
$post_ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type LIKE %s", $wpdb->esc_like('wpultra_') . '%'));
foreach ($post_ids as $pid) {
    wp_delete_post((int) $pid, true); // force-delete: also clears postmeta + term relationships
}

/** Sandbox / snapshot / backup directories (may hold AI-written PHP + DB dumps). */
$base = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : (rtrim(ABSPATH, '/\\') . '/wp-content');
$dirs = [rtrim($base, '/\\') . '/wpultra-sandbox'];
foreach ((array) glob(rtrim($base, '/\\') . '/uploads/wpultra-snapshots*') as $d) { $dirs[] = $d; }
foreach ((array) glob(rtrim($base, '/\\') . '/uploads/wpultra-backups*') as $d) { $dirs[] = $d; }
foreach ((array) glob(rtrim($base, '/\\') . '/wpultra-widgets') as $d) { $dirs[] = $d; }

require_once ABSPATH . 'wp-admin/includes/file.php';
if (function_exists('WP_Filesystem') && WP_Filesystem()) {
    global $wp_filesystem;
    foreach ($dirs as $dir) {
        if (is_string($dir) && $dir !== '' && $wp_filesystem->is_dir($dir)) {
            $wp_filesystem->delete($dir, true);
        }
    }
}
