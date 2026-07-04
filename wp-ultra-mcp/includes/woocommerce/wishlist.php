<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Wishlist + back-in-stock subscriptions (roadmap B4).
 *
 * Storage:
 *  - logged-in visitors: user_meta 'wpultra_wishlist' = int[] product ids (cap 100)
 *  - guests:             cookie   'wpultra_wl'        = comma-separated ids (cap 30)
 *  - back-in-stock subs: product post meta '_wpultra_stock_subs' = string[] emails (cap 500)
 *
 * The list/email/template/analytics operations (prefix wpultra_wl_) are PURE —
 * no WordPress or WooCommerce dependency — so they are unit-testable via
 * tests/woo-wishlist.test.php. The WP/WC wrappers and the front-end runtime
 * live below the PURE section and guard every WP call.
 *
 * Runtime contract: this file defines wpultra_wishlist_boot() (cheap +
 * idempotent); the controller calls it on plugins_loaded. Front-end features
 * (GET add/remove links, shortcodes, subscribe form, stock-status watcher)
 * only arm when the autoloaded option wpultra_wishlist_enabled === '1'.
 * The wpultra_wl_notify_event handler registers unconditionally so scheduled
 * notification batches (and the notify-now ability) always run. CPT-free —
 * the wpultra/woo-wishlist ability works regardless of the toggle.
 */

// ---------------------------------------------------------------------------
// PURE: wishlist id-list operations
// ---------------------------------------------------------------------------

/**
 * Sanitize a candidate id list: keep positive integers (accepts numeric
 * strings of digits and integral floats), dedupe preserving order, cap the
 * result when $cap > 0. Pure.
 */
function wpultra_wl_sanitize_ids(array $ids, int $cap = 0): array {
    $out = [];
    foreach ($ids as $v) {
        if (is_int($v)) {
            $n = $v;
        } elseif (is_string($v) && preg_match('/^\s*\d+\s*$/', $v)) {
            $n = (int) trim($v);
        } elseif (is_float($v) && $v === (float) (int) $v) {
            $n = (int) $v;
        } else {
            continue;
        }
        if ($n <= 0 || in_array($n, $out, true)) { continue; }
        $out[] = $n;
        if ($cap > 0 && count($out) >= $cap) { break; }
    }
    return $out;
}

/**
 * Add $pid to a wishlist. Sanitizes the incoming list, dedupes, and silently
 * drops the add when the list is already at $cap. Pure.
 */
function wpultra_wl_add(array $ids, int $pid, int $cap): array {
    $ids = wpultra_wl_sanitize_ids($ids, $cap);
    if ($pid <= 0 || in_array($pid, $ids, true)) { return $ids; }
    if ($cap > 0 && count($ids) >= $cap) { return $ids; }
    $ids[] = $pid;
    return $ids;
}

/** Remove $pid from a wishlist (sanitizing on the way through). Pure. */
function wpultra_wl_remove(array $ids, int $pid): array {
    $out = [];
    foreach (wpultra_wl_sanitize_ids($ids) as $n) {
        if ($n !== $pid) { $out[] = $n; }
    }
    return $out;
}

/**
 * Parse the guest-wishlist cookie value ("12,34,56"). Garbage tokens are
 * dropped, ids deduped, result capped at $cap. Pure.
 */
function wpultra_wl_parse_cookie(string $raw, int $cap): array {
    if (trim($raw) === '') { return []; }
    return wpultra_wl_sanitize_ids(explode(',', $raw), $cap);
}

/** Serialize a wishlist back to the cookie format. Pure. */
function wpultra_wl_to_cookie(array $ids): string {
    return implode(',', wpultra_wl_sanitize_ids($ids));
}

// ---------------------------------------------------------------------------
// PURE: back-in-stock subscriber lists
// ---------------------------------------------------------------------------

