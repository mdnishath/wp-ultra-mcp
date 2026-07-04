<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Affiliate / referral tracking engine (roadmap A5).
 *
 * Storage:
 *   - Affiliates: CPT `wpultra_affiliate` (hidden). post_title = name.
 *     Meta `_wpultra_affiliate` = {email, code, rate_pct, status, clicks, created_at}
 *     with the code mirrored to `_wpultra_aff_code` for fast lookup.
 *   - Referrals: CPT `wpultra_referral` (hidden). post_title = "order #N".
 *     Meta `_wpultra_referral` = {affiliate_id, code, order_id, order_total,
 *     commission, status, created_at, note} with affiliate_id / status mirrored
 *     to `_wpultra_ref_affiliate` / `_wpultra_ref_status` for queryability.
 *
 * Runtime contract: this file defines wpultra_affiliates_boot() and the
 * controller calls it on plugins_loaded. Boot is idempotent. CPTs register on
 * init regardless (the ability needs them); the front-end hooks (?ref= click
 * capture + WooCommerce order attribution) only arm when the autoloaded option
 * `wpultra_aff_enabled` is '1'. ALL runtime code swallows exceptions — the
 * tracker must never break a page view or a checkout.
 *
 * Lifecycle of a referral: pending -> approved|rejected, approved -> paid.
 *
 * Self-referral policy (documented choice): when the order's billing email
 * equals the affiliate's own email the referral is SKIPPED and a best-effort
 * audit-log line is written (no referral post is created) — silent-but-logged
 * beats a rejected row that inflates the report's referral_count.
 */

if (!defined('WPULTRA_AFF_CPT'))       { define('WPULTRA_AFF_CPT', 'wpultra_affiliate'); }
if (!defined('WPULTRA_AFF_REF_CPT'))   { define('WPULTRA_AFF_REF_CPT', 'wpultra_referral'); }
if (!defined('WPULTRA_AFF_META'))      { define('WPULTRA_AFF_META', '_wpultra_affiliate'); }
if (!defined('WPULTRA_AFF_CODE_META')) { define('WPULTRA_AFF_CODE_META', '_wpultra_aff_code'); }
if (!defined('WPULTRA_AFF_REF_META'))  { define('WPULTRA_AFF_REF_META', '_wpultra_referral'); }
if (!defined('WPULTRA_AFF_REF_AFF_META'))    { define('WPULTRA_AFF_REF_AFF_META', '_wpultra_ref_affiliate'); }
if (!defined('WPULTRA_AFF_REF_STATUS_META')) { define('WPULTRA_AFF_REF_STATUS_META', '_wpultra_ref_status'); }
if (!defined('WPULTRA_AFF_COOKIE'))    { define('WPULTRA_AFF_COOKIE', 'wpultra_ref'); }

/* ============================================================
 * PURE core — no WordPress calls. Unit-tested in tests/affiliates.test.php.
 * ============================================================ */

/** Referral-code shape: 3-32 chars of lowercase a-z, 0-9 and dash. */
function wpultra_aff_valid_code(string $code): bool {
    return (bool) preg_match('/^[a-z0-9-]{3,32}$/', $code);
}

/** Canonical form of a code: trimmed + lowercased (validation is separate). */
function wpultra_aff_normalize_code(string $code): string {
    return strtolower(trim($code));
}

/**
 * Commission for an order: total * rate%, rounded to 2dp.
 * Rate is clamped to 0..100 and a negative total counts as 0 (never negative).
 */
function wpultra_aff_commission(float $order_total, float $rate_pct): float {
    if ($rate_pct < 0.0)   { $rate_pct = 0.0; }
    if ($rate_pct > 100.0) { $rate_pct = 100.0; }
    if ($order_total < 0.0) { $order_total = 0.0; }
    return round($order_total * $rate_pct / 100.0, 2);
}

