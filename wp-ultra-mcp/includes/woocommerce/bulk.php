<?php
declare(strict_types=1);
if (!defined('ABSPATH') && !defined('WPULTRA_TEST')) { /* allow harness load */ }

/**
 * WooCommerce bulk product operations (roadmap-2 S4).
 *
 * new_price / validate_changes / diff are PURE (no WordPress or WooCommerce
 * dependency) so they are unit-testable via tests/woo-bulk.test.php. select()
 * and apply() touch wc_get_products()/WC_Product CRUD and are exercised at
 * runtime only.
 */

// ---------------------------------------------------------------------------
// PURE: price math
// ---------------------------------------------------------------------------

/**
 * Compute a new price from a current price and a price_adjust spec:
 *   ['mode' => 'percent'|'fixed', 'direction' => 'increase'|'decrease', 'amount' => float]
 * Result is clamped to >= 0 and rounded to 2 decimal places. Pure.
 */
function wpultra_woo_bulk_new_price(float $current, array $adjust): float {
    $mode      = (string) ($adjust['mode'] ?? 'fixed');
    $direction = (string) ($adjust['direction'] ?? 'increase');
    $amount    = (float) ($adjust['amount'] ?? 0);
    $sign      = $direction === 'decrease' ? -1 : 1;

    if ($mode === 'percent') {
        $delta = $current * ($amount / 100);
    } else {
        $delta = $amount;
    }

    $new = $current + ($sign * $delta);
    if ($new < 0) { $new = 0.0; }
    return round($new, 2);
}

// ---------------------------------------------------------------------------
// PURE: change-set validation
// ---------------------------------------------------------------------------

/** Known top-level keys accepted by wpultra_woo_bulk_apply(). Pure. */
function wpultra_woo_bulk_known_change_keys(): array {
    return [
        'regular_price', 'sale_price', 'price_adjust',
        'stock_quantity', 'stock_adjust', 'manage_stock', 'stock_status',
        'status', 'catalog_visibility',
        'add_category', 'remove_category', 'tax_class',
        'sale_from', 'sale_to',
    ];
}

/**
 * Validate a $changes array. Returns true when valid, or a string describing
 * the first problem found. Checks: only known top-level keys; price_adjust /
 * stock_adjust shape; sane enum values; sale_price < regular_price when both
 * are being set as plain values. Pure.
 */
