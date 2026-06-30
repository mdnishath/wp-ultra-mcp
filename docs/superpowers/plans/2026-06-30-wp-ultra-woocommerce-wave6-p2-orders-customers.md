# WP-Ultra-MCP — Wave 6 WooCommerce · Plan 2: Orders + Customers

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **Plan 2 of the Wave 6 program** (spec: `docs/superpowers/specs/2026-06-30-wp-ultra-woocommerce-wave6-design.md`; Plan 1 shipped the catalog layer, branch `feat/woocommerce-wave6`, count 63). Wave 6 ships as **v0.10.0** at Plan 4 — do NOT bump version or release here. Builds directly on Plan 1's `includes/woocommerce/{setup,schema,products}.php` + `woocommerce` category.
>
> **VERIFIED LIVE ENV (from Plan 1):** WooCommerce **10.9.1**, **HPOS = ON** (custom order tables), currency **BDT**, base country **BD**. This is why every order path MUST go through the WooCommerce CRUD API (`wc_get_orders`, `wc_get_order`, `wc_create_order`, `WC_Order`, `WC_Order_Query`, `wc_create_refund`) and NEVER `get_posts('shop_order')` / `get_post_meta` — those read the legacy `wp_posts`/postmeta tables which HPOS bypasses, so they return stale/empty data. Customers go through `WC_Customer` + `wc_get_orders`.

**Goal:** 8 abilities — Orders: `woo-list-orders`, `woo-get-order`, `woo-create-order`, `woo-update-order`, `woo-refund-order`; Customers: `woo-list-customers`, `woo-get-customer`, `woo-upsert-customer` — built HPOS-safely on the Woo CRUD API.

**Architecture:** Two new engine files mirror `products.php`: `includes/woocommerce/orders.php` (order list/get/create/update/refund via `WC_Order`) and `includes/woocommerce/customers.php` (customer list/get/upsert via `WC_Customer`). A pure customer-field validator is added to `schema.php` (unit-tested). Eight thin abilities call the engine + audit, all gated on `wpultra_woo_active()`.

**Tech Stack:** PHP 8.0+ (`declare(strict_types=1)`), WP 7.0, WooCommerce 10.9.1 CRUD API (HPOS-aware), vendored mcp-adapter, WordPress Abilities API. No new dependencies.

## Global Constraints

- Every PHP file starts with `<?php` + `declare(strict_types=1);` + `if (!defined('ABSPATH')) { exit(); }`.
- Engine functions return arrays/values or `WP_Error` (via `wpultra_err($code,$msg,$data='')`). Abilities return `wpultra_ok([...])` or the `WP_Error`. Helpers in `wp-ultra-mcp/includes/helpers.php`.
- **Ability registration MUST match the codebase shape** — copy `wp-ultra-mcp/includes/abilities/woo-upsert-product.php` (write) and `woo-get-product.php` (read): `wp_register_ability('wpultra/<slug>',[...])`, `label`/`description` via `__()`, `category=>'woocommerce'`, `input_schema` with `properties` a PLAIN ARRAY (never `(object)`), `output_schema`, a NAMED STRING `execute_callback`, `permission_callback=>'wpultra_permission_callback'`, and `meta=>['show_in_rest'=>true,'mcp'=>['public'=>true,'type'=>'tool'],'annotations'=>[...]]`.
- Read abilities (`woo-list-orders`, `woo-get-order`, `woo-list-customers`, `woo-get-customer`): `annotations=>['readonly'=>true,'destructive'=>false,'idempotent'=>true]`, NO audit. Write abilities (`woo-create-order`, `woo-update-order`, `woo-upsert-customer`): `['readonly'=>false,'destructive'=>false,'idempotent'=>false]` + `wpultra_audit_log('<slug>',<summary>,$ok)` after the write. `woo-refund-order`: `['readonly'=>false,'destructive'=>true,'idempotent'=>false]` + audit.
- **Every callback gates on Woo first:** `if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive','WooCommerce is not active.'); }`.
- **HPOS DISCIPLINE (non-negotiable):** order access is ONLY via `wc_get_order($id)` / `wc_get_orders($args)` / `new WC_Order_Query($args)` / `wc_create_order($args)` / `wc_create_refund($args)` and `WC_Order`/`WC_Order_Item_*` methods. NEVER `get_posts(['post_type'=>'shop_order'])`, `get_post_meta` on an order, or raw `$wpdb`. A reviewer treats any such access as a Critical finding.
- **Bootstrap wiring** (`wp-ultra-mcp/includes/bootstrap-mcp.php`): add the 8 slugs to the `// woocommerce` group in `wpultra_ability_files()` AND to `wpultra_ability_category_map()['woocommerce']`; add `orders` and `customers` to the woocommerce engine require loop in `wpultra_load_abilities()` (currently `['setup','schema','products']` → `['setup','schema','products','orders','customers']`). `tests/bootstrap.test.php` count `63` → `71`; keep files↔map in sync (the map-covers-every-file-once assertion must hold).
- Bundled PHP: `$PHP = C:/Users/nisha/AppData/Roaming/Local/lightning-services/php-8.2.30+1/bin/win64/php.exe`. Test site root: `C:/Users/nisha/Local Sites/wp-connector/app/public`. Live token: `wpultra-test-9a88`.
- **Re-run `wp-ultra-mcp/bin/deploy.ps1` after every commit.** Commands run from `E:\wp-connector`. Live-test scripts go in the site webroot (`.../wp-content/wpultra-*.php`), `require dirname(__DIR__).'/wp-load.php'`, token-gate, `wp_set_current_user(<admin id>)`, **require BOTH the engine files AND the ability files** (the `*_cb` callbacks are NOT auto-loaded in a plain wp-load request — Plan 1 hit this; require `includes/woocommerce/*.php` then `includes/abilities/woo-*.php`), call the `*_cb` functions, echo JSON, then DELETE the script and clean up any test orders/customers created.
- **Harness** (`tests/harness.php`): `it('name',fn)`, `assert_eq($expected,$actual)` (strict), `assert_true($cond,$msg='')`, `assert_wp_error($v,$code='')`; file ends `run_tests();`. `tests/run-all.ps1` auto-globs. Engine/schema files reference Woo/WP functions only inside bodies; pure tests stub nothing Woo (the customer validator is pure).
- Commit messages: `feat(woocommerce): …` / `test(woocommerce): …`; end the body with `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.

## File Structure

```
wp-ultra-mcp/includes/
  woocommerce/
    schema.php     MODIFY — append wpultra_woo_validate_customer (Task 5)
    orders.php     NEW — WC_Order list/get/create/update/refund (Tasks 1–4)
    customers.php  NEW — WC_Customer list/get/upsert (Tasks 5–6)
  abilities/
    woo-list-orders.php      NEW (Task 1)
    woo-get-order.php        NEW (Task 1)
    woo-create-order.php     NEW (Task 2)
    woo-update-order.php     NEW (Task 3)
    woo-refund-order.php     NEW (Task 4)
    woo-list-customers.php   NEW (Task 5)
    woo-get-customer.php     NEW (Task 5)
    woo-upsert-customer.php  NEW (Task 6)
  bootstrap-mcp.php   MODIFY — wire engine loop + 8 abilities (Tasks 1–6)
