# WP-Ultra-MCP — Wave 6 WooCommerce · Plan 4: Bridge + Skill + Cleanup + Ship v0.10.0

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`).
>
> **Final plan of the Wave 6 program** (spec: `docs/superpowers/specs/2026-06-30-wp-ultra-woocommerce-wave6-design.md`). Plans 1–3 shipped catalog + orders/customers + marketing/reports (branch `feat/woocommerce-wave6`, count 76, full suite green). This plan adds the Elementor/Gutenberg storefront bridge, the `woocommerce-architect` skill, a cleanup pass for the accumulated Minor findings, and bumps the version to **0.10.0** for release. After this plan: a final whole-branch review, then merge/push/release via `finishing-a-development-branch`.

**Goal:** 1 ability `woo-insert-product-block` (count 76→77) + the `woocommerce-architect` built-in skill + cleanup of carried Minor findings + version bump to 0.10.0 + README/changelog.

**Architecture:** A new `includes/woocommerce/bridge.php` builds a WooCommerce shortcode from a display spec and inserts it into either a Gutenberg post (a `core/shortcode` block via the Wave-4a `wpultra_gb_*` engine) or an Elementor page (a classic section→column→shortcode-widget via the `wpultra_el_*` engine). The skill is a Markdown file auto-discovered by `includes/skills/sources.php` (no code wiring). Cleanup edits are surgical.

**Tech Stack:** PHP 8.0+, WP 7.0, WooCommerce 10.9.1 shortcodes, the existing `wpultra_gb_*` (gutenberg) + `wpultra_el_*` (elementor) engines, vendored mcp-adapter, Abilities API.

## Global Constraints

- Every PHP file: `<?php` + `declare(strict_types=1);` + `if (!defined('ABSPATH')) { exit(); }`.
- Engine returns arrays/values or `WP_Error` via `wpultra_err`. Abilities return `wpultra_ok([...])` or the `WP_Error`.
- **Ability registration shape** — copy `wp-ultra-mcp/includes/abilities/woo-upsert-product.php`: named string `execute_callback`, `properties` PLAIN ARRAY, `permission_callback=>'wpultra_permission_callback'`, `meta=>['show_in_rest'=>true,'mcp'=>['public'=>true,'type'=>'tool'],'annotations'=>[...]]`. `woo-insert-product-block` is a WRITE: `['readonly'=>false,'destructive'=>false,'idempotent'=>false]` + `wpultra_audit_log` after the write. Gates on `wpultra_woo_active()`.
- **Reuse the existing engines, do NOT reinvent:** Gutenberg — `wpultra_gb_load(int): ['post'=>..,'blocks'=>array]|WP_Error`, `wpultra_gb_insert(array $blocks, array $parentPath, int $pos, array $block): array|WP_Error`, `wpultra_gb_save(int,array): array|WP_Error`, `wpultra_gb_str_to_path(string): array`. Elementor — `wpultra_el_raw(int): array` (the raw `_elementor_data` elements), `wpultra_el_write(int, array): array|WP_Error`, `wpultra_el_new_id(array $tree=[]): string`. A Gutenberg block is `['blockName'=>..,'attrs'=>[],'innerBlocks'=>[],'innerHTML'=>..,'innerContent'=>[..]]`.
- **Bootstrap wiring** (`bootstrap-mcp.php`): add `bridge` to the woocommerce engine require loop; add `'woo-insert-product-block'` to `wpultra_ability_files()` + `wpultra_ability_category_map()['woocommerce']`. `tests/bootstrap.test.php` count `76` → `77`. Files↔map in sync.
- **Built-in skill** lives at `wp-ultra-mcp/includes/skills/built-in/woocommerce-architect.md` — auto-discovered by `includes/skills/sources.php` (glob of `built-in/*.md`); NO code wiring. It must have YAML frontmatter (`name:` + `description:`) matching the existing `elementor-v4-architect.md` shape.
- Bundled PHP: `$PHP = C:/Users/nisha/AppData/Roaming/Local/lightning-services/php-8.2.30+1/bin/win64/php.exe`. Test site root: `C:/Users/nisha/Local Sites/wp-connector/app/public`. Live token: `wpultra-test-9a88`.
- **Re-run `wp-ultra-mcp/bin/deploy.ps1` after every commit.** Live-test probes: token-gated webroot scripts, require engine + ability files, clean up after, delete the script.
- **Harness:** `tests/run-all.ps1` must stay green throughout.
- Commit messages: `feat(woocommerce):` / `fix(woocommerce):` / `docs(woocommerce):` / `chore(release):`; end body with `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.

## File Structure

```
wp-ultra-mcp/includes/
  woocommerce/
    bridge.php   NEW — build shortcode + insert into gutenberg/elementor (Task 1)
    orders.php   MODIFY — date-range fix (Task 3)
    reports.php  MODIFY — date-range fix (Task 3)
    products.php MODIFY — surface wc_delete_attribute + term-seeding WP_Errors (Task 3)
  abilities/
    woo-insert-product-block.php  NEW (Task 1)
  skills/built-in/
    woocommerce-architect.md      NEW (Task 2)
  bootstrap-mcp.php   MODIFY — engine loop + 1 slug (Task 1)
  wp-ultra-mcp.php    MODIFY — version 0.9.0 → 0.10.0 (Task 4)
  readme.txt          MODIFY — stable tag + changelog (Task 4)
README.md             MODIFY — WooCommerce abilities section (Task 4)
tests/
  bootstrap.test.php  MODIFY — count 76 → 77 (Task 1)
  woo-bridge.test.php NEW — pure shortcode-builder test (Task 1)
```

---

### Task 1: `woo-insert-product-block` (bridge)

**Files:**
- Create: `wp-ultra-mcp/includes/woocommerce/bridge.php`, `wp-ultra-mcp/includes/abilities/woo-insert-product-block.php`, `tests/woo-bridge.test.php`
- Modify: `bootstrap-mcp.php` (engine loop + slug), `tests/bootstrap.test.php` (76 → 77)

**Interfaces:**
- Produces:
  - `wpultra_woo_build_shortcode(string $display, array $p): string` — PURE; maps display + params to a Woo shortcode string.
  - `wpultra_woo_insert_product_block(array $input): array|WP_Error` — `['post_id','builder','shortcode']`. `builder: gutenberg|elementor`, `display`, `params{}`, `post_id`, `parent_path?`/`position?` (gutenberg).

- [ ] **Step 1: Write the failing pure test** — `tests/woo-bridge.test.php`

```php
<?php
require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../wp-ultra-mcp/includes/woocommerce/bridge.php';

it('builds grid shortcode', function () {
    assert_eq('[products limit="4" columns="4"]', wpultra_woo_build_shortcode('grid', []));
});
it('builds single product_page', function () {
    assert_eq('[product_page id="9"]', wpultra_woo_build_shortcode('single', ['id' => 9]));
});
it('builds add_to_cart', function () {
    assert_eq('[add_to_cart id="9"]', wpultra_woo_build_shortcode('add_to_cart', ['id' => 9]));
});
it('builds on-sale via products', function () {
    assert_eq('[products limit="3" columns="3" on_sale="true"]', wpultra_woo_build_shortcode('sale', ['limit' => 3, 'columns' => 3]));
});

run_tests();
```

- [ ] **Step 2: Run → fail** — `& $PHP tests/woo-bridge.test.php` → FAIL (`wpultra_woo_build_shortcode` undefined).

- [ ] **Step 3: Write `bridge.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH') && !defined('WPULTRA_TEST')) { /* allow harness load */ }

