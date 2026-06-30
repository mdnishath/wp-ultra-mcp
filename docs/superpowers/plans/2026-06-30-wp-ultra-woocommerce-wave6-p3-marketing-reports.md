# WP-Ultra-MCP — Wave 6 WooCommerce · Plan 3: Marketing/Config + Reports

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`) syntax.
>
> **Plan 3 of the Wave 6 program** (spec: `docs/superpowers/specs/2026-06-30-wp-ultra-woocommerce-wave6-design.md`). Plans 1+2 shipped catalog + orders/customers (branch `feat/woocommerce-wave6`, count 71). Wave 6 ships as **v0.10.0** at Plan 4 — do NOT bump version or release here. Builds on `includes/woocommerce/{setup,schema,products,orders,customers}.php` + the `woocommerce` category.
>
> **VERIFIED LIVE ENV:** WooCommerce 10.9.1, HPOS ON, currency BDT, base country BD. Coupons (`shop_coupon`) and reviews (WP comments) are NOT in HPOS — only orders are; so coupon/review access uses the normal CPT/comment APIs (via `WC_Coupon` and the WP comment API), and **Reports that touch orders MUST use `wc_get_orders` (HPOS-safe), never `get_posts('shop_order')`**.

**Goal:** 5 abilities — `woo-manage-coupon`, `woo-get-settings`, `woo-update-settings`, `woo-manage-review`, `woo-get-reports` — for store marketing, configuration, and analytics.

**Architecture:** Three new engine files mirror the existing ones: `includes/woocommerce/coupons.php` (`WC_Coupon` CRUD), `includes/woocommerce/settings.php` (read general/payment/shipping/tax config + whitelisted updates), `includes/woocommerce/reports.php` (sales/top-products/revenue/low-stock via the CRUD layer). Reviews use the WP comment API inside a small `wpultra_woo_manage_review` in `settings.php` (or its own tiny file — see Task 3). Five thin abilities gate on `wpultra_woo_active()` + audit writes.

**Tech Stack:** PHP 8.0+, WP 7.0, WooCommerce 10.9.1 (`WC_Coupon`, `WC()->payment_gateways()`, `WC_Shipping_Zones`, `wc_get_orders`, the WP comment API), vendored mcp-adapter, Abilities API. No new dependencies.

## Global Constraints

- Every PHP file: `<?php` + `declare(strict_types=1);` + `if (!defined('ABSPATH')) { exit(); }`.
- Engine returns arrays/values or `WP_Error` via `wpultra_err`. Abilities return `wpultra_ok([...])` or the `WP_Error`.
- **Ability registration shape** — copy `wp-ultra-mcp/includes/abilities/woo-upsert-product.php` (write) / `woo-get-product.php` (read): named string `execute_callback`, `properties` a PLAIN ARRAY, `permission_callback=>'wpultra_permission_callback'`, `meta=>['show_in_rest'=>true,'mcp'=>['public'=>true,'type'=>'tool'],'annotations'=>[...]]`.
- Read abilities (`woo-get-settings`, `woo-get-reports`): `['readonly'=>true,'destructive'=>false,'idempotent'=>true]`, NO audit. Write abilities (`woo-manage-coupon`, `woo-update-settings`, `woo-manage-review`): `['readonly'=>false,'destructive'=>false,'idempotent'=>false]` + `wpultra_audit_log('<slug>',<summary>,$ok)` after a create/update/delete (NOT on `list`/`get`/`read` sub-actions). `woo-manage-coupon` delete and `woo-manage-review` delete keep `destructive=>false` at the ability level (they are reversible trash by default).
- **Every callback gates on Woo first:** `if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive','WooCommerce is not active.'); }`.
- **HPOS:** any order access (Reports) ONLY via `wc_get_orders`. Coupons via `WC_Coupon` + `get_posts(['post_type'=>'shop_coupon'])` (coupons are a normal CPT, NOT HPOS — this is allowed). Reviews via the WP comment API (`get_comments`, `wp_insert_comment`, `wp_set_comment_status`, `wp_delete_comment`). NEVER `get_posts('shop_order')`/`get_post_meta` on an order.
- **`woo-update-settings` writes WHITELISTED option keys ONLY** (a fixed allowlist in the engine) — it must never `update_option` an arbitrary key. Unknown keys → reported in a `rejected` list, not written.
- **Bootstrap wiring** (`bootstrap-mcp.php`): add the 5 slugs to the `// woocommerce` group in `wpultra_ability_files()` AND `wpultra_ability_category_map()['woocommerce']`; add `coupons,settings,reports` to the woocommerce engine require loop (`['setup','schema','products','orders','customers']` → `+ 'coupons','settings','reports'`). `tests/bootstrap.test.php` count `71` → `76`; keep files↔map in sync.
- Bundled PHP: `$PHP = C:/Users/nisha/AppData/Roaming/Local/lightning-services/php-8.2.30+1/bin/win64/php.exe`. Test site root: `C:/Users/nisha/Local Sites/wp-connector/app/public`. Live token: `wpultra-test-9a88`.
- **Re-run `wp-ultra-mcp/bin/deploy.ps1` after every commit.** Live-test probes go in the site webroot, `require dirname(__DIR__).'/wp-load.php'`, token-gate, `wp_set_current_user(<admin id>)`, **require BOTH the engine files AND the ability files**, call `*_cb`, echo JSON, then DELETE the script + clean up any created coupons/reviews. (Gotcha from Plan 2: deleting a *customer* needs `wp-admin/includes/user.php`; deleting a *comment/coupon* does NOT.)
- **Harness:** `it`, `assert_eq`, `assert_true`, `assert_wp_error`; ends `run_tests();`. Pure tests (settings whitelist + report aggregation helper) stub nothing Woo. Engine files reference Woo/WP only inside bodies.
- Commit messages: `feat(woocommerce): …`; end body with `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.

## File Structure

```
wp-ultra-mcp/includes/
  woocommerce/
    coupons.php   NEW — WC_Coupon CRUD (Task 1)
    settings.php  NEW — store config read + whitelisted update + review management (Tasks 2,3)
    reports.php   NEW — sales/top-products/revenue/low-stock (Task 4)
  abilities/
    woo-manage-coupon.php    NEW (Task 1)
    woo-get-settings.php     NEW (Task 2)
    woo-update-settings.php  NEW (Task 2)
    woo-manage-review.php    NEW (Task 3)
    woo-get-reports.php      NEW (Task 4)
  bootstrap-mcp.php   MODIFY — engine loop + 5 slugs (Tasks 1–4)
