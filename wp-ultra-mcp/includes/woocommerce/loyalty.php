<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Points & loyalty / gift cards engine (roadmap-2 B5).
 *
 * PURE first (unit-testable via tests/woo-loyalty.test.php, no WP/WC
 * dependency): earn math, redeem value, ledger ring buffer, coupon-code
 * generator, config validation/merge, redeem validation, gift-card email
 * template. WP/WC wrappers after, all guarded.
 *
 * Model:
 *  - Config lives in the autoloaded option `wpultra_loyalty`
 *    {enabled, earn_rate, redeem_rate, min_redeem, award_on}. Empty option or
 *    enabled=false means the earning hooks are NOT armed (cheap boot guard).
 *  - Points balance: user_meta `wpultra_points` (int). Ledger: user_meta
 *    `wpultra_points_ledger` = [{at, delta, reason, ref}] newest LAST, cap 200.
 *  - Earning: on `woocommerce_order_status_<award_on>` the customer earns
 *    floor(order_total x earn_rate) points. GUESTS EARN NOTHING (no user id
 *    to attach the balance to). Idempotence via order meta
 *    `_wpultra_points_awarded` (order-object meta -> HPOS-safe), written
 *    BEFORE the balance is credited. Refund clawback on
 *    `woocommerce_order_status_refunded` (once, meta `_wpultra_points_clawed`).
 *  - Redeem: points -> single-use fixed_cart WC_Coupon 'pts-xxxxxxxx',
 *    individual_use, email-locked to the redeeming customer.
 *  - Gift cards: single-use fixed_cart WC_Coupon 'gift-xxxxxxxx' — a simple
 *    single-use gift VOUCHER, not a partial-redemption balance card.
 *
 * HPOS: orders are read via wc_get_order and order meta ONLY via
 * $order->get_meta()/update_meta_data()/save(). Coupons are normal CPT posts
 * (not HPOS) — WC_Coupon API + get_posts meta_query are fine there.
 */

/* ============================================================
 * PURE: config
 * ============================================================ */

/** Default loyalty configuration. Pure. */
function wpultra_loyalty_default_config(): array {
    return [
        'enabled'     => false,
        'earn_rate'   => 1.0,   // points earned per 1 unit of currency spent
        'redeem_rate' => 0.01,  // currency value of 1 point
        'min_redeem'  => 100,   // minimum points per redemption
        'award_on'    => 'completed', // order status that triggers earning
    ];
}

/**
 * Validate a config patch. Returns true, or a string describing the first
 * problem. Rates must be numeric and > 0, min_redeem an integer >= 1,
 * award_on one of completed|processing, enabled a boolean. Unknown keys are
 * rejected. Pure.
 */
function wpultra_loyalty_validate_config(array $patch) {
    $known = ['enabled', 'earn_rate', 'redeem_rate', 'min_redeem', 'award_on'];
    foreach (array_keys($patch) as $k) {
        if (!in_array($k, $known, true)) { return "unknown config key: $k"; }
    }
    if (array_key_exists('enabled', $patch) && !is_bool($patch['enabled'])) {
        return 'enabled must be a boolean';
    }
    foreach (['earn_rate', 'redeem_rate'] as $rk) {
        if (array_key_exists($rk, $patch)) {
            if (!is_numeric($patch[$rk]) || (float) $patch[$rk] <= 0) {
                return "$rk must be a number greater than 0";
            }
        }
    }
    if (array_key_exists('min_redeem', $patch)) {
        $m = $patch['min_redeem'];
        if (!is_numeric($m) || (int) $m != (float) $m || (int) $m < 1) {
            return 'min_redeem must be an integer >= 1';
        }
    }
    if (array_key_exists('award_on', $patch)) {
        if (!in_array($patch['award_on'], ['completed', 'processing'], true)) {
            return "award_on must be 'completed' or 'processing'";
        }
    }
    return true;
}

