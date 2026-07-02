<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Devtools engine: send-email, render-page (HTML probe of a live URL), list-registry
 * (compact descriptors of core registries), purge-cache (multi-layer cache purge probe map).
 * Pure logic (HTML extraction, fatal-marker detection, registry/purge shaping) is kept in
 * standalone, testable functions; anything that touches WordPress/network lives in a thin
 * wrapper that calls out to the pure fn.
 */

// ---------------------------------------------------------------------------
// Pure: HTML probes (regex-based, no WordPress dependency).
// ---------------------------------------------------------------------------

/** Extract the <title> tag's inner text (trimmed, whitespace-collapsed). Empty string when absent. Pure. */
function wpultra_devtools_extract_title(string $html): string {
    if (!preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) { return ''; }
    $title = html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8');
    $title = preg_replace('/\s+/', ' ', $title);
    return trim((string) $title);
}

/** Count occurrences of an opening tag (e.g. 'h1', 'script') in raw HTML. Pure, regex-based. */
function wpultra_devtools_count_tag(string $html, string $tag): int {
    $tag = trim($tag);
    if ($tag === '' || !preg_match('/^[a-zA-Z0-9]+$/', $tag)) { return 0; }
    if (!preg_match_all('/<' . preg_quote($tag, '/') . '(?:\s[^>]*)?>/i', $html, $m)) { return 0; }
    return count($m[0]);
}

/** Pure: fatal-error / critical-error markers commonly emitted by PHP or WP's fatal-error handler. */
function wpultra_devtools_fatal_markers(): array {
    return [
        'Fatal error',
        'There has been a critical error',
        'Parse error',
        'Uncaught Error',
        'Uncaught Exception',
        'Recoverable fatal error',
        'Stack trace:',
    ];
}

/** Return the fatal/critical-error markers found in $html (subset of wpultra_devtools_fatal_markers()). Pure. */
function wpultra_devtools_detect_fatal(string $html): array {
    $found = [];
    foreach (wpultra_devtools_fatal_markers() as $marker) {
        if (stripos($html, $marker) !== false) { $found[] = $marker; }
    }
    return $found;
}

/**
 * Build the render-page report from an already-fetched HTML body + metadata. Pure — no network.
 * $meta: ['status'=>int, 'load_ms'=>float|int, 'url'=>string]
 */
function wpultra_devtools_render_report(string $html, array $meta): array {
    $fatals = wpultra_devtools_detect_fatal($html);
    return [
        'url'            => (string) ($meta['url'] ?? ''),
        'http_status'    => (int) ($meta['status'] ?? 0),
        'load_ms'        => $meta['load_ms'] ?? null,
        'title'          => wpultra_devtools_extract_title($html),
        'h1_count'       => wpultra_devtools_count_tag($html, 'h1'),
        'body_length'    => strlen($html),
        'fatal_detected' => $fatals !== [],
        'fatal_markers'  => $fatals,
    ];
}

// ---------------------------------------------------------------------------
// Pure: registry shapers (take fixture-style arrays, return compact descriptors).
// ---------------------------------------------------------------------------

/** Shape a post_type_exists()-style map (name => object-ish array/object) into compact descriptors. Pure. */
function wpultra_devtools_shape_post_types(array $types): array {
    $out = [];
    foreach ($types as $name => $obj) {
        $a = is_array($obj) ? $obj : (array) $obj;
        $out[] = [
            'name'   => (string) $name,
            'label'  => (string) ($a['label'] ?? $name),
            'public' => (bool) ($a['public'] ?? false),
        ];
    }
    return $out;
}

/** Shape a taxonomies map (name => object-ish array/object) into compact descriptors. Pure. */
function wpultra_devtools_shape_taxonomies(array $taxes): array {
    $out = [];
    foreach ($taxes as $name => $obj) {
        $a = is_array($obj) ? $obj : (array) $obj;
        $out[] = [
            'name'         => (string) $name,
            'label'        => (string) ($a['label'] ?? $name),
            'public'       => (bool) ($a['public'] ?? false),
            'object_types' => array_values((array) ($a['object_type'] ?? [])),
        ];
    }
    return $out;
}

/** Shape a tag => callback[] map (as from a shortcode registry) into a sorted list of tag names. Pure. */
function wpultra_devtools_shape_shortcodes(array $shortcodes): array {
    $out = array_values(array_unique(array_map('strval', array_keys($shortcodes))));
    sort($out);
    return $out;
}