tests/
  woo-schema.test.php   MODIFY — add customer-validator tests (Task 5)
  bootstrap.test.php    MODIFY — count 63 → 71 (Tasks 1–6)
```

---

### Task 1: `orders.php` read engine + `woo-list-orders` + `woo-get-order`

**Files:**
- Create: `wp-ultra-mcp/includes/woocommerce/orders.php` (read functions now; write/refund appended Tasks 2–4)
- Create: `wp-ultra-mcp/includes/abilities/woo-list-orders.php`, `woo-get-order.php`
- Modify: `bootstrap-mcp.php` (engine loop + 2 slugs), `tests/bootstrap.test.php` (63 → 65)

**Interfaces:**
- Produces:
  - `wpultra_woo_order_row(WC_Order $o): array` — `['id','number','status','total','currency','customer_id','date_created','items_count']`.
  - `wpultra_woo_order_full(WC_Order $o): array` — row + billing, shipping, payment_method, line items (`[{item_id,product_id,name,qty,subtotal,total}]`), shipping/fee/tax totals, notes-count.
  - `wpultra_woo_list_orders(array $args): array` — HPOS-safe via `wc_get_orders`; `['count'=>int,'orders'=>[rows]]`.
  - `wpultra_woo_get_order(int $id): array|WP_Error` — `wpultra_err('order_not_found',...)` when missing.

- [ ] **Step 1: Write `orders.php` (read half)**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

function wpultra_woo_order_row($o): array {
    return [
        'id'           => $o->get_id(),
        'number'       => $o->get_order_number(),
        'status'       => $o->get_status(),
        'total'        => $o->get_total(),
        'currency'     => $o->get_currency(),
        'customer_id'  => $o->get_customer_id(),
        'date_created' => $o->get_date_created() ? $o->get_date_created()->date('c') : null,
        'items_count'  => count($o->get_items()),
    ];
}

function wpultra_woo_order_full($o): array {
    $items = [];
    foreach ($o->get_items() as $item_id => $item) {
        $items[] = [
            'item_id'    => $item_id,
            'product_id' => $item->get_product_id(),
            'variation_id' => $item->get_variation_id(),
            'name'       => $item->get_name(),
            'qty'        => $item->get_quantity(),
            'subtotal'   => $item->get_subtotal(),
            'total'      => $item->get_total(),
        ];
    }
    return array_merge(wpultra_woo_order_row($o), [
        'payment_method'       => $o->get_payment_method(),
        'payment_method_title' => $o->get_payment_method_title(),
        'billing'  => $o->get_address('billing'),
        'shipping' => $o->get_address('shipping'),
        'items'            => $items,
        'shipping_total'   => $o->get_shipping_total(),
        'total_tax'        => $o->get_total_tax(),
        'discount_total'   => $o->get_discount_total(),
        'customer_note'    => $o->get_customer_note(),
        'notes_count'      => count(wc_get_order_notes(['order_id' => $o->get_id()])),
    ]);
}

function wpultra_woo_list_orders(array $args): array {
    $q = [
        'limit'   => isset($args['per_page']) ? (int) $args['per_page'] : 20,
        'page'    => isset($args['page']) ? max(1, (int) $args['page']) : 1,
        'orderby' => 'date',
        'order'   => 'DESC',
        'return'  => 'objects',
    ];
    if (!empty($args['status']))   { $q['status'] = $args['status']; } // 'processing' or ['processing','completed']
    if (!empty($args['customer'])) { $q['customer_id'] = (int) $args['customer']; }
    if (!empty($args['date_from'])) { $q['date_created'] = '>=' . $args['date_from']; }
    if (!empty($args['date_to']))   { $q['date_created'] = '<=' . $args['date_to']; }
    if (!empty($args['search']))    { $q['s'] = (string) $args['search']; }
    $orders = wc_get_orders($q);
    $rows = [];
    foreach ($orders as $o) { $rows[] = wpultra_woo_order_row($o); }
    return ['count' => count($rows), 'orders' => $rows];
}

function wpultra_woo_get_order(int $id) {
    $o = wc_get_order($id);
    if (!$o) { return wpultra_err('order_not_found', "No order with id $id."); }
    return wpultra_woo_order_full($o);
}
```

