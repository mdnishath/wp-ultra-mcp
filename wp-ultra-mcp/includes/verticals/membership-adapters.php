<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Third-party membership adapters (MemberPress / Paid Memberships Pro).
 *
 * Mirrors includes/verticals/lms-adapters.php: pure detection + driver
 * resolution (unit-testable), thin WP-calling drivers that degrade gracefully
 * when the plugin is absent. The built-in level/rule paywall
 * (includes/verticals/membership.php) stays the default; these drivers let an
 * agent on a site ALREADY running MemberPress or PMPro list levels, read a
 * user's membership, and grant/revoke memberships through the same
 * membership-manage ability (pass `plugin`).
 *
 * Writes go through each plugin's own API (pmpro_changeMembershipLevel /
 * MeprTransaction::store) so hooks, emails, and reporting stay coherent.
 */

/** WP_Error factory that works under WP and the bare test harness. */
function wpultra_memx_err(string $code, string $message) {
    if (function_exists('wpultra_err')) { return wpultra_err($code, $message); }
    return new WP_Error($code, $message);
}

/* ------------------------------------------------------------------ *
 * PURE: detection + driver resolution + shapes
 * ------------------------------------------------------------------ */

/** All third-party membership keys this domain knows about. Pure. */
function wpultra_memx_known(): array {
    return ['memberpress', 'pmpro'];
}

/** Human label for a membership key. Pure. */
function wpultra_memx_label(string $key): string {
    return match ($key) {
        'memberpress' => 'MemberPress',
        'pmpro'       => 'Paid Memberships Pro',
        default       => $key,
    };
}

/**
 * Detect each supported membership plugin and its version.
 * @return array<string,?string>
 */
function wpultra_memx_detect(): array {
    $out = ['memberpress' => null, 'pmpro' => null];
    if (defined('MEPR_VERSION')) {
        $out['memberpress'] = (string) MEPR_VERSION;
    } elseif (class_exists('MeprUser') || class_exists('MeprAppCtrl')) {
        $out['memberpress'] = '';
    }
    if (defined('PMPRO_VERSION')) {
        $out['pmpro'] = (string) PMPRO_VERSION;
    } elseif (function_exists('pmpro_getAllLevels')) {
        $out['pmpro'] = '';
    }
    return $out;
}

/**
 * Resolve which third-party driver to use. Pure over the detection map.
 * @return string|WP_Error
 */
function wpultra_memx_driver(string $explicit = '', ?array $detected = null) {
    if ($detected === null) { $detected = wpultra_memx_detect(); }
    if ($explicit !== '') {
        if (!in_array($explicit, wpultra_memx_known(), true)) {
            return wpultra_memx_err('membership_unknown_plugin', "Unknown membership plugin '{$explicit}'. Known: memberpress, pmpro (or builtin).");
        }
        if (($detected[$explicit] ?? null) === null) {
            return wpultra_memx_err('membership_plugin_unavailable', wpultra_memx_label($explicit) . ' is not active on this site.');
        }
        return $explicit;
    }
    foreach (wpultra_memx_known() as $key) {
        if (($detected[$key] ?? null) !== null) { return $key; }
    }
    return wpultra_memx_err('membership_plugin_unavailable', 'No supported membership plugin (MemberPress, Paid Memberships Pro) is active.');
}

/** Orientation summary (used by membership-manage detect-plugins). */
function wpultra_memx_status(): array {
    $detected = wpultra_memx_detect();
    $out = [];
    foreach (wpultra_memx_known() as $key) {
        $version = $detected[$key];
        $out[] = [
            'plugin'    => $key,
            'label'     => wpultra_memx_label($key),
            'installed' => $version !== null,
            'version'   => $version !== null ? $version : null,
        ];
    }
    return $out;
}

/** Unified level shape. Pure. */
function wpultra_memx_shape_level($id, string $name, $price, string $period, string $plugin): array {
    return [
        'id'     => (string) $id,
        'name'   => $name,
        'price'  => is_numeric($price) ? (float) $price : 0.0,
        'period' => $period,
        'plugin' => $plugin,
    ];
}

/** Unified member-status shape. Pure. */
function wpultra_memx_shape_member(int $user_id, array $levels, string $plugin): array {
    return [
        'user_id' => $user_id,
        'status'  => $levels === [] ? 'none' : 'active',
        'levels'  => $levels,
        'plugin'  => $plugin,
    ];
}

/* ------------------------------------------------------------------ *
 * THIN WP-calling drivers
 * ------------------------------------------------------------------ */

