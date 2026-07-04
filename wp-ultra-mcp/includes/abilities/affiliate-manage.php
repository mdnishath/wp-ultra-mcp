<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

// Defensive engine require — this ability must work regardless of load order
// (mirrors woo-bulk-edit's pattern for its engine file).
if (!function_exists('wpultra_aff_valid_code') && defined('WPULTRA_DIR') && is_readable(WPULTRA_DIR . 'includes/marketing/affiliates.php')) {
    require_once WPULTRA_DIR . 'includes/marketing/affiliates.php';
}

wp_register_ability('wpultra/affiliate-manage', [
    'label'       => __('Affiliates: Manage Referral Program', 'wp-ultra-mcp'),
    'description' => __(
        'Full affiliate / referral program manager: affiliates get a referral link (?ref=CODE), visits set a 30-day cookie, '
        . 'and WooCommerce orders placed while the cookie is set become commission referrals. '
        . 'Referral lifecycle: pending -> approved | rejected, approved -> paid (mark-paid). Self-referrals (billing email = affiliate email) are skipped automatically. '
        . 'Actions: '
        . 'create {name, email, code?, rate_pct?} — new affiliate; code auto-generated from the name plus a random suffix when omitted (shape [a-z0-9-]{3,32}, always unique); rate_pct defaults to the program default. '
        . 'update {id|code, name?, email?, rate_pct?, status?(active|disabled), new_code?} — edit an affiliate; disabled affiliates stop earning. '
        . 'get {id|code} / list {limit? default 50 cap 200} — inspect affiliates (includes email, clicks, code). '
        . 'delete {id|code, confirm:true, force?} — remove an affiliate; BLOCKED while referrals exist unless force:true (then referrals are kept orphaned with a note). '
        . 'link {id|code, url?} — build the referral URL (default home URL), e.g. {action:"link", code:"john-doe-a1b2"} -> https://site.com/?ref=john-doe-a1b2. '
        . 'referrals {affiliate?(id|code), status?(pending|approved|rejected|paid), limit? default 50 cap 200} — list referrals newest first. '
        . 'approve / reject {referral_id or ids[]} — pending referrals only. '
        . 'mark-paid {referral_id, or ids[] + confirm:true} — approved referrals only; this is the payout bookkeeping step. '
        . 'report — per-affiliate payout rollup (order totals + commission per status: pending/approved/paid, plus rejected totals and referral counts, grand totals, and the current config). Emails are NOT exposed in report mode. '
        . 'config {enable?, default_rate?, cookie_days?} — turn the front-end tracker on/off and tune defaults (reversible, no confirm needed). '
        . 'Examples: {action:"create", name:"John Doe", email:"john@x.com", rate_pct:15} then {action:"link", code:"john-doe-a1b2"}; '
        . '{action:"referrals", status:"pending"} then {action:"approve", ids:[12,13]} then {action:"mark-paid", ids:[12,13], confirm:true}; '
        . '{action:"config", enable:true, default_rate:10, cookie_days:30}.',
        'wp-ultra-mcp'
    ),
    'category'    => 'marketing',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['create', 'update', 'get', 'list', 'delete', 'link', 'referrals',
                           'approve', 'reject', 'mark-paid', 'report', 'config'],
            ],
            'id'           => ['type' => 'integer'],
            'code'         => ['type' => 'string'],
            'name'         => ['type' => 'string'],
            'email'        => ['type' => 'string'],
            'rate_pct'     => ['type' => 'number'],
            'status'       => ['type' => 'string'],
            'new_code'     => ['type' => 'string'],
            'url'          => ['type' => 'string'],
            'affiliate'    => ['type' => 'string'],
            'referral_id'  => ['type' => 'integer'],
            'ids'          => ['type' => 'array', 'items' => ['type' => 'integer']],
            'limit'        => ['type' => 'integer'],
            'force'        => ['type' => 'boolean'],
            'confirm'      => ['type' => 'boolean'],
            'enable'       => ['type' => 'boolean'],
            'default_rate' => ['type' => 'number'],
            'cookie_days'  => ['type' => 'integer'],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'    => ['type' => 'boolean'],
            'action'     => ['type' => 'string'],
            'affiliate'  => ['type' => 'object'],
            'affiliates' => ['type' => 'array'],
            'referrals'  => ['type' => 'array'],
            'results'    => ['type' => 'array'],
            'report'     => ['type' => 'object'],
            'config'     => ['type' => 'object'],
            'url'        => ['type' => 'string'],
            'note'       => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_affiliate_manage_ability',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

/** @return array|WP_Error */
function wpultra_affiliate_manage_ability(array $input) {
    if (!function_exists('wpultra_aff_valid_code')) {
        return wpultra_err('affiliate_engine_missing', 'The affiliates engine (includes/marketing/affiliates.php) is not loaded.');
    }
    // The CPTs normally register on init via wpultra_affiliates_boot(); make
    // sure they exist even if the controller has not booted the engine yet.
    wpultra_aff_register_cpts();

    $action = (string) ($input['action'] ?? '');
    switch ($action) {
        case 'create':    return wpultra_aff_action_create($input);
        case 'update':    return wpultra_aff_action_update($input);
        case 'get':       return wpultra_aff_action_get($input);
        case 'list':      return wpultra_aff_action_list($input);
        case 'delete':    return wpultra_aff_action_delete($input);
        case 'link':      return wpultra_aff_action_link($input);
        case 'referrals': return wpultra_aff_action_referrals($input);
        case 'approve':   return wpultra_aff_action_transition($input, 'approved');
        case 'reject':    return wpultra_aff_action_transition($input, 'rejected');
        case 'mark-paid': return wpultra_aff_action_transition($input, 'paid');
        case 'report':    return wpultra_aff_action_report($input);
        case 'config':    return wpultra_aff_action_config($input);
        default:
            return wpultra_err('unknown_action', "Unknown action '$action'. Known: create, update, get, list, delete, link, referrals, approve, reject, mark-paid, report, config.");
    }
}

/** Resolve {id} or {code} (or {affiliate} as either) to an affiliate array, or WP_Error. */
function wpultra_aff_resolve_target(array $input) {
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) {
        $needle = trim((string) ($input['code'] ?? ''));
        if ($needle === '') { $needle = trim((string) ($input['affiliate'] ?? '')); }
        if ($needle === '') { return wpultra_err('missing_target', 'Provide an affiliate id or code.'); }
        $id = ctype_digit($needle) ? (int) $needle : wpultra_aff_find_by_code($needle);
    }
    $aff = wpultra_aff_get($id);
    if ($aff === null) { return wpultra_err('not_found', 'Affiliate not found.'); }
    return $aff;
}

