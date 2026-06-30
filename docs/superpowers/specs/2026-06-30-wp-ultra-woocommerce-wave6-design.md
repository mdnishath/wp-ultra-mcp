# WP-Ultra-MCP — Wave 6 (WooCommerce)

> Design doc. Status: **approved (design)**, ready to plan. Builds on the shipped v0.9.0 plugin (Waves 1–4b + the full Elementor design arc). Ships as **v0.10.0**. Single comprehensive wave (user-chosen), internally organized into modules so it stays tractable and testable.

## Problem

The plugin can already control WordPress core, Elementor, and Gutenberg — but has **zero first-class WooCommerce support**. Today a user *can* drive WooCommerce only through the generic power-tools (`execute-php`, `run-wp-cli`, `execute-wp-query`), which forces the AI to guess internal meta keys, function names, and (since modern WooCommerce) the **HPOS** order tables every single time. No validation, high error rate, no discoverability.

**Main goal (user, verbatim intent):** *normal users should be able to build an advanced ecommerce site with AI.* So WooCommerce must become a first-class, schema-driven, validated capability — built to the same reliability bar as the Elementor arc — covering the whole store: catalog, orders, customers, marketing, settings, reports, plus a bridge to surface Woo content inside Elementor/Gutenberg pages.

## Principles (carried from the Elementor arc)

1. **Go through WooCommerce's own CRUD API, never raw postmeta/SQL.** `wc_get_product()`, `WC_Product*` objects, `wc_get_order()`, `WC_Order`, `WC_Customer`, `WC_Coupon`, `$obj->save()`. This is **HPOS-safe by construction** (orders may live in `wp_wc_orders`, not postmeta) and survives WooCommerce internal changes.
2. **Schema-driven + validated.** Abilities expose/accept a documented field schema; inputs are coerced + validated before the CRUD save, with per-field rejection reporting (the Elementor `validate` pattern), not silent drops.
3. **Pure engine, thin abilities.** Logic lives in pure-ish `includes/woocommerce/*.php` functions (unit-testable with the zero-dep harness via stubs); abilities are wiring + audit.
4. **Graceful degradation.** WooCommerce may be inactive. Every ability first checks `wpultra_woo_active()`; if absent it returns a structured `woocommerce_inactive` error with a one-line how-to-install, never a fatal.
5. **Partial-safe writes.** Multi-field/multi-entity operations collect per-item failures into `notes` rather than aborting the whole call (Elementor token-plan pattern).

## Architecture

`includes/woocommerce/` — module-per-file, mirroring `includes/elementor/`:

| File | Responsibility |
|---|---|
| `setup.php` | `wpultra_woo_active()` (class_exists `WooCommerce` + version), HPOS detection (`Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()`), Woo page setup status (shop/cart/checkout/my-account), store base country/currency. Activation guard helper. |
| `schema.php` | Canonical field schemas for product / variation / order / customer / coupon (key → `{type, enum?, required?, default?, writable?}`); `wpultra_woo_validate($entity, $input)` → `{ok, clean, rejected:[{field,reason}]}`; coercion (numbers, bools, money, term-id/slug resolution). |
| `products.php` | List/get/upsert/delete products (all types), variations, categories, tags, attributes + terms, gallery/featured image attach. |
| `orders.php` | List/get/create/update/refund orders (HPOS-safe), line items, order notes, status transitions, addresses. |
| `customers.php` | List/get/upsert customers (`WC_Customer`), order history summary. |
| `coupons.php` | CRUD coupons (`WC_Coupon`) — type, amount, restrictions, usage limits. |
| `settings.php` | Read/update store settings (general, currency, payment-gateway enable/disable, shipping zones/methods read+toggle, tax options), product reviews moderation. |
| `reports.php` | Sales totals, top products, revenue over a date range, low/out-of-stock list — computed via Woo data stores / WC_Admin reports where available, else aggregated through the CRUD layer. |
| `elementor-bridge.php` | Build a WooCommerce content block (product grid / single-product display / add-to-cart / categories) and inject it into an Elementor or Gutenberg page via the existing engines. Free-Elementor path = Woo **shortcode** wrapped in a shortcode widget/block; designed to be Pro-extensible (Woo widgets) later. |

Each ability file in `includes/abilities/` (one per ability) requires the relevant engine module and is wired in `bootstrap-mcp.php`.

## Ability surface (~22)

