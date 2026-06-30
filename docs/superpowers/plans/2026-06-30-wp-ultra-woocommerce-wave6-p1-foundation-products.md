# WP-Ultra-MCP — Wave 6 WooCommerce · Plan 1: Foundation + Products

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **This is Plan 1 of the Wave 6 program** (spec: `docs/superpowers/specs/2026-06-30-wp-ultra-woocommerce-wave6-design.md`). Wave 6 ships as **v0.10.0**. It is built in phases, each its own plan + each independently testable, because the WooCommerce API must be live-verified before later phases (orders/HPOS, reports) can be planned with real code. Plan order:
> 1. **Foundation + Products** ← THIS PLAN (installs Woo, verifies the API, builds the catalog layer)
> 2. Orders + Customers (HPOS-safe) — planned after Plan 1's API facts are recorded
> 3. Marketing/Config (coupons, settings, reviews) + Reports
> 4. Elementor/Gutenberg bridge + `woocommerce-architect` skill + version bump → ship v0.10.0
>
> Do NOT bump the plugin version or write release notes in this plan — that happens in Plan 4.

**Goal:** Install WooCommerce on the test site, add a first-class detection layer + validated product field schema, and ship 8 catalog abilities (`woo-store-status`, `woo-list-products`, `woo-get-product`, `woo-upsert-product`, `woo-delete-product`, `woo-manage-variation`, `woo-manage-product-category`, `woo-manage-attribute`) built on WooCommerce's own CRUD API.

**Architecture:** A new `includes/woocommerce/` engine directory mirrors `includes/elementor/`. `setup.php` answers "is Woo usable / how is it configured" (active, HPOS, pages, currency). `schema.php` is pure validation/coercion for product input. `products.php` wraps the `WC_Product*` CRUD objects. Thin abilities call the engine + audit. A new `woocommerce` ability category gates the whole group.

**Tech Stack:** PHP 8.0+ (`declare(strict_types=1)`), WP 7.0, WooCommerce (installed in Task 1), the WooCommerce CRUD API (`wc_get_product`, `WC_Product_Simple|Variable|Grouped|External`, `WC_Product_Variation`, `wc_get_products`, `wc_create_attribute`), vendored mcp-adapter, WordPress Abilities API. No new Composer/npm dependencies.

## Global Constraints

- Every PHP file starts with `<?php` + `declare(strict_types=1);` + `if (!defined('ABSPATH')) { exit(); }`.
- Engine functions return arrays/values or `WP_Error`. Abilities return `wpultra_ok([...])` (merges `['success'=>true]`) or `wpultra_err($code, $message, $data='')` (returns `WP_Error`). Helpers live in `wp-ultra-mcp/includes/helpers.php`.
- **Ability registration MUST match the codebase shape** — copy `wp-ultra-mcp/includes/abilities/gutenberg-insert-pattern.php` exactly: `wp_register_ability('wpultra/<slug>', [...])` with `label`/`description` in `__()`, `category => 'woocommerce'`, `input_schema` (with `properties` a PLAIN ARRAY, never `(object)`), `output_schema`, a named string `execute_callback` (NOT a closure), `permission_callback => 'wpultra_permission_callback'`, and the `meta` block `['show_in_rest'=>true, 'mcp'=>['public'=>true,'type'=>'tool'], 'annotations'=>[...]]`.
- Read abilities: `annotations => ['readonly'=>true,'destructive'=>false,'idempotent'=>true]`, NO audit. Write abilities: `['readonly'=>false,'destructive'=>false,'idempotent'=>false]` (delete uses `'destructive'=>true`), and call `wpultra_audit_log('<slug>', '<summary>', $ok)` after the write.
- **Every ability's callback gates on Woo first:** `if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active. Install/activate it first (wp plugin install woocommerce --activate).'); }`.
- **Never touch postmeta/SQL directly for Woo entities** — always go through `wc_get_product()` / `WC_Product*` setters / `->save()`. (HPOS-safety matters in later plans; staying on the CRUD API now keeps the whole wave consistent.)
- **New category `woocommerce`** must be registered in THREE places (Task 3): `wpultra_register_categories()` (the `$cats` map), and each ability slug added to BOTH `wpultra_ability_files()` and `wpultra_ability_category_map()['woocommerce']` in `wp-ultra-mcp/includes/bootstrap-mcp.php`. Add a `woocommerce` engine require loop to `wpultra_load_abilities()` (mirror the elementor/gutenberg loops, gated on the category not being disabled).
- **`tests/bootstrap.test.php` asserts the EXACT ability count** (currently `55`, line 14) AND that the category map covers every file exactly once. Each task that adds abilities bumps that number and keeps the map in sync. Final count after this plan: **63**.
- Bundled PHP for lint/tests: `$PHP = C:/Users/nisha/AppData/Roaming/Local/lightning-services/php-8.2.30+1/bin/win64/php.exe`. Test site root: `C:/Users/nisha/Local Sites/wp-connector/app/public`. Live token: `wpultra-test-9a88`.
- **Re-run `wp-ultra-mcp/bin/deploy.ps1` after every commit** (Local runs the deployed copy). Commands run from `E:\wp-connector`.
- **Harness** (`tests/harness.php`): `it('name', fn)`, `assert_eq($expected,$actual)` (strict), `assert_true($cond,$msg='')`, `assert_wp_error($v,$code='')`; file ends with `run_tests();`. `tests/run-all.ps1` auto-globs `*.test.php`. Engine files must reference WooCommerce/WP functions ONLY inside function bodies (so the files load under the no-WP harness); pure tests stub any WP/Woo function they exercise.
- Commit messages use the form `feat(woocommerce): …` / `test(woocommerce): …`; end the body with `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.

## File Structure