- [ ] **Step 2: Lint** — `& $PHP -l wp-ultra-mcp/includes/woocommerce/orders.php` → `No syntax errors detected`.

- [ ] **Step 3: Write `woo-list-orders` ability** (read-only)

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/woo-list-orders', [
    'label'       => __('WooCommerce: List Orders', 'wp-ultra-mcp'),
    'description' => __('List orders (HPOS-safe) with filters: status, customer id, date_from/date_to (ISO), search, page, per_page.', 'wp-ultra-mcp'),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'status'    => ['type' => 'string'],
            'customer'  => ['type' => 'integer'],
            'date_from' => ['type' => 'string'],
            'date_to'   => ['type' => 'string'],
            'search'    => ['type' => 'string'],
            'page'      => ['type' => 'integer'],
            'per_page'  => ['type' => 'integer'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => ['success' => ['type' => 'boolean'], 'count' => ['type' => 'integer'], 'orders' => ['type' => 'array']],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_woo_list_orders_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_woo_list_orders_cb(array $input) {
    if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    return wpultra_ok(wpultra_woo_list_orders($input));
}
```

- [ ] **Step 4: Write `woo-get-order` ability** (read-only)

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/woo-get-order', [
    'label'       => __('WooCommerce: Get Order', 'wp-ultra-mcp'),
    'description' => __('Get one order\'s full detail (HPOS-safe): items, billing/shipping, totals, payment, notes count.', 'wp-ultra-mcp'),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type'       => 'object',
        'properties' => ['order_id' => ['type' => 'integer']],
        'required'   => ['order_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => ['success' => ['type' => 'boolean'], 'order' => ['type' => 'object']],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_woo_get_order_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_woo_get_order_cb(array $input) {
    if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    $res = wpultra_woo_get_order((int) ($input['order_id'] ?? 0));
    if (is_wp_error($res)) { return $res; }
    return wpultra_ok(['order' => $res]);
}
```

- [ ] **Step 5: Wire engine loop + 2 slugs + bump count** — in `bootstrap-mcp.php` change the woocommerce engine loop array to include `'orders','customers'`; add `'woo-list-orders','woo-get-order'` to the woocommerce group in `wpultra_ability_files()` AND `wpultra_ability_category_map()['woocommerce']`. `tests/bootstrap.test.php` count `63` → `65`.

- [ ] **Step 6: Run bootstrap test** — `& $PHP tests/bootstrap.test.php` → PASS (count 65). Lint both ability files.

- [ ] **Step 7: Deploy + live-verify** — `powershell -File wp-ultra-mcp/bin/deploy.ps1`. Token-gated webroot probe (require wp-load, admin user, require `woocommerce/{setup,schema,products,orders}.php` + the 2 ability files + helpers): create a quick order via `wc_create_order()` + `$o->add_product(wc_get_product(99),2); $o->set_status('processing'); $o->calculate_totals(); $o->save();` (product 99 exists from Plan 1), then call `wpultra_woo_list_orders_cb([])` and `wpultra_woo_get_order_cb(['order_id'=>$o->get_id()])`. Confirm the order appears in the list and get returns items + totals. Force-delete the probe order (`$o->delete(true)`) + delete the script.
Expected: list count ≥1, get shows the line item (product 99, qty 2) + a non-zero total.

- [ ] **Step 8: Commit**

```bash
git add wp-ultra-mcp/includes/woocommerce/orders.php wp-ultra-mcp/includes/abilities/woo-list-orders.php wp-ultra-mcp/includes/abilities/woo-get-order.php wp-ultra-mcp/includes/bootstrap-mcp.php tests/bootstrap.test.php
git commit -m "feat(woocommerce): order read engine + woo-list-orders + woo-get-order (HPOS-safe)"
```

---

### Task 2: `woo-create-order`

**Files:**
- Modify: `wp-ultra-mcp/includes/woocommerce/orders.php` (append `wpultra_woo_create_order`)
- Create: `wp-ultra-mcp/includes/abilities/woo-create-order.php`
- Modify: `bootstrap-mcp.php` (1 slug), `tests/bootstrap.test.php` (65 → 66)

**Interfaces:**
- Produces: `wpultra_woo_create_order(array $input): array|WP_Error` — `['id'=>int, 'total'=>string, 'status'=>string]`. Input: `line_items:[{product_id,quantity,variation_id?}]` (required, non-empty), `customer_id?`, `status?` (default 'pending'), `billing?` (assoc address), `shipping?`, `customer_note?`. Adds each product via `add_product`, sets addresses, `calculate_totals()`, `save()`.

- [ ] **Step 1: Append the engine function to `orders.php`**

```php
function wpultra_woo_create_order(array $input) {
    $lines = $input['line_items'] ?? [];
    if (!is_array($lines) || $lines === []) {
        return wpultra_err('no_line_items', 'create-order requires a non-empty line_items array.');
    }
    $order = wc_create_order();
    if (is_wp_error($order)) { return $order; }

    $added = 0;
    $skipped = [];
    foreach ($lines as $li) {
        $pid = (int) ($li['product_id'] ?? 0);
        $qty = max(1, (int) ($li['quantity'] ?? 1));
        $vid = (int) ($li['variation_id'] ?? 0);
        $product = wc_get_product($vid ?: $pid);
        if (!$product) { $skipped[] = ['product_id' => $pid, 'reason' => 'not_found']; continue; }
        $order->add_product($product, $qty);
        $added++;
    }
    if ($added === 0) {
        $order->delete(true);
        return wpultra_err('no_valid_products', 'None of the line_items resolved to a product.', ['skipped' => $skipped]);
    }

    if (!empty($input['customer_id'])) { $order->set_customer_id((int) $input['customer_id']); }
    if (!empty($input['billing']) && is_array($input['billing']))   { $order->set_address($input['billing'], 'billing'); }
    if (!empty($input['shipping']) && is_array($input['shipping'])) { $order->set_address($input['shipping'], 'shipping'); }
    if (isset($input['customer_note'])) { $order->set_customer_note((string) $input['customer_note']); }
    $order->set_status(!empty($input['status']) ? (string) $input['status'] : 'pending');
    $order->calculate_totals();
    $id = $order->save();
    if (!$id) { return wpultra_err('order_save_failed', 'save() returned 0.'); }
    return ['id' => (int) $id, 'total' => $order->get_total(), 'status' => $order->get_status(), 'skipped' => $skipped];
}
```

- [ ] **Step 2: Lint** — `& $PHP -l wp-ultra-mcp/includes/woocommerce/orders.php`.

- [ ] **Step 3: Write `woo-create-order` ability** (write + audit)

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/woo-create-order', [
    'label'       => __('WooCommerce: Create Order', 'wp-ultra-mcp'),
    'description' => __('Create an order from line_items [{product_id,quantity,variation_id?}], optional customer_id/status/billing/shipping/customer_note. Recalculates totals.', 'wp-ultra-mcp'),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'line_items'    => ['type' => 'array'],
            'customer_id'   => ['type' => 'integer'],
            'status'        => ['type' => 'string'],
            'billing'       => ['type' => 'object'],
            'shipping'      => ['type' => 'object'],
            'customer_note' => ['type' => 'string'],
        ],
        'required'   => ['line_items'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => ['success' => ['type' => 'boolean'], 'id' => ['type' => 'integer'], 'total' => ['type' => 'string'], 'status' => ['type' => 'string']],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_woo_create_order_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_woo_create_order_cb(array $input) {
    if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    $res = wpultra_woo_create_order($input);
    wpultra_audit_log('woo-create-order', is_wp_error($res) ? 'failed' : ('order ' . $res['id'] . ' total ' . $res['total']), !is_wp_error($res));
    if (is_wp_error($res)) { return $res; }
    return wpultra_ok(['id' => $res['id'], 'total' => $res['total'], 'status' => $res['status'], 'skipped' => $res['skipped']]);
}
```

- [ ] **Step 4: Wire slug + bump count** — add `'woo-create-order'` to files + map; `tests/bootstrap.test.php` `65` → `66`.

- [ ] **Step 5: Run bootstrap test** — PASS (count 66). Lint the ability file.

- [ ] **Step 6: Deploy + live-verify** — probe: `woo-create-order` with `line_items:[{product_id:99,quantity:2}]`, `status:'processing'` → assert an id, non-zero total, status `processing`. Then `wpultra_woo_get_order_cb` on it → assert the line item. Pass `line_items:[]` → assert `no_line_items` error. Pass a bad product id → assert it lands in `skipped`. Force-delete the test order + delete the script.

- [ ] **Step 7: Commit**

```bash
git add wp-ultra-mcp/includes/woocommerce/orders.php wp-ultra-mcp/includes/abilities/woo-create-order.php wp-ultra-mcp/includes/bootstrap-mcp.php tests/bootstrap.test.php
git commit -m "feat(woocommerce): woo-create-order (line items + addresses, totals recalculated)"
```

---

### Task 3: `woo-update-order` (status / note / addresses / line items)

**Files:**
- Modify: `wp-ultra-mcp/includes/woocommerce/orders.php` (append `wpultra_woo_update_order`)
- Create: `wp-ultra-mcp/includes/abilities/woo-update-order.php`
- Modify: `bootstrap-mcp.php` (1 slug), `tests/bootstrap.test.php` (66 → 67)

**Interfaces:**
- Produces: `wpultra_woo_update_order(array $input): array|WP_Error` — `['id'=>int,'status'=>string,'total'=>string]`. Input: `order_id` (required), `status?`, `note?` (+`note_to_customer?` bool) → `add_order_note`, `billing?`/`shipping?` addresses, `add_items?:[{product_id,quantity,variation_id?}]`, `remove_item_ids?:[int]`. Recalculates totals if items changed.

- [ ] **Step 1: Append the engine function to `orders.php`**

```php
function wpultra_woo_update_order(array $input) {
    $id = (int) ($input['order_id'] ?? 0);
    $order = wc_get_order($id);
    if (!$order) { return wpultra_err('order_not_found', "No order with id $id."); }

    $items_changed = false;
    if (!empty($input['remove_item_ids']) && is_array($input['remove_item_ids'])) {
        foreach ($input['remove_item_ids'] as $iid) { $order->remove_item((int) $iid); $items_changed = true; }
    }
    if (!empty($input['add_items']) && is_array($input['add_items'])) {
        foreach ($input['add_items'] as $li) {
            $pid = (int) ($li['variation_id'] ?? 0) ?: (int) ($li['product_id'] ?? 0);
            $product = wc_get_product($pid);
            if ($product) { $order->add_product($product, max(1, (int) ($li['quantity'] ?? 1))); $items_changed = true; }
        }
    }
    if (!empty($input['billing']) && is_array($input['billing']))   { $order->set_address($input['billing'], 'billing'); }
    if (!empty($input['shipping']) && is_array($input['shipping'])) { $order->set_address($input['shipping'], 'shipping'); }
    if (isset($input['note']) && $input['note'] !== '') {
        $order->add_order_note((string) $input['note'], !empty($input['note_to_customer']));
    }
    if ($items_changed) { $order->calculate_totals(); }
    if (!empty($input['status'])) { $order->set_status((string) $input['status']); }
    $order->save();
    return ['id' => $id, 'status' => $order->get_status(), 'total' => $order->get_total()];
}
```

- [ ] **Step 2: Lint** — `& $PHP -l wp-ultra-mcp/includes/woocommerce/orders.php`.

- [ ] **Step 3: Write `woo-update-order` ability** (write + audit)

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/woo-update-order', [
    'label'       => __('WooCommerce: Update Order', 'wp-ultra-mcp'),
    'description' => __('Update an order: status, add_order_note (note + note_to_customer), billing/shipping addresses, add_items / remove_item_ids. Recalculates totals on item changes.', 'wp-ultra-mcp'),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'order_id'         => ['type' => 'integer'],
            'status'           => ['type' => 'string'],
            'note'             => ['type' => 'string'],
            'note_to_customer' => ['type' => 'boolean'],
            'billing'          => ['type' => 'object'],
            'shipping'         => ['type' => 'object'],
            'add_items'        => ['type' => 'array'],
            'remove_item_ids'  => ['type' => 'array'],
        ],
        'required'   => ['order_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => ['success' => ['type' => 'boolean'], 'id' => ['type' => 'integer'], 'status' => ['type' => 'string'], 'total' => ['type' => 'string']],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_woo_update_order_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_woo_update_order_cb(array $input) {
    if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    $res = wpultra_woo_update_order($input);
    wpultra_audit_log('woo-update-order', is_wp_error($res) ? 'failed' : ('order ' . $res['id'] . ' -> ' . $res['status']), !is_wp_error($res));
    if (is_wp_error($res)) { return $res; }
    return wpultra_ok($res);
}
```

- [ ] **Step 4: Wire slug + bump count** — add `'woo-update-order'`; `tests/bootstrap.test.php` `66` → `67`.

- [ ] **Step 5: Run bootstrap test** — PASS (count 67). Lint the ability file.

- [ ] **Step 6: Deploy + live-verify** — probe: create a test order; `woo-update-order` set `status:'completed'` + `note:'Packed'` → assert status completed; `get` and assert notes_count increased; add a line item → assert total changed; bad order_id → `order_not_found`. Force-delete the order + delete the script.

- [ ] **Step 7: Commit**

```bash
git add wp-ultra-mcp/includes/woocommerce/orders.php wp-ultra-mcp/includes/abilities/woo-update-order.php wp-ultra-mcp/includes/bootstrap-mcp.php tests/bootstrap.test.php
git commit -m "feat(woocommerce): woo-update-order (status/note/addresses/line-items)"
```

---

### Task 4: `woo-refund-order`

**Files:**
- Modify: `wp-ultra-mcp/includes/woocommerce/orders.php` (append `wpultra_woo_refund_order`)
- Create: `wp-ultra-mcp/includes/abilities/woo-refund-order.php`
- Modify: `bootstrap-mcp.php` (1 slug), `tests/bootstrap.test.php` (67 → 68)

**Interfaces:**
- Produces: `wpultra_woo_refund_order(array $input): array|WP_Error` — `['refund_id'=>int,'amount'=>string,'order_id'=>int]`. Input: `order_id` (required), `amount?` (string; default full remaining), `reason?`, `restock?` (bool, default true), `line_items?` (Woo refund line_items map). Uses `wc_create_refund`.

- [ ] **Step 1: Append the engine function to `orders.php`**

```php
function wpultra_woo_refund_order(array $input) {
    $id = (int) ($input['order_id'] ?? 0);
    $order = wc_get_order($id);
    if (!$order) { return wpultra_err('order_not_found', "No order with id $id."); }

    $amount = isset($input['amount']) && $input['amount'] !== '' ? (string) $input['amount'] : (string) $order->get_remaining_refund_amount();
    if ((float) $amount <= 0) { return wpultra_err('nothing_to_refund', 'Refund amount is zero or order is fully refunded.'); }

    $args = [
        'order_id'       => $id,
        'amount'         => $amount,
        'reason'         => (string) ($input['reason'] ?? ''),
        'restock_items'  => array_key_exists('restock', $input) ? (bool) $input['restock'] : true,
    ];
    if (!empty($input['line_items']) && is_array($input['line_items'])) { $args['line_items'] = $input['line_items']; }

    $refund = wc_create_refund($args);
    if (is_wp_error($refund)) { return $refund; }
    return ['refund_id' => $refund->get_id(), 'amount' => $refund->get_amount(), 'order_id' => $id];
}
```

- [ ] **Step 2: Lint** — `& $PHP -l wp-ultra-mcp/includes/woocommerce/orders.php`.

- [ ] **Step 3: Write `woo-refund-order` ability** (destructive + audit)

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/woo-refund-order', [
    'label'       => __('WooCommerce: Refund Order', 'wp-ultra-mcp'),
    'description' => __('Refund an order: amount (default full remaining), reason, restock (default true), optional line_items. Uses wc_create_refund.', 'wp-ultra-mcp'),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'order_id'   => ['type' => 'integer'],
            'amount'     => ['type' => 'string'],
            'reason'     => ['type' => 'string'],
            'restock'    => ['type' => 'boolean'],
            'line_items' => ['type' => 'object'],
        ],
        'required'   => ['order_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => ['success' => ['type' => 'boolean'], 'refund_id' => ['type' => 'integer'], 'amount' => ['type' => 'string']],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_woo_refund_order_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_woo_refund_order_cb(array $input) {
    if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    $res = wpultra_woo_refund_order($input);
    wpultra_audit_log('woo-refund-order', is_wp_error($res) ? 'failed' : ('order ' . $res['order_id'] . ' refund ' . $res['amount']), !is_wp_error($res));
    if (is_wp_error($res)) { return $res; }
    return wpultra_ok(['refund_id' => $res['refund_id'], 'amount' => $res['amount']]);
}
```

- [ ] **Step 4: Wire slug + bump count** — add `'woo-refund-order'`; `tests/bootstrap.test.php` `67` → `68`.

- [ ] **Step 5: Run bootstrap test** — PASS (count 68). Lint the ability file.

- [ ] **Step 6: Deploy + live-verify** — probe: create a test order (product 99 x2, status processing), then `woo-refund-order` with a partial `amount:'10'` → assert a refund_id + amount; `get` the order and assert `get_total_refunded()` reflects it (or re-list). Then full-refund a second test order (no amount) → assert remaining→0. Bad order_id → `order_not_found`. Force-delete the test orders + delete the script.

- [ ] **Step 7: Commit**

```bash
git add wp-ultra-mcp/includes/woocommerce/orders.php wp-ultra-mcp/includes/abilities/woo-refund-order.php wp-ultra-mcp/includes/bootstrap-mcp.php tests/bootstrap.test.php
git commit -m "feat(woocommerce): woo-refund-order (full/partial + restock)"
```

---

### Task 5: `customers.php` + customer validator + `woo-list-customers` + `woo-get-customer`

**Files:**
- Modify: `wp-ultra-mcp/includes/woocommerce/schema.php` (append `wpultra_woo_validate_customer`)
- Create: `wp-ultra-mcp/includes/woocommerce/customers.php` (read functions; upsert appended Task 6)
- Create: `wp-ultra-mcp/includes/abilities/woo-list-customers.php`, `woo-get-customer.php`
- Modify: `tests/woo-schema.test.php` (customer-validator tests), `bootstrap-mcp.php` (2 slugs), `tests/bootstrap.test.php` (68 → 70)

**Interfaces:**
- Produces:
  - `wpultra_woo_validate_customer(array $input): array` — `['clean'=>[...], 'rejected'=>[...]]`; whitelist `email,first_name,last_name,username,password,billing,shipping,role`; unknown → `unknown_field`; bad email → `invalid_email`.
  - `wpultra_woo_customer_row(WC_Customer $c): array`, `wpultra_woo_customer_full(WC_Customer $c): array`.
  - `wpultra_woo_list_customers(array $args): array` — `['count','customers'=>[rows]]` (via `get_users(['role'=>'customer'])` + `wc_get_customer_total_spent`/`wc_get_customer_order_count`).
  - `wpultra_woo_get_customer(int $id): array|WP_Error` — `customer_not_found` on a non-customer/missing id.

- [ ] **Step 1: Write the failing customer-validator tests** — append to `tests/woo-schema.test.php` (before `run_tests();`)

```php
it('customer validate keeps known + rejects unknown', function () {
    $r = wpultra_woo_validate_customer(['email' => 'a@b.com', 'first_name' => 'Ann', 'bogus' => 1]);
    assert_eq('a@b.com', $r['clean']['email']);
    assert_eq('Ann', $r['clean']['first_name']);
    assert_eq(1, count($r['rejected']));
    assert_eq('bogus', $r['rejected'][0]['field']);
    assert_eq('unknown_field', $r['rejected'][0]['reason']);
});

