<?php
declare(strict_types=1);

if (!defined('ABSPATH')) { exit(); }

/** Collapse '.'/'..', normalize to forward slashes, strip trailing slash (keep root). Pure. */
function wpultra_normalize_absolute_path(string $path): string {
    $path = str_replace('\\', '/', $path);
    $is_unc = str_starts_with($path, '//');
    $segments = explode('/', $path);
    $out = [];
    foreach ($segments as $seg) {
        if ($seg === '' || $seg === '.') { continue; }
        if ($seg === '..') { array_pop($out); continue; }
        $out[] = $seg;
    }
    $prefix = '';
    if (preg_match('#^[A-Za-z]:#', $path)) { $prefix = ''; }       // windows drive kept as first segment
    elseif ($is_unc) { $prefix = '//'; }
    elseif (str_starts_with($path, '/')) { $prefix = '/'; }
    $joined = $prefix . implode('/', $out);
    return $joined === '' ? '/' : $joined;
}

/**
 * True if $path equals $dir or is nested under it. Pure.
 * Windows-style paths (drive letter / UNC) compare case-insensitively: NTFS is
 * case-insensitive and realpath() may report a different drive-letter case than
 * ABSPATH (C:\ vs c:/), which caused spurious path_outside_base rejections.
 * The check keys on path shape, not the running OS, so it stays pure/testable.
 */
function wpultra_path_is_within_directory(string $path, string $dir): bool {
    $p = wpultra_normalize_absolute_path($path);
    $d = wpultra_normalize_absolute_path($dir);
    $windows = preg_match('#^(?:[A-Za-z]:|//)#', $p) && preg_match('#^(?:[A-Za-z]:|//)#', $d);
    if ($windows) {
        return strcasecmp($p, $d) === 0 || strncasecmp($p, $d . '/', strlen($d) + 1) === 0;
    }
    return $p === $d || str_starts_with($p, $d . '/');
}

function wpultra_is_valid_identifier(string $name): bool {
    return (bool) preg_match('/^[A-Za-z0-9_]+$/', $name);
}

/** The plugin's own private CPTs — the generic content abilities must not touch these. */
function wpultra_reserved_post_types(): array {
    return ['wpultra_memory', 'wpultra_skill', 'wpultra_ability'];
}

/**
 * Return ['verb'=>UPPER, 'destructive'=>bool]. Pure.
 *
 * Allow-list approach: only genuinely read-only verbs (and INSERT, which only adds
 * rows) are non-destructive. Everything else — DELETE/UPDATE (even with a WHERE,
 * since `WHERE 1=1` is a trivial bypass), DDL (DROP/TRUNCATE/ALTER/RENAME/CREATE),
 * privilege changes (GRANT/REVOKE), CTEs that can wrap a DELETE (WITH …), and any
 * unrecognised verb — requires `confirm: true`.
 */
function wpultra_classify_query(string $sql): array {
    // Strip a leading block comment (/* ... */) or line comment so `/*x*/DELETE` can't hide the verb.
    $trimmed = preg_replace('#^\s*(?:/\*.*?\*/|--[^\n]*\n|\#[^\n]*\n)\s*#s', '', trim($sql));
    $trimmed = ltrim((string) $trimmed, "( \t\n\r");
    $verb = strtoupper(preg_split('/\s+/', $trimmed)[0] ?? '');
    $safe = ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN', 'INSERT'];
    $destructive = !in_array($verb, $safe, true);
    // A SELECT can still write to disk or lock rows — force confirmation for those.
    if (!$destructive && preg_match('/\b(INTO\s+(OUTFILE|DUMPFILE)|LOAD_FILE)\b/i', $sql)) {
        $destructive = true;
    }
    // INSERT is normally additive (only adds rows), but `INSERT … ON DUPLICATE KEY
    // UPDATE` overwrites existing rows — that is an UPDATE in disguise and must need
    // confirm:true like any other row mutation, not slip through as a "safe" INSERT.
    if (!$destructive && $verb === 'INSERT' && preg_match('/\bON\s+DUPLICATE\s+KEY\s+UPDATE\b/i', $sql)) {
        $destructive = true;
    }
    return ['verb' => $verb, 'destructive' => $destructive];
}

