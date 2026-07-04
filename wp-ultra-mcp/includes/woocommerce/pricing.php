<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Dynamic pricing & discounts engine (roadmap B1).
 *
 * Storage: ONE autoloaded option `wpultra_pricing_rules` — an array keyed by
 * rule id ('pr-' + 6 lowercase alnum). Rule shape:
 *   {id, name, enabled: bool, type, scope: {products: int[]|'all', categories?: slug[]},
 *    config, created_at}
 * Types + config:
 *   tiered_qty    {tiers: [{min_qty, discount_pct}]}   — highest matching min_qty wins
 *   bogo          {buy_qty, get_qty, discount_pct}     — complete groups only, 100 = free
 *   cart_discount {min_total, discount_pct | amount}   — cart-subtotal threshold discount
 *   role_price    {role, discount_pct}                 — logged-in users with that role
 *
 * Runtime contract: this file defines wpultra_pricing_boot() and the plugin
 * controller calls it on plugins_loaded. Boot is CHEAP — one autoloaded option
 * read; cart hooks are registered only when at least one enabled rule exists.
 *   - Item-level rules (tiered_qty, role_price): woocommerce_before_calculate_totals
 *     @20 sets a discounted price per cart item. The ORIGINAL price is re-read
 *     fresh from the DB product on every run so repeated recalculations never
 *     compound the discount (the classic dynamic-pricing bug). Item rules do
 *     NOT stack — the single best (largest) percentage wins.
 *   - Fee-level rules (bogo, cart_discount): woocommerce_cart_calculate_fees
 *     adds ONE negative fee per matching rule, labelled with the rule name.
 * All runtime handlers are try/catch-wrapped — a broken rule must never break
 * the cart.
 *
 * Layout: pure functions first (unit-tested via tests/woo-pricing.test.php with
 * the zero-dependency harness — no WordPress/WooCommerce), WP/WC-touching
 * wrappers after (every WC call guarded with function_exists/class_exists).
 */

/* =====================================================================
 * Pure core — no WordPress/WooCommerce calls, fully unit-testable.
 * ===================================================================== */

/** Valid rule types. Pure. */
function wpultra_pricing_types(): array {
    return ['tiered_qty', 'bogo', 'cart_discount', 'role_price'];
}

/** True for ints and integer-looking strings/floats. Pure. */
function wpultra_pricing_intish($v): bool {
    if (is_int($v)) { return true; }
    if (is_float($v)) { return floor($v) === $v; }
    return is_string($v) && preg_match('/^-?\d+$/', $v) === 1;
}

/**
 * Generate a rule id: 'pr-' + 6 lowercase alnum chars.
 * $rand(min, max) must behave like random_int — injectable for tests. Pure.
 */
function wpultra_pricing_new_id(?callable $rand = null): string {
    $rand = $rand ?? 'random_int';
    $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $id = 'pr-';
    for ($i = 0; $i < 6; $i++) { $id .= $alphabet[(int) $rand(0, 35)]; }
    return $id;
}

/**
 * Validate a rule document (name + type + scope + config).
 * Returns true, or an error string describing the first problem. Pure.
 */