/** Sanitize a subscriber list: valid lowercase emails, deduped, capped. Pure. */
function wpultra_wl_subs_sanitize(array $subs, int $cap = 0): array {
    $out = [];
    foreach ($subs as $e) {
        if (!is_string($e)) { continue; }
        $e = strtolower(trim($e));
        if ($e === '' || filter_var($e, FILTER_VALIDATE_EMAIL) === false) { continue; }
        if (in_array($e, $out, true)) { continue; }
        $out[] = $e;
        if ($cap > 0 && count($out) >= $cap) { break; }
    }
    return $out;
}

/**
 * Add an email to a subscriber list. Returns the updated list on success, or
 * an error string: 'invalid_email' | 'already_subscribed' | 'cap_reached'.
 * Emails are lowercased so the dupe check is case-insensitive. Pure.
 *
 * @return array|string
 */
function wpultra_wl_subs_add(array $subs, string $email, int $cap) {
    $email = strtolower(trim($email));
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) { return 'invalid_email'; }
    $subs = wpultra_wl_subs_sanitize($subs, $cap);
    if (in_array($email, $subs, true)) { return 'already_subscribed'; }
    if ($cap > 0 && count($subs) >= $cap) { return 'cap_reached'; }
    $subs[] = $email;
    return $subs;
}

/** Remove an email (case-insensitive) from a subscriber list. Pure. */
function wpultra_wl_subs_remove(array $subs, string $email): array {
    $email = strtolower(trim($email));
    $out = [];
    foreach (wpultra_wl_subs_sanitize($subs) as $e) {
        if ($e !== $email) { $out[] = $e; }
    }
    return $out;
}

// ---------------------------------------------------------------------------
// PURE: notification email template
// ---------------------------------------------------------------------------

/** HTML-escape for both text and attribute contexts (ENT_QUOTES). Pure. */
function wpultra_wl_esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/**
 * Render the back-in-stock notification email body from
 * ['name' =>, 'price' =>, 'url' =>, 'store' =>]. Every value is escaped —
 * a product named "<script>…" comes out inert. Pure.
 */
function wpultra_wl_notify_html(array $d): string {
    $name  = wpultra_wl_esc((string) ($d['name'] ?? ''));
    $price = wpultra_wl_esc((string) ($d['price'] ?? ''));
    $url   = wpultra_wl_esc((string) ($d['url'] ?? ''));
    $store = wpultra_wl_esc((string) ($d['store'] ?? ''));

    $html  = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:0 auto;padding:16px;">';
    $html .= '<h2 style="margin:0 0 12px;">' . $name . ' is back in stock!</h2>';
    if ($price !== '') {
        $html .= '<p style="margin:0 0 12px;">Price: <strong>' . $price . '</strong></p>';
    }
    if ($url !== '') {
        $html .= '<p style="margin:0 0 16px;"><a href="' . $url . '" style="background:#2271b1;color:#ffffff;padding:10px 18px;text-decoration:none;border-radius:4px;display:inline-block;">View product</a></p>';
    }
    $html .= '<p style="color:#888888;font-size:12px;margin:16px 0 0;">You asked ' . ($store !== '' ? $store : 'this store') . ' to email you when this product became available again.</p>';
    $html .= '</div>';
    return $html;
}

// ---------------------------------------------------------------------------
// PURE: analytics
// ---------------------------------------------------------------------------

/**
 * Given a list of wishlists (each an id-array), return the most-wished
 * products as [['product_id' => int, 'count' => int], …] sorted by count
 * descending, ties broken by product_id ascending. Each wishlist counts a
 * product at most once. Pure.
 */
function wpultra_wl_top(array $wishlists): array {
    $counts = [];
    foreach ($wishlists as $list) {
        if (!is_array($list)) { continue; }
        foreach (wpultra_wl_sanitize_ids($list) as $id) {
            $counts[$id] = ($counts[$id] ?? 0) + 1;
        }
    }
    $rows = [];
    foreach ($counts as $id => $c) {
        $rows[] = ['product_id' => (int) $id, 'count' => (int) $c];
    }
    usort($rows, static function (array $a, array $b): int {
        if ($a['count'] !== $b['count']) { return $b['count'] <=> $a['count']; }
        return $a['product_id'] <=> $b['product_id'];
    });
    return $rows;
}

