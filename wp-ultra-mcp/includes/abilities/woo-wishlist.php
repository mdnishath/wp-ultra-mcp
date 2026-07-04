<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

// The engine ships under includes/woocommerce/wishlist.php — require it
// defensively so this ability works regardless of load order (mirrors
// woo-bulk-edit's relationship with its engine file).
if (!function_exists('wpultra_wl_add') && defined('WPULTRA_DIR') && is_readable(WPULTRA_DIR . 'includes/woocommerce/wishlist.php')) {
    require_once WPULTRA_DIR . 'includes/woocommerce/wishlist.php';
}

wp_register_ability('wpultra/woo-wishlist', [
    'label'       => __('WooCommerce: Wishlist + Back-in-stock Alerts', 'wp-ultra-mcp'),
    'description' => __(
        'Wishlist and back-in-stock alert system (no extra plugin needed). Visitors keep a wishlist (logged-in users: user meta, cap 100; guests: cookie, cap 30) and can subscribe their email to an out-of-stock product to get ONE email when it returns (per-product cap 500 subscribers; batches of 25 per cron tick). '
        . 'actions: '
        . 'config {enable: bool} = arm/disarm the front-end features (reversible, takes effect next request; the option is wpultra_wishlist_enabled). '
        . 'wishlist-get {user_id} = admin view of one user\'s wishlist with product names/prices/stock. '
        . 'wishlist-add / wishlist-remove {user_id, product_id} = manage a user\'s wishlist server-side. '
        . 'wishlist-stats = top wished products across ALL users (scans up to 1000 users with wishlist meta). '
        . 'subs-list {product_id} = back-in-stock subscriber emails + count. '
        . 'subscribe {product_id, email} / unsubscribe {product_id, email} = manage a product\'s alert list. '
        . 'notify-now {product_id, confirm:true} = force-send the next notification batch immediately regardless of stock status (confirm-gated — it emails real people; 25 per batch, any remainder is auto-rescheduled +30s). '
        . 'Front-end (once enabled): shortcode [wpultra_wishlist] renders the visitor\'s wishlist (name, price, add-to-cart, remove); [wpultra_wishlist_button id="123"] renders an add/remove toggle link (id defaults to the current product). '
        . 'Zero-JS link patterns usable in any menu/button/template: ?wpultra_wishlist_add=ID adds, ?wpultra_wishlist_remove=ID removes (both redirect back to the same page), ?add-to-cart=ID is the stock WooCommerce add-to-cart link. '
        . 'Out-of-stock single product pages automatically show a nonce-protected email subscribe form (rate-limited 10/min per IP); when stock flips to instock the notification batch schedules itself. '
        . 'Examples: {action:"config", enable:true} = turn everything on. {action:"subscribe", product_id:42, email:"x@y.com"} = add a subscriber. {action:"notify-now", product_id:42, confirm:true} = send pending alerts now. {action:"wishlist-stats"} = what do customers want most?',
        'wp-ultra-mcp'
    ),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['config', 'wishlist-get', 'wishlist-add', 'wishlist-remove', 'wishlist-stats', 'subs-list', 'subscribe', 'unsubscribe', 'notify-now'],
            ],
            'product_id' => ['type' => 'integer'],
            'user_id'    => ['type' => 'integer'],
            'email'      => ['type' => 'string'],
            'enable'     => ['type' => 'boolean'],
            'confirm'    => ['type' => 'boolean'],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'       => ['type' => 'boolean'],
            'action'        => ['type' => 'string'],
            'enabled'       => ['type' => 'boolean'],
            'shortcodes'    => ['type' => 'object'],
            'link_patterns' => ['type' => 'object'],
            'user_id'       => ['type' => 'integer'],
            'product_id'    => ['type' => 'integer'],
            'count'         => ['type' => 'integer'],
            'items'         => ['type' => 'array'],
            'emails'        => ['type' => 'array'],
            'top'           => ['type' => 'array'],
            'sent'          => ['type' => 'integer'],
            'failed'        => ['type' => 'integer'],
            'remaining'     => ['type' => 'integer'],
            'note'          => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_woo_wishlist_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

/** Shape a wishlist id-list into rows with product name/price/stock. */
function wpultra_woo_wishlist_shape_items(array $ids): array {
    $items = [];
    foreach ($ids as $id) {
        $id = (int) $id;
        $p = function_exists('wc_get_product') ? wc_get_product($id) : null;
        if (!$p) {
            $items[] = ['product_id' => $id, 'missing' => true];
            continue;
        }
        $items[] = [
            'product_id'   => $id,
            'name'         => (string) $p->get_name(),
            'price'        => (string) $p->get_price(),
            'stock_status' => (string) $p->get_stock_status(),
            'permalink'    => function_exists('get_permalink') ? (string) get_permalink($id) : '',
        ];
    }
    return $items;
}

function wpultra_woo_wishlist_cb(array $input) {
    if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    if (!function_exists('wpultra_wl_add')) {
        return wpultra_err('wishlist_engine_missing', 'The wishlist engine (includes/woocommerce/wishlist.php) is not loaded.');
    }

    $action     = (string) ($input['action'] ?? '');
    $product_id = (int) ($input['product_id'] ?? 0);
    $user_id    = (int) ($input['user_id'] ?? 0);
    $email      = (string) ($input['email'] ?? '');

    try {
        switch ($action) {

            case 'config': {
                if (!array_key_exists('enable', $input) || !is_bool($input['enable'])) {
                    return wpultra_err('missing_enable', 'config requires enable:true|false.');
                }
                $enable = $input['enable'] === true;
                update_option('wpultra_wishlist_enabled', $enable ? '1' : '0', true);
                wpultra_audit_log('woo-wishlist', 'config enabled=' . ($enable ? '1' : '0'), true);
                return wpultra_ok([
                    'action'  => 'config',
                    'enabled' => $enable,
                    'shortcodes' => [
                        '[wpultra_wishlist]'                => 'Renders the current visitor\'s wishlist (name, price, add-to-cart, remove links). Put it on a "My Wishlist" page.',
                        '[wpultra_wishlist_button id="123"]' => 'Add/remove toggle link for one product; id defaults to the current product in the loop.',
                    ],
                    'link_patterns' => [
                        'add'         => '?wpultra_wishlist_add=PRODUCT_ID',
                        'remove'      => '?wpultra_wishlist_remove=PRODUCT_ID',
                        'add_to_cart' => '?add-to-cart=PRODUCT_ID',
                    ],
                    'note' => $enable
                        ? 'Front-end features armed (takes effect on the next request): GET add/remove links, both shortcodes, the out-of-stock subscribe form, and the stock-status watcher.'
                        : 'Front-end features disarmed. Stored wishlists and subscriber lists are kept; this ability keeps working either way.',
                ]);
            }

            case 'wishlist-get': {
                if ($user_id <= 0) { return wpultra_err('missing_user_id', 'wishlist-get requires user_id.'); }
                if (function_exists('get_userdata') && !get_userdata($user_id)) {
                    return wpultra_err('user_not_found', "User #$user_id does not exist.");
                }
                $ids = wpultra_wl_get_user($user_id);
                return wpultra_ok([
                    'action'  => 'wishlist-get',
                    'user_id' => $user_id,
                    'count'   => count($ids),
                    'items'   => wpultra_woo_wishlist_shape_items($ids),
                ]);
            }

            case 'wishlist-add':
            case 'wishlist-remove': {
                if ($user_id <= 0) { return wpultra_err('missing_user_id', "$action requires user_id."); }
                if ($product_id <= 0) { return wpultra_err('missing_product_id', "$action requires product_id."); }
                if (function_exists('get_userdata') && !get_userdata($user_id)) {
                    return wpultra_err('user_not_found', "User #$user_id does not exist.");
                }
                if ($action === 'wishlist-add' && function_exists('wc_get_product') && !wc_get_product($product_id)) {
                    return wpultra_err('product_not_found', "Product #$product_id does not exist.");
                }
                $ids = wpultra_wl_get_user($user_id);
                $new = $action === 'wishlist-add'
                    ? wpultra_wl_add($ids, $product_id, wpultra_wl_cap_user())
                    : wpultra_wl_remove($ids, $product_id);
                $changed = $new !== $ids;
                if ($changed) { wpultra_wl_save_user($user_id, $new); }
                wpultra_audit_log('woo-wishlist', "$action user=$user_id product=$product_id changed=" . ($changed ? '1' : '0'), true);
                $note = '';
                if ($action === 'wishlist-add' && !$changed && !in_array($product_id, $new, true)) {
                    $note = 'Wishlist is at its cap (' . wpultra_wl_cap_user() . ') — the product was not added.';
                }
                return wpultra_ok(array_filter([
                    'action'     => $action,
                    'user_id'    => $user_id,
                    'product_id' => $product_id,
                    'count'      => count($new),
                    'items'      => wpultra_woo_wishlist_shape_items($new),
                    'note'       => $note,
                ], static fn($v) => $v !== ''));
            }

            case 'wishlist-stats': {
                if (!function_exists('get_users')) { return wpultra_err('wp_unavailable', 'get_users() is unavailable.'); }
                $user_ids = get_users(['meta_key' => 'wpultra_wishlist', 'number' => 1000, 'fields' => 'ID']);
                $lists = [];
                foreach ((array) $user_ids as $uid) {
                    $l = get_user_meta((int) $uid, 'wpultra_wishlist', true);
                    if (is_array($l) && $l !== []) { $lists[] = $l; }
                }
                $top = array_slice(wpultra_wl_top($lists), 0, 20);
                foreach ($top as &$row) {
                    $p = function_exists('wc_get_product') ? wc_get_product($row['product_id']) : null;
                    $row['name']  = $p ? (string) $p->get_name() : '(missing product)';
                    $row['price'] = $p ? (string) $p->get_price() : '';
                }
                unset($row);
                return wpultra_ok([
                    'action' => 'wishlist-stats',
                    'count'  => count($lists),
                    'top'    => $top,
                    'note'   => 'Scanned ' . count((array) $user_ids) . ' users with wishlist meta (cap 1000); top 20 shown. Guest (cookie) wishlists are not server-visible.',
                ]);
            }

            case 'subs-list': {
                if ($product_id <= 0) { return wpultra_err('missing_product_id', 'subs-list requires product_id.'); }
                $subs = wpultra_wl_subs_get($product_id);
                return wpultra_ok([
                    'action'     => 'subs-list',
                    'product_id' => $product_id,
                    'count'      => count($subs),
                    'emails'     => $subs,
                ]);
            }

            case 'subscribe': {
                if ($product_id <= 0) { return wpultra_err('missing_product_id', 'subscribe requires product_id.'); }
                if (function_exists('wc_get_product') && !wc_get_product($product_id)) {
                    return wpultra_err('product_not_found', "Product #$product_id does not exist.");
                }
                $res = wpultra_wl_subs_add(wpultra_wl_subs_get($product_id), $email, 500);
                if (!is_array($res)) {
                    $msg = [
                        'invalid_email'      => 'That email address is not valid.',
                        'already_subscribed' => 'That email is already subscribed to this product.',
                        'cap_reached'        => 'The subscriber list for this product is full (cap 500).',
                    ][$res] ?? $res;
                    return wpultra_err($res, $msg);
                }
                wpultra_wl_subs_save($product_id, $res);
                wpultra_audit_log('woo-wishlist', "subscribe product=$product_id count=" . count($res), true);
                return wpultra_ok(['action' => 'subscribe', 'product_id' => $product_id, 'count' => count($res)]);
            }

            case 'unsubscribe': {
                if ($product_id <= 0) { return wpultra_err('missing_product_id', 'unsubscribe requires product_id.'); }
                if (trim($email) === '') { return wpultra_err('missing_email', 'unsubscribe requires email.'); }
                $before = wpultra_wl_subs_get($product_id);
                $after  = wpultra_wl_subs_remove($before, $email);
                $removed = count($after) < count($before);
                if ($removed) { wpultra_wl_subs_save($product_id, $after); }
                wpultra_audit_log('woo-wishlist', "unsubscribe product=$product_id removed=" . ($removed ? '1' : '0'), true);
                return wpultra_ok([
                    'action'     => 'unsubscribe',
                    'product_id' => $product_id,
                    'count'      => count($after),
                    'note'       => $removed ? 'Email removed.' : 'Email was not on the list.',
                ]);
            }

            case 'notify-now': {
                if ($product_id <= 0) { return wpultra_err('missing_product_id', 'notify-now requires product_id.'); }
                if (($input['confirm'] ?? false) !== true) {
                    return wpultra_err('notify_unconfirmed', 'notify-now sends real emails to every pending subscriber. Re-run with confirm:true.');
                }
                if (function_exists('wc_get_product') && !wc_get_product($product_id)) {
                    return wpultra_err('product_not_found', "Product #$product_id does not exist.");
                }
                $res = wpultra_wl_notify_run($product_id);
                wpultra_audit_log('woo-wishlist', "notify-now product=$product_id sent={$res['sent']} failed={$res['failed']} remaining={$res['remaining']}", true);
                return wpultra_ok([
                    'action'     => 'notify-now',
                    'product_id' => $product_id,
                    'sent'       => $res['sent'],
                    'failed'     => $res['failed'],
                    'remaining'  => $res['remaining'],
                    'note'       => $res['remaining'] > 0
                        ? 'Batch of 25 sent; the remainder is scheduled to continue automatically in ~30s (WP-Cron).'
                        : 'All pending subscribers processed; the list is now clear.',
                ]);
            }

            default:
                return wpultra_err('unknown_action', "Unknown action: $action. Use config|wishlist-get|wishlist-add|wishlist-remove|wishlist-stats|subs-list|subscribe|unsubscribe|notify-now.");
        }
    } catch (\Throwable $e) {
        wpultra_audit_log('woo-wishlist', "$action threw: " . $e->getMessage(), false);
        return wpultra_err('wishlist_failed', 'woo-wishlist failed: ' . $e->getMessage());
    }
}
