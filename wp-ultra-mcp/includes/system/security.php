<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Security hardening + malware/integrity scan engine (Roadmap-2 S2).
 *
 * Two families:
 *
 *  HARDEN — apply idempotent toggles that shrink the attack surface. Each toggle
 *  reports applied|skipped plus a human-readable "undo" note so the caller can
 *  reverse it. Toggles that must survive across requests are option-backed and
 *  wired into the always-on runtime by wpultra_security_boot() (the controller
 *  hooks that in — this file only defines it). The one config-file toggle
 *  (disable_file_edit) edits wp-config.php through a marker block written before
 *  the "stop editing" sentinel line.
 *
 *  SCAN — read-only, capped/time-guarded integrity + malware heuristics:
 *   - core_checksums(): compare on-disk wp-admin/wp-includes files against the
 *     official wp.org checksum manifest for the installed version.
 *   - suspicious_code(): scan plugin + uploads .php files for high-signal
 *     backdoor patterns (eval, base64_decode, obfuscation, superglobal-invoke,
 *     preg_replace /e, system/exec/...). A .php file anywhere under uploads is
 *     itself a red flag.
 *   - recently_modified(): files changed inside a lookback window.
 *
 * The PURE functions (wpultra_security_scan_content, wpultra_security_wpconfig_set,
 * wpultra_security_harden_plan) are the testable core: no WordPress calls.
 */

/* =====================================================================
 * PURE — suspicious-code pattern matrix + scanner.
 * ===================================================================== */

/**
 * PURE. The malware/backdoor pattern matrix. Each entry:
 *   id       — stable identifier
 *   regex    — PCRE (case-insensitive applied by the scanner)
 *   severity — 'high' | 'medium' | 'low'
 *   label    — human description
 *
 * High-signal patterns favoured over broad ones to keep false positives low.
 * @return array<int,array{id:string,regex:string,severity:string,label:string}>
 */
function wpultra_security_scan_patterns(): array {
    return [
        ['id' => 'eval',            'regex' => '/\beval\s*\(/',                              'severity' => 'high',   'label' => 'eval() — arbitrary code execution'],
        ['id' => 'base64_decode',   'regex' => '/\bbase64_decode\s*\(/',                     'severity' => 'high',   'label' => 'base64_decode() — common payload obfuscation'],
        ['id' => 'gzinflate',       'regex' => '/\bgzinflate\s*\(/',                         'severity' => 'high',   'label' => 'gzinflate() — compressed-payload obfuscation'],
        ['id' => 'str_rot13',       'regex' => '/\bstr_rot13\s*\(/',                         'severity' => 'medium', 'label' => 'str_rot13() — string obfuscation'],
        // $_POST['x'](...), $_GET[...](...), $_REQUEST[...](...) — invoking a superglobal as a callable.
        ['id' => 'superglobal_call','regex' => '/\$_(POST|REQUEST|GET)\s*\[[^\]]*\]\s*\(/',  'severity' => 'high',   'label' => 'superglobal invoked as a function — request-driven code execution'],
        // preg_replace with the deprecated /e modifier (executes replacement as PHP).
        // Anchored to a single quoted string ((?!\1) — never crosses into the next
        // argument) ending in <delimiter><modifiers-containing-e> right before the
        // closing quote, so plain preg_replace('/x/', 'we…') calls don't false-positive.
        ['id' => 'preg_replace_e',  'regex' => '/\bpreg_replace\s*\(\s*([\'"])(?:(?!\1).)*[\/#~|!%][imsxuadj]*e[imsxuadj]*\1/s', 'severity' => 'high', 'label' => 'preg_replace() /e modifier — executes replacement as code'],
        ['id' => 'assert',          'regex' => '/\bassert\s*\(/',                            'severity' => 'medium', 'label' => 'assert() — can execute a string as code'],
        ['id' => 'shell_exec',      'regex' => '/\b(system|exec|shell_exec|passthru)\s*\(/', 'severity' => 'high',   'label' => 'shell command execution (system/exec/shell_exec/passthru)'],
    ];
}