/** Pure: build a WooCommerce shortcode from a display spec. */
function wpultra_woo_build_shortcode(string $display, array $p): string {
    $limit = (int) ($p['limit'] ?? 4);
    $cols  = (int) ($p['columns'] ?? 4);
    switch ($display) {
        case 'single':
            return '[product_page id="' . (int) ($p['id'] ?? 0) . '"]';
        case 'add_to_cart':
            return '[add_to_cart id="' . (int) ($p['id'] ?? 0) . '"]';
        case 'categories':
            return '[product_categories number="' . $limit . '" columns="' . $cols . '" parent="0"]';
        case 'sale':
            return '[products limit="' . $limit . '" columns="' . $cols . '" on_sale="true"]';
        case 'featured':
            return '[products limit="' . $limit . '" columns="' . $cols . '" visibility="featured"]';
        case 'best_selling':
            return '[products limit="' . $limit . '" columns="' . $cols . '" best_selling="true"]';
        case 'grid':
        default:
            $cat = '';
            if (!empty($p['category'])) { $cat = ' category="' . preg_replace('/[^a-z0-9\-]/', '', strtolower((string) $p['category'])) . '"'; }
            return '[products limit="' . $limit . '" columns="' . $cols . '"' . $cat . ']';
    }
}

function wpultra_woo_insert_product_block(array $input) {
    $post_id = (int) ($input['post_id'] ?? 0);
    if (!$post_id || !get_post($post_id)) { return wpultra_err('post_not_found', "No post with id $post_id."); }
    $builder = (string) ($input['builder'] ?? 'gutenberg');
    $display = (string) ($input['display'] ?? 'grid');
    $shortcode = wpultra_woo_build_shortcode($display, (array) ($input['params'] ?? []));

    if ($builder === 'elementor') {
        if (!function_exists('wpultra_el_raw')) { return wpultra_err('elementor_unavailable', 'Elementor engine not loaded.'); }
        $elements = wpultra_el_raw($post_id);
        $sid = wpultra_el_new_id($elements);
        $cid = wpultra_el_new_id($elements);
        $wid = wpultra_el_new_id($elements);
        $node = [
            'id' => $sid, 'elType' => 'section', 'settings' => (object) [], 'elements' => [
                ['id' => $cid, 'elType' => 'column', 'settings' => ['_column_size' => 100, '_inline_size' => null], 'elements' => [
                    ['id' => $wid, 'elType' => 'widget', 'widgetType' => 'shortcode', 'settings' => ['shortcode' => $shortcode], 'elements' => []],
                ]],
            ],
        ];
        $elements[] = $node;
        $res = wpultra_el_write($post_id, $elements);
        if (is_wp_error($res)) { return $res; }
        return ['post_id' => $post_id, 'builder' => 'elementor', 'shortcode' => $shortcode];
    }

    // gutenberg (default)
    if (!function_exists('wpultra_gb_load')) { return wpultra_err('gutenberg_unavailable', 'Gutenberg engine not loaded.'); }
    $loaded = wpultra_gb_load($post_id);
    if (is_wp_error($loaded)) { return $loaded; }
    $block = ['blockName' => 'core/shortcode', 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => $shortcode, 'innerContent' => [$shortcode]];
    $path = wpultra_gb_str_to_path((string) ($input['parent_path'] ?? ''));
    $pos = isset($input['position']) ? (int) $input['position'] : PHP_INT_MAX;
    $updated = wpultra_gb_insert($loaded['blocks'], $path, $pos, $block);
    if (is_wp_error($updated)) { return $updated; }
    $tree = wpultra_gb_save($post_id, $updated);
    if (is_wp_error($tree)) { return $tree; }
    return ['post_id' => $post_id, 'builder' => 'gutenberg', 'shortcode' => $shortcode];
}
```

- [ ] **Step 4: Run → pass** — `& $PHP tests/woo-bridge.test.php` → PASS (4 `it`). Lint `bridge.php`.

- [ ] **Step 5: Write `woo-insert-product-block` ability**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/woo-insert-product-block', [
    'label'       => __('WooCommerce: Insert Product Block', 'wp-ultra-mcp'),
    'description' => __('Insert a WooCommerce storefront block (grid/single/add_to_cart/categories/sale/featured/best_selling) into a Gutenberg post or Elementor page as a shortcode. builder=gutenberg|elementor, display, params{limit,columns,category,id}.', 'wp-ultra-mcp'),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'post_id'     => ['type' => 'integer'],
            'builder'     => ['type' => 'string', 'enum' => ['gutenberg', 'elementor']],
            'display'     => ['type' => 'string', 'enum' => ['grid', 'single', 'add_to_cart', 'categories', 'sale', 'featured', 'best_selling']],
            'params'      => ['type' => 'object'],
            'parent_path' => ['type' => 'string'],
            'position'    => ['type' => 'integer'],
        ],
        'required'   => ['post_id', 'display'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean'], 'shortcode' => ['type' => 'string'], 'builder' => ['type' => 'string']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_woo_insert_product_block_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

function wpultra_woo_insert_product_block_cb(array $input) {
    if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    $res = wpultra_woo_insert_product_block($input);
    wpultra_audit_log('woo-insert-product-block', is_wp_error($res) ? 'failed' : ((string) ($input['builder'] ?? 'gutenberg') . ' post ' . (int) ($input['post_id'] ?? 0)), !is_wp_error($res));
    if (is_wp_error($res)) { return $res; }
    return wpultra_ok(['shortcode' => $res['shortcode'], 'builder' => $res['builder']]);
}
```