/**
 * Build the referral URL for a code: appends ?ref=CODE or &ref=CODE depending
 * on whether the base already carries a query string. A base that already ends
 * in '?' or '&' gets the pair appended without an extra separator.
 */
function wpultra_aff_referral_link(string $base_url, string $code): string {
    $code = wpultra_aff_normalize_code($code);
    $base = trim($base_url);
    if ($base === '') { $base = '/'; }
    $pair = 'ref=' . rawurlencode($code);
    $last = substr($base, -1);
    if ($last === '?' || $last === '&') { return $base . $pair; }
    $sep = (strpos($base, '?') !== false) ? '&' : '?';
    return $base . $sep . $pair;
}

/**
 * Validate affiliate input for create/update. Returns true or an error string.
 * Expected keys: name (required), email (required), code? (shape), rate_pct? (0..100).
 */
function wpultra_aff_validate(array $in) {
    $name = trim((string) ($in['name'] ?? ''));
    if ($name === '') { return 'name is required.'; }
    if (strlen($name) > 200) { return 'name must be 200 characters or fewer.'; }

    $email = trim((string) ($in['email'] ?? ''));
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return 'email must be a valid email address.';
    }

    if (array_key_exists('code', $in) && (string) $in['code'] !== '') {
        $code = wpultra_aff_normalize_code((string) $in['code']);
        if (!wpultra_aff_valid_code($code)) {
            return 'code must be 3-32 characters of a-z, 0-9 and dashes.';
        }
    }

    if (array_key_exists('rate_pct', $in) && $in['rate_pct'] !== null && $in['rate_pct'] !== '') {
        if (!is_numeric($in['rate_pct'])) { return 'rate_pct must be a number.'; }
        $rate = (float) $in['rate_pct'];
        if ($rate < 0.0 || $rate > 100.0) { return 'rate_pct must be between 0 and 100.'; }
    }

    return true;
}

/**
 * Deterministic code candidate from a display name + optional suffix.
 * Always returns a value that passes wpultra_aff_valid_code().
 */
function wpultra_aff_gen_code(string $name, string $suffix = ''): string {
    $slug = strtolower(trim($name));
    $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    if ($slug === '') { $slug = 'aff'; }

    $suffix = (string) preg_replace('/[^a-z0-9]+/', '', strtolower(trim($suffix)));
    if (strlen($suffix) > 28) { $suffix = substr($suffix, 0, 28); }

    $budget = 32 - ($suffix === '' ? 0 : strlen($suffix) + 1);
    if ($budget < 3) { $budget = 3; }
    if (strlen($slug) > $budget) { $slug = trim(substr($slug, 0, $budget), '-'); }
    if ($slug === '') { $slug = 'aff'; }

    $code = $suffix === '' ? $slug : $slug . '-' . $suffix;
    if (strlen($code) > 32) { $code = trim(substr($code, 0, 32), '-'); }
    while (strlen($code) < 3) { $code .= 'x'; }
    return $code;
}

/** Allowed referral status transitions: pending->approved|rejected, approved->paid. */
function wpultra_aff_can_transition(string $from, string $to): bool {
    $allowed = [
        'pending'  => ['approved', 'rejected'],
        'approved' => ['paid'],
    ];
    return in_array($to, $allowed[$from] ?? [], true);
}

/** All valid referral statuses (filter validation). */
function wpultra_aff_referral_statuses(): array {
    return ['pending', 'approved', 'rejected', 'paid'];
}

/**
 * Payout rollup over a list of referral meta arrays.
 * Returns ['affiliates' => [affiliate_id => rollup], 'totals' => rollup].
 * Rollup: pending_total, approved_total, paid_total, rejected_total (order
 * totals per status), commission_pending, commission_approved,
 * commission_paid, referral_count. Rows with an unknown status still count
 * toward referral_count but land in no money bucket. All money is 2dp.
 */