/** Clamp a list limit: default 50, cap 200. */
function wpultra_aff_clamp_limit(array $input): int {
    $limit = (int) ($input['limit'] ?? 50);
    if ($limit < 1) { $limit = 50; }
    return min($limit, 200);
}

/** @return array|WP_Error */
function wpultra_aff_action_create(array $input) {
    $cfg  = wpultra_aff_config();
    $in   = [
        'name'     => trim((string) ($input['name'] ?? '')),
        'email'    => trim((string) ($input['email'] ?? '')),
        'code'     => (string) ($input['code'] ?? ''),
        'rate_pct' => array_key_exists('rate_pct', $input) ? $input['rate_pct'] : $cfg['default_rate'],
    ];
    $valid = wpultra_aff_validate($in);
    if ($valid !== true) { return wpultra_err('invalid_affiliate', (string) $valid); }

    if ($in['code'] !== '') {
        $code = wpultra_aff_normalize_code($in['code']);
        if (wpultra_aff_find_by_code($code) > 0) {
            return wpultra_err('code_exists', "Referral code '$code' is already taken.");
        }
    } else {
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $candidate = wpultra_aff_gen_code($in['name'], substr(bin2hex(random_bytes(3)), 0, 4));
            if (wpultra_aff_find_by_code($candidate) === 0) { $code = $candidate; break; }
        }
        if ($code === '') { return wpultra_err('code_collision', 'Could not generate a unique referral code — pass one explicitly.'); }
    }

    $id = wpultra_aff_insert([
        'name'     => $in['name'],
        'email'    => $in['email'],
        'code'     => $code,
        'rate_pct' => (float) $in['rate_pct'],
    ]);
    if (is_wp_error($id)) { return $id; }

    $aff = wpultra_aff_get($id);
    wpultra_audit_log('affiliate-manage', "create affiliate #$id code=$code", true);
    return wpultra_ok([
        'action'    => 'create',
        'affiliate' => wpultra_aff_shape_affiliate($id, $aff['name'] ?? $in['name'], $aff['meta'] ?? []),
        'url'       => wpultra_aff_referral_link(function_exists('home_url') ? (string) home_url('/') : '/', $code),
    ]);
}

