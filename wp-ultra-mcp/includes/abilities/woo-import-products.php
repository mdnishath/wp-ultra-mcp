<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/woo-import-products', [
    'label'       => __('WooCommerce: Import Products (CSV)', 'wp-ultra-mcp'),
    'description' => __('Import products from CSV. Supply csv_text OR path (jailed read). Columns: name (required), sku, type (default simple), status, regular_price, sale_price, stock_quantity, manage_stock, description, short_description, categories(|-separated), images(|-separated urls, sideloaded best-effort). Rows are matched by SKU. dry_run:true (default) returns the per-row plan with no writes; dry_run:false requires confirm:true. update_existing (default true) updates SKU matches; when false they are skipped. Returns {created, updated, skipped, errors[], rows[]}.', 'wp-ultra-mcp'),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'csv_text'        => ['type' => 'string'],
            'path'            => ['type' => 'string'],
            'dry_run'         => ['type' => 'boolean', 'default' => true],
            'update_existing' => ['type' => 'boolean', 'default' => true],
            'confirm'         => ['type' => 'boolean'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'dry_run' => ['type' => 'boolean'],
            'created' => ['type' => 'integer'],
            'updated' => ['type' => 'integer'],
            'skipped' => ['type' => 'integer'],
            'errors'  => ['type' => 'array'],
            'rows'    => ['type' => 'array'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_woo_import_products_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_woo_import_products_cb(array $input) {
    if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }

    $dry_run = array_key_exists('dry_run', $input) ? ($input['dry_run'] === true) : true;
    $update_existing = array_key_exists('update_existing', $input) ? ($input['update_existing'] === true) : true;

    // Live imports (dry_run:false) mutate the store — require explicit confirmation.
    if (!$dry_run && ($input['confirm'] ?? false) !== true) {
        return wpultra_err('import_unconfirmed', 'Live import is destructive/bulk. Re-run with dry_run:false and confirm:true.');
    }

    // Resolve the CSV source: inline text or a jailed file.
    $csv = '';
    if (isset($input['csv_text']) && trim((string) $input['csv_text']) !== '') {
        $csv = (string) $input['csv_text'];
    } elseif (isset($input['path']) && trim((string) $input['path']) !== '') {
        $resolved = wpultra_resolve_path((string) $input['path'], true);
        if (is_wp_error($resolved)) { return $resolved; }
        $csv = (string) @file_get_contents($resolved);
        if ($csv === '') { return wpultra_err('empty_file', "CSV file is empty or unreadable: $resolved"); }
    } else {
        return wpultra_err('missing_source', 'Provide csv_text or path.');
    }

    $res = wpultra_woo_csv_import($csv, $dry_run, $update_existing);
    $summary = is_wp_error($res)
        ? 'failed'
        : ($dry_run ? 'dry-run' : 'live') . " created={$res['created']} updated={$res['updated']} skipped={$res['skipped']}";
    wpultra_audit_log('woo-import-products', $summary, !is_wp_error($res));
    return $res;
}