tests/
  woo-settings.test.php   NEW — pure whitelist + report-bucket unit tests (Tasks 2,4)
  bootstrap.test.php      MODIFY — count 71 → 76
```

---

### Task 1: `woo-manage-coupon`

**Files:**
- Create: `wp-ultra-mcp/includes/woocommerce/coupons.php`, `wp-ultra-mcp/includes/abilities/woo-manage-coupon.php`
- Modify: `bootstrap-mcp.php` (engine loop + 1 slug), `tests/bootstrap.test.php` (71 → 72)

**Interfaces:**
- Produces: `wpultra_woo_manage_coupon(array $input): array|WP_Error` — `action: list|get|create|update|delete`. create/update set code, discount_type, amount, description, free_shipping, expiry, min/max spend, product/category include/exclude, usage limits. Returns `['id','code']` (create/update), `['id','deleted']` (delete), full record (get), `['count','coupons']` (list).

- [ ] **Step 1: Write `coupons.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

function wpultra_woo_coupon_full(WC_Coupon $c): array {
    return [
        'id'            => $c->get_id(),
        'code'          => $c->get_code(),
        'discount_type' => $c->get_discount_type(),
        'amount'        => $c->get_amount(),
        'description'   => $c->get_description(),
        'free_shipping' => $c->get_free_shipping(),
        'date_expires'  => $c->get_date_expires() ? $c->get_date_expires()->date('Y-m-d') : null,
        'minimum_amount' => $c->get_minimum_amount(),
        'maximum_amount' => $c->get_maximum_amount(),
        'usage_limit'   => $c->get_usage_limit(),
        'usage_count'   => $c->get_usage_count(),
        'product_ids'   => $c->get_product_ids(),
        'excluded_product_ids' => $c->get_excluded_product_ids(),
        'individual_use' => $c->get_individual_use(),
    ];
}