/** PURE. The severity rank used to pick the highest severity for a summary. */
function wpultra_security_severity_rank(string $severity): int {
    switch ($severity) {
        case 'high':   return 3;
        case 'medium': return 2;
        case 'low':    return 1;
        default:       return 0;
    }
}

/**
 * PURE. Scan a blob of code against the pattern matrix. Returns one hit per
 * matching pattern (deduped by pattern id — a pattern that matches many times
 * yields a single hit with a count), each: {id, severity, label, count}.
 *
 * @return array<int,array{id:string,severity:string,label:string,count:int}>
 */
function wpultra_security_scan_content(string $code): array {
    $hits = [];
    foreach (wpultra_security_scan_patterns() as $p) {
        $n = @preg_match_all($p['regex'] . 'i', $code, $m);
        if ($n === false || $n < 1) { continue; }
        $hits[] = [
            'id'       => $p['id'],
            'severity' => $p['severity'],
            'label'    => $p['label'],
            'count'    => (int) $n,
        ];
    }
    return $hits;
}

/**
 * PURE. Reduce a set of scan hits to the highest severity present ('' when none).
 * @param array<int,array{severity?:string}> $hits
 */
function wpultra_security_highest_severity(array $hits): string {
    $best = '';
    $bestRank = 0;
    foreach ($hits as $h) {
        $sev = is_array($h) ? (string) ($h['severity'] ?? '') : '';
        $rank = wpultra_security_severity_rank($sev);
        if ($rank > $bestRank) { $bestRank = $rank; $best = $sev; }
    }
    return $best;
}

/* =====================================================================
 * PURE — wp-config.php constant editor.
 * ===================================================================== */

/**
 * PURE. Insert or replace a `define('CONST', value);` in a wp-config.php source
 * string, idempotently, immediately before the "stop editing" sentinel line
 * (`/* That's all, stop editing! ... *​/`). Existing definitions of the same
 * constant (anywhere in the file) are replaced in place; if none exists a new
 * define is inserted before the sentinel.
 *
 * $value handling:
 *   - bool  -> true / false (unquoted)
 *   - int   -> the integer literal
 *   - string-> single-quoted, with backslashes and single quotes escaped
 *
 * Returns the rewritten source string, or a WP_Error when the sentinel anchor
 * line is missing (we refuse to guess an insertion point in an unfamiliar file).
 *
 * @param string $config wp-config.php contents
 * @param string $const  constant name (e.g. DISALLOW_FILE_EDIT)
 * @param bool|int|string $value
 * @return string|WP_Error
 */
function wpultra_security_wpconfig_set(string $config, string $const, $value) {
    if (!preg_match('/^[A-Z_][A-Z0-9_]*$/i', $const)) {
        return wpultra_err('bad_const', "Invalid constant name '$const'.");
    }

    // Literal for the value.
    if (is_bool($value)) {
        $literal = $value ? 'true' : 'false';
    } elseif (is_int($value)) {
        $literal = (string) $value;
    } else {
        $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], (string) $value);
        $literal = "'" . $escaped . "'";
    }

    $define = "define('$const', $literal);";

    // Replace an existing define('CONST', ...); of any spelling/spacing.
    $replacePattern = '/define\s*\(\s*([\'"])' . preg_quote($const, '/') . '\1\s*,.*?\)\s*;/is';
    if (preg_match($replacePattern, $config)) {
        return (string) preg_replace($replacePattern, $define, $config, 1);
    }

    // Otherwise insert before the "stop editing" sentinel line.
    $sentinel = '/^.*stop editing.*$/mi';
    if (!preg_match($sentinel, $config, $m, PREG_OFFSET_CAPTURE)) {
        return wpultra_err('anchor_missing', "wp-config.php is missing the \"stop editing\" sentinel line; refusing to guess an insertion point.");
    }
    $offset = $m[0][1];
    $insertion = $define . "\n\n";
    return substr($config, 0, $offset) . $insertion . substr($config, $offset);
}

