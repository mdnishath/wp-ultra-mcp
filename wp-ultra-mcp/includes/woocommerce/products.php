<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

function wpultra_woo_product_row($p): array {
    return [
        'id'     => $p->get_id(),
        'name'   => $p->get_name(),
        'type'   => $p->get_type(),
        'status' => $p->get_status(),
        'price'  => $p->get_price(),
        'stock'  => $p->get_stock_quantity(),
        'sku'    => $p->get_sku(),
    ];
}

function wpultra_woo_product_full($p): array {
    $row = wpultra_woo_product_row($p);
    return array_merge($row, [
        'slug'              => $p->get_slug(),
        'regular_price'     => $p->get_regular_price(),
        'sale_price'        => $p->get_sale_price(),
        'description'       => $p->get_description(),
        'short_description' => $p->get_short_description(),
        'manage_stock'      => $p->get_manage_stock(),
        'stock_status'      => $p->get_stock_status(),
        'backorders'        => $p->get_backorders(),
        'weight'            => $p->get_weight(),
        'dimensions'        => ['length' => $p->get_length(), 'width' => $p->get_width(), 'height' => $p->get_height()],
        'virtual'           => $p->get_virtual(),
        'downloadable'      => $p->get_downloadable(),
        'featured'          => $p->get_featured(),
        'catalog_visibility' => $p->get_catalog_visibility(),
        'category_ids'      => $p->get_category_ids(),
        'tag_ids'           => $p->get_tag_ids(),
        'image_id'          => $p->get_image_id(),
        'gallery_image_ids' => $p->get_gallery_image_ids(),
        'attributes'        => array_keys($p->get_attributes()),
        'variation_ids'     => method_exists($p, 'get_children') ? $p->get_children() : [],
        'permalink'         => $p->get_permalink(),
    ]);
}

function wpultra_woo_list_products(array $args): array {
    $q = [
        'limit'    => isset($args['per_page']) ? (int) $args['per_page'] : 20,
        'page'     => isset($args['page']) ? max(1, (int) $args['page']) : 1,
        'paginate' => false,
        'return'   => 'objects',
    ];
    if (!empty($args['search']))        { $q['s'] = (string) $args['search']; }
    if (!empty($args['status']))        { $q['status'] = (string) $args['status']; }
    if (!empty($args['type']))          { $q['type'] = (string) $args['type']; }
    if (!empty($args['stock_status']))  { $q['stock_status'] = (string) $args['stock_status']; }
    if (!empty($args['category']))      { $q['category'] = [(string) $args['category']]; } // slug(s)
    if (!empty($args['on_sale']))       { $q['include'] = wc_get_product_ids_on_sale(); }
    $products = wc_get_products($q);
    $rows = [];
    foreach ($products as $p) { $rows[] = wpultra_woo_product_row($p); }
    return ['count' => count($rows), 'products' => $rows];
}

function wpultra_woo_get_product(int $id) {
    $p = wc_get_product($id);
    if (!$p) { return wpultra_err('product_not_found', "No product with id $id."); }
    return wpultra_woo_product_full($p);
}

/** Instantiate the right product object for a (possibly new) product of a given type. */
function wpultra_woo_make_product(string $type, int $id = 0) {
    $map = [
        'simple'   => 'WC_Product_Simple',
        'variable' => 'WC_Product_Variable',
        'grouped'  => 'WC_Product_Grouped',
        'external' => 'WC_Product_External',
    ];
    $cls = $map[$type] ?? 'WC_Product_Simple';
    return new $cls($id);
}

function wpultra_woo_upsert_product(array $input) {
    $id = isset($input['id']) ? (int) $input['id'] : 0;
    unset($input['id']);
    $validated = wpultra_woo_validate_product($input);
    $clean = $validated['clean'];

    // Determine type (existing product keeps its type unless overridden).
    if ($id) {
        $existing = wc_get_product($id);
        if (!$existing) { return wpultra_err('product_not_found', "No product with id $id."); }
        $type = $clean['type'] ?? $existing->get_type();
    } else {
        $type = $clean['type'] ?? 'simple';
    }
    $p = wpultra_woo_make_product($type, $id);

    // Apply scalar setters present in $clean.
    $setters = [
        'name' => 'set_name', 'slug' => 'set_slug', 'status' => 'set_status',
        'catalog_visibility' => 'set_catalog_visibility', 'featured' => 'set_featured',
        'description' => 'set_description', 'short_description' => 'set_short_description',
        'sku' => 'set_sku', 'regular_price' => 'set_regular_price', 'sale_price' => 'set_sale_price',
        'manage_stock' => 'set_manage_stock', 'stock_quantity' => 'set_stock_quantity',
        'stock_status' => 'set_stock_status', 'backorders' => 'set_backorders',
        'weight' => 'set_weight', 'length' => 'set_length', 'width' => 'set_width', 'height' => 'set_height',
        'virtual' => 'set_virtual', 'downloadable' => 'set_downloadable',
        'category_ids' => 'set_category_ids', 'tag_ids' => 'set_tag_ids',
        'image_id' => 'set_image_id', 'gallery_image_ids' => 'set_gallery_image_ids',
        'menu_order' => 'set_menu_order',
    ];
    foreach ($setters as $field => $method) {
        if (array_key_exists($field, $clean) && method_exists($p, $method)) {
            try { $p->{$method}($clean[$field]); } catch (\Throwable $e) {
                $validated['rejected'][] = ['field' => $field, 'reason' => 'setter_error'];
            }
        }
    }
    // External product extras.
    if ($type === 'external') {
        if (isset($clean['external_url'])) { $p->set_product_url($clean['external_url']); }
        if (isset($clean['button_text'])) { $p->set_button_text($clean['button_text']); }
    }

    $newId = $p->save();
    if (!$newId) { return wpultra_err('product_save_failed', 'WooCommerce save() returned 0.'); }
    return ['id' => (int) $newId, 'rejected' => $validated['rejected']];
}
