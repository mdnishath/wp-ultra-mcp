<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Hosting-environment report engine (read-only). One-call snapshot of the
 * PHP runtime, loaded extensions, OPcache, the database driver, WordPress
 * config, and the host filesystem — plus a rule-evaluated warnings list.
 * This is the first thing to pull when diagnosing a hosting issue.
 *
 * Every runtime read is guarded (function_exists/ini_get-false handling) so
 * this ability can never fatal on a locked-down host; unavailable facts
 * degrade to null rather than throwing.
 */

/* ------------------------------------------------------------------ *
 * PURE helpers — no WordPress, no PHP runtime introspection.
 * ------------------------------------------------------------------ */

/**
 * Parse a php.ini shorthand byte value ('256M', '1G', '512k', '-1', or a
 * plain number) into an integer byte count. '-1' means "unlimited" and is
 * returned as -1. Anything that doesn't parse returns 0. Pure.
 */
function wpultra_env_ini_bytes(string $v): int {
    $v = trim($v);
    if ($v === '') { return 0; }
    if ($v === '-1') { return -1; }
    if (!preg_match('/^(\d+(?:\.\d+)?)\s*([gmk]?)$/i', $v, $m)) { return 0; }
    $num = (float) $m[1];
    switch (strtolower($m[2])) {
        case 'g': $num *= 1073741824; break;
        case 'm': $num *= 1048576; break;
        case 'k': $num *= 1024; break;
    }
    return (int) $num;
}

/**
 * Decode a PHP error_reporting() bitmask into a readable label ('E_ALL',
 * 'E_ERROR|E_WARNING', 'None (0)', or 'Custom (N)' when no known flag
 * matches). Uses only PHP's own core E_* constants — never touches WordPress
 * or the actual runtime setting, so it's safe to call in unit tests. Pure.
 */
function wpultra_env_error_reporting_label(int $level): string {
    if ($level === 0) { return 'None (0)'; }
    if (defined('E_ALL') && $level === E_ALL) { return 'E_ALL'; }
    $candidates = [
        'E_ERROR' => E_ERROR, 'E_WARNING' => E_WARNING, 'E_PARSE' => E_PARSE, 'E_NOTICE' => E_NOTICE,
        'E_CORE_ERROR' => E_CORE_ERROR, 'E_CORE_WARNING' => E_CORE_WARNING,
        'E_COMPILE_ERROR' => E_COMPILE_ERROR, 'E_COMPILE_WARNING' => E_COMPILE_WARNING,
        'E_USER_ERROR' => E_USER_ERROR, 'E_USER_WARNING' => E_USER_WARNING, 'E_USER_NOTICE' => E_USER_NOTICE,
        'E_DEPRECATED' => E_DEPRECATED, 'E_USER_DEPRECATED' => E_USER_DEPRECATED,
    ];
    if (defined('E_STRICT')) { $candidates['E_STRICT'] = E_STRICT; }
    $names = [];
    foreach ($candidates as $name => $value) {
        if ($value !== 0 && ($level & $value) === $value) { $names[] = $name; }
    }
    return $names !== [] ? implode('|', $names) : "Custom ($level)";
}

/**
 * Pure rule evaluator over a plain facts array (see wpultra_env_build_facts
 * for the shape). Returns a list of {id, severity: warn|info, message}.
 * Rules:
 *  - memory_limit_bytes < 256M (and not -1/unlimited)                -> warn
 *  - max_execution_time < 60 and !== 0 (0 = unlimited)                -> warn
 *  - upload_max_filesize_bytes < 8M (and not -1/unlimited)            -> warn
 *  - post_max_size_bytes < upload_max_filesize_bytes                  -> warn
 *  - missing curl / gd / mbstring / openssl                          -> warn each
 *  - disk free < 5% or < 500MB (only when disk facts are known)      -> warn
 *  - php_version < 8.0                                                -> warn
 *  - opcache disabled                                                 -> info
 */