function wpultra_woo_bulk_validate_changes(array $changes) {
    if (empty($changes)) { return 'changes must not be empty'; }

    $known = wpultra_woo_bulk_known_change_keys();
    foreach (array_keys($changes) as $key) {
        if (!in_array($key, $known, true)) {
            return "unknown change key: $key";
        }
    }

    if (array_key_exists('price_adjust', $changes)) {
        $adj = $changes['price_adjust'];
        if (!is_array($adj)) { return 'price_adjust must be an object'; }
        $mode = $adj['mode'] ?? null;
        if (!in_array($mode, ['percent', 'fixed'], true)) { return 'price_adjust.mode must be percent or fixed'; }
        $target = $adj['target'] ?? null;
        if (!in_array($target, ['regular', 'sale'], true)) { return 'price_adjust.target must be regular or sale'; }
        $direction = $adj['direction'] ?? null;
        if (!in_array($direction, ['increase', 'decrease'], true)) { return 'price_adjust.direction must be increase or decrease'; }
        if (!isset($adj['amount']) || !is_numeric($adj['amount'])) { return 'price_adjust.amount must be numeric'; }
        if ((float) $adj['amount'] < 0) { return 'price_adjust.amount must not be negative'; }
    }

    if (array_key_exists('stock_adjust', $changes)) {
        $adj = $changes['stock_adjust'];
        if (!is_array($adj)) { return 'stock_adjust must be an object'; }
        if (!isset($adj['amount']) || !is_numeric($adj['amount']) || (int) $adj['amount'] != (float) $adj['amount']) {
            return 'stock_adjust.amount must be an integer';
        }
    }

    if (array_key_exists('stock_quantity', $changes)) {
        $sq = $changes['stock_quantity'];
        if (!is_numeric($sq) || (int) $sq != (float) $sq) { return 'stock_quantity must be an integer'; }
    }

    if (array_key_exists('stock_status', $changes)) {
        if (!in_array($changes['stock_status'], ['instock', 'outofstock', 'onbackorder'], true)) {
            return 'stock_status must be instock, outofstock, or onbackorder';
        }
    }

    if (array_key_exists('status', $changes)) {
        if (!in_array($changes['status'], ['publish', 'draft', 'private'], true)) {
            return 'status must be publish, draft, or private';
        }
    }

    if (array_key_exists('catalog_visibility', $changes)) {
        if (!in_array($changes['catalog_visibility'], ['visible', 'catalog', 'search', 'hidden'], true)) {
            return 'catalog_visibility must be visible, catalog, search, or hidden';
        }
    }

    foreach (['regular_price', 'sale_price'] as $pk) {
        if (array_key_exists($pk, $changes) && !is_numeric($changes[$pk])) {
            return "$pk must be numeric";
        }
    }

    // Sanity: when both plain regular_price and sale_price are being set, sale must be lower.
    if (array_key_exists('regular_price', $changes) && array_key_exists('sale_price', $changes)) {
        if ((float) $changes['sale_price'] >= (float) $changes['regular_price']) {
            return 'sale_price must be less than regular_price';
        }
    }

    foreach (['sale_from', 'sale_to'] as $dk) {
        if (array_key_exists($dk, $changes)) {
            $v = (string) $changes[$dk];
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) { return "$dk must be Y-m-d"; }
        }
    }
    if (array_key_exists('sale_from', $changes) && array_key_exists('sale_to', $changes)) {
        if ((string) $changes['sale_from'] > (string) $changes['sale_to']) {
            return 'sale_from must not be after sale_to';
        }
    }

    if (array_key_exists('add_category', $changes) && !is_string($changes['add_category']) && !is_array($changes['add_category'])) {
        return 'add_category must be a string or array of slugs';
    }
    if (array_key_exists('remove_category', $changes) && !is_string($changes['remove_category']) && !is_array($changes['remove_category'])) {
        return 'remove_category must be a string or array of slugs';
    }

    return true;
}

// ---------------------------------------------------------------------------
// PURE: diff
// ---------------------------------------------------------------------------

/**
 * Compare two flat associative "before"/"after" snapshots and return the list
 * of field names whose value actually changed (loose string compare so
 * '9' vs '9.00' — same numeric value serialized differently — still counts
 * only when the string differs; callers normalize prices before diffing).
 * Pure.
 */
function wpultra_woo_bulk_diff(array $before, array $after): array {
    $changed = [];
    $keys = array_unique(array_merge(array_keys($before), array_keys($after)));
    foreach ($keys as $k) {
        $b = $before[$k] ?? null;
        $a = $after[$k] ?? null;
        if ($b !== $a) { $changed[] = $k; }
    }
    return $changed;
}

// ---------------------------------------------------------------------------
// Runtime: select (touches WooCommerce)
// ---------------------------------------------------------------------------

/**
 * Resolve target product ids from filters via wc_get_products().
 * Filters: ids (passthrough array of ints — short-circuits other filters),
 * category (slug), type, stock_status, status, search, on_sale (bool),
 * price_min/price_max (regular price range), limit (default 100, cap 500).
 * Returns ['ids' => [...], 'count' => N].
 */
