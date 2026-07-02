<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Security + performance audits engine.
 *
 * Each audit follows the same shape:
 *  1. A WP-touching collector builds a plain context array (`wpultra_audits_security_collect()` /
 *     `wpultra_audits_performance_collect()`).
 *  2. A PURE rule evaluator turns that context array into findings (`wpultra_audits_security_evaluate()` /
 *     `wpultra_audits_performance_evaluate()`) — id, status (pass|warn|fail), detail. No WordPress calls.
 *  3. Performance additionally has a PURE scoring fn (`wpultra_audits_performance_score()`) turning
 *     findings into a 0-100 int.
 *
 * The evaluators/scorer are the testable core: tests feed synthetic context arrays straight through
 * them, no WordPress required.
 */

// ---------------------------------------------------------------------------
// Security audit
// ---------------------------------------------------------------------------

/** Pure: default/expected shape of the security-audit context. Documents every key the evaluator reads. */
function wpultra_audits_security_context_defaults(): array {
    return [
        'core_version'          => '',   // installed WP version string
        'core_latest_version'   => '',   // latest available WP version string ('' = unknown/up to date)
        'core_update_available' => false,
        'disallow_file_edit'    => false, // DISALLOW_FILE_EDIT defined && true
        'wp_debug'              => false,
        'wp_debug_display'      => false,
        'is_ssl'                => false,
        'table_prefix'          => 'wp_',
        'admin_usernames'       => [],   // usernames with role administrator
        'admin_count'           => 0,
        'uploads_index_exists'  => true, // wp-content/uploads/index.php present (directory-listing sentinel)
        'xmlrpc_enabled'        => true, // heuristic: xmlrpc_enabled filter result / option, no HTTP probe
        'plugin_updates_pending'=> 0,
        'theme_updates_pending' => 0,
        'inactive_plugins_count'=> 0,
        'salts_defined'         => true, // all 8 AUTH/SECURE/LOGGED_IN/NONCE key+salt constants defined
        'salts_placeholder'     => false, // any salt still equals the wp.org generator placeholder text
    ];
}

/**
 * PURE. Context array in -> findings array out. Each finding: id, status (pass|warn|fail), detail.
 * @param array $ctx see wpultra_audits_security_context_defaults() for keys (missing keys use defaults).
 * @return array<int,array{id:string,status:string,detail:string}>
 */