function wpultra_aff_report(array $referrals_meta): array {
    $zero = [
        'pending_total'       => 0.0,
        'approved_total'      => 0.0,
        'paid_total'          => 0.0,
        'rejected_total'      => 0.0,
        'commission_pending'  => 0.0,
        'commission_approved' => 0.0,
        'commission_paid'     => 0.0,
        'referral_count'      => 0,
    ];
    $per    = [];
    $totals = $zero;

    foreach ($referrals_meta as $row) {
        if (!is_array($row)) { continue; }
        $aid    = (int) ($row['affiliate_id'] ?? 0);
        $status = (string) ($row['status'] ?? '');
        $total  = (float) ($row['order_total'] ?? 0);
        $comm   = (float) ($row['commission'] ?? 0);

        if (!isset($per[$aid])) {
            $per[$aid] = $zero;
            $per[$aid]['affiliate_id'] = $aid;
            $per[$aid]['code'] = (string) ($row['code'] ?? '');
        }

        $buckets = [
            'pending'  => ['pending_total', 'commission_pending'],
            'approved' => ['approved_total', 'commission_approved'],
            'paid'     => ['paid_total', 'commission_paid'],
            'rejected' => ['rejected_total', null],
        ];
        if (isset($buckets[$status])) {
            [$totalKey, $commKey] = $buckets[$status];
            $per[$aid][$totalKey] += $total;
            $totals[$totalKey]    += $total;
            if ($commKey !== null) {
                $per[$aid][$commKey] += $comm;
                $totals[$commKey]    += $comm;
            }
        }
        $per[$aid]['referral_count']++;
        $totals['referral_count']++;
    }

    $money = ['pending_total', 'approved_total', 'paid_total', 'rejected_total',
              'commission_pending', 'commission_approved', 'commission_paid'];
    foreach ($per as $aid => $r) {
        foreach ($money as $k) { $per[$aid][$k] = round($r[$k], 2); }
    }
    foreach ($money as $k) { $totals[$k] = round($totals[$k], 2); }

    return ['affiliates' => $per, 'totals' => $totals];
}

/**
 * Public shape of an affiliate. $include_email=false is used in report mode so
 * one affiliate's payout view never leaks other affiliates' emails.
 */
function wpultra_aff_shape_affiliate(int $id, string $name, array $meta, bool $include_email = true): array {
    $out = [
        'id'         => $id,
        'name'       => $name,
        'code'       => (string) ($meta['code'] ?? ''),
        'rate_pct'   => (float) ($meta['rate_pct'] ?? 0),
        'status'     => (string) ($meta['status'] ?? 'active'),
        'clicks'     => (int) ($meta['clicks'] ?? 0),
        'created_at' => (string) ($meta['created_at'] ?? ''),
    ];
    if ($include_email) { $out['email'] = (string) ($meta['email'] ?? ''); }
    return $out;
}

/** Public shape of a referral row. */
function wpultra_aff_shape_referral(int $id, array $meta): array {
    return [
        'id'           => $id,
        'affiliate_id' => (int) ($meta['affiliate_id'] ?? 0),
        'code'         => (string) ($meta['code'] ?? ''),
        'order_id'     => (int) ($meta['order_id'] ?? 0),
        'order_total'  => (float) ($meta['order_total'] ?? 0),
        'commission'   => (float) ($meta['commission'] ?? 0),
        'status'       => (string) ($meta['status'] ?? 'pending'),
        'created_at'   => (string) ($meta['created_at'] ?? ''),
        'note'         => (string) ($meta['note'] ?? ''),
    ];
}

/* ============================================================
 * WordPress wrappers — CPT persistence + config.
 * ============================================================ */

/** Current tracker config with defaults applied. */
function wpultra_aff_config(): array {
    $rate = 10.0;
    $days = 30;
    $on   = false;
    if (function_exists('get_option')) {
        $rate = (float) get_option('wpultra_aff_default_rate', 10);
        $days = (int) get_option('wpultra_aff_cookie_days', 30);
        $on   = get_option('wpultra_aff_enabled') === '1';
    }
    if ($rate < 0.0 || $rate > 100.0) { $rate = 10.0; }
    if ($days < 1) { $days = 30; }
    return ['enabled' => $on, 'default_rate' => $rate, 'cookie_days' => $days];
}