function wpultra_pricing_validate(array $rule) {
    $name = $rule['name'] ?? '';
    if (!is_string($name) || trim($name) === '') { return 'name must be a non-empty string.'; }

    $type = $rule['type'] ?? '';
    if (!is_string($type) || !in_array($type, wpultra_pricing_types(), true)) {
        return 'type must be one of: ' . implode(', ', wpultra_pricing_types()) . '.';
    }

    $scope = $rule['scope'] ?? null;
    if (!is_array($scope)) {
        return "scope must be an object like {products:'all'} or {products:[12,34]}.";
    }
    $products = $scope['products'] ?? null;
    if ($products !== 'all') {
        if (!is_array($products) || $products === []) {
            return "scope.products must be 'all' or a non-empty array of product IDs.";
        }
        foreach ($products as $p) {
            if (!wpultra_pricing_intish($p) || (int) $p <= 0) {
                return 'scope.products entries must be positive product IDs (integers).';
            }
        }
    }
    if (array_key_exists('categories', $scope)) {
        if (!is_array($scope['categories'])) {
            return 'scope.categories must be an array of category slugs.';
        }
        foreach ($scope['categories'] as $slug) {
            if (!is_string($slug) || trim($slug) === '') {
                return 'scope.categories entries must be non-empty slug strings.';
            }
        }
    }

    $cfg = $rule['config'] ?? null;
    if (!is_array($cfg)) { return 'config must be an object.'; }

    $pct_ok = static function ($v): bool {
        return is_numeric($v) && (float) $v >= 0 && (float) $v <= 100;
    };

    switch ($type) {
        case 'tiered_qty':
            $tiers = $cfg['tiers'] ?? null;
            if (!is_array($tiers) || $tiers === []) {
                return 'tiered_qty config.tiers must be a non-empty array of {min_qty, discount_pct}.';
            }
            $prev = 0;
            foreach ($tiers as $tier) {
                if (!is_array($tier)) { return 'each tier must be an object {min_qty, discount_pct}.'; }
                if (!wpultra_pricing_intish($tier['min_qty'] ?? null) || (int) $tier['min_qty'] < 1) {
                    return 'tier min_qty must be an integer >= 1.';
                }
                if ((int) $tier['min_qty'] <= $prev) {
                    return 'tier min_qty values must be ascending and unique.';
                }
                $prev = (int) $tier['min_qty'];
                if (!$pct_ok($tier['discount_pct'] ?? null)) {
                    return 'tier discount_pct must be a number between 0 and 100.';
                }
            }
            return true;

        case 'bogo':
            foreach (['buy_qty', 'get_qty'] as $k) {
                if (!wpultra_pricing_intish($cfg[$k] ?? null) || (int) $cfg[$k] < 1) {
                    return "bogo config.$k must be an integer >= 1.";
                }
            }
            if (!$pct_ok($cfg['discount_pct'] ?? null)) {
                return 'bogo config.discount_pct must be a number between 0 and 100 (100 = free).';
            }
            return true;

        case 'cart_discount':
            if (!isset($cfg['min_total']) || !is_numeric($cfg['min_total']) || (float) $cfg['min_total'] < 0) {
                return 'cart_discount config.min_total must be a number >= 0.';
            }
            $has_pct = array_key_exists('discount_pct', $cfg);
            $has_amt = array_key_exists('amount', $cfg);
            if ($has_pct === $has_amt) { // both or neither
                return 'cart_discount config must have exactly one of discount_pct or amount.';
            }
            if ($has_pct && !$pct_ok($cfg['discount_pct'])) {
                return 'cart_discount config.discount_pct must be a number between 0 and 100.';
            }
            if ($has_amt && (!is_numeric($cfg['amount']) || (float) $cfg['amount'] <= 0)) {
                return 'cart_discount config.amount must be a number > 0.';
            }
            return true;

        case 'role_price':
            if (!is_string($cfg['role'] ?? null) || trim((string) $cfg['role']) === '') {
                return 'role_price config.role must be a non-empty role slug (e.g. "wholesale").';
            }
            if (!$pct_ok($cfg['discount_pct'] ?? null)) {
                return 'role_price config.discount_pct must be a number between 0 and 100.';
            }
            return true;
    }
    return 'unknown rule type.'; // unreachable — enum checked above
}

/**
 * Does a rule scope match this product? products: 'all' or id whitelist;
 * when scope.categories is non-empty, ALSO require a slug intersection. Pure.
 */
function wpultra_pricing_scope_match(array $scope, int $product_id, array $category_slugs): bool {
    $products = $scope['products'] ?? 'all';
    if ($products !== 'all') {
        if (!is_array($products)) { return false; }
        if (!in_array($product_id, array_map('intval', $products), true)) { return false; }
    }
    $want = $scope['categories'] ?? [];
    if (is_array($want) && $want !== []) {
        $have = array_map('strval', $category_slugs);
        foreach ($want as $slug) {
            if (in_array((string) $slug, $have, true)) { return true; }
        }
        return false;
    }
    return true;
}

/**
 * Percentage for the highest tier whose min_qty <= qty (0.0 when none).
 * Order-independent — scans all tiers. Pure.
 */
