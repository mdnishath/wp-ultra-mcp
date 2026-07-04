<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Headless domain — WPGraphQL stack detection + readiness (Roadmap-3, Wave H1).
 *
 * Mirrors includes/forms/setup.php: every probe degrades gracefully when the
 * plugin is absent (constant/class checks only, never fatal). Pure scoring /
 * shaping functions take their inputs as arguments so they are unit-testable.
 */

/**
 * Detect the WPGraphQL headless stack and versions.
 * Value is the version string when installed ('' when present but version
 * unknown), or null when absent.
 * @return array<string,?string>  keys: wp-graphql, wpgraphql-jwt, wpgraphql-acf, woographql, wpgraphql-smart-cache
 */
function wpultra_headless_detect(): array {
    $out = [
        'wp-graphql'            => null,
        'wpgraphql-jwt'         => null,
        'wpgraphql-acf'         => null,
        'woographql'            => null,
        'wpgraphql-smart-cache' => null,
    ];
    // WPGraphQL core
    if (defined('WPGRAPHQL_VERSION')) {
        $out['wp-graphql'] = (string) WPGRAPHQL_VERSION;
    } elseif (class_exists('WPGraphQL')) {
        $out['wp-graphql'] = '';
    }
    // WPGraphQL JWT Authentication
    if (defined('WPGRAPHQL_JWT_AUTHENTICATION_VERSION')) {
        $out['wpgraphql-jwt'] = (string) WPGRAPHQL_JWT_AUTHENTICATION_VERSION;
    } elseif (class_exists('WPGraphQL\\JWT_Authentication\\JWT_Authentication')) {
        $out['wpgraphql-jwt'] = '';
    }
    // WPGraphQL for ACF
    if (defined('WPGRAPHQL_FOR_ACF_VERSION')) {
        $out['wpgraphql-acf'] = (string) WPGRAPHQL_FOR_ACF_VERSION;
    } elseif (class_exists('WPGraphQLAcf') || class_exists('WPGraphQL\\ACF\\ACF')) {
        $out['wpgraphql-acf'] = '';
    }
    // WooGraphQL (WPGraphQL for WooCommerce)
    if (defined('WPGRAPHQL_WOOCOMMERCE_VERSION')) {
        $out['woographql'] = (string) WPGRAPHQL_WOOCOMMERCE_VERSION;
    } elseif (class_exists('WP_GraphQL_WooCommerce')) {
        $out['woographql'] = '';
    }
    // WPGraphQL Smart Cache
    if (defined('WPGRAPHQL_SMART_CACHE_VERSION')) {
        $out['wpgraphql-smart-cache'] = (string) WPGRAPHQL_SMART_CACHE_VERSION;
    } elseif (class_exists('WPGraphQL\\SmartCache\\Cache\\Collection')) {
        $out['wpgraphql-smart-cache'] = '';
    }
    return $out;
}

/**
 * Permalink readiness. Pure over the structure string; pass null to read the
 * live option. GraphQL routing (and every headless slug route) needs pretty
 * permalinks — a plain (empty) structure is the #1 fresh-install blocker.
 * @return array{pretty:bool,structure:string}
 */
function wpultra_headless_permalinks(?string $structure = null): array {
    if ($structure === null) {
        $structure = function_exists('get_option') ? (string) get_option('permalink_structure', '') : '';
    }
    return ['pretty' => $structure !== '', 'structure' => $structure];
}

/**
 * Shape the stored CORS config option into a stable public form. Pure.
 * @param mixed $raw  value of the wpultra_headless_cors option
 * @return array{enabled:bool,origins:array<int,string>}
 */
function wpultra_headless_shape_cors($raw): array {
    $origins = [];
    if (is_array($raw) && isset($raw['origins']) && is_array($raw['origins'])) {
        foreach ($raw['origins'] as $o) {
            if (is_string($o) && $o !== '') { $origins[] = $o; }
        }
    }
    return ['enabled' => $origins !== [], 'origins' => array_values($origins)];
}

