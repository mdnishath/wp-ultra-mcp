<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * WooCommerce order fulfillment engine (roadmap B2).
 *
 * Tracking numbers, a custom 'shipped' order status with a safe transition
 * map, print-ready packing slips (HTML — the user prints to PDF from the
 * browser), and customer shipping notifications.
 *
 * PURE functions (prefix wpultra_fulfill_, no WordPress/WooCommerce calls)
 * come first and are unit-tested in tests/woo-fulfillment.test.php. Runtime
 * wrappers (WC order access, wp_mail, hooks) follow and guard every WP/WC
 * call.
 *
 * HPOS NOTE: this store runs High-Performance Order Storage (custom order
 * tables). ALL order meta goes through the order object —
 * $order->get_meta() / $order->update_meta_data() + $order->save().
 * NEVER get_post_meta()/update_post_meta() on an order id.
 *
 * Tracking meta shape (order meta `_wpultra_tracking`):
 *   ['carrier' => str, 'number' => str, 'url' => str,
 *    'shipped_at' => int unix (0 = not stamped), 'notified' => bool]
 */

// ---------------------------------------------------------------------------
// PURE: escaping
// ---------------------------------------------------------------------------

/** Tiny pure HTML escaper (usable in text nodes and double-quoted attributes). */
function wpultra_fulfill_esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ---------------------------------------------------------------------------
// PURE: carriers + tracking URLs
// ---------------------------------------------------------------------------

/**
 * Supported carriers → ['label' => display name, 'url_template' => template
 * with a {number} placeholder]. 'custom' has an empty template — the caller
 * supplies url_template (must contain {number}). Pure.
 */
function wpultra_fulfill_carriers(): array {
    return [
        'ups'        => ['label' => 'UPS',        'url_template' => 'https://www.ups.com/track?tracknum={number}'],
        'fedex'      => ['label' => 'FedEx',      'url_template' => 'https://www.fedex.com/fedextrack/?trknbr={number}'],
        'dhl'        => ['label' => 'DHL',        'url_template' => 'https://www.dhl.com/en/express/tracking.html?AWB={number}'],
        'usps'       => ['label' => 'USPS',       'url_template' => 'https://tools.usps.com/go/TrackConfirmAction?tLabels={number}'],
        'royal-mail' => ['label' => 'Royal Mail', 'url_template' => 'https://www.royalmail.com/track-your-item#/tracking-results/{number}'],
        'tnt'        => ['label' => 'TNT',        'url_template' => 'https://www.tnt.com/express/en_us/site/tracking.html?searchType=con&cons={number}'],
        'aramex'     => ['label' => 'Aramex',     'url_template' => 'https://www.aramex.com/us/en/track/track-results-new?ShipmentNumber={number}'],
        'pathao'     => ['label' => 'Pathao',     'url_template' => 'https://parcel.pathao.com/tracking?consignment_id={number}'],
        'steadfast'  => ['label' => 'Steadfast',  'url_template' => 'https://steadfast.com.bd/t/{number}'],
        'redx'       => ['label' => 'RedX',       'url_template' => 'https://redx.com.bd/track-global-parcel/?trackingId={number}'],
        'custom'     => ['label' => 'Custom',     'url_template' => ''],
    ];
}

/**
 * Resolve a tracking URL for a carrier + number. The number is
 * rawurlencode()d into the template. 'custom' requires $custom_template
 * containing '{number}'. Unknown carrier / empty number / bad custom
 * template → ''. Pure.
 */
function wpultra_fulfill_tracking_url(string $carrier, string $number, string $custom_template = ''): string {
    $number = trim($number);
    if ($number === '') { return ''; }

    $carriers = wpultra_fulfill_carriers();
    if (!isset($carriers[$carrier])) { return ''; }

    $template = $carrier === 'custom' ? trim($custom_template) : $carriers[$carrier]['url_template'];
    if ($template === '' || !str_contains($template, '{number}')) { return ''; }

    return str_replace('{number}', rawurlencode($number), $template);
}