```
wp-ultra-mcp/includes/
  woocommerce/
    setup.php       NEW — wpultra_woo_active / _hpos / _store_status (Task 1)
    schema.php      NEW — pure product field schema + validate/coerce (Task 2)
    products.php    NEW — WC_Product CRUD wrappers (Tasks 4–7)
  abilities/
    woo-store-status.php           NEW (Task 3)
    woo-list-products.php          NEW (Task 4)
    woo-get-product.php            NEW (Task 4)
    woo-upsert-product.php         NEW (Task 5)
    woo-delete-product.php         NEW (Task 6)
    woo-manage-variation.php       NEW (Task 6)
    woo-manage-product-category.php NEW (Task 7)
    woo-manage-attribute.php       NEW (Task 7)
  bootstrap-mcp.php   MODIFY — register category + wire engine + 8 abilities (Tasks 3–7)
tests/
  woo-schema.test.php   NEW — pure unit tests for schema.php (Task 2)
  bootstrap.test.php    MODIFY — count 55 → 63, add woocommerce to map (Tasks 3–7)
```

---

### Task 1: Install WooCommerce + `setup.php` detection layer

**Files:**
- Create: `wp-ultra-mcp/includes/woocommerce/setup.php`
- (No unit test — detection is live-verified; it only calls Woo/WP globals.)

**Interfaces:**
- Produces: `wpultra_woo_active(): bool`, `wpultra_woo_hpos_enabled(): bool`, `wpultra_woo_store_status(): array` (keys `active, version, hpos_enabled, currency, base_country, pages, counts`).

- [ ] **Step 1: Install + activate WooCommerce on the test site**

