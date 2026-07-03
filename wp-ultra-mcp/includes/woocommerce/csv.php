<?php
declare(strict_types=1);
if (!defined('ABSPATH') && !defined('WPULTRA_TEST')) { /* allow harness load */ }

/**
 * WooCommerce CSV product import/export engine (roadmap #18).
 *
 * The parse/build/row_to_product/product_to_row helpers are PURE (no WordPress or
 * WooCommerce dependency) so they are unit-testable via tests/woo-csv.test.php.
 * The export/import functions touch WooCommerce CRUD and are exercised at runtime.
 */

// ---------------------------------------------------------------------------
// PURE: CSV parsing
// ---------------------------------------------------------------------------

/**
 * Split raw CSV text into logical records, respecting quoted fields that may
 * themselves contain commas, newlines, or escaped ("") quotes. Returns an array
 * of raw line strings, each a complete CSV record. Pure.
 */
function wpultra_woo_csv_split_records(string $csv): array {
    // Normalize line endings (leave embedded ones inside quotes intact — we scan
    // character-by-character, so CRLF outside quotes becomes a record boundary).
    $csv = str_replace(["\r\n", "\r"], "\n", $csv);
    $records = [];
    $current = '';
    $in_quotes = false;
    $len = strlen($csv);
    for ($i = 0; $i < $len; $i++) {
        $ch = $csv[$i];
        if ($ch === '"') {
            // A doubled quote inside a quoted field is an escaped quote — consume both
            // and stay in-quotes so the boundary detection is not confused.
            if ($in_quotes && $i + 1 < $len && $csv[$i + 1] === '"') {
                $current .= '""';
                $i++;
                continue;
            }
            $in_quotes = !$in_quotes;
            $current .= $ch;
            continue;
        }
        if ($ch === "\n" && !$in_quotes) {
            $records[] = $current;
            $current = '';
            continue;
        }
        $current .= $ch;
    }
    // Trailing record without a final newline.
    if ($current !== '' || $in_quotes) {
        $records[] = $current;
    }
    // Drop trailing empty records (e.g. a file ending in a newline).
    while (!empty($records) && trim(end($records)) === '') {
        array_pop($records);
    }
    return $records;
}

/**
 * Parse CSV text into a list of associative rows keyed by the header row.
 * Handles quoted fields with embedded commas/newlines and escaped ("") quotes.
 * Extra columns beyond the header are dropped; missing trailing columns become ''.
 * Pure.
 */
function wpultra_woo_csv_parse(string $csv): array {
    $records = wpultra_woo_csv_split_records($csv);
    if (empty($records)) { return []; }
    $header = str_getcsv(array_shift($records), ',', '"', '');
    $header = array_map(static function ($h) { return trim((string) $h); }, $header);
    $rows = [];
    foreach ($records as $rec) {
        if (trim($rec) === '') { continue; }
        $fields = str_getcsv($rec, ',', '"', '');
        $row = [];
        foreach ($header as $idx => $key) {
            if ($key === '') { continue; }
            $row[$key] = array_key_exists($idx, $fields) ? (string) $fields[$idx] : '';
        }
        $rows[] = $row;
    }
    return $rows;
}

// ---------------------------------------------------------------------------
// PURE: CSV building
// ---------------------------------------------------------------------------

/** Quote a single CSV field only when needed (comma, quote, newline). Pure. */
function wpultra_woo_csv_quote_field(string $value): string {
    if (preg_match('/[",\r\n]/', $value)) {
        return '"' . str_replace('"', '""', $value) . '"';
    }
    return $value;
}

/**
 * Build a CSV string from an array of associative rows and an explicit column
 * order. Every output row emits exactly the given columns (missing keys => '').
 * Uses CRLF line endings (Excel-friendly). Pure.
 */
function wpultra_woo_csv_build(array $rows, array $columns): string {
    $lines = [];
    $lines[] = implode(',', array_map('wpultra_woo_csv_quote_field', array_map('strval', $columns)));
    foreach ($rows as $row) {
        $cells = [];
        foreach ($columns as $col) {
            $v = $row[$col] ?? '';
            if (is_array($v)) { $v = implode('|', $v); }
            $cells[] = wpultra_woo_csv_quote_field((string) $v);
        }
        $lines[] = implode(',', $cells);
    }
    return implode("\r\n", $lines) . "\r\n";
}

