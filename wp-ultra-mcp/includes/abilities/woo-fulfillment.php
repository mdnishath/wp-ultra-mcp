<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

// The engine ships under includes/woocommerce/fulfillment.php — require it
// defensively so this ability works regardless of load order (mirrors
// woo-bulk-edit's defensive engine require).
if (!function_exists('wpultra_fulfill_carriers') && defined('WPULTRA_DIR') && is_readable(WPULTRA_DIR . 'includes/woocommerce/fulfillment.php')) {
    require_once WPULTRA_DIR . 'includes/woocommerce/fulfillment.php';
}

wp_register_ability('wpultra/woo-fulfillment', [
    'label'       => __('WooCommerce: Order Fulfillment', 'wp-ultra-mcp'),
    'description' => __(
        'Order fulfillment workflow: tracking numbers, a custom "shipped" order status, print-ready packing slips, and customer shipping notifications. '
        . 'Actions: '
        . 'set-tracking {order_id, carrier, number, url_template? (custom only)} — store a tracking number on the order; returns the resolved tracking URL. Carriers: ups, fedex, dhl, usps, royal-mail, tnt, aramex, pathao, steadfast, redx, custom (custom requires url_template containing {number}). '
        . 'get-tracking {order_id} — read back {carrier, number, url, shipped_at, notified}. '
        . 'packing-slip {order_id} or {order_ids[]} — returns print-ready standalone HTML (a pick list: items, SKU, qty — no prices; the user prints to PDF from the browser; bulk output puts one order per page). '
        . 'notify {order_id, confirm:true} — email the customer "Your order #N has shipped" with carrier + tracking link (confirm-gated, it emails a real customer; refuses when no tracking is set; marks tracking.notified). '
        . 'set-status {order_id, status, force?} — change order status, validated against a safe workflow map (pending→processing/cancelled/on-hold, on-hold→processing/cancelled, processing→shipped/completed/cancelled/refunded, shipped→completed/refunded, completed→refunded). The map is a safety rail — WooCommerce itself allows any transition; pass force:true to bypass. '
        . 'bulk-status {order_ids[], status, force?, confirm:true} — same for up to 100 orders, per-order results, confirm-gated. '
        . 'statuses — list the store\'s current order statuses (including our registered "shipped") plus the transition map. '
        . 'Examples: {action:"set-tracking", order_id:123, carrier:"dhl", number:"JD014600003RU"} · '
        . '{action:"set-tracking", order_id:123, carrier:"custom", number:"ABC1", url_template:"https://courier.example/t/{number}"} · '
        . '{action:"set-status", order_id:123, status:"shipped"} · '
        . '{action:"notify", order_id:123, confirm:true} · '
        . '{action:"packing-slip", order_ids:[123,124,125]}.',
        'wp-ultra-mcp'
    ),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['set-tracking', 'get-tracking', 'packing-slip', 'notify', 'set-status', 'bulk-status', 'statuses'],
            ],
            'order_id'     => ['type' => 'integer'],
            'order_ids'    => ['type' => 'array', 'items' => ['type' => 'integer']],
            'carrier'      => ['type' => 'string', 'enum' => ['ups', 'fedex', 'dhl', 'usps', 'royal-mail', 'tnt', 'aramex', 'pathao', 'steadfast', 'redx', 'custom']],
            'number'       => ['type' => 'string'],
            'url_template' => ['type' => 'string'],
            'status'       => ['type' => 'string'],
            'force'        => ['type' => 'boolean'],
            'confirm'      => ['type' => 'boolean'],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'     => ['type' => 'boolean'],
            'action'      => ['type' => 'string'],
            'tracking'    => ['type' => 'object'],
            'html'        => ['type' => 'string'],
            'orders'      => ['type' => 'integer'],
            'sent'        => ['type' => 'boolean'],
            'to'          => ['type' => 'string'],
            'subject'     => ['type' => 'string'],
            'from'        => ['type' => 'string'],
            'to_status'   => ['type' => 'string'],
            'results'     => ['type' => 'array'],
            'summary'     => ['type' => 'object'],
            'statuses'    => ['type' => 'object'],
            'transitions' => ['type' => 'object'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_woo_fulfillment_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_woo_fulfillment_cb(array $input) {
    if (!function_exists('wpultra_woo_active') || !wpultra_woo_active()) {
        return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.');
    }
    if (!function_exists('wpultra_fulfill_carriers')) {
        return wpultra_err('fulfillment_engine_missing', 'The fulfillment engine (includes/woocommerce/fulfillment.php) is not loaded.');
    }

    $action = (string) ($input['action'] ?? '');
    $order_id = (int) ($input['order_id'] ?? 0);

    // ---- statuses ----------------------------------------------------
    if ($action === 'statuses') {
        $statuses = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : [];
        return wpultra_ok([
            'action'      => 'statuses',
            'statuses'    => is_array($statuses) ? $statuses : [],
            'transitions' => wpultra_fulfill_allowed_transitions(),
        ]);
    }

    // ---- set-tracking -------------------------------------------------
    if ($action === 'set-tracking') {
        if ($order_id < 1) { return wpultra_err('missing_order_id', 'order_id is required.'); }
        $carrier = (string) ($input['carrier'] ?? '');
        $number  = (string) ($input['number'] ?? '');
        if ($carrier === '') { return wpultra_err('missing_carrier', 'carrier is required. Supported: ' . implode(', ', array_keys(wpultra_fulfill_carriers())) . '.'); }
        if (trim($number) === '') { return wpultra_err('missing_number', 'number is required.'); }

        $tracking = wpultra_fulfill_set_tracking($order_id, $carrier, $number, (string) ($input['url_template'] ?? ''));
        if (is_wp_error($tracking)) {
            wpultra_audit_log('woo-fulfillment', "set-tracking #$order_id failed: " . $tracking->get_error_message(), false);
            return $tracking;
        }
        wpultra_audit_log('woo-fulfillment', "set-tracking #$order_id $carrier " . $tracking['number'], true);
        return wpultra_ok(['action' => 'set-tracking', 'orders' => 1, 'tracking' => $tracking]);
    }

    // ---- get-tracking -------------------------------------------------
    if ($action === 'get-tracking') {
        if ($order_id < 1) { return wpultra_err('missing_order_id', 'order_id is required.'); }
        if (!function_exists('wc_get_order') || !wc_get_order($order_id)) {
            return wpultra_err('order_not_found', "Order $order_id not found.");
        }
        $tracking = wpultra_fulfill_get_tracking($order_id);
        if ($tracking === null) {
            return wpultra_ok(['action' => 'get-tracking', 'tracking' => [], 'summary' => ['note' => 'No tracking set on this order.']]);
        }
        return wpultra_ok(['action' => 'get-tracking', 'tracking' => $tracking]);
    }

    // ---- packing-slip (single or bulk) ---------------------------------
    if ($action === 'packing-slip') {
        $ids = [];
        if (!empty($input['order_ids']) && is_array($input['order_ids'])) {
            $ids = array_values(array_unique(array_map('intval', $input['order_ids'])));
        } elseif ($order_id > 0) {
            $ids = [$order_id];
        }
        if (empty($ids)) { return wpultra_err('missing_order_id', 'Provide order_id or order_ids[].'); }
        if (count($ids) > 50) { return wpultra_err('too_many_orders', 'packing-slip is capped at 50 orders per call.'); }

        $datas = [];
        $errors = [];
        foreach ($ids as $id) {
            $data = wpultra_fulfill_order_slip_data($id);
            if (is_wp_error($data)) { $errors[] = ['id' => $id, 'error' => $data->get_error_message()]; continue; }
            $datas[] = $data;
        }
        if (empty($datas)) {
            return wpultra_err('no_valid_orders', 'None of the requested orders could be loaded.', $errors);
        }
        wpultra_audit_log('woo-fulfillment', 'packing-slip orders=' . count($datas), true);
        return wpultra_ok([
            'action'  => 'packing-slip',
            'orders'  => count($datas),
            'html'    => wpultra_fulfill_packing_slips_html($datas),
            'results' => $errors,
        ]);
    }

    // ---- notify ---------------------------------------------------------
    if ($action === 'notify') {
        if ($order_id < 1) { return wpultra_err('missing_order_id', 'order_id is required.'); }
        if (($input['confirm'] ?? false) !== true) {
            return wpultra_err('notify_unconfirmed', 'notify emails a real customer. Re-run with confirm:true.');
        }
        $res = wpultra_fulfill_send_notification($order_id);
        if (is_wp_error($res)) {
            wpultra_audit_log('woo-fulfillment', "notify #$order_id failed: " . $res->get_error_message(), false);
            return $res;
        }
        wpultra_audit_log('woo-fulfillment', "notify #$order_id sent to " . $res['to'], true);
        return wpultra_ok(['action' => 'notify', 'sent' => true, 'to' => $res['to'], 'subject' => $res['subject']]);
    }

    // ---- set-status -------------------------------------------------------
    if ($action === 'set-status') {
        if ($order_id < 1) { return wpultra_err('missing_order_id', 'order_id is required.'); }
        $status = trim((string) ($input['status'] ?? ''));
        if ($status === '') { return wpultra_err('missing_status', 'status is required.'); }
        $force = ($input['force'] ?? false) === true;

        $res = wpultra_fulfill_set_status($order_id, $status, $force);
        if (is_wp_error($res)) {
            wpultra_audit_log('woo-fulfillment', "set-status #$order_id → $status failed: " . $res->get_error_message(), false);
            return $res;
        }
        wpultra_audit_log('woo-fulfillment', "set-status #$order_id {$res['from']} → {$res['to']}" . ($force ? ' (forced)' : ''), true);
        return wpultra_ok(['action' => 'set-status', 'from' => $res['from'], 'to_status' => $res['to'], 'orders' => 1]);
    }

    // ---- bulk-status ---------------------------------------------------------
    if ($action === 'bulk-status') {
        $ids = !empty($input['order_ids']) && is_array($input['order_ids'])
            ? array_values(array_unique(array_map('intval', $input['order_ids'])))
            : [];
        if (empty($ids)) { return wpultra_err('missing_order_ids', 'order_ids[] is required.'); }
        if (count($ids) > 100) { return wpultra_err('too_many_orders', 'bulk-status is capped at 100 orders per call.'); }
        $status = trim((string) ($input['status'] ?? ''));
        if ($status === '') { return wpultra_err('missing_status', 'status is required.'); }
        if (($input['confirm'] ?? false) !== true) {
            return wpultra_err('bulk_status_unconfirmed', 'bulk-status changes many live orders. Re-run with confirm:true.');
        }
        $force = ($input['force'] ?? false) === true;

        $results = [];
        $changed = 0;
        $failed = 0;
        foreach ($ids as $id) {
            $res = wpultra_fulfill_set_status($id, $status, $force);
            if (is_wp_error($res)) {
                $results[] = ['id' => $id, 'ok' => false, 'error' => $res->get_error_message()];
                $failed++;
            } else {
                $results[] = ['id' => $id, 'ok' => true, 'from' => $res['from'], 'to' => $res['to']];
                $changed++;
            }
        }
        wpultra_audit_log('woo-fulfillment', "bulk-status → $status changed=$changed failed=$failed" . ($force ? ' (forced)' : ''), $failed === 0);
        return wpultra_ok([
            'action'  => 'bulk-status',
            'results' => $results,
            'summary' => ['total' => count($ids), 'changed' => $changed, 'failed' => $failed],
        ]);
    }

    return wpultra_err('unknown_action', "Unknown action '$action'. Use set-tracking, get-tracking, packing-slip, notify, set-status, bulk-status, or statuses.");
}
