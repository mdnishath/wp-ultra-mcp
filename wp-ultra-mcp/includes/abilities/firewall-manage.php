<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

// Defensively load the engine.
if (!function_exists('wpultra_fw_evaluate')) {
    $wpultra_fw_engine = WPULTRA_DIR . 'includes/system/firewall.php';
    if (is_readable($wpultra_fw_engine)) { require_once $wpultra_fw_engine; }
}

wp_register_ability('wpultra/firewall-manage', [
    'label'       => __('Firewall (WAF-lite) Manage', 'wp-ultra-mcp'),
    'description' => __(
        'Manage the application-layer PHP firewall (WAF-lite): rate limiting, bad-bot blocking, IP / CIDR-range / country blocking, and high-signal request-pattern rules. '
        . 'IMPORTANT CAVEAT: this is an APPLICATION-LAYER firewall that runs inside WordPress on plugins_loaded — it can only inspect and block requests that reach PHP. '
        . 'It is NOT a network firewall or a real WAF: it cannot filter traffic answered by a CDN / page cache before PHP boots, the client IP is REMOTE_ADDR only (proxy headers are spoofable), and country is read from edge/CDN headers (Cloudflare CF-IPCountry etc.). Treat it as defence-in-depth. '
        . 'THREE MODES: off (default, nothing runs), log (verdicts recorded but nothing is blocked — the recommended first mode after enabling), block (enforce). Recommended flow: set mode=log, review the log for a day, then set mode=block. '
        . 'EVALUATION ORDER (first block wins): ip_allow (always bypass) -> ip_block -> bad_bots -> countries_block -> request rules -> rate limit. '
        . 'ACTIONS (pass action=...): '
        . 'status — current config + whether enforcement is active. '
        . 'config {config:{...}} — merge + normalize a config patch (mode, rate_limit:{enabled,per_minute}, ip_allow[], ip_block[], bad_bots:{enabled,extra[]}, countries_block[] (ISO2), rules:{enabled,log_only}); switching to log/block auto-adds YOUR current IP to ip_allow and returns a prominent lockout-safety note. '
        . 'block-ip {ip} / unblock-ip {ip} — manage ip_block (plain IP or CIDR). allow-ip {ip} / disallow-ip {ip} — manage the always-bypass ip_allow. '
        . 'test-request {path?,query?,user_agent?,ip?,country?,logged_in?} — DRY RUN: evaluate the current config against a synthetic request and return the verdict WITHOUT enforcing (the key trust-building tool). '
        . 'log — recent verdicts (newest first). clear-log — wipe the verdict log. '
        . 'LOCKOUT SAFETY: the evaluator hard-bypasses BEFORE any check for WP-CLI, cron, is_admin(), loopback IPs (127.0.0.1 / ::1), and any /wp-admin, /wp-login.php, /wp-json/mcp or /wp-json/wpultra path; logged-in administrators (manage_options) and allowlisted IPs always bypass; rate limiting never applies to logged-in users; country blocking fails open on unknown country; and the whole evaluator runs inside try/catch and FAILS OPEN on any internal error. So the site owner and this AI can never be permanently locked out and disabling the firewall is always reachable. '
        . 'Request rules are LOG-ONLY by default even in block mode (rules.log_only) because signature false positives are risky. '
        . 'Rate-limit defaults: per_minute 120 (clamped 10..1000). Bad-bot defaults target known scanners/tools (sqlmap, nikto, wpscan...) and NEVER match Googlebot / Bingbot / semrush / ahrefs. '
        . 'Example: {action:"config", config:{mode:"log", rate_limit:{enabled:true, per_minute:90}, bad_bots:{enabled:true}}}. '
        . 'Example: {action:"block-ip", ip:"203.0.113.0/24"}. '
        . 'Example: {action:"test-request", path:"/index.php", user_agent:"sqlmap/1.5"}.',
        'wp-ultra-mcp'
    ),
    'category'      => 'diagnostics',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['status', 'config', 'block-ip', 'unblock-ip', 'allow-ip', 'disallow-ip', 'test-request', 'log', 'clear-log'],
            ],
            'config'     => ['type' => 'object'],
            'ip'         => ['type' => 'string'],
            'path'       => ['type' => 'string'],
            'query'      => ['type' => 'string'],
            'user_agent' => ['type' => 'string'],
            'country'    => ['type' => 'string'],
            'logged_in'  => ['type' => 'boolean'],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_firewall_manage_ability',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

/** @return array|WP_Error */
function wpultra_firewall_manage_ability(array $input) {
    if (!function_exists('wpultra_fw_evaluate')) {
        return wpultra_err('engine_missing', 'Firewall engine (includes/system/firewall.php) is not loaded.');
    }

    $action = (string) ($input['action'] ?? '');

    switch ($action) {
        case 'status':        return wpultra_fw_action_status();
        case 'config':        return wpultra_fw_action_config($input);
        case 'block-ip':      return wpultra_fw_action_ip_list($input, 'ip_block', true);
        case 'unblock-ip':    return wpultra_fw_action_ip_list($input, 'ip_block', false);
        case 'allow-ip':      return wpultra_fw_action_ip_list($input, 'ip_allow', true);
        case 'disallow-ip':   return wpultra_fw_action_ip_list($input, 'ip_allow', false);
        case 'test-request':  return wpultra_fw_action_test_request($input);
        case 'log':           return wpultra_fw_action_log();
        case 'clear-log':     return wpultra_fw_action_clear_log();
    }

    return wpultra_err('unknown_action', "Unknown action '$action'.");
}

/** status: current normalized config + enforcement summary. */
function wpultra_fw_action_status() {
    $cfg = wpultra_firewall_get_config();
    return wpultra_ok([
        'config'      => $cfg,
        'mode'        => $cfg['mode'],
        'enforcing'   => $cfg['mode'] === 'block',
        'logging'     => in_array($cfg['mode'], ['log', 'block'], true),
        'blocked_ips' => count($cfg['ip_block']),
        'allowed_ips' => count($cfg['ip_allow']),
    ]);
}

/** config: merge a patch, normalize, persist; auto-allowlist caller when going live. */
function wpultra_fw_action_config(array $input) {
    $patch = is_array($input['config'] ?? null) ? $input['config'] : [];
    if ($patch === []) {
        return wpultra_err('no_config', 'Provide a config object to merge.');
    }

    $current = wpultra_firewall_get_config();
    // Shallow-merge the patch over the current config so callers can send just
    // one section without wiping the rest; normalize does the deep validation.
    $merged = array_replace($current, $patch);

    $note = '';
    $goingLive = in_array((string) ($merged['mode'] ?? 'off'), ['log', 'block'], true);
    if ($goingLive) {
        // Lockout safety net: auto-add the caller's current IP to the allowlist.
        $ip = wpultra_firewall_client_ip();
        $allow = isset($merged['ip_allow']) && is_array($merged['ip_allow']) ? $merged['ip_allow'] : [];
        if ($ip !== '' && wpultra_fw_valid_ip_or_cidr($ip) && !in_array($ip, $allow, true)) {
            $allow[] = $ip;
        }
        $merged['ip_allow'] = $allow;
        $note = 'LOCKOUT SAFETY: your current IP (' . ($ip !== '' ? $ip : 'unknown') . ') was added to the allowlist. '
            . 'The evaluator also hard-bypasses WP-CLI, cron, is_admin(), loopback IPs and /wp-admin, /wp-login.php, /wp-json/mcp and /wp-json/wpultra paths; '
            . 'logged-in admins always bypass; and it fails open on any internal error. You and this AI can never be permanently locked out, and disabling the firewall is always reachable. '
            . 'Tip: start in mode=log, review the log, then switch to mode=block.';
    }

    $saved = wpultra_firewall_save_config($merged);
    wpultra_audit_log('firewall-manage', 'config mode=' . $saved['mode'], true);

    $out = ['config' => $saved, 'mode' => $saved['mode']];
    if ($note !== '') { $out['safety_note'] = $note; }
    return wpultra_ok($out);
}

/** block-ip/unblock-ip/allow-ip/disallow-ip: manage an IP/CIDR list. */
function wpultra_fw_action_ip_list(array $input, string $listKey, bool $add) {
    $ip = trim((string) ($input['ip'] ?? ''));
    if ($ip === '') { return wpultra_err('no_ip', 'Provide an ip (plain IP or CIDR).'); }
    if (!wpultra_fw_valid_ip_or_cidr($ip)) {
        return wpultra_err('invalid_ip', "Not a valid IP or CIDR: $ip");
    }

    // Lockout-safety: never let the operator blocklist loopback or their own
    // current IP (both are also hard-bypassed at runtime, but refusing here
    // keeps the blocklist honest and avoids a confusing no-op entry).
    if ($add && $listKey === 'ip_block') {
        $caller = function_exists('wpultra_firewall_client_ip') ? wpultra_firewall_client_ip() : (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if ($ip === '127.0.0.1' || $ip === '::1' || ($caller !== '' && $ip === $caller)) {
            return wpultra_err('lockout_risk', "Refusing to blocklist $ip — it is loopback or your current IP. Blocking it risks locking you out (use allow-ip instead, or block a different address).");
        }
    }

    $cfg  = wpultra_firewall_get_config();
    $list = is_array($cfg[$listKey] ?? null) ? $cfg[$listKey] : [];

    if ($add) {
        if (!in_array($ip, $list, true)) { $list[] = $ip; }
    } else {
        $list = array_values(array_filter($list, static fn($x) => (string) $x !== $ip));
    }
    $cfg[$listKey] = $list;

    $saved = wpultra_firewall_save_config($cfg);
    wpultra_audit_log('firewall-manage', ($add ? 'add' : 'remove') . " $listKey $ip", true);

    return wpultra_ok([$listKey => $saved[$listKey]]);
}

/** test-request: DRY-RUN the evaluator against the current config. No enforcement. */
function wpultra_fw_action_test_request(array $input) {
    $cfg = wpultra_firewall_get_config();
    $ctx = [
        'ip'        => trim((string) ($input['ip'] ?? '')),
        'ua'        => (string) ($input['user_agent'] ?? ''),
        'path'      => (string) ($input['path'] ?? '/'),
        'query'     => (string) ($input['query'] ?? ''),
        'logged_in' => (bool) ($input['logged_in'] ?? false),
        'country'   => strtoupper(trim((string) ($input['country'] ?? ''))),
    ];

    // Note the hard path bypass (dry run reports it so the operator understands
    // why a /wp-admin probe would never actually be blocked).
    $path_bypassed = wpultra_fw_bypass_path($ctx['path']);

    // Rate limiter not evaluated live in a dry run — pass 0 (well under the floor).
    $verdict = wpultra_fw_evaluate($cfg, $ctx, 0);

    return wpultra_ok([
        'request'       => $ctx,
        'mode'          => $cfg['mode'],
        'verdict'       => $verdict,
        'path_bypassed' => $path_bypassed,
        'note'          => 'Dry run only — nothing was blocked. Rate limit treated as 0 (not evaluated live). '
            . ($path_bypassed ? 'This path is on the hard-bypass list, so it would NEVER be blocked in production. ' : '')
            . ($cfg['mode'] !== 'block' ? 'Current mode is "' . $cfg['mode'] . '": even a block verdict would not enforce until mode=block.' : ''),
    ]);
}

/** log: recent verdicts (newest first). */
function wpultra_fw_action_log() {
    $log = wpultra_firewall_log_read();
    return wpultra_ok(['log' => $log, 'count' => count($log)]);
}

/** clear-log: wipe the verdict log. */
function wpultra_fw_action_clear_log() {
    wpultra_firewall_log_clear();
    wpultra_audit_log('firewall-manage', 'clear-log', true);
    return wpultra_ok(['log' => [], 'count' => 0]);
}