function wpultra_woo_manage_coupon(array $input) {
    $action = (string) ($input['action'] ?? 'list');

    if ($action === 'list') {
        $ids = get_posts(['post_type' => 'shop_coupon', 'post_status' => 'publish', 'numberposts' => (int) ($input['per_page'] ?? 50), 'fields' => 'ids']);
        $rows = [];
        foreach ($ids as $pid) { $c = new WC_Coupon((int) $pid); $rows[] = ['id' => $c->get_id(), 'code' => $c->get_code(), 'discount_type' => $c->get_discount_type(), 'amount' => $c->get_amount()]; }
        return ['count' => count($rows), 'coupons' => $rows];
    }

    $idOrCode = $input['id'] ?? ($input['code'] ?? '');
    if ($action === 'get') {
        $c = new WC_Coupon($idOrCode);
        if (!$c->get_id()) { return wpultra_err('coupon_not_found', 'No such coupon.'); }
        return wpultra_woo_coupon_full($c);
    }
    if ($action === 'delete') {
        $c = new WC_Coupon($idOrCode);
        if (!$c->get_id()) { return wpultra_err('coupon_not_found', 'No such coupon.'); }
        $cid = $c->get_id();
        $c->delete(!empty($input['force']));
        return ['id' => $cid, 'deleted' => true];
    }

    // create or update
    $c = ($action === 'update') ? new WC_Coupon($idOrCode) : new WC_Coupon();
    if ($action === 'update' && !$c->get_id()) { return wpultra_err('coupon_not_found', 'No such coupon to update.'); }
    if ($action === 'create') {
        $code = (string) ($input['code'] ?? '');
        if ($code === '') { return wpultra_err('code_required', 'Creating a coupon requires a code.'); }
        $c->set_code($code);
    }
    if (isset($input['discount_type'])) { $c->set_discount_type((string) $input['discount_type']); }
    if (isset($input['amount']))        { $c->set_amount((string) $input['amount']); }
    if (isset($input['description']))   { $c->set_description((string) $input['description']); }
    if (isset($input['free_shipping'])) { $c->set_free_shipping((bool) $input['free_shipping']); }
    if (isset($input['date_expires']))  { $c->set_date_expires((string) $input['date_expires']); }
    if (isset($input['minimum_amount'])) { $c->set_minimum_amount((string) $input['minimum_amount']); }
    if (isset($input['maximum_amount'])) { $c->set_maximum_amount((string) $input['maximum_amount']); }
    if (isset($input['usage_limit']))   { $c->set_usage_limit((int) $input['usage_limit']); }
    if (isset($input['individual_use'])) { $c->set_individual_use((bool) $input['individual_use']); }
    if (isset($input['product_ids']) && is_array($input['product_ids'])) { $c->set_product_ids(array_map('intval', $input['product_ids'])); }
    if (isset($input['excluded_product_ids']) && is_array($input['excluded_product_ids'])) { $c->set_excluded_product_ids(array_map('intval', $input['excluded_product_ids'])); }
    $id = $c->save();
    if (!$id) { return wpultra_err('coupon_save_failed', 'save() returned 0.'); }
    return ['id' => (int) $id, 'code' => $c->get_code()];
}
```

- [ ] **Step 2: Lint** — `& $PHP -l wp-ultra-mcp/includes/woocommerce/coupons.php`.

- [ ] **Step 3: Write `woo-manage-coupon` ability**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/woo-manage-coupon', [
    'label'       => __('WooCommerce: Manage Coupon', 'wp-ultra-mcp'),
    'description' => __('Create/update/get/delete/list coupons: code, discount_type (fixed_cart|percent|fixed_product), amount, free_shipping, date_expires, min/max amount, usage_limit, product include/exclude.', 'wp-ultra-mcp'),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'action'        => ['type' => 'string', 'enum' => ['list', 'get', 'create', 'update', 'delete']],
            'id'            => ['type' => 'integer'],
            'code'          => ['type' => 'string'],
            'discount_type' => ['type' => 'string'],
            'amount'        => ['type' => 'string'],
            'description'   => ['type' => 'string'],
            'free_shipping' => ['type' => 'boolean'],
            'date_expires'  => ['type' => 'string'],
            'minimum_amount' => ['type' => 'string'],
            'maximum_amount' => ['type' => 'string'],
            'usage_limit'   => ['type' => 'integer'],
            'individual_use' => ['type' => 'boolean'],
            'product_ids'   => ['type' => 'array'],
            'excluded_product_ids' => ['type' => 'array'],
            'force'         => ['type' => 'boolean'],
            'per_page'      => ['type' => 'integer'],
        ],
        'required'   => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_woo_manage_coupon_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

function wpultra_woo_manage_coupon_cb(array $input) {
    if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    $res = wpultra_woo_manage_coupon($input);
    $action = (string) ($input['action'] ?? 'list');
    if (in_array($action, ['create', 'update', 'delete'], true)) {
        wpultra_audit_log('woo-manage-coupon', $action . (is_wp_error($res) ? ' failed' : ''), !is_wp_error($res));
    }
    if (is_wp_error($res)) { return $res; }
    return wpultra_ok($res);
}
```

- [ ] **Step 4: Wire engine loop + slug + bump count** — add `'coupons','settings','reports'` to the woocommerce engine require loop (settings/reports created in Tasks 2/4; `is_readable` guards their absence); add `'woo-manage-coupon'` to files + map; `tests/bootstrap.test.php` `71` → `72`.

- [ ] **Step 5: Run bootstrap test** — PASS (count 72). Lint the ability file.