/** @return array|WP_Error */
function wpultra_aff_action_update(array $input) {
    $aff = wpultra_aff_resolve_target($input);
    if (is_wp_error($aff)) { return $aff; }
    $id   = $aff['id'];
    $meta = $aff['meta'];
    $name = $aff['name'];

    if (array_key_exists('name', $input) && trim((string) $input['name']) !== '') { $name = trim((string) $input['name']); }
    if (array_key_exists('email', $input)) { $meta['email'] = strtolower(trim((string) $input['email'])); }
    if (array_key_exists('rate_pct', $input)) { $meta['rate_pct'] = $input['rate_pct']; }

    if (array_key_exists('status', $input)) {
        $status = (string) $input['status'];
        if (!in_array($status, ['active', 'disabled'], true)) {
            return wpultra_err('invalid_status', "Affiliate status must be 'active' or 'disabled'.");
        }
        $meta['status'] = $status;
    }

    if (array_key_exists('new_code', $input) && (string) $input['new_code'] !== '') {
        $new = wpultra_aff_normalize_code((string) $input['new_code']);
        if (!wpultra_aff_valid_code($new)) {
            return wpultra_err('invalid_code', 'new_code must be 3-32 characters of a-z, 0-9 and dashes.');
        }
        $holder = wpultra_aff_find_by_code($new);
        if ($holder > 0 && $holder !== $id) {
            return wpultra_err('code_exists', "Referral code '$new' is already taken.");
        }
        $meta['code'] = $new;
    }

    $valid = wpultra_aff_validate(['name' => $name, 'email' => $meta['email'] ?? '', 'rate_pct' => $meta['rate_pct'] ?? 0]);
    if ($valid !== true) { return wpultra_err('invalid_affiliate', (string) $valid); }
    $meta['rate_pct'] = (float) ($meta['rate_pct'] ?? 0);

    if ($name !== $aff['name']) {
        wp_update_post(['ID' => $id, 'post_title' => $name]);
    }
    wpultra_aff_save_meta($id, $meta);
    wpultra_audit_log('affiliate-manage', "update affiliate #$id", true);
    return wpultra_ok(['action' => 'update', 'affiliate' => wpultra_aff_shape_affiliate($id, $name, $meta)]);
}

/** @return array|WP_Error */
function wpultra_aff_action_get(array $input) {
    $aff = wpultra_aff_resolve_target($input);
    if (is_wp_error($aff)) { return $aff; }
    return wpultra_ok(['action' => 'get', 'affiliate' => wpultra_aff_shape_affiliate($aff['id'], $aff['name'], $aff['meta'])]);
}

/** @return array|WP_Error */
function wpultra_aff_action_list(array $input) {
    $rows = wpultra_aff_list(wpultra_aff_clamp_limit($input));
    $out  = [];
    foreach ($rows as $r) { $out[] = wpultra_aff_shape_affiliate($r['id'], $r['name'], $r['meta']); }
    return wpultra_ok(['action' => 'list', 'affiliates' => $out]);
}