it('customer validate rejects bad email', function () {
    $r = wpultra_woo_validate_customer(['email' => 'not-an-email']);
    assert_eq(1, count($r['rejected']));
    assert_eq('invalid_email', $r['rejected'][0]['reason']);
    assert_true(!isset($r['clean']['email']));
});
```

- [ ] **Step 2: Run → fail** — `& $PHP tests/woo-schema.test.php` → FAIL (`wpultra_woo_validate_customer` undefined).

- [ ] **Step 3: Append `wpultra_woo_validate_customer` to `schema.php`**

```php
function wpultra_woo_customer_schema(): array {
    return [
        'email'      => ['type' => 'email'],
        'first_name' => ['type' => 'string'],
        'last_name'  => ['type' => 'string'],
        'username'   => ['type' => 'string'],
        'password'   => ['type' => 'string'],
        'role'       => ['type' => 'string'],
        'billing'    => ['type' => 'array'],
        'shipping'   => ['type' => 'array'],
    ];
}

function wpultra_woo_validate_customer(array $input): array {
    $schema = wpultra_woo_customer_schema();
    $clean = [];
    $rejected = [];
    foreach ($input as $field => $value) {
        if (!isset($schema[$field])) { $rejected[] = ['field' => $field, 'reason' => 'unknown_field']; continue; }
        if ($schema[$field]['type'] === 'email') {
            if (!is_string($value) || strpos($value, '@') === false || strpos($value, '.') === false) {
                $rejected[] = ['field' => $field, 'reason' => 'invalid_email'];
                continue;
            }
            $clean[$field] = $value;
        } elseif ($schema[$field]['type'] === 'array') {
            $clean[$field] = is_array($value) ? $value : [];
        } else {
            $clean[$field] = (string) $value;
        }
    }
    return ['clean' => $clean, 'rejected' => $rejected];
}
```
(Pure — no WP calls. The email check is intentionally a simple structural check so the validator stays harness-pure; `WC_Customer::set_email` does the authoritative validation on save.)

- [ ] **Step 4: Run → pass** — `& $PHP tests/woo-schema.test.php` → PASS (now 7 `it` blocks).

- [ ] **Step 5: Write `customers.php` (read half)**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

function wpultra_woo_customer_row($c): array {
    return [
        'id'          => $c->get_id(),
        'email'       => $c->get_email(),
        'name'        => trim($c->get_first_name() . ' ' . $c->get_last_name()),
        'orders'      => wc_get_customer_order_count($c->get_id()),
        'total_spent' => wc_get_customer_total_spent($c->get_id()),
    ];
}

function wpultra_woo_customer_full($c): array {
    return array_merge(wpultra_woo_customer_row($c), [
        'first_name' => $c->get_first_name(),
        'last_name'  => $c->get_last_name(),
        'username'   => $c->get_username(),
        'billing'    => $c->get_billing(),
        'shipping'   => $c->get_shipping(),
        'date_created' => $c->get_date_created() ? $c->get_date_created()->date('c') : null,
    ]);
}

function wpultra_woo_list_customers(array $args): array {
    $q = [
        'role'   => 'customer',
        'number' => isset($args['per_page']) ? (int) $args['per_page'] : 20,
        'paged'  => isset($args['page']) ? max(1, (int) $args['page']) : 1,
        'fields' => 'ID',
    ];
    if (!empty($args['search'])) { $q['search'] = '*' . $args['search'] . '*'; $q['search_columns'] = ['user_email', 'display_name']; }
    $ids = get_users($q);
    $rows = [];
    foreach ($ids as $uid) {
        $c = new WC_Customer((int) $uid);
        if ($c->get_id()) { $rows[] = wpultra_woo_customer_row($c); }
    }
    return ['count' => count($rows), 'customers' => $rows];
}

function wpultra_woo_get_customer(int $id) {
    if ($id <= 0) { return wpultra_err('customer_not_found', 'No customer id given.'); }
    $c = new WC_Customer($id);
    if (!$c->get_id()) { return wpultra_err('customer_not_found', "No customer with id $id."); }
    return wpultra_woo_customer_full($c);
}
```