/** Merge a (validated) patch over the current config, normalizing types. Pure. */
function wpultra_loyalty_merge_config(array $current, array $patch): array {
    $cfg = array_merge(wpultra_loyalty_default_config(), $current, $patch);
    return [
        'enabled'     => (bool) $cfg['enabled'],
        'earn_rate'   => (float) $cfg['earn_rate'],
        'redeem_rate' => (float) $cfg['redeem_rate'],
        'min_redeem'  => (int) $cfg['min_redeem'],
        'award_on'    => in_array($cfg['award_on'], ['completed', 'processing'], true) ? $cfg['award_on'] : 'completed',
    ];
}

/* ============================================================
 * PURE: points math
 * ============================================================ */

/**
 * Points earned for an order total at a given earn rate:
 * floor(total x rate), never negative. A zero/negative total or rate earns 0.
 * Pure.
 */
function wpultra_loyalty_earn(float $order_total, float $earn_rate): int {
    if ($order_total <= 0 || $earn_rate <= 0) { return 0; }
    return (int) floor($order_total * $earn_rate);
}

/**
 * Currency value of a number of points at a given redeem rate, rounded to
 * 2 decimal places, never negative. Pure.
 */
function wpultra_loyalty_redeem_value(int $points, float $redeem_rate): float {
    if ($points <= 0 || $redeem_rate <= 0) { return 0.0; }
    return round($points * $redeem_rate, 2);
}

/**
 * Can $points be redeemed against $balance with a minimum of $min?
 * Returns true, or one of the error strings:
 *   'not_integer'          — points is not a whole number
 *   'below_minimum'        — points < min (or points < 1)
 *   'insufficient_balance' — points > balance
 * $points is intentionally untyped so callers can pass raw input. Pure.
 *
 * @param mixed $points
 */
function wpultra_loyalty_can_redeem($points, int $balance, int $min) {
    if (is_bool($points) || !is_numeric($points) || (float) $points != (int) $points) {
        return 'not_integer';
    }
    $p = (int) $points;
    if ($p < 1 || $p < $min) { return 'below_minimum'; }
    if ($p > $balance) { return 'insufficient_balance'; }
    return true;
}

/* ============================================================
 * PURE: ledger
 * ============================================================ */

/**
 * Append an entry to a points ledger (newest LAST) and cap it at $cap entries
 * (oldest dropped first). Entry fields are normalized to
 * {at:int, delta:int, reason:string, ref:string}. Pure.
 */
function wpultra_loyalty_ledger_push(array $ledger, array $entry, int $cap = 200): array {
    $ledger[] = [
        'at'     => (int) ($entry['at'] ?? time()),
        'delta'  => (int) ($entry['delta'] ?? 0),
        'reason' => (string) ($entry['reason'] ?? ''),
        'ref'    => (string) ($entry['ref'] ?? ''),
    ];
    if ($cap < 1) { $cap = 200; }
    if (count($ledger) > $cap) { $ledger = array_slice($ledger, -$cap); }
    return array_values($ledger);
}

/* ============================================================
 * PURE: coupon code generator
 * ============================================================ */

/**
 * Generate a coupon code '<prefix>-xxxxxxxx' (8 lowercase alphanumerics).
 * $rand is an injectable random-int source with the random_int(min, max)
 * signature — pass null for a cryptographically secure default. Pure given
 * $rand.
 */
function wpultra_loyalty_code(string $prefix, ?callable $rand = null): string {
    $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $max = strlen($chars) - 1;
    if ($rand === null) {
        $rand = static function (int $lo, int $hi): int { return random_int($lo, $hi); };
    }
    $out = '';
    for ($i = 0; $i < 8; $i++) {
        $idx = (int) $rand(0, $max);
        if ($idx < 0) { $idx = 0; }
        if ($idx > $max) { $idx = $idx % ($max + 1); }
        $out .= $chars[$idx];
    }
    return $prefix . '-' . $out;
}

/* ============================================================
 * PURE: gift-card email template
 * ============================================================ */

/**
 * Render the gift-card email body (HTML). Every interpolated value is
 * HTML-escaped, so a hostile note/store name/code cannot inject markup
 * (XSS-safe). Expects: amount (string|number), currency (string), code,
 * note, store. Pure.
 */