- [ ] **Step 6: Wire engine loop + slug + bump count** — add `'bridge'` to the woocommerce engine require loop; add `'woo-insert-product-block'` to files + map; `tests/bootstrap.test.php` `76` → `77`.

- [ ] **Step 7: Run the suite** — `& $PHP tests/woo-bridge.test.php` + `& $PHP tests/bootstrap.test.php` (count 77). Lint all changed files.

- [ ] **Step 8: Deploy + live-verify** — `powershell -File wp-ultra-mcp/bin/deploy.ps1`. Probe (require engine `{setup,schema,products,bridge}.php` + gutenberg engine `{tree,engine}.php` + elementor engine `{setup,tree,engine}.php` + the ability + helpers; admin user):
   - GUTENBERG: create a draft post (`wp_insert_post(['post_title'=>'Woo GB Probe','post_status'=>'draft','post_content'=>''])`); `wpultra_woo_insert_product_block_cb(['post_id'=>$gid,'builder'=>'gutenberg','display'=>'grid','params'=>['limit'=>3]])` → assert success + shortcode `[products limit="3" columns="4"]`; re-load the post content and assert it contains `wp:shortcode` + `[products`. Delete the post (`wp_delete_post($gid,true)`).
   - ELEMENTOR: create a post, set `_elementor_edit_mode`='builder'; `wpultra_woo_insert_product_block_cb(['post_id'=>$eid,'builder'=>'elementor','display'=>'single','params'=>['id'=>99]])` → assert success + shortcode `[product_page id="99"]`; read `get_post_meta($eid,'_elementor_data',true)` and assert it contains `"widgetType":"shortcode"` + the shortcode. Delete the post.
   Echo JSON, confirm, delete the probe script. If the Elementor path has a quirk (e.g. settings shape), adjust to what writes valid `_elementor_data` and RECORD it in the report.