// ---------------------------------------------------------------------------
// PURE: status workflow
// ---------------------------------------------------------------------------

/**
 * Sane fulfillment workflow map: status → statuses it may move to.
 * This is a SAFETY RAIL, not a Woo restriction — WooCommerce itself allows
 * any transition; the ability enforces this map by default and bypasses it
 * with force:true. Statuses are un-prefixed (no 'wc-'). Pure.
 */
function wpultra_fulfill_allowed_transitions(): array {
    return [
        'pending'    => ['processing', 'cancelled', 'on-hold'],
        'on-hold'    => ['processing', 'cancelled'],
        'processing' => ['shipped', 'completed', 'cancelled', 'refunded'],
        'shipped'    => ['completed', 'refunded'],
        'completed'  => ['refunded'],
    ];
}

/**
 * True when $from → $to is allowed by the workflow map. 'wc-' prefixes are
 * stripped first. Unknown $from or $to not in the map → false. Pure.
 */
function wpultra_fulfill_can_transition(string $from, string $to): bool {
    $from = str_starts_with($from, 'wc-') ? substr($from, 3) : $from;
    $to   = str_starts_with($to, 'wc-') ? substr($to, 3) : $to;
    $map = wpultra_fulfill_allowed_transitions();
    if (!isset($map[$from])) { return false; }
    return in_array($to, $map[$from], true);
}

/**
 * Insert 'wc-shipped' => 'Shipped' into a Woo order-status map, right after
 * 'wc-processing' (appended at the end when processing is absent). Idempotent.
 * Used as the 'wc_order_statuses' filter body. Pure.
 */
function wpultra_fulfill_insert_shipped_status(array $statuses): array {
    if (isset($statuses['wc-shipped'])) { return $statuses; }
    $out = [];
    $inserted = false;
    foreach ($statuses as $key => $label) {
        $out[$key] = $label;
        if ($key === 'wc-processing') {
            $out['wc-shipped'] = 'Shipped';
            $inserted = true;
        }
    }
    if (!$inserted) { $out['wc-shipped'] = 'Shipped'; }
    return $out;
}

// ---------------------------------------------------------------------------
// PURE: packing slip HTML (print-ready — user prints to PDF from the browser)
// ---------------------------------------------------------------------------

/**
 * Render ONE order's packing-slip section (no <html> wrapper). $data shape:
 *   store:  {name, address}
 *   order:  {number, date, shipping_name, shipping_address_lines: [],
 *            billing_email, items: [{name, sku, qty}], customer_note,
 *            tracking: {carrier, number}|null}
 * ALL values are escaped. No prices — a packing slip is a pick list. Pure.
 */