function wpultra_loyalty_gift_html(array $d): string {
    $esc = static function ($v): string {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    };
    $amount   = $esc($d['amount'] ?? '');
    $currency = $esc($d['currency'] ?? '');
    $code     = $esc($d['code'] ?? '');
    $note     = $esc($d['note'] ?? '');
    $store    = $esc($d['store'] ?? '');

    $html  = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:520px;margin:0 auto;padding:24px;border:1px solid #e5e5e5;border-radius:8px;">';
    $html .= '<h1 style="font-size:20px;margin:0 0 12px;">You received a gift card' . ($store !== '' ? ' from ' . $store : '') . '!</h1>';
    $html .= '<p style="font-size:28px;font-weight:bold;margin:0 0 16px;">' . $amount . ($currency !== '' ? ' ' . $currency : '') . '</p>';
    $html .= '<p style="margin:0 0 8px;">Use this code at checkout:</p>';
    $html .= '<p style="font-size:22px;letter-spacing:2px;font-family:monospace;background:#f5f5f5;padding:12px;border-radius:6px;text-align:center;margin:0 0 16px;">' . $code . '</p>';
    if ($note !== '') {
        $html .= '<p style="font-style:italic;color:#555;margin:0 0 16px;">&ldquo;' . $note . '&rdquo;</p>';
    }
    $html .= '<p style="font-size:12px;color:#888;margin:0;">This is a single-use voucher — it is consumed in full on first use.'
        . ($store !== '' ? ' Sent by ' . $store . '.' : '') . '</p>';
    $html .= '</div>';
    return $html;
}

/* ============================================================
 * Runtime: config + balance storage (WP-guarded)
 * ============================================================ */

/** Current effective config (stored option merged over defaults). */
function wpultra_loyalty_config(): array {
    $stored = function_exists('get_option') ? get_option('wpultra_loyalty', []) : [];
    if (!is_array($stored)) { $stored = []; }
    return wpultra_loyalty_merge_config($stored, []);
}

/** Persist the config (autoloaded — the boot guard reads it on every request). */
function wpultra_loyalty_save_config(array $cfg): void {
    if (function_exists('update_option')) { update_option('wpultra_loyalty', $cfg, true); }
}

/** Current points balance for a user (int, >= 0). */
function wpultra_loyalty_get_balance(int $user_id): int {
    if (!function_exists('get_user_meta')) { return 0; }
    $n = (int) get_user_meta($user_id, 'wpultra_points', true);
    return $n > 0 ? $n : 0;
}

/** Full ledger for a user (newest last). */
function wpultra_loyalty_get_ledger(int $user_id): array {
    if (!function_exists('get_user_meta')) { return []; }
    $l = get_user_meta($user_id, 'wpultra_points_ledger', true);
    return is_array($l) ? array_values($l) : [];
}

/**
 * Apply a signed points delta to a user's balance (floored at 0 — the ledger
 * records the delta that was ACTUALLY applied) and append a ledger entry.
 * Returns the new balance.
 */
function wpultra_loyalty_add_points(int $user_id, int $delta, string $reason, string $ref): int {
    $balance = wpultra_loyalty_get_balance($user_id);
    $new = $balance + $delta;
    if ($new < 0) { $new = 0; }
    $applied = $new - $balance;
    if (function_exists('update_user_meta')) {
        update_user_meta($user_id, 'wpultra_points', $new);
        $ledger = wpultra_loyalty_ledger_push(wpultra_loyalty_get_ledger($user_id), [
            'at'     => time(),
            'delta'  => $applied,
            'reason' => $reason,
            'ref'    => $ref,
        ]);
        update_user_meta($user_id, 'wpultra_points_ledger', $ledger);
    }
    return $new;
}

/* ============================================================
 * Runtime: earning hooks (boot contract)
 * ============================================================ */

/**
 * Boot the loyalty runtime. Idempotent; the controller calls this on
 * plugins_loaded. Cheap guard: when the autoloaded `wpultra_loyalty` option is
 * empty or enabled=false, NO hooks are armed (config changes take effect on
 * the next request).
 */
