<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Fix-permalinks engine (Roadmap 4, BF1.3).
 *
 * Fixes the classic "every page is 404" breakage: flush WordPress's own rewrite
 * rules, regenerate the "# BEGIN WordPress" .htaccess block via WP's own
 * save_mod_rewrite_rules() (never hand-write the file), and verify with a single
 * HTTP probe against one published post.
 *
 * IMPORTANT: this file's marker-block detector (wpultra_permalinks_htaccess_has_wp_block)
 * only recognizes "# BEGIN WordPress" — it must never match or touch the separate
 * "# BEGIN WPUltra" block owned by includes/abilities/manage-server-rules.php.
 */

/* ============================================================
 * PURE helpers (unit-tested — no WordPress required)
 * ============================================================ */

/** PURE: the permalink structure tags WordPress recognizes. */
function wpultra_permalinks_known_tags(): array {
    return [
        '%year%', '%monthnum%', '%day%', '%hour%', '%minute%', '%second%',
        '%post_id%', '%postname%', '%category%', '%author%',
    ];
}

/**
 * PURE: validate a custom permalink structure string.
 * Must start with '/' and contain at least one recognized tag.
 *
 * @return true|WP_Error
 */
function wpultra_permalinks_validate_structure(string $s) {
    if ($s === '' || $s[0] !== '/') {
        return wpultra_err('bad_structure', 'Permalink structure must start with a slash (e.g. /%postname%/).');
    }
    foreach (wpultra_permalinks_known_tags() as $tag) {
        if (str_contains($s, $tag)) { return true; }
    }
    return wpultra_err(
        'bad_structure',
        'Permalink structure must contain at least one recognized tag: ' . implode(', ', wpultra_permalinks_known_tags())
    );
}

/**
 * PURE: does $contents contain WordPress's own managed .htaccess marker block?
 * Matches "# BEGIN WordPress" in any case/spacing. Deliberately does NOT match
 * the plugin's own "# BEGIN WPUltra" block (different marker name) — that block
 * is owned by includes/abilities/manage-server-rules.php and must never be
 * touched here.
 */
function wpultra_permalinks_htaccess_has_wp_block(string $contents): bool {
    if ($contents === '') { return false; }
    return (bool) preg_match('/#\s*BEGIN\s+WordPress\b/i', $contents);
}

/**
 * PURE: extract WordPress's own "# BEGIN WordPress" ... "# END WordPress" marker
 * block (markers inclusive), case-insensitively. Returns '' when absent. Used to
 * compare .htaccess state before vs after a flush so the `fix` action can report
 * 'no_change' instead of unconditionally reporting 'written'.
 */
function wpultra_permalinks_extract_wp_block(string $contents): string {
    if ($contents === '') { return ''; }
    if (preg_match('/#\s*BEGIN\s+WordPress\b.*?#\s*END\s+WordPress\b/is', $contents, $m)) {
        return $m[0];
    }
    return '';
}

/**
 * PURE: assemble the `status` action's output from plain data. No WordPress
 * calls — the WP-touching wrapper (wpultra_permalinks_get_status) gathers the
 * raw inputs and delegates the shaping here so the logic is fully testable.
 */
function wpultra_permalinks_status_shape(
    string $permalink_structure,
    ?array $rewrite_rules,
    bool $is_apache,
    bool $is_nginx,
    bool $got_mod_rewrite,
    string $htaccess_path,
    bool $htaccess_exists,
    bool $htaccess_writable,
    string $htaccess_contents,
    string $home_url,
    string $site_url
): array {
    $server = $is_apache ? 'apache' : ($is_nginx ? 'nginx' : 'other');

    return [
        'permalink_structure'    => $permalink_structure,
        'pretty_permalinks'      => $permalink_structure !== '',
        'rewrite_rules_present'  => $rewrite_rules !== null,
        'rewrite_rules_count'    => is_array($rewrite_rules) ? count($rewrite_rules) : 0,
        'server'                 => $server,
        'mod_rewrite'            => $got_mod_rewrite,
        'htaccess'               => [
            'path'         => $htaccess_path,
            'exists'       => $htaccess_exists,
            'writable'     => $htaccess_writable,
            'has_wp_block' => wpultra_permalinks_htaccess_has_wp_block($htaccess_contents),
        ],
        'home_siteurl_mismatch'  => untrailingslashit($home_url) !== untrailingslashit($site_url),
    ];
}

/* ============================================================
 * WP-touching wrappers (live-tested by the controller, not unit tests)
 * ============================================================ */

/** Resolve the .htaccess path the same way core's get_home_path() would. */
function wpultra_permalinks_htaccess_path(): string {
    if (function_exists('get_home_path')) {
        return get_home_path() . '.htaccess';
    }
    $base = defined('ABSPATH') ? ABSPATH : '/';
    return rtrim(str_replace('\\', '/', $base), '/') . '/.htaccess';
}

