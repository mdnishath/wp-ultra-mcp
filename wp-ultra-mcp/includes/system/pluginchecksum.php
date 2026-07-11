<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Plugin checksum-verify engine (Roadmap-4 BF2.5).
 *
 * Verifies installed plugin files against the wp.org plugin-repo checksum API
 * (the plugin analogue of wpultra_security_scan_core_checksums() for core).
 * Only plugins actually hosted on wp.org publish a manifest at
 * https://downloads.wordpress.org/plugin-checksums/{slug}/{version}.json —
 * premium/custom plugins 404 and are reported as 'not_on_wporg' (not a failure).
 *
 * PURE functions (unit-tested): wpultra_pluginck_compare(),
 * wpultra_pluginck_manifest_url(), wpultra_pluginck_parse_manifest(),
 * wpultra_pluginck_slug_from_basename(). Everything else is a thin
 * WordPress-touching wrapper around them.
 */

/* =====================================================================
 * PURE
 * ===================================================================== */

/**
 * PURE. Derive the plugin-repo slug from a get_plugins() basename key, e.g.
 * "akismet/akismet.php" -> "akismet". Single-file plugins (no directory
 * segment), e.g. "hello.php" -> "hello" (extension stripped).
 */
function wpultra_pluginck_slug_from_basename(string $basename): string {
    $basename = str_replace('\\', '/', trim($basename));
    if ($basename === '') { return ''; }
    $pos = strpos($basename, '/');
    if ($pos !== false) {
        return substr($basename, 0, $pos);
    }
    if (strtolower(substr($basename, -4)) === '.php') {
        return substr($basename, 0, -4);
    }
    return $basename;
}

/** PURE. Build the wp.org plugin-checksums manifest URL for a slug + version. */
function wpultra_pluginck_manifest_url(string $slug, string $version): string {
    return 'https://downloads.wordpress.org/plugin-checksums/' . rawurlencode($slug) . '/' . rawurlencode($version) . '.json';
}

/**
 * PURE. Parse the wp.org checksum manifest JSON body into a flat
 * path => md5 map. Tolerates entries missing sha256 (only md5 is used).
 * An absent/empty "files" key yields an empty map (not an error) — the
 * manifest legitimately shipped zero files. Malformed JSON is a WP_Error.
 *
 * @return array<string,string>|WP_Error
 */
function wpultra_pluginck_parse_manifest(string $json) {
    $data = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        return wpultra_err('invalid_manifest', 'Could not parse the checksum manifest JSON.');
    }
    $files = $data['files'] ?? null;
    if (!is_array($files)) {
        return [];
    }
    $out = [];
    foreach ($files as $path => $meta) {
        if (!is_string($path) || $path === '') { continue; }
        if (is_array($meta)) {
            $out[$path] = isset($meta['md5']) ? (string) $meta['md5'] : '';
        } elseif (is_string($meta)) {
            // Tolerate a flat path=>md5 shape too, in case the API ever simplifies it.
            $out[$path] = $meta;
        } else {
            $out[$path] = '';
        }
    }
    return $out;
}

/**
 * PURE. Compare a manifest's expected path=>md5 map against an on-disk
 * path=>md5 map. Returns {ok, modified, missing, extra}:
 *   modified — path present both places, hashes differ
 *   missing  — path in the manifest, absent on disk
 *   extra    — path on disk, absent from the manifest
 *
 * @param array<string,string> $manifest_files
 * @param array<string,string> $ondisk_md5
 * @return array{ok:bool, modified:array<int,string>, missing:array<int,string>, extra:array<int,string>}
 */
function wpultra_pluginck_compare(array $manifest_files, array $ondisk_md5): array {
    $modified = [];
    $missing  = [];
    foreach ($manifest_files as $path => $expected) {
        if (!array_key_exists($path, $ondisk_md5)) {
            $missing[] = $path;
            continue;
        }
        $expected = (string) $expected;
        if ($expected !== '' && $ondisk_md5[$path] !== $expected) {
            $modified[] = $path;
        }
    }
    $extra = [];
    foreach ($ondisk_md5 as $path => $md5) {
        if (!array_key_exists($path, $manifest_files)) {
            $extra[] = $path;
        }
    }
    sort($modified);
    sort($missing);
    sort($extra);
    return [
        'ok'       => ($modified === [] && $missing === [] && $extra === []),
        'modified' => $modified,
        'missing'  => $missing,
        'extra'    => $extra,
    ];
}

/* =====================================================================
 * WordPress-touching wrappers (live-verified by the controller, not unit-tested)
 * ===================================================================== */

/** Per-plugin file scan cap (filterable), bounds a single verify() call. */
function wpultra_pluginck_file_cap(): int {
    $n = (int) (function_exists('apply_filters') ? apply_filters('wpultra_pluginck_file_cap', 2000) : 2000);
    return $n > 0 ? $n : 2000;
}