function wpultra_pricing_tier_pct(array $tiers, int $qty): float {
    $best_min = 0;
    $pct = 0.0;
    foreach ($tiers as $tier) {
        if (!is_array($tier)) { continue; }
        $min = (int) ($tier['min_qty'] ?? 0);
        if ($min < 1 || $min > $qty) { continue; }
        if ($min > $best_min) {
            $best_min = $min;
            $pct = (float) ($tier['discount_pct'] ?? 0);
        }
    }
    return $pct;
}

/**
 * BOGO discount amount for one line: complete (buy+get) groups only —
 * floor(qty / (buy+get)) * get * unit_price * pct/100, rounded 2dp. Pure.
 */
function wpultra_pricing_bogo_discount(int $qty, int $buy, int $get, float $pct, float $unit_price): float {
    if ($buy < 1 || $get < 1 || $unit_price <= 0 || $pct <= 0) { return 0.0; }
    $group = $buy + $get;
    if ($qty < $group) { return 0.0; }
    $groups = intdiv($qty, $group);
    return round($groups * $get * $unit_price * $pct / 100, 2);
}

/**
 * Cart-subtotal threshold discount. 0.0 below min_total; otherwise
 * pct-of-subtotal, or a flat amount capped at the subtotal. Rounded 2dp. Pure.
 */
function wpultra_pricing_cart_discount(float $subtotal, array $config): float {
    $min = (float) ($config['min_total'] ?? 0);
    if ($subtotal <= 0 || $subtotal < $min) { return 0.0; }
    if (array_key_exists('discount_pct', $config) && is_numeric($config['discount_pct'])) {
        $pct = min(100.0, max(0.0, (float) $config['discount_pct']));
        return round($subtotal * $pct / 100, 2);
    }
    if (array_key_exists('amount', $config) && is_numeric($config['amount'])) {
        return round(min(max(0.0, (float) $config['amount']), $subtotal), 2);
    }
    return 0.0;
}

/**
 * Best single item-level percentage (tiered_qty + role_price rules only —
 * NO stacking, the largest pct wins). Disabled rules and non-matching scopes
 * are skipped. Result clamped to 0..100. Pure.
 */
function wpultra_pricing_best_item_pct(array $rules, int $product_id, array $cats, string $user_role, int $qty): float {
    $best = 0.0;
    foreach ($rules as $rule) {
        if (!is_array($rule) || empty($rule['enabled'])) { continue; }
        $type = $rule['type'] ?? '';
        if ($type !== 'tiered_qty' && $type !== 'role_price') { continue; }
        $scope = is_array($rule['scope'] ?? null) ? $rule['scope'] : ['products' => 'all'];
        if (!wpultra_pricing_scope_match($scope, $product_id, $cats)) { continue; }
        $cfg = is_array($rule['config'] ?? null) ? $rule['config'] : [];
        if ($type === 'tiered_qty') {
            $tiers = is_array($cfg['tiers'] ?? null) ? $cfg['tiers'] : [];
            $pct = wpultra_pricing_tier_pct($tiers, $qty);
        } else { // role_price
            $pct = ($user_role !== '' && (string) ($cfg['role'] ?? '') === $user_role)
                ? (float) ($cfg['discount_pct'] ?? 0)
                : 0.0;
        }
        if ($pct > $best) { $best = $pct; }
    }
    return min(100.0, max(0.0, $best));
}

/**
 * Dry-run heart: simulate the whole rule set against a hypothetical cart.
 * $cart = [{product_id, qty, price (unit), categories: slug[]}].
 * Returns:
 *   lines:  [{product_id, qty, original_line_total, discount_pct, discounted_line_total}]
 *   fees:   [{label, amount}] — NEGATIVE amounts (bogo + cart_discount), mirroring WC fees
 *   totals: {before, discount, after}
 * BOGO fees are computed off the item-discounted unit price (matches the
 * runtime, where before_calculate_totals runs before the fees hook), and
 * cart_discount thresholds compare against the item-discounted subtotal
 * (matches WC_Cart::get_subtotal() at fee time). Pure.
 */