function wpultra_audits_security_evaluate(array $ctx): array {
    $ctx = array_merge(wpultra_audits_security_context_defaults(), $ctx);
    $findings = [];

    // core version vs latest
    if ($ctx['core_update_available']) {
        $findings[] = ['id' => 'core_update', 'status' => 'fail', 'detail' => "Core update available: {$ctx['core_version']} -> {$ctx['core_latest_version']}"];
    } else {
        $findings[] = ['id' => 'core_update', 'status' => 'pass', 'detail' => "Core is up to date ({$ctx['core_version']})"];
    }

    // file editing disabled
    $findings[] = $ctx['disallow_file_edit']
        ? ['id' => 'file_edit_disabled', 'status' => 'pass', 'detail' => 'DISALLOW_FILE_EDIT is set — plugin/theme editor disabled']
        : ['id' => 'file_edit_disabled', 'status' => 'warn', 'detail' => 'DISALLOW_FILE_EDIT is not set — the built-in plugin/theme file editor is reachable'];

    // debug display off in production
    $findings[] = ($ctx['wp_debug'] && $ctx['wp_debug_display'])
        ? ['id' => 'debug_display', 'status' => 'fail', 'detail' => 'WP_DEBUG_DISPLAY is on — PHP errors may leak to visitors']
        : ['id' => 'debug_display', 'status' => 'pass', 'detail' => 'Debug display is off (or WP_DEBUG is off)'];

    // users named 'admin'
    $has_admin_username = in_array('admin', array_map('strtolower', $ctx['admin_usernames']), true);
    $findings[] = $has_admin_username
        ? ['id' => 'admin_username', 'status' => 'warn', 'detail' => "A user named 'admin' exists — guessable username for brute-force"]
        : ['id' => 'admin_username', 'status' => 'pass', 'detail' => "No user named 'admin'"];

    // admin count
    $admin_count = (int) $ctx['admin_count'];
    if ($admin_count > 3) {
        $findings[] = ['id' => 'admin_count', 'status' => 'warn', 'detail' => "$admin_count administrator accounts — consider reducing"];
    } elseif ($admin_count === 0) {
        $findings[] = ['id' => 'admin_count', 'status' => 'warn', 'detail' => 'No administrator accounts detected'];
    } else {
        $findings[] = ['id' => 'admin_count', 'status' => 'pass', 'detail' => "$admin_count administrator account(s)"];
    }

    // weak table prefix
    $findings[] = ($ctx['table_prefix'] === 'wp_')
        ? ['id' => 'table_prefix', 'status' => 'warn', 'detail' => "Default table prefix 'wp_' in use"]
        : ['id' => 'table_prefix', 'status' => 'pass', 'detail' => "Custom table prefix '{$ctx['table_prefix']}' in use"];

    // SSL
    $findings[] = $ctx['is_ssl']
        ? ['id' => 'ssl', 'status' => 'pass', 'detail' => 'Site is served over HTTPS']
        : ['id' => 'ssl', 'status' => 'fail', 'detail' => 'Site is not served over HTTPS'];

    // directory listing sentinel
    $findings[] = $ctx['uploads_index_exists']
        ? ['id' => 'uploads_index', 'status' => 'pass', 'detail' => 'wp-content/uploads/index.php present']
        : ['id' => 'uploads_index', 'status' => 'warn', 'detail' => 'wp-content/uploads/index.php missing — directory listing may be exposed'];

    // xmlrpc
    $findings[] = $ctx['xmlrpc_enabled']
        ? ['id' => 'xmlrpc', 'status' => 'warn', 'detail' => 'XML-RPC appears enabled — a common brute-force/amplification target']
        : ['id' => 'xmlrpc', 'status' => 'pass', 'detail' => 'XML-RPC appears disabled'];

    // plugin/theme updates pending
    $pending = (int) $ctx['plugin_updates_pending'] + (int) $ctx['theme_updates_pending'];
    $findings[] = ($pending > 0)
        ? ['id' => 'updates_pending', 'status' => 'warn', 'detail' => "$pending plugin/theme update(s) pending"]
        : ['id' => 'updates_pending', 'status' => 'pass', 'detail' => 'No plugin/theme updates pending'];

    // inactive plugins
    $inactive = (int) $ctx['inactive_plugins_count'];
    $findings[] = ($inactive > 0)
        ? ['id' => 'inactive_plugins', 'status' => 'warn', 'detail' => "$inactive inactive plugin(s) installed — remove unused plugins to shrink attack surface"]
        : ['id' => 'inactive_plugins', 'status' => 'pass', 'detail' => 'No inactive plugins'];

    // salts
    if (!$ctx['salts_defined']) {
        $findings[] = ['id' => 'salts', 'status' => 'fail', 'detail' => 'One or more authentication salts/keys are not defined'];
    } elseif ($ctx['salts_placeholder']) {
        $findings[] = ['id' => 'salts', 'status' => 'fail', 'detail' => 'Authentication salts still contain placeholder text — generate real secrets'];
    } else {
        $findings[] = ['id' => 'salts', 'status' => 'pass', 'detail' => 'Authentication salts/keys are defined and appear unique'];
    }

    return $findings;
}

