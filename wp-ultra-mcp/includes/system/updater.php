<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Self-update engine: check the GitHub releases API for a newer version and
 * apply it (download_url → unzip_file over WP_PLUGIN_DIR — the flow proven
 * live on both production sites). Also feeds WP core's plugin-update UI via
 * the update_plugins transient so wp-admin shows "update available" natively.
 */

const WPULTRA_UPDATER_REPO      = 'mdnishath/wp-ultra-mcp';
const WPULTRA_UPDATER_TRANSIENT = 'wpultra_latest_release';
const WPULTRA_UPDATER_TTL       = 6 * HOUR_IN_SECONDS;

/**
 * Pure: extract {version, zip_url, notes_url, published} from a GitHub
 * /releases/latest JSON payload. Returns null when the payload has no usable
 * tag or no wp-ultra-mcp.zip asset.
 */
function wpultra_updater_parse_release($json): ?array {
    if (!is_array($json)) { return null; }
    $tag = isset($json['tag_name']) ? trim((string) $json['tag_name']) : '';
    $version = ltrim($tag, 'vV');
    if ($version === '' || !preg_match('/^\d+\.\d+(\.\d+)?/', $version)) { return null; }
    $zip = '';
    foreach ((array) ($json['assets'] ?? []) as $asset) {
        if (is_array($asset) && ($asset['name'] ?? '') === 'wp-ultra-mcp.zip' && !empty($asset['browser_download_url'])) {
            $zip = (string) $asset['browser_download_url'];
            break;
        }
    }
    if ($zip === '' || !preg_match('#^https://github\.com/#i', $zip)) { return null; }
    return [
        'version'   => $version,
        'zip_url'   => $zip,
        'notes_url' => (string) ($json['html_url'] ?? ''),
        'published' => (string) ($json['published_at'] ?? ''),
    ];
}

/** Pure: is $latest a strictly newer version than $current? */
function wpultra_updater_is_newer(string $current, string $latest): bool {
    return version_compare($latest, $current, '>');
}

/**
 * Pure: build the object WP core expects inside the update_plugins transient
 * response map for this plugin.
 */
function wpultra_updater_build_update_item(string $basename, string $slug, array $release): object {
    return (object) [
        'id'          => 'github.com/' . WPULTRA_UPDATER_REPO,
        'slug'        => $slug,
        'plugin'      => $basename,
        'new_version' => $release['version'],
        'url'         => $release['notes_url'] !== '' ? $release['notes_url'] : 'https://github.com/' . WPULTRA_UPDATER_REPO,
        'package'     => $release['zip_url'],
    ];
}

/**
 * Pure: derive a release array from the redirect Location of
 * github.com/<repo>/releases/latest (e.g. …/releases/tag/v0.14.0). API-less
 * fallback for when api.github.com rate-limits unauthenticated callers (403).
 */
function wpultra_updater_release_from_location(string $location): ?array {
    if (!preg_match('#^https://github\.com/' . preg_quote(WPULTRA_UPDATER_REPO, '#') . '/releases/tag/(v?[\w.\-]+)$#i', trim($location), $m)) {
        return null;
    }
    $tag = $m[1];
    $version = ltrim($tag, 'vV');
    if (!preg_match('/^\d+\.\d+(\.\d+)?/', $version)) { return null; }
    return [
        'version'   => $version,
        'zip_url'   => 'https://github.com/' . WPULTRA_UPDATER_REPO . "/releases/download/$tag/wp-ultra-mcp.zip",
        'notes_url' => trim($location),
        'published' => '',
    ];
}

