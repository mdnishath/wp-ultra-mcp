<?php
require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../wp-ultra-mcp/includes/woocommerce/csv.php';

// ---------------------------------------------------------------------------
// parse
// ---------------------------------------------------------------------------

it('parses simple header-mapped rows', function () {
    $csv = "name,sku,regular_price\nHat,HAT-1,9.99\nMug,MUG-1,5\n";
    $rows = wpultra_woo_csv_parse($csv);
    assert_eq(2, count($rows));
    assert_eq('Hat', $rows[0]['name']);
    assert_eq('HAT-1', $rows[0]['sku']);
    assert_eq('9.99', $rows[0]['regular_price']);
    assert_eq('Mug', $rows[1]['name']);
});

it('parses quoted fields containing commas', function () {
    $csv = "name,description\n\"Big, Red Hat\",\"A hat, with a comma\"\n";
    $rows = wpultra_woo_csv_parse($csv);
    assert_eq('Big, Red Hat', $rows[0]['name']);
    assert_eq('A hat, with a comma', $rows[0]['description']);
});

it('parses quoted field with embedded newline', function () {
    $csv = "name,description\nHat,\"line one\nline two\"\nMug,plain\n";
    $rows = wpultra_woo_csv_parse($csv);
    assert_eq(2, count($rows));
    assert_eq("line one\nline two", $rows[0]['description']);
    assert_eq('Mug', $rows[1]['name']);
});

it('parses escaped doubled quotes', function () {
    $csv = "name,description\nHat,\"He said \"\"hi\"\" loudly\"\n";
    $rows = wpultra_woo_csv_parse($csv);
    assert_eq('He said "hi" loudly', $rows[0]['description']);
});

it('handles CRLF line endings and trailing newline', function () {
    $csv = "name,sku\r\nHat,H1\r\nMug,M1\r\n";
    $rows = wpultra_woo_csv_parse($csv);
    assert_eq(2, count($rows));
    assert_eq('Hat', $rows[0]['name']);
    assert_eq('M1', $rows[1]['sku']);
});

it('pads missing trailing columns with empty string', function () {
    $csv = "name,sku,regular_price\nHat\n";
    $rows = wpultra_woo_csv_parse($csv);
    assert_eq('Hat', $rows[0]['name']);
    assert_eq('', $rows[0]['sku']);
    assert_eq('', $rows[0]['regular_price']);
});

// ---------------------------------------------------------------------------
// build + round-trip
// ---------------------------------------------------------------------------

it('builds CSV with proper quoting', function () {
    $cols = ['name', 'description'];
    $rows = [['name' => 'Big, Red', 'description' => "has \"quote\"\nand newline"]];
    $out = wpultra_woo_csv_build($rows, $cols);
    assert_contains('"Big, Red"', $out);
    assert_contains('"has ""quote""', $out);
});

it('build->parse round-trips values', function () {
    $cols = ['name', 'sku', 'description'];
    $rows = [
        ['name' => 'Big, Hat', 'sku' => 'H1', 'description' => "multi\nline \"q\""],
        ['name' => 'Mug', 'sku' => 'M1', 'description' => 'plain'],
    ];
    $csv = wpultra_woo_csv_build($rows, $cols);
    $back = wpultra_woo_csv_parse($csv);
    assert_eq(2, count($back));
    assert_eq('Big, Hat', $back[0]['name']);
    assert_eq("multi\nline \"q\"", $back[0]['description']);
    assert_eq('Mug', $back[1]['name']);
    assert_eq('M1', $back[1]['sku']);
});

it('build joins array cells with pipe', function () {
    $cols = ['name', 'categories'];
    $rows = [['name' => 'Hat', 'categories' => ['Apparel', 'Sale']]];
    $out = wpultra_woo_csv_build($rows, $cols);
    assert_contains('Apparel|Sale', $out);
});

// ---------------------------------------------------------------------------
// row_to_product validation matrix
// ---------------------------------------------------------------------------

it('row_to_product defaults type to simple and status to publish', function () {
    $r = wpultra_woo_csv_row_to_product(['name' => 'Hat']);
    assert_eq([], $r['errors']);
    assert_eq('simple', $r['product']['type']);
    assert_eq('publish', $r['product']['status']);
    assert_eq('Hat', $r['product']['name']);
});

it('row_to_product flags missing name', function () {
    $r = wpultra_woo_csv_row_to_product(['sku' => 'H1']);
    assert_true(in_array('name is required', $r['errors'], true));
    assert_true(!isset($r['product']['name']));
});