- [ ] **Step 6: Lint** — `& $PHP -l` on `schema.php` + `customers.php`.

- [ ] **Step 7: Write `woo-list-customers` + `woo-get-customer` abilities** (read-only; same shape as `woo-list-products`/`woo-get-product` — `properties` plain array, `readonly=>true`, no audit, Woo gate). `woo-list-customers` input `{search?,page?,per_page?}` → `wpultra_ok(wpultra_woo_list_customers($input))`. `woo-get-customer` input `{customer_id}` required → `wpultra_woo_get_customer((int)$input['customer_id'])` wrapped as `['customer'=>...]` with `is_wp_error` guard.

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/woo-list-customers', [
    'label'       => __('WooCommerce: List Customers', 'wp-ultra-mcp'),
    'description' => __('List customers with optional search/page/per_page; rows include order count + total spent.', 'wp-ultra-mcp'),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type' => 'object',
        'properties' => ['search' => ['type' => 'string'], 'page' => ['type' => 'integer'], 'per_page' => ['type' => 'integer']],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean'], 'count' => ['type' => 'integer'], 'customers' => ['type' => 'array']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_woo_list_customers_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

function wpultra_woo_list_customers_cb(array $input) {
    if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    return wpultra_ok(wpultra_woo_list_customers($input));
}
```

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/woo-get-customer', [
    'label'       => __('WooCommerce: Get Customer', 'wp-ultra-mcp'),
    'description' => __('Get one customer\'s full detail: name, email, billing/shipping, order count, total spent.', 'wp-ultra-mcp'),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type' => 'object',
        'properties' => ['customer_id' => ['type' => 'integer']],
        'required' => ['customer_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean'], 'customer' => ['type' => 'object']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_woo_get_customer_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

function wpultra_woo_get_customer_cb(array $input) {
    if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    $res = wpultra_woo_get_customer((int) ($input['customer_id'] ?? 0));
    if (is_wp_error($res)) { return $res; }
    return wpultra_ok(['customer' => $res]);
}
```