// ---------------------------------------------------------------------------
// WP wrappers: storage (every WP call guarded — harness-safe)
// ---------------------------------------------------------------------------

/** Guest wishlist cap (cookie) and logged-in cap (user meta). */
function wpultra_wl_cap_guest(): int { return 30; }
function wpultra_wl_cap_user(): int { return 100; }

/** Read one user's wishlist from user meta. */
function wpultra_wl_get_user(int $user_id): array {
    if ($user_id <= 0 || !function_exists('get_user_meta')) { return []; }
    $v = get_user_meta($user_id, 'wpultra_wishlist', true);
    return is_array($v) ? wpultra_wl_sanitize_ids($v, wpultra_wl_cap_user()) : [];
}

/** Persist one user's wishlist to user meta. */
function wpultra_wl_save_user(int $user_id, array $ids): void {
    if ($user_id <= 0 || !function_exists('update_user_meta')) { return; }
    update_user_meta($user_id, 'wpultra_wishlist', wpultra_wl_sanitize_ids($ids, wpultra_wl_cap_user()));
}

/** Current visitor's wishlist: user meta when logged in, cookie otherwise. */
function wpultra_wl_get_current(): array {
    if (function_exists('is_user_logged_in') && is_user_logged_in() && function_exists('get_current_user_id')) {
        return wpultra_wl_get_user((int) get_current_user_id());
    }
    $raw = isset($_COOKIE['wpultra_wl']) ? (string) $_COOKIE['wpultra_wl'] : '';
    return wpultra_wl_parse_cookie($raw, wpultra_wl_cap_guest());
}

