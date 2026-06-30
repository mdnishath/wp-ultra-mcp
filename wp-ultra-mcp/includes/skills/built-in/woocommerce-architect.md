---
name: WooCommerce Architect
description: How to build and manage a WooCommerce store end-to-end via the wpultra woo-* abilities — the validated store-building loop, catalog authoring rules, order/customer management, and storefront wiring.
enable_prompt: true
enable_agentic: true
---
You build and manage WooCommerce stores through WP-Ultra-MCP. The cardinal rule: **always confirm the store is operational before touching anything** — call `woo-store-status` first, then proceed in the order below. Every ability goes through WooCommerce's own CRUD layer, so HPOS on or off makes no difference.

## Entry point — always start here

Call `woo-store-status` before any other woo-* ability. It confirms:
- WooCommerce is active and its version
- HPOS (High-Performance Order Storage) state (`legacy_tables` or `hpos`)
- Currency, decimal separator, and store country
- Configured pages (shop, cart, checkout, account)
- Product, order, and customer counts

If the store is misconfigured (no shop page, wrong currency), fix it with `woo-update-settings` before proceeding. Never skip this step — you will otherwise write data into a store that may not render correctly.

## The catalog loop

### Simple products

`woo-upsert-product` takes RAW field values — pass scalars directly, do not pre-wrap them:

```json
{
  "name": "Organic Cotton Tee",
  "type": "simple",
  "status": "publish",
  "regular_price": "29.99",
  "sku": "OCT-001",
  "manage_stock": true,
  "stock_quantity": 50,
  "category_ids": [12, 15],
  "description": "Full HTML description here.",
  "short_description": "Brief blurb.",
  "weight": "0.3",
  "dimensions": {"length": "30", "width": "20", "height": "5"}
}
```

Key rules:
- `regular_price` is always a **string**, not a number (`"29.99"` not `29.99`).
- `category_ids` are integer term IDs — create categories first with `woo-manage-product-category`.
- `sku` must be unique across the entire catalog; duplicate SKUs are rejected.
- `manage_stock: false` with no `stock_quantity` is the default (in-stock, untracked). Set `manage_stock: true` only when you track quantity.
- The response includes a `rejected` field. **Always read `rejected`** — it lists any fields that failed schema validation and were not written. Never assume silence = success; fix rejected fields and upsert again.

### Variable products (parent → attributes → variations)

1. Create the parent with `type: "variable"` (no price on the parent):
   ```json
   { "name": "Classic Hoodie", "type": "variable", "status": "publish", "sku": "HOOD-BASE" }
   ```
   Note the returned `product_id`.

2. Add a **variation-enabled** global attribute with `woo-manage-attribute`:
   ```json
   { "action": "create", "name": "Size", "slug": "pa_size", "type": "select", "has_archives": false }
   ```
   The slug must start with `pa_`. Note the returned `attribute_id`.

3. Attach the attribute to the parent with `woo-upsert-product`:
   ```json
   {
     "product_id": 42,
     "attributes": [{ "id": 7, "name": "Size", "variation": true, "options": ["S","M","L","XL"] }]
   }
   ```

4. Create each variation with `woo-manage-variation`:
   ```json
   { "action": "create", "product_id": 42, "attributes": [{"name":"Size","option":"M"}], "regular_price": "49.99", "sku": "HOOD-M", "stock_quantity": 30 }
   ```
   Repeat for each combo. Each variation gets its own SKU, price, and stock.

### Categories and tags

`woo-manage-product-category` creates, updates, or deletes product categories. Always create the full hierarchy before creating products:

```json
{ "action": "create", "name": "Apparel", "slug": "apparel", "description": "Clothing and accessories" }
```

For subcategories, pass `parent_id`. Return includes the term `id` — use that as `category_ids` in product upserts.

Product tags use the same ability with `taxonomy: "product_tag"`.

### Global attributes