- [ ] **Step 8: Wire 2 slugs + bump count** — add `'woo-list-customers','woo-get-customer'` to files + map; `tests/bootstrap.test.php` `68` → `70`.

- [ ] **Step 9: Run the suite** — `& $PHP tests/woo-schema.test.php` (7 pass) + `& $PHP tests/bootstrap.test.php` (count 70). Lint all changed files.

- [ ] **Step 10: Deploy + live-verify** — probe: create a customer via `$c=new WC_Customer(); $c->set_email('probe@x.com'); $c->set_first_name('Probe'); $c->save();` then `woo-list-customers` → assert ≥1; `woo-get-customer` on its id → assert email + name; bad id → `customer_not_found`. Delete the customer (`$c->delete(true)`) + delete the script.

- [ ] **Step 11: Commit**

```bash
git add wp-ultra-mcp/includes/woocommerce/schema.php wp-ultra-mcp/includes/woocommerce/customers.php wp-ultra-mcp/includes/abilities/woo-list-customers.php wp-ultra-mcp/includes/abilities/woo-get-customer.php wp-ultra-mcp/includes/bootstrap-mcp.php tests/woo-schema.test.php tests/bootstrap.test.php
git commit -m "feat(woocommerce): customer read engine + validator + woo-list-customers + woo-get-customer"
```