### Status / setup
- **`woo-store-status`** *(read)* — `{active, version, hpos_enabled, currency, base_country, pages:{shop,cart,checkout,my_account}, counts:{products,orders,customers}}`. The AI's entry point; tells it whether Woo is usable and how the store is configured. If inactive, returns install guidance.

### Products (catalog)
- **`woo-list-products`** *(read)* — filter by `{search, status, type, category, tag, stock_status, on_sale, page, per_page}`; returns compact rows `{id,name,type,status,price,stock,sku}`.
- **`woo-get-product`** *(read)* — full product incl. variations, attributes, categories, tags, images, dimensions, tax, downloadable/virtual flags.
- **`woo-upsert-product`** *(write)* — create/update **simple, variable, grouped, external/affiliate**. Accepts name, slug, status, type, short/long description, regular/sale price + sale schedule, SKU, manage-stock + quantity + backorders, weight + dimensions, shipping/tax class, categories, tags, attributes (incl. variation-defining), featured image + gallery (by URL or media id), virtual/downloadable + files, cross/up-sells, menu order, catalog visibility. Schema-validated; rejected fields reported.
- **`woo-delete-product`** *(write)* — trash or force-delete.
- **`woo-manage-variation`** *(write)* — CRUD variations of a variable product (attribute combo, price, stock, sku, image, dimensions).
- **`woo-manage-product-category`** *(write)* — CRUD product categories & tags (name, slug, parent, description, thumbnail); list too.
- **`woo-manage-attribute`** *(write)* — CRUD global product attributes (`pa_*`) and their terms; list.

### Orders
- **`woo-list-orders`** *(read)* — HPOS-safe; filter `{status, customer, date_from, date_to, search, page, per_page}`; compact rows `{id,number,status,total,customer,date}`.
- **`woo-get-order`** *(read)* — full: line items, shipping/fee/tax lines, billing/shipping address, payment method, notes, totals.
- **`woo-create-order`** *(write)* — line items (product/variation + qty), customer, addresses, shipping, coupons, status; recalculates totals via Woo.
- **`woo-update-order`** *(write)* — change status (with valid-transition awareness), edit line items, add order note (private/customer), update addresses.
- **`woo-refund-order`** *(write)* — full or partial refund (amount or per-line qty), optional restock.

### Customers
- **`woo-list-customers`** *(read)* — filter/search/paginate; rows `{id,email,name,orders,total_spent}`.
- **`woo-get-customer`** *(read)* — profile, billing/shipping, order history summary.
- **`woo-upsert-customer`** *(write)* — create/update `WC_Customer` (email, name, billing, shipping, password optional).

### Marketing / config
- **`woo-manage-coupon`** *(write)* — CRUD: code, discount type (fixed cart / percent / fixed product), amount, free shipping, expiry, min/max spend, product/category include/exclude, usage limits; list.
- **`woo-get-settings`** *(read)* — general (currency, country, selling/shipping locations, units), payment gateways (id/enabled/title), shipping zones+methods, tax options.
- **`woo-update-settings`** *(write)* — update general options, enable/disable a payment gateway, toggle/add a shipping method on a zone, tax toggles. Whitelisted option keys only.
- **`woo-manage-review`** *(write)* — list/approve/unapprove/spam/delete product reviews; create a review.

### Reports
- **`woo-get-reports`** *(read)* — `{type: 'sales'|'top_products'|'revenue'|'low_stock'|'coupons', date_from?, date_to?}` → aggregated figures.

### Elementor / Gutenberg bridge
- **`woo-insert-product-block`** *(write)* — insert a Woo content unit into a target page (`builder: 'elementor'|'gutenberg'`, `display: 'grid'|'single'|'add_to_cart'|'categories'|'featured'|'sale'`, params like ids/category/columns/limit) at a path. Implemented as a Woo **shortcode** injected through the existing Elementor/Gutenberg insert engines. Pro-widget path is a documented future extension.

### Skill
- **`woocommerce-architect`** built-in skill (`includes/skills/built-in/woocommerce-architect.md`) — encodes the validated authoring loop and the non-obvious rules: variable product = parent + child variations (attributes must be set variation-enabled first); go through CRUD API not meta; HPOS order access; stock-management flags; how to build a storefront on free Elementor via shortcodes; the perceive→build→validate→verify loop. Mirrors `elementor-v4-architect`.

## HPOS & API specifics (to verify live, like the Elementor API table)

