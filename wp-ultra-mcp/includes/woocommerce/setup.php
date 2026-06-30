<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/** True when WooCommerce is loaded and usable. */
function wpultra_woo_active(): bool {
    return class_exists('WooCommerce') && defined('WC_VERSION');
}

/** True when High-Performance Order Storage (custom order tables) is on. */
function wpultra_woo_hpos_enabled(): bool {
    $cls = 'Automattic\\WooCommerce\\Utilities\\OrderUtil';
    if (!class_exists($cls)) { return false; }
    return (bool) call_user_func([$cls, 'custom_orders_table_usage_is_enabled']);
}

/** Snapshot of store configuration for the AI's entry point. */
function wpultra_woo_store_status(): array {
    if (!wpultra_woo_active()) {
        return ['active' => false];
    }
    $pages = [
        'shop'      => (int) wc_get_page_id('shop'),
        'cart'      => (int) wc_get_page_id('cart'),
        'checkout'  => (int) wc_get_page_id('checkout'),
        'myaccount' => (int) wc_get_page_id('myaccount'),
    ];
    $counts = [
        'products'  => (int) wp_count_posts('product')->publish,
        'orders'    => function_exists('wc_orders_count') ? (int) wc_orders_count('completed') + (int) wc_orders_count('processing') : 0,
        'customers' => (int) (function_exists('wc_get_customer_default_role') ? count_users()['avail_roles']['customer'] ?? 0 : 0),
    ];
    return [
        'active'       => true,
        'version'      => WC_VERSION,
        'hpos_enabled' => wpultra_woo_hpos_enabled(),
        'currency'     => get_woocommerce_currency(),
        'base_country' => WC()->countries ? WC()->countries->get_base_country() : '',
        'pages'        => $pages,
        'counts'       => $counts,
    ];
}