/** Register both hidden CPTs (idempotent; runs on init). */
function wpultra_aff_register_cpts(): void {
    if (!function_exists('register_post_type')) { return; }
    $args = [
        'public'       => false,
        'show_ui'      => false,
        'show_in_rest' => false,
        'supports'     => ['title'],
        'rewrite'      => false,
    ];
    if (!function_exists('post_type_exists') || !post_type_exists(WPULTRA_AFF_CPT)) {
        register_post_type(WPULTRA_AFF_CPT, $args);
    }
    if (!function_exists('post_type_exists') || !post_type_exists(WPULTRA_AFF_REF_CPT)) {
        register_post_type(WPULTRA_AFF_REF_CPT, $args);
    }
}

/* ---- Affiliate CRUD ---- */

/** Fast code -> affiliate id lookup via the mirrored code meta. 0 = not found. */
function wpultra_aff_find_by_code(string $code): int {
    if (!function_exists('get_posts')) { return 0; }
    $code = wpultra_aff_normalize_code($code);
    if ($code === '') { return 0; }
    $ids = get_posts([
        'post_type'   => WPULTRA_AFF_CPT,
        'post_status' => 'publish',
        'numberposts' => 1,
        'fields'      => 'ids',
        'meta_key'    => WPULTRA_AFF_CODE_META,
        'meta_value'  => $code,
    ]);
    return is_array($ids) && $ids ? (int) $ids[0] : 0;
}

/** Load one affiliate: ['id', 'name', 'meta' => [...]] or null. */
function wpultra_aff_get(int $id): ?array {
    if ($id <= 0 || !function_exists('get_post')) { return null; }
    $post = get_post($id);
    if (!$post || $post->post_type !== WPULTRA_AFF_CPT || $post->post_status === 'trash') { return null; }
    $meta = get_post_meta($id, WPULTRA_AFF_META, true);
    if (!is_array($meta)) { $meta = []; }
    return ['id' => (int) $post->ID, 'name' => (string) $post->post_title, 'meta' => $meta];
}

/** Persist affiliate meta (blob + code mirror). */
function wpultra_aff_save_meta(int $id, array $meta): void {
    update_post_meta($id, WPULTRA_AFF_META, $meta);
    update_post_meta($id, WPULTRA_AFF_CODE_META, (string) ($meta['code'] ?? ''));
}

/**
 * Create an affiliate post. $in: name, email, code (already unique+valid),
 * rate_pct. @return int|WP_Error new post id.
 */
function wpultra_aff_insert(array $in) {
    $id = wp_insert_post([
        'post_type'   => WPULTRA_AFF_CPT,
        'post_status' => 'publish',
        'post_title'  => (string) $in['name'],
    ], true);
    if (is_wp_error($id)) { return $id; }
    $meta = [
        'email'      => strtolower(trim((string) $in['email'])),
        'code'       => (string) $in['code'],
        'rate_pct'   => (float) $in['rate_pct'],
        'status'     => 'active',
        'clicks'     => 0,
        'created_at' => gmdate('Y-m-d H:i:s'),
    ];
    wpultra_aff_save_meta((int) $id, $meta);
    return (int) $id;
}

/** List affiliates (newest first): array of ['id','name','meta']. */
function wpultra_aff_list(int $limit = 50): array {
    if (!function_exists('get_posts')) { return []; }
    $posts = get_posts([
        'post_type'   => WPULTRA_AFF_CPT,
        'post_status' => 'publish',
        'numberposts' => $limit,
        'orderby'     => 'date',
        'order'       => 'DESC',
    ]);
    $out = [];
    foreach ((array) $posts as $p) {
        $meta = get_post_meta((int) $p->ID, WPULTRA_AFF_META, true);
        $out[] = ['id' => (int) $p->ID, 'name' => (string) $p->post_title, 'meta' => is_array($meta) ? $meta : []];
    }
    return $out;
}