- [ ] **Step 6: Deploy + live-verify** — probe (require engine + ability files): `create` (`code:'CLAUDE10', discount_type:'percent', amount:'10'`) → assert id + code; `get` by code → assert discount_type percent; `list` → assert present; `update` amount to '15' → re-get assert; `delete` → assert deleted. Create with no code → `code_required`. Force-delete the coupon if still present + delete the script.

- [ ] **Step 7: Commit**

```bash
git add wp-ultra-mcp/includes/woocommerce/coupons.php wp-ultra-mcp/includes/abilities/woo-manage-coupon.php wp-ultra-mcp/includes/bootstrap-mcp.php tests/bootstrap.test.php
git commit -m "feat(woocommerce): woo-manage-coupon (WC_Coupon CRUD)"
```

---

### Task 2: `settings.php` + `woo-get-settings` + `woo-update-settings`

**Files:**
- Create: `wp-ultra-mcp/includes/woocommerce/settings.php` (read + whitelisted update; review management appended Task 3)
- Create: `wp-ultra-mcp/includes/abilities/woo-get-settings.php`, `woo-update-settings.php`
- Create: `tests/woo-settings.test.php` (pure whitelist test)
- Modify: `bootstrap-mcp.php` (2 slugs), `tests/bootstrap.test.php` (72 → 74)

**Interfaces:**
- Produces:
  - `wpultra_woo_settings_whitelist(): array` — the allowed `woocommerce_*` option keys for update.
  - `wpultra_woo_get_settings(): array` — general (currency, country, units, selling/shipping locations), payment gateways (`[{id,enabled,title}]`), shipping zones (`[{id,name,methods}]`), tax (enabled, prices-include-tax).
  - `wpultra_woo_update_settings(array $input): array` — `['updated'=>[k=>v], 'rejected'=>[{key,reason}]]`. `options` (assoc of whitelisted keys), `gateway` (`{id, enabled:bool}` → toggle gateway via its settings option). Unknown option keys → rejected `not_whitelisted`.

- [ ] **Step 1: Write the failing whitelist test** — `tests/woo-settings.test.php`

```php
<?php
require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../wp-ultra-mcp/includes/woocommerce/settings.php';

it('whitelist contains core keys and excludes arbitrary', function () {
    $wl = wpultra_woo_settings_whitelist();
    assert_true(in_array('woocommerce_currency', $wl, true));
    assert_true(in_array('woocommerce_weight_unit', $wl, true));
    assert_true(!in_array('siteurl', $wl, true));
    assert_true(!in_array('admin_email', $wl, true));
});

run_tests();
```

- [ ] **Step 2: Run → fail** — `& $PHP tests/woo-settings.test.php` → FAIL (`wpultra_woo_settings_whitelist` undefined).

- [ ] **Step 3: Write `settings.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

function wpultra_woo_settings_whitelist(): array {
    return [
        'woocommerce_currency',
        'woocommerce_currency_pos',
        'woocommerce_price_thousand_sep',
        'woocommerce_price_decimal_sep',
        'woocommerce_price_num_decimals',
        'woocommerce_default_country',
        'woocommerce_weight_unit',
        'woocommerce_dimension_unit',
        'woocommerce_allowed_countries',
        'woocommerce_ship_to_countries',
        'woocommerce_calc_taxes',
        'woocommerce_prices_include_tax',
        'woocommerce_enable_coupons',
        'woocommerce_store_address',
        'woocommerce_store_city',
        'woocommerce_store_postcode',
    ];
}

function wpultra_woo_get_settings(): array {
    $gateways = [];
    if (function_exists('WC') && WC()->payment_gateways()) {
        foreach (WC()->payment_gateways()->payment_gateways() as $gw) {
            $gateways[] = ['id' => $gw->id, 'enabled' => ($gw->enabled === 'yes'), 'title' => $gw->get_title()];
        }
    }
    $zones = [];
    if (class_exists('WC_Shipping_Zones')) {
        foreach (WC_Shipping_Zones::get_zones() as $z) {
            $methods = [];
            foreach (($z['shipping_methods'] ?? []) as $m) { $methods[] = ['id' => $m->id, 'title' => $m->get_title(), 'enabled' => ($m->is_enabled())]; }
            $zones[] = ['id' => $z['zone_id'], 'name' => $z['zone_name'], 'methods' => $methods];
        }
    }
    return [
        'general' => [
            'currency'        => get_option('woocommerce_currency'),
            'currency_pos'    => get_option('woocommerce_currency_pos'),
            'default_country' => get_option('woocommerce_default_country'),
            'weight_unit'     => get_option('woocommerce_weight_unit'),
            'dimension_unit'  => get_option('woocommerce_dimension_unit'),
            'coupons_enabled' => get_option('woocommerce_enable_coupons'),
        ],
        'tax' => [
            'calc_taxes'         => get_option('woocommerce_calc_taxes'),
            'prices_include_tax' => get_option('woocommerce_prices_include_tax'),
        ],
        'payment_gateways' => $gateways,
        'shipping_zones'   => $zones,
    ];
}

function wpultra_woo_update_settings(array $input) {
    $updated = [];
    $rejected = [];
    $whitelist = wpultra_woo_settings_whitelist();

    if (!empty($input['options']) && is_array($input['options'])) {
        foreach ($input['options'] as $key => $val) {
            if (!in_array($key, $whitelist, true)) { $rejected[] = ['key' => $key, 'reason' => 'not_whitelisted']; continue; }
            update_option($key, $val);
            $updated[$key] = $val;
        }
    }

    if (!empty($input['gateway']) && is_array($input['gateway'])) {
        $gid = (string) ($input['gateway']['id'] ?? '');
        $enabled = !empty($input['gateway']['enabled']);
        if ($gid !== '' && function_exists('WC') && WC()->payment_gateways()) {
            $all = WC()->payment_gateways()->payment_gateways();
            if (isset($all[$gid])) {
                $opt = 'woocommerce_' . $gid . '_settings';
                $s = get_option($opt, []);
                if (!is_array($s)) { $s = []; }
                $s['enabled'] = $enabled ? 'yes' : 'no';
                update_option($opt, $s);
                $updated['gateway:' . $gid] = $enabled ? 'enabled' : 'disabled';
            } else {
                $rejected[] = ['key' => 'gateway:' . $gid, 'reason' => 'gateway_not_found'];
            }
        }
    }
    return ['updated' => $updated, 'rejected' => $rejected];
}
```