function wpultra_fulfill_packing_slip_section(array $data): string {
    $store = is_array($data['store'] ?? null) ? $data['store'] : [];
    $order = is_array($data['order'] ?? null) ? $data['order'] : [];
    $e = 'wpultra_fulfill_esc';

    $store_name = $e((string) ($store['name'] ?? ''));
    $store_addr = $e((string) ($store['address'] ?? ''));

    $number = $e((string) ($order['number'] ?? ''));
    $date   = $e((string) ($order['date'] ?? ''));
    $ship_name = $e((string) ($order['shipping_name'] ?? ''));
    $email  = $e((string) ($order['billing_email'] ?? ''));
    $note   = $e((string) ($order['customer_note'] ?? ''));

    $addr_lines = [];
    foreach ((array) ($order['shipping_address_lines'] ?? []) as $line) {
        $line = trim((string) $line);
        if ($line !== '') { $addr_lines[] = $e($line); }
    }

    $rows = '';
    $total_qty = 0;
    foreach ((array) ($order['items'] ?? []) as $item) {
        if (!is_array($item)) { continue; }
        $qty = (int) ($item['qty'] ?? 0);
        $total_qty += $qty;
        $rows .= '<tr>'
            . '<td style="padding:6px 8px;border-bottom:1px solid #ddd;">' . $e((string) ($item['name'] ?? '')) . '</td>'
            . '<td style="padding:6px 8px;border-bottom:1px solid #ddd;">' . $e((string) ($item['sku'] ?? '')) . '</td>'
            . '<td style="padding:6px 8px;border-bottom:1px solid #ddd;text-align:right;">' . $qty . '</td>'
            . '</tr>';
    }

    $tracking_html = '';
    $tracking = $order['tracking'] ?? null;
    if (is_array($tracking) && trim((string) ($tracking['number'] ?? '')) !== '') {
        $tracking_html = '<p style="margin:12px 0 0;font-size:13px;">'
            . '<strong>Tracking:</strong> ' . $e((string) ($tracking['carrier'] ?? ''))
            . ' &mdash; ' . $e((string) ($tracking['number'] ?? '')) . '</p>';
    }

    $note_html = $note !== ''
        ? '<div style="margin-top:14px;padding:10px;border:1px dashed #999;font-size:13px;"><strong>Customer note:</strong> ' . $note . '</div>'
        : '';

    return '<div class="wpultra-slip" style="page-break-after: always;max-width:190mm;margin:0 auto;padding:14mm 10mm;font-family:Arial,Helvetica,sans-serif;color:#111;">'
        . '<table style="width:100%;border-collapse:collapse;margin-bottom:18px;"><tr>'
        . '<td style="vertical-align:top;"><div style="font-size:20px;font-weight:bold;">' . $store_name . '</div>'
        . '<div style="font-size:12px;color:#555;white-space:pre-line;">' . $store_addr . '</div></td>'
        . '<td style="vertical-align:top;text-align:right;"><div style="font-size:22px;font-weight:bold;letter-spacing:1px;">PACKING SLIP</div>'
        . '<div style="font-size:13px;margin-top:4px;">Order <strong>#' . $number . '</strong></div>'
        . '<div style="font-size:12px;color:#555;">' . $date . '</div></td>'
        . '</tr></table>'
        . '<div style="margin-bottom:16px;font-size:13px;"><strong>Ship to:</strong><br>'
        . ($ship_name !== '' ? $ship_name . '<br>' : '')
        . implode('<br>', $addr_lines)
        . ($email !== '' ? '<br><span style="color:#555;">' . $email . '</span>' : '')
        . '</div>'
        . '<table style="width:100%;border-collapse:collapse;font-size:13px;">'
        . '<thead><tr>'
        . '<th style="text-align:left;padding:6px 8px;border-bottom:2px solid #111;">Item</th>'
        . '<th style="text-align:left;padding:6px 8px;border-bottom:2px solid #111;width:130px;">SKU</th>'
        . '<th style="text-align:right;padding:6px 8px;border-bottom:2px solid #111;width:60px;">Qty</th>'
        . '</tr></thead>'
        . '<tbody>' . $rows . '</tbody>'
        . '<tfoot><tr><td colspan="2" style="padding:6px 8px;text-align:right;font-weight:bold;">Total items</td>'
        . '<td style="padding:6px 8px;text-align:right;font-weight:bold;">' . $total_qty . '</td></tr></tfoot>'
        . '</table>'
        . $tracking_html
        . $note_html
        . '</div>';
}

/**
 * Wrap one or more packing-slip $data arrays into a complete standalone
 * print-ready HTML document (A4-ish, one order per page via
 * page-break-after: always). Pure.
 */
function wpultra_fulfill_packing_slips_html(array $datas): string {
    $sections = '';
    foreach ($datas as $data) {
        if (is_array($data)) { $sections .= wpultra_fulfill_packing_slip_section($data); }
    }
    return '<!DOCTYPE html><html><head><meta charset="utf-8">'
        . '<title>Packing slip</title>'
        . '<style>@page { size: A4; margin: 0; } body { margin: 0; } @media print { .wpultra-slip { page-break-after: always; } }</style>'
        . '</head><body>' . $sections . '</body></html>';
}