/** Fetch (transient-cached) the latest release. @return array|WP_Error */
function wpultra_updater_fetch_latest(bool $force = false) {
    if (!$force) {
        $cached = get_transient(WPULTRA_UPDATER_TRANSIENT);
        if (is_array($cached) && !empty($cached['version'])) { return $cached; }
    }
    $ua = 'wp-ultra-mcp/' . (defined('WPULTRA_VERSION') ? WPULTRA_VERSION : '0');
    $release = null;
    $resp = wp_remote_get('https://api.github.com/repos/' . WPULTRA_UPDATER_REPO . '/releases/latest', [
        'timeout' => 15,
        'headers' => ['Accept' => 'application/vnd.github+json', 'User-Agent' => $ua],
    ]);
    if (!is_wp_error($resp) && (int) wp_remote_retrieve_response_code($resp) === 200) {
        $release = wpultra_updater_parse_release(json_decode(wp_remote_retrieve_body($resp), true));
    }
    if ($release === null) {
        // API failed or rate-limited (403 for unauthenticated IPs) — read the
        // redirect target of the public releases/latest page instead.
        $head = wp_remote_get('https://github.com/' . WPULTRA_UPDATER_REPO . '/releases/latest', [
            'timeout' => 15, 'redirection' => 0, 'headers' => ['User-Agent' => $ua],
        ]);
        if (is_wp_error($head)) { return $head; }
        $release = wpultra_updater_release_from_location((string) wp_remote_retrieve_header($head, 'location'));
    }
    if ($release === null) { return wpultra_err('release_unresolvable', 'Could not resolve the latest GitHub release (API rate-limited and no redirect tag).'); }
    set_transient(WPULTRA_UPDATER_TRANSIENT, $release, WPULTRA_UPDATER_TTL);
    return $release;
}

/** Shape the check result for the ability. @return array|WP_Error */
function wpultra_updater_check(bool $force = false) {
    $release = wpultra_updater_fetch_latest($force);
    if (is_wp_error($release)) { return $release; }
    $current = defined('WPULTRA_VERSION') ? WPULTRA_VERSION : '0';
    return [
        'current'          => $current,
        'latest'           => $release['version'],
        'update_available' => wpultra_updater_is_newer($current, $release['version']),
        'notes_url'        => $release['notes_url'],
        'published'        => $release['published'],
    ];
}

/**
 * Download the release zip and unzip it over WP_PLUGIN_DIR (entries carry the
 * wp-ultra-mcp/ folder prefix with forward slashes). @return array|WP_Error
 */
function wpultra_updater_apply() {
    $release = wpultra_updater_fetch_latest(true);
    if (is_wp_error($release)) { return $release; }
    $current = defined('WPULTRA_VERSION') ? WPULTRA_VERSION : '0';
    if (!wpultra_updater_is_newer($current, $release['version'])) {
        return wpultra_err('already_latest', "Already on the latest version ($current).");
    }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/misc.php';
    if (!function_exists('WP_Filesystem') || !WP_Filesystem()) {
        return wpultra_err('fs_unavailable', 'Could not initialize the WordPress filesystem API.');
    }
    $tmp = download_url($release['zip_url'], 300);
    if (is_wp_error($tmp)) { return $tmp; }
    $res = unzip_file($tmp, WP_PLUGIN_DIR);
    @unlink($tmp);
    if (is_wp_error($res)) { return $res; }
    delete_transient(WPULTRA_UPDATER_TRANSIENT);
    $hdr = get_file_data(WP_PLUGIN_DIR . '/wp-ultra-mcp/wp-ultra-mcp.php', ['Version' => 'Version']);
    $on_disk = (string) ($hdr['Version'] ?? '');
    if ($on_disk !== $release['version']) {
        return wpultra_err('verify_failed', "Update extracted but on-disk version reads '$on_disk', expected '{$release['version']}'.");
    }
    if (function_exists('wp_clean_plugins_cache')) { wp_clean_plugins_cache(true); }
    return [
        'updated'      => true,
        'from'         => $current,
        'to'           => $on_disk,
        'note'         => 'New code loads on the next request; this request still runs ' . $current . '.',
    ];
}

/** Filter: inject our GitHub release into WP core's update_plugins transient. */
function wpultra_updater_inject_transient($transient) {
    if (!is_object($transient)) { return $transient; }
    // Never trigger a remote call on frequent admin loads unless the cache exists or expired naturally.
    $release = wpultra_updater_fetch_latest(false);
    if (is_wp_error($release)) { return $transient; }
    $current  = defined('WPULTRA_VERSION') ? WPULTRA_VERSION : '0';
    $basename = plugin_basename(WPULTRA_FILE);
    if (wpultra_updater_is_newer($current, $release['version'])) {
        if (!isset($transient->response) || !is_array($transient->response)) { $transient->response = []; }
        $transient->response[$basename] = wpultra_updater_build_update_item($basename, dirname($basename), $release);
    } elseif (isset($transient->response[$basename])) {
        unset($transient->response[$basename]);
    }
    return $transient;
}
