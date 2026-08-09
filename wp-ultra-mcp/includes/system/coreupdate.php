<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * WordPress core-update engine. The agent can already update plugins/themes;
 * this closes the gap for WordPress itself. `check` is read-only; `update`
 * applies a core update via Core_Upgrader and is confirm-gated (a failed core
 * update can white-screen a site — take a db-snapshot first).
 */

/** PURE: shape a core-update offer object into the compact report row. */
function wpultra_coreupdate_shape($update, string $current): array {
    $version = is_object($update) ? (string) ($update->version ?? '') : '';
    return [
        'response'  => is_object($update) ? (string) ($update->response ?? '') : 'unknown', // upgrade|latest|development
        'version'   => $version,
        'locale'    => is_object($update) ? (string) ($update->locale ?? 'en_US') : 'en_US',
        'is_newer'  => $version !== '' && version_compare($version, $current, '>'),
        'partial'   => is_object($update) ? (bool) ($update->partial ?? false) : false,
    ];
}

/** @return array|WP_Error read-only: current version + available core updates. */
function wpultra_coreupdate_check(bool $force = false) {
    require_once ABSPATH . 'wp-admin/includes/update.php';
    if ($force && function_exists('wp_version_check')) { wp_version_check([], true); }
    global $wp_version;
    $current = isset($wp_version) ? (string) $wp_version : (function_exists('get_bloginfo') ? (string) get_bloginfo('version') : '');
    $updates = function_exists('get_core_updates') ? get_core_updates() : [];
    $offers = [];
    $available = false;
    foreach ((array) $updates as $u) {
        $row = wpultra_coreupdate_shape($u, $current);
        if ($row['response'] === 'upgrade' && $row['is_newer']) { $available = true; }
        $offers[] = $row;
    }
    return [
        'current'          => $current,
        'update_available' => $available,
        'offers'           => $offers,
    ];
}

/** @return array|WP_Error apply the recommended core update (confirm-gated by the ability). */
function wpultra_coreupdate_apply(string $version = '') {
    require_once ABSPATH . 'wp-admin/includes/update.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/misc.php';

    if (function_exists('wp_version_check')) { wp_version_check([], true); }
    $updates = function_exists('get_core_updates') ? get_core_updates() : [];
    if (empty($updates) || !is_object($updates[0])) {
        return wpultra_err('no_update', 'No core update offer is available.');
    }
    // Pick the requested version, else the first "upgrade" offer.
    $target = null;
    foreach ($updates as $u) {
        if ($version !== '' && (string) ($u->version ?? '') === $version) { $target = $u; break; }
        if ($version === '' && ($u->response ?? '') === 'upgrade') { $target = $u; break; }
    }
    if (!$target) { return wpultra_err('offer_not_found', $version !== '' ? "No core offer for version '$version'." : 'No upgradable core offer.'); }

    global $wp_version;
    $from = isset($wp_version) ? (string) $wp_version : '';

    if (!class_exists('Core_Upgrader')) { return wpultra_err('upgrader_unavailable', 'Core_Upgrader unavailable.'); }
    $upgrader = new Core_Upgrader(new WP_Upgrader_Skin());
    $result = $upgrader->upgrade($target);
    if (is_wp_error($result)) { return $result; }

    return [
        'updated' => true,
        'from'    => $from,
        'to'      => (string) ($target->version ?? ''),
        'note'    => 'Core files updated; the new version loads on the next request. Run the DB upgrade routine if wp-admin prompts for it.',
    ];
}

/** Ability dispatcher. @return array|WP_Error */
function wpultra_core_update(array $input) {
    $action = (string) ($input['action'] ?? 'check');
    switch ($action) {
        case 'check':
            return wpultra_ok(wpultra_coreupdate_check((bool) ($input['force'] ?? false)));
        case 'update':
            if ($e = wpultra_require_confirm($input, 'Updating WordPress core rewrites core files; a failure can take the site down. Take a db-snapshot + backup first.')) { return $e; }
            $res = wpultra_coreupdate_apply((string) ($input['version'] ?? ''));
            if (is_wp_error($res)) { return $res; }
            wpultra_audit_log('core-update', "core {$res['from']} → {$res['to']}", true);
            return wpultra_ok($res);
        default:
            return wpultra_err('bad_action', "Unknown action '$action'.");
    }
}
