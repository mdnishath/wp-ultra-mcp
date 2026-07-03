<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/woo-export-products', [
    'label'       => __('WooCommerce: Export Products (CSV)', 'wp-ultra-mcp'),
    'description' => __('Export products to CSV. Filters: status, category (slug), type, limit (default 100, max 500). Set return_csv:true to get the CSV inline (only when <=100 rows); otherwise it is written to a jailed path (default wp-content/uploads/wpultra-exports/products-<date>.csv). Columns: id,name,sku,type,status,regular_price,sale_price,stock_quantity,manage_stock,description,short_description,categories(|),images(|).', 'wp-ultra-mcp'),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'status'     => ['type' => 'string'],
            'category'   => ['type' => 'string'],
            'type'       => ['type' => 'string'],
            'limit'      => ['type' => 'integer'],
            'return_csv' => ['type' => 'boolean'],
            'path'       => ['type' => 'string'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'count'   => ['type' => 'integer'],
            'csv'     => ['type' => 'string'],
            'path'    => ['type' => 'string'],
            'bytes'   => ['type' => 'integer'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_woo_export_products_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_woo_export_products_cb(array $input) {
    if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    $path = isset($input['path']) ? (string) $input['path'] : null;
    $filters = $input;
    unset($filters['path']);
    return wpultra_woo_csv_export($filters, $path);
}