function wpultra_woo_bulk_select(array $filters): array {
    if (!function_exists('wc_get_products')) {
        return ['ids' => [], 'count' => 0];
    }

    // Direct id passthrough — still honors the limit cap.
    if (!empty($filters['ids']) && is_array($filters['ids'])) {
        $ids = array_values(array_unique(array_map('intval', $filters['ids'])));
        $limit = wpultra_woo_bulk_clamp_limit($filters['limit'] ?? count($ids));
        $ids = array_slice($ids, 0, $limit);
        return ['ids' => $ids, 'count' => count($ids)];
    }

    $limit = wpultra_woo_bulk_clamp_limit($filters['limit'] ?? 100);
    $q = [
        'limit'    => $limit,
        'paginate' => false,
        'return'   => 'ids',
    ];
    if (!empty($filters['category']))     { $q['category'] = [(string) $filters['category']]; }
    if (!empty($filters['type']))         { $q['type'] = (string) $filters['type']; }
    if (!empty($filters['stock_status'])) { $q['stock_status'] = (string) $filters['stock_status']; }
    if (!empty($filters['status']))       { $q['status'] = (string) $filters['status']; }
    if (!empty($filters['search']))       { $q['s'] = (string) $filters['search']; }
    if (!empty($filters['on_sale']) && function_exists('wc_get_product_ids_on_sale')) {
        $on_sale = wc_get_product_ids_on_sale();
        // An empty include list is silently DROPPED by WC_Product_Query (it
        // would select everything) — with nothing on sale the selection is empty.
        if (empty($on_sale)) { return ['ids' => [], 'count' => 0]; }
        $q['include'] = $on_sale;
    }

    $ids = wc_get_products($q);
    $ids = array_map('intval', is_array($ids) ? $ids : []);

    // Price range: WC_Product_Query silently DROPS an arbitrary meta_query (it
    // is not a supported query var), which would select everything — so filter
    // in PHP on each product's ACTIVE price (sale price when on sale).
    if (isset($filters['price_min']) || isset($filters['price_max'])) {
        $min = isset($filters['price_min']) ? (float) $filters['price_min'] : null;
        $max = isset($filters['price_max']) ? (float) $filters['price_max'] : null;
        $ids = array_values(array_filter($ids, static function ($id) use ($min, $max) {
            $p = wc_get_product($id);
            if (!$p) { return false; }
            $price = (float) $p->get_price();
            if ($min !== null && $price < $min) { return false; }
            if ($max !== null && $price > $max) { return false; }
            return true;
        }));
    }

    return ['ids' => $ids, 'count' => count($ids)];
}

/** Clamp a requested limit to [1, 500]. Pure-ish helper (no WP dependency). */
function wpultra_woo_bulk_clamp_limit($limit): int {
    $n = (int) $limit;
    if ($n < 1) { $n = 100; }
    if ($n > 500) { $n = 500; }
    return $n;
}

// ---------------------------------------------------------------------------
// Runtime: apply (touches WooCommerce)
// ---------------------------------------------------------------------------

/** Flatten the fields this engine can change into a comparable snapshot for diffing. */
function wpultra_woo_bulk_snapshot($p): array {
    return [
        'regular_price'      => (string) $p->get_regular_price(),
        'sale_price'         => (string) $p->get_sale_price(),
        'stock_quantity'     => $p->get_stock_quantity(),
        'manage_stock'       => (bool) $p->get_manage_stock(),
        'stock_status'       => (string) $p->get_stock_status(),
        'status'             => (string) $p->get_status(),
        'catalog_visibility' => (string) $p->get_catalog_visibility(),
        'tax_class'          => (string) $p->get_tax_class(),
        'category_ids'       => $p->get_category_ids(),
        'date_on_sale_from'  => $p->get_date_on_sale_from() ? $p->get_date_on_sale_from()->date('Y-m-d') : null,
        'date_on_sale_to'    => $p->get_date_on_sale_to() ? $p->get_date_on_sale_to()->date('Y-m-d') : null,
    ];
}

/** Resolve a category slug (or array of slugs) to term ids on the product_cat taxonomy. */
function wpultra_woo_bulk_resolve_category_slugs($slugs): array {
    $slugs = is_array($slugs) ? $slugs : [$slugs];
    $ids = [];
    foreach ($slugs as $slug) {
        $slug = (string) $slug;
        if ($slug === '') { continue; }
        $term = get_term_by('slug', $slug, 'product_cat');
        if ($term && !is_wp_error($term)) { $ids[] = (int) $term->term_id; }
    }
    return $ids;
}

/**
 * Apply a validated change-set to a list of product ids.
 *   $ids      — product ids (already resolved by select()).
 *   $changes  — see wpultra_woo_bulk_validate_changes() for the supported shape.
 *   $dry_run  — when true, compute before/after but do NOT save().
 * Returns ['results' => [{id, before, after, changed[]}...], 'summary' => {...}].
 */