/**
 * Which auth mode authenticated GraphQL should use. Pure: 'jwt' only when the
 * JWT plugin is installed AND its signing secret is defined; otherwise the
 * Application-Passwords path (always available on modern WP, already used by MCP).
 * @param array<string,?string> $detected  wpultra_headless_detect() map
 */
function wpultra_headless_auth_mode(array $detected, bool $jwt_secret_defined): string {
    if (($detected['wpgraphql-jwt'] ?? null) !== null && $jwt_secret_defined) { return 'jwt'; }
    return 'application-passwords';
}

/**
 * Readiness score + missing list + recommendations. Pure over its inputs.
 *
 * Weights: wp-graphql 50, pretty permalinks 15, jwt 10, smart-cache 10, cors 10;
 * conditional +10 wpgraphql-acf when ACF is present, +10 woographql when Woo is
 * present. score = round(100 * earned / total applicable). "Ready" is the
 * minimum viable headless backend: WPGraphQL active + pretty permalinks.
 *
 * @param array<string,?string> $detected  wpultra_headless_detect() map
 * @param bool  $pretty_permalinks
 * @param bool  $cors_configured
 * @param array{acf?:bool,woo?:bool} $ctx  which companion plugins exist on the site
 * @return array{score:int,ready:bool,missing:array<int,string>,recommendations:array<int,string>}
 */
function wpultra_headless_readiness(array $detected, bool $pretty_permalinks, bool $cors_configured, array $ctx = []): array {
    $has = static fn(string $k): bool => ($detected[$k] ?? null) !== null;
    $components = [
        ['key' => 'wp-graphql',            'weight' => 50, 'ok' => $has('wp-graphql')],
        ['key' => 'pretty-permalinks',     'weight' => 15, 'ok' => $pretty_permalinks],
        ['key' => 'wpgraphql-jwt',         'weight' => 10, 'ok' => $has('wpgraphql-jwt')],
        ['key' => 'wpgraphql-smart-cache', 'weight' => 10, 'ok' => $has('wpgraphql-smart-cache')],
        ['key' => 'cors',                  'weight' => 10, 'ok' => $cors_configured],
    ];
    if (!empty($ctx['acf'])) {
        $components[] = ['key' => 'wpgraphql-acf', 'weight' => 10, 'ok' => $has('wpgraphql-acf')];
    }
    if (!empty($ctx['woo'])) {
        $components[] = ['key' => 'woographql', 'weight' => 10, 'ok' => $has('woographql')];
    }
    $total = 0; $earned = 0; $missing = [];
    foreach ($components as $c) {
        $total += $c['weight'];
        if ($c['ok']) { $earned += $c['weight']; }
        else { $missing[] = $c['key']; }
    }
    $score = $total > 0 ? (int) round(100 * $earned / $total) : 0;
    $ready = $has('wp-graphql') && $pretty_permalinks;

    $recommendations = [];
    if (!$has('wp-graphql')) {
        $recommendations[] = 'WPGraphQL is not installed — run headless-setup to install and activate the headless bundle.';
    }
    if (!$pretty_permalinks) {
        $recommendations[] = 'Permalinks are set to "plain" — headless-setup will switch to pretty permalinks (/%postname%/).';
    }
    if ($has('wp-graphql') && !$has('wpgraphql-jwt')) {
        $recommendations[] = 'No JWT auth plugin — authenticated frontend queries will use Application Passwords; headless-setup can add WPGraphQL-JWT.';
    }
    if ($has('wp-graphql') && !$has('wpgraphql-smart-cache')) {
        $recommendations[] = 'WPGraphQL Smart Cache is missing — recommended for production query caching (headless-setup installs it).';
    }
    if (!$cors_configured) {
        $recommendations[] = 'No frontend origin is allowed via CORS yet — headless-setup configures it for your frontend URL(s).';
    }
    if (!empty($ctx['acf']) && !$has('wpgraphql-acf')) {
        $recommendations[] = 'ACF is active but WPGraphQL-for-ACF is missing — field groups are invisible to GraphQL until it is installed.';
    }
    if (!empty($ctx['woo']) && !$has('woographql')) {
        $recommendations[] = 'WooCommerce is active but WooGraphQL is missing — products/cart/checkout are not queryable until it is installed.';
    }
    return ['score' => $score, 'ready' => $ready, 'missing' => $missing, 'recommendations' => $recommendations];
}