---

### Task 6: `woo-upsert-customer`

**Files:**
- Modify: `wp-ultra-mcp/includes/woocommerce/customers.php` (append `wpultra_woo_upsert_customer`)
- Create: `wp-ultra-mcp/includes/abilities/woo-upsert-customer.php`
- Modify: `bootstrap-mcp.php` (1 slug), `tests/bootstrap.test.php` (70 → 71)

**Interfaces:**
- Consumes: `wpultra_woo_validate_customer()` (Task 5).
- Produces: `wpultra_woo_upsert_customer(array $input): array|WP_Error` — `['id'=>int,'rejected'=>[...]]`. Create (no `id`; `email` required) or update (with `id`). Applies validated fields via `WC_Customer` setters; `save()`.

- [ ] **Step 1: Append the engine function to `customers.php`**

```php
function wpultra_woo_upsert_customer(array $input) {
    $id = isset($input['id']) ? (int) $input['id'] : 0;
    unset($input['id']);
    $validated = wpultra_woo_validate_customer($input);
    $clean = $validated['clean'];

    if ($id) {
        $c = new WC_Customer($id);
        if (!$c->get_id()) { return wpultra_err('customer_not_found', "No customer with id $id."); }
    } else {
        if (empty($clean['email'])) { return wpultra_err('email_required', 'Creating a customer requires a valid email.'); }
        $c = new WC_Customer();
    }

    $setters = [
        'email' => 'set_email', 'first_name' => 'set_first_name', 'last_name' => 'set_last_name',
        'username' => 'set_username', 'password' => 'set_password', 'role' => 'set_role',
    ];
    foreach ($setters as $field => $method) {
        if (array_key_exists($field, $clean) && method_exists($c, $method)) {
            try { $c->{$method}($clean[$field]); } catch (\Throwable $e) { $validated['rejected'][] = ['field' => $field, 'reason' => 'setter_error']; }
        }
    }
    if (!empty($clean['billing']) && is_array($clean['billing']))   { $c->set_billing($clean['billing']); }
    if (!empty($clean['shipping']) && is_array($clean['shipping'])) { $c->set_shipping($clean['shipping']); }

    $newId = $c->save();
    if (!$newId) { return wpultra_err('customer_save_failed', 'WC_Customer save() returned 0.'); }
    return ['id' => (int) $newId, 'rejected' => $validated['rejected']];
}
```