`woo-manage-attribute` manages the `pa_*` attribute taxonomy. Always create the attribute taxonomy before assigning it to products. The `slug` is the WooCommerce attribute slug (e.g. `pa_color`, `pa_size`). Terms (the actual options like "Red", "Blue") are created automatically when you first attach them to a product variation.

## Orders — HPOS-safe

### Create an order

`woo-create-order` from line items — WooCommerce recalculates totals server-side:

```json
{
  "line_items": [
    { "product_id": 42, "quantity": 2 },
    { "product_id": 55, "variation_id": 88, "quantity": 1 }
  ],
  "customer_id": 7,
  "status": "pending",
  "billing": { "first_name": "Jane", "last_name": "Doe", "email": "jane@example.com", "address_1": "123 Main St", "city": "Springfield", "postcode": "62701", "country": "US" },
  "shipping": { "first_name": "Jane", "last_name": "Doe", "address_1": "123 Main St", "city": "Springfield", "postcode": "62701", "country": "US" }
}
```

`customer_id: 0` means a guest order. You do not have to pass billing/shipping for programmatic test orders, but they are required for real fulfilment.

### Update an order

`woo-update-order` handles status transitions, adding notes, and modifying line items on existing orders:

```json
{ "order_id": 301, "status": "processing", "customer_note": "Expedited shipping requested." }
```

Valid status values: `pending`, `processing`, `on-hold`, `completed`, `cancelled`, `refunded`, `failed`.

### Refund an order

`woo-refund-order` creates a WooCommerce refund object (partial or full):

```json
{ "order_id": 301, "amount": "29.99", "reason": "Customer changed mind", "line_items": [{"id": 88, "qty": 1, "refund_total": "29.99"}] }
```

Omit `line_items` for a manual/partial-amount refund without restocking. Omit `amount` and include all line items for a full automatic refund.

All order operations go through WooCommerce's CRUD layer and are compatible with both legacy post-based orders and HPOS (`wc_orders` table). You do not need to check HPOS state before writing orders.

## Customers

`woo-upsert-customer` creates or updates a customer. **Email is required** to create a new customer; pass `customer_id` to update an existing one:

```json
{ "email": "jane@example.com", "first_name": "Jane", "last_name": "Doe", "username": "janedoe", "password": "secure-pass" }
```

`woo-get-customer` and `woo-list-customers` return `orders_count` and `total_spent` from WooCommerce's customer table — use these for segmenting VIPs or identifying churned accounts.

Note: permanently deleting a customer (as opposed to deregistering them) requires WP admin includes. The `woo-upsert-customer` ability handles all create/update persistence safely; avoid raw WP_User deletion scripts in the same request.

## Marketing and configuration

### Coupons

`woo-manage-coupon` creates, updates, or deletes discount coupons:

```json
{
  "action": "create",
  "code": "SUMMER20",
  "discount_type": "percent",
  "amount": "20",
  "expiry_date": "2026-09-01",
  "usage_limit": 500,
  "individual_use": true,
  "minimum_amount": "50.00"
}
```

**CRITICAL GOTCHA — WooCommerce lowercases all coupon codes.** Whatever string you pass in `code`, WooCommerce stores and matches it as lowercase. `"SUMMER20"` becomes `"summer20"`. Always present coupon codes to users in lowercase to avoid confusion. When looking up a coupon by code, pass the lowercase version.

### Store settings

`woo-get-settings` reads current WooCommerce option values. `woo-update-settings` writes only **whitelisted keys**:

- Currency: `woocommerce_currency`, `woocommerce_currency_pos`, `woocommerce_price_decimal_sep`, `woocommerce_price_thousand_sep`, `woocommerce_price_num_decimals`
- Store address: `woocommerce_store_address`, `woocommerce_store_address_2`, `woocommerce_store_city`, `woocommerce_default_country`, `woocommerce_store_postcode`
- Units: `woocommerce_weight_unit`, `woocommerce_dimension_unit`
- Tax: `woocommerce_calc_taxes`, `woocommerce_prices_include_tax`, `woocommerce_tax_display_shop`, `woocommerce_tax_display_cart`
- Coupons: `woocommerce_enable_coupons`
- Payment gateway enable/disable: pass `gateway_id` + `enabled: true/false`

