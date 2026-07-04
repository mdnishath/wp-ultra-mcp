<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

// The engine ships under includes/woocommerce/bulk.php but the woocommerce
// engine-file loader in bootstrap-mcp.php does not yet list it — require it
// defensively so this ability works regardless of load order (mirrors how
// analytics-report leans on its engine file).
if (!function_exists('wpultra_woo_bulk_select') && defined('WPULTRA_DIR') && is_readable(WPULTRA_DIR . 'includes/woocommerce/bulk.php')) {
    require_once WPULTRA_DIR . 'includes/woocommerce/bulk.php';
}

wp_register_ability('wpultra/woo-bulk-edit', [
    'label'       => __('WooCommerce: Bulk Edit Products', 'wp-ultra-mcp'),
    'description' => __(
        'Bulk-edit many products at once: select a target set with filters, then apply a change-set to all of them. '
        . 'filters (required — how to select): ids[] (explicit passthrough), category (slug), type, stock_status, status, search, on_sale, price_min/price_max (matched against each product\'s ACTIVE price — the sale price while on sale), limit (default 100, cap 500). '
        . 'changes (required — what to change): regular_price/sale_price (set to an exact value), price_adjust {mode: percent|fixed, target: regular|sale, direction: increase|decrease, amount} (relative change, clamped >=0, rounded 2dp), '
        . 'stock_quantity (set) or stock_adjust {amount} (relative, clamped >=0), manage_stock (bool), stock_status (NOTE: on a managed-stock product WooCommerce derives stock_status from the quantity on save — set stock_quantity 0 to make it outofstock, or manage_stock:false first), status (publish|draft|private), catalog_visibility, add_category/remove_category (slug or array of slugs), tax_class, sale_from/sale_to (Y-m-d schedule). '
        . 'Examples: {filters:{category:"sale"}, changes:{price_adjust:{mode:"percent", target:"regular", direction:"decrease", amount:20}}} = "all products in category \'sale\' → regular price -20%". '
        . '{filters:{stock_status:"outofstock"}, changes:{stock_quantity:0}} = "set stock 0 where stock_status outofstock". '
        . 'dry_run defaults to TRUE (preview only, no writes) and returns a before/after diff per product; pass dry_run:false and confirm:true to write. Output is capped to 100 per-product rows (a note is added when more were affected).',
        'wp-ultra-mcp'
    ),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'filters' => [
                'type'       => 'object',
                'properties' => [
                    'ids'          => ['type' => 'array', 'items' => ['type' => 'integer']],
                    'category'     => ['type' => 'string'],
                    'type'         => ['type' => 'string'],
                    'stock_status' => ['type' => 'string'],
                    'status'       => ['type' => 'string'],
                    'search'       => ['type' => 'string'],
                    'on_sale'      => ['type' => 'boolean'],
                    'price_min'    => ['type' => 'number'],
                    'price_max'    => ['type' => 'number'],
                    'limit'        => ['type' => 'integer'],
                ],
                'additionalProperties' => false,
            ],
            'changes' => [
                'type'       => 'object',
                'properties' => [
                    'regular_price'      => ['type' => 'number'],
                    'sale_price'         => ['type' => 'number'],
                    'price_adjust'       => [
                        'type'       => 'object',
                        'properties' => [
                            'mode'      => ['type' => 'string', 'enum' => ['percent', 'fixed']],
                            'target'    => ['type' => 'string', 'enum' => ['regular', 'sale']],
                            'direction' => ['type' => 'string', 'enum' => ['increase', 'decrease']],
                            'amount'    => ['type' => 'number'],
                        ],
                    ],
                    'stock_quantity'     => ['type' => 'integer'],
                    'stock_adjust'       => [
                        'type'       => 'object',
                        'properties' => ['amount' => ['type' => 'integer']],
                    ],
                    'manage_stock'       => ['type' => 'boolean'],
                    'stock_status'       => ['type' => 'string', 'enum' => ['instock', 'outofstock', 'onbackorder']],
                    'status'             => ['type' => 'string', 'enum' => ['publish', 'draft', 'private']],
                    'catalog_visibility' => ['type' => 'string', 'enum' => ['visible', 'catalog', 'search', 'hidden']],
                    'add_category'       => ['type' => 'array', 'items' => ['type' => 'string']],
                    'remove_category'    => ['type' => 'array', 'items' => ['type' => 'string']],
                    'tax_class'          => ['type' => 'string'],
                    'sale_from'          => ['type' => 'string'],
                    'sale_to'            => ['type' => 'string'],
                ],
                'additionalProperties' => false,
            ],
            'dry_run' => ['type' => 'boolean', 'default' => true],
            'confirm' => ['type' => 'boolean'],
            'limit'   => ['type' => 'integer'],
        ],
        'required'             => ['filters', 'changes'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'   => ['type' => 'boolean'],
            'dry_run'   => ['type' => 'boolean'],
            'selected'  => ['type' => 'integer'],
            'results'   => ['type' => 'array'],
            'truncated' => ['type' => 'boolean'],
            'summary'   => ['type' => 'object'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_woo_bulk_edit_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_woo_bulk_edit_cb(array $input) {
    if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    if (!function_exists('wpultra_woo_bulk_select')) {
        return wpultra_err('bulk_engine_missing', 'The bulk engine (includes/woocommerce/bulk.php) is not loaded.');
    }

    $filters = is_array($input['filters'] ?? null) ? $input['filters'] : [];
    $changes = is_array($input['changes'] ?? null) ? $input['changes'] : [];
    if (empty($changes)) { return wpultra_err('missing_changes', 'changes must be a non-empty object.'); }

    $valid = wpultra_woo_bulk_validate_changes($changes);
    if ($valid !== true) { return wpultra_err('invalid_changes', (string) $valid); }

    $dry_run = array_key_exists('dry_run', $input) ? ($input['dry_run'] === true) : true;

    // A top-level `limit` is a convenience alias for filters.limit.
    if (isset($input['limit']) && !isset($filters['limit'])) { $filters['limit'] = $input['limit']; }

    // Live edits (dry_run:false) mutate the store in bulk — require explicit confirmation.
    if (!$dry_run && ($input['confirm'] ?? false) !== true) {
        return wpultra_err('bulk_edit_unconfirmed', 'Live bulk edit is destructive. Re-run with dry_run:false and confirm:true.');
    }

    $selected = wpultra_woo_bulk_select($filters);
    $ids = $selected['ids'];

    if (empty($ids)) {
        return wpultra_ok([
            'dry_run'   => $dry_run,
            'selected'  => 0,
            'results'   => [],
            'truncated' => false,
            'summary'   => ['total' => 0, 'updated' => 0, 'failed' => 0],
        ]);
    }

    $res = wpultra_woo_bulk_apply($ids, $changes, $dry_run);
    if (is_wp_error($res)) {
        wpultra_audit_log('woo-bulk-edit', 'failed: ' . $res->get_error_message(), false);
        return $res;
    }

    $results = $res['results'];
    $truncated = count($results) > 100;
    $output_results = $truncated ? array_slice($results, 0, 100) : $results;

    $summary = "selected={$selected['count']} " . ($dry_run ? 'dry-run' : 'live')
        . " updated={$res['summary']['updated']} failed={$res['summary']['failed']}";
    wpultra_audit_log('woo-bulk-edit', $summary, true);

    return wpultra_ok([
        'dry_run'   => $dry_run,
        'selected'  => $selected['count'],
        'results'   => $output_results,
        'truncated' => $truncated,
        'summary'   => $res['summary'],
    ]);
}