/* ---- Referral CRUD ---- */

/** Create a referral post from a full meta array. @return int|WP_Error */
function wpultra_aff_referral_insert(array $meta) {
    $id = wp_insert_post([
        'post_type'   => WPULTRA_AFF_REF_CPT,
        'post_status' => 'publish',
        'post_title'  => 'order #' . (int) ($meta['order_id'] ?? 0),
    ], true);
    if (is_wp_error($id)) { return $id; }
    wpultra_aff_referral_save_meta((int) $id, $meta);
    return (int) $id;
}

/** Persist referral meta (blob + affiliate/status mirrors). */
function wpultra_aff_referral_save_meta(int $id, array $meta): void {
    update_post_meta($id, WPULTRA_AFF_REF_META, $meta);
    update_post_meta($id, WPULTRA_AFF_REF_AFF_META, (string) (int) ($meta['affiliate_id'] ?? 0));
    update_post_meta($id, WPULTRA_AFF_REF_STATUS_META, (string) ($meta['status'] ?? 'pending'));
}

/** Load one referral: ['id', 'meta'] or null. */
function wpultra_aff_referral_get(int $id): ?array {
    if ($id <= 0 || !function_exists('get_post')) { return null; }
    $post = get_post($id);
    if (!$post || $post->post_type !== WPULTRA_AFF_REF_CPT || $post->post_status === 'trash') { return null; }
    $meta = get_post_meta($id, WPULTRA_AFF_REF_META, true);
    if (!is_array($meta)) { $meta = []; }
    return ['id' => (int) $post->ID, 'meta' => $meta];
}

/**
 * Query referrals newest first. $filters: affiliate_id?, status?, limit?.
 * @return array of ['id','meta']
 */
function wpultra_aff_referrals_query(array $filters = []): array {
    if (!function_exists('get_posts')) { return []; }
    $limit = (int) ($filters['limit'] ?? 50);
    if ($limit < 1) { $limit = 50; }
    $args = [
        'post_type'   => WPULTRA_AFF_REF_CPT,
        'post_status' => 'publish',
        'numberposts' => $limit,
        'orderby'     => 'date',
        'order'       => 'DESC',
    ];
    $meta_query = [];
    if (!empty($filters['affiliate_id'])) {
        $meta_query[] = ['key' => WPULTRA_AFF_REF_AFF_META, 'value' => (string) (int) $filters['affiliate_id']];
    }
    if (!empty($filters['status'])) {
        $meta_query[] = ['key' => WPULTRA_AFF_REF_STATUS_META, 'value' => (string) $filters['status']];
    }
    if ($meta_query) { $args['meta_query'] = $meta_query; }
    $posts = get_posts($args);
    $out = [];
    foreach ((array) $posts as $p) {
        $meta = get_post_meta((int) $p->ID, WPULTRA_AFF_REF_META, true);
        $out[] = ['id' => (int) $p->ID, 'meta' => is_array($meta) ? $meta : []];
    }
    return $out;
}

/** Transition one referral's status. Returns true or an error string. */
function wpultra_aff_referral_set_status(int $id, string $to) {
    $ref = wpultra_aff_referral_get($id);
    if ($ref === null) { return "referral #$id not found."; }
    $from = (string) ($ref['meta']['status'] ?? 'pending');
    if ($from === $to) { return "referral #$id is already $to."; }
    if (!wpultra_aff_can_transition($from, $to)) {
        return "referral #$id cannot go $from -> $to (allowed: pending->approved|rejected, approved->paid).";
    }
    $meta = $ref['meta'];
    $meta['status'] = $to;
    wpultra_aff_referral_save_meta($id, $meta);
    return true;
}