/* =====================================================================
 * PURE — harden plan (which requested measures still need applying).
 * ===================================================================== */

/** PURE. The canonical set of hardening measure ids. */
function wpultra_security_known_measures(): array {
    return ['disable-file-edit', 'disable-xmlrpc', 'limit-login', 'security-headers', 'hide-version'];
}

/**
 * PURE. Split the requested measures into those that still need applying vs those
 * already satisfied by the current state. Unknown measures are dropped.
 *
 * $current_state maps measure-id => bool ("already done?").
 *
 * @param array $requested     list of measure ids
 * @param array $current_state measure-id => bool
 * @return array{to_apply:array<int,string>, already_done:array<int,string>}
 */
function wpultra_security_harden_plan(array $requested, array $current_state): array {
    $known = wpultra_security_known_measures();
    $to_apply = [];
    $already_done = [];
    $seen = [];
    foreach ($requested as $m) {
        $m = (string) $m;
        if (!in_array($m, $known, true) || isset($seen[$m])) { continue; }
        $seen[$m] = true;
        if (!empty($current_state[$m])) {
            $already_done[] = $m;
        } else {
            $to_apply[] = $m;
        }
    }
    return ['to_apply' => $to_apply, 'already_done' => $already_done];
}

/* =====================================================================
 * HARDEN — option flags + wp-config write (WordPress-touching wrappers).
 * The runtime consumers live in wpultra_security_boot().
 * ===================================================================== */

const WPULTRA_SECURITY_OPTION = 'wpultra_security';

/** Read the persisted security-toggle option map. WP-touching. */
function wpultra_security_get_state(): array {
    $state = function_exists('get_option') ? get_option(WPULTRA_SECURITY_OPTION, []) : [];
    return is_array($state) ? $state : [];
}

/** Persist a key in the security-toggle option map. WP-touching. */
function wpultra_security_set_state(string $key, $value): void {
    $state = wpultra_security_get_state();
    $state[$key] = $value;
    if (function_exists('update_option')) { update_option(WPULTRA_SECURITY_OPTION, $state, true); }
}

/** Locate wp-config.php (ABSPATH, or one directory up — WP's own fallback layout). */
function wpultra_security_wpconfig_path(): string {
    $root = rtrim(ABSPATH, '/\\');
    if (is_file($root . '/wp-config.php')) { return $root . '/wp-config.php'; }
    $up = dirname($root);
    if (is_file($up . '/wp-config.php') && !is_file($up . '/wp-settings.php')) { return $up . '/wp-config.php'; }
    return $root . '/wp-config.php';
}

/**
 * HARDEN: disable_file_edit. Writes define('DISALLOW_FILE_EDIT', true); into
 * wp-config.php via the pure editor. Idempotent (skips when already defined at
 * runtime). Returns a result row.
 *
 * @return array{measure:string,status:string,detail:string,undo:string}
 */
function wpultra_security_harden_disable_file_edit(): array {
    $measure = 'disable-file-edit';
    if (defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT) {
        return ['measure' => $measure, 'status' => 'skipped', 'detail' => 'DISALLOW_FILE_EDIT already defined and true.', 'undo' => "Remove the define('DISALLOW_FILE_EDIT', true); line from wp-config.php."];
    }
    $path = wpultra_security_wpconfig_path();
    if (!is_file($path) || !is_readable($path)) {
        return ['measure' => $measure, 'status' => 'error', 'detail' => "wp-config.php not found or unreadable at $path.", 'undo' => ''];
    }
    if (!is_writable($path)) {
        return ['measure' => $measure, 'status' => 'error', 'detail' => "wp-config.php is not writable at $path.", 'undo' => ''];
    }
    $src = (string) file_get_contents($path);
    $new = wpultra_security_wpconfig_set($src, 'DISALLOW_FILE_EDIT', true);
    if (is_wp_error($new)) {
        return ['measure' => $measure, 'status' => 'error', 'detail' => $new->get_error_message(), 'undo' => ''];
    }
    // Back up before writing (single-slot, sibling file).
    @copy($path, $path . '.wpultra-security-backup');
    $ok = @file_put_contents($path, $new) !== false;
    if (!$ok) {
        return ['measure' => $measure, 'status' => 'error', 'detail' => "Failed to write wp-config.php at $path.", 'undo' => ''];
    }
    return ['measure' => $measure, 'status' => 'applied', 'detail' => "Wrote define('DISALLOW_FILE_EDIT', true); to wp-config.php (backup at $path.wpultra-security-backup).", 'undo' => "Delete the define('DISALLOW_FILE_EDIT', true); line from wp-config.php, or restore $path.wpultra-security-backup."];
}