Run (from `E:\wp-connector`):
```bash
PHP="C:/Users/nisha/AppData/Roaming/Local/lightning-services/php-8.2.30+1/bin/win64/php.exe"
WP="C:/Users/nisha/Local Sites/wp-connector/app/public"
"$PHP" "$WP/wp-content/plugins/woocommerce/woocommerce.php" >/dev/null 2>&1 # noop; real install next
"$PHP" -d memory_limit=512M "$WP/../../../../"  # placeholder
```
Preferred (use the plugin's own ability path — run via the running site or wp-cli if available). If wp-cli is on PATH:
```bash
wp --path="$WP" plugin install woocommerce --activate
```
If wp-cli is NOT available, install through the running site by dropping a token-gated script `$WP/wp-content/wpultra-wooinstall.php`:
```php
<?php
require dirname(__DIR__) . '/wp-load.php';
if (($_GET['t'] ?? '') !== 'wpultra-test-9a88') { http_response_code(403); exit('no'); }
require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/misc.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
$api = plugins_api('plugin_information', ['slug' => 'woocommerce', 'fields' => ['sections' => false]]);
$up = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
$res = $up->install($api->download_link);
activate_plugin('woocommerce/woocommerce.php');
echo json_encode(['installed' => $res, 'active' => is_plugin_active('woocommerce/woocommerce.php'), 'version' => defined('WC_VERSION') ? WC_VERSION : null]);
```
Then `curl "http://wp-connector.local/wp-content/wpultra-wooinstall.php?t=wpultra-test-9a88"` and **delete the file**.
Expected: JSON `{"active":true,"version":"<x.y.z>"}`.

- [ ] **Step 2: Write `setup.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/** True when WooCommerce is loaded and usable. */
function wpultra_woo_active(): bool {
    return class_exists('WooCommerce') && defined('WC_VERSION');
}

/** True when High-Performance Order Storage (custom order tables) is on. */
function wpultra_woo_hpos_enabled(): bool {
    $cls = 'Automattic\\WooCommerce\\Utilities\\OrderUtil';
    if (!class_exists($cls)) { return false; }
    return (bool) call_user_func([$cls, 'custom_orders_table_usage_is_enabled']);
}

/** Snapshot of store configuration for the AI's entry point. */
function wpultra_woo_store_status(): array {
    if (!wpultra_woo_active()) {
        return ['active' => false];
    }
    $pages = [
        'shop'      => (int) wc_get_page_id('shop'),
        'cart'      => (int) wc_get_page_id('cart'),
        'checkout'  => (int) wc_get_page_id('checkout'),
        'myaccount' => (int) wc_get_page_id('myaccount'),
    ];
    $counts = [
        'products'  => (int) wp_count_posts('product')->publish,
        'orders'    => function_exists('wc_orders_count') ? (int) wc_orders_count('completed') + (int) wc_orders_count('processing') : 0,
        'customers' => (int) (function_exists('wc_get_customer_default_role') ? count_users()['avail_roles']['customer'] ?? 0 : 0),
    ];
    return [
        'active'       => true,
        'version'      => WC_VERSION,
        'hpos_enabled' => wpultra_woo_hpos_enabled(),
        'currency'     => get_woocommerce_currency(),
        'base_country' => WC()->countries ? WC()->countries->get_base_country() : '',
        'pages'        => $pages,
        'counts'       => $counts,
    ];
}
```

- [ ] **Step 3: Lint**

Run: `& $PHP -l wp-ultra-mcp/includes/woocommerce/setup.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Live-verify detection** (token-gated probe). Create `$WP/wp-content/wpultra-woocheck.php`:

```php
<?php
require dirname(__DIR__) . '/wp-load.php';
if (($_GET['t'] ?? '') !== 'wpultra-test-9a88') { http_response_code(403); exit('no'); }
require_once dirname(__DIR__) . '/wp-content/plugins/wp-ultra-mcp/includes/woocommerce/setup.php';
header('Content-Type: application/json');
echo json_encode(wpultra_woo_store_status(), JSON_PRETTY_PRINT);
```
Run: `curl "http://wp-connector.local/wp-content/wpultra-woocheck.php?t=wpultra-test-9a88"` then **delete the file**.
Expected: JSON with `active:true`, a `version`, a boolean `hpos_enabled`, `currency` (e.g. `USD`), and a `pages`/`counts` object. **Record the observed `hpos_enabled` value + WC version into project memory** (the later orders plan needs it).

- [ ] **Step 5: Commit**

```bash
git add wp-ultra-mcp/includes/woocommerce/setup.php
git commit -m "feat(woocommerce): WooCommerce detection layer (active/HPOS/store-status)"
```

---

### Task 2: `schema.php` — pure product field schema + validation/coercion

**Files:**
- Create: `wp-ultra-mcp/includes/woocommerce/schema.php`
- Test: `tests/woo-schema.test.php`

**Interfaces:**
- Produces:
  - `wpultra_woo_product_schema(): array` — map `field => ['type'=>'string|number|bool|enum|money|int|array', 'enum'?=>[], 'writable'=>true]`.
  - `wpultra_woo_coerce_money($v): ?string` — money string or null (empty → null, numeric → string with up to 2 decimals stripped of trailing junk).
  - `wpultra_woo_coerce_bool($v): bool`.
  - `wpultra_woo_validate_product(array $input): array` — `['clean'=>[...], 'rejected'=>[['field'=>..,'reason'=>..], ...]]`. Unknown keys → rejected `unknown_field`; enum mismatch → `invalid_enum`; type-coercion failure → `invalid_type`. Known keys coerced into `clean`.

- [ ] **Step 1: Write the failing test** — `tests/woo-schema.test.php`

```php
<?php
require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../wp-ultra-mcp/includes/woocommerce/schema.php';

it('coerces money', function () {
    assert_eq('19.99', wpultra_woo_coerce_money('19.99'));
    assert_eq('20', wpultra_woo_coerce_money(20));
    assert_eq(null, wpultra_woo_coerce_money(''));
});

it('coerces bool', function () {
    assert_true(wpultra_woo_coerce_bool('yes'));
    assert_true(wpultra_woo_coerce_bool(1));
    assert_true(!wpultra_woo_coerce_bool('no'));
    assert_true(!wpultra_woo_coerce_bool(false));
});

it('validate keeps known fields and coerces', function () {
    $r = wpultra_woo_validate_product(['name' => 'Hat', 'regular_price' => '9.5', 'manage_stock' => 'yes']);
    assert_eq('Hat', $r['clean']['name']);
    assert_eq('9.5', $r['clean']['regular_price']);
    assert_eq(true, $r['clean']['manage_stock']);
    assert_eq([], $r['rejected']);
});

it('validate rejects unknown field', function () {
    $r = wpultra_woo_validate_product(['name' => 'Hat', 'frobnicate' => 1]);
    assert_eq('Hat', $r['clean']['name']);
    assert_eq(1, count($r['rejected']));
    assert_eq('frobnicate', $r['rejected'][0]['field']);
    assert_eq('unknown_field', $r['rejected'][0]['reason']);
});

it('validate rejects bad enum', function () {
    $r = wpultra_woo_validate_product(['type' => 'wormhole']);
    assert_eq(1, count($r['rejected']));
    assert_eq('invalid_enum', $r['rejected'][0]['reason']);
    assert_true(!isset($r['clean']['type']));
});

run_tests();
```

- [ ] **Step 2: Run test to verify it fails**

Run: `& $PHP tests/woo-schema.test.php`
Expected: FAIL — `wpultra_woo_coerce_money` undefined (fatal) or assertion failures.

- [ ] **Step 3: Write minimal implementation** — `wp-ultra-mcp/includes/woocommerce/schema.php`

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH') && !defined('WPULTRA_TEST')) { /* allow harness load */ }

function wpultra_woo_product_schema(): array {
    return [
        'name'               => ['type' => 'string',  'writable' => true],
        'slug'               => ['type' => 'string',  'writable' => true],
        'type'               => ['type' => 'enum', 'enum' => ['simple', 'variable', 'grouped', 'external'], 'writable' => true],
        'status'             => ['type' => 'enum', 'enum' => ['publish', 'draft', 'pending', 'private'], 'writable' => true],
        'catalog_visibility' => ['type' => 'enum', 'enum' => ['visible', 'catalog', 'search', 'hidden'], 'writable' => true],
        'featured'           => ['type' => 'bool',   'writable' => true],
        'description'        => ['type' => 'string', 'writable' => true],
        'short_description'  => ['type' => 'string', 'writable' => true],
        'sku'                => ['type' => 'string', 'writable' => true],
        'regular_price'      => ['type' => 'money',  'writable' => true],
        'sale_price'         => ['type' => 'money',  'writable' => true],
        'manage_stock'       => ['type' => 'bool',   'writable' => true],
        'stock_quantity'     => ['type' => 'int',    'writable' => true],
        'stock_status'       => ['type' => 'enum', 'enum' => ['instock', 'outofstock', 'onbackorder'], 'writable' => true],
        'backorders'         => ['type' => 'enum', 'enum' => ['no', 'notify', 'yes'], 'writable' => true],
        'weight'             => ['type' => 'string', 'writable' => true],
        'length'             => ['type' => 'string', 'writable' => true],
        'width'              => ['type' => 'string', 'writable' => true],
        'height'             => ['type' => 'string', 'writable' => true],
        'virtual'            => ['type' => 'bool',   'writable' => true],
        'downloadable'       => ['type' => 'bool',   'writable' => true],
        'category_ids'       => ['type' => 'array',  'writable' => true],
        'tag_ids'            => ['type' => 'array',  'writable' => true],
        'image_id'           => ['type' => 'int',    'writable' => true],
        'gallery_image_ids'  => ['type' => 'array',  'writable' => true],
        'menu_order'         => ['type' => 'int',    'writable' => true],
        'external_url'       => ['type' => 'string', 'writable' => true],
        'button_text'        => ['type' => 'string', 'writable' => true],
    ];
}

function wpultra_woo_coerce_money($v): ?string {
    if ($v === '' || $v === null) { return null; }
    if (!is_numeric($v)) { return null; }
    return (string) (0 + $v);
}

function wpultra_woo_coerce_bool($v): bool {
    if (is_bool($v)) { return $v; }
    if (is_int($v)) { return $v !== 0; }
    $s = strtolower(trim((string) $v));
    return in_array($s, ['1', 'yes', 'true', 'on'], true);
}

function wpultra_woo_validate_product(array $input): array {
    $schema = wpultra_woo_product_schema();
    $clean = [];
    $rejected = [];
    foreach ($input as $field => $value) {
        if (!isset($schema[$field])) {
            $rejected[] = ['field' => $field, 'reason' => 'unknown_field'];
            continue;
        }
        $def = $schema[$field];
        switch ($def['type']) {
            case 'enum':
                if (!in_array($value, $def['enum'], true)) {
                    $rejected[] = ['field' => $field, 'reason' => 'invalid_enum'];
                    continue 2;
                }
                $clean[$field] = $value;
                break;
            case 'money':
                $m = wpultra_woo_coerce_money($value);
                if ($m === null && $value !== '' && $value !== null) {
                    $rejected[] = ['field' => $field, 'reason' => 'invalid_type'];
                    continue 2;
                }
                $clean[$field] = $m;
                break;
            case 'bool':
                $clean[$field] = wpultra_woo_coerce_bool($value);
                break;
            case 'int':
                $clean[$field] = (int) $value;
                break;
            case 'array':
                $clean[$field] = is_array($value) ? array_values($value) : [$value];
                break;
            default:
                $clean[$field] = (string) $value;
        }
    }
    return ['clean' => $clean, 'rejected' => $rejected];
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `& $PHP tests/woo-schema.test.php`
Expected: PASS (all 5 `it` blocks).

- [ ] **Step 5: Lint + commit**

```bash
& $PHP -l wp-ultra-mcp/includes/woocommerce/schema.php
git add wp-ultra-mcp/includes/woocommerce/schema.php tests/woo-schema.test.php
git commit -m "feat(woocommerce): pure product field schema + validate/coerce (+unit tests)"
```

---

### Task 3: `woo-store-status` ability + bootstrap wiring (new `woocommerce` category)

**Files:**
- Create: `wp-ultra-mcp/includes/abilities/woo-store-status.php`
- Modify: `wp-ultra-mcp/includes/bootstrap-mcp.php` (register category, wire engine loop, add slug)
- Modify: `tests/bootstrap.test.php` (count 55 → 56; add `woocommerce` map entry; add a `has woocommerce` assertion)

**Interfaces:**
- Consumes: `wpultra_woo_store_status()` (Task 1), `wpultra_woo_active()`, `wpultra_ok`, `wpultra_err`.
- Produces: registered ability `wpultra/woo-store-status`; the `woocommerce` category + engine require loop other Woo abilities rely on.

- [ ] **Step 1: Write the ability** — `wp-ultra-mcp/includes/abilities/woo-store-status.php`

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/woo-store-status', [
    'label'       => __('WooCommerce: Store Status', 'wp-ultra-mcp'),
    'description' => __('Report whether WooCommerce is active and how the store is configured (version, HPOS, currency, pages, counts).', 'wp-ultra-mcp'),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => ['success' => ['type' => 'boolean'], 'store' => ['type' => 'object']],
        'required'   => ['success'],
    ],
    'execute_callback'    => 'wpultra_woo_store_status_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_woo_store_status_cb(array $input) {
    if (!wpultra_woo_active()) {
        return wpultra_ok(['store' => ['active' => false, 'hint' => 'WooCommerce is not active. Install/activate it (wp plugin install woocommerce --activate).']]);
    }
    return wpultra_ok(['store' => wpultra_woo_store_status()]);
}
```
(Note: store-status returns success even when inactive — it's the *probe*; the other abilities hard-error when inactive.)

- [ ] **Step 2: Register the category** in `wpultra_register_categories()` — add to the `$cats` array (after `'gutenberg' => ...`):

```php
        'woocommerce' => 'WooCommerce store: products, orders, customers, settings.',
```

- [ ] **Step 3: Wire the engine require loop** in `wpultra_load_abilities()` — after the gutenberg `foreach` block, add:

```php
    if (!in_array('woocommerce', $disabled, true)) {
        foreach (['setup', 'schema', 'products'] as $wcf) {
            $wcp = WPULTRA_DIR . 'includes/woocommerce/' . $wcf . '.php';
            if (is_readable($wcp)) { require_once $wcp; }
        }
    }
```
(`products.php` is created in Task 4; `is_readable` guards its absence until then.)

- [ ] **Step 4: Add the slug** to `wpultra_ability_files()` (new group after the blueprints line) AND to `wpultra_ability_category_map()`:

In `wpultra_ability_files()`:
```php
        // woocommerce (Wave 6, Plan 1)
        'woo-store-status',
```
In `wpultra_ability_category_map()` add a new key:
```php
        'woocommerce' => ['woo-store-status'],
```

- [ ] **Step 5: Update `tests/bootstrap.test.php`** — bump the count and add an assertion:
- Line 14: `assert_eq(55, count($files), 'count');` → `assert_eq(56, count($files), 'count');`
- After the gutenberg assertion (line 25), add:
```php
    assert_true(in_array('woo-store-status', $files, true), 'has woocommerce');
```

- [ ] **Step 6: Run the bootstrap test**

Run: `& $PHP tests/bootstrap.test.php`
Expected: PASS (count 56, map covers every file once).

- [ ] **Step 7: Lint, deploy, live-verify**

```bash
& $PHP -l wp-ultra-mcp/includes/abilities/woo-store-status.php
& $PHP -l wp-ultra-mcp/includes/bootstrap-mcp.php
powershell -File wp-ultra-mcp/bin/deploy.ps1
```
Then confirm the ability registers by listing MCP tools (or a token-gated script that does `do_action('abilities_api_init')` is unnecessary — the deploy + an MCP `tools/list` from the client shows `wpultra/woo-store-status`). Expected: tool present; calling it returns `store.active:true`.

- [ ] **Step 8: Commit**

```bash
git add wp-ultra-mcp/includes/abilities/woo-store-status.php wp-ultra-mcp/includes/bootstrap-mcp.php tests/bootstrap.test.php
git commit -m "feat(woocommerce): woo-store-status ability + woocommerce category wiring"
```

---

### Task 4: `products.php` read engine + `woo-list-products` + `woo-get-product`

**Files:**
- Create: `wp-ultra-mcp/includes/woocommerce/products.php` (read functions now; write functions appended in Tasks 5–7)
- Create: `wp-ultra-mcp/includes/abilities/woo-list-products.php`, `wp-ultra-mcp/includes/abilities/woo-get-product.php`
- Modify: `bootstrap-mcp.php` (2 slugs), `tests/bootstrap.test.php` (56 → 58)

**Interfaces:**
- Produces:
  - `wpultra_woo_list_products(array $args): array` — uses `wc_get_products`; returns `['count'=>int, 'products'=>[['id','name','type','status','price','stock','sku'], ...]]`.
  - `wpultra_woo_get_product(int $id): array|WP_Error` — full product detail or `WP_Error('product_not_found')`.
  - `wpultra_woo_product_row(WC_Product $p): array` and `wpultra_woo_product_full(WC_Product $p): array` (shapers).

- [ ] **Step 1: Write `products.php` (read half)**

```php
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
```

- [ ] **Step 2: Lint**

Run: `& $PHP -l wp-ultra-mcp/includes/woocommerce/products.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Write `woo-list-products` ability**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/woo-list-products', [
    'label'       => __('WooCommerce: List Products', 'wp-ultra-mcp'),
    'description' => __('List products with filters (search, status, type, category slug, stock_status, on_sale, page, per_page).', 'wp-ultra-mcp'),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'search'       => ['type' => 'string'],
            'status'       => ['type' => 'string'],
            'type'         => ['type' => 'string'],
            'category'     => ['type' => 'string'],
            'stock_status' => ['type' => 'string'],
            'on_sale'      => ['type' => 'boolean'],
            'page'         => ['type' => 'integer'],
            'per_page'     => ['type' => 'integer'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => ['success' => ['type' => 'boolean'], 'count' => ['type' => 'integer'], 'products' => ['type' => 'array']],
        'required'   => ['success'],
    ],
    'execute_callback'    => 'wpultra_woo_list_products_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_woo_list_products_cb(array $input) {
    if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    $res = wpultra_woo_list_products($input);
    return wpultra_ok($res);
}
```

- [ ] **Step 4: Write `woo-get-product` ability**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/woo-get-product', [
    'label'       => __('WooCommerce: Get Product', 'wp-ultra-mcp'),
    'description' => __('Get one product\'s full detail (prices, stock, attributes, categories, images, variations).', 'wp-ultra-mcp'),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type'       => 'object',
        'properties' => ['product_id' => ['type' => 'integer']],
        'required'   => ['product_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => ['success' => ['type' => 'boolean'], 'product' => ['type' => 'object']],
        'required'   => ['success'],
    ],
    'execute_callback'    => 'wpultra_woo_get_product_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_woo_get_product_cb(array $input) {
    if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    $res = wpultra_woo_get_product((int) ($input['product_id'] ?? 0));
    if (is_wp_error($res)) { return $res; }
    return wpultra_ok(['product' => $res]);
}
```

- [ ] **Step 5: Wire both slugs** — `wpultra_ability_files()` woocommerce group + `wpultra_ability_category_map()['woocommerce']`:
```php
        'woo-store-status', 'woo-list-products', 'woo-get-product',
```
(both files list AND map). Bump `tests/bootstrap.test.php` line 14: `56` → `58`.

- [ ] **Step 6: Run bootstrap test**

Run: `& $PHP tests/bootstrap.test.php`
Expected: PASS (count 58).

- [ ] **Step 7: Deploy + live-verify** (token-gated script `$WP/wp-content/wpultra-wootest.php` that sets admin user, requires setup/schema/products + the helpers, creates a quick simple product via `WC_Product_Simple` if none exist, then echoes `wpultra_woo_list_products([])` and `wpultra_woo_get_product($id)`). Run via curl with the token, confirm JSON has the product row + full detail, then **delete the script**.

```bash
powershell -File wp-ultra-mcp/bin/deploy.ps1
# curl "http://wp-connector.local/wp-content/wpultra-wootest.php?t=wpultra-test-9a88"
```
Expected: list returns ≥1 product; get returns full detail with `regular_price`, `category_ids`, etc.

- [ ] **Step 8: Commit**

```bash
git add wp-ultra-mcp/includes/woocommerce/products.php wp-ultra-mcp/includes/abilities/woo-list-products.php wp-ultra-mcp/includes/abilities/woo-get-product.php wp-ultra-mcp/includes/bootstrap-mcp.php tests/bootstrap.test.php
git commit -m "feat(woocommerce): product read engine + woo-list-products + woo-get-product"
```

---

### Task 5: `woo-upsert-product` (simple + variable/grouped/external)

**Files:**
- Modify: `wp-ultra-mcp/includes/woocommerce/products.php` (append `wpultra_woo_upsert_product`)
- Create: `wp-ultra-mcp/includes/abilities/woo-upsert-product.php`
- Modify: `bootstrap-mcp.php` (1 slug), `tests/bootstrap.test.php` (58 → 59)

**Interfaces:**
- Consumes: `wpultra_woo_validate_product()` (Task 2), `wpultra_woo_get_product()` (Task 4).
- Produces: `wpultra_woo_upsert_product(array $input): array|WP_Error` — creates (no `id`) or updates (with `id`) a product; returns `['id'=>int, 'rejected'=>[...]]`.

- [ ] **Step 1: Append the engine function to `products.php`**

```php
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
```

- [ ] **Step 2: Lint**

Run: `& $PHP -l wp-ultra-mcp/includes/woocommerce/products.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Write `woo-upsert-product` ability**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/woo-upsert-product', [
    'label'       => __('WooCommerce: Upsert Product', 'wp-ultra-mcp'),
    'description' => __('Create or update a product (simple/variable/grouped/external). Pass id to update. Returns rejected fields.', 'wp-ultra-mcp'),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'id'                 => ['type' => 'integer'],
            'name'               => ['type' => 'string'],
            'type'               => ['type' => 'string'],
            'status'             => ['type' => 'string'],
            'slug'               => ['type' => 'string'],
            'description'        => ['type' => 'string'],
            'short_description'  => ['type' => 'string'],
            'sku'                => ['type' => 'string'],
            'regular_price'      => ['type' => 'string'],
            'sale_price'         => ['type' => 'string'],
            'manage_stock'       => ['type' => 'boolean'],
            'stock_quantity'     => ['type' => 'integer'],
            'stock_status'       => ['type' => 'string'],
            'backorders'         => ['type' => 'string'],
            'weight'             => ['type' => 'string'],
            'length'             => ['type' => 'string'],
            'width'              => ['type' => 'string'],
            'height'             => ['type' => 'string'],
            'virtual'            => ['type' => 'boolean'],
            'downloadable'       => ['type' => 'boolean'],
            'featured'           => ['type' => 'boolean'],
            'catalog_visibility' => ['type' => 'string'],
            'category_ids'       => ['type' => 'array'],
            'tag_ids'            => ['type' => 'array'],
            'image_id'           => ['type' => 'integer'],
            'gallery_image_ids'  => ['type' => 'array'],
            'menu_order'         => ['type' => 'integer'],
            'external_url'       => ['type' => 'string'],
            'button_text'        => ['type' => 'string'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => ['success' => ['type' => 'boolean'], 'id' => ['type' => 'integer'], 'rejected' => ['type' => 'array']],
        'required'   => ['success'],
    ],
    'execute_callback'    => 'wpultra_woo_upsert_product_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_woo_upsert_product_cb(array $input) {
    if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    $res = wpultra_woo_upsert_product($input);
    wpultra_audit_log('woo-upsert-product', is_wp_error($res) ? 'failed' : ('product ' . $res['id']), !is_wp_error($res));
    if (is_wp_error($res)) { return $res; }
    return wpultra_ok(['id' => $res['id'], 'rejected' => $res['rejected']]);
}
```

- [ ] **Step 4: Wire slug + bump count** — add `'woo-upsert-product'` to files list + map; `tests/bootstrap.test.php` `58` → `59`.

- [ ] **Step 5: Run bootstrap test**

Run: `& $PHP tests/bootstrap.test.php`
Expected: PASS (count 59).

- [ ] **Step 6: Deploy + live-verify** — token-gated script: create a SIMPLE product (`name`,`regular_price`,`sku`,`manage_stock:true`,`stock_quantity:5`) → assert returns id + empty rejected; update its price by id → re-get and assert new price; create a VARIABLE product (`type:variable`) → assert id + type. Pass an unknown field → assert it appears in `rejected`. Delete the script.
```bash
powershell -File wp-ultra-mcp/bin/deploy.ps1
```
Expected: create/update round-trip works; unknown field rejected; variable product created.

- [ ] **Step 7: Commit**

```bash
git add wp-ultra-mcp/includes/woocommerce/products.php wp-ultra-mcp/includes/abilities/woo-upsert-product.php wp-ultra-mcp/includes/bootstrap-mcp.php tests/bootstrap.test.php
git commit -m "feat(woocommerce): woo-upsert-product (simple/variable/grouped/external, validated)"
```

---

### Task 6: `woo-delete-product` + `woo-manage-variation`

**Files:**
- Modify: `wp-ultra-mcp/includes/woocommerce/products.php` (append delete + variation functions)
- Create: `wp-ultra-mcp/includes/abilities/woo-delete-product.php`, `wp-ultra-mcp/includes/abilities/woo-manage-variation.php`
- Modify: `bootstrap-mcp.php` (2 slugs), `tests/bootstrap.test.php` (59 → 61)

**Interfaces:**
- Produces:
  - `wpultra_woo_delete_product(int $id, bool $force): array|WP_Error` — trash (`force=false`) or permanently delete; returns `['id'=>int,'deleted'=>bool]`.
  - `wpultra_woo_manage_variation(array $input): array|WP_Error` — `action: create|update|delete|list` on a variable product's variations.

- [ ] **Step 1: Append engine functions to `products.php`**

```php
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
    $v->set_parent_id($parentId);
    if (isset($input['attributes']) && is_array($input['attributes'])) { $v->set_attributes($input['attributes']); }
    if (isset($input['regular_price'])) { $v->set_regular_price((string) $input['regular_price']); }
    if (isset($input['sale_price']))    { $v->set_sale_price((string) $input['sale_price']); }
    if (isset($input['sku']))           { $v->set_sku((string) $input['sku']); }
    if (isset($input['manage_stock']))  { $v->set_manage_stock(wpultra_woo_coerce_bool($input['manage_stock'])); }
    if (isset($input['stock_quantity'])) { $v->set_stock_quantity((int) $input['stock_quantity']); }
    if (isset($input['image_id']))      { $v->set_image_id((int) $input['image_id']); }
    $newId = $v->save();
    if (!$newId) { return wpultra_err('variation_save_failed', 'save() returned 0.'); }
    return ['id' => (int) $newId, 'parent_id' => $parentId];
}
```

- [ ] **Step 2: Lint**

Run: `& $PHP -l wp-ultra-mcp/includes/woocommerce/products.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Write `woo-delete-product` ability** (destructive annotation; `force` defaults false → trash)

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/woo-delete-product', [
    'label'       => __('WooCommerce: Delete Product', 'wp-ultra-mcp'),
    'description' => __('Trash a product, or permanently delete it with force:true.', 'wp-ultra-mcp'),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type'       => 'object',
        'properties' => ['product_id' => ['type' => 'integer'], 'force' => ['type' => 'boolean']],
        'required'   => ['product_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => ['success' => ['type' => 'boolean'], 'id' => ['type' => 'integer'], 'deleted' => ['type' => 'boolean']],
        'required'   => ['success'],
    ],
    'execute_callback'    => 'wpultra_woo_delete_product_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_woo_delete_product_cb(array $input) {
    if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    $res = wpultra_woo_delete_product((int) ($input['product_id'] ?? 0), (bool) ($input['force'] ?? false));
    wpultra_audit_log('woo-delete-product', is_wp_error($res) ? 'failed' : ('product ' . $res['id'] . ' force=' . (($input['force'] ?? false) ? '1' : '0')), !is_wp_error($res));
    if (is_wp_error($res)) { return $res; }
    return wpultra_ok($res);
}
```

- [ ] **Step 4: Write `woo-manage-variation` ability**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/woo-manage-variation', [
    'label'       => __('WooCommerce: Manage Variation', 'wp-ultra-mcp'),
    'description' => __('Create/update/delete/list variations of a variable product (attributes, price, stock, sku, image).', 'wp-ultra-mcp'),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'action'         => ['type' => 'string', 'enum' => ['create', 'update', 'delete', 'list']],
            'parent_id'      => ['type' => 'integer'],
            'variation_id'   => ['type' => 'integer'],
            'attributes'     => ['type' => 'object'],
            'regular_price'  => ['type' => 'string'],
            'sale_price'     => ['type' => 'string'],
            'sku'            => ['type' => 'string'],
            'manage_stock'   => ['type' => 'boolean'],
            'stock_quantity' => ['type' => 'integer'],
            'image_id'       => ['type' => 'integer'],
        ],
        'required'   => ['action', 'parent_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => ['success' => ['type' => 'boolean']],
        'required'   => ['success'],
    ],
    'execute_callback'    => 'wpultra_woo_manage_variation_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_woo_manage_variation_cb(array $input) {
    if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    $res = wpultra_woo_manage_variation($input);
    $action = (string) ($input['action'] ?? 'list');
    if (in_array($action, ['create', 'update', 'delete'], true)) {
        wpultra_audit_log('woo-manage-variation', $action . ' on ' . (int) ($input['parent_id'] ?? 0), !is_wp_error($res));
    }
    if (is_wp_error($res)) { return $res; }
    return wpultra_ok($res);
}
```

