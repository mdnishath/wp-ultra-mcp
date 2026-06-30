<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

function wpultra_woo_order_row($o): array {
    return [
        'id'           => $o->get_id(),
        'number'       => $o->get_order_number(),
        'status'       => $o->get_status(),
        'total'        => $o->get_total(),
        'currency'     => $o->get_currency(),
        'customer_id'  => $o->get_customer_id(),
        'date_created' => $o->get_date_created() ? $o->get_date_created()->date('c') : null,
        'items_count'  => count($o->get_items()),
    ];
}

function wpultra_woo_order_full($o): array {
    $items = [];
    foreach ($o->get_items() as $item_id => $item) {
        $items[] = [
            'item_id'      => $item_id,
            'product_id'   => $item->get_product_id(),
            'variation_id' => $item->get_variation_id(),
            'name'         => $item->get_name(),
            'qty'          => $item->get_quantity(),
            'subtotal'     => $item->get_subtotal(),
            'total'        => $item->get_total(),
        ];
    }
    return array_merge(wpultra_woo_order_row($o), [
        'payment_method'       => $o->get_payment_method(),
        'payment_method_title' => $o->get_payment_method_title(),
        'billing'              => $o->get_address('billing'),
        'shipping'             => $o->get_address('shipping'),
        'items'                => $items,
        'shipping_total'       => $o->get_shipping_total(),
        'total_tax'            => $o->get_total_tax(),
        'discount_total'       => $o->get_discount_total(),
        'customer_note'        => $o->get_customer_note(),
        'notes_count'          => count(wc_get_order_notes(['order_id' => $o->get_id()])),
    ]);
}

function wpultra_woo_list_orders(array $args): array {
    $q = [
        'limit'   => isset($args['per_page']) ? (int) $args['per_page'] : 20,
        'page'    => isset($args['page']) ? max(1, (int) $args['page']) : 1,
        'orderby' => 'date',
        'order'   => 'DESC',
        'return'  => 'objects',
    ];
    if (!empty($args['status']))    { $q['status'] = $args['status']; } // 'processing' or ['processing','completed']
    if (!empty($args['customer']))  { $q['customer_id'] = (int) $args['customer']; }
    if (!empty($args['date_from'])) { $q['date_created'] = '>=' . $args['date_from']; }
    if (!empty($args['date_to']))   { $q['date_created'] = '<=' . $args['date_to']; }
    if (!empty($args['search']))    { $q['s'] = (string) $args['search']; }
    $orders = wc_get_orders($q);
    $rows = [];
    foreach ($orders as $o) { $rows[] = wpultra_woo_order_row($o); }
    return ['count' => count($rows), 'orders' => $rows];
}

function wpultra_woo_get_order(int $id) {
    $o = wc_get_order($id);
    if (!$o) { return wpultra_err('order_not_found', "No order with id $id."); }
    return wpultra_woo_order_full($o);
}