it('row_to_product flags bad price', function () {
    $r = wpultra_woo_csv_row_to_product(['name' => 'Hat', 'regular_price' => 'abc']);
    assert_eq(1, count($r['errors']));
    assert_contains('not numeric', $r['errors'][0]);
    assert_true(!isset($r['product']['regular_price']));
});

it('row_to_product flags negative price', function () {
    $r = wpultra_woo_csv_row_to_product(['name' => 'Hat', 'regular_price' => '-5']);
    assert_contains('negative', $r['errors'][0]);
    assert_true(!isset($r['product']['regular_price']));
});

it('row_to_product rejects sale_price above regular_price', function () {
    $r = wpultra_woo_csv_row_to_product(['name' => 'Hat', 'regular_price' => '10', 'sale_price' => '20']);
    assert_true(in_array('sale_price exceeds regular_price', $r['errors'], true));
    assert_true(!isset($r['product']['sale_price']));
    assert_eq('10', $r['product']['regular_price']);
});

it('row_to_product flags invalid type but keeps row', function () {
    $r = wpultra_woo_csv_row_to_product(['name' => 'Hat', 'type' => 'wormhole']);
    assert_contains('invalid type', $r['errors'][0]);
    assert_eq('simple', $r['product']['type']);
});

it('row_to_product parses stock and implies manage_stock', function () {
    $r = wpultra_woo_csv_row_to_product(['name' => 'Hat', 'stock_quantity' => '7']);
    assert_eq([], $r['errors']);
    assert_eq(7, $r['product']['stock_quantity']);
    assert_eq(true, $r['product']['manage_stock']);
});

it('row_to_product flags non-integer stock', function () {
    $r = wpultra_woo_csv_row_to_product(['name' => 'Hat', 'stock_quantity' => '3.5']);
    assert_contains('not an integer', $r['errors'][0]);
    assert_true(!isset($r['product']['stock_quantity']));
});

it('row_to_product splits categories and images on pipe', function () {
    $r = wpultra_woo_csv_row_to_product([
        'name' => 'Hat',
        'categories' => 'Apparel|Sale',
        'images' => 'http://x/a.jpg|http://x/b.jpg',
    ]);
    assert_eq(['Apparel', 'Sale'], $r['product']['categories']);
    assert_eq(2, count($r['product']['images']));
});

it('row_to_product is header-case-insensitive', function () {
    $r = wpultra_woo_csv_row_to_product(['Name' => 'Hat', 'SKU' => 'H1', 'Regular_Price' => '9']);
    assert_eq('Hat', $r['product']['name']);
    assert_eq('H1', $r['product']['sku']);
    assert_eq('9', $r['product']['regular_price']);
});

// ---------------------------------------------------------------------------
// product_to_row
// ---------------------------------------------------------------------------

it('product_to_row emits columns in order with manage_stock as yes/no', function () {
    $cols = ['id', 'name', 'sku', 'manage_stock', 'categories'];
    $data = ['id' => 5, 'name' => 'Hat', 'sku' => 'H1', 'manage_stock' => true, 'categories' => ['A', 'B']];
    $row = wpultra_woo_csv_product_to_row($data, $cols);
    assert_eq('5', $row['id']);
    assert_eq('Hat', $row['name']);
    assert_eq('yes', $row['manage_stock']);
    assert_eq('A|B', $row['categories']);
});

it('product_to_row leaves manage_stock empty when unset', function () {
    $cols = ['name', 'manage_stock'];
    $row = wpultra_woo_csv_product_to_row(['name' => 'Hat'], $cols);
    assert_eq('', $row['manage_stock']);
});

it('product_to_row round-trips through build+parse', function () {
    $cols = wpultra_woo_csv_columns();
    $data = [
        'id' => 1, 'name' => 'Fancy, Hat', 'sku' => 'H1', 'type' => 'simple', 'status' => 'publish',
        'regular_price' => '9.99', 'sale_price' => '', 'stock_quantity' => '4', 'manage_stock' => false,
        'description' => "line\nbreak", 'short_description' => '', 'categories' => ['Apparel'], 'images' => [],
    ];
    $row = wpultra_woo_csv_product_to_row($data, $cols);
    $csv = wpultra_woo_csv_build([$row], $cols);
    $back = wpultra_woo_csv_parse($csv);
    assert_eq('Fancy, Hat', $back[0]['name']);
    assert_eq('no', $back[0]['manage_stock']);
    assert_eq('Apparel', $back[0]['categories']);
    assert_eq("line\nbreak", $back[0]['description']);
});

run_tests();