function wpultra_filesystem_base_dir(): string {
    return (string) apply_filters('wpultra_filesystem_base_dir', ABSPATH);
}

function wpultra_path_requires_sandbox(string $path): bool {
    // Trailing dots/spaces are stripped by some filesystems on open, so `shell.php.`
    // and `shell.php ` resolve to `shell.php` — strip them before matching.
    $name = strtolower(rtrim(basename($path), " ."));
    // Any extension the PHP handler (or a server config) commonly maps to executable code.
    if (preg_match('/\.(php\d*|phtml|phps|pht|phar|ini)$/', $name)) { return true; }
    return in_array($name, ['.htaccess', 'web.config'], true);
}

/**
 * Resolve a path inside the jail. Returns absolute path string or WP_Error.
 * Relative paths resolve against the base dir. Symlink final targets are rejected.
 */
function wpultra_resolve_path(string $path, bool $must_exist = false) {
    $path = trim($path);
    if ($path === '') { return wpultra_err('missing_path', 'Path is required.'); }
    // Reject null bytes / control chars: they bypass extension checks and break FS calls.
    if (strpbrk($path, "\0") !== false || preg_match('/[\x00-\x1f]/', $path)) {
        return wpultra_err('invalid_path', 'Path contains illegal control characters.');
    }
    // Reject NTFS alternate-data-stream syntax (`shell.php::$DATA`) and any stray colon that
    // isn't the Windows drive-letter separator — otherwise the ADS suffix hides the .php
    // extension from the sandbox check and writes an executable file to the web root.
    $after_drive = preg_replace('#^[A-Za-z]:#', '', $path);
    if (strpos((string) $after_drive, ':') !== false) {
        return wpultra_err('invalid_path', 'Path contains an illegal colon (NTFS stream or drive syntax).');
    }

    $base = wpultra_filesystem_base_dir();
    $is_abs = (bool) preg_match('#^([A-Za-z]:[\\\\/]|[\\\\/])#', $path);
    $candidate = $is_abs ? $path : rtrim($base, '/\\') . '/' . $path;

    // Resolve parent via realpath (handles symlinks/.. in the existing portion); append missing tail.
    $real = realpath($candidate);
    if ($real === false) {
        if ($must_exist) { return wpultra_err('path_not_found', "Path does not exist: $candidate"); }
        $parent = realpath(dirname($candidate));
        if ($parent === false) {
            $resolved = wpultra_normalize_absolute_path($candidate);
        } else {
            $resolved = wpultra_normalize_absolute_path($parent . '/' . basename($candidate));
        }
    } else {
        $resolved = wpultra_normalize_absolute_path($real);
    }

    if (!wpultra_path_is_within_directory($resolved, $base)) {
        return wpultra_err('path_outside_base', "Path is outside the allowed base directory: $resolved");
    }
    if (is_link($resolved)) {
        return wpultra_err('symlink_rejected', "Refusing to operate on a symlink: $resolved");
    }
    if (wpultra_path_requires_sandbox($resolved)) {
        $sandbox = wpultra_normalize_absolute_path(WPULTRA_SANDBOX_DIR);
        if (!wpultra_path_is_within_directory($resolved, $sandbox)) {
            return wpultra_err('sandbox_required', "Executable files must be written under the sandbox dir: $sandbox");
        }
    }
    return $resolved;
}

/** Centralized safe-mode check: true when the sandbox crashed and code-exec should be suspended. */
function wpultra_safe_mode_active(): bool {
    return function_exists('wpultra_sandbox_crashed') && wpultra_sandbox_crashed();
}

/**
 * Pure: given a WP-CLI argv array, return the dangerous command it invokes ('' if none).
 * "Dangerous" = arbitrary PHP / SQL / shell execution that bypasses this plugin's own guards
 * (the execute-php sandbox, the SQL destructive-verb gate). Callers require explicit opt-in.
 */