/** Shape a role-name => role-array map (as from WP_Roles::roles) into compact descriptors. Pure. */
function wpultra_devtools_shape_roles(array $roles): array {
    $out = [];
    foreach ($roles as $slug => $role) {
        $a = is_array($role) ? $role : (array) $role;
        $caps = is_array($a['capabilities'] ?? null) ? $a['capabilities'] : [];
        $out[] = [
            'slug'          => (string) $slug,
            'name'          => (string) ($a['name'] ?? $slug),
            'capability_count' => count(array_filter($caps)),
        ];
    }
    return $out;
}

/** Shape an image_sizes name => dims array map into compact descriptors. Pure. */
function wpultra_devtools_shape_image_sizes(array $sizes): array {
    $out = [];
    foreach ($sizes as $name => $dim) {
        $a = is_array($dim) ? $dim : [];
        $out[] = [
            'name'   => (string) $name,
            'width'  => (int) ($a['width'] ?? 0),
            'height' => (int) ($a['height'] ?? 0),
            'crop'   => (bool) ($a['crop'] ?? false),
        ];
    }
    return $out;
}

/**
 * Shape a REST server route map (route => array-of-route-definitions, as from
 * WP_REST_Server::get_routes()) into a compact list of [route, methods[]]. Pure.
 */
function wpultra_devtools_shape_rest_routes(array $routes): array {
    $out = [];
    foreach ($routes as $route => $defs) {
        $methods = [];
        foreach ((array) $defs as $def) {
            $d = is_array($def) ? $def : (array) $def;
            $m = $d['methods'] ?? [];
            if (is_array($m)) { $methods = array_merge($methods, array_keys(array_filter($m))); }
            elseif (is_string($m)) { $methods[] = $m; }
        }
        $out[] = ['route' => (string) $route, 'methods' => array_values(array_unique($methods))];
    }
    return $out;
}

/**
 * Shape a $wp_filter-style hook map (priority => [callback-key => ['function'=>callable,'accepted_args'=>int]])
 * for one hook into a flat, priority-ordered list of callback descriptors. Pure.
 * $callbacks: array<int, array<string, array{function?:mixed, accepted_args?:int}>>
 */
function wpultra_devtools_shape_hook_callbacks(array $callbacks): array {
    $out = [];
    $priorities = array_keys($callbacks);
    sort($priorities, SORT_NUMERIC);
    foreach ($priorities as $priority) {
        $group = $callbacks[$priority];
        if (!is_array($group)) { continue; }
        foreach ($group as $entry) {
            $e = is_array($entry) ? $entry : [];
            $out[] = [
                'priority'      => (int) $priority,
                'callback'      => wpultra_devtools_describe_callback($e['function'] ?? null),
                'accepted_args' => (int) ($e['accepted_args'] ?? 1),
            ];
        }
    }
    return $out;
}

/** Render a human-readable name for a callable (string fn, 'Class::method', or [$obj/$class, $method]). Pure. */
function wpultra_devtools_describe_callback($fn): string {
    if (is_string($fn)) { return $fn; }
    if (is_array($fn) && count($fn) === 2) {
        $obj = $fn[0];
        $method = (string) $fn[1];
        $cls = is_object($obj) ? get_class($obj) : (string) $obj;
        return $cls . '::' . $method;
    }
    if (is_object($fn) && !($fn instanceof \Closure)) {
        return get_class($fn) . '::__invoke';
    }
    if ($fn instanceof \Closure) { return '{closure}'; }
    return 'unknown';
}

// ---------------------------------------------------------------------------
// Pure: purge-cache probe map (which layer *would* fire — actual firing lives in the wrapper).
// ---------------------------------------------------------------------------

/**
 * Static description of every cache layer this ability knows how to purge, in probe form.
 * Each entry: ['id'=>string,'label'=>string,'type'=>'function'|'action'|'class_method'|'always',
 *              'target'=>string (function/action/class name)].
 * Pure — no side effects, no WordPress calls.
 */