- Orders: `wc_get_order($id)`, `wc_create_order()`, `WC_Order_Query` for listing (NOT `get_posts` on `shop_order` — breaks under HPOS). Status via `$order->update_status()`. Refund via `wc_create_refund()`.
- Products: `wc_get_product()`, `WC_Product_Simple|Variable|Grouped|External`, `WC_Product_Variation`; query via `wc_get_products()` / `WC_Product_Query`. Attributes via `WC_Product_Attribute`; global attributes via `wc_get_attribute_taxonomies()` / `wc_create_attribute()`.
- Customers: `WC_Customer`, `wc_get_orders(['customer'=>...])`.
- Coupons: `new WC_Coupon($code)`, setters + `->save()`.
- Settings: `WC_Admin_Settings::get_option` / `update_option` on whitelisted `woocommerce_*` keys; gateways `WC()->payment_gateways()->payment_gateways()`; shipping `WC_Shipping_Zones`.
- Reports: prefer `Automattic\WooCommerce\Admin\API\Reports\*` data stores when present; fall back to `wc_get_orders` aggregation.

## Bootstrap wiring

- New ability slugs into `wpultra_ability_files()` under a `// woocommerce (Wave 6)` group, and a new **`'woocommerce'`** category in `wpultra_ability_category_map()` + `wpultra_register_categories()`.
- `includes/woocommerce/*` added to the engine require loop in `wpultra_load_abilities()`.
- `tests/bootstrap.test.php` ability count updated (55 → ~77).
- The new built-in skill registered alongside `elementor-v4-architect` / `self-healing`.
- Version bumps to **0.10.0** in `wp-ultra-mcp.php` header, `WPULTRA_VERSION`, `readme.txt` stable tag; README ability list updated.

## Safety

- Every ability gates on `wpultra_woo_active()` first.
- All writes go through Woo CRUD `->save()` (validated by Woo) AND our pre-validation; rejected fields reported, never silently dropped.
- `woo-update-settings` writes **whitelisted option keys only** — cannot set arbitrary `wp_options`.
- Destructive ops (`woo-delete-product`, `woo-refund-order`, force-delete) default to non-destructive (trash / dry-run flag) and require explicit `force`/`confirm` for the irreversible path, consistent with `execute-wp-query`'s confirm gate.
- All mutating abilities call `wpultra_audit_log`.
- Sandbox safe-mode still applies (Woo PHP runs under the same guard).

## Testing

- **Install WooCommerce on the Local test site** via `run-wp-cli` (`wp plugin install woocommerce --activate`) — required, no Woo currently present; field-plugin-style live testing.
- **Pure unit (zero-dep harness):** `schema.php` validation/coercion (money parse, bool/enum coercion, required-field rejection, term resolution with stubs); compact-row shapers; shortcode-builder string in `elementor-bridge.php`. Woo-object calls are live-tested.
- **Live (token-gated script on the running Local site):** create simple + variable product (with variations) → get → list → update price/stock → delete; create order from a product → update status → partial refund (HPOS path); upsert customer; create coupon + verify it applies; read + toggle a payment gateway; reports return non-zero after an order; `woo-insert-product-block` injects a `[products]` shortcode into an Elementor page and renders. Capture the verified API facts into the project memory + the skill, exactly as the Elementor arc did.

## Out of scope (explicit)

- **Elementor Pro / theme single-product & shop-archive template building** — free Elementor cannot edit theme templates; bridge is shortcode/block-based now, Pro widgets a future extension (documented, not built).
- Subscriptions, Bookings, Memberships, and other premium WooCommerce extensions.
- Payment-gateway *credential* entry / live transactions (enable/disable + config only; never moving real money).
- Multi-currency, complex tax-rule authoring beyond toggles.
- Bricks builder (separate wave).

## Success criteria

- From a fresh AI session against a Woo-active site, the AI can: discover store status; create/edit/delete products of every type incl. variations, categories, attributes; manage orders end-to-end (create → status → refund) HPOS-safely; manage customers and coupons; read/adjust core store settings; pull sales/stock reports; and surface product content into an Elementor/Gutenberg page — **all schema-validated, all without raw SQL/meta guessing**.
- The `woocommerce-architect` skill lets a normal user say "build me an advanced store" and have the AI follow a reliable loop.
- Every ability live-verified on the test site; verified API facts recorded in memory + skill.