function wpultra_loyalty_boot(): void {
    static $booted = false;
    if ($booted || !function_exists('add_action') || !function_exists('get_option')) { return; }
    $booted = true;

    $stored = get_option('wpultra_loyalty', []);
    if (!is_array($stored) || empty($stored['enabled'])) { return; }

    $award_on = in_array($stored['award_on'] ?? 'completed', ['completed', 'processing'], true)
        ? (string) $stored['award_on'] : 'completed';
    add_action('woocommerce_order_status_' . $award_on, 'wpultra_loyalty_award_order', 10, 1);
    add_action('woocommerce_order_status_refunded', 'wpultra_loyalty_clawback_order', 10, 1);
}

/**
 * Award points for an order reaching the configured status. Guests (no
 * customer user id) earn nothing. Idempotent: the `_wpultra_points_awarded`
 * order meta (HPOS-safe, via the order object) is checked AND written before
 * the balance is credited, so a double-fired status hook cannot double-award.
 * Never throws.
 *
 * @param int|mixed $order_id
 */
function wpultra_loyalty_award_order($order_id): void {
    try {
        if (!function_exists('wc_get_order')) { return; }
        $order = wc_get_order((int) $order_id);
        if (!$order || !is_object($order) || !method_exists($order, 'get_meta')) { return; }

        // Guests earn nothing — there is no user account to attach points to.
        $user_id = (int) $order->get_customer_id();
        if ($user_id <= 0) { return; }

        // Idempotence guard FIRST (order-object meta — HPOS-safe).
        if ((string) $order->get_meta('_wpultra_points_awarded') !== '') { return; }

        $cfg = wpultra_loyalty_config();
        if (empty($cfg['enabled'])) { return; }

        $points = wpultra_loyalty_earn((float) $order->get_total(), (float) $cfg['earn_rate']);

        // Write the guard (with the awarded amount, so a refund clawback can
        // subtract exactly what was granted) BEFORE crediting the balance.
        $order->update_meta_data('_wpultra_points_awarded', (string) $points);
        $order->save();

        if ($points > 0) {
            wpultra_loyalty_add_points($user_id, $points, 'order', 'order:' . (int) $order->get_id());
        }
    } catch (\Throwable $e) {
        // Never break an order status transition.
    }
}

/**
 * Claw back previously awarded points when an order is refunded. Runs at most
 * once per order (meta `_wpultra_points_clawed`), subtracts exactly the
 * awarded amount, balance floored at 0. Never throws.
 *
 * @param int|mixed $order_id
 */
function wpultra_loyalty_clawback_order($order_id): void {
    try {
        if (!function_exists('wc_get_order')) { return; }
        $order = wc_get_order((int) $order_id);
        if (!$order || !is_object($order) || !method_exists($order, 'get_meta')) { return; }

        $awarded = (int) $order->get_meta('_wpultra_points_awarded');
        if ($awarded <= 0) { return; }
        if ((string) $order->get_meta('_wpultra_points_clawed') !== '') { return; }

        // Mark clawed FIRST (HPOS-safe order-object meta).
        $order->update_meta_data('_wpultra_points_clawed', '1');
        $order->save();

        $user_id = (int) $order->get_customer_id();
        if ($user_id > 0) {
            wpultra_loyalty_add_points($user_id, -$awarded, 'refund-clawback', 'order:' . (int) $order->get_id());
        }
    } catch (\Throwable $e) {
        // Never break an order status transition.
    }
}

/* ============================================================
 * Runtime: coupons (redeem + gift cards)
 * ============================================================ */

/**
 * Create a single-use fixed_cart WC_Coupon. Args: code, amount,
 * email (locks the coupon to that address), individual_use (bool),
 * expires_ts (unix, 0 = never), description, meta (map). Returns the coupon
 * id. Throws on failure (callers roll back).
 */