function wpultra_devtools_purge_probes(): array {
    return [
        ['id' => 'wp_rocket',  'label' => 'WP Rocket',        'type' => 'function', 'target' => 'rocket_clean_domain'],
        ['id' => 'litespeed',  'label' => 'LiteSpeed Cache',   'type' => 'action',   'target' => 'litespeed_purge_all'],
        ['id' => 'w3tc',       'label' => 'W3 Total Cache',    'type' => 'function', 'target' => 'w3tc_flush_all'],
        ['id' => 'wp_super_cache', 'label' => 'WP Super Cache', 'type' => 'function', 'target' => 'wp_cache_clear_cache'],
        ['id' => 'autoptimize', 'label' => 'Autoptimize',      'type' => 'class_method', 'target' => 'autoptimizeCache::clearall'],
        ['id' => 'elementor',  'label' => 'Elementor CSS cache', 'type' => 'class_method', 'target' => '\\Elementor\\Plugin::files_manager'],
        ['id' => 'object_cache', 'label' => 'Object cache (wp_cache_flush)', 'type' => 'always', 'target' => 'wp_cache_flush'],
    ];
}

/**
 * Pure availability check for a single probe entry against injected existence-checker callables,
 * so the logic is testable without WordPress. $checkers: ['function_exists'=>callable,
 * 'action_exists'=>callable (hook has a callback attached), 'class_exists'=>callable].
 */
function wpultra_devtools_probe_available(array $probe, array $checkers): bool {
    $type = (string) ($probe['type'] ?? '');
    $target = (string) ($probe['target'] ?? '');
    if ($type === 'always') { return true; }
    if ($type === 'function') {
        $fn = $checkers['function_exists'] ?? 'function_exists';
        return (bool) $fn($target);
    }
    if ($type === 'action') {
        $fn = $checkers['action_exists'] ?? null;
        return $fn ? (bool) $fn($target) : false;
    }
    if ($type === 'class_method') {
        $cls = strtok($target, ':');
        $fn = $checkers['class_exists'] ?? 'class_exists';
        return (bool) $fn($cls);
    }
    return false;
}

/**
 * Given the static probe map + injected checkers, return ['purged'=>[id...], 'skipped'=>[id...]].
 * Does not execute anything — pure decision layer the wrapper acts on.
 */
function wpultra_devtools_plan_purge(array $probes, array $checkers): array {
    $purged = [];
    $skipped = [];
    foreach ($probes as $probe) {
        $id = (string) ($probe['id'] ?? '');
        if ($id === '') { continue; }
        if (wpultra_devtools_probe_available($probe, $checkers)) { $purged[] = $id; }
        else { $skipped[] = $id; }
    }
    return ['purged' => $purged, 'skipped' => $skipped];
}

// ---------------------------------------------------------------------------
// Pure: misc validation helpers.
// ---------------------------------------------------------------------------

