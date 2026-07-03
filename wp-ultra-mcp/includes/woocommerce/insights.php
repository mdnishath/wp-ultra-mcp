<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/** Max rows any single insight will hydrate. Filterable via `wpultra_woo_insights_max`. */
function wpultra_woo_insights_max(): int {
    $max = (int) apply_filters('wpultra_woo_insights_max', 2000);
    return $max > 0 ? $max : 2000;
}

/**
 * Pure: aggregate order fixtures {email, total} into per-customer totals, keep only
 * customers with >= $min_orders, sort by total_spent desc. Money accumulated in
 * integer cents to avoid binary-float drift, divided by 100 at the end.
 */
function wpultra_woo_insights_aggregate_customers(array $order_fixtures, int $min_orders = 2): array {
    $tally = [];
    foreach ($order_fixtures as $o) {
        $email = strtolower(trim((string) ($o['email'] ?? '')));
        if ($email === '') { continue; }
        if (!isset($tally[$email])) { $tally[$email] = ['email' => $email, 'orders' => 0, 'total_cents' => 0]; }
        $tally[$email]['orders']++;
        $tally[$email]['total_cents'] += (int) round(((float) ($o['total'] ?? 0)) * 100);
    }
    $rows = [];
    foreach ($tally as $row) {
        if ($row['orders'] < $min_orders) { continue; }
        $rows[] = [
            'email'       => $row['email'],
            'orders'      => $row['orders'],
            'total_spent' => round($row['total_cents'] / 100, 2),
        ];
    }
    usort($rows, function ($a, $b) { return $b['total_spent'] <=> $a['total_spent']; });
    return $rows;
}

/**
 * Pure: split product fixtures {qty, status} into out_of_stock[] / low_stock[] using the
 * given threshold. A product is out_of_stock when stock_status is 'outofstock' OR qty <= 0.
 * Otherwise it's low_stock when qty <= $threshold (boundary inclusive).
 */
function wpultra_woo_insights_split_stock(array $product_fixtures, int $threshold = 3): array {
    $out_of_stock = [];
    $low_stock = [];
    foreach ($product_fixtures as $p) {
        $qty = $p['qty'] ?? null;
        $status = (string) ($p['status'] ?? '');
        $qty_num = $qty === null ? null : (float) $qty;
        if ($status === 'outofstock' || ($qty_num !== null && $qty_num <= 0)) {
            $out_of_stock[] = $p;
        } elseif ($qty_num !== null && $qty_num <= $threshold) {
            $low_stock[] = $p;
        }
    }
    return ['out_of_stock' => $out_of_stock, 'low_stock' => $low_stock];
}

/** Pure: build a {count, value} summary from rows each carrying a numeric $money_key. */
function wpultra_woo_insights_summary(array $rows, string $money_key = 'total'): array {
    $cents = 0;
    foreach ($rows as $r) { $cents += (int) round(((float) ($r[$money_key] ?? 0)) * 100); }
    return ['count' => count($rows), 'value' => round($cents / 100, 2)];
}

/**
 * Abandoned checkouts: orders left in pending/failed/cancelled status within the last
 * $days days. HPOS-safe via wc_get_orders(). Full (unmasked) email — this is an admin tool.
 */
function wpultra_woo_insights_abandoned_checkouts(int $days = 7): array {
    $days = $days > 0 ? $days : 7;
    $max = wpultra_woo_insights_max();
    $since = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));
    $q = [
        'limit'  => $max,
        'return' => 'objects',
        'status' => ['pending', 'failed', 'cancelled'],
        'orderby' => 'date',
        'order'  => 'DESC',
        'date_created' => '>=' . $since,
    ];
    $orders = wc_get_orders($q);
    $truncated = count($orders) >= $max;

    $rows = [];
    foreach ($orders as $o) {
        $rows[] = [
            'id'          => $o->get_id(),
            'status'      => $o->get_status(),
            'total'       => $o->get_total(),
            'email'       => $o->get_billing_email(),
            'created'     => $o->get_date_created() ? $o->get_date_created()->date('c') : null,
            'items_count' => count($o->get_items()),
        ];
    }
    $summary = wpultra_woo_insights_summary($rows, 'total');
    $res = ['days' => $days, 'checkouts' => $rows, 'summary' => $summary];
    if ($truncated) { $res['truncated'] = true; }
    return $res;
}

/**
 * Stock alerts: products with manage_stock on, split into out_of_stock[] (stock_status
 * outofstock) and low_stock[] (qty <= $low_threshold, but still in stock). HPOS-safe —
 * uses wc_get_products() (WC's own product query abstraction, unaffected by order storage).
 */
function wpultra_woo_insights_stock_alerts(int $low_threshold = 3): array {
    $low_threshold = $low_threshold >= 0 ? $low_threshold : 3;
    $max = wpultra_woo_insights_max();

    $row = function ($p): array {
        return [
            'id'     => $p->get_id(),
            'name'   => $p->get_name(),
            'sku'    => $p->get_sku(),
            'qty'    => $p->get_stock_quantity(),
            'status' => $p->get_stock_status(),
        ];
    };

    // `manage_stock` isn't a whitelisted wc_get_products() query var — filter via meta_query
    // on the underlying '_manage_stock' postmeta instead.
    $manage_stock_meta = [['key' => '_manage_stock', 'value' => 'yes', 'compare' => '=']];

    // Out of stock — cheap, indexed query via WC's own stock_status filter.
    $out = wc_get_products(['limit' => $max, 'stock_status' => 'outofstock', 'meta_query' => $manage_stock_meta, 'return' => 'objects']);
    $out_of_stock = array_map($row, $out);

    // Low stock (in stock, qty <= threshold) — filter in-stock manage_stock products by qty.
    $in_stock = wc_get_products(['limit' => $max, 'stock_status' => 'instock', 'meta_query' => $manage_stock_meta, 'return' => 'objects']);
    $low_stock = [];
    foreach ($in_stock as $p) {
        $qty = $p->get_stock_quantity();
        if ($qty !== null && (float) $qty <= $low_threshold) { $low_stock[] = $row($p); }
    }

    return [
        'low_threshold' => $low_threshold,
        'out_of_stock'  => $out_of_stock,
        'low_stock'     => $low_stock,
        'summary'       => ['out_of_stock_count' => count($out_of_stock), 'low_stock_count' => count($low_stock)],
    ];
}

/**
 * Repeat customers: aggregate completed/processing orders (within $days days) by billing
 * email, keep customers with >= $min_orders, sorted by total_spent desc, top 25.
 */
function wpultra_woo_insights_repeat_customers(int $days = 7, int $min_orders = 2): array {
    $days = $days > 0 ? $days : 7;
    $min_orders = $min_orders > 0 ? $min_orders : 2;
    $max = wpultra_woo_insights_max();
    $since = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));

    $ids = wc_get_orders([
        'limit'  => $max,
        'return' => 'ids',
        'status' => ['completed', 'processing'],
        'date_created' => '>=' . $since,
    ]);
    $truncated = count($ids) >= $max;

    $fixtures = [];
    foreach ($ids as $oid) {
        $o = wc_get_order($oid);
        if (!$o) { continue; }
        $fixtures[] = ['email' => $o->get_billing_email(), 'total' => $o->get_total()];
    }

    $customers = array_slice(wpultra_woo_insights_aggregate_customers($fixtures, $min_orders), 0, 25);
    $res = ['days' => $days, 'min_orders' => $min_orders, 'customers' => $customers];
    if ($truncated) { $res['truncated'] = true; }
    return $res;
}