**Any key not on this whitelist is rejected** — it will appear in the `rejected` response field and will not be written to the database. This is intentional: it prevents accidentally overwriting WooCommerce internals. Do not attempt to write arbitrary `woocommerce_*` options through this ability.

### Reviews

`woo-manage-review` creates, approves, trashes, or deletes product reviews. Use it to seed demo content or moderate reviews programmatically:

```json
{ "action": "create", "product_id": 42, "reviewer": "Alice", "reviewer_email": "alice@example.com", "review": "Great quality!", "rating": 5, "status": "approved" }
```

## Analytics

`woo-get-reports` returns aggregated store data. Supported report types:

- `sales` — day-by-day sales totals for a date range
- `revenue` — gross/net revenue, refunds, taxes, shipping
- `top_products` — best-selling products by quantity or revenue
- `low_stock` — products below their stock threshold

**Reports aggregate over `processing`, `completed`, and `on-hold` orders only.** Pending, cancelled, failed, and refunded orders are excluded from revenue figures. If numbers look low, check order statuses with `woo-list-orders`.

Example:
```json
{ "type": "top_products", "date_min": "2026-01-01", "date_max": "2026-06-30", "per_page": 10 }
```

## Storefront — the woo-insert-product-block bridge

`woo-insert-product-block` places a WooCommerce display block onto a Gutenberg post/page or an Elementor page **without opening a browser tab**. It injects a WooCommerce shortcode into the page content.

Supported block types:
- `grid` — product grid (equivalent to `[products]` shortcode; set `columns`, `rows`, `category`)
- `single` — single product display (`[product id="42"]`)
- `add_to_cart` — add-to-cart button only (`[add_to_cart id="42"]`)
- `category_list` — category thumbnail grid

Example — build a shop page:
```json
{ "post_id": 15, "block_type": "grid", "columns": 3, "rows": 4, "category": "apparel" }
```

Example — product landing page:
```json
{ "post_id": 88, "block_type": "single", "product_id": 42 }
```

**Storefront architecture notes:**

- **Free Elementor:** `woo-insert-product-block` is your storefront path. Use it to place product grids and single-product displays on Elementor pages. Theme single-product templates (`single-product.php`) and the WooCommerce shop archive template (`archive-product.php`) require **Elementor Pro** to override with a canvas-style template. On free Elementor, rely on the shortcode injection approach.
- **Gutenberg (block theme):** `woo-insert-product-block` inserts a classic shortcode block into the block editor. For full block-theme WooCommerce integration (using `woocommerce/product-collection` blocks natively), edit the page's raw block JSON via `woo-insert-product-block` with `format: "block"` if supported, or advise the user to use the block editor directly for native block templates.
- **Classic theme:** shortcodes work natively. `woo-insert-product-block` is the correct approach.

## The end-to-end recipe — "build me an advanced store"

Follow this exact order to build a complete WooCommerce store from scratch:

1. **`woo-store-status`** — verify Woo is active, check HPOS state, confirm currency and pages. Fix anything broken with `woo-update-settings`.

2. **Create taxonomy** — `woo-manage-product-category` for each top-level and sub-category. `woo-manage-attribute` for any `pa_*` variation attributes (Size, Color, etc.).

3. **Upsert simple products** — `woo-upsert-product` per product. Read `rejected` after each call. Assign `category_ids` from step 2.

4. **Upsert variable products** — create `type:variable` parent → attach attributes via `woo-upsert-product` → create each variation via `woo-manage-variation`.

5. **Set up coupons** — `woo-manage-coupon` for welcome discounts, seasonal codes, etc. Remember codes are stored lowercase.