9. Commit all new/changed files together.

```bash
git add wp-ultra-mcp/includes/woocommerce/bridge.php wp-ultra-mcp/includes/abilities/woo-insert-product-block.php wp-ultra-mcp/includes/bootstrap-mcp.php tests/woo-bridge.test.php tests/bootstrap.test.php
git commit -m "feat(woocommerce): woo-insert-product-block (storefront shortcode bridge for gutenberg+elementor)"
```

---

### Task 2: `woocommerce-architect` built-in skill

**Files:**
- Create: `wp-ultra-mcp/includes/skills/built-in/woocommerce-architect.md`

**Interfaces:** none (auto-discovered by `includes/skills/sources.php`). No bootstrap change, no count change.

- [ ] **Step 1: Write the skill** — `wp-ultra-mcp/includes/skills/built-in/woocommerce-architect.md`. Mirror the frontmatter shape of `wp-ultra-mcp/includes/skills/built-in/elementor-v4-architect.md` (open it to copy the exact frontmatter keys). Required frontmatter: `name: WooCommerce Architect` + a one-line `description:`. Body content (write it out fully — this is the deliverable; encode the proven Wave-6 workflow + gotchas):

  - **Entry point:** always call `woo-store-status` first — confirms Woo active, HPOS state, currency, page setup, counts.
  - **The catalog loop:** `woo-upsert-product` takes RAW field values (name, regular_price as a string, sku, manage_stock+stock_quantity, category_ids, etc.); unknown fields come back in `rejected` (schema-validated, never silently dropped) — read `rejected` and fix. Variable products = create `type:variable` parent, set a variation-enabled attribute, then `woo-manage-variation` for each combo. Categories/tags via `woo-manage-product-category`; global attributes (`pa_*`) via `woo-manage-attribute`.
  - **Orders (HPOS-safe):** `woo-create-order` from `line_items:[{product_id,quantity}]` (+ customer_id/status/addresses) recalculates totals; `woo-update-order` for status/notes/line-items; `woo-refund-order` full or partial. All go through WooCommerce's CRUD layer — works whether or not HPOS is on.
  - **Customers:** `woo-upsert-customer` (email required to create); `woo-get-customer`/`woo-list-customers` include order count + total spent.
  - **Marketing/config:** `woo-manage-coupon` (note: WooCommerce lowercases coupon codes); `woo-get-settings`/`woo-update-settings` (update writes WHITELISTED keys only — currency/country/units/tax/coupon toggles/store address + payment-gateway enable/disable; non-whitelisted keys are rejected); `woo-manage-review` (product reviews).
  - **Analytics:** `woo-get-reports` type sales|revenue|top_products|low_stock.
  - **Storefront:** `woo-insert-product-block` puts a product grid / single product / add-to-cart / category list onto a Gutenberg post or Elementor page as a WooCommerce shortcode (no browser tab). On free Elementor this is the storefront path (theme single-product/shop templates need Elementor Pro). To build a full shop page: create the page, then insert a `grid` block; for a product landing, insert `single` or `add_to_cart` with the product id.
  - **The end-to-end recipe** ("build me an advanced store"): store-status → create categories/attributes → upsert products (simple + variable) → set up a coupon → (optionally) build a shop/landing page via `woo-insert-product-block` → verify with `woo-get-reports`/`woo-list-products`.
  - **Gotchas (live-proven):** coupon codes are lowercased; deleting a *customer* needs WP admin includes (the abilities handle persistence, this only matters for raw scripts); `woo-update-settings` never writes non-whitelisted options; reports aggregate over `processing/completed/on-hold` orders.