// ---------------------------------------------------------------------------
// PURE: row <-> product mapping
// ---------------------------------------------------------------------------

/** Canonical CSV column order for product export. Pure. */
function wpultra_woo_csv_columns(): array {
    return [
        'id', 'name', 'sku', 'type', 'status',
        'regular_price', 'sale_price', 'stock_quantity', 'manage_stock',
        'description', 'short_description', 'categories', 'images',
    ];
}

/**
 * Normalize a raw CSV row into a product input array plus per-row validation
 * errors. Does NOT touch WooCommerce. Returns:
 *   ['product' => [...normalized fields...], 'errors' => ['msg', ...]]
 * Pure.
 */
function wpultra_woo_csv_row_to_product(array $row): array {
    // Case-insensitive key lookup so headers like "Name"/"SKU" still map.
    $lc = [];
    foreach ($row as $k => $v) { $lc[strtolower(trim((string) $k))] = $v; }
    $get = static function (string $key) use ($lc) {
        return isset($lc[$key]) ? trim((string) $lc[$key]) : '';
    };

    $errors = [];
    $product = [];

    $name = $get('name');
    if ($name === '') {
        $errors[] = 'name is required';
    } else {
        $product['name'] = $name;
    }

    $sku = $get('sku');
    if ($sku !== '') { $product['sku'] = $sku; }

    // Type: default to 'simple'; reject unknown types.
    $type = strtolower($get('type'));
    if ($type === '') {
        $type = 'simple';
    } elseif (!in_array($type, ['simple', 'variable', 'grouped', 'external'], true)) {
        $errors[] = "invalid type: $type";
        $type = 'simple';
    }
    $product['type'] = $type;

    // Prices: numeric, non-negative.
    foreach (['regular_price', 'sale_price'] as $pk) {
        $raw = $get($pk);
        if ($raw === '') { continue; }
        if (!is_numeric($raw)) {
            $errors[] = "$pk is not numeric: $raw";
            continue;
        }
        if ((float) $raw < 0) {
            $errors[] = "$pk is negative: $raw";
            continue;
        }
        $product[$pk] = (string) (0 + $raw);
    }
    // sale_price must not exceed regular_price when both present.
    if (isset($product['sale_price'], $product['regular_price'])
        && (float) $product['sale_price'] > (float) $product['regular_price']) {
        $errors[] = 'sale_price exceeds regular_price';
        unset($product['sale_price']);
    }

    $desc = $get('description');
    if ($desc !== '') { $product['description'] = $desc; }
    $short = $get('short_description');
    if ($short !== '') { $product['short_description'] = $short; }

    // Stock quantity: integer, non-negative; presence implies manage_stock.
    $sq = $get('stock_quantity');
    if ($sq !== '') {
        if (!is_numeric($sq) || (int) $sq != (float) $sq) {
            $errors[] = "stock_quantity is not an integer: $sq";
        } else {
            $product['stock_quantity'] = max(0, (int) $sq);
        }
    }
    // manage_stock: explicit column overrides; else implied by a stock quantity.
    $ms = $get('manage_stock');
    if ($ms !== '') {
        $product['manage_stock'] = in_array(strtolower($ms), ['1', 'yes', 'true', 'on'], true);
    } elseif (isset($product['stock_quantity'])) {
        $product['manage_stock'] = true;
    }

    // Categories and images: pipe-separated lists.
    $cats = $get('categories');
    if ($cats !== '') {
        $product['categories'] = array_values(array_filter(array_map('trim', explode('|', $cats)), static function ($s) { return $s !== ''; }));
    }
    $imgs = $get('images');
    if ($imgs !== '') {
        $product['images'] = array_values(array_filter(array_map('trim', explode('|', $imgs)), static function ($s) { return $s !== ''; }));
    }

    // Status: default publish; restrict to known set.
    $status = strtolower($get('status'));
    if ($status === '') {
        $product['status'] = 'publish';
    } elseif (!in_array($status, ['publish', 'draft', 'pending', 'private'], true)) {
        $errors[] = "invalid status: $status";
        $product['status'] = 'publish';
    } else {
        $product['status'] = $status;
    }

    return ['product' => $product, 'errors' => $errors];
}