function wpultra_loyalty_make_coupon(array $args): int {
    if (!class_exists('WC_Coupon')) {
        throw new \RuntimeException('WC_Coupon class not available');
    }
    $coupon = new \WC_Coupon();
    $coupon->set_code((string) $args['code']);
    $coupon->set_discount_type('fixed_cart');
    $coupon->set_amount((float) $args['amount']);
    $coupon->set_usage_limit(1);
    if (!empty($args['individual_use'])) { $coupon->set_individual_use(true); }
    if (!empty($args['email'])) { $coupon->set_email_restrictions([(string) $args['email']]); }
    if (!empty($args['expires_ts'])) { $coupon->set_date_expires((int) $args['expires_ts']); }
    if (!empty($args['description'])) { $coupon->set_description((string) $args['description']); }
    foreach ((array) ($args['meta'] ?? []) as $k => $v) {
        $coupon->update_meta_data((string) $k, $v);
    }
    $id = $coupon->save();
    if (!$id) {
        throw new \RuntimeException('coupon save returned no id');
    }
    return (int) $id;
}

/**
 * Redeem points into a single-use coupon locked to the user's email.
 * Points are subtracted FIRST (reserving them against double-spend); the
 * subtraction is rolled back if coupon creation throws.
 * Returns ['code', 'value', 'points_spent', 'balance', 'locked_to'] or WP_Error.
 *
 * @param mixed $points
 */
function wpultra_loyalty_redeem(int $user_id, $points) {
    if (!class_exists('WC_Coupon')) {
        return wpultra_err('woocommerce_inactive', 'WooCommerce coupons are not available.');
    }
    if (!function_exists('get_userdata')) {
        return wpultra_err('wp_unavailable', 'WordPress user functions are not available.');
    }
    $user = get_userdata($user_id);
    if (!$user) { return wpultra_err('user_not_found', "No user with id $user_id."); }

    $cfg = wpultra_loyalty_config();
    $balance = wpultra_loyalty_get_balance($user_id);
    $check = wpultra_loyalty_can_redeem($points, $balance, (int) $cfg['min_redeem']);
    if ($check !== true) {
        $msgs = [
            'not_integer'          => 'points must be a whole number.',
            'below_minimum'        => "points is below the minimum redemption of {$cfg['min_redeem']}.",
            'insufficient_balance' => "user only has $balance points.",
        ];
        return wpultra_err('redeem_' . $check, $msgs[$check] ?? $check);
    }
    $points = (int) $points;

    $value = wpultra_loyalty_redeem_value($points, (float) $cfg['redeem_rate']);
    if ($value <= 0) {
        return wpultra_err('redeem_zero_value', 'These points are worth 0.00 at the current redeem_rate.');
    }

    $code = wpultra_loyalty_code('pts');
    $email = (string) $user->user_email;

    // Reserve the points first, roll back if the coupon cannot be created.
    wpultra_loyalty_add_points($user_id, -$points, 'redeem', 'coupon:' . $code);
    try {
        wpultra_loyalty_make_coupon([
            'code'           => $code,
            'amount'         => $value,
            'email'          => $email,
            'individual_use' => true,
            'description'    => sprintf('WP-Ultra loyalty redemption: %d points by user #%d', $points, $user_id),
        ]);
    } catch (\Throwable $e) {
        wpultra_loyalty_add_points($user_id, $points, 'redeem-rollback', 'coupon:' . $code);
        return wpultra_err('coupon_create_failed', 'Coupon creation failed, points were restored: ' . $e->getMessage());
    }

    return [
        'code'         => $code,
        'value'        => $value,
        'points_spent' => $points,
        'balance'      => wpultra_loyalty_get_balance($user_id),
        'locked_to'    => $email,
    ];
}

/**
 * Create a gift-card coupon (single-use gift VOUCHER — consumed in full on
 * first use, no partial-redemption balance). Args: amount (> 0),
 * recipient_email (optional — locks the coupon), note (optional),
 * expires_days (optional). Returns coupon details or WP_Error.
 */