/** Persist the current visitor's wishlist (user meta or cookie). */
function wpultra_wl_save_current(array $ids): void {
    if (function_exists('is_user_logged_in') && is_user_logged_in() && function_exists('get_current_user_id')) {
        wpultra_wl_save_user((int) get_current_user_id(), $ids);
        return;
    }
    $value = wpultra_wl_to_cookie(wpultra_wl_sanitize_ids($ids, wpultra_wl_cap_guest()));
    if (headers_sent()) { return; }
    setcookie('wpultra_wl', $value, [
        'expires'  => time() + 30 * 86400,
        'path'     => (defined('COOKIEPATH') && COOKIEPATH) ? COOKIEPATH : '/',
        'domain'   => defined('COOKIE_DOMAIN') ? (string) COOKIE_DOMAIN : '',
        'secure'   => function_exists('is_ssl') ? is_ssl() : false,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE['wpultra_wl'] = $value; // visible to same-request reads
}

/** Read a product's back-in-stock subscriber list from post meta. */
function wpultra_wl_subs_get(int $product_id): array {
    if ($product_id <= 0 || !function_exists('get_post_meta')) { return []; }
    $v = get_post_meta($product_id, '_wpultra_stock_subs', true);
    return is_array($v) ? wpultra_wl_subs_sanitize($v, 500) : [];
}

/** Persist a product's subscriber list (deletes the meta when empty). */
function wpultra_wl_subs_save(int $product_id, array $subs): void {
    if ($product_id <= 0 || !function_exists('update_post_meta')) { return; }
    $subs = wpultra_wl_subs_sanitize($subs, 500);
    if ($subs === []) {
        if (function_exists('delete_post_meta')) { delete_post_meta($product_id, '_wpultra_stock_subs'); }
        return;
    }
    update_post_meta($product_id, '_wpultra_stock_subs', $subs);
}

// ---------------------------------------------------------------------------
// Runtime: boot + front-end hooks. Never throws.
// ---------------------------------------------------------------------------

/**
 * Boot the wishlist runtime. Idempotent; the controller calls this on
 * plugins_loaded. The notify-event handler always registers (scheduled
 * batches must complete even if the toggle flips off mid-run); everything
 * front-facing arms only when wpultra_wishlist_enabled === '1'.
 */
function wpultra_wishlist_boot(): void {
    static $booted = false;
    if ($booted || !function_exists('add_action')) { return; }
    $booted = true;

    add_action('wpultra_wl_notify_event', 'wpultra_wl_notify_event_handler', 10, 1);

    if (!function_exists('get_option') || get_option('wpultra_wishlist_enabled') !== '1') { return; }

    add_action('init', 'wpultra_wl_handle_get', 20);
    add_action('template_redirect', 'wpultra_wl_handle_sub_post', 5);
    if (function_exists('add_shortcode')) {
        add_shortcode('wpultra_wishlist', 'wpultra_wl_shortcode_list');
        add_shortcode('wpultra_wishlist_button', 'wpultra_wl_shortcode_button');
    }
    add_action('woocommerce_single_product_summary', 'wpultra_wl_render_sub_form', 35);
    add_action('woocommerce_product_set_stock_status', 'wpultra_wl_on_stock_status', 10, 3);
}

/** True when this request is a normal front-end page view. */
function wpultra_wl_is_frontend(): bool {
    if (PHP_SAPI === 'cli') { return false; }
    if (function_exists('is_admin') && is_admin()) { return false; }
    if (function_exists('wp_doing_cron') && wp_doing_cron()) { return false; }
    if (defined('REST_REQUEST') && REST_REQUEST) { return false; }
    if (defined('DOING_AJAX') && DOING_AJAX) { return false; }
    return true;
}

/**
 * Zero-JS add/remove links: ?wpultra_wishlist_add=ID / ?wpultra_wishlist_remove=ID
 * on any front-end URL update the visitor's wishlist, then redirect back to
 * the same URL with the param stripped.
 */
function wpultra_wl_handle_get(): void {
    try {
        if (!wpultra_wl_is_frontend()) { return; }
        $add = isset($_GET['wpultra_wishlist_add']) ? (int) $_GET['wpultra_wishlist_add'] : 0;
        $rem = isset($_GET['wpultra_wishlist_remove']) ? (int) $_GET['wpultra_wishlist_remove'] : 0;
        if ($add <= 0 && $rem <= 0) { return; }

        $cap = (function_exists('is_user_logged_in') && is_user_logged_in()) ? wpultra_wl_cap_user() : wpultra_wl_cap_guest();
        $ids = wpultra_wl_get_current();
        if ($add > 0) { $ids = wpultra_wl_add($ids, $add, $cap); }
        if ($rem > 0) { $ids = wpultra_wl_remove($ids, $rem); }
        wpultra_wl_save_current($ids);

        if (function_exists('wp_safe_redirect') && function_exists('remove_query_arg')) {
            wp_safe_redirect(remove_query_arg(['wpultra_wishlist_add', 'wpultra_wishlist_remove']));
            exit;
        }
    } catch (\Throwable $e) {
        // Never break a front-end page.
    }
}

/** esc_url when WordPress is loaded, attribute-escape fallback otherwise. */
function wpultra_wl_esc_url(string $url): string {
    return function_exists('esc_url') ? (string) esc_url($url) : wpultra_wl_esc($url);
}

/** [wpultra_wishlist] — render the current visitor's wishlist. All escaped. */
function wpultra_wl_shortcode_list($atts = [], $content = '', $tag = ''): string {
    try {
        $ids = wpultra_wl_get_current();
        if ($ids === []) {
            return '<div class="wpultra-wishlist wpultra-wishlist-empty">'
                . wpultra_wl_esc(__('Your wishlist is empty.', 'wp-ultra-mcp')) . '</div>';
        }
        if (!function_exists('wc_get_product')) { return ''; }

        $out = '<div class="wpultra-wishlist"><ul class="wpultra-wishlist-items" style="list-style:none;padding:0;">';
        foreach ($ids as $id) {
            $p = wc_get_product($id);
            if (!$p) { continue; }
            $name      = wpultra_wl_esc((string) $p->get_name());
            $permalink = wpultra_wl_esc_url((string) (function_exists('get_permalink') ? get_permalink($id) : ''));
            $price     = (string) $p->get_price_html(); // WC returns escaped HTML
            $remove    = function_exists('add_query_arg') ? wpultra_wl_esc_url((string) add_query_arg('wpultra_wishlist_remove', $id)) : '';
            $cart      = function_exists('add_query_arg') ? wpultra_wl_esc_url((string) add_query_arg('add-to-cart', $id)) : '';

            $out .= '<li class="wpultra-wishlist-item" style="margin:0 0 10px;padding:8px 0;border-bottom:1px solid #eee;">'
                . '<a href="' . $permalink . '" class="wpultra-wishlist-name">' . $name . '</a> '
                . '<span class="wpultra-wishlist-price">' . $price . '</span> '
                . ($cart !== '' ? '<a href="' . $cart . '" class="wpultra-wishlist-cart button">' . wpultra_wl_esc(__('Add to cart', 'wp-ultra-mcp')) . '</a> ' : '')
                . ($remove !== '' ? '<a href="' . $remove . '" class="wpultra-wishlist-remove" style="color:#b32d2e;">' . wpultra_wl_esc(__('Remove', 'wp-ultra-mcp')) . '</a>' : '')
                . '</li>';
        }
        $out .= '</ul></div>';
        return $out;
    } catch (\Throwable $e) {
        return '';
    }
}

/**
 * [wpultra_wishlist_button id="123"] — add/remove toggle link for one product
 * (defaults to the current product in the loop).
 */
function wpultra_wl_shortcode_button($atts = [], $content = '', $tag = ''): string {
    try {
        $atts = is_array($atts) ? $atts : [];
        $pid  = isset($atts['id']) ? (int) $atts['id'] : 0;
        if ($pid <= 0) {
            global $product;
            if (is_object($product) && method_exists($product, 'get_id')) {
                $pid = (int) $product->get_id();
            } elseif (function_exists('get_the_ID')) {
                $pid = (int) get_the_ID();
            }
        }
        if ($pid <= 0 || !function_exists('add_query_arg')) { return ''; }

        $in = in_array($pid, wpultra_wl_get_current(), true);
        if ($in) {
            $url  = wpultra_wl_esc_url((string) add_query_arg('wpultra_wishlist_remove', $pid));
            $text = wpultra_wl_esc(__('Remove from wishlist', 'wp-ultra-mcp'));
            $cls  = 'wpultra-wishlist-button wpultra-wishlist-button-remove';
        } else {
            $url  = wpultra_wl_esc_url((string) add_query_arg('wpultra_wishlist_add', $pid));
            $text = wpultra_wl_esc(__('Add to wishlist', 'wp-ultra-mcp'));
            $cls  = 'wpultra-wishlist-button wpultra-wishlist-button-add';
        }
        return '<a href="' . $url . '" class="' . $cls . '">' . ($in ? '&#10084; ' : '&#9825; ') . $text . '</a>';
    } catch (\Throwable $e) {
        return '';
    }
}

// ---------------------------------------------------------------------------
// Runtime: back-in-stock subscribe form + POST handler
// ---------------------------------------------------------------------------

/** Human messages for the ?wpultra_stock_sub_done= confirmation codes. */
function wpultra_wl_sub_messages(): array {
    return [
        'ok'                 => __('Thanks! We will email you when this product is back in stock.', 'wp-ultra-mcp'),
        'invalid_email'      => __('That email address does not look valid — please try again.', 'wp-ultra-mcp'),
        'already_subscribed' => __('You are already on the list for this product.', 'wp-ultra-mcp'),
        'cap_reached'        => __('The notification list for this product is full.', 'wp-ultra-mcp'),
        'rate_limited'       => __('Too many attempts — please wait a minute and try again.', 'wp-ultra-mcp'),
    ];
}

/** Subscribe form on out-of-stock single product pages (summary prio 35). */
function wpultra_wl_render_sub_form(): void {
    try {
        global $product;
        if (!is_object($product) || !method_exists($product, 'is_in_stock') || !method_exists($product, 'get_id')) { return; }
        if ($product->is_in_stock()) { return; }
        $pid = (int) $product->get_id();
        if ($pid <= 0) { return; }

        $done = isset($_GET['wpultra_stock_sub_done']) ? (string) $_GET['wpultra_stock_sub_done'] : '';
        $msgs = wpultra_wl_sub_messages();

        echo '<div class="wpultra-stock-sub" style="margin:1em 0;padding:12px;border:1px solid #ddd;border-radius:4px;">';
        if ($done !== '' && isset($msgs[$done])) {
            echo '<p class="wpultra-stock-sub-msg" style="margin:0 0 8px;font-weight:600;">' . wpultra_wl_esc((string) $msgs[$done]) . '</p>';
        }
        if ($done !== 'ok') {
            echo '<form method="post" class="wpultra-stock-sub-form">'
                . '<p style="margin:0 0 8px;">' . wpultra_wl_esc(__('Out of stock? Get one email when it is back:', 'wp-ultra-mcp')) . '</p>'
                . '<input type="email" name="wpultra_stock_sub_email" required placeholder="you@example.com" style="max-width:260px;" /> '
                . '<input type="hidden" name="wpultra_stock_sub" value="1" />'
                . '<input type="hidden" name="wpultra_stock_sub_pid" value="' . $pid . '" />';
            if (function_exists('wp_nonce_field')) {
                wp_nonce_field('wpultra_stock_sub_' . $pid, 'wpultra_stock_sub_nonce');
            }
            echo '<button type="submit" class="button">' . wpultra_wl_esc(__('Notify me', 'wp-ultra-mcp')) . '</button></form>';
        }
        echo '</div>';
    } catch (\Throwable $e) {
        // Never break a product page.
    }
}

/** Per-IP rate limit via transient: at most $limit attempts per minute. */
function wpultra_wl_rate_ok(string $ip, int $limit = 10): bool {
    if (!function_exists('get_transient') || !function_exists('set_transient')) { return true; }
    $key = 'wpultra_wl_rl_' . md5($ip);
    $n = (int) get_transient($key);
    if ($n >= $limit) { return false; }
    set_transient($key, $n + 1, 60);
    return true;
}

/** Handle the subscribe POST on template_redirect: nonce + rate limit + add. */
function wpultra_wl_handle_sub_post(): void {
    try {
        if (!wpultra_wl_is_frontend()) { return; }
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') { return; }
        if (empty($_POST['wpultra_stock_sub'])) { return; }
        $pid = (int) ($_POST['wpultra_stock_sub_pid'] ?? 0);
        if ($pid <= 0) { return; }

        $nonce = (string) ($_POST['wpultra_stock_sub_nonce'] ?? '');
        if (!function_exists('wp_verify_nonce') || !wp_verify_nonce($nonce, 'wpultra_stock_sub_' . $pid)) { return; }

        $code = 'ok';
        if (!wpultra_wl_rate_ok((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 10)) {
            $code = 'rate_limited';
        } else {
            $email = (string) ($_POST['wpultra_stock_sub_email'] ?? '');
            $res = wpultra_wl_subs_add(wpultra_wl_subs_get($pid), $email, 500);
            if (is_array($res)) { wpultra_wl_subs_save($pid, $res); } else { $code = $res; }
        }

        if (function_exists('wp_safe_redirect') && function_exists('add_query_arg') && function_exists('remove_query_arg')) {
            wp_safe_redirect(add_query_arg('wpultra_stock_sub_done', $code, remove_query_arg('wpultra_stock_sub_done')));
            exit;
        }
    } catch (\Throwable $e) {
        // Never break a front-end request.
    }
}

// ---------------------------------------------------------------------------
// Runtime: back-in-stock notifications
// ---------------------------------------------------------------------------

/**
 * woocommerce_product_set_stock_status listener: when a product with pending
 * subscribers flips to instock, schedule one notify batch shortly after.
 *
 * @param int|mixed    $product_id
 * @param string|mixed $stock_status
 * @param mixed        $product
 */
function wpultra_wl_on_stock_status($product_id, $stock_status = '', $product = null): void {
    try {
        if ((string) $stock_status !== 'instock') { return; }
        $pid = (int) $product_id;
        if ($pid <= 0) { return; }
        if (wpultra_wl_subs_get($pid) === []) { return; }
        if (!function_exists('wp_schedule_single_event')) { return; }
        if (function_exists('wp_next_scheduled') && wp_next_scheduled('wpultra_wl_notify_event', [$pid])) { return; }
        wp_schedule_single_event(time() + 2, 'wpultra_wl_notify_event', [$pid]);
    } catch (\Throwable $e) {
        // Never break a product save.
    }
}

/** Cron-event wrapper around one notify batch. Never throws. */
function wpultra_wl_notify_event_handler($product_id): void {
    try {
        wpultra_wl_notify_run((int) $product_id);
    } catch (\Throwable $e) {
        // Swallow — a broken batch must not poison WP-Cron.
    }
}

/**
 * Send one batch of back-in-stock emails for a product: up to $batch mails
 * per tick, processed subscribers are cleared from the meta, and the
 * remainder is rescheduled +30s. Returns ['sent', 'failed', 'remaining'].
 */
function wpultra_wl_notify_run(int $product_id, int $batch = 25): array {
    $res = ['sent' => 0, 'failed' => 0, 'remaining' => 0];
    try {
        if ($product_id <= 0) { return $res; }
        $subs = wpultra_wl_subs_get($product_id);
        if ($subs === []) { return $res; }
        if (!function_exists('wc_get_product') || !function_exists('wp_mail')) {
            $res['remaining'] = count($subs);
            return $res;
        }
        $product = wc_get_product($product_id);
        if (!$product) {
            // Product is gone — drop the stale subscriber list.
            wpultra_wl_subs_save($product_id, []);
            return $res;
        }

        $store = function_exists('get_bloginfo') ? (string) get_bloginfo('name') : '';
        $price = '';
        try {
            $price = trim(html_entity_decode(strip_tags((string) $product->get_price_html()), ENT_QUOTES, 'UTF-8'));
        } catch (\Throwable $e) {
            // price stays ''
        }
        $data = [
            'name'  => (string) $product->get_name(),
            'price' => $price,
            'url'   => function_exists('get_permalink') ? (string) get_permalink($product_id) : '',
            'store' => $store,
        ];
        $html    = wpultra_wl_notify_html($data);
        $subject = $data['name'] . ' is back in stock' . ($store !== '' ? ' at ' . $store : '');
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        $tick = array_slice($subs, 0, max(1, $batch));
        $rest = array_slice($subs, count($tick));

        foreach ($tick as $email) {
            $ok = false;
            try { $ok = (bool) wp_mail($email, $subject, $html, $headers); } catch (\Throwable $e) { $ok = false; }
            if ($ok) { $res['sent']++; } else { $res['failed']++; }
        }

        // Processed (sent OR failed) subscribers are cleared so a permanently
        // bouncing address can never wedge the queue in a retry loop.
        wpultra_wl_subs_save($product_id, $rest);
        $res['remaining'] = count($rest);

        if ($rest !== [] && function_exists('wp_schedule_single_event')) {
            wp_schedule_single_event(time() + 30, 'wpultra_wl_notify_event', [$product_id]);
        }
    } catch (\Throwable $e) {
        // Never fatal.
    }
    return $res;
}