/* ============================================================
 * Runtime (front-end hooks). Never throws.
 * ============================================================ */

/**
 * Boot the tracker. Idempotent; the controller calls this on plugins_loaded.
 * CPTs always register on init (the ability needs them); the click-capture and
 * order-attribution hooks only arm when wpultra_aff_enabled === '1'.
 */
function wpultra_affiliates_boot(): void {
    static $booted = false;
    if ($booted || !function_exists('add_action')) { return; }
    $booted = true;

    add_action('init', 'wpultra_aff_register_cpts', 5);
    if (function_exists('did_action') && did_action('init')) { wpultra_aff_register_cpts(); }

    if (!function_exists('get_option') || get_option('wpultra_aff_enabled') !== '1') { return; }

    add_action('init', 'wpultra_aff_capture_click', 20);
    // Primary attribution hook + a fallback for orders created outside the
    // classic checkout flow. The per-order '_wpultra_ref_recorded' guard makes
    // double-fire (and re-fire) safe.
    add_action('woocommerce_checkout_order_processed', 'wpultra_aff_record_order', 10, 1);
    add_action('woocommerce_new_order', 'wpultra_aff_record_order', 20, 1);
    // woocommerce_new_order fires when the order row is SAVED — for programmatic
    // and admin-created orders that is BEFORE items/totals exist, so the referral
    // records total 0. Re-sync a still-pending referral's total on every order
    // status change (draft→pending→processing… all pass through here).
    add_action('woocommerce_order_status_changed', 'wpultra_aff_sync_order_total', 10, 1);
}

/**
 * Refresh a pending referral's order_total + commission from the order's
 * current total (see hook comment above — new_order can fire on an empty order).
 *
 * @param int|mixed $order_id
 */
function wpultra_aff_sync_order_total($order_id): void {
    try {
        $order_id = (int) $order_id;
        if ($order_id <= 0 || !function_exists('wc_get_order')) { return; }
        $rid = (int) get_post_meta($order_id, '_wpultra_ref_referral_id', true);
        if ($rid <= 0) { return; }
        $ref = wpultra_aff_referral_get($rid);
        if ($ref === null || (string) ($ref['meta']['status'] ?? '') !== 'pending') { return; }
        $order = wc_get_order($order_id);
        if (!$order) { return; }
        $total = (float) $order->get_total();
        if ($total === (float) ($ref['meta']['order_total'] ?? 0)) { return; }
        $aff = wpultra_aff_get((int) ($ref['meta']['affiliate_id'] ?? 0));
        $rate = $aff !== null ? (float) ($aff['meta']['rate_pct'] ?? 0) : 0.0;
        $meta = $ref['meta'];
        $meta['order_total'] = $total;
        $meta['commission']  = wpultra_aff_commission($total, $rate);
        wpultra_aff_referral_save_meta($rid, $meta);
    } catch (\Throwable $e) {
        // Never break an order transition.
    }
}

/**
 * ?ref=CODE click capture (front-end only). Sets the wpultra_ref cookie for
 * wpultra_aff_cookie_days days and increments the affiliate's click counter —
 * but only when the visitor is not already carrying this same code, so a
 * cookied visitor browsing around doesn't inflate clicks on every pageview.
 */