/** WP-touching: build the security-audit context array from the live environment. */
function wpultra_audits_security_collect(): array {
    $ctx = wpultra_audits_security_context_defaults();

    $ctx['core_version'] = (string) get_bloginfo('version');
    if (!function_exists('get_core_updates')) {
        require_once ABSPATH . 'wp-admin/includes/update.php';
    }
    $core_updates = function_exists('get_core_updates') ? get_core_updates() : [];
    $latest = '';
    $update_available = false;
    if (is_array($core_updates)) {
        foreach ($core_updates as $u) {
            $response = is_object($u) ? ($u->response ?? '') : ($u['response'] ?? '');
            $version  = is_object($u) ? ($u->current ?? '') : ($u['current'] ?? '');
            if ($response === 'upgrade' && $version !== '') {
                $latest = (string) $version;
                $update_available = true;
                break;
            }
            if ($version !== '' && $latest === '') { $latest = (string) $version; }
        }
    }
    $ctx['core_latest_version']   = $latest;
    $ctx['core_update_available'] = $update_available;

    $ctx['disallow_file_edit'] = defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT;
    $ctx['wp_debug']           = defined('WP_DEBUG') && WP_DEBUG;
    $ctx['wp_debug_display']   = defined('WP_DEBUG_DISPLAY') && WP_DEBUG_DISPLAY;
    $ctx['is_ssl']             = function_exists('is_ssl') ? is_ssl() : false;

    global $wpdb;
    $ctx['table_prefix'] = isset($wpdb->prefix) ? (string) $wpdb->prefix : 'wp_';

    $admins = get_users(['role' => 'administrator', 'fields' => ['user_login']]);
    $ctx['admin_usernames'] = array_map(static fn($u) => (string) $u->user_login, is_array($admins) ? $admins : []);
    $ctx['admin_count'] = count($ctx['admin_usernames']);

    $upload_dir = wp_upload_dir();
    $basedir = is_array($upload_dir) ? ($upload_dir['basedir'] ?? '') : '';
    $ctx['uploads_index_exists'] = $basedir !== '' && file_exists(rtrim($basedir, '/\\') . '/index.php');

    // Heuristic only (no HTTP probe): XML-RPC is enabled unless something has filtered it off.
    $ctx['xmlrpc_enabled'] = (bool) apply_filters('xmlrpc_enabled', true);

    if (!function_exists('get_plugin_updates')) {
        require_once ABSPATH . 'wp-admin/includes/update.php';
    }
    $ctx['plugin_updates_pending'] = function_exists('get_plugin_updates') ? count(get_plugin_updates()) : 0;
    $ctx['theme_updates_pending']  = function_exists('get_theme_updates') ? count(get_theme_updates()) : 0;

    if (!function_exists('get_plugins')) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
    $active_paths = (array) get_option('active_plugins', []);
    $all_plugins  = function_exists('get_plugins') ? get_plugins() : [];
    $ctx['inactive_plugins_count'] = max(0, count($all_plugins) - count($active_paths));

    $salt_constants = ['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'];
    $defined_all = true;
    $placeholder = false;
    foreach ($salt_constants as $c) {
        if (!defined($c)) { $defined_all = false; continue; }
        $val = (string) constant($c);
        if ($val === '' || str_contains($val, 'put your unique phrase here')) { $placeholder = true; }
    }
    $ctx['salts_defined']     = $defined_all;
    $ctx['salts_placeholder'] = $placeholder;

    return $ctx;
}

// ---------------------------------------------------------------------------
// Performance audit
// ---------------------------------------------------------------------------

/** Pure: default/expected shape of the performance-audit context. Documents every key the evaluator reads. */
function wpultra_audits_performance_context_defaults(): array {
    return [
        'autoload_total_bytes'  => 0,
        'autoload_top10'        => [],  // [['name'=>..., 'bytes'=>...], ...]
        'transient_count'       => 0,
        'transient_expired'     => 0,
        'posts_count'           => 0,
        'postmeta_count'        => 0,
        'revisions_count'       => 0,
        'attachment_count'      => 0,
        'attachment_files_missing' => 0, // attachments whose file is missing on disk
        'active_plugin_count'   => 0,
        'object_cache_present'  => false,
        'page_cache_detected'   => false,
        'cron_overdue_count'    => 0,
    ];
}

/**
 * PURE. Context array in -> findings array out. Each finding: id, status (pass|warn|fail), detail.
 * @return array<int,array{id:string,status:string,detail:string}>
 */