- [ ] **Step 4: Run → pass** — `& $PHP tests/woo-settings.test.php` → PASS. Lint `settings.php`.

- [ ] **Step 5: Write `woo-get-settings` ability** (read-only)

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/woo-get-settings', [
    'label'       => __('WooCommerce: Get Settings', 'wp-ultra-mcp'),
    'description' => __('Read store settings: general (currency/country/units), payment gateways, shipping zones+methods, tax options.', 'wp-ultra-mcp'),
    'category'    => 'woocommerce',
    'input_schema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean'], 'settings' => ['type' => 'object']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_woo_get_settings_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

function wpultra_woo_get_settings_cb(array $input) {
    if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    return wpultra_ok(['settings' => wpultra_woo_get_settings()]);
}
```

- [ ] **Step 6: Write `woo-update-settings` ability** (write + audit)

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/woo-update-settings', [
    'label'       => __('WooCommerce: Update Settings', 'wp-ultra-mcp'),
    'description' => __('Update WHITELISTED store options (currency, country, units, tax/coupon toggles, store address) and enable/disable a payment gateway. Non-whitelisted keys are rejected.', 'wp-ultra-mcp'),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type'       => 'object',
        'properties' => ['options' => ['type' => 'object'], 'gateway' => ['type' => 'object']],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean'], 'updated' => ['type' => 'object'], 'rejected' => ['type' => 'array']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_woo_update_settings_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

function wpultra_woo_update_settings_cb(array $input) {
    if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    $res = wpultra_woo_update_settings($input);
    wpultra_audit_log('woo-update-settings', 'updated ' . count($res['updated']) . ' rejected ' . count($res['rejected']), true);
    return wpultra_ok($res);
}
```

- [ ] **Step 7: Wire 2 slugs + bump count** — add `'woo-get-settings','woo-update-settings'` to files + map; `tests/bootstrap.test.php` `72` → `74`.

- [ ] **Step 8: Run tests** — `& $PHP tests/woo-settings.test.php` + `& $PHP tests/bootstrap.test.php` (count 74). Lint all changed files.

- [ ] **Step 9: Deploy + live-verify** — probe: `woo-get-settings` → assert `general.currency` is `BDT` + a non-empty `payment_gateways` array. `woo-update-settings` with `{options:{woocommerce_weight_unit:'g'}}` → assert `updated` has it; re-get → assert weight_unit `g`; then set it back to its original. `{options:{siteurl:'http://evil'}}` → assert `siteurl` in `rejected` as `not_whitelisted` AND confirm `get_option('siteurl')` is UNCHANGED. Toggle a gateway off then on. Delete the script.

- [ ] **Step 10: Commit**