function wpultra_wp_cli_unsafe_command(array $args): string {
    $tokens = [];
    foreach ($args as $a) {
        $a = (string) $a;
        if ($a === '') { continue; }
        if ($a[0] === '-') {
            // WP-CLI GLOBAL flags that run arbitrary PHP BEFORE the command itself —
            // `wp --exec="<php>" option list` executes the PHP yet classifies as the
            // innocuous `option list`, bypassing the command-name gate entirely.
            // Flag them so allow_unsafe (explicit opt-in) is still required.
            $flag = strtolower($a);
            if (str_starts_with($flag, '--exec'))    { return '--exec'; }
            if (str_starts_with($flag, '--require'))  { return '--require'; }
            continue; // other flags/options are not command-name relevant
        }
        $tokens[] = strtolower($a);
        if (count($tokens) >= 2) { break; }
    }
    $cmd = $tokens[0] ?? '';
    $sub = $tokens[1] ?? '';
    // Single-word commands that run arbitrary code / open a shell.
    if (in_array($cmd, ['eval', 'eval-file', 'shell', 'server'], true)) { return $cmd; }
    // Two-word commands that run raw SQL or rewrite wp-config.
    if (in_array("$cmd $sub", ['db query', 'db cli', 'db import', 'config set', 'config edit'], true)) { return "$cmd $sub"; }
    return '';
}

/** Max byte length accepted by execute-php (filterable). Guards against pathological payloads. */
function wpultra_execute_php_max_bytes(): int {
    $n = (int) (function_exists('apply_filters') ? apply_filters('wpultra_execute_php_max_bytes', 200000) : 200000);
    return $n > 0 ? $n : 200000;
}

function wpultra_is_enabled(): bool {
    if (get_option('wpultra_enabled') !== '1') { return false; }
    $locked = (string) get_option('wpultra_domain', '');
    if ($locked === '') { return true; }
    $current = wp_parse_url(home_url(), PHP_URL_HOST);
    return $locked === $current;
}

function wpultra_current_user_can_manage(): bool {
    return is_multisite() ? is_super_admin() : current_user_can('manage_options');
}

function wpultra_permission_callback(): bool {
    if (!wpultra_is_enabled()) { return false; }
    // When the access engine is loaded, use its relaxed baseline (admin, OR a
    // non-admin whose role holds at least one grant). The per-ability gate on
    // wp_before_execute_ability then enforces the fine-grained policy + rate
    // limits. With an empty policy this is identical to admin-only.
    if (function_exists('wpultra_access_baseline_user')) { return wpultra_access_baseline_user(); }
    return wpultra_current_user_can_manage();
}

/**
 * Low-level: queue one entry for the audit ring buffer (an option) and the
 * stats tally. Entries accumulate in a request-scoped buffer and are persisted
 * ONCE per request by wpultra_audit_flush() on shutdown — a playbook chaining
 * 20 abilities does 2 option writes, not 40, and the read-modify-write race
 * window between concurrent MCP requests shrinks to a single write. No frame
 * bookkeeping — used by both wpultra_audit_log() and the central hook. No-ops
 * outside WordPress (unit tests). Best-effort.
 */
function wpultra_audit_write(string $action, string $summary, bool $ok = true): void {
    if (!function_exists('get_option') || !function_exists('update_option')) { return; }
    if (!isset($GLOBALS['__wpultra_audit_buffer'])) { $GLOBALS['__wpultra_audit_buffer'] = []; }
    $GLOBALS['__wpultra_audit_buffer'][] = [
        'ts'      => function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s'),
        'user'    => function_exists('get_current_user_id') ? (int) get_current_user_id() : 0,
        'action'  => $action,
        'summary' => function_exists('mb_substr') ? mb_substr($summary, 0, 300) : substr($summary, 0, 300),
        'ok'      => $ok,
    ];
    // Central shutdown (priority 5) may still buffer failure frames — flush after it.
    if (empty($GLOBALS['__wpultra_audit_flush_hooked']) && function_exists('add_action')) {
        $GLOBALS['__wpultra_audit_flush_hooked'] = true;
        add_action('shutdown', 'wpultra_audit_flush', 20);
    }
}

