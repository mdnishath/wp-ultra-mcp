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

function wpultra_woo_create_order(array $input) {
    $lines = $input['line_items'] ?? [];
    if (!is_array($lines) || $lines === []) {
        return wpultra_err('no_line_items', 'create-order requires a non-empty line_items array.');
    }
    $order = wc_create_order();
    if (is_wp_error($order)) { return $order; }

    $added = 0;
    $skipped = [];
    foreach ($lines as $li) {
        $pid = (int) ($li['product_id'] ?? 0);
        $qty = max(1, (int) ($li['quantity'] ?? 1));
        $vid = (int) ($li['variation_id'] ?? 0);
        $product = wc_get_product($vid ?: $pid);
        if (!$product) { $skipped[] = ['product_id' => $pid, 'reason' => 'not_found']; continue; }
        $order->add_product($product, $qty);
        $added++;
    }
    if ($added === 0) {
        $order->delete(true);
        return wpultra_err('no_valid_products', 'None of the line_items resolved to a product.', ['skipped' => $skipped]);
    }

    if (!empty($input['customer_id'])) { $order->set_customer_id((int) $input['customer_id']); }
    if (!empty($input['billing']) && is_array($input['billing']))   { $order->set_address($input['billing'], 'billing'); }
    if (!empty($input['shipping']) && is_array($input['shipping'])) { $order->set_address($input['shipping'], 'shipping'); }
    if (isset($input['customer_note'])) { $order->set_customer_note((string) $input['customer_note']); }
    $order->set_status(!empty($input['status']) ? (string) $input['status'] : 'pending');
    $order->calculate_totals();
    $id = $order->save();
    if (!$id) { return wpultra_err('order_save_failed', 'save() returned 0.'); }
    return ['id' => (int) $id, 'total' => $order->get_total(), 'status' => $order->get_status(), 'skipped' => $skipped];
}

function wpultra_woo_update_order(array $input) {
    $id = (int) ($input['order_id'] ?? 0);
    $order = wc_get_order($id);
    if (!$order) { return wpultra_err('order_not_found', "No order with id $id."); }

    $items_changed = false;
    if (!empty($input['remove_item_ids']) && is_array($input['remove_item_ids'])) {
        foreach ($input['remove_item_ids'] as $iid) { $order->remove_item((int) $iid); $items_changed = true; }
    }
    if (!empty($input['add_items']) && is_array($input['add_items'])) {
        foreach ($input['add_items'] as $li) {
            $pid = (int) ($li['variation_id'] ?? 0) ?: (int) ($li['product_id'] ?? 0);
            $product = wc_get_product($pid);
            if ($product) { $order->add_product($product, max(1, (int) ($li['quantity'] ?? 1))); $items_changed = true; }
        }
    }
    if (!empty($input['billing']) && is_array($input['billing']))   { $order->set_address($input['billing'], 'billing'); }
    if (!empty($input['shipping']) && is_array($input['shipping'])) { $order->set_address($input['shipping'], 'shipping'); }
    if (isset($input['note']) && $input['note'] !== '') {
        $order->add_order_note((string) $input['note'], !empty($input['note_to_customer']));
    }
    if ($items_changed) { $order->calculate_totals(); }
    if (!empty($input['status'])) { $order->set_status((string) $input['status']); }
    $order->save();
    return ['id' => $id, 'status' => $order->get_status(), 'total' => $order->get_total()];
}

function wpultra_woo_refund_order(array $input) {
    $id = (int) ($input['order_id'] ?? 0);
    $order = wc_get_order($id);
    if (!$order) { return wpultra_err('order_not_found', "No order with id $id."); }

    $amount = isset($input['amount']) && $input['amount'] !== '' ? (string) $input['amount'] : (string) $order->get_remaining_refund_amount();
    if ((float) $amount <= 0) { return wpultra_err('nothing_to_refund', 'Refund amount is zero or order is fully refunded.'); }

    $args = [
        'order_id'       => $id,
        'amount'         => $amount,
        'reason'         => (string) ($input['reason'] ?? ''),
        'restock_items'  => array_key_exists('restock', $input) ? (bool) $input['restock'] : true,
    ];
    if (!empty($input['line_items']) && is_array($input['line_items'])) { $args['line_items'] = $input['line_items']; }

    $refund = wc_create_refund($args);
    if (is_wp_error($refund)) { return $refund; }
    return ['refund_id' => $refund->get_id(), 'amount' => $refund->get_amount(), 'order_id' => $id];
}