/** Per-plugin wall-clock budget in seconds (filterable) for the file-hashing loop. */
function wpultra_pluginck_time_budget(): float {
    $n = (float) (function_exists('apply_filters') ? apply_filters('wpultra_pluginck_time_budget', 20.0) : 20.0);
    return $n > 0 ? $n : 20.0;
}

/** Overall wall-clock budget in seconds (filterable) for a verify(all:true) run across many plugins. */
function wpultra_pluginck_overall_time_budget(): float {
    $n = (float) (function_exists('apply_filters') ? apply_filters('wpultra_pluginck_overall_time_budget', 60.0) : 60.0);
    return $n > 0 ? $n : 60.0;
}

/** Ensure get_plugins() is available (wp-admin only autoloads it there). */
function wpultra_pluginck_require_plugin_admin(): void {
    if (function_exists('get_plugins')) { return; }
    if (function_exists('wpultra_system_require_plugin_admin')) {
        wpultra_system_require_plugin_admin();
        return;
    }
    $p = rtrim((string) ABSPATH, '/\\') . '/wp-admin/includes/plugin.php';
    if (is_readable($p)) { require_once $p; }
}

/** WP-touching. Installed plugins => [basename => ['version'=>..]] via get_plugins(). */
function wpultra_pluginck_installed_plugins(): array {
    wpultra_pluginck_require_plugin_admin();
    return function_exists('get_plugins') ? get_plugins() : [];
}

/**
 * ACTION: list. Every installed plugin with its guessed slug + version +
 * whether we can attempt verification (a slug was derivable).
 *
 * @return array{plugins:array<int,array>, count:int}
 */
function wpultra_pluginck_list_installed(): array {
    $rows = [];
    foreach (wpultra_pluginck_installed_plugins() as $basename => $data) {
        $slug = wpultra_pluginck_slug_from_basename((string) $basename);
        $rows[] = [
            'plugin'     => (string) $basename,
            'slug'       => $slug,
            'name'       => (string) ($data['Name'] ?? $basename),
            'version'    => (string) ($data['Version'] ?? ''),
            'can_verify' => $slug !== '' && (string) ($data['Version'] ?? '') !== '',
        ];
    }
    return ['plugins' => $rows, 'count' => count($rows)];
}

/**
 * Recursively collect file paths under a directory, capped. Relative-path
 * keys are NOT computed here (caller strips the base) — this only bounds
 * how much of the filesystem a single scan will walk.
 *
 * @return array<int,string> absolute paths
 */
function wpultra_pluginck_collect_files(string $dir, int $cap): array {
    $out = [];
    if ($dir === '' || !is_dir($dir)) { return $out; }
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
    } catch (\Throwable $e) {
        return $out;
    }
    foreach ($it as $file) {
        if (count($out) >= $cap) { break; }
        if (!$file->isFile()) { continue; }
        if (strtolower($file->getExtension()) !== 'php') { continue; }
        $out[] = $file->getPathname();
    }
    return $out;
}

/**
 * WP-touching. Verify one installed plugin's on-disk files against the
 * wp.org checksum manifest for its installed version.
 *
 * @param string $basename get_plugins() key, e.g. "akismet/akismet.php"
 * @param string $version  installed version string
 * @return array
 */