/** Gather raw status inputs from WordPress and delegate shaping to the pure function. */
function wpultra_permalinks_get_status(): array {
    global $is_apache, $is_nginx;

    $structure = (string) get_option('permalink_structure', '');
    $rules = get_option('rewrite_rules');
    $rules = is_array($rules) ? $rules : null;
    $got_mod_rewrite = function_exists('got_mod_rewrite') ? (bool) got_mod_rewrite() : false;

    $htaccess_path = wpultra_permalinks_htaccess_path();
    $exists = file_exists($htaccess_path);
    $writable = $exists ? is_writable($htaccess_path) : is_writable(dirname($htaccess_path));
    $contents = $exists ? (string) @file_get_contents($htaccess_path) : '';

    return wpultra_permalinks_status_shape(
        $structure,
        $rules,
        (bool) ($is_apache ?? false),
        (bool) ($is_nginx ?? false),
        $got_mod_rewrite,
        $htaccess_path,
        $exists,
        $writable,
        $contents,
        (string) home_url(),
        (string) site_url()
    );
}

/**
 * Load the admin includes flush_rewrite_rules()/save_mod_rewrite_rules() need.
 * Outside wp-admin these aren't loaded, and save_mod_rewrite_rules() silently
 * no-ops if got_mod_rewrite()/get_home_path() aren't defined yet.
 */
function wpultra_permalinks_require_admin_includes(): void {
    if (!function_exists('get_home_path')) {
        $file = ABSPATH . 'wp-admin/includes/file.php';
        if (is_readable($file)) { require_once $file; }
    }
    if (!function_exists('got_mod_rewrite') || !function_exists('save_mod_rewrite_rules')) {
        $misc = ABSPATH . 'wp-admin/includes/misc.php';
        if (is_readable($misc)) { require_once $misc; }
    }
}

/**
 * Probe one published post to confirm permalinks resolve after the fix. Exactly
 * ONE HTTP request — never loop probes, single-worker dev hosts deadlock on a
 * nested self-request.
 */
function wpultra_permalinks_verify(): ?array {
    $posts = get_posts(['numberposts' => 1, 'post_status' => 'publish', 'orderby' => 'ID', 'order' => 'DESC']);
    if (empty($posts)) { return null; }

    $permalink = (string) get_permalink($posts[0]);
    $response = wp_remote_get($permalink, ['timeout' => 10, 'sslverify' => false]);
    if (is_wp_error($response)) {
        return ['url' => $permalink, 'status' => 0, 'ok' => false];
    }
    $status = (int) wp_remote_retrieve_response_code($response);
    return ['url' => $permalink, 'status' => $status, 'ok' => $status >= 200 && $status < 400];
}

/**
 * `fix` action: optionally set a new permalink structure, hard-flush rewrite
 * rules (which regenerates the .htaccess block via core's own
 * save_mod_rewrite_rules() — never hand-write the file), then verify with a
 * single HTTP probe.
 *
 * @return array|WP_Error
 */
function wpultra_permalinks_fix(array $input) {
    if (($input['confirm'] ?? false) !== true) {
        return wpultra_err('confirm_required', 'Regenerating permalinks requires confirm:true.');
    }

    global $wp_rewrite, $is_nginx;

    $structure = null;
    if (isset($input['structure']) && $input['structure'] !== '') {
        $structure = (string) $input['structure'];
        $valid = wpultra_permalinks_validate_structure($structure);
        if (is_wp_error($valid)) { return $valid; }
    }

    // Load admin includes FIRST or the .htaccess regeneration inside flush_rewrite_rules()
    // silently no-ops outside wp-admin.
    wpultra_permalinks_require_admin_includes();

    if ($structure !== null && isset($wp_rewrite) && is_object($wp_rewrite)) {
        $wp_rewrite->set_permalink_structure($structure);
    }

    // Snapshot the WP marker block BEFORE the flush so the apache-writable branch
    // below can tell whether the flush actually changed anything (see wpultra_ok
    // shape: 'written' vs 'no_change' below).
    $htaccess_path = wpultra_permalinks_htaccess_path();
    $before_contents = file_exists($htaccess_path) ? (string) @file_get_contents($htaccess_path) : '';
    $before_block = wpultra_permalinks_extract_wp_block($before_contents);

    flush_rewrite_rules(true);

    $htaccess_result = 'no_change';
    $manual_rules = null;
    $note = '';

    if (!empty($is_nginx)) {
        $htaccess_result = 'skipped_nginx';
        $note = 'nginx does not use .htaccess; add the equivalent rewrite rules to your server config manually.';
    } else {
        $exists = file_exists($htaccess_path);
        $writable = $exists ? is_writable($htaccess_path) : is_writable(dirname($htaccess_path));
        if (!$writable) {
            $htaccess_result = 'skipped_unwritable';
            $manual_rules = isset($wp_rewrite) && is_object($wp_rewrite) ? $wp_rewrite->mod_rewrite_rules() : '';
        } else {
            $after_contents = $exists ? (string) @file_get_contents($htaccess_path) : '';
            $after_block = wpultra_permalinks_extract_wp_block($after_contents);
            $htaccess_result = ($after_block !== '' && $after_block === $before_block) ? 'no_change' : 'written';
        }
    }

    $result = [
        'flushed'   => true,
        'structure' => $structure ?? (string) get_option('permalink_structure', ''),
        'htaccess'  => $htaccess_result,
    ];
    if ($manual_rules !== null) { $result['manual_rules'] = $manual_rules; }

    $verify = wpultra_permalinks_verify();
    if ($verify !== null) {
        $result['verify'] = $verify;
    } else {
        $note = trim($note . ' No published post found to verify against.');
    }
    if ($note !== '') { $result['note'] = $note; }

    return wpultra_ok($result);
}