function wpultra_pricing_preview(array $rules, array $cart, string $user_role): array {
    $lines = [];
    $norm = [];
    $before = 0.0;
    $items_after = 0.0;

    foreach ($cart as $line) {
        if (!is_array($line)) { continue; }
        $pid = (int) ($line['product_id'] ?? 0);
        $qty = (int) ($line['qty'] ?? 0);
        $price = is_numeric($line['price'] ?? null) ? (float) $line['price'] : 0.0;
        $cats = is_array($line['categories'] ?? null) ? array_map('strval', $line['categories']) : [];
        if ($qty < 1) { continue; }

        $pct = wpultra_pricing_best_item_pct($rules, $pid, $cats, $user_role, $qty);
        $orig = round($price * $qty, 2);
        $disc_unit = $price * (1 - $pct / 100);
        $after = round($disc_unit * $qty, 2);

        $lines[] = [
            'product_id'            => $pid,
            'qty'                   => $qty,
            'original_line_total'   => $orig,
            'discount_pct'          => $pct,
            'discounted_line_total' => $after,
        ];
        $before      += $orig;
        $items_after += $after;
        $norm[] = ['pid' => $pid, 'qty' => $qty, 'unit' => $disc_unit, 'cats' => $cats];
    }

    $before      = round($before, 2);
    $items_after = round($items_after, 2);

    $fees = [];
    $fee_discount = 0.0;
    foreach ($rules as $rule) {
        if (!is_array($rule) || empty($rule['enabled'])) { continue; }
        $type = $rule['type'] ?? '';
        if ($type !== 'bogo' && $type !== 'cart_discount') { continue; }
        $label = trim((string) ($rule['name'] ?? '')) !== '' ? (string) $rule['name'] : (string) ($rule['id'] ?? 'Discount');
        $cfg = is_array($rule['config'] ?? null) ? $rule['config'] : [];
        $amount = 0.0;

        if ($type === 'bogo') {
            $scope = is_array($rule['scope'] ?? null) ? $rule['scope'] : ['products' => 'all'];
            $buy = (int) ($cfg['buy_qty'] ?? 0);
            $get = (int) ($cfg['get_qty'] ?? 0);
            $pct = (float) ($cfg['discount_pct'] ?? 0);
            foreach ($norm as $n) {
                if (!wpultra_pricing_scope_match($scope, $n['pid'], $n['cats'])) { continue; }
                $amount += wpultra_pricing_bogo_discount($n['qty'], $buy, $get, $pct, $n['unit']);
            }
        } else { // cart_discount
            $amount = wpultra_pricing_cart_discount($items_after, $cfg);
        }

        $amount = round($amount, 2);
        if ($amount > 0) {
            $fees[] = ['label' => $label, 'amount' => -$amount];
            $fee_discount += $amount;
        }
    }

    $discount = round(($before - $items_after) + $fee_discount, 2);
    return [
        'lines'  => $lines,
        'fees'   => $fees,
        'totals' => ['before' => $before, 'discount' => $discount, 'after' => round($before - $discount, 2)],
    ];
}

/** One-line list summary of a rule (for the `list` action). Pure. */
function wpultra_pricing_summarize(array $rule): array {
    $scope = is_array($rule['scope'] ?? null) ? $rule['scope'] : [];
    $products = $scope['products'] ?? 'all';
    $summary = $products === 'all'
        ? 'all products'
        : (is_array($products) ? count($products) . ' product(s)' : 'invalid');
    $cats = $scope['categories'] ?? [];
    if (is_array($cats) && $cats !== []) {
        $summary .= '; categories: ' . implode(', ', array_map('strval', $cats));
    }
    return [
        'id'         => (string) ($rule['id'] ?? ''),
        'name'       => (string) ($rule['name'] ?? ''),
        'type'       => (string) ($rule['type'] ?? ''),
        'enabled'    => !empty($rule['enabled']),
        'scope'      => $summary,
        'created_at' => (string) ($rule['created_at'] ?? ''),
    ];
}

/* =====================================================================
 * WordPress / WooCommerce wrappers — every WP/WC call guarded.
 * ===================================================================== */

/** Read the rule set (autoloaded option). Always an array. */
function wpultra_pricing_get_rules(): array {
    if (!function_exists('get_option')) { return []; }
    $rules = get_option('wpultra_pricing_rules', []);
    return is_array($rules) ? $rules : [];
}

/** Persist the rule set (autoload=true so boot costs a single warm read). */
function wpultra_pricing_save_rules(array $rules): bool {
    if (!function_exists('update_option')) { return false; }
    update_option('wpultra_pricing_rules', $rules, true);
    return true;
}