- [ ] **Step 2: Verify the skill parses + loads** — token-gated probe (require `includes/skills/sources.php`, call the loader function `wpultra_skill_sources()` — confirm `woocommerce-architect` is a key with a non-empty `name`+`body`), OR run a tiny PHP check that requires sources.php and asserts the new file is globbed. Confirm no parse error. Delete the probe. (No deploy strictly needed for a skill file, but deploy anyway so the live site has it.)

```bash
powershell -File wp-ultra-mcp/bin/deploy.ps1
```

- [ ] **Step 3: Commit**

```bash
git add wp-ultra-mcp/includes/skills/built-in/woocommerce-architect.md
git commit -m "feat(woocommerce): woocommerce-architect built-in skill (the store-building loop)"
```

---

### Task 3: Cleanup pass (carried Minor findings)

**Files:**
- Modify: `wp-ultra-mcp/includes/woocommerce/orders.php`, `reports.php`, `products.php`

**Interfaces:** no signature changes — surgical fixes only. No count change.

- [ ] **Step 1: Fix the both-sided date range in `reports.php`.** Find the block that sets `$q['date_created']` from `date_from`/`date_to` and replace it so a both-supplied range uses WooCommerce's range syntax:

```php
    if (!empty($input['date_from']) && !empty($input['date_to'])) {
        $q['date_created'] = $input['date_from'] . '...' . $input['date_to'];
    } elseif (!empty($input['date_from'])) {
        $q['date_created'] = '>=' . $input['date_from'];
    } elseif (!empty($input['date_to'])) {
        $q['date_created'] = '<=' . $input['date_to'];
    }
```

- [ ] **Step 2: Fix the same both-sided date range in `orders.php`** (`wpultra_woo_list_orders`) — apply the identical three-branch pattern from Step 1 (replace the two unconditional `$q['date_created']` assignments).

- [ ] **Step 3: Surface swallowed WP_Errors in `products.php`.** In `wpultra_woo_manage_attribute`: (a) the `delete` branch — change `$ok = wc_delete_attribute($id); return ['id'=>$id,'deleted'=>(bool)$ok];` so a `WP_Error` from `wc_delete_attribute` is returned (mirror the term-delete `is_wp_error` guard): if `wc_delete_attribute` returns a `WP_Error`, `return $ok;` before the array. (b) the term-seeding loop — capture `wp_insert_term` results and, if a term insert returns a `WP_Error` that is NOT `term_exists`, collect it into a `notes` array on the return (do not fatal). Keep the return shape backward-compatible (`['id','slug']` plus an optional `notes`).

- [ ] **Step 4: Run the FULL suite** — `powershell -File tests/run-all.ps1` → `ALL TEST FILES PASSED` (count unchanged at 77; no regressions). Lint the 3 changed files.

- [ ] **Step 5: Deploy + live-verify the date fix** — `powershell -File wp-ultra-mcp/bin/deploy.ps1`. Probe: create 2 orders dated "now" (product 99, completed). `woo-get-reports type:'sales', date_from:'<yesterday ISO>', date_to:'<tomorrow ISO>'` → assert `order_count` ≥ 2 (proves BOTH bounds applied — the orders fall inside the range). `date_from:'<10 days ago>', date_to:'<5 days ago>'` → assert `order_count` 0 (orders are outside that past window — proves the lower bound is now honored). Force-delete the test orders, delete the probe. (Use fixed date strings computed in the probe via `date('c', strtotime('-1 day'))` etc.)