function wpultra_pluginck_verify_plugin(string $basename, string $version): array {
    $slug = wpultra_pluginck_slug_from_basename($basename);
    if ($slug === '') {
        return ['plugin' => $basename, 'slug' => '', 'version' => $version, 'status' => 'not_on_wporg', 'note' => 'Could not derive a slug for this plugin.'];
    }
    if ($version === '') {
        return ['plugin' => $basename, 'slug' => $slug, 'version' => '', 'status' => 'check_failed', 'note' => 'Installed version is unknown.'];
    }

    if (!function_exists('wp_remote_get')) {
        return ['plugin' => $basename, 'slug' => $slug, 'version' => $version, 'status' => 'check_failed', 'note' => 'wp_remote_get() unavailable.'];
    }

    $url  = wpultra_pluginck_manifest_url($slug, $version);
    $resp = wp_remote_get($url, ['timeout' => 15]);
    if (is_wp_error($resp)) {
        return ['plugin' => $basename, 'slug' => $slug, 'version' => $version, 'status' => 'check_failed', 'note' => 'Network error: ' . $resp->get_error_message()];
    }

    $code = function_exists('wp_remote_retrieve_response_code') ? (int) wp_remote_retrieve_response_code($resp) : 0;
    if ($code === 404) {
        return ['plugin' => $basename, 'slug' => $slug, 'version' => $version, 'status' => 'not_on_wporg', 'note' => 'No checksum manifest published for this plugin/version (not on wp.org, or a premium/custom build).'];
    }
    if ($code !== 200) {
        return ['plugin' => $basename, 'slug' => $slug, 'version' => $version, 'status' => 'check_failed', 'note' => "Unexpected HTTP $code fetching the checksum manifest."];
    }

    $body     = function_exists('wp_remote_retrieve_body') ? (string) wp_remote_retrieve_body($resp) : '';
    $manifest = wpultra_pluginck_parse_manifest($body);
    if (is_wp_error($manifest)) {
        return ['plugin' => $basename, 'slug' => $slug, 'version' => $version, 'status' => 'check_failed', 'note' => $manifest->get_error_message()];
    }
    if ($manifest === []) {
        return ['plugin' => $basename, 'slug' => $slug, 'version' => $version, 'status' => 'not_on_wporg', 'note' => 'The checksum manifest was empty for this plugin/version.'];
    }

    $plugin_root  = defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : (rtrim((string) ABSPATH, '/\\') . '/wp-content/plugins');
    $is_single    = strpos($basename, '/') === false;
    $plugin_dir   = rtrim((string) $plugin_root, '/\\') . '/' . $slug;

    $cap          = wpultra_pluginck_file_cap();
    $time_budget  = wpultra_pluginck_time_budget();
    $start        = microtime(true);
    $capped       = false;

    $ondisk    = [];
    $checked_manifest = [];
    $checked   = 0;
    foreach ($manifest as $rel => $expected_md5) {
        if ($checked >= $cap || (microtime(true) - $start) > $time_budget) { $capped = true; break; }
        $checked++;
        $checked_manifest[$rel] = $expected_md5;
        $file = $is_single ? (rtrim((string) $plugin_root, '/\\') . '/' . $rel) : ($plugin_dir . '/' . $rel);
        if (is_file($file)) {
            $actual = @md5_file($file);
            if ($actual !== false) { $ondisk[$rel] = $actual; }
        }
    }

    $extra = [];
    if (!$capped && !$is_single && is_dir($plugin_dir)) {
        foreach (wpultra_pluginck_collect_files($plugin_dir, $cap) as $abs) {
            $rel = str_replace('\\', '/', ltrim(substr($abs, strlen($plugin_dir)), '/\\'));
            if ($rel !== '' && !array_key_exists($rel, $manifest)) { $extra[] = $rel; }
        }
    }

    $result = wpultra_pluginck_compare($checked_manifest, $ondisk);
    $result['extra'] = array_values(array_unique(array_merge($result['extra'], $extra)));
    sort($result['extra']);
    $result['ok'] = ($result['modified'] === [] && $result['missing'] === [] && $result['extra'] === []);

    return [
        'plugin'   => $basename,
        'slug'     => $slug,
        'version'  => $version,
        'status'   => 'ok',
        'checked'  => $checked,
        'capped'   => $capped,
        'ok'       => $result['ok'],
        'modified' => $result['modified'],
        'missing'  => $result['missing'],
        'unknown'  => $result['extra'],
    ];
}

/**
 * ACTION: verify. $input: {plugin?: string, all?: bool}. Verifies one
 * installed plugin (by its folder slug) or every installed plugin.
 *
 * @return array|WP_Error
 */
function wpultra_pluginck_verify(array $input) {
    $plugin_input = isset($input['plugin']) ? trim((string) $input['plugin']) : '';
    $all = ($input['all'] ?? false) === true;

    if ($plugin_input === '' && !$all) {
        return wpultra_err('missing_plugin', "Provide 'plugin' (a plugin folder slug) or 'all: true'.");
    }

    $by_slug = [];
    foreach (wpultra_pluginck_installed_plugins() as $basename => $data) {
        $slug = wpultra_pluginck_slug_from_basename((string) $basename);
        if ($slug === '') { continue; }
        $by_slug[$slug] = ['basename' => (string) $basename, 'version' => (string) ($data['Version'] ?? '')];
    }

    if ($all) {
        $targets = array_keys($by_slug);
    } else {
        if (!isset($by_slug[$plugin_input])) {
            return wpultra_err('plugin_not_found', "No installed plugin matches slug '$plugin_input'.");
        }
        $targets = [$plugin_input];
    }

    $results = [];
    $start   = microtime(true);
    $overall_budget = wpultra_pluginck_overall_time_budget();
    $capped_overall = false;
    foreach ($targets as $slug) {
        if ((microtime(true) - $start) > $overall_budget) { $capped_overall = true; break; }
        $info = $by_slug[$slug];
        $results[] = wpultra_pluginck_verify_plugin($info['basename'], $info['version']);
    }

    return ['results' => $results, 'count' => count($results), 'capped' => $capped_overall];
}