/**
 * HARDEN: disable_xmlrpc. Sets an option flag consumed at runtime by
 * wpultra_security_boot() (which filters xmlrpc_enabled to false). Idempotent.
 *
 * @return array{measure:string,status:string,detail:string,undo:string}
 */
function wpultra_security_harden_disable_xmlrpc(): array {
    $measure = 'disable-xmlrpc';
    $state = wpultra_security_get_state();
    if (!empty($state['disable_xmlrpc'])) {
        return ['measure' => $measure, 'status' => 'skipped', 'detail' => 'XML-RPC already disabled by WPUltra.', 'undo' => "Re-run security-harden without disable-xmlrpc, or clear the 'disable_xmlrpc' key in the wpultra_security option."];
    }
    wpultra_security_set_state('disable_xmlrpc', true);
    return ['measure' => $measure, 'status' => 'applied', 'detail' => 'XML-RPC will be disabled on every request (xmlrpc_enabled filter forced false).', 'undo' => "Clear the 'disable_xmlrpc' key in the wpultra_security option."];
}

/**
 * HARDEN: limit_login. Stores max attempts + lockout minutes; the runtime hook
 * (wpultra_security_boot) counts failed logins and blocks over the threshold.
 *
 * @param array $options {max_attempts?:int, lockout_minutes?:int}
 * @return array{measure:string,status:string,detail:string,undo:string}
 */
function wpultra_security_harden_limit_login(array $options = []): array {
    $measure = 'limit-login';
    $max     = isset($options['max_attempts']) ? max(1, (int) $options['max_attempts']) : 5;
    $lockout = isset($options['lockout_minutes']) ? max(1, (int) $options['lockout_minutes']) : 15;
    $state = wpultra_security_get_state();
    $existing = $state['limit_login'] ?? null;
    if (is_array($existing) && (int) ($existing['max_attempts'] ?? 0) === $max && (int) ($existing['lockout_minutes'] ?? 0) === $lockout) {
        return ['measure' => $measure, 'status' => 'skipped', 'detail' => "Login limiting already set to $max attempts / $lockout min lockout.", 'undo' => "Clear the 'limit_login' key in the wpultra_security option."];
    }
    wpultra_security_set_state('limit_login', ['max_attempts' => $max, 'lockout_minutes' => $lockout]);
    return ['measure' => $measure, 'status' => 'applied', 'detail' => "Login attempts limited to $max, then a $lockout-minute lockout per IP.", 'undo' => "Clear the 'limit_login' key in the wpultra_security option."];
}

/**
 * HARDEN: security_headers. Delegates to the rules engine's security-headers
 * preset (writes .htaccess / composes nginx text). Requires the rules engine.
 *
 * @return array{measure:string,status:string,detail:string,undo:string}
 */