/** Single-order convenience wrapper around wpultra_fulfill_packing_slips_html(). Pure. */
function wpultra_fulfill_packing_slip_html(array $data): string {
    return wpultra_fulfill_packing_slips_html([$data]);
}

// ---------------------------------------------------------------------------
// PURE: customer notification email
// ---------------------------------------------------------------------------

/** Email subject for a shipped order. Pure. */
function wpultra_fulfill_notify_subject(string $order_number): string {
    return 'Your order #' . $order_number . ' has shipped';
}

/**
 * HTML body for the "your order has shipped" email. $d shape:
 *   {order_number, carrier, carrier_label?, number, url, store_name}
 * All values escaped; url only rendered as a link when non-empty. Pure.
 */
function wpultra_fulfill_notify_html(array $d): string {
    $e = 'wpultra_fulfill_esc';
    $number  = $e((string) ($d['order_number'] ?? ''));
    $carrier = $e((string) ($d['carrier_label'] ?? ($d['carrier'] ?? '')));
    $track   = $e((string) ($d['number'] ?? ''));
    $url     = trim((string) ($d['url'] ?? ''));
    $store   = $e((string) ($d['store_name'] ?? ''));

    $link_html = '';
    if ($url !== '' && (str_starts_with($url, 'https://') || str_starts_with($url, 'http://'))) {
        $link_html = '<p style="margin:20px 0;"><a href="' . $e($url) . '" '
            . 'style="background:#2271b1;color:#ffffff;padding:10px 22px;border-radius:4px;text-decoration:none;display:inline-block;">'
            . 'Track your package</a></p>'
            . '<p style="font-size:12px;color:#666;">Or copy this link: ' . $e($url) . '</p>';
    }

    return '<div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:0 auto;color:#222;">'
        . '<h2 style="font-size:20px;">Good news &mdash; your order has shipped!</h2>'
        . '<p>Order <strong>#' . $number . '</strong> is on its way.</p>'
        . '<table style="border-collapse:collapse;font-size:14px;margin:12px 0;">'
        . '<tr><td style="padding:4px 12px 4px 0;color:#555;">Carrier</td><td style="padding:4px 0;"><strong>' . $carrier . '</strong></td></tr>'
        . '<tr><td style="padding:4px 12px 4px 0;color:#555;">Tracking number</td><td style="padding:4px 0;"><strong>' . $track . '</strong></td></tr>'
        . '</table>'
        . $link_html
        . '<p style="margin-top:24px;font-size:13px;color:#666;">Thank you for shopping with ' . $store . '.</p>'
        . '</div>';
}

// ---------------------------------------------------------------------------
// Runtime: boot (controller calls this on plugins_loaded — do not hook here)
// ---------------------------------------------------------------------------

/**
 * Wire the custom 'shipped' order status + email tracking snippet into the
 * store. The controller calls this from the always-on runtime bootstrap on
 * plugins_loaded; this file only defines it.
 */