- [ ] **Step 6: Commit**

```bash
git add wp-ultra-mcp/includes/woocommerce/orders.php wp-ultra-mcp/includes/woocommerce/reports.php wp-ultra-mcp/includes/woocommerce/products.php
git commit -m "fix(woocommerce): both-sided date-range filter (orders+reports) + surface attribute/term WP_Errors"
```

---

### Task 4: Version bump v0.10.0 + README + changelog

**Files:**
- Modify: `wp-ultra-mcp/wp-ultra-mcp.php` (header `Version:` + `WPULTRA_VERSION`), `wp-ultra-mcp/readme.txt` (Stable tag + changelog), `README.md` (WooCommerce abilities section + total count)

**Interfaces:** none. No count change. This is the release-prep commit.

- [ ] **Step 1: Bump the plugin version** — in `wp-ultra-mcp/wp-ultra-mcp.php`: the header `* Version: 0.9.0` → `* Version: 0.10.0`, and `define('WPULTRA_VERSION', '0.9.0');` → `'0.10.0'`.

- [ ] **Step 2: Update `readme.txt`** — `Stable tag: 0.9.0` → `0.10.0`, and add a changelog entry at the top of the `== Changelog ==` section:

```
= 0.10.0 =
* Wave 6 — WooCommerce: 22 new abilities for full store control. Products (simple/variable/grouped/external + variations, categories, global attributes), HPOS-safe orders (create/update/status/refund) + customers, coupons, whitelisted store settings + payment-gateway toggle, product reviews, sales/top-product/low-stock reports, and a storefront bridge that inserts product blocks into Gutenberg/Elementor pages as shortcodes. New woocommerce-architect skill encodes the store-building loop. All schema-validated, all via the WooCommerce CRUD API.
```

- [ ] **Step 3: Update `README.md`** — add a WooCommerce section to the abilities listing (mirror how the Elementor/Gutenberg sections are written) listing the 22 Wave-6 abilities grouped (products 8, orders 5, customers 3, marketing/config 4, reports 1, bridge 1), and update any "N abilities" total to reflect **77**.

- [ ] **Step 4: Run the full suite once more** — `powershell -File tests/run-all.ps1` → `ALL TEST FILES PASSED` (count 77). Lint `wp-ultra-mcp.php`.

- [ ] **Step 5: Deploy** — `powershell -File wp-ultra-mcp/bin/deploy.ps1` (so the live site reports v0.10.0).

- [ ] **Step 6: Commit**

```bash
git add wp-ultra-mcp/wp-ultra-mcp.php wp-ultra-mcp/readme.txt README.md
git commit -m "chore(release): Wave 6 WooCommerce, v0.10.0 (77 abilities)"
```

---

## Plan 4 Done — exit criteria

- `woo-insert-product-block` live-verified on both builders (Gutenberg `core/shortcode`, Elementor shortcode widget); `tests/bootstrap.test.php` count = **77**; `woo-bridge.test.php` green; full suite green.
- `woocommerce-architect` skill parses + loads.
- Carried Minor findings cleaned (date-range both-sided fixed in orders+reports and live-proven; attribute/term WP_Errors surfaced).
- Version is **0.10.0** in all three spots; README + changelog updated.
- **After this plan:** a final whole-branch review of the entire Wave 6 (from `main`), then `finishing-a-development-branch` → merge to main, push, build zip (`bin/build-zip.ps1`), `gh release create v0.10.0`.

## Self-Review notes (done during planning)

- **Spec coverage (Plan 4 slice):** Elementor/Gutenberg bridge ✓ (Task 1), `woocommerce-architect` skill ✓ (Task 2), the design's "ship as v0.10.0" ✓ (Task 4). Cleanup (Task 3) resolves the carried Minors incl. the cross-plan date-range bug.
- **Reuse:** bridge calls the existing `wpultra_gb_*` + `wpultra_el_*` engines (verified signatures) — no new tree logic. Skill is auto-globbed (no wiring).
- **Type consistency:** `wpultra_woo_build_shortcode` (pure, tested) + `wpultra_woo_insert_product_block` return `['post_id','builder','shortcode']`; callback wraps `shortcode`+`builder`. Count 76→77 once (Task 1). Engine loop extended once (Task 1, `+bridge`).
- **Placeholders:** none — every code step has concrete content; the skill body is specified section-by-section (the implementer writes the prose, which IS the deliverable).