function wpultra_env_warnings(array $facts): array {
    $warnings = [];

    $mem = $facts['memory_limit_bytes'] ?? null;
    if (is_int($mem) && $mem !== -1 && $mem < 256 * 1024 * 1024) {
        $warnings[] = ['id' => 'low_memory_limit', 'severity' => 'warn', 'message' => 'PHP memory_limit is below the recommended 256M.'];
    }

    $met = $facts['max_execution_time'] ?? null;
    if (is_int($met) && $met !== 0 && $met < 60) {
        $warnings[] = ['id' => 'low_max_execution_time', 'severity' => 'warn', 'message' => 'max_execution_time is below 60 seconds and may time out heavier requests.'];
    }

    $upload = $facts['upload_max_filesize_bytes'] ?? null;
    if (is_int($upload) && $upload !== -1 && $upload < 8 * 1024 * 1024) {
        $warnings[] = ['id' => 'low_upload_max_filesize', 'severity' => 'warn', 'message' => 'upload_max_filesize is below the recommended 8M.'];
    }

    $post = $facts['post_max_size_bytes'] ?? null;
    if (is_int($upload) && is_int($post) && $upload !== -1 && $post !== -1 && $post < $upload) {
        $warnings[] = ['id' => 'post_max_lt_upload_max', 'severity' => 'warn', 'message' => 'post_max_size is smaller than upload_max_filesize; uploads at the max size will fail.'];
    }

    $extensions = $facts['extensions'] ?? [];
    foreach (['curl', 'gd', 'mbstring', 'openssl'] as $ext) {
        if (empty($extensions[$ext])) {
            $warnings[] = ['id' => "missing_ext_$ext", 'severity' => 'warn', 'message' => "PHP extension '$ext' is not loaded."];
        }
    }

    $free = $facts['disk_free_bytes'] ?? null;
    $total = $facts['disk_total_bytes'] ?? null;
    if (is_int($free) && is_int($total) && $total > 0) {
        $pct = $free / $total * 100;
        $five_hundred_mb = 500 * 1024 * 1024;
        if ($pct < 5 || $free < $five_hundred_mb) {
            $warnings[] = ['id' => 'low_disk_space', 'severity' => 'warn', 'message' => 'Disk free space is critically low (below 5% or below 500MB).'];
        }
    }

    $php_version = (string) ($facts['php_version'] ?? '');
    if ($php_version !== '' && version_compare($php_version, '8.0', '<')) {
        $warnings[] = ['id' => 'outdated_php', 'severity' => 'warn', 'message' => "PHP $php_version is outdated; upgrade to 8.0 or newer for security and performance."];
    }

    if (array_key_exists('opcache_enabled', $facts) && $facts['opcache_enabled'] === false) {
        $warnings[] = ['id' => 'opcache_disabled', 'severity' => 'info', 'message' => 'OPcache is disabled; enabling it improves PHP performance.'];
    }

    return $warnings;
}

/**
 * Reduce the collected report sections down to the flat facts array that
 * wpultra_env_warnings() consumes. Pure (only reads its arguments).
 */
function wpultra_env_build_facts(array $php, array $extensions, array $opcache, array $server): array {
    $ini = $php['ini'] ?? [];
    // An unavailable/unreadable ini directive (null) is mapped to -1 (the
    // "unlimited" sentinel) rather than '' -> 0, mirroring how
    // max_execution_time's unavailable case already defaults to 0 =
    // unlimited. Otherwise wpultra_env_warnings() would misread "we
    // couldn't read this" as "this is set to 0 bytes" and fire a false
    // low_memory_limit / low_upload_max_filesize warning on a locked-down
    // host that simply blocks ini_get().
    $byte_ini = static function (array $ini, string $key): int {
        $raw = $ini[$key] ?? null;
        return $raw === null ? -1 : wpultra_env_ini_bytes((string) $raw);
    };
    return [
        'memory_limit_bytes'        => $byte_ini($ini, 'memory_limit'),
        'max_execution_time'        => (int) ($ini['max_execution_time'] ?? 0),
        'upload_max_filesize_bytes' => $byte_ini($ini, 'upload_max_filesize'),
        'post_max_size_bytes'       => $byte_ini($ini, 'post_max_size'),
        'extensions'                => $extensions,
        'disk_free_bytes'           => $server['disk_free'] ?? null,
        'disk_total_bytes'          => $server['disk_total'] ?? null,
        'php_version'               => (string) ($php['version'] ?? PHP_VERSION),
        'opcache_enabled'           => (bool) ($opcache['enabled'] ?? false),
    ];
}