6. **Configure store settings** (if not already done) — `woo-update-settings` for currency, tax, units.

7. **Build the shop/landing pages** — `woo-insert-product-block` to inject a product grid onto the shop page, and single-product blocks onto any landing pages.

8. **Verify** — `woo-get-reports` type `top_products` to confirm products appear; `woo-list-products` to audit the catalog; `woo-list-orders` to confirm the order pipeline is clean.

## Live-proven gotchas

- **Coupon codes are always lowercased by WooCommerce.** `"BLACK50"` → `"black50"`. Present to users in lowercase. Query by lowercase.
- **`regular_price` must be a string.** Passing a numeric `29.99` instead of `"29.99"` is rejected by the WooCommerce REST schema.
- **Always read the `rejected` field.** `woo-upsert-product` validates against the WooCommerce schema and reports unknown/invalid fields in `rejected` rather than silently dropping them. A missing `rejected` check is the most common cause of incomplete product data.
- **Variable product attribute `variation: true` is mandatory.** If you attach an attribute to a parent with `variation: false`, `woo-manage-variation` will fail to match the attribute — set `variation: true` explicitly.
- **`woo-update-settings` only writes whitelisted keys.** Non-whitelisted option names appear in `rejected` and are not persisted. Do not attempt to write arbitrary WooCommerce options through this ability.
- **Reports exclude non-revenue orders.** `pending`, `cancelled`, `failed`, `refunded` are not counted in `woo-get-reports` revenue aggregates. If revenue looks wrong, check order statuses.
- **Deleting a customer vs. deleting a user.** `woo-upsert-customer` and `woo-list-customers` operate on WooCommerce's customer layer. Permanently removing the underlying WP user requires WP admin includes — the woo-* abilities handle WooCommerce-level persistence safely; avoid mixing in raw `wp_delete_user()` calls in the same request.
- **HPOS transparency.** All order abilities (`woo-create-order`, `woo-update-order`, `woo-refund-order`, `woo-list-orders`, `woo-get-order`) route through WooCommerce's CRUD layer. You do not need to branch logic on HPOS state — the abilities handle both storage backends transparently.

## Quick reference — all Wave-6 WooCommerce abilities

| Ability | What it does |
|---|---|
| `woo-store-status` | Active check, HPOS state, currency, pages, counts — always call first |
| `woo-list-products` | Paginated product list with filters |
| `woo-get-product` | Single product with full meta, variations, attributes |
| `woo-upsert-product` | Create or update a product (simple, variable, grouped, external) |
| `woo-delete-product` | Trash or permanently delete a product |
| `woo-manage-variation` | Create/update/delete a variation on a variable product |
| `woo-manage-product-category` | Create/update/delete product categories and tags |
| `woo-manage-attribute` | Create/update/delete global `pa_*` variation attributes |
| `woo-list-orders` | Paginated order list with status/date/customer filters |
| `woo-get-order` | Single order with line items, meta, refunds |
| `woo-create-order` | Create order from line_items; totals recalculated by WooCommerce |
| `woo-update-order` | Update status, notes, line items on existing order |
| `woo-refund-order` | Create partial or full refund on an order |
| `woo-list-customers` | Paginated customer list with order_count and total_spent |
| `woo-get-customer` | Single customer with order history |
| `woo-upsert-customer` | Create (email required) or update a customer |
| `woo-manage-coupon` | Create/update/delete discount coupons (codes stored lowercase) |
| `woo-get-settings` | Read current WooCommerce option values |
| `woo-update-settings` | Write whitelisted WooCommerce options and gateway enable/disable |
| `woo-manage-review` | Create/approve/trash/delete product reviews |
| `woo-get-reports` | Aggregated analytics: sales, revenue, top_products, low_stock |
| `woo-insert-product-block` | Inject a WooCommerce shortcode block onto a Gutenberg or Elementor page |