function wpultra_security_harden_security_headers(): array {
    $measure = 'security-headers';
    if (!function_exists('wpultra_rules_set')) {
        return ['measure' => $measure, 'status' => 'error', 'detail' => 'Server-rules engine unavailable (includes/system/rules.php not loaded).', 'undo' => ''];
    }
    // rules_set REPLACES the whole managed block — merge with presets already
    // there (e.g. browser-caching/gzip written by optimize-cache) when the
    // optimizer's section parser is available.
    $presets = ['security-headers'];
    $custom  = [];
    if (function_exists('wpultra_optimize_rules_sections') && function_exists('wpultra_rules_preset_registry') && function_exists('wpultra_rules_get')) {
        $map = [];
        foreach (wpultra_rules_preset_registry() as $pname => $builder) { $map[$pname] = (array) call_user_func($builder); }
        $cur = wpultra_rules_get();
        $lines = is_array($cur) && isset($cur['lines']) && is_array($cur['lines']) ? $cur['lines'] : [];
        $sections = wpultra_optimize_rules_sections($lines, $map);
        $presets = array_values(array_unique(array_merge($sections['presets'], $presets)));
        $custom  = $sections['custom'];
    }
    $res = wpultra_rules_set(['presets' => $presets, 'custom_lines' => $custom, 'confirm' => true]);
    if (is_wp_error($res)) {
        return ['measure' => $measure, 'status' => 'error', 'detail' => $res->get_error_message(), 'undo' => ''];
    }
    $mode = is_array($res) ? (string) ($res['mode'] ?? '') : '';
    // The rules engine writes .htaccess whenever the file exists — but on nginx
    // that file is inert, so flag it even in "apache" mode.
    $on_nginx = $mode === 'nginx' || (function_exists('wpultra_rules_is_nginx') && wpultra_rules_is_nginx());
    $note = $on_nginx ? ' WARNING: this server appears to be nginx — .htaccess rules have NO effect; add the equivalent add_header directives to the nginx server config manually (see manage-server-rules action=get for the composed text)' : '';
    return ['measure' => $measure, 'status' => $on_nginx ? 'partial' : 'applied', 'detail' => 'Security headers written via the server-rules engine.' . $note, 'undo' => "Run manage-server-rules with action=clear (or restore-backup) to remove the managed .htaccess block."];
}

/**
 * HARDEN: hide_wp_version. Sets an option flag; the runtime hook removes the
 * wp_generator action and strips the ?ver= ...  meta. Idempotent.
 *
 * @return array{measure:string,status:string,detail:string,undo:string}
 */
function wpultra_security_harden_hide_version(): array {
    $measure = 'hide-version';
    $state = wpultra_security_get_state();
    if (!empty($state['hide_version'])) {
        return ['measure' => $measure, 'status' => 'skipped', 'detail' => 'WordPress version meta already hidden by WPUltra.', 'undo' => "Clear the 'hide_version' key in the wpultra_security option."];
    }
    wpultra_security_set_state('hide_version', true);
    return ['measure' => $measure, 'status' => 'applied', 'detail' => 'The generator meta tag will be removed from wp_head on every request.', 'undo' => "Clear the 'hide_version' key in the wpultra_security option."];
}

/**
 * HARDEN dispatcher. Applies each requested (still-needed) measure and returns
 * the per-measure result rows plus the plan split.
 *
 * @param array $measures list of measure ids
 * @param array $options  measure options (e.g. limit-login {max_attempts, lockout_minutes})
 * @return array{results:array<int,array>, plan:array}
 */
function wpultra_security_harden(array $measures, array $options = []): array {
    // Build current-state map so already-satisfied measures are reported, not re-applied.
    $state = wpultra_security_get_state();
    $current = [
        'disable-file-edit' => defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT,
        'disable-xmlrpc'    => !empty($state['disable_xmlrpc']),
        'limit-login'       => false, // options may differ; let the toggle decide skip/apply
        'security-headers'  => false, // .htaccess state is external; always attempt (rules engine dedupes)
        'hide-version'      => !empty($state['hide_version']),
    ];
    $plan = wpultra_security_harden_plan($measures, $current);

    $results = [];
    foreach ($plan['already_done'] as $m) {
        $results[] = ['measure' => $m, 'status' => 'skipped', 'detail' => 'Already applied.', 'undo' => ''];
    }
    foreach ($plan['to_apply'] as $m) {
        switch ($m) {
            case 'disable-file-edit': $results[] = wpultra_security_harden_disable_file_edit(); break;
            case 'disable-xmlrpc':    $results[] = wpultra_security_harden_disable_xmlrpc();    break;
            case 'limit-login':       $results[] = wpultra_security_harden_limit_login($options); break;
            case 'security-headers':  $results[] = wpultra_security_harden_security_headers();  break;
            case 'hide-version':      $results[] = wpultra_security_harden_hide_version();       break;
        }
    }
    return ['results' => $results, 'plan' => $plan];
}