/* ------------------------------------------------------------------ *
 * Thin, guarded runtime readers — never fatal, degrade to null.
 * ------------------------------------------------------------------ */

/** ini_get() wrapper that returns null instead of false when unavailable/disabled. */
function wpultra_env_ini(string $key): ?string {
    if (!function_exists('ini_get')) { return null; }
    $v = @ini_get($key);
    return $v === false ? null : (string) $v;
}

function wpultra_env_php_section(): array {
    return [
        'version'   => PHP_VERSION,
        'sapi'      => PHP_SAPI,
        'os_family' => defined('PHP_OS_FAMILY') ? PHP_OS_FAMILY : PHP_OS,
        'ini'       => [
            'memory_limit'         => wpultra_env_ini('memory_limit'),
            'max_execution_time'   => wpultra_env_ini('max_execution_time'),
            'max_input_time'       => wpultra_env_ini('max_input_time'),
            'max_input_vars'       => wpultra_env_ini('max_input_vars'),
            'upload_max_filesize'  => wpultra_env_ini('upload_max_filesize'),
            'post_max_size'        => wpultra_env_ini('post_max_size'),
            'display_errors'       => wpultra_env_ini('display_errors'),
            'error_reporting'      => wpultra_env_error_reporting_label(error_reporting()),
            'date_timezone'        => wpultra_env_ini('date.timezone'),
        ],
    ];
}

/** Extensions that matter to a WordPress install, plus which recommended ones are missing. */
function wpultra_env_extensions_section(): array {
    $names = [
        'curl', 'gd', 'imagick', 'zip', 'mbstring', 'intl', 'openssl', 'mysqli',
        'exif', 'xml', 'json', 'dom', 'fileinfo', 'iconv', 'sodium',
    ];
    $map = [];
    foreach ($names as $name) {
        $map[$name] = function_exists('extension_loaded') && @extension_loaded($name);
    }
    $map['opcache'] = function_exists('opcache_get_status');

    $missing = [];
    foreach ($map as $name => $loaded) {
        if ($name === 'opcache') { continue; }
        if (!$loaded) { $missing[] = $name; }
    }

    return ['extensions' => $map, 'missing_recommended' => $missing];
}

/** OPcache status; gracefully reports 'unavailable' when the function is restricted/missing. */
function wpultra_env_opcache_section(): array {
    if (!function_exists('opcache_get_status')) {
        return ['enabled' => false, 'available' => false, 'memory_used' => null, 'memory_free' => null, 'hit_rate' => null];
    }
    try {
        $status = @opcache_get_status(false);
    } catch (\Throwable $e) {
        $status = false;
    }
    if (!is_array($status)) {
        return ['enabled' => false, 'available' => false, 'memory_used' => null, 'memory_free' => null, 'hit_rate' => null];
    }

    $enabled = (bool) ($status['opcache_enabled'] ?? false);
    $memory = is_array($status['memory_usage'] ?? null) ? $status['memory_usage'] : [];
    $stats = is_array($status['opcache_statistics'] ?? null) ? $status['opcache_statistics'] : [];

    $hits = isset($stats['hits']) ? (int) $stats['hits'] : null;
    $misses = isset($stats['misses']) ? (int) $stats['misses'] : null;
    $hit_rate = null;
    if ($hits !== null && $misses !== null && ($hits + $misses) > 0) {
        $hit_rate = round($hits / ($hits + $misses) * 100, 2);
    } elseif (isset($stats['opcache_hit_rate'])) {
        $hit_rate = round((float) $stats['opcache_hit_rate'], 2);
    }

    return [
        'enabled'      => $enabled,
        'available'    => true,
        'memory_used'  => isset($memory['used_memory']) ? (int) $memory['used_memory'] : null,
        'memory_free'  => isset($memory['free_memory']) ? (int) $memory['free_memory'] : null,
        'hit_rate'     => $hit_rate,
    ];
}