/** product_cat slugs for a product id (empty outside WP / for bad ids). */
function wpultra_pricing_product_cats(int $product_id): array {
    if ($product_id <= 0 || !function_exists('get_the_terms')) { return []; }
    $terms = get_the_terms($product_id, 'product_cat');
    if (!is_array($terms)) { return []; }
    $slugs = [];
    foreach ($terms as $t) {
        if (is_object($t) && isset($t->slug)) { $slugs[] = (string) $t->slug; }
    }
    return $slugs;
}

/** First role slug of the current user ('' when logged out / outside WP). */
function wpultra_pricing_current_role(): string {
    if (!function_exists('wp_get_current_user')) { return ''; }
    $user = wp_get_current_user();
    if (!is_object($user) || empty($user->roles) || !is_array($user->roles)) { return ''; }
    $first = reset($user->roles);
    return is_string($first) ? $first : '';
}

/**
 * woocommerce_before_calculate_totals @20 — apply the best item-level pct per
 * cart item. Skips admin (unless AJAX), runs once per request (static guard),
 * and ALWAYS re-reads the original price from a fresh product object so
 * repeated recalculations never compound the discount.
 */
function wpultra_pricing_apply_item_prices($cart): void {
    static $done = false;
    if ($done) { return; }
    if (function_exists('is_admin') && is_admin() && !(defined('DOING_AJAX') && DOING_AJAX)) { return; }
    if (!is_object($cart) || !method_exists($cart, 'get_cart')) { return; }
    $done = true;

    try {
        $rules = wpultra_pricing_get_rules();
        if ($rules === []) { return; }
        $role = wpultra_pricing_current_role();

        foreach ($cart->get_cart() as $item) {
            if (!is_array($item) || empty($item['data']) || !is_object($item['data'])) { continue; }
            $product = $item['data'];
            $pid = (int) ($item['product_id'] ?? 0);
            $vid = (int) ($item['variation_id'] ?? 0);
            $qty = max(1, (int) ($item['quantity'] ?? 1));
            $cats = wpultra_pricing_product_cats($pid);

            $pct = wpultra_pricing_best_item_pct($rules, $pid, $cats, $role, $qty);
            if ($vid > 0) {
                $pct = max($pct, wpultra_pricing_best_item_pct($rules, $vid, $cats, $role, $qty));
            }
            if ($pct <= 0) { continue; }

            // Fresh original price (sale-aware, unfiltered) — never the possibly
            // already-discounted in-cart price, so recalcs don't compound.
            $orig = null;
            if (function_exists('wc_get_product')) {
                $fresh = wc_get_product((int) $product->get_id());
                if ($fresh && is_object($fresh)) { $orig = (float) $fresh->get_price('edit'); }
            }
            if ($orig === null) { $orig = (float) $product->get_price('edit'); }
            if ($orig <= 0) { continue; }

            $product->set_price(round($orig * (1 - $pct / 100), 4));
        }
    } catch (\Throwable $e) {
        // Never break the cart.
    }
}

/**
 * woocommerce_cart_calculate_fees — one negative fee per matching bogo /
 * cart_discount rule, labelled with the rule name.
 */