/**
 * Convert a normalized product-data array (from wpultra_woo_product_export_data
 * or equivalent) into a flat CSV row for the given columns. Pure.
 */
function wpultra_woo_csv_product_to_row(array $productdata, array $columns): array {
    $row = [];
    foreach ($columns as $col) {
        switch ($col) {
            case 'manage_stock':
                $v = $productdata['manage_stock'] ?? '';
                $row[$col] = ($v === '' || $v === null) ? '' : (($v ? 'yes' : 'no'));
                break;
            case 'categories':
            case 'images':
                $v = $productdata[$col] ?? [];
                $row[$col] = is_array($v) ? implode('|', $v) : (string) $v;
                break;
            default:
                $v = $productdata[$col] ?? '';
                $row[$col] = is_array($v) ? implode('|', $v) : (string) $v;
        }
    }
    return $row;
}

// ---------------------------------------------------------------------------
// Runtime: export (touches WooCommerce)
// ---------------------------------------------------------------------------

/** Flatten a WC_Product into the associative shape product_to_row expects. */
function wpultra_woo_csv_export_product_data($p): array {
    $cat_names = [];
    if (function_exists('get_the_terms')) {
        $terms = get_the_terms($p->get_id(), 'product_cat');
        if (is_array($terms)) {
            foreach ($terms as $t) { $cat_names[] = $t->name; }
        }
    }
    $images = [];
    $img_id = $p->get_image_id();
    if ($img_id && function_exists('wp_get_attachment_url')) {
        $url = wp_get_attachment_url($img_id);
        if ($url) { $images[] = $url; }
    }
    if (function_exists('wp_get_attachment_url')) {
        foreach ($p->get_gallery_image_ids() as $gid) {
            $url = wp_get_attachment_url($gid);
            if ($url) { $images[] = $url; }
        }
    }
    return [
        'id'                => $p->get_id(),
        'name'              => $p->get_name(),
        'sku'               => $p->get_sku(),
        'type'              => $p->get_type(),
        'status'            => $p->get_status(),
        'regular_price'     => $p->get_regular_price(),
        'sale_price'        => $p->get_sale_price(),
        'stock_quantity'    => $p->get_stock_quantity(),
        'manage_stock'      => $p->get_manage_stock(),
        'description'       => $p->get_description(),
        'short_description' => $p->get_short_description(),
        'categories'        => $cat_names,
        'images'            => $images,
    ];
}

/**
 * Export products matching $filters to CSV.
 * Filters: status, category (slug), limit (default 100, max 500).
 * If $filters['return_csv'] is true and the result is <= 100 rows, the CSV is
 * returned inline. Otherwise it is written to a jailed path (given $path, else
 * a default under uploads/wpultra-exports/).
 *
 * Returns ['success'=>true, 'count'=>N, ...] with either 'csv' or 'path'.
 */