- [ ] **Step 5: Wire 2 slugs + bump count** — add `'woo-delete-product'`, `'woo-manage-variation'` to files list + map; `tests/bootstrap.test.php` `59` → `61`.

- [ ] **Step 6: Run bootstrap test**

Run: `& $PHP tests/bootstrap.test.php`
Expected: PASS (count 61).

- [ ] **Step 7: Deploy + live-verify** — token-gated script: on the variable product from Task 5, first set a variation-enabled attribute via `set_attributes` on the parent (the script does this with a global or custom attribute), then `create` a variation (attributes + price + stock) → assert id; `list` → assert ≥1; `update` its price → re-list assert; `delete` it → assert deleted. Then trash a throwaway simple product (`force:false`) → assert `deleted:true` and it's in trash; delete the script.
```bash
powershell -File wp-ultra-mcp/bin/deploy.ps1
```
Expected: variation CRUD round-trips; product trash works.

- [ ] **Step 8: Commit**

```bash
git add wp-ultra-mcp/includes/woocommerce/products.php wp-ultra-mcp/includes/abilities/woo-delete-product.php wp-ultra-mcp/includes/abilities/woo-manage-variation.php wp-ultra-mcp/includes/bootstrap-mcp.php tests/bootstrap.test.php
git commit -m "feat(woocommerce): woo-delete-product + woo-manage-variation"
```

