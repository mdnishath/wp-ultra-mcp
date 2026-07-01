<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/** Max rows any single report will hydrate. Filterable via `wpultra_woo_report_max`. */
function wpultra_woo_report_max(): int {
    $max = (int) apply_filters('wpultra_woo_report_max', 2000);
    return $max > 0 ? $max : 2000;
}

/**
 * Pure: sum order totals. $orders is an array of rows each with a 'total' (and optional
 * 'refunded'). Money is accumulated in integer cents to avoid binary-float drift, then
 * divided by 100 at the end. `gross` = totals incl. tax/shipping before refunds;
 * `net` = gross minus refunds.
 */
function wpultra_woo_report_money(array $orders): array {
    $gross_cents = 0;
    $net_cents = 0;
    foreach ($orders as $o) {
        $t = (int) round(((float) ($o['total'] ?? 0)) * 100);
        $r = (int) round(((float) ($o['refunded'] ?? 0)) * 100);
        $gross_cents += $t;
        $net_cents += $t - $r;
    }
    return [
        'order_count' => count($orders),
        'gross' => $gross_cents / 100,
        'net'   => $net_cents / 100,
    ];
}

function wpultra_woo_get_reports(array $input): array {
    $type = (string) ($input['type'] ?? 'sales');
    $statuses = ['wc-processing', 'wc-completed', 'wc-on-hold'];
    $max = wpultra_woo_report_max();
    $date = null;
    if (!empty($input['date_from']) && !empty($input['date_to'])) {
        $date = $input['date_from'] . '...' . $input['date_to'];
    } elseif (!empty($input['date_from'])) {
        $date = '>=' . $input['date_from'];
    } elseif (!empty($input['date_to'])) {
        $date = '<=' . $input['date_to'];
    }

    if ($type === 'low_stock') {
        $out = wc_get_products(['limit' => $max, 'stock_status' => 'outofstock', 'return' => 'objects']);
        $rows = [];
        foreach ($out as $p) { $rows[] = ['id' => $p->get_id(), 'name' => $p->get_name(), 'stock' => $p->get_stock_quantity()]; }
        $res = ['type' => 'low_stock', 'count' => count($rows), 'products' => $rows];
        if (count($rows) >= $max) { $res['truncated'] = true; }
        return $res;
    }

    // Fetch order IDs only (cheap), capped at $max, then hydrate one at a time.
    $q = ['limit' => $max, 'return' => 'ids', 'status' => $statuses, 'orderby' => 'date', 'order' => 'DESC'];
    if ($date !== null) { $q['date_created'] = $date; }
    $ids = wc_get_orders($q);
    $truncated = count($ids) >= $max;

    if ($type === 'top_products') {
        $tally = [];
        foreach ($ids as $oid) {
            $o = wc_get_order($oid);
            if (!$o) { continue; }
            foreach ($o->get_items() as $item) {
                $pid = $item->get_product_id();
                if (!isset($tally[$pid])) { $tally[$pid] = ['product_id' => $pid, 'name' => $item->get_name(), 'qty' => 0, 'revenue_cents' => 0]; }
                $tally[$pid]['qty'] += $item->get_quantity();
                // Per-item net: item total minus this order's refund allocated to the item.
                $item_net = (float) $item->get_total() - (float) $o->get_total_refunded_for_item($item->get_id());
                $tally[$pid]['revenue_cents'] += (int) round($item_net * 100);
            }
        }
        usort($tally, function ($a, $b) { return $b['qty'] <=> $a['qty']; });
        $products = array_slice(array_values($tally), 0, 10);
        foreach ($products as &$row) { $row['revenue'] = $row['revenue_cents'] / 100; unset($row['revenue_cents']); }
        unset($row);
        $res = ['type' => 'top_products', 'products' => $products];
        if ($truncated) { $res['truncated'] = true; }
        return $res;
    }

    // sales / revenue — accumulate net in integer cents while iterating hydrated orders.
    $rows = [];
    foreach ($ids as $oid) {
        $o = wc_get_order($oid);
        if (!$o) { continue; }
        $rows[] = ['total' => $o->get_total(), 'refunded' => $o->get_total_refunded()];
    }
    $m = wpultra_woo_report_money($rows);
    $res = [
        'type' => $type,
        'order_count' => $m['order_count'],
        'gross' => round($m['gross'], 2),          // order totals incl. tax/shipping, before refunds
        'net'   => round($m['net'], 2),            // gross minus refunds
        'currency' => get_woocommerce_currency(),
    ];
    if ($truncated) { $res['truncated'] = true; }
    return $res;
}