/** Database driver facts via $wpdb (guarded — absent outside a WordPress request). */
function wpultra_env_database_section(): array {
    global $wpdb;
    if (!isset($wpdb) || !is_object($wpdb)) {
        return ['version' => null, 'server_info' => null, 'prefix' => null, 'charset' => null, 'collate' => null];
    }
    return [
        'version'     => method_exists($wpdb, 'db_version') ? $wpdb->db_version() : null,
        'server_info' => method_exists($wpdb, 'db_server_info') ? $wpdb->db_server_info() : null,
        'prefix'      => $wpdb->prefix ?? null,
        'charset'     => $wpdb->charset ?? null,
        'collate'     => $wpdb->collate ?? null,
    ];
}

/** WordPress version/config facts (guarded — all globals/functions may be absent). */
function wpultra_env_wordpress_section(): array {
    global $wp_version;
    return [
        'version'             => $wp_version ?? null,
        'wp_memory_limit'     => defined('WP_MEMORY_LIMIT') ? WP_MEMORY_LIMIT : null,
        'wp_max_memory_limit' => defined('WP_MAX_MEMORY_LIMIT') ? WP_MAX_MEMORY_LIMIT : null,
        'wp_debug'            => defined('WP_DEBUG') ? (bool) WP_DEBUG : false,
        'multisite'           => function_exists('is_multisite') && is_multisite(),
        'language'            => function_exists('get_locale') ? get_locale() : null,
        'home_url'            => function_exists('home_url') ? home_url() : null,
        'site_url'            => function_exists('site_url') ? site_url() : null,
    ];
}

/** Web server + disk facts. Disk reads degrade to null when disabled/unavailable (guarded). */
function wpultra_env_server_section(): array {
    $software = isset($_SERVER['SERVER_SOFTWARE']) ? (string) $_SERVER['SERVER_SOFTWARE'] : null;
    $https = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';

    $path = defined('ABSPATH') ? ABSPATH : __DIR__;
    $free = function_exists('disk_free_space') ? @disk_free_space($path) : false;
    $total = function_exists('disk_total_space') ? @disk_total_space($path) : false;

    $disk_free = null;
    $disk_total = null;
    $percent_free = null;
    if ($free !== false && $total !== false && (float) $total > 0.0) {
        $disk_free = (int) $free;
        $disk_total = (int) $total;
        $percent_free = round((float) $free / (float) $total * 100, 2);
    }

    return [
        'server_software'    => $software,
        'https'              => $https,
        'disk_free'          => $disk_free,
        'disk_total'         => $disk_total,
        'disk_percent_free'  => $percent_free,
    ];
}

/**
 * Assemble the full report. Thin WordPress-touching wrapper — all the logic
 * it delegates to is guarded/pure. This is what the ability's execute
 * callback calls directly.
 */
function wpultra_env_collect(): array {
    $php = wpultra_env_php_section();
    $ext = wpultra_env_extensions_section();
    $opcache = wpultra_env_opcache_section();
    $database = wpultra_env_database_section();
    $wordpress = wpultra_env_wordpress_section();
    $server = wpultra_env_server_section();

    $facts = wpultra_env_build_facts($php, $ext['extensions'], $opcache, $server);
    $warnings = wpultra_env_warnings($facts);

    return [
        'php'                 => $php,
        'extensions'          => $ext['extensions'],
        'missing_recommended' => $ext['missing_recommended'],
        'opcache'             => $opcache,
        'database'            => $database,
        'wordpress'           => $wordpress,
        'server'              => $server,
        'warnings'            => $warnings,
    ];
}