/** @return array|WP_Error */
function wpultra_aff_action_delete(array $input) {
    if (($input['confirm'] ?? false) !== true) {
        return wpultra_err('unconfirmed', 'Deleting an affiliate is destructive. Re-run with confirm: true.');
    }
    $aff = wpultra_aff_resolve_target($input);
    if (is_wp_error($aff)) { return $aff; }
    $id    = $aff['id'];
    $force = ($input['force'] ?? false) === true;

    $refs = wpultra_aff_referrals_query(['affiliate_id' => $id, 'limit' => 200]);
    if ($refs !== [] && !$force) {
        return wpultra_err('has_referrals', count($refs) . ' referral(s) exist for this affiliate. Pass force: true to delete anyway (referrals are kept, orphaned with a note).');
    }

    $orphaned = 0;
    foreach ($refs as $r) {
        $meta = $r['meta'];
        $tag  = "[affiliate #$id '" . (string) ($meta['code'] ?? '') . "' deleted]";
        $meta['note'] = trim(((string) ($meta['note'] ?? '')) . ' ' . $tag);
        wpultra_aff_referral_save_meta($r['id'], $meta);
        $orphaned++;
    }

    wp_delete_post($id, true);
    wpultra_audit_log('affiliate-manage', "delete affiliate #$id orphaned=$orphaned", true);
    return wpultra_ok([
        'action' => 'delete',
        'note'   => $orphaned > 0 ? "Affiliate #$id deleted; $orphaned referral(s) kept orphaned with a note." : "Affiliate #$id deleted.",
    ]);
}

/** @return array|WP_Error */
function wpultra_aff_action_link(array $input) {
    $aff = wpultra_aff_resolve_target($input);
    if (is_wp_error($aff)) { return $aff; }
    $base = trim((string) ($input['url'] ?? ''));
    if ($base === '') { $base = function_exists('home_url') ? (string) home_url('/') : '/'; }
    return wpultra_ok([
        'action' => 'link',
        'url'    => wpultra_aff_referral_link($base, (string) ($aff['meta']['code'] ?? '')),
    ]);
}

/** @return array|WP_Error */
function wpultra_aff_action_referrals(array $input) {
    $filters = ['limit' => wpultra_aff_clamp_limit($input)];

    $who = trim((string) ($input['affiliate'] ?? ''));
    if ($who === '' && (int) ($input['id'] ?? 0) > 0) { $who = (string) (int) $input['id']; }
    if ($who !== '') {
        $aid = ctype_digit($who) ? (int) $who : wpultra_aff_find_by_code($who);
        if ($aid <= 0 || wpultra_aff_get($aid) === null) { return wpultra_err('not_found', 'Affiliate not found.'); }
        $filters['affiliate_id'] = $aid;
    }

    $status = trim((string) ($input['status'] ?? ''));
    if ($status !== '') {
        if (!in_array($status, wpultra_aff_referral_statuses(), true)) {
            return wpultra_err('invalid_status', 'Referral status must be one of: ' . implode(', ', wpultra_aff_referral_statuses()) . '.');
        }
        $filters['status'] = $status;
    }

    $rows = wpultra_aff_referrals_query($filters);
    $out  = [];
    foreach ($rows as $r) { $out[] = wpultra_aff_shape_referral($r['id'], $r['meta']); }
    return wpultra_ok(['action' => 'referrals', 'referrals' => $out]);
}