/** List levels/products. @return array<int,array>|WP_Error */
function wpultra_memx_list_levels(string $plugin) {
    try {
        if ($plugin === 'pmpro') {
            if (!function_exists('pmpro_getAllLevels')) { return wpultra_memx_err('membership_plugin_unavailable', 'PMPro API (pmpro_getAllLevels) missing.'); }
            $out = [];
            foreach ((array) pmpro_getAllLevels(true, true) as $lvl) {
                $period = ((int) ($lvl->cycle_number ?? 0)) > 0 ? strtolower((string) $lvl->cycle_period) : 'lifetime';
                $price  = is_numeric($lvl->billing_amount ?? null) && (float) $lvl->billing_amount > 0 ? $lvl->billing_amount : ($lvl->initial_payment ?? 0);
                $out[] = wpultra_memx_shape_level($lvl->id ?? 0, (string) ($lvl->name ?? ''), $price, $period, $plugin);
            }
            return $out;
        }
        if ($plugin === 'memberpress') {
            $posts = get_posts(['post_type' => 'memberpressproduct', 'post_status' => 'publish', 'numberposts' => 200, 'orderby' => 'title', 'order' => 'ASC']);
            $out = [];
            foreach ($posts as $p) {
                $price  = get_post_meta((int) $p->ID, '_mepr_product_price', true);
                $ptype  = (string) get_post_meta((int) $p->ID, '_mepr_product_period_type', true);
                $period = in_array($ptype, ['months', 'years', 'weeks'], true) ? rtrim($ptype, 's') : 'lifetime';
                $out[] = wpultra_memx_shape_level($p->ID, (string) $p->post_title, $price, $period, $plugin);
            }
            return $out;
        }
    } catch (\Throwable $e) {
        if (function_exists('wpultra_log_throwable')) { wpultra_log_throwable($e, 'membership-adapter'); }
        return wpultra_memx_err('membership_list_failed', wpultra_memx_label($plugin) . ' level listing failed: ' . $e->getMessage());
    }
    return wpultra_memx_err('membership_unknown_plugin', "No list-levels driver for '$plugin'.");
}

/** A user's membership status. @return array|WP_Error */
function wpultra_memx_member_status(string $plugin, int $user_id) {
    if ($user_id <= 0 || !get_userdata($user_id)) { return wpultra_memx_err('user_not_found', "User #$user_id not found."); }
    try {
        if ($plugin === 'pmpro') {
            if (!function_exists('pmpro_getMembershipLevelsForUser')) { return wpultra_memx_err('membership_plugin_unavailable', 'PMPro API (pmpro_getMembershipLevelsForUser) missing.'); }
            $levels = [];
            foreach ((array) pmpro_getMembershipLevelsForUser($user_id) as $lvl) {
                if (!$lvl) { continue; }
                $levels[] = [
                    'id'      => (string) ($lvl->id ?? ''),
                    'name'    => (string) ($lvl->name ?? ''),
                    'since'   => isset($lvl->startdate) && $lvl->startdate ? gmdate('Y-m-d', (int) $lvl->startdate) : null,
                    'expires' => isset($lvl->enddate) && $lvl->enddate ? gmdate('Y-m-d', (int) $lvl->enddate) : null,
                ];
            }
            return wpultra_memx_shape_member($user_id, $levels, $plugin);
        }
        if ($plugin === 'memberpress') {
            if (!class_exists('MeprUser')) { return wpultra_memx_err('membership_plugin_unavailable', 'MemberPress API (MeprUser) missing.'); }
            $mu = new MeprUser($user_id);
            $levels = [];
            foreach ((array) $mu->active_product_subscriptions('ids') as $pid) {
                $pid = (int) $pid;
                if ($pid <= 0) { continue; }
                $levels[] = ['id' => (string) $pid, 'name' => (string) get_the_title($pid), 'since' => null, 'expires' => null];
            }
            return wpultra_memx_shape_member($user_id, $levels, $plugin);
        }
    } catch (\Throwable $e) {
        if (function_exists('wpultra_log_throwable')) { wpultra_log_throwable($e, 'membership-adapter'); }
        return wpultra_memx_err('membership_status_failed', wpultra_memx_label($plugin) . ' status read failed: ' . $e->getMessage());
    }
    return wpultra_memx_err('membership_unknown_plugin', "No member-status driver for '$plugin'.");
}