function wpultra_audits_performance_evaluate(array $ctx): array {
    $ctx = array_merge(wpultra_audits_performance_context_defaults(), $ctx);
    $findings = [];

    // autoloaded options size
    $bytes = (int) $ctx['autoload_total_bytes'];
    if ($bytes > 1_000_000) {
        $findings[] = ['id' => 'autoload_size', 'status' => 'fail', 'detail' => sprintf('Autoloaded options total %.1f KB — over 1 MB slows every page load', $bytes / 1024)];
    } elseif ($bytes > 500_000) {
        $findings[] = ['id' => 'autoload_size', 'status' => 'warn', 'detail' => sprintf('Autoloaded options total %.1f KB — approaching the ~1 MB danger zone', $bytes / 1024)];
    } else {
        $findings[] = ['id' => 'autoload_size', 'status' => 'pass', 'detail' => sprintf('Autoloaded options total %.1f KB', $bytes / 1024)];
    }

    // top 10 largest autoloaded options (informational, always present when any exist)
    $top = is_array($ctx['autoload_top10']) ? $ctx['autoload_top10'] : [];
    if ($top) {
        $names = array_map(static fn($o) => is_array($o) ? ($o['name'] ?? '') : '', array_slice($top, 0, 10));
        $findings[] = ['id' => 'autoload_top10', 'status' => 'pass', 'detail' => 'Largest autoloaded options: ' . implode(', ', array_filter($names))];
    }

    // transients
    $expired = (int) $ctx['transient_expired'];
    $findings[] = ($expired > 50)
        ? ['id' => 'transients_expired', 'status' => 'warn', 'detail' => "$expired expired transient(s) not yet garbage-collected"]
        : ['id' => 'transients_expired', 'status' => 'pass', 'detail' => "$expired expired transient(s)"];

    // revisions
    $revisions = (int) $ctx['revisions_count'];
    $findings[] = ($revisions > 1000)
        ? ['id' => 'revisions', 'status' => 'warn', 'detail' => "$revisions post revisions stored — consider limiting via WP_POST_REVISIONS"]
        : ['id' => 'revisions', 'status' => 'pass', 'detail' => "$revisions post revisions stored"];

    // attachments vs files
    $missing = (int) $ctx['attachment_files_missing'];
    $findings[] = ($missing > 0)
        ? ['id' => 'attachment_files', 'status' => 'warn', 'detail' => "$missing attachment(s) reference a missing file on disk"]
        : ['id' => 'attachment_files', 'status' => 'pass', 'detail' => 'All attachments have a file on disk'];

    // active plugin count
    $plugins = (int) $ctx['active_plugin_count'];
    $findings[] = ($plugins > 40)
        ? ['id' => 'active_plugins', 'status' => 'warn', 'detail' => "$plugins active plugins — high plugin count can slow every request"]
        : ['id' => 'active_plugins', 'status' => 'pass', 'detail' => "$plugins active plugins"];

    // object cache
    $findings[] = $ctx['object_cache_present']
        ? ['id' => 'object_cache', 'status' => 'pass', 'detail' => 'A persistent external object cache is active']
        : ['id' => 'object_cache', 'status' => 'warn', 'detail' => 'No persistent external object cache detected'];

    // page cache
    $findings[] = $ctx['page_cache_detected']
        ? ['id' => 'page_cache', 'status' => 'pass', 'detail' => 'A page-caching plugin was detected']
        : ['id' => 'page_cache', 'status' => 'warn', 'detail' => 'No page-caching plugin detected'];

    // cron overdue
    $overdue = (int) $ctx['cron_overdue_count'];
    $findings[] = ($overdue > 0)
        ? ['id' => 'cron_overdue', 'status' => 'warn', 'detail' => "$overdue cron event(s) overdue — wp-cron may not be firing"]
        : ['id' => 'cron_overdue', 'status' => 'pass', 'detail' => 'No overdue cron events'];

    return $findings;
}

/**
 * PURE. Findings -> a 0-100 int score. Each 'warn' costs 5 points, each 'fail' costs 12 points,
 * off the informational baseline (informational-only ids like autoload_top10 never affect score
 * since they are always emitted as 'pass'). Floors at 0, ceilings at 100.
 * @param array<int,array{id:string,status:string,detail:string}> $findings
 */
function wpultra_audits_performance_score(array $findings): int {
    $score = 100;
    foreach ($findings as $f) {
        $status = is_array($f) ? ($f['status'] ?? '') : '';
        if ($status === 'warn') { $score -= 5; }
        elseif ($status === 'fail') { $score -= 12; }
    }
    return max(0, min(100, $score));
}

/**
 * PURE. The set of `autoload` column values WordPress treats as "autoloaded".
 * WP 6.6+ replaced the sole 'yes' with a richer vocabulary ('on'/'auto'/'auto-on'),
 * so a `WHERE autoload = 'yes'` query silently misses autoloaded options on modern WP.
 * @return array<int,string>
 */