function wpultra_fulfill_boot(): void {
    if (!function_exists('add_action') || !function_exists('add_filter')) { return; }

    // Register the wc-shipped post status (legacy posts storage / HPOS sync
    // both understand it once it exists).
    add_action('init', function () {
        if (!function_exists('register_post_status')) { return; }
        register_post_status('wc-shipped', [
            'label'                     => __('Shipped', 'wp-ultra-mcp'),
            'public'                    => false,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => function_exists('_n_noop')
                ? _n_noop('Shipped <span class="count">(%s)</span>', 'Shipped <span class="count">(%s)</span>', 'wp-ultra-mcp')
                : ['Shipped (%s)', 'Shipped (%s)'],
        ]);
    });

    // Teach WooCommerce the status (admin dropdowns, reports, wc_get_orders).
    add_filter('wc_order_statuses', function ($statuses) {
        return wpultra_fulfill_insert_shipped_status(is_array($statuses) ? $statuses : []);
    });

    // Stamp shipped_at on the tracking meta when an order moves to shipped.
    add_action('woocommerce_order_status_shipped', function ($order_id, $order = null) {
        try {
            if (!is_object($order) && function_exists('wc_get_order')) { $order = wc_get_order((int) $order_id); }
            if (!is_object($order) || !method_exists($order, 'get_meta')) { return; }
            $tracking = $order->get_meta('_wpultra_tracking');
            if (!is_array($tracking) || !empty($tracking['shipped_at'])) { return; }
            $tracking['shipped_at'] = time();
            $order->update_meta_data('_wpultra_tracking', $tracking);
            $order->save();
        } catch (\Throwable $e) {
            // Best-effort stamp — never break the status transition.
        }
    }, 10, 2);

    // Append tracking info to Woo's own customer emails when tracking exists.
    add_action('woocommerce_email_order_meta', function ($order, $sent_to_admin = false, $plain_text = false) {
        try {
            if (!is_object($order) || !method_exists($order, 'get_meta')) { return; }
            $tracking = $order->get_meta('_wpultra_tracking');
            if (!is_array($tracking) || trim((string) ($tracking['number'] ?? '')) === '') { return; }
            $carriers = wpultra_fulfill_carriers();
            $label = $carriers[(string) ($tracking['carrier'] ?? '')]['label'] ?? (string) ($tracking['carrier'] ?? '');
            $number = (string) ($tracking['number'] ?? '');
            $url = (string) ($tracking['url'] ?? '');
            if ($plain_text) {
                echo "\nTracking: " . $label . ' ' . $number . ($url !== '' ? ' — ' . $url : '') . "\n";
            } else {
                echo '<p><strong>' . wpultra_fulfill_esc('Tracking') . ':</strong> '
                    . wpultra_fulfill_esc($label) . ' &mdash; ' . wpultra_fulfill_esc($number)
                    . ($url !== '' ? ' (<a href="' . wpultra_fulfill_esc($url) . '">' . wpultra_fulfill_esc('track') . '</a>)' : '')
                    . '</p>';
            }
        } catch (\Throwable $e) {
            // Best-effort append — never break Woo's email rendering.
        }
    }, 10, 3);
}

// ---------------------------------------------------------------------------
// Runtime: tracking meta (HPOS-safe — order-object meta only)
// ---------------------------------------------------------------------------

/** Read the tracking meta for an order. Returns the array or null when unset. */
function wpultra_fulfill_get_tracking(int $order_id): ?array {
    if (!function_exists('wc_get_order')) { return null; }
    $order = wc_get_order($order_id);
    if (!$order) { return null; }
    $tracking = $order->get_meta('_wpultra_tracking');
    return is_array($tracking) && $tracking !== [] ? $tracking : null;
}

/**
 * Store tracking on an order. Preserves an existing shipped_at stamp,
 * resets notified to false (a new number means a new notification is due).
 * Returns the stored tracking array or WP_Error.
 */