/* =====================================================================
 * SCAN — integrity + malware heuristics (WordPress-touching, capped).
 * ===================================================================== */

/** Default per-scan file cap (files inspected before we stop, to bound runtime). */
function wpultra_security_scan_file_cap(): int {
    $n = (int) (function_exists('apply_filters') ? apply_filters('wpultra_security_scan_file_cap', 5000) : 5000);
    return $n > 0 ? $n : 5000;
}

/**
 * SCAN: core_checksums(). Fetches the official wp.org checksum manifest for the
 * installed core version and compares md5 of each wp-admin/wp-includes file.
 * Returns findings: modified[], missing[], plus meta. Skips (with a note) if the
 * version is unknown or the manifest can't be fetched.
 *
 * @return array
 */
function wpultra_security_scan_core_checksums(): array {
    $version = function_exists('get_bloginfo') ? (string) get_bloginfo('version') : '';
    $locale  = 'en_US';
    if ($version === '') {
        return ['scan' => 'checksums', 'status' => 'skipped', 'note' => 'Core version unknown; cannot fetch checksum manifest.', 'modified' => [], 'missing' => []];
    }

    $url = 'https://api.wordpress.org/core/checksums/1.0/?version=' . rawurlencode($version) . '&locale=' . rawurlencode($locale);
    $body = '';
    if (function_exists('wp_remote_get')) {
        $resp = wp_remote_get($url, ['timeout' => 15]);
        if (is_wp_error($resp)) {
            return ['scan' => 'checksums', 'status' => 'skipped', 'note' => 'Could not reach api.wordpress.org: ' . $resp->get_error_message(), 'modified' => [], 'missing' => []];
        }
        $body = function_exists('wp_remote_retrieve_body') ? (string) wp_remote_retrieve_body($resp) : '';
    }
    $data = json_decode($body, true);
    $checksums = is_array($data) && isset($data['checksums']) && is_array($data['checksums']) ? $data['checksums'] : null;
    if (!$checksums) {
        return ['scan' => 'checksums', 'status' => 'skipped', 'note' => "No checksums returned for version $version (locale $locale).", 'modified' => [], 'missing' => []];
    }

    $root = rtrim(ABSPATH, '/\\');
    $modified = [];
    $missing  = [];
    $checked  = 0;
    $cap      = wpultra_security_scan_file_cap();
    foreach ($checksums as $rel => $md5) {
        // Limit to the two core code directories (skip wp-content etc.).
        if (strpos($rel, 'wp-admin/') !== 0 && strpos($rel, 'wp-includes/') !== 0) { continue; }
        if ($checked >= $cap) { break; }
        $checked++;
        $file = $root . '/' . $rel;
        if (!is_file($file)) { $missing[] = $rel; continue; }
        $actual = @md5_file($file);
        if ($actual !== false && $actual !== $md5) { $modified[] = $rel; }
    }

    return [
        'scan'     => 'checksums',
        'status'   => 'ok',
        'version'  => $version,
        'checked'  => $checked,
        'modified' => $modified,
        'missing'  => $missing,
        'severity' => (!empty($modified) || !empty($missing)) ? 'high' : '',
    ];
}

/**
 * PURE-ish helper: recursively collect .php files under a directory, capped.
 * Uses SPL iterators; returns absolute paths.
 *
 * @return array<int,string>
 */
function wpultra_security_collect_php_files(string $dir, int $cap): array {
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
        $ext = strtolower($file->getExtension());
        if (in_array($ext, ['php', 'phtml', 'php5', 'php7', 'phar'], true)) {
            $out[] = $file->getPathname();
        }
    }
    return $out;
}