- [ ] **Step 2: Lint** — `& $PHP -l wp-ultra-mcp/includes/woocommerce/customers.php`.

- [ ] **Step 3: Write `woo-upsert-customer` ability** (write + audit)

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/woo-upsert-customer', [
    'label'       => __('WooCommerce: Upsert Customer', 'wp-ultra-mcp'),
    'description' => __('Create (email required) or update (pass id) a customer. Fields: email, first_name, last_name, username, password, role, billing, shipping. Returns rejected fields.', 'wp-ultra-mcp'),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'id'         => ['type' => 'integer'],
            'email'      => ['type' => 'string'],
            'first_name' => ['type' => 'string'],
            'last_name'  => ['type' => 'string'],
            'username'   => ['type' => 'string'],
            'password'   => ['type' => 'string'],
            'role'       => ['type' => 'string'],
            'billing'    => ['type' => 'object'],
            'shipping'   => ['type' => 'object'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => ['success' => ['type' => 'boolean'], 'id' => ['type' => 'integer'], 'rejected' => ['type' => 'array']],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_woo_upsert_customer_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_woo_upsert_customer_cb(array $input) {
    if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    $res = wpultra_woo_upsert_customer($input);
    wpultra_audit_log('woo-upsert-customer', is_wp_error($res) ? 'failed' : ('customer ' . $res['id']), !is_wp_error($res));
    if (is_wp_error($res)) { return $res; }
    return wpultra_ok(['id' => $res['id'], 'rejected' => $res['rejected']]);
}
```

- [ ] **Step 4: Wire slug + bump count** — add `'woo-upsert-customer'`; `tests/bootstrap.test.php` `70` → `71`.

- [ ] **Step 5: Run the FULL suite** — `powershell -File tests/run-all.ps1` → `ALL TEST FILES PASSED` (bootstrap 71, woo-schema 7, nothing regressed). Lint the ability file.

- [ ] **Step 6: Deploy + live-verify** — probe: `woo-upsert-customer` create (`email:'newcust@x.com', first_name:'New'`) → assert id + empty rejected; `woo-get-customer` on it → assert email; update (by id) `last_name:'Buyer'` → re-get assert; create with NO email → assert `email_required`; create with an unknown field → assert it appears in `rejected`. Delete the test customer + delete the script.

- [ ] **Step 7: Commit**

```bash
git add wp-ultra-mcp/includes/woocommerce/customers.php wp-ultra-mcp/includes/abilities/woo-upsert-customer.php wp-ultra-mcp/includes/bootstrap-mcp.php tests/bootstrap.test.php
git commit -m "feat(woocommerce): woo-upsert-customer (validated create/update)"
```

---

## Plan 2 Done — exit criteria

- 8 abilities (5 orders + 3 customers) under the `woocommerce` category; `tests/bootstrap.test.php` count = **71**; full suite green.
- Orders are end-to-end live-verified HPOS-safely (create → get → update status/note → refund) and customers (upsert → get → list) — all via the Woo CRUD API, zero `get_posts('shop_order')`/postmeta.
- Record any live API quirks (refund line_items shape, customer role behavior) into the SDD ledger for Plan 3/4.
- Do NOT bump plugin version (Plan 4 ships v0.10.0).

## Self-Review notes (done during planning)

- **Spec coverage (Plan 2 slice):** list/get/create/update/refund orders ✓ (Tasks 1–4), list/get/upsert customers ✓ (Tasks 5–6), HPOS-safe via CRUD API ✓ (constraint + engine uses `wc_get_orders`/`wc_get_order`/`wc_create_order`/`wc_create_refund`/`WC_Customer`), validated customer input ✓ (Task 5 validator, unit-tested), graceful Woo-inactive gate ✓, audit on writes/refund ✓. Coupons/settings/reviews/reports/bridge/skill remain Plans 3–4.
- **Type consistency:** engine return shapes (`['count','orders']`, `['id','total','status']`, `['refund_id','amount','order_id']`, `['count','customers']`, `['id','rejected']`) match their callbacks; `wpultra_woo_validate_customer` name consistent across schema + customers engine + tests; count chain 63→65→66→67→68→70→71 monotonic and matches the per-task bumps; engine require loop updated once (Task 1) to `['setup','schema','products','orders','customers']`.
- **Placeholders:** none — every step has concrete code/commands. Live-test probe bodies are described entity-by-entity following the established `wpultra-*.php` pattern (require engine + ability files, token-gate, clean up).
- **HPOS:** every order function uses only `wc_*`/`WC_Order*` APIs — no legacy post/postmeta access anywhere in `orders.php`.