---

### Task 7: `woo-manage-product-category` + `woo-manage-attribute`

**Files:**
- Modify: `wp-ultra-mcp/includes/woocommerce/products.php` (append taxonomy + attribute functions)
- Create: `wp-ultra-mcp/includes/abilities/woo-manage-product-category.php`, `wp-ultra-mcp/includes/abilities/woo-manage-attribute.php`
- Modify: `bootstrap-mcp.php` (2 slugs), `tests/bootstrap.test.php` (61 → 63)

**Interfaces:**
- Produces:
  - `wpultra_woo_manage_term(array $input): array|WP_Error` — `action: create|update|delete|list` on `product_cat` or `product_tag`.
  - `wpultra_woo_manage_attribute(array $input): array|WP_Error` — `action: create|update|delete|list` on global attributes (`wc_create_attribute` etc.).

- [ ] **Step 1: Append engine functions to `products.php`**

```php
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
    if (!empty($input['terms']) && is_array($input['terms'])) {
        $tax = wc_attribute_taxonomy_name($payload['slug']);
        if (!taxonomy_exists($tax)) { register_taxonomy($tax, 'product', []); }
        foreach ($input['terms'] as $term) {
            if (!term_exists((string) $term, $tax)) { wp_insert_term((string) $term, $tax); }
        }
    }
    return ['id' => $id, 'slug' => $payload['slug']];
}
```