function wpultra_fulfill_set_tracking(int $order_id, string $carrier, string $number, string $custom_template = '') {
    if (!function_exists('wc_get_order')) { return wpultra_err('woocommerce_missing', 'wc_get_order() is unavailable.'); }
    $order = wc_get_order($order_id);
    if (!$order) { return wpultra_err('order_not_found', "Order $order_id not found."); }

    $carriers = wpultra_fulfill_carriers();
    if (!isset($carriers[$carrier])) {
        return wpultra_err('unknown_carrier', "Unknown carrier '$carrier'. Supported: " . implode(', ', array_keys($carriers)) . '.');
    }
    $number = trim($number);
    if ($number === '') { return wpultra_err('missing_number', 'Tracking number must not be empty.'); }

    $url = wpultra_fulfill_tracking_url($carrier, $number, $custom_template);
    if ($carrier === 'custom' && $url === '') {
        return wpultra_err('bad_custom_template', "Carrier 'custom' requires url_template containing the {number} placeholder.");
    }

    $existing = $order->get_meta('_wpultra_tracking');
    $existing = is_array($existing) ? $existing : [];

    $tracking = [
        'carrier'    => $carrier,
        'number'     => $number,
        'url'        => $url,
        'shipped_at' => (int) ($existing['shipped_at'] ?? 0),
        'notified'   => false,
    ];
    $order->update_meta_data('_wpultra_tracking', $tracking);
    $order->save();

    return $tracking;
}

// ---------------------------------------------------------------------------
// Runtime: packing-slip data from a real order (HPOS-safe accessors)
// ---------------------------------------------------------------------------

/** Build the pure packing-slip $data array from a WC_Order. Returns array or WP_Error. */
function wpultra_fulfill_order_slip_data(int $order_id) {
    if (!function_exists('wc_get_order')) { return wpultra_err('woocommerce_missing', 'wc_get_order() is unavailable.'); }
    $order = wc_get_order($order_id);
    if (!$order) { return wpultra_err('order_not_found', "Order $order_id not found."); }

    // Shipping address with billing fallback (virtual carts leave shipping empty).
    $formatted = (string) $order->get_formatted_shipping_address();
    if ($formatted === '') { $formatted = (string) $order->get_formatted_billing_address(); }
    // Formatted addresses come back as HTML with <br/> separators — split to
    // plain lines and strip tags; the pure renderer re-escapes everything.
    $lines = preg_split('/<br\s*\/?>/i', $formatted) ?: [];
    $lines = array_values(array_filter(array_map(static fn($l) => trim(html_entity_decode(strip_tags((string) $l), ENT_QUOTES, 'UTF-8')), $lines), static fn($l) => $l !== ''));

    $ship_name = trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name());
    if ($ship_name === '') { $ship_name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()); }

    $items = [];
    foreach ($order->get_items() as $item) {
        $sku = '';
        try {
            $product = method_exists($item, 'get_product') ? $item->get_product() : null;
            if ($product) { $sku = (string) $product->get_sku(); }
        } catch (\Throwable $e) {
            // Deleted product — slip still lists name + qty.
        }
        $items[] = [
            'name' => (string) $item->get_name(),
            'sku'  => $sku,
            'qty'  => (int) $item->get_quantity(),
        ];
    }

    $date = $order->get_date_created();
    $tracking = $order->get_meta('_wpultra_tracking');
    $tracking = is_array($tracking) && trim((string) ($tracking['number'] ?? '')) !== ''
        ? ['carrier' => (string) ($tracking['carrier'] ?? ''), 'number' => (string) ($tracking['number'] ?? '')]
        : null;

    $store_name = function_exists('get_bloginfo') ? (string) get_bloginfo('name') : '';
    $store_address = '';
    if (function_exists('get_option')) {
        $parts = array_filter([
            (string) get_option('woocommerce_store_address', ''),
            (string) get_option('woocommerce_store_address_2', ''),
            trim((string) get_option('woocommerce_store_city', '') . ' ' . (string) get_option('woocommerce_store_postcode', '')),
        ], static fn($p) => trim($p) !== '');
        $store_address = implode("\n", $parts);
    }

    return [
        'store' => ['name' => $store_name, 'address' => $store_address],
        'order' => [
            'number'                 => (string) $order->get_order_number(),
            'date'                   => $date ? $date->date('Y-m-d') : '',
            'shipping_name'          => $ship_name,
            'shipping_address_lines' => $lines,
            'billing_email'          => (string) $order->get_billing_email(),
            'items'                  => $items,
            'customer_note'          => (string) $order->get_customer_note(),
            'tracking'               => $tracking,
        ],
    ];
}