function wpultra_loyalty_gift_create(array $args) {
    if (!class_exists('WC_Coupon')) {
        return wpultra_err('woocommerce_inactive', 'WooCommerce coupons are not available.');
    }
    $amount = (float) ($args['amount'] ?? 0);
    if ($amount <= 0) { return wpultra_err('invalid_amount', 'amount must be greater than 0.'); }

    $recipient = trim((string) ($args['recipient_email'] ?? ''));
    if ($recipient !== '' && function_exists('is_email') && !is_email($recipient)) {
        return wpultra_err('invalid_email', "recipient_email is not a valid email: $recipient");
    }
    $note = (string) ($args['note'] ?? '');
    $expires_days = (int) ($args['expires_days'] ?? 0);
    $expires_ts = $expires_days > 0 ? time() + ($expires_days * 86400) : 0;

    $code = wpultra_loyalty_code('gift');
    try {
        wpultra_loyalty_make_coupon([
            'code'        => $code,
            'amount'      => round($amount, 2),
            'email'       => $recipient,
            'expires_ts'  => $expires_ts,
            'description' => 'WP-Ultra gift card' . ($recipient !== '' ? " for $recipient" : ''),
            'meta'        => [
                '_wpultra_gift_card' => 1,
                '_wpultra_gift_note' => $note,
            ],
        ]);
    } catch (\Throwable $e) {
        return wpultra_err('coupon_create_failed', 'Gift-card coupon creation failed: ' . $e->getMessage());
    }

    return [
        'code'      => $code,
        'amount'    => round($amount, 2),
        'recipient' => $recipient,
        'note'      => $note,
        'expires'   => $expires_ts > 0 ? gmdate('Y-m-d', $expires_ts) : null,
    ];
}

/**
 * Send the gift-card email to the recipient via wp_mail. Returns true or
 * WP_Error.
 */
function wpultra_loyalty_gift_send(string $recipient, array $card) {
    if (!function_exists('wp_mail')) {
        return wpultra_err('mail_unavailable', 'wp_mail is not available.');
    }
    $store = function_exists('get_bloginfo') ? (string) get_bloginfo('name') : '';
    $currency = function_exists('get_woocommerce_currency') ? (string) get_woocommerce_currency() : '';
    $html = wpultra_loyalty_gift_html([
        'amount'   => number_format((float) ($card['amount'] ?? 0), 2, '.', ''),
        'currency' => $currency,
        'code'     => (string) ($card['code'] ?? ''),
        'note'     => (string) ($card['note'] ?? ''),
        'store'    => $store,
    ]);
    $subject = ($store !== '' ? "$store — " : '') . 'You received a gift card!';
    $sent = wp_mail($recipient, $subject, $html, ['Content-Type: text/html; charset=UTF-8']);
    if (!$sent) {
        return wpultra_err('mail_failed', 'wp_mail reported failure sending the gift-card email.');
    }
    return true;
}

/**
 * List gift-card coupons (shop_coupon posts flagged _wpultra_gift_card=1 —
 * coupons are plain CPT posts, so a get_posts meta_query is correct here).
 * Returns [{code, amount, used, recipient, expires}].
 */
function wpultra_loyalty_gift_list(int $limit = 100): array {
    if (!function_exists('get_posts') || !class_exists('WC_Coupon')) { return []; }
    if ($limit < 1) { $limit = 100; }
    if ($limit > 500) { $limit = 500; }
    $posts = get_posts([
        'post_type'   => 'shop_coupon',
        'post_status' => 'any',
        'numberposts' => $limit,
        'fields'      => 'ids',
        'meta_query'  => [
            ['key' => '_wpultra_gift_card', 'value' => '1'],
        ],
    ]);
    $out = [];
    foreach ((array) $posts as $pid) {
        try {
            $c = new \WC_Coupon((int) $pid);
            if (!$c->get_id()) { continue; }
            $emails = (array) $c->get_email_restrictions();
            $expires = $c->get_date_expires();
            $out[] = [
                'code'      => (string) $c->get_code(),
                'amount'    => (float) $c->get_amount(),
                'used'      => ((int) $c->get_usage_count()) > 0,
                'recipient' => (string) ($emails[0] ?? ''),
                'expires'   => $expires ? $expires->date('Y-m-d') : null,
            ];
        } catch (\Throwable $e) {
            // Skip a broken coupon row rather than failing the whole list.
        }
    }
    return $out;
}