/* ============================================================
 * H1.2 — headless-setup: bundle plan, CORS config, JWT secret.
 * ============================================================ */

/**
 * Install source per stack plugin. WPGraphQL core, Smart Cache and the ACF
 * addon live on wp.org (bare slug); JWT auth and WooGraphQL are GitHub-only
 * (zip URL — wpultra_system_install_plugin accepts both forms).
 * @return array<string,string>
 */
function wpultra_headless_bundle_sources(): array {
    return [
        'wp-graphql'            => 'wp-graphql',
        'wpgraphql-jwt'         => 'https://github.com/wp-graphql/wp-graphql-jwt-authentication/archive/refs/tags/v0.7.0.zip',
        'wpgraphql-smart-cache' => 'wpgraphql-smart-cache',
        'wpgraphql-acf'         => 'wpgraphql-acf',
        'woographql'            => 'https://github.com/wp-graphql/wp-graphql-woocommerce/releases/latest/download/wp-graphql-woocommerce.zip',
    ];
}

/**
 * What headless-setup should install. Pure over detection + ctx: the core trio
 * always applies; the ACF/Woo addons only when their parent plugin is present.
 * Detected plugins come back as action "already" so the report shows the full
 * bundle either way.
 * @param array<string,?string> $detected  wpultra_headless_detect() map
 * @param array{acf?:bool,woo?:bool} $ctx
 * @return array<int,array{key:string,source:string,action:string}>
 */
function wpultra_headless_bundle_plan(array $detected, array $ctx = []): array {
    $sources = wpultra_headless_bundle_sources();
    $keys = ['wp-graphql', 'wpgraphql-jwt', 'wpgraphql-smart-cache'];
    if (!empty($ctx['acf'])) { $keys[] = 'wpgraphql-acf'; }
    if (!empty($ctx['woo'])) { $keys[] = 'woographql'; }
    $plan = [];
    foreach ($keys as $k) {
        $plan[] = [
            'key'    => $k,
            'source' => $sources[$k],
            'action' => ($detected[$k] ?? null) !== null ? 'already' : 'install',
        ];
    }
    return $plan;
}

/**
 * Validate + normalize frontend origins for CORS. Pure. Each entry must be an
 * http(s) URL; only scheme://host[:port] is kept (paths/slashes stripped).
 * Returns the normalized unique list, or an error string naming the bad entry.
 * @param array<int,mixed> $raw
 * @return array<int,string>|string
 */