/** Shared approve / reject / mark-paid handler. @return array|WP_Error */
function wpultra_aff_action_transition(array $input, string $to) {
    $ids = [];
    if ((int) ($input['referral_id'] ?? 0) > 0) { $ids[] = (int) $input['referral_id']; }
    foreach ((array) ($input['ids'] ?? []) as $i) {
        $i = (int) $i;
        if ($i > 0 && !in_array($i, $ids, true)) { $ids[] = $i; }
    }
    if ($ids === []) { return wpultra_err('missing_referral', 'Provide referral_id or ids[].'); }

    // mark-paid is the payout bookkeeping step — bulk runs must be confirmed.
    if ($to === 'paid' && count($ids) > 1 && ($input['confirm'] ?? false) !== true) {
        return wpultra_err('unconfirmed', 'Bulk mark-paid records payouts for ' . count($ids) . ' referrals. Re-run with confirm: true.');
    }

    $results = [];
    $ok_count = 0;
    foreach ($ids as $rid) {
        $res = wpultra_aff_referral_set_status($rid, $to);
        if ($res === true) {
            $ok_count++;
            $ref = wpultra_aff_referral_get($rid);
            $results[] = ['id' => $rid, 'ok' => true, 'referral' => $ref ? wpultra_aff_shape_referral($rid, $ref['meta']) : null];
        } else {
            $results[] = ['id' => $rid, 'ok' => false, 'error' => (string) $res];
        }
    }

    $all_ok = $ok_count === count($ids);
    wpultra_audit_log('affiliate-manage', "$to referrals=" . implode(',', $ids) . " ok=$ok_count/" . count($ids), $all_ok);
    return wpultra_ok(['action' => $to === 'paid' ? 'mark-paid' : ($to === 'approved' ? 'approve' : 'reject'), 'results' => $results]);
}

/** @return array|WP_Error */
function wpultra_aff_action_report(array $input) {
    // Bounded pull: payout report reads up to 1000 newest referrals.
    $rows = wpultra_aff_referrals_query(['limit' => 1000]);
    $meta_rows = [];
    foreach ($rows as $r) { $meta_rows[] = $r['meta']; }

    $report = wpultra_aff_report($meta_rows);

    // Enrich each rollup with the affiliate's name + code (NEVER email here).
    $enriched = [];
    foreach ($report['affiliates'] as $aid => $roll) {
        $aff = wpultra_aff_get((int) $aid);
        if ($aff !== null) {
            $roll['name'] = $aff['name'];
            $roll['code'] = (string) ($aff['meta']['code'] ?? $roll['code']);
            $roll['rate_pct'] = (float) ($aff['meta']['rate_pct'] ?? 0);
            $roll['clicks']   = (int) ($aff['meta']['clicks'] ?? 0);
            $roll['status']   = (string) ($aff['meta']['status'] ?? 'active');
        } else {
            $roll['name'] = '(deleted affiliate)';
        }
        $enriched[] = $roll;
    }

    return wpultra_ok([
        'action' => 'report',
        'report' => ['affiliates' => $enriched, 'totals' => $report['totals']],
        'config' => wpultra_aff_config(),
    ]);
}

/** @return array|WP_Error */
function wpultra_aff_action_config(array $input) {
    if (!function_exists('update_option')) {
        return wpultra_err('no_options', 'Options API unavailable.');
    }
    $changed = [];

    if (array_key_exists('enable', $input)) {
        // Autoloaded on purpose — this is the cheap front-end guard.
        update_option('wpultra_aff_enabled', $input['enable'] === true ? '1' : '0', true);
        $changed[] = 'enabled';
    }
    if (array_key_exists('default_rate', $input)) {
        if (!is_numeric($input['default_rate']) || (float) $input['default_rate'] < 0 || (float) $input['default_rate'] > 100) {
            return wpultra_err('invalid_rate', 'default_rate must be a number between 0 and 100.');
        }
        update_option('wpultra_aff_default_rate', (float) $input['default_rate'], false);
        $changed[] = 'default_rate';
    }
    if (array_key_exists('cookie_days', $input)) {
        $days = (int) $input['cookie_days'];
        if ($days < 1 || $days > 365) {
            return wpultra_err('invalid_cookie_days', 'cookie_days must be between 1 and 365.');
        }
        update_option('wpultra_aff_cookie_days', $days, false);
        $changed[] = 'cookie_days';
    }

    if ($changed !== []) {
        wpultra_audit_log('affiliate-manage', 'config ' . implode(',', $changed), true);
    }
    return wpultra_ok([
        'action' => 'config',
        'config' => wpultra_aff_config(),
        'note'   => $changed === [] ? 'No changes — current config returned.' : 'Updated: ' . implode(', ', $changed) . '. Note: enable takes effect on the NEXT request (hooks arm at boot).',
    ]);
}