/**
 * Persist everything buffered by wpultra_audit_write(): merge into the
 * wpultra_audit ring (one read + one write) and fold each outcome into the
 * wpultra_ability_stats tally (one read + one write). Runs on shutdown; also
 * called by in-request readers (activity/stats/brain) so an ability chain that
 * logs then reads its own trail sees a complete log. Idempotent — the buffer
 * empties before writing, so re-entry from an update_option hook is a no-op.
 */
function wpultra_audit_flush(): void {
    $buf = $GLOBALS['__wpultra_audit_buffer'] ?? [];
    if ($buf === [] || !function_exists('get_option') || !function_exists('update_option')) { return; }
    $GLOBALS['__wpultra_audit_buffer'] = [];
    $log = get_option('wpultra_audit', []);
    if (!is_array($log)) { $log = []; }
    foreach ($buf as $entry) { $log[] = $entry; }
    $max = (int) (function_exists('apply_filters') ? apply_filters('wpultra_audit_max', 200) : 200);
    if ($max < 1) { $max = 200; }
    if (count($log) > $max) { $log = array_slice($log, -$max); }
    update_option('wpultra_audit', $log, false);
    // Feed the self-improvement stats tally so the AI can see its own failure patterns.
    if (function_exists('wpultra_stats_apply')) {
        $stats = get_option('wpultra_ability_stats', []);
        if (!is_array($stats)) { $stats = []; }
        foreach ($buf as $entry) {
            $stats = wpultra_stats_apply($stats, (string) $entry['action'], (bool) $entry['ok']);
            if (empty($entry['ok']) && $entry['summary'] !== '') {
                $err = (string) $entry['summary'];
                $stats[(string) $entry['action']]['last_error'] = function_exists('mb_substr') ? mb_substr($err, 0, 200) : substr($err, 0, 200);
            }
        }
        update_option('wpultra_ability_stats', $stats, false);
    }
}

/**
 * Append a rich entry to the privileged-action audit log. Also marks the
 * in-flight central-audit frame as already-logged, so the central hook
 * (wpultra_audit_central_*) does NOT write a second, generic entry for the
 * same call — abilities that self-log keep their detailed summary; those that
 * don't still get covered by the central fallback.
 */
function wpultra_audit_log(string $action, string $summary, bool $ok = true): void {
    if (!empty($GLOBALS['__wpultra_audit_frames'])) {
        $top = count($GLOBALS['__wpultra_audit_frames']) - 1;
        $GLOBALS['__wpultra_audit_frames'][$top]['logged'] = true;
    }
    wpultra_audit_write($action, $summary, $ok);
}

/** Pure: should a non-readonly ability be centrally audited? Skips reads + non-wpultra abilities. */
function wpultra_audit_should_log(string $ability_name): bool {
    if (strncmp($ability_name, 'wpultra/', 8) !== 0) { return false; }
    if (!function_exists('wp_get_ability')) { return true; } // no registry (tests) — err toward logging
    $ability = wp_get_ability($ability_name);
    if (!$ability) { return false; }
    $ann = (array) $ability->get_meta_item('annotations', []);
    return empty($ann['readonly']); // readonly reads don't need an audit trail
}

/**
 * Central audit — guarantees every non-readonly ability execution is recorded
 * exactly once with an accurate ok/fail, covering the ~51 write abilities that
 * never call wpultra_audit_log() themselves.
 *
 * Core fires wp_before_execute_ability ALWAYS but wp_after_execute_ability only
 * on success (a WP_Error from do_execute() returns before it). So we keep a
 * frame stack: before → push; the ability's own wpultra_audit_log() marks its
 * frame logged; after (success) → pop and, if still unlogged, write a generic
 * ok; shutdown → any frame left is a failed/fatal call, written as a generic
 * failure. One entry per call, no duplicates, complete coverage.
 */
function wpultra_audit_central_before(string $ability_name, $input = null): void {
    if (!isset($GLOBALS['__wpultra_audit_frames'])) { $GLOBALS['__wpultra_audit_frames'] = []; }
    $GLOBALS['__wpultra_audit_frames'][] = ['name' => $ability_name, 'logged' => false];
}

