<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/woo-insights', [
    'label'       => __('WooCommerce: Insights', 'wp-ultra-mcp'),
    'description' => __('Store insights: abandoned-checkouts (pending/failed/cancelled orders in last `days`, default 7), stock-alerts (out-of-stock + low-stock split at `low_threshold`, default 3), repeat-customers (completed/processing orders in last `days` grouped by billing email, >= `min_orders`, default 2, top 25 by spend). HPOS-safe.', 'wp-ultra-mcp'),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'type'          => ['type' => 'string', 'enum' => ['abandoned-checkouts', 'stock-alerts', 'repeat-customers']],
            'days'          => ['type' => 'integer'],
            'low_threshold' => ['type' => 'integer'],
            'min_orders'    => ['type' => 'integer'],
        ],
        'required'   => ['type'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean'], 'insights' => ['type' => 'object']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_woo_insights_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

function wpultra_woo_insights_cb(array $input) {
    if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    $type = (string) ($input['type'] ?? '');
    $days = isset($input['days']) ? (int) $input['days'] : 7;
    $low_threshold = isset($input['low_threshold']) ? (int) $input['low_threshold'] : 3;
    $min_orders = isset($input['min_orders']) ? (int) $input['min_orders'] : 2;

    switch ($type) {
        case 'abandoned-checkouts':
            $insights = wpultra_woo_insights_abandoned_checkouts($days);
            break;
        case 'stock-alerts':
            $insights = wpultra_woo_insights_stock_alerts($low_threshold);
            break;
        case 'repeat-customers':
            $insights = wpultra_woo_insights_repeat_customers($days, $min_orders);
            break;
        default:
            return wpultra_err('invalid_type', "Unknown insights type: $type");
    }
    return wpultra_ok(['insights' => array_merge(['type' => $type], $insights)]);
}
