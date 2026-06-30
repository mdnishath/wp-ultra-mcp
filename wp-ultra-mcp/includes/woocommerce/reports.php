<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/** Pure: sum order totals. $orders is an array of rows each with a 'total'. */
function wpultra_woo_report_money(array $orders): array {
    $gross = 0.0;
    foreach ($orders as $o) { $gross += (float) ($o['total'] ?? 0); }
    return ['order_count' => count($orders), 'gross' => $gross];
}

function wpultra_woo_get_reports(array $input): array {
    $type = (string) ($input['type'] ?? 'sales');
    $statuses = ['wc-processing', 'wc-completed', 'wc-on-hold'];
    $q = ['limit' => -1, 'return' => 'objects', 'status' => $statuses];
    if (!empty($input['date_from']) && !empty($input['date_to'])) {
        $q['date_created'] = $input['date_from'] . '...' . $input['date_to'];
    } elseif (!empty($input['date_from'])) {
        $q['date_created'] = '>=' . $input['date_from'];
    } elseif (!empty($input['date_to'])) {
        $q['date_created'] = '<=' . $input['date_to'];
    }

    if ($type === 'low_stock') {
        $out = wc_get_products(['limit' => -1, 'stock_status' => 'outofstock', 'return' => 'objects']);
        $rows = [];
        foreach ($out as $p) { $rows[] = ['id' => $p->get_id(), 'name' => $p->get_name(), 'stock' => $p->get_stock_quantity()]; }
        return ['type' => 'low_stock', 'count' => count($rows), 'products' => $rows];
    }

    $orders = wc_get_orders($q);
    if ($type === 'top_products') {
        $tally = [];
        foreach ($orders as $o) {
            foreach ($o->get_items() as $item) {
                $pid = $item->get_product_id();
                if (!isset($tally[$pid])) { $tally[$pid] = ['product_id' => $pid, 'name' => $item->get_name(), 'qty' => 0, 'revenue' => 0.0]; }
                $tally[$pid]['qty'] += $item->get_quantity();
                $tally[$pid]['revenue'] += (float) $item->get_total();
            }
        }
        usort($tally, function ($a, $b) { return $b['qty'] <=> $a['qty']; });
        return ['type' => 'top_products', 'products' => array_slice(array_values($tally), 0, 10)];
    }

    // sales / revenue
    $rows = [];
    foreach ($orders as $o) { $rows[] = ['total' => $o->get_total()]; }
    $m = wpultra_woo_report_money($rows);
    return ['type' => $type, 'order_count' => $m['order_count'], 'gross' => round($m['gross'], 2), 'currency' => get_woocommerce_currency()];
}