function wpultra_pricing_apply_cart_fees($cart): void {
    if (function_exists('is_admin') && is_admin() && !(defined('DOING_AJAX') && DOING_AJAX)) { return; }
    if (!is_object($cart) || !method_exists($cart, 'add_fee') || !method_exists($cart, 'get_cart')) { return; }

    try {
        $rules = wpultra_pricing_get_rules();
        foreach ($rules as $rule) {
            if (!is_array($rule) || empty($rule['enabled'])) { continue; }
            $type = $rule['type'] ?? '';
            if ($type !== 'bogo' && $type !== 'cart_discount') { continue; }
            $label = trim((string) ($rule['name'] ?? '')) !== '' ? (string) $rule['name'] : (string) ($rule['id'] ?? 'Discount');
            $cfg = is_array($rule['config'] ?? null) ? $rule['config'] : [];
            $amount = 0.0;

            if ($type === 'bogo') {
                $scope = is_array($rule['scope'] ?? null) ? $rule['scope'] : ['products' => 'all'];
                $buy = (int) ($cfg['buy_qty'] ?? 0);
                $get = (int) ($cfg['get_qty'] ?? 0);
                $pct = (float) ($cfg['discount_pct'] ?? 0);
                foreach ($cart->get_cart() as $item) {
                    if (!is_array($item) || empty($item['data']) || !is_object($item['data'])) { continue; }
                    $pid = (int) ($item['product_id'] ?? 0);
                    $vid = (int) ($item['variation_id'] ?? 0);
                    $qty = max(0, (int) ($item['quantity'] ?? 0));
                    $cats = wpultra_pricing_product_cats($pid);
                    if (!wpultra_pricing_scope_match($scope, $pid, $cats)
                        && !($vid > 0 && wpultra_pricing_scope_match($scope, $vid, $cats))) {
                        continue;
                    }
                    $unit = (float) $item['data']->get_price();
                    $amount += wpultra_pricing_bogo_discount($qty, $buy, $get, $pct, $unit);
                }
            } else { // cart_discount
                $subtotal = method_exists($cart, 'get_subtotal') ? (float) $cart->get_subtotal() : 0.0;
                $amount = wpultra_pricing_cart_discount($subtotal, $cfg);
            }

            $amount = round($amount, 2);
            if ($amount > 0) { $cart->add_fee($label, -$amount); }
        }
    } catch (\Throwable $e) {
        // Never break the cart.
    }
}

/**
 * Boot — called by the controller on plugins_loaded. CHEAP: one autoloaded
 * option read; hooks registered only when at least one enabled rule of the
 * relevant kind exists. Idempotent.
 */
function wpultra_pricing_boot(): void {
    static $booted = false;
    if ($booted) { return; }
    $booted = true;

    if (!function_exists('add_action') || !function_exists('get_option')) { return; }
    $rules = get_option('wpultra_pricing_rules', []);
    if (!is_array($rules) || $rules === []) { return; }

    $item_rules = false;
    $fee_rules = false;
    foreach ($rules as $rule) {
        if (!is_array($rule) || empty($rule['enabled'])) { continue; }
        $type = $rule['type'] ?? '';
        if ($type === 'tiered_qty' || $type === 'role_price') { $item_rules = true; }
        if ($type === 'bogo' || $type === 'cart_discount') { $fee_rules = true; }
        if ($item_rules && $fee_rules) { break; }
    }

    if ($item_rules) {
        add_action('woocommerce_before_calculate_totals', 'wpultra_pricing_apply_item_prices', 20);
    }
    if ($fee_rules) {
        add_action('woocommerce_cart_calculate_fees', 'wpultra_pricing_apply_cart_fees', 20);
    }
}

/**
 * Resolve a preview cart ([{product_id, qty, price?, categories?}]) against
 * real store data (missing prices/categories filled via wc_get_product), then
 * run the pure preview with all stored rules. Explicit price + categories per
 * line make fully hypothetical carts possible. Returns the preview array or
 * WP_Error.
 */
function wpultra_pricing_preview_wp(array $cart_input, string $role) {
    $cart = [];
    foreach ($cart_input as $line) {
        if (!is_array($line)) { continue; }
        $pid = (int) ($line['product_id'] ?? 0);
        $qty = max(1, (int) ($line['qty'] ?? 1));
        $price = is_numeric($line['price'] ?? null) ? (float) $line['price'] : null;
        $cats = is_array($line['categories'] ?? null) ? array_map('strval', $line['categories']) : null;

        if (($price === null || $cats === null) && $pid > 0 && function_exists('wc_get_product')) {
            $product = wc_get_product($pid);
            if ($product && is_object($product)) {
                if ($price === null) { $price = (float) $product->get_price(); }
                if ($cats === null) { $cats = wpultra_pricing_product_cats($pid); }
            }
        }
        if ($price === null) {
            return wpultra_err(
                'preview_price_unresolved',
                "Cart line for product $pid has no price and the product could not be loaded — pass an explicit price for hypothetical lines."
            );
        }
        $cart[] = ['product_id' => $pid, 'qty' => $qty, 'price' => $price, 'categories' => $cats ?? []];
    }
    if ($cart === []) {
        return wpultra_err('preview_empty_cart', 'preview requires cart: a non-empty array of {product_id, qty, price?} lines.');
    }
    return wpultra_pricing_preview(wpultra_pricing_get_rules(), $cart, $role);
}
