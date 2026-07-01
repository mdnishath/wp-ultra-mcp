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
    // Clamp negative stock to 0 — WooCommerce has no notion of negative on-hand stock.
    if (array_key_exists('stock_quantity', $clean) && (int) $clean['stock_quantity'] < 0) {
        $clean['stock_quantity'] = 0;
    }
    // Setting a stock quantity has no effect unless manage_stock is on; enable it
    // implicitly when a quantity is supplied without an explicit manage_stock.
    if (array_key_exists('stock_quantity', $clean) && !array_key_exists('manage_stock', $clean) && method_exists($p, 'set_manage_stock')) {
        $p->set_manage_stock(true);
    }
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

function wpultra_woo_delete_product(int $id, bool $force) {
    $p = wc_get_product($id);
    if (!$p) { return wpultra_err('product_not_found', "No product with id $id."); }
    $ok = $p->delete($force);
    return ['id' => $id, 'deleted' => (bool) $ok];
}

function wpultra_woo_manage_variation(array $input) {
    $parentId = (int) ($input['parent_id'] ?? 0);
    $parent = wc_get_product($parentId);
    if (!$parent || $parent->get_type() !== 'variable') {
        return wpultra_err('not_variable_product', "Product $parentId is not a variable product.");
    }
    $action = (string) ($input['action'] ?? 'list');

    if ($action === 'list') {
        $rows = [];
        foreach ($parent->get_children() as $vid) {
            $v = wc_get_product($vid);
            if ($v) { $rows[] = ['id' => $vid, 'attributes' => $v->get_attributes(), 'price' => $v->get_price(), 'stock' => $v->get_stock_quantity(), 'sku' => $v->get_sku()]; }
        }
        return ['variations' => $rows, 'count' => count($rows)];
    }

    if ($action === 'delete') {
        $vid = (int) ($input['variation_id'] ?? 0);
        $v = wc_get_product($vid);
        if (!$v || $v->get_parent_id() !== $parentId) { return wpultra_err('variation_not_found', "No variation $vid on product $parentId."); }
        $v->delete(true);
        return ['id' => $vid, 'deleted' => true];
    }

    // create or update
    $vid = (int) ($input['variation_id'] ?? 0);
    $v = ($action === 'update' && $vid) ? wc_get_product($vid) : new WC_Product_Variation();
    if (!$v) { return wpultra_err('variation_not_found', "No variation $vid."); }
    // On update, ensure the id is actually a variation of THIS parent — otherwise set_parent_id
    // would re-home another product's variation or corrupt a simple product.
    if ($action === 'update' && $vid) {
        if ($v->get_type() !== 'variation' || $v->get_parent_id() !== $parentId) {
            return wpultra_err('variation_not_found', "No variation $vid on product $parentId.");
        }
    }
    $v->set_parent_id($parentId);
    if (isset($input['attributes']) && is_array($input['attributes'])) { $v->set_attributes($input['attributes']); }
    if (isset($input['regular_price'])) { $v->set_regular_price((string) $input['regular_price']); }
    if (isset($input['sale_price']))    { $v->set_sale_price((string) $input['sale_price']); }
    if (isset($input['sku']))           { $v->set_sku((string) $input['sku']); }
    if (isset($input['manage_stock']))  { $v->set_manage_stock(wpultra_woo_coerce_bool($input['manage_stock'])); }
    if (isset($input['stock_quantity'])) {
        // Enable manage_stock implicitly (otherwise set_stock_quantity is a no-op),
        // and clamp negative quantities to zero.
        if (!isset($input['manage_stock'])) { $v->set_manage_stock(true); }
        $v->set_stock_quantity(max(0, (int) $input['stock_quantity']));
    }
    if (isset($input['image_id']))      { $v->set_image_id((int) $input['image_id']); }
    $newId = $v->save();
    if (!$newId) { return wpultra_err('variation_save_failed', 'save() returned 0.'); }
    return ['id' => (int) $newId, 'parent_id' => $parentId];
}

function wpultra_woo_manage_term(array $input) {
    $taxonomy = (($input['taxonomy'] ?? 'category') === 'tag') ? 'product_tag' : 'product_cat';
    $action = (string) ($input['action'] ?? 'list');

    if ($action === 'list') {
        $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
        $rows = [];
        foreach ($terms as $t) { $rows[] = ['id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'parent' => $t->parent, 'count' => $t->count]; }
        return ['terms' => $rows, 'count' => count($rows)];
    }
    if ($action === 'delete') {
        $tid = (int) ($input['id'] ?? 0);
        $r = wp_delete_term($tid, $taxonomy);
        if (is_wp_error($r)) { return $r; }
        return ['id' => $tid, 'deleted' => (bool) $r];
    }
    $name = (string) ($input['name'] ?? '');
    $args = [];
    if (isset($input['slug']))        { $args['slug'] = (string) $input['slug']; }
    if (isset($input['parent']))      { $args['parent'] = (int) $input['parent']; }
    if (isset($input['description'])) { $args['description'] = (string) $input['description']; }
    if ($action === 'update') {
        $tid = (int) ($input['id'] ?? 0);
        if ($name !== '') { $args['name'] = $name; }
        $r = wp_update_term($tid, $taxonomy, $args);
    } else {
        $r = wp_insert_term($name, $taxonomy, $args);
    }
    if (is_wp_error($r)) { return $r; }
    return ['id' => (int) $r['term_id'], 'taxonomy' => $taxonomy];
}

function wpultra_woo_manage_attribute(array $input) {
    $action = (string) ($input['action'] ?? 'list');

    if ($action === 'list') {
        $rows = [];
        foreach (wc_get_attribute_taxonomies() as $a) {
            $rows[] = ['id' => (int) $a->attribute_id, 'name' => $a->attribute_label, 'slug' => $a->attribute_name, 'type' => $a->attribute_type];
        }
        return ['attributes' => $rows, 'count' => count($rows)];
    }
    if ($action === 'delete') {
        $id = (int) ($input['id'] ?? 0);
        $ok = wc_delete_attribute($id);
        if (is_wp_error($ok)) { return $ok; }
        return ['id' => $id, 'deleted' => (bool) $ok];
    }
    $payload = [
        'name'         => (string) ($input['name'] ?? ''),
        'slug'         => (string) ($input['slug'] ?? sanitize_title((string) ($input['name'] ?? ''))),
        'type'         => (string) ($input['type'] ?? 'select'),
        'order_by'     => 'menu_order',
        'has_archives' => false,
    ];
    if ($action === 'update') {
        $id = (int) ($input['id'] ?? 0);
        $res = wc_update_attribute($id, $payload);
    } else {
        $res = wc_create_attribute($payload);
    }
    if (is_wp_error($res)) { return $res; }
    $id = is_array($res) ? (int) ($res['id'] ?? 0) : (int) $res;
    // Optionally add terms to the attribute taxonomy.
    $notes = [];
    if (!empty($input['terms']) && is_array($input['terms'])) {
        $tax = wc_attribute_taxonomy_name($payload['slug']);
        if (!taxonomy_exists($tax)) { register_taxonomy($tax, 'product', []); }
        foreach ($input['terms'] as $term) {
            if (!term_exists((string) $term, $tax)) {
                $tr = wp_insert_term((string) $term, $tax);
                if (is_wp_error($tr) && $tr->get_error_code() !== 'term_exists') {
                    $notes[] = 'term_insert_failed:' . (string) $term . ':' . $tr->get_error_message();
                }
            }
        }
    }
    $ret = ['id' => $id, 'slug' => $payload['slug']];
    if (!empty($notes)) { $ret['notes'] = $notes; }
    return $ret;
}