/**
 * SCAN: suspicious_code(). Scans plugin dir + uploads dir .php files for
 * backdoor patterns. ANY .php under uploads is flagged on its own. Capped and
 * size-guarded (skips files > ~2 MB).
 *
 * @return array
 */
function wpultra_security_scan_suspicious_code(): array {
    $cap = wpultra_security_scan_file_cap();
    $findings = [];

    $plugin_dir = defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : (rtrim(ABSPATH, '/\\') . '/wp-content/plugins');
    $upload = function_exists('wp_upload_dir') ? wp_upload_dir() : [];
    $uploads_dir = is_array($upload) ? (string) ($upload['basedir'] ?? '') : '';

    $scanned = 0;

    // Uploads FIRST — ANY .php here is itself suspicious, and uploads normally
    // holds few/no .php files. Scanning plugins first would let a big plugin
    // set consume the whole file cap and silently skip the highest-signal area.
    $uploads_php = wpultra_security_collect_php_files($uploads_dir, $cap);
    foreach ($uploads_php as $file) {
        if ($scanned >= $cap) { break; }
        $scanned++;
        $code = @filesize($file) > 2_000_000 ? '' : (string) @file_get_contents($file);
        $hits = $code !== '' ? wpultra_security_scan_content($code) : [];
        // A .php file in uploads is a high-severity finding regardless of content.
        array_unshift($hits, ['id' => 'php_in_uploads', 'severity' => 'high', 'label' => 'PHP file located under the uploads directory (should never contain executable code)', 'count' => 1]);
        $findings[] = ['file' => $file, 'area' => 'uploads', 'hits' => $hits, 'severity' => 'high'];
    }

    // Plugins with the remaining cap.
    foreach (wpultra_security_collect_php_files((string) $plugin_dir, $cap) as $file) {
        if ($scanned >= $cap) { break; }
        $scanned++;
        if (@filesize($file) > 2_000_000) { continue; }
        $code = (string) @file_get_contents($file);
        $hits = wpultra_security_scan_content($code);
        if ($hits) {
            $findings[] = ['file' => $file, 'area' => 'plugins', 'hits' => $hits, 'severity' => wpultra_security_highest_severity($hits)];
        }
    }

    return [
        'scan'     => 'suspicious-code',
        'status'   => 'ok',
        'scanned'  => $scanned,
        'capped'   => $scanned >= $cap,
        'findings' => $findings,
        'severity' => wpultra_security_highest_severity(array_map(static fn($f) => ['severity' => $f['severity']], $findings)),
    ];
}

/**
 * SCAN: recently_modified(). Lists .php files under plugins + uploads modified
 * within the last $days. Capped. Useful to spot a fresh injection.
 *
 * @return array
 */
function wpultra_security_scan_recently_modified(int $days = 7): array {
    $days = max(1, $days);
    $cap = wpultra_security_scan_file_cap();
    $cutoff = time() - ($days * 86400);

    // Uploads first — same cap-ordering rationale as the suspicious-code scan.
    $dirs = [];
    $upload = function_exists('wp_upload_dir') ? wp_upload_dir() : [];
    if (is_array($upload) && !empty($upload['basedir'])) { $dirs[] = (string) $upload['basedir']; }
    $dirs[] = defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : (rtrim(ABSPATH, '/\\') . '/wp-content/plugins');

    $files = [];
    $scanned = 0;
    foreach ($dirs as $d) {
        foreach (wpultra_security_collect_php_files((string) $d, $cap) as $file) {
            if ($scanned >= $cap) { break 2; }
            $scanned++;
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime >= $cutoff) {
                $files[] = ['file' => $file, 'modified' => gmdate('Y-m-d H:i:s', $mtime)];
            }
        }
    }

    return [
        'scan'     => 'recently-modified',
        'status'   => 'ok',
        'days'     => $days,
        'scanned'  => $scanned,
        'capped'   => $scanned >= $cap,
        'files'    => $files,
        'severity' => !empty($files) ? 'low' : '',
    ];
}