function wpultra_woo_csv_export(array $filters, ?string $path = null) {
    if (!function_exists('wc_get_products')) {
        return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.');
    }
    $limit = isset($filters['limit']) ? (int) $filters['limit'] : 100;
    if ($limit < 1) { $limit = 1; }
    if ($limit > 500) { $limit = 500; }

    $q = ['limit' => $limit, 'paginate' => false, 'return' => 'objects'];
    if (!empty($filters['status']))   { $q['status'] = (string) $filters['status']; }
    if (!empty($filters['category'])) { $q['category'] = [(string) $filters['category']]; }
    if (!empty($filters['type']))     { $q['type'] = (string) $filters['type']; }

    $products = wc_get_products($q);
    $columns = wpultra_woo_csv_columns();
    $rows = [];
    foreach ($products as $p) {
        $rows[] = wpultra_woo_csv_product_to_row(wpultra_woo_csv_export_product_data($p), $columns);
    }
    $csv = wpultra_woo_csv_build($rows, $columns);
    $count = count($rows);

    $return_csv = !empty($filters['return_csv']);
    if ($return_csv && $count <= 100) {
        return wpultra_ok(['count' => $count, 'csv' => $csv]);
    }

    // Write to a jailed path.
    if ($path === null || trim($path) === '') {
        $date = function_exists('current_time') ? current_time('Ymd-His') : gmdate('Ymd-His');
        $rel = 'wp-content/uploads/wpultra-exports/products-' . $date . '.csv';
        if (function_exists('wp_upload_dir')) {
            $up = wp_upload_dir();
            if (!empty($up['basedir'])) {
                $dir = rtrim((string) $up['basedir'], '/\\') . '/wpultra-exports';
                if (!is_dir($dir) && function_exists('wp_mkdir_p')) { wp_mkdir_p($dir); }
                $rel = $dir . '/products-' . $date . '.csv';
            }
        }
        $path = $rel;
    }
    $resolved = wpultra_resolve_path($path, false);
    if (is_wp_error($resolved)) { return $resolved; }
    $dir = dirname($resolved);
    if (!is_dir($dir) && function_exists('wp_mkdir_p')) { wp_mkdir_p($dir); }
    $written = @file_put_contents($resolved, $csv);
    if ($written === false) {
        return wpultra_err('export_write_failed', "Could not write CSV to $resolved.");
    }
    return wpultra_ok(['count' => $count, 'path' => $resolved, 'bytes' => (int) $written]);
}

// ---------------------------------------------------------------------------
// Runtime: import (touches WooCommerce)
// ---------------------------------------------------------------------------

/** Best-effort sideload of a remote image URL into the media library; returns attachment id or 0. */
function wpultra_woo_csv_sideload_image(string $url, int $post_id = 0): int {
    if ($url === '' || !function_exists('media_sideload_image')) { return 0; }
    if (!function_exists('download_url')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }
    $id = media_sideload_image($url, $post_id, null, 'id');
    if (is_wp_error($id) || !$id) { return 0; }
    return (int) $id;
}

/** Resolve a list of category names/slugs to term ids, creating missing ones. */
function wpultra_woo_csv_resolve_categories(array $names): array {
    $ids = [];
    foreach ($names as $name) {
        $name = (string) $name;
        if ($name === '') { continue; }
        $term = get_term_by('name', $name, 'product_cat');
        if (!$term) { $term = get_term_by('slug', sanitize_title($name), 'product_cat'); }
        if ($term && !is_wp_error($term)) {
            $ids[] = (int) $term->term_id;
            continue;
        }
        $created = wp_insert_term($name, 'product_cat');
        if (!is_wp_error($created) && isset($created['term_id'])) {
            $ids[] = (int) $created['term_id'];
        }
    }
    return $ids;
}

/**
 * Import products from CSV text.
 *   $dry_run         — when true, only report the per-row plan; no writes.
 *   $update_existing — when true, rows whose SKU matches an existing product
 *                      update it; when false, matching rows are skipped.
 * Returns ['success'=>true, 'created'=>N, 'updated'=>N, 'skipped'=>N,
 *          'errors'=>[...], 'rows'=>[per-row plan]].
 */