```bash
git add wp-ultra-mcp/includes/woocommerce/settings.php wp-ultra-mcp/includes/abilities/woo-get-settings.php wp-ultra-mcp/includes/abilities/woo-update-settings.php wp-ultra-mcp/includes/bootstrap-mcp.php tests/woo-settings.test.php tests/bootstrap.test.php
git commit -m "feat(woocommerce): woo-get-settings + woo-update-settings (whitelisted)"
```

---

### Task 3: `woo-manage-review`

**Files:**
- Modify: `wp-ultra-mcp/includes/woocommerce/settings.php` (append `wpultra_woo_manage_review`)
- Create: `wp-ultra-mcp/includes/abilities/woo-manage-review.php`
- Modify: `bootstrap-mcp.php` (1 slug), `tests/bootstrap.test.php` (74 → 75)

**Interfaces:**
- Produces: `wpultra_woo_manage_review(array $input): array|WP_Error` — `action: list|approve|unapprove|spam|trash|delete|create`. Reviews are WP comments on `product` posts. `list` filters by `product_id?`/`status?`. `create` needs `product_id`, `content`, `author`, `email`, optional `rating` (1–5, stored as comment_meta). status changes via `wp_set_comment_status`; delete via `wp_delete_comment`.

- [ ] **Step 1: Append `wpultra_woo_manage_review` to `settings.php`**

```php
function wpultra_woo_manage_review(array $input) {
    $action = (string) ($input['action'] ?? 'list');

    if ($action === 'list') {
        $args = ['type' => 'review', 'number' => (int) ($input['per_page'] ?? 50)];
        if (!empty($input['product_id'])) { $args['post_id'] = (int) $input['product_id']; }
        if (!empty($input['status']))     { $args['status'] = (string) $input['status']; }
        $comments = get_comments($args);
        $rows = [];
        foreach ($comments as $cm) {
            $rows[] = ['id' => (int) $cm->comment_ID, 'product_id' => (int) $cm->comment_post_ID, 'author' => $cm->comment_author, 'content' => $cm->comment_content, 'rating' => (int) get_comment_meta($cm->comment_ID, 'rating', true), 'approved' => ($cm->comment_approved === '1')];
        }
        return ['count' => count($rows), 'reviews' => $rows];
    }

    if ($action === 'create') {
        $pid = (int) ($input['product_id'] ?? 0);
        if (!$pid || get_post_type($pid) !== 'product') { return wpultra_err('invalid_product', 'create review requires a valid product_id.'); }
        $cid = wp_insert_comment([
            'comment_post_ID'  => $pid,
            'comment_author'   => (string) ($input['author'] ?? 'Guest'),
            'comment_author_email' => (string) ($input['email'] ?? ''),
            'comment_content'  => (string) ($input['content'] ?? ''),
            'comment_type'     => 'review',
            'comment_approved' => 1,
        ]);
        if (!$cid) { return wpultra_err('review_create_failed', 'wp_insert_comment returned 0.'); }
        if (isset($input['rating'])) { update_comment_meta($cid, 'rating', max(1, min(5, (int) $input['rating']))); }
        return ['id' => (int) $cid, 'product_id' => $pid];
    }

    $id = (int) ($input['id'] ?? 0);
    if (!$id || !get_comment($id)) { return wpultra_err('review_not_found', "No review with id $id."); }
    if ($action === 'delete') { wp_delete_comment($id, !empty($input['force'])); return ['id' => $id, 'deleted' => true]; }
    $map = ['approve' => 'approve', 'unapprove' => 'hold', 'spam' => 'spam', 'trash' => 'trash'];
    if (!isset($map[$action])) { return wpultra_err('bad_action', "Unknown review action '$action'."); }
    wp_set_comment_status($id, $map[$action]);
    return ['id' => $id, 'status' => $map[$action]];
}
```

- [ ] **Step 2: Lint** — `& $PHP -l wp-ultra-mcp/includes/woocommerce/settings.php`.

- [ ] **Step 3: Write `woo-manage-review` ability**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/woo-manage-review', [
    'label'       => __('WooCommerce: Manage Review', 'wp-ultra-mcp'),
    'description' => __('List/create/approve/unapprove/spam/trash/delete product reviews. create needs product_id, content, author, email, optional rating 1-5.', 'wp-ultra-mcp'),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'action'     => ['type' => 'string', 'enum' => ['list', 'create', 'approve', 'unapprove', 'spam', 'trash', 'delete']],
            'id'         => ['type' => 'integer'],
            'product_id' => ['type' => 'integer'],
            'status'     => ['type' => 'string'],
            'author'     => ['type' => 'string'],
            'email'      => ['type' => 'string'],
            'content'    => ['type' => 'string'],
            'rating'     => ['type' => 'integer'],
            'force'      => ['type' => 'boolean'],
            'per_page'   => ['type' => 'integer'],
        ],
        'required'   => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_woo_manage_review_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