/** Pure: minimal RFC-5322-ish email sanity check (delegates to filter_var, which needs no WP). */
function wpultra_devtools_is_valid_email(string $email): bool {
    return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

// ---------------------------------------------------------------------------
// WordPress-calling wrappers (thin; not unit-tested directly, exercised via ability callbacks).
// ---------------------------------------------------------------------------

/** SMTP-plugin detection probe map for send-email's mailer-info payload. Pure data, WP-agnostic. */
function wpultra_devtools_smtp_probes(): array {
    return [
        ['class' => 'WPMailSMTP\\Core', 'label' => 'WP Mail SMTP'],
        ['class' => 'Wpo\\Smtp\\SmtpProvider', 'label' => 'Post SMTP'],
        ['class' => 'EasyWPSMTP\\Core', 'label' => 'Easy WP SMTP'],
        ['function' => 'wp_smtp_activation', 'label' => 'WP SMTP'],
    ];
}

/** @return array send bool + failure detail + mailer info, hooked via wp_mail_failed for this single call. */
function wpultra_devtools_send_email(string $to, string $subject, string $body, bool $html) {
    if (!wpultra_devtools_is_valid_email($to)) { return wpultra_err('invalid_email', "Invalid recipient email address: $to"); }
    if (trim($subject) === '') { return wpultra_err('missing_subject', 'subject is required.'); }
    if (trim($body) === '') { return wpultra_err('missing_body', 'body is required.'); }

    $error = null;
    $capture = function ($wp_error) use (&$error) { $error = $wp_error; };
    if (function_exists('add_action')) { add_action('wp_mail_failed', $capture); }

    $headers = $html ? ['Content-Type: text/html; charset=UTF-8'] : [];
    $sent = function_exists('wp_mail') ? wp_mail($to, $subject, $body, $headers) : false;

    if (function_exists('remove_action')) { remove_action('wp_mail_failed', $capture); }

    $mailer_probes = wpultra_devtools_smtp_probes();
    $detected = [];
    foreach ($mailer_probes as $probe) {
        $ok = false;
        if (isset($probe['class'])) { $ok = class_exists((string) $probe['class']); }
        elseif (isset($probe['function'])) { $ok = function_exists((string) $probe['function']); }
        if ($ok) { $detected[] = (string) $probe['label']; }
    }

    $errorMessage = '';
    if ($error !== null && is_wp_error($error)) { $errorMessage = $error->get_error_message(); }

    wpultra_audit_log('send-email', "to=$to subject=" . mb_substr($subject, 0, 80) . ($sent ? ' sent' : ' FAILED: ' . $errorMessage), (bool) $sent);

    if (!$sent) { return wpultra_err('send_failed', $errorMessage !== '' ? $errorMessage : 'wp_mail() returned false.'); }

    return wpultra_ok([
        'sent'          => true,
        'to'            => $to,
        'smtp_detected' => $detected,
    ]);
}

/** @return array|WP_Error render-page report for a post_id or explicit URL. */
function wpultra_devtools_render_page(array $input) {
    $url = trim((string) ($input['url'] ?? ''));
    $post_id = (int) ($input['post_id'] ?? 0);
    if ($url === '' && $post_id <= 0) { return wpultra_err('missing_target', 'Provide either url or post_id.'); }
    if ($url === '') {
        if (!function_exists('get_post') || !get_post($post_id)) { return wpultra_err('bad_post', "post_id $post_id not found."); }
        $url = (string) get_permalink($post_id);
        if ($url === '') { return wpultra_err('no_permalink', "Could not resolve a permalink for post_id $post_id."); }
    }
    if (!function_exists('wp_remote_get')) { return wpultra_err('wp_unavailable', 'wp_remote_get() is unavailable.'); }

    $start = microtime(true);
    $response = wp_remote_get($url, ['timeout' => 20, 'sslverify' => false, 'redirection' => 5]);
    $load_ms = round((microtime(true) - $start) * 1000, 1);

    if (is_wp_error($response)) { return $response; }

    $status = (int) wp_remote_retrieve_response_code($response);
    $html = (string) wp_remote_retrieve_body($response);
    $report = wpultra_devtools_render_report($html, ['status' => $status, 'load_ms' => $load_ms, 'url' => $url]);
    return wpultra_ok($report);
}

/** @return array|WP_Error compact descriptors for the requested registry. */
function wpultra_devtools_list_registry(array $input) {
    $what = (string) ($input['what'] ?? '');
    switch ($what) {
        case 'post-types':
            if (!function_exists('get_post_types')) { return wpultra_err('wp_unavailable', 'get_post_types() is unavailable.'); }
            $types = [];
            foreach (get_post_types([], 'objects') as $name => $obj) {
                $types[$name] = ['label' => $obj->label ?? $name, 'public' => (bool) ($obj->public ?? false)];
            }
            return wpultra_ok(['what' => $what, 'items' => wpultra_devtools_shape_post_types($types)]);

        case 'taxonomies':
            if (!function_exists('get_taxonomies')) { return wpultra_err('wp_unavailable', 'get_taxonomies() is unavailable.'); }
            $taxes = [];
            foreach (get_taxonomies([], 'objects') as $name => $obj) {
                $taxes[$name] = ['label' => $obj->label ?? $name, 'public' => (bool) ($obj->public ?? false), 'object_type' => $obj->object_type ?? []];
            }
            return wpultra_ok(['what' => $what, 'items' => wpultra_devtools_shape_taxonomies($taxes)]);

        case 'shortcodes':
            global $shortcode_tags;
            $tags = is_array($shortcode_tags ?? null) ? $shortcode_tags : [];
            return wpultra_ok(['what' => $what, 'items' => wpultra_devtools_shape_shortcodes($tags)]);

        case 'roles':
            if (!function_exists('wp_roles')) { return wpultra_err('wp_unavailable', 'wp_roles() is unavailable.'); }
            $roles = wp_roles()->roles ?? [];
            return wpultra_ok(['what' => $what, 'items' => wpultra_devtools_shape_roles(is_array($roles) ? $roles : [])]);

        case 'image-sizes':
            if (!function_exists('wp_get_additional_image_sizes')) { return wpultra_err('wp_unavailable', 'wp_get_additional_image_sizes() is unavailable.'); }
            global $_wp_additional_image_sizes;
            $sizes = [];
            foreach (['thumbnail', 'medium', 'medium_large', 'large'] as $core) {
                $sizes[$core] = [
                    'width'  => (int) get_option("{$core}_size_w"),
                    'height' => (int) get_option("{$core}_size_h"),
                    'crop'   => (bool) get_option("{$core}_crop"),
                ];
            }
            foreach ((array) $_wp_additional_image_sizes as $name => $dim) { $sizes[$name] = $dim; }
            return wpultra_ok(['what' => $what, 'items' => wpultra_devtools_shape_image_sizes($sizes)]);

        case 'rest-routes':
            if (!function_exists('rest_get_server')) { return wpultra_err('wp_unavailable', 'rest_get_server() is unavailable.'); }
            $routes = rest_get_server()->get_routes();
            return wpultra_ok(['what' => $what, 'items' => wpultra_devtools_shape_rest_routes(is_array($routes) ? $routes : [])]);

        case 'hooks':
            $hook = trim((string) ($input['hook'] ?? ''));
            if ($hook === '') { return wpultra_err('missing_hook', "hook is required when what='hooks'."); }
            global $wp_filter;
            $callbacks = [];
            if (isset($wp_filter[$hook])) {
                $obj = $wp_filter[$hook];
                if (is_object($obj) && isset($obj->callbacks)) { $callbacks = $obj->callbacks; }
                elseif (is_array($obj)) { $callbacks = $obj; }
            }
            return wpultra_ok(['what' => $what, 'hook' => $hook, 'items' => wpultra_devtools_shape_hook_callbacks($callbacks)]);

        default:
            return wpultra_err('unknown_registry', "Unknown what='$what'.");
    }
}

/** @return array purge-cache result: which layers purged vs skipped, executed for real via the pure plan. */
function wpultra_devtools_purge_cache() {
    $probes = wpultra_devtools_purge_probes();
    $checkers = [
        'function_exists' => 'function_exists',
        'class_exists'    => 'class_exists',
        'action_exists'   => static function (string $hook): bool {
            return function_exists('has_action') ? (bool) has_action($hook) : false;
        },
    ];
    $plan = wpultra_devtools_plan_purge($probes, $checkers);

    // Execute each purged layer for real, best-effort (never fatal — wrap in try/catch).
    foreach ($plan['purged'] as $id) {
        try {
            switch ($id) {
                case 'wp_rocket':
                    if (function_exists('rocket_clean_domain')) { rocket_clean_domain(); }
                    break;
                case 'litespeed':
                    if (function_exists('do_action')) { do_action('litespeed_purge_all'); }
                    break;
                case 'w3tc':
                    if (function_exists('w3tc_flush_all')) { w3tc_flush_all(); }
                    break;
                case 'wp_super_cache':
                    if (function_exists('wp_cache_clear_cache')) { wp_cache_clear_cache(); }
                    break;
                case 'autoptimize':
                    if (class_exists('autoptimizeCache') && method_exists('autoptimizeCache', 'clearall')) { \autoptimizeCache::clearall(); }
                    break;
                case 'elementor':
                    if (class_exists('\\Elementor\\Plugin') && isset(\Elementor\Plugin::$instance->files_manager)) {
                        \Elementor\Plugin::$instance->files_manager->clear_cache();
                    }
                    break;
                case 'object_cache':
                    if (function_exists('wp_cache_flush')) { wp_cache_flush(); }
                    break;
            }
        } catch (\Throwable $e) {
            // Best-effort: a misbehaving third-party cache plugin must not fatal the ability.
            $plan['skipped'][] = $id . ' (error: ' . $e->getMessage() . ')';
        }
    }

    wpultra_audit_log('purge-cache', 'purged: ' . implode(', ', $plan['purged']) . '; skipped: ' . implode(', ', $plan['skipped']), true);

    return wpultra_ok(['purged' => $plan['purged'], 'skipped' => $plan['skipped']]);
}