/** Grant a membership via the plugin's own API. @return array|WP_Error */
function wpultra_memx_assign(string $plugin, int $user_id, string $level_id, int $expires = 0) {
    if ($user_id <= 0 || !get_userdata($user_id)) { return wpultra_memx_err('user_not_found', "User #$user_id not found."); }
    $lid = (int) $level_id;
    if ($lid <= 0) { return wpultra_memx_err('level_not_found', "level_id must be the numeric " . wpultra_memx_label($plugin) . " level/product id."); }
    try {
        if ($plugin === 'pmpro') {
            if (!function_exists('pmpro_changeMembershipLevel')) { return wpultra_memx_err('membership_plugin_unavailable', 'PMPro API (pmpro_changeMembershipLevel) missing.'); }
            $ok = pmpro_changeMembershipLevel($lid, $user_id);
            if (!$ok) { return wpultra_memx_err('membership_assign_failed', "PMPro rejected the level change (is #$lid a real level id?)."); }
            return ['user_id' => $user_id, 'level_id' => (string) $lid, 'plugin' => $plugin, 'assigned' => true];
        }
        if ($plugin === 'memberpress') {
            if (!class_exists('MeprTransaction')) { return wpultra_memx_err('membership_plugin_unavailable', 'MemberPress API (MeprTransaction) missing.'); }
            $prod = get_post($lid);
            if (!$prod || $prod->post_type !== 'memberpressproduct') { return wpultra_memx_err('level_not_found', "MemberPress membership #$lid not found."); }
            // The documented way to comp a membership: a completed manual transaction.
            $txn = new MeprTransaction();
            $txn->user_id    = $user_id;
            $txn->product_id = $lid;
            $txn->trans_num  = 'wpultra-' . uniqid();
            $txn->status     = MeprTransaction::$complete_str;
            $txn->gateway    = 'manual';
            $txn->expires_at = $expires > 0
                ? gmdate('Y-m-d 23:59:59', $expires)
                : (class_exists('MeprUtils') && method_exists('MeprUtils', 'db_lifetime') ? MeprUtils::db_lifetime() : '0000-00-00 00:00:00');
            $txn->store();
            return ['user_id' => $user_id, 'level_id' => (string) $lid, 'plugin' => $plugin, 'assigned' => true, 'transaction' => (string) $txn->trans_num];
        }
    } catch (\Throwable $e) {
        if (function_exists('wpultra_log_throwable')) { wpultra_log_throwable($e, 'membership-adapter'); }
        return wpultra_memx_err('membership_assign_failed', wpultra_memx_label($plugin) . ' assign failed: ' . $e->getMessage());
    }
    return wpultra_memx_err('membership_unknown_plugin', "No assign driver for '$plugin'.");
}

/** Revoke a membership via the plugin's own API. @return array|WP_Error */
function wpultra_memx_remove(string $plugin, int $user_id, string $level_id = '') {
    if ($user_id <= 0 || !get_userdata($user_id)) { return wpultra_memx_err('user_not_found', "User #$user_id not found."); }
    try {
        if ($plugin === 'pmpro') {
            if (!function_exists('pmpro_changeMembershipLevel')) { return wpultra_memx_err('membership_plugin_unavailable', 'PMPro API (pmpro_changeMembershipLevel) missing.'); }
            // Level 0 = cancel; PMPro fires its own cancellation hooks/emails.
            $ok = pmpro_changeMembershipLevel(0, $user_id);
            if (!$ok) { return wpultra_memx_err('membership_remove_failed', 'PMPro rejected the cancellation.'); }
            return ['user_id' => $user_id, 'plugin' => $plugin, 'removed' => true];
        }
        if ($plugin === 'memberpress') {
            if (!class_exists('MeprUser')) { return wpultra_memx_err('membership_plugin_unavailable', 'MemberPress API (MeprUser) missing.'); }
            $lid = (int) $level_id;
            $mu  = new MeprUser($user_id);
            $txns = (array) $mu->active_product_subscriptions('transactions');
            $expired = 0;
            foreach ($txns as $txn) {
                if (!is_object($txn)) { continue; }
                if ($lid > 0 && (int) ($txn->product_id ?? 0) !== $lid) { continue; }
                $txn->expires_at = gmdate('Y-m-d H:i:s', time() - 86400); // yesterday => no longer active
                $txn->store();
                $expired++;
            }
            if ($expired === 0) { return wpultra_memx_err('membership_remove_failed', "User #$user_id has no active MemberPress transaction" . ($lid > 0 ? " for membership #$lid" : '') . '.'); }
            return ['user_id' => $user_id, 'plugin' => $plugin, 'removed' => true, 'transactions_expired' => $expired];
        }
    } catch (\Throwable $e) {
        if (function_exists('wpultra_log_throwable')) { wpultra_log_throwable($e, 'membership-adapter'); }
        return wpultra_memx_err('membership_remove_failed', wpultra_memx_label($plugin) . ' remove failed: ' . $e->getMessage());
    }
    return wpultra_memx_err('membership_unknown_plugin', "No remove driver for '$plugin'.");
}