- [ ] **Step 2: Lint**

Run: `& $PHP -l wp-ultra-mcp/includes/woocommerce/products.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Write `woo-manage-product-category` ability**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/woo-manage-product-category', [
    'label'       => __('WooCommerce: Manage Product Category/Tag', 'wp-ultra-mcp'),
    'description' => __('Create/update/delete/list product categories (or tags via taxonomy:tag).', 'wp-ultra-mcp'),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'action'      => ['type' => 'string', 'enum' => ['create', 'update', 'delete', 'list']],
            'taxonomy'    => ['type' => 'string', 'enum' => ['category', 'tag']],
            'id'          => ['type' => 'integer'],
            'name'        => ['type' => 'string'],
            'slug'        => ['type' => 'string'],
            'parent'      => ['type' => 'integer'],
            'description' => ['type' => 'string'],
        ],
        'required'   => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => ['success' => ['type' => 'boolean']],
        'required'   => ['success'],
    ],
    'execute_callback'    => 'wpultra_woo_manage_term_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_woo_manage_term_cb(array $input) {
    if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    $res = wpultra_woo_manage_term($input);
    $action = (string) ($input['action'] ?? 'list');
    if (in_array($action, ['create', 'update', 'delete'], true)) {
        wpultra_audit_log('woo-manage-product-category', $action, !is_wp_error($res));
    }
    if (is_wp_error($res)) { return $res; }
    return wpultra_ok($res);
}
```