function wpultra_woo_manage_review_cb(array $input) {
    if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    $res = wpultra_woo_manage_review($input);
    $action = (string) ($input['action'] ?? 'list');
    if ($action !== 'list') {
        wpultra_audit_log('woo-manage-review', $action . (is_wp_error($res) ? ' failed' : ''), !is_wp_error($res));
    }
    if (is_wp_error($res)) { return $res; }
    return wpultra_ok($res);
}
```

- [ ] **Step 4: Wire slug + bump count** — add `'woo-manage-review'`; `tests/bootstrap.test.php` `74` → `75`.

- [ ] **Step 5: Run bootstrap test** — PASS (count 75). Lint the ability file.

- [ ] **Step 6: Deploy + live-verify** — probe: `create` a review (`product_id:99, content:'Great', author:'Tester', email:'t@x.com', rating:5`) → assert id; `list` (`product_id:99`) → assert ≥1 with rating 5; `unapprove` it → `list status:'hold'` assert present; `approve`; `delete` (force:true) → assert deleted. Bad product → `invalid_product`. Delete the script (reviews are removed by the delete step).

- [ ] **Step 7: Commit**

```bash
git add wp-ultra-mcp/includes/woocommerce/settings.php wp-ultra-mcp/includes/abilities/woo-manage-review.php wp-ultra-mcp/includes/bootstrap-mcp.php tests/bootstrap.test.php
git commit -m "feat(woocommerce): woo-manage-review (product reviews via comment API)"
```

---

### Task 4: `reports.php` + `woo-get-reports`

**Files:**
- Create: `wp-ultra-mcp/includes/woocommerce/reports.php`, `wp-ultra-mcp/includes/abilities/woo-get-reports.php`
- Modify: `tests/woo-settings.test.php` (add a pure report-bucket test), `bootstrap-mcp.php` (1 slug), `tests/bootstrap.test.php` (75 → 76)

**Interfaces:**
- Produces:
  - `wpultra_woo_report_money(array $orders): array` — pure helper: given rows of `['total'=>..]` returns `['order_count','gross']` (unit-testable with plain arrays).
  - `wpultra_woo_get_reports(array $input): array` — `type: sales|top_products|revenue|low_stock`. sales/revenue use `wc_get_orders` over an optional `date_from`/`date_to` (HPOS-safe); top_products aggregates line items; low_stock uses `wc_get_products(['stock_status'=>'outofstock'])` + managed low-stock.

- [ ] **Step 1: Write the failing report-bucket test** — append to `tests/woo-settings.test.php` (before `run_tests();`)

```php
it('report money sums totals', function () {
    $r = wpultra_woo_report_money([['total' => '10.00'], ['total' => '5.50'], ['total' => '4.50']]);
    assert_eq(3, $r['order_count']);
    assert_eq('20', (string) (0 + $r['gross']));
});
```
(This requires `reports.php` to be required at the top of `woo-settings.test.php` — add `require_once __DIR__ . '/../wp-ultra-mcp/includes/woocommerce/reports.php';` alongside the settings require.)

- [ ] **Step 2: Run → fail** — `& $PHP tests/woo-settings.test.php` → FAIL (`wpultra_woo_report_money` undefined).

- [ ] **Step 3: Write `reports.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/** Pure: sum order totals. $orders is an array of rows each with a 'total'. */
function wpultra_woo_report_money(array $orders): array {
    $gross = 0.0;
    foreach ($orders as $o) { $gross += (float) ($o['total'] ?? 0); }
    return ['order_count' => count($orders), 'gross' => $gross];
}