function wpultra_woo_bulk_apply(array $ids, array $changes, bool $dry_run) {
    $valid = wpultra_woo_bulk_validate_changes($changes);
    if ($valid !== true) { return wpultra_err('invalid_changes', (string) $valid); }

    $results = [];
    $updated = 0;
    $failed = 0;

    foreach ($ids as $id) {
        $id = (int) $id;
        $p = wc_get_product($id);
        if (!$p) {
            $results[] = ['id' => $id, 'before' => null, 'after' => null, 'changed' => [], 'error' => 'product_not_found'];
            $failed++;
            continue;
        }

        $before = wpultra_woo_bulk_snapshot($p);

        // Direct price sets.
        if (array_key_exists('regular_price', $changes)) {
            $p->set_regular_price((string) round((float) $changes['regular_price'], 2));
        }
        if (array_key_exists('sale_price', $changes)) {
            $p->set_sale_price((string) round((float) $changes['sale_price'], 2));
        }

        // Percent/fixed price adjustment relative to the current price.
        if (array_key_exists('price_adjust', $changes)) {
            $adj = $changes['price_adjust'];
            $target = (string) ($adj['target'] ?? 'regular');
            $current = $target === 'sale' ? (float) $p->get_sale_price() : (float) $p->get_regular_price();
            $new = wpultra_woo_bulk_new_price($current, $adj);
            if ($target === 'sale') { $p->set_sale_price((string) $new); }
            else { $p->set_regular_price((string) $new); }
        }

        // Stock.
        if (array_key_exists('manage_stock', $changes)) {
            $p->set_manage_stock(wpultra_woo_coerce_bool($changes['manage_stock']));
        }
        if (array_key_exists('stock_quantity', $changes)) {
            if (!array_key_exists('manage_stock', $changes)) { $p->set_manage_stock(true); }
            $p->set_stock_quantity(max(0, (int) $changes['stock_quantity']));
        }
        if (array_key_exists('stock_adjust', $changes)) {
            $amount = (int) ($changes['stock_adjust']['amount'] ?? 0);
            $current = (int) $p->get_stock_quantity();
            if (!array_key_exists('manage_stock', $changes)) { $p->set_manage_stock(true); }
            $p->set_stock_quantity(max(0, $current + $amount));
        }
        if (array_key_exists('stock_status', $changes)) {
            $p->set_stock_status((string) $changes['stock_status']);
        }

        // Status / visibility / tax.
        if (array_key_exists('status', $changes)) {
            $p->set_status((string) $changes['status']);
        }
        if (array_key_exists('catalog_visibility', $changes)) {
            $p->set_catalog_visibility((string) $changes['catalog_visibility']);
        }
        if (array_key_exists('tax_class', $changes)) {
            $p->set_tax_class((string) $changes['tax_class']);
        }

        // Category add/remove by slug.
        if (array_key_exists('add_category', $changes) || array_key_exists('remove_category', $changes)) {
            $current_ids = $p->get_category_ids();
            if (array_key_exists('add_category', $changes)) {
                $add_ids = wpultra_woo_bulk_resolve_category_slugs($changes['add_category']);
                $current_ids = array_values(array_unique(array_merge($current_ids, $add_ids)));
            }
            if (array_key_exists('remove_category', $changes)) {
                $remove_ids = wpultra_woo_bulk_resolve_category_slugs($changes['remove_category']);
                $current_ids = array_values(array_diff($current_ids, $remove_ids));
            }
            $p->set_category_ids($current_ids);
        }

        // Sale schedule.
        if (array_key_exists('sale_from', $changes)) {
            $p->set_date_on_sale_from((string) $changes['sale_from']);
        }
        if (array_key_exists('sale_to', $changes)) {
            $p->set_date_on_sale_to((string) $changes['sale_to']);
        }

        if (!$dry_run) {
            try {
                $p->save();
            } catch (\Throwable $e) {
                $results[] = ['id' => $id, 'before' => $before, 'after' => $before, 'changed' => [], 'error' => 'save_failed'];
                $failed++;
                continue;
            }
        }

        $after = wpultra_woo_bulk_snapshot($p);
        $changed = wpultra_woo_bulk_diff($before, $after);
        $results[] = ['id' => $id, 'before' => $before, 'after' => $after, 'changed' => $changed];
        if (!empty($changed)) { $updated++; }
    }

    return wpultra_ok([
        'dry_run' => $dry_run,
        'results' => $results,
        'summary' => [
            'total'   => count($ids),
            'updated' => $updated,
            'failed'  => $failed,
        ],
    ]);
}