// ---------------------------------------------------------------------------
// Runtime: customer notification
// ---------------------------------------------------------------------------

/**
 * Email the customer their tracking info. Refuses when no tracking is set.
 * Marks tracking.notified = true on success. Returns
 * ['sent' => bool, 'to' => email, 'subject' => str] or WP_Error.
 */
function wpultra_fulfill_send_notification(int $order_id) {
    if (!function_exists('wc_get_order')) { return wpultra_err('woocommerce_missing', 'wc_get_order() is unavailable.'); }
    if (!function_exists('wp_mail')) { return wpultra_err('wp_mail_missing', 'wp_mail() is unavailable.'); }
    $order = wc_get_order($order_id);
    if (!$order) { return wpultra_err('order_not_found', "Order $order_id not found."); }

    $tracking = $order->get_meta('_wpultra_tracking');
    if (!is_array($tracking) || trim((string) ($tracking['number'] ?? '')) === '') {
        return wpultra_err('no_tracking', "Order $order_id has no tracking set. Run set-tracking first.");
    }

    $to = trim((string) $order->get_billing_email());
    if ($to === '') { return wpultra_err('no_billing_email', "Order $order_id has no billing email."); }

    $carriers = wpultra_fulfill_carriers();
    $carrier = (string) ($tracking['carrier'] ?? '');
    $order_number = (string) $order->get_order_number();

    $subject = wpultra_fulfill_notify_subject($order_number);
    $body = wpultra_fulfill_notify_html([
        'order_number'  => $order_number,
        'carrier'       => $carrier,
        'carrier_label' => $carriers[$carrier]['label'] ?? $carrier,
        'number'        => (string) ($tracking['number'] ?? ''),
        'url'           => (string) ($tracking['url'] ?? ''),
        'store_name'    => function_exists('get_bloginfo') ? (string) get_bloginfo('name') : '',
    ]);

    $sent = (bool) wp_mail($to, $subject, $body, ['Content-Type: text/html; charset=UTF-8']);
    if (!$sent) { return wpultra_err('mail_failed', "wp_mail() reported failure sending to $to."); }

    $tracking['notified'] = true;
    $order->update_meta_data('_wpultra_tracking', $tracking);
    $order->save();

    return ['sent' => true, 'to' => $to, 'subject' => $subject];
}

// ---------------------------------------------------------------------------
// Runtime: status changes
// ---------------------------------------------------------------------------

/**
 * Change one order's status, enforcing the workflow map unless $force.
 * Returns ['id', 'from', 'to', 'forced'] or WP_Error.
 */
function wpultra_fulfill_set_status(int $order_id, string $status, bool $force = false) {
    if (!function_exists('wc_get_order')) { return wpultra_err('woocommerce_missing', 'wc_get_order() is unavailable.'); }
    $order = wc_get_order($order_id);
    if (!$order) { return wpultra_err('order_not_found', "Order $order_id not found."); }

    $status = str_starts_with($status, 'wc-') ? substr($status, 3) : $status;
    $from = (string) $order->get_status();

    if ($from === $status) {
        return wpultra_err('same_status', "Order $order_id is already '$from'.");
    }
    if (!$force && !wpultra_fulfill_can_transition($from, $status)) {
        return wpultra_err(
            'transition_blocked',
            "Transition '$from' → '$status' is outside the safe workflow map (WooCommerce would allow it — pass force:true to bypass)."
        );
    }

    try {
        $order->update_status($status, 'WPUltra fulfillment');
    } catch (\Throwable $e) {
        return wpultra_err('status_update_failed', 'update_status failed: ' . $e->getMessage());
    }

    return ['id' => $order_id, 'from' => $from, 'to' => $status, 'forced' => $force];
}