function wpultra_woo_get_reports(array $input): array {
    $type = (string) ($input['type'] ?? 'sales');
    $statuses = ['wc-processing', 'wc-completed', 'wc-on-hold'];
    $q = ['limit' => -1, 'return' => 'objects', 'status' => $statuses];
    if (!empty($input['date_from'])) { $q['date_created'] = '>=' . $input['date_from']; }
    if (!empty($input['date_to']))   { $q['date_created'] = '<=' . $input['date_to']; }

    if ($type === 'low_stock') {
        $out = wc_get_products(['limit' => -1, 'stock_status' => 'outofstock', 'return' => 'objects']);
        $rows = [];
        foreach ($out as $p) { $rows[] = ['id' => $p->get_id(), 'name' => $p->get_name(), 'stock' => $p->get_stock_quantity()]; }
        return ['type' => 'low_stock', 'count' => count($rows), 'products' => $rows];
    }

    $orders = wc_get_orders($q);
    if ($type === 'top_products') {
        $tally = [];
        foreach ($orders as $o) {
            foreach ($o->get_items() as $item) {
                $pid = $item->get_product_id();
                if (!isset($tally[$pid])) { $tally[$pid] = ['product_id' => $pid, 'name' => $item->get_name(), 'qty' => 0, 'revenue' => 0.0]; }
                $tally[$pid]['qty'] += $item->get_quantity();
                $tally[$pid]['revenue'] += (float) $item->get_total();
            }
        }
        usort($tally, function ($a, $b) { return $b['qty'] <=> $a['qty']; });
        return ['type' => 'top_products', 'products' => array_slice(array_values($tally), 0, 10)];
    }

    // sales / revenue
    $rows = [];
    foreach ($orders as $o) { $rows[] = ['total' => $o->get_total()]; }
    $m = wpultra_woo_report_money($rows);
    return ['type' => $type, 'order_count' => $m['order_count'], 'gross' => round($m['gross'], 2), 'currency' => get_woocommerce_currency()];
}
```

- [ ] **Step 4: Run → pass** — `& $PHP tests/woo-settings.test.php` → PASS (now 3 `it` blocks). Lint `reports.php`.

- [ ] **Step 5: Write `woo-get-reports` ability** (read-only)

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/woo-get-reports', [
    'label'       => __('WooCommerce: Get Reports', 'wp-ultra-mcp'),
    'description' => __('Sales analytics: type sales|revenue (order_count+gross over optional date_from/date_to), top_products (by qty), low_stock (out-of-stock list). HPOS-safe.', 'wp-ultra-mcp'),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'type'      => ['type' => 'string', 'enum' => ['sales', 'revenue', 'top_products', 'low_stock']],
            'date_from' => ['type' => 'string'],
            'date_to'   => ['type' => 'string'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean'], 'report' => ['type' => 'object']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_woo_get_reports_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

function wpultra_woo_get_reports_cb(array $input) {
    if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    return wpultra_ok(['report' => wpultra_woo_get_reports($input)]);
}
```

- [ ] **Step 6: Wire slug + bump count** — add `'woo-get-reports'`; `tests/bootstrap.test.php` `75` → `76`.

- [ ] **Step 7: Run the FULL suite** — `powershell -File tests/run-all.ps1` → `ALL TEST FILES PASSED` (bootstrap 76, woo-schema 7, woo-settings 3, nothing regressed). Lint the ability file.

- [ ] **Step 8: Deploy + live-verify** — probe: create 2 quick orders (product 99), then `woo-get-reports type:'sales'` → assert `order_count`≥2 + `gross`>0 + `currency:'BDT'`; `type:'top_products'` → assert product 99 present with a qty; `type:'low_stock'` → assert it returns a products array (may be empty). Force-delete the test orders + delete the script.

- [ ] **Step 9: Commit**

```bash
git add wp-ultra-mcp/includes/woocommerce/reports.php wp-ultra-mcp/includes/abilities/woo-get-reports.php wp-ultra-mcp/includes/bootstrap-mcp.php tests/woo-settings.test.php tests/bootstrap.test.php
git commit -m "feat(woocommerce): woo-get-reports (sales/top-products/low-stock, HPOS-safe)"
```

---

## Plan 3 Done — exit criteria

- 5 abilities under `woocommerce`; `tests/bootstrap.test.php` count = **76**; full suite green.
- Live-verified: coupon CRUD; settings read + whitelisted update (non-whitelisted key rejected AND not written — confirm `siteurl` unchanged); review create→moderate→delete; reports sales/top-products/low-stock (orders aggregated HPOS-safely).
- Record live quirks (gateway settings option shape, review comment_type) into the SDD ledger for Plan 4.
- Do NOT bump plugin version (Plan 4 ships v0.10.0).

## Self-Review notes (done during planning)

- **Spec coverage (Plan 3 slice):** coupons CRUD ✓ (Task 1), settings read+update ✓ (Task 2, whitelisted), reviews ✓ (Task 3), reports ✓ (Task 4). Bridge + skill remain Plan 4.
- **Security:** `woo-update-settings` writes ONLY whitelisted keys (pure `wpultra_woo_settings_whitelist`, unit-tested to exclude `siteurl`/`admin_email`); non-whitelisted → `rejected`, never written. Gateway toggle bounded to registered gateway ids.
- **HPOS:** Reports order access uses `wc_get_orders` only. Coupons (`shop_coupon`) and reviews (comments) are NOT HPOS entities — normal CPT/comment APIs are correct and allowed there.
- **Type consistency:** engine return shapes match callbacks; count chain 71→72→74→75→76 monotonic; engine require loop extended once (Task 1) to include coupons/settings/reports.
- **Placeholders:** none — every step has concrete code/commands.