function wpultra_audit_central_after(string $ability_name, $input = null, $result = null): void {
    if (empty($GLOBALS['__wpultra_audit_frames'])) { return; }
    $frame = array_pop($GLOBALS['__wpultra_audit_frames']);
    if (empty($frame['logged']) && wpultra_audit_should_log($ability_name)) {
        wpultra_audit_write(substr($ability_name, 8), 'executed', true);
    }
}

/** Shutdown: any frame still open never reached the after-hook — i.e. it failed. */
function wpultra_audit_central_shutdown(): void {
    if (empty($GLOBALS['__wpultra_audit_frames'])) { return; }
    foreach ($GLOBALS['__wpultra_audit_frames'] as $frame) {
        if (empty($frame['logged']) && wpultra_audit_should_log((string) $frame['name'])) {
            wpultra_audit_write(substr((string) $frame['name'], 8), 'failed or interrupted', false);
        }
    }
    $GLOBALS['__wpultra_audit_frames'] = [];
}

/**
 * Public (unauthenticated) REST endpoints — /chat, /track, /jserror — can be
 * switched off individually via option wpultra_public_endpoints_disabled
 * (array of keys). All enabled by default (they are rate-limited and
 * non-mutating); site owners who don't use the chatbot or marketing beacons
 * can close them entirely (e.g. via option-set). Registration is skipped, so
 * a disabled endpoint 404s instead of merely refusing.
 */
function wpultra_public_endpoint_enabled(string $key): bool {
    if (!function_exists('get_option')) { return true; }
    $disabled = get_option('wpultra_public_endpoints_disabled', []);
    return !in_array($key, array_map('strval', (array) $disabled), true);
}

/**
 * Per-site random token appended to the snapshot/backup directory names.
 * Those dirs live under uploads (web-reachable); .htaccess/web.config deny
 * rules cover Apache/IIS but are ignored by nginx, where an unguessable path
 * is the only protection a plugin can provide. Generated once, then stable.
 */
function wpultra_secret_dir_token(): string {
    if (!function_exists('get_option') || !function_exists('update_option')) { return ''; }
    $t = (string) get_option('wpultra_dir_token', '');
    if (!preg_match('/^[a-f0-9]{16}$/', $t)) {
        try { $t = substr(bin2hex(random_bytes(16)), 0, 16); } catch (\Throwable $e) { $t = substr(md5(uniqid((string) wp_rand(), true)), 0, 16); }
        update_option('wpultra_dir_token', $t, false);
    }
    return $t;
}

/**
 * Guard a destructive action behind `confirm: true`. Returns a WP_Error to
 * return immediately when the caller hasn't confirmed, or null to proceed.
 * Collapses the three confirm idioms (`!== true`, `empty()`, bare `?? false`)
 * that grew across the ability set into one call, so a missing gate reads as a
 * visible omission rather than an invisible one.
 *
 * Usage: `if ($e = wpultra_require_confirm($input, 'Deleting X is permanent.')) { return $e; }`
 */
function wpultra_require_confirm(array $input, string $reason, string $code = 'confirm_required'): ?WP_Error {
    if (($input['confirm'] ?? false) === true) { return null; }
    $reason = rtrim($reason);
    if (stripos($reason, 'confirm') === false) { $reason .= ' Re-run with confirm: true.'; }
    return wpultra_err($code, $reason);
}

/**
 * Pure: apply optional limit/offset pagination to a list. Returns
 * [pageItems, meta] where meta = {total, returned, offset, limit}. With no
 * limit/offset in $input the default page size applies — set it to each
 * caller's existing cap so behaviour is unchanged for lists at or below it.
 */
function wpultra_paginate(array $items, array $input, int $default_limit = 100): array {
    $items  = array_values($items);
    $total  = count($items);
    $offset = max(0, (int) ($input['offset'] ?? 0));
    $limit  = (int) ($input['limit'] ?? $default_limit);
    if ($limit <= 0) { $limit = $default_limit; }
    $page = array_slice($items, $offset, $limit);
    return [$page, ['total' => $total, 'returned' => count($page), 'offset' => $offset, 'limit' => $limit]];
}