function wpultra_woo_csv_import(string $csv, bool $dry_run, bool $update_existing) {
    $parsed = wpultra_woo_csv_parse($csv);
    if (empty($parsed)) {
        return wpultra_err('empty_csv', 'CSV contained no data rows.');
    }
    $created = 0; $updated = 0; $skipped = 0;
    $errors = [];
    $plan = [];

    foreach ($parsed as $i => $raw) {
        $line = $i + 2; // +1 for header, +1 for 1-based
        $norm = wpultra_woo_csv_row_to_product($raw);
        $product = $norm['product'];
        $rowErrors = $norm['errors'];

        if (!empty($rowErrors)) {
            foreach ($rowErrors as $e) { $errors[] = "row $line: $e"; }
            // A missing name is fatal for the row; skip it.
            if (in_array('name is required', $rowErrors, true)) {
                $skipped++;
                $plan[] = ['row' => $line, 'action' => 'skip', 'reason' => 'validation', 'errors' => $rowErrors];
                continue;
            }
        }

        $sku = $product['sku'] ?? '';
        $existingId = 0;
        if ($sku !== '' && function_exists('wc_get_product_id_by_sku')) {
            $existingId = (int) wc_get_product_id_by_sku($sku);
        }

        if ($existingId && !$update_existing) {
            $skipped++;
            $plan[] = ['row' => $line, 'action' => 'skip', 'reason' => 'exists', 'id' => $existingId, 'sku' => $sku];
            continue;
        }

        $action = $existingId ? 'update' : 'create';
        if ($dry_run) {
            $plan[] = ['row' => $line, 'action' => $action, 'id' => $existingId ?: null, 'sku' => $sku, 'name' => $product['name'] ?? '', 'errors' => $rowErrors];
            if ($action === 'create') { $created++; } else { $updated++; }
            continue;
        }

        // Live write.
        $res = wpultra_woo_csv_apply_row($product, $existingId);
        if (is_wp_error($res)) {
            $errors[] = "row $line: " . $res->get_error_message();
            $skipped++;
            $plan[] = ['row' => $line, 'action' => 'skip', 'reason' => 'save_failed', 'sku' => $sku];
            continue;
        }
        if ($action === 'create') { $created++; } else { $updated++; }
        $plan[] = ['row' => $line, 'action' => $action, 'id' => (int) $res, 'sku' => $sku];
    }

    return wpultra_ok([
        'dry_run' => $dry_run,
        'created' => $created,
        'updated' => $updated,
        'skipped' => $skipped,
        'errors'  => $errors,
        'rows'    => $plan,
    ]);
}

/** Create/update a single product from normalized CSV input via WC CRUD. Returns product id or WP_Error. */
function wpultra_woo_csv_apply_row(array $product, int $existingId) {
    if ($existingId) {
        $p = wc_get_product($existingId);
        if (!$p) { return wpultra_err('product_not_found', "No product with id $existingId."); }
    } else {
        $type = $product['type'] ?? 'simple';
        $p = function_exists('wpultra_woo_make_product')
            ? wpultra_woo_make_product($type, 0)
            : new WC_Product_Simple();
    }

    if (isset($product['name']))              { $p->set_name((string) $product['name']); }
    if (isset($product['sku']) && $product['sku'] !== '') { $p->set_sku((string) $product['sku']); }
    if (isset($product['status']))            { $p->set_status((string) $product['status']); }
    if (isset($product['description']))       { $p->set_description((string) $product['description']); }
    if (isset($product['short_description'])) { $p->set_short_description((string) $product['short_description']); }
    if (isset($product['regular_price']))     { $p->set_regular_price((string) $product['regular_price']); }
    if (isset($product['sale_price']))        { $p->set_sale_price((string) $product['sale_price']); }
    if (isset($product['manage_stock']))      { $p->set_manage_stock((bool) $product['manage_stock']); }
    if (isset($product['stock_quantity'])) {
        if (!isset($product['manage_stock'])) { $p->set_manage_stock(true); }
        $p->set_stock_quantity(max(0, (int) $product['stock_quantity']));
    }
    if (!empty($product['categories']) && is_array($product['categories'])) {
        $ids = wpultra_woo_csv_resolve_categories($product['categories']);
        if (!empty($ids)) { $p->set_category_ids($ids); }
    }

    $id = $p->save();
    if (!$id) { return wpultra_err('product_save_failed', 'WooCommerce save() returned 0.'); }

    // Images are sideloaded best-effort AFTER the product exists (so they attach).
    if (!empty($product['images']) && is_array($product['images'])) {
        $attIds = [];
        foreach ($product['images'] as $url) {
            $att = wpultra_woo_csv_sideload_image((string) $url, (int) $id);
            if ($att) { $attIds[] = $att; }
        }
        if (!empty($attIds)) {
            $p->set_image_id(array_shift($attIds));
            if (!empty($attIds)) { $p->set_gallery_image_ids($attIds); }
            $p->save();
        }
    }

    return (int) $id;
}
