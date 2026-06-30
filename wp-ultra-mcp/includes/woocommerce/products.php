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