- [ ] **Step 4: Write `woo-manage-attribute` ability**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/woo-manage-attribute', [
    'label'       => __('WooCommerce: Manage Attribute', 'wp-ultra-mcp'),
    'description' => __('Create/update/delete/list global product attributes (pa_*) and seed their terms.', 'wp-ultra-mcp'),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'action' => ['type' => 'string', 'enum' => ['create', 'update', 'delete', 'list']],
            'id'     => ['type' => 'integer'],
            'name'   => ['type' => 'string'],
            'slug'   => ['type' => 'string'],
            'type'   => ['type' => 'string'],
            'terms'  => ['type' => 'array'],
        ],
        'required'   => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => ['success' => ['type' => 'boolean']],
        'required'   => ['success'],
    ],
    'execute_callback'    => 'wpultra_woo_manage_attribute_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_woo_manage_attribute_cb(array $input) {
    if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    $res = wpultra_woo_manage_attribute($input);
    $action = (string) ($input['action'] ?? 'list');
    if (in_array($action, ['create', 'update', 'delete'], true)) {
        wpultra_audit_log('woo-manage-attribute', $action, !is_wp_error($res));
    }
    if (is_wp_error($res)) { return $res; }
    return wpultra_ok($res);
}
```

- [ ] **Step 5: Wire 2 slugs + bump count** — add `'woo-manage-product-category'`, `'woo-manage-attribute'` to files list + map; `tests/bootstrap.test.php` `61` → `63`.

- [ ] **Step 6: Run the full suite**

Run: `powershell -File tests/run-all.ps1`
Expected: `ALL TEST FILES PASSED` (bootstrap count 63, woo-schema green, nothing else regressed).

- [ ] **Step 7: Deploy + live-verify** — token-gated script: create a category (`name:'Shirts'`) → assert id; list → assert present; create a global attribute (`name:'Color'`, `terms:['Red','Blue']`) → assert id + taxonomy `pa_color`; assign the category to the Task-5 product via `woo-upsert-product` `category_ids:[<id>]` → re-get assert; delete the category; delete the script.
```bash
powershell -File wp-ultra-mcp/bin/deploy.ps1
```
Expected: category + attribute CRUD round-trip; product picks up the category.

- [ ] **Step 8: Commit**

```bash
git add wp-ultra-mcp/includes/woocommerce/products.php wp-ultra-mcp/includes/abilities/woo-manage-product-category.php wp-ultra-mcp/includes/abilities/woo-manage-attribute.php wp-ultra-mcp/includes/bootstrap-mcp.php tests/bootstrap.test.php
git commit -m "feat(woocommerce): woo-manage-product-category + woo-manage-attribute"
```

---

## Plan 1 Done — exit criteria

- WooCommerce installed + active on the test site; `woo-store-status` reports it.
- 8 catalog abilities registered under a new `woocommerce` category; `tests/bootstrap.test.php` count = **63**; full suite green.
- Live-verified: create/get/list/update/delete products (simple + variable), variations, categories, attributes — all via the Woo CRUD API, schema-validated.
- **Record verified API facts** (HPOS state, WC version, any setter quirks found live) into project memory + note them for Plan 2 (Orders/Customers).
- Do NOT bump plugin version yet (Plan 4 ships v0.10.0).

## Self-Review notes (done during planning)

- **Spec coverage (Plan 1 slice):** store-status ✓ (Task 3), products list/get/upsert/delete ✓ (Tasks 4–6), variations ✓ (Task 6), categories+tags ✓ (Task 7), attributes ✓ (Task 7), schema-driven validation ✓ (Task 2), Woo-CRUD-API-only ✓ (constraint + engine), graceful-degradation gate ✓ (constraint). Orders/customers/coupons/settings/reports/bridge/skill are **out of Plan 1 scope** — covered by Plans 2–4.
- **Type consistency:** engine return shapes (`['id'=>..,'rejected'=>..]`, `['count'=>..,'products'=>..]`) match their callbacks; `wpultra_woo_coerce_bool/_money` names consistent across schema + variation engine; category count chain 55→56→58→59→61→63 is monotonic and matches the per-task bumps.
- **Placeholders:** none — every step has concrete code/commands. The one judgement call (exact live-test script body in Tasks 4–7 Step 7) is described field-by-field; the implementer writes the throwaway probe following the documented `wpultra-*.php` live-test pattern from project memory.