function wpultra_headless_validate_origins(array $raw) {
    $out = [];
    foreach ($raw as $o) {
        if (!is_string($o) || $o === '') { return 'Origin must be a non-empty string.'; }
        $p = parse_url($o);
        $scheme = strtolower((string) ($p['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || empty($p['host'])) {
            return "Invalid origin '$o' — expected http(s)://host[:port].";
        }
        $origin = $scheme . '://' . strtolower((string) $p['host']) . (isset($p['port']) ? ':' . (int) $p['port'] : '');
        if (!in_array($origin, $out, true)) { $out[] = $origin; }
    }
    return $out;
}

/**
 * CORS response headers for a GraphQL request. Pure: echo the request origin
 * back only on an exact allowlist match (no wildcard — credentials are allowed,
 * so reflecting arbitrary origins would be an auth leak).
 * @param array<int,string> $allowed  normalized origins
 * @return array<string,string>
 */
function wpultra_headless_cors_headers(string $request_origin, array $allowed): array {
    if ($request_origin === '' || $allowed === [] || !in_array($request_origin, $allowed, true)) { return []; }
    return [
        'Access-Control-Allow-Origin'      => $request_origin,
        'Access-Control-Allow-Credentials' => 'true',
        'Access-Control-Allow-Headers'     => 'Content-Type, Authorization',
        'Vary'                             => 'Origin',
    ];
}

/**
 * Find an installed-but-INACTIVE stack plugin among get_plugins() keys. Pure.
 * Detection (wpultra_headless_detect) only sees active plugins' classes/constants,
 * so without this the installer would re-install into an existing folder and fail;
 * with it, headless-setup just activates the copy already on disk. Dir-name
 * candidates cover both wp.org spellings and GitHub archive suffixes (-0.7.0 etc.).
 * @param array<int,string> $plugin_files  plugin refs as dir/file.php
 * @return string  the matching plugin ref, or '' when not installed
 */
function wpultra_headless_match_installed(array $plugin_files, string $key): string {
    $candidates = [
        'wp-graphql'            => ['wp-graphql'],
        'wpgraphql-jwt'         => ['wp-graphql-jwt-authentication*', 'wpgraphql-jwt*'],
        'wpgraphql-smart-cache' => ['wpgraphql-smart-cache', 'wp-graphql-smart-cache'],
        'wpgraphql-acf'         => ['wpgraphql-acf', 'wp-graphql-acf'],
        'woographql'            => ['wp-graphql-woocommerce*', 'woographql*'],
    ][$key] ?? [];
    foreach ($plugin_files as $ref) {
        $dir = strstr($ref, '/', true);
        if ($dir === false) { continue; }
        foreach ($candidates as $c) {
            $ok = str_ends_with($c, '*') ? str_starts_with($dir, substr($c, 0, -1)) : $dir === $c;
            if ($ok) { return $ref; }
        }
    }
    return '';
}

/** 64-hex-char signing secret (256 bits). wp_generate_password when WP is loaded, CSPRNG fallback otherwise. */
function wpultra_headless_generate_secret(): string {
    return bin2hex(random_bytes(32));
}

/** The stored JWT signing secret ('' when never generated). */
function wpultra_headless_jwt_secret(): string {
    return function_exists('get_option') ? (string) get_option('wpultra_headless_jwt_secret', '') : '';
}

/**
 * Runtime boot (every request): feed the stored secret to WPGraphQL-JWT via its
 * filter (no wp-config.php edit needed) and attach CORS headers to GraphQL
 * responses for the configured frontend origins.
 */
function wpultra_headless_boot(): void {
    add_filter('graphql_jwt_auth_secret_key', function ($secret) {
        if (is_string($secret) && $secret !== '') { return $secret; } // wp-config constant/filter wins
        $stored = wpultra_headless_jwt_secret();
        return $stored !== '' ? $stored : $secret;
    });
    add_filter('graphql_response_headers_to_send', function ($headers) {
        $cors = wpultra_headless_shape_cors(get_option('wpultra_headless_cors', []));
        if (!$cors['enabled']) { return $headers; }
        $origin = isset($_SERVER['HTTP_ORIGIN']) ? (string) $_SERVER['HTTP_ORIGIN'] : '';
        return array_merge((array) $headers, wpultra_headless_cors_headers($origin, $cors['origins']));
    });
    // H1.5: schema exposure filters (persisted CPT/tax → show_in_graphql) + themeTokens.
    if (function_exists('wpultra_headless_expose_boot')) { wpultra_headless_expose_boot(); }
    // H1.6: public REST fallback routes (no-op while the bundle is disabled).
    if (function_exists('wpultra_headless_rest_register_routes')) {
        add_action('rest_api_init', 'wpultra_headless_rest_register_routes');
    }
    // H2.2: rewrite the editor's Preview button to the frontend (no-op while disabled).
    if (function_exists('wpultra_headless_preview_boot')) { wpultra_headless_preview_boot(); }
    // H2.4: let the revalidate webhook reach the configured (possibly loopback) frontend.
    if (function_exists('wpultra_headless_reval_boot')) { wpultra_headless_reval_boot(); }
    // H3.3: the wpSeo GraphQL field (driver-backed SEO meta for the frontend).
    if (function_exists('wpultra_headless_seo_boot')) { wpultra_headless_seo_boot(); }
}