/**
 * Output schema for a multi-action "manager" ability whose returned fields
 * legitimately vary by action. Declares success + any always-present fields,
 * documents the polymorphism, and keeps additionalProperties open so every
 * action's payload validates. Better than a bare {success}: the client is told
 * the shape is action-dependent (see the ability description) rather than
 * inferring a fixed contract that doesn't exist.
 */
function wpultra_manager_output_schema(array $known = []): array {
    return [
        'type'        => 'object',
        'description' => 'Fields vary by action; see the ability description for the per-action response shape.',
        'properties'  => array_merge(['success' => ['type' => 'boolean']], $known),
        'required'    => ['success'],
        'additionalProperties' => true,
    ];
}

/**
 * Shared input-schema properties for the design-token "brief" — the colors /
 * fonts / sizes / confirm block that the elementor, bricks, and gutenberg
 * apply-design-tokens abilities all accept. One definition keeps the trio in
 * lock-step instead of drifting across three hand-maintained copies.
 */
function wpultra_design_brief_schema(): array {
    return [
        'colors' => ['type' => 'array', 'items' => [
            'type' => 'object',
            'properties' => ['role' => ['type' => 'string'], 'title' => ['type' => 'string'], 'hex' => ['type' => 'string']],
            'required' => ['title', 'hex'], 'additionalProperties' => false,
        ]],
        'fonts' => ['type' => 'array', 'items' => [
            'type' => 'object',
            'properties' => ['role' => ['type' => 'string'], 'title' => ['type' => 'string'], 'family' => ['type' => 'string']],
            'required' => ['title', 'family'], 'additionalProperties' => false,
        ]],
        'sizes' => ['type' => 'array', 'items' => [
            'type' => 'object',
            'properties' => ['role' => ['type' => 'string'], 'title' => ['type' => 'string'], 'size' => ['type' => 'number'], 'unit' => ['type' => 'string']],
            'required' => ['title', 'size'], 'additionalProperties' => false,
        ]],
        'confirm' => ['type' => 'boolean'],
    ];
}

/** A permissive schema node that accepts any JSON type — documents a field's
 *  existence without constraining its type (safe for dynamic/mixed values). */
function wpultra_schema_any(): array {
    return ['type' => ['string', 'number', 'integer', 'boolean', 'array', 'object', 'null']];
}

/** The limit/offset input-schema fragment for paginated list abilities. */
function wpultra_pagination_schema(): array {
    return [
        'limit'  => ['type' => 'integer', 'description' => 'Max items to return (optional).'],
        'offset' => ['type' => 'integer', 'description' => 'Items to skip for paging (optional).'],
    ];
}

/**
 * Record a swallowed exception without changing control flow. The crash-isolation
 * catches around subsystem boots intentionally suppress throwables so one broken
 * feature can't white-screen the site — but discarding $e entirely made a failed
 * boot indistinguishable from one that never ran. Route those catches here: it
 * error_log()s under WP_DEBUG and best-effort appends to the wpultra_error_log
 * ring when that engine is loaded. Never throws.
 */
function wpultra_log_throwable(\Throwable $e, string $context = ''): void {
    $msg = ($context !== '' ? "[$context] " : '') . $e->getMessage()
        . ' @ ' . $e->getFile() . ':' . $e->getLine();
    if (defined('WP_DEBUG') && WP_DEBUG && function_exists('error_log')) {
        error_log('WP-Ultra-MCP: ' . $msg);
    }
    if (function_exists('wpultra_errors_load_ring') && function_exists('wpultra_errors_ring_push') && function_exists('wpultra_errors_save_ring')) {
        try {
            $entry = [
                'message' => $context !== '' ? "$context: " . $e->getMessage() : $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => (int) $e->getLine(),
                'ts'      => function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s'),
                'source'  => 'caught',
            ];
            wpultra_errors_save_ring(wpultra_errors_ring_push(wpultra_errors_load_ring(), $entry));
        } catch (\Throwable $ignored) { /* logging must never itself break boot */ }
    }
}

function wpultra_ok(array $fields): array { return array_merge(['success' => true], $fields); }

function wpultra_err(string $code, string $message, $data = ''): WP_Error {
    return new WP_Error($code, $message, $data);
}