function wpultra_audits_autoload_yes_values(): array {
    return ['yes', 'on', 'auto', 'auto-on'];
}

/** WP-touching: build the performance-audit context array from the live environment. */
function wpultra_audits_performance_collect(): array {
    global $wpdb;
    $ctx = wpultra_audits_performance_context_defaults();

    $autoload_vals = wpultra_audits_autoload_yes_values();
    $placeholders  = implode(',', array_fill(0, count($autoload_vals), '%s'));
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT option_name AS name, LENGTH(option_value) AS bytes FROM {$wpdb->options} WHERE autoload IN ($placeholders)",
        ...$autoload_vals
    ), ARRAY_A);
    $rows = is_array($rows) ? $rows : [];
    $total = 0;
    foreach ($rows as &$r) { $r['bytes'] = (int) ($r['bytes'] ?? 0); $total += $r['bytes']; }
    unset($r);
    usort($rows, static fn($a, $b) => $b['bytes'] <=> $a['bytes']);
    $ctx['autoload_total_bytes'] = $total;
    $ctx['autoload_top10']       = array_slice($rows, 0, 10);

    $ctx['transient_count']   = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%' AND option_name NOT LIKE '\\_transient\\_timeout\\_%'");
    $now = time();
    $ctx['transient_expired'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d", $wpdb->esc_like('_transient_timeout_') . '%', $now));

    $ctx['posts_count']    = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}");
    $ctx['postmeta_count'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta}");
    $ctx['revisions_count'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", 'revision'));

    $ctx['attachment_count'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", 'attachment'));
    $missing = 0;
    $attachments = get_posts(['post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => 200, 'fields' => 'ids']);
    foreach ((array) $attachments as $id) {
        $file = get_attached_file((int) $id);
        if ($file !== false && $file !== '' && !file_exists($file)) { $missing++; }
    }
    $ctx['attachment_files_missing'] = $missing;

    $active_paths = (array) get_option('active_plugins', []);
    $ctx['active_plugin_count'] = count($active_paths);

    $ctx['object_cache_present'] = function_exists('wp_using_ext_object_cache') ? (bool) wp_using_ext_object_cache() : false;

    $ctx['page_cache_detected'] = (bool) wpultra_audits_page_cache_probe();

    $cron = _get_cron_array();
    $ctx['cron_overdue_count'] = wpultra_audits_count_overdue_cron(is_array($cron) ? $cron : [], time());

    return $ctx;
}

/** Pure: probe map for known page-caching plugins, evaluated via wpultra_snapshot_detect() semantics. */
function wpultra_audits_page_cache_probes(): array {
    return [
        ['label' => 'wp_rocket', 'function' => 'rocket_clean_domain'],
        ['label' => 'w3tc', 'function' => 'w3tc_flush_all'],
        ['label' => 'wp_super_cache', 'function' => 'wp_cache_clear_cache'],
        ['label' => 'litespeed', 'constant' => 'LSCWP_V'],
        ['label' => 'wp_fastest_cache', 'class' => 'WpFastestCache'],
        ['label' => 'breeze', 'constant' => 'BREEZE_VERSION'],
        ['label' => 'autoptimize', 'class' => 'autoptimizeMain'],
    ];
}

/** WP-touching: true if any known page-cache plugin is detected. */
function wpultra_audits_page_cache_probe(): bool {
    $detect = function_exists('wpultra_snapshot_detect') ? wpultra_snapshot_detect(wpultra_audits_page_cache_probes()) : [];
    foreach ($detect as $found) { if ($found) { return true; } }
    return false;
}

/**
 * PURE. Given the raw `_get_cron_array()` structure (timestamp => [hook => [key => event]]) and the
 * current unix time, count how many scheduled events are overdue (timestamp already passed).
 * @param array $cron
 */
function wpultra_audits_count_overdue_cron(array $cron, int $now): int {
    $count = 0;
    foreach ($cron as $timestamp => $hooks) {
        if (!is_numeric($timestamp) || !is_array($hooks)) { continue; }
        if ((int) $timestamp >= $now) { continue; }
        foreach ($hooks as $hook => $events) {
            if (!is_array($events)) { continue; }
            $count += count($events);
        }
    }
    return $count;
}