function wpultra_aff_capture_click(): void {
    try {
        if (PHP_SAPI === 'cli') { return; }
        if (function_exists('is_admin') && is_admin()) { return; }
        if (function_exists('wp_doing_cron') && wp_doing_cron()) { return; }
        if (defined('REST_REQUEST') && REST_REQUEST) { return; }
        if (defined('DOING_AJAX') && DOING_AJAX) { return; }

        $raw = isset($_GET['ref']) ? (string) $_GET['ref'] : '';
        if ($raw === '') { return; }
        $code = wpultra_aff_normalize_code($raw);
        if (!wpultra_aff_valid_code($code)) { return; }

        $aid = wpultra_aff_find_by_code($code);
        if ($aid <= 0) { return; }
        $aff = wpultra_aff_get($aid);
        if ($aff === null || (string) ($aff['meta']['status'] ?? '') !== 'active') { return; }

        $already = isset($_COOKIE[WPULTRA_AFF_COOKIE])
            && wpultra_aff_normalize_code((string) $_COOKIE[WPULTRA_AFF_COOKIE]) === $code;

        $cfg = wpultra_aff_config();
        if (!headers_sent()) {
            // Best-effort: plain setcookie keeps 5.x-compat call shape simple.
            setcookie(WPULTRA_AFF_COOKIE, $code, time() + $cfg['cookie_days'] * 86400, '/');
        }
        $_COOKIE[WPULTRA_AFF_COOKIE] = $code; // visible to this request too

        if (!$already) {
            $meta = $aff['meta'];
            $meta['clicks'] = ((int) ($meta['clicks'] ?? 0)) + 1;
            wpultra_aff_save_meta($aid, $meta);
        }
    } catch (\Throwable $e) {
        // Never break the front-end.
    }
}

/**
 * Order attribution: on checkout, if the visitor carries a valid wpultra_ref
 * cookie pointing at an active affiliate, create a pending referral. The order
 * meta '_wpultra_ref_recorded' is written FIRST so hook double-fire is safe.
 * Self-referrals (billing email == affiliate email) are skipped with a
 * best-effort audit-log line.
 *
 * @param int|mixed $order_id
 */
function wpultra_aff_record_order($order_id): void {
    try {
        $order_id = (int) $order_id;
        if ($order_id <= 0) { return; }
        if (!function_exists('wc_get_order') || !function_exists('get_post_meta')) { return; }

        $raw = isset($_COOKIE[WPULTRA_AFF_COOKIE]) ? (string) $_COOKIE[WPULTRA_AFF_COOKIE] : '';
        if ($raw === '') { return; }
        $code = wpultra_aff_normalize_code($raw);
        if (!wpultra_aff_valid_code($code)) { return; }

        // Idempotence guard, written before any other work.
        if (get_post_meta($order_id, '_wpultra_ref_recorded', true)) { return; }
        update_post_meta($order_id, '_wpultra_ref_recorded', 1);

        $aid = wpultra_aff_find_by_code($code);
        if ($aid <= 0) { return; }
        $aff = wpultra_aff_get($aid);
        if ($aff === null || (string) ($aff['meta']['status'] ?? '') !== 'active') { return; }

        $order = wc_get_order($order_id);
        if (!$order) { return; }

        $billing   = strtolower(trim((string) (method_exists($order, 'get_billing_email') ? $order->get_billing_email() : '')));
        $aff_email = strtolower(trim((string) ($aff['meta']['email'] ?? '')));
        if ($billing !== '' && $billing === $aff_email) {
            if (function_exists('wpultra_audit_log')) {
                wpultra_audit_log('affiliate-manage', "self-referral skipped: order#$order_id code=$code", true);
            }
            return;
        }

        $total      = (float) $order->get_total();
        $rate       = (float) ($aff['meta']['rate_pct'] ?? 0);
        $commission = wpultra_aff_commission($total, $rate);

        $rid = wpultra_aff_referral_insert([
            'affiliate_id' => $aid,
            'code'         => $code,
            'order_id'     => $order_id,
            'order_total'  => $total,
            'commission'   => $commission,
            'status'       => 'pending',
            'created_at'   => gmdate('Y-m-d H:i:s'),
            'note'         => '',
        ]);
        // Link the referral to the order so later status transitions can re-sync
        // the total (new_order may have fired before items/totals existed).
        if (is_int($rid) && $rid > 0) {
            update_post_meta($order_id, '_wpultra_ref_referral_id', $rid);
        }
    } catch (\Throwable $e) {
        // Never break checkout.
    }
}