/**
 * SCAN dispatcher. Runs the requested scans (default all) and returns findings
 * grouped by scan plus a summary risk level.
 *
 * @param array $scans list of scan ids: checksums|suspicious-code|recently-modified
 * @param int   $days  lookback window for recently-modified
 * @return array{scans:array<int,array>, risk:string}
 */
function wpultra_security_scan(array $scans = [], int $days = 7): array {
    $known = ['checksums', 'suspicious-code', 'recently-modified'];
    if ($scans === []) { $scans = $known; }
    $scans = array_values(array_intersect($known, array_map('strval', $scans)));

    $out = [];
    foreach ($scans as $s) {
        switch ($s) {
            case 'checksums':         $out[] = wpultra_security_scan_core_checksums(); break;
            case 'suspicious-code':   $out[] = wpultra_security_scan_suspicious_code(); break;
            case 'recently-modified': $out[] = wpultra_security_scan_recently_modified($days); break;
        }
    }

    $risk = wpultra_security_highest_severity(array_map(static fn($r) => ['severity' => (string) ($r['severity'] ?? '')], $out));
    return ['scans' => $out, 'risk' => $risk === '' ? 'clean' : $risk];
}

/* =====================================================================
 * Always-on runtime — controller hooks wpultra_security_boot() in.
 * ===================================================================== */

/** Runtime: current failed-login count option key for an IP. */
function wpultra_security_login_key(string $ip): string {
    return 'wpultra_login_fail_' . md5($ip);
}

/** Runtime: best-effort client IP for login limiting. */
function wpultra_security_client_ip(): string {
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return $ip !== '' ? $ip : '0.0.0.0';
}

/**
 * Registers the always-on runtime hooks driven by the persisted option flags:
 *  - disable_xmlrpc  -> force xmlrpc_enabled false
 *  - hide_version    -> remove the generator meta
 *  - limit_login     -> count failed logins per IP and block over the threshold
 *
 * The controller calls this from the always-on bootstrap; this file only defines it.
 */
function wpultra_security_boot(): void {
    $state = wpultra_security_get_state();

    if (!empty($state['disable_xmlrpc']) && function_exists('add_filter')) {
        // xmlrpc_enabled only gates AUTHENTICATED methods — anonymous ones
        // (pingback, system.listMethods, demo.*) keep answering. Empty the
        // method table too so the endpoint is fully inert.
        add_filter('xmlrpc_enabled', '__return_false');
        add_filter('xmlrpc_methods', '__return_empty_array');
    }

    if (!empty($state['hide_version']) && function_exists('remove_action')) {
        remove_action('wp_head', 'wp_generator');
        if (function_exists('add_filter')) {
            add_filter('the_generator', '__return_empty_string');
        }
    }

    $limit = $state['limit_login'] ?? null;
    if (is_array($limit) && function_exists('add_action') && function_exists('add_filter')) {
        $max     = max(1, (int) ($limit['max_attempts'] ?? 5));
        $lockout = max(1, (int) ($limit['lockout_minutes'] ?? 15));

        // Before authentication: reject if the IP is currently locked out.
        add_filter('authenticate', function ($user) use ($max) {
            $ip  = wpultra_security_client_ip();
            $key = wpultra_security_login_key($ip);
            $count = (int) (function_exists('get_transient') ? get_transient($key) : 0);
            if ($count >= $max) {
                return wpultra_err('too_many_attempts', 'Too many failed login attempts. Try again later.');
            }
            return $user;
        }, 30);

        // On failed login: increment the per-IP counter with a lockout TTL.
        add_action('wp_login_failed', function () use ($lockout) {
            $ip  = wpultra_security_client_ip();
            $key = wpultra_security_login_key($ip);
            $count = (int) (function_exists('get_transient') ? get_transient($key) : 0);
            if (function_exists('set_transient')) {
                set_transient($key, $count + 1, $lockout * 60);
            }
        });

        // On success: clear the counter for that IP.
        add_action('wp_login', function () {
            $ip  = wpultra_security_client_ip();
            if (function_exists('delete_transient')) {
                delete_transient(wpultra_security_login_key($ip));
            }
        });
    }
}
