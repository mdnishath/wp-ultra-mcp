# WP-Ultra-MCP

**Free, open-source.** Turn any WordPress site into a [Model Context Protocol](https://modelcontextprotocol.io) server so AI clients (Claude Code, Claude Desktop, Cursor, Gemini CLI) can build and control the whole site — files, SQL, WP-CLI, PHP, content, Elementor, and more — directly, with no relay service in the middle.

Install the plugin, flip a toggle, paste a config into your AI client. That's it. Your data never leaves your server.

> Inspired by what closed/paid tools like Novamira do — rebuilt as a free plugin, and pushed further with **declarative custom abilities** (no plugin code needed), management **Hubs**, and a crash-recovery **sandbox**.

---

## ✨ The headline: extend the AI's toolset without writing code

Every other tool makes you write a PHP plugin to add a capability. WP-Ultra-MCP lets you (or the AI itself) define a new **ability** from a tiny `.md`/JSON recipe — uploaded in the **Ability Hub** or created over MCP — and it instantly becomes a real tool the AI can call:

```markdown
---
name: woo-empty-cart
description: Empty a WooCommerce customer's cart
category: custom
run: wp-cli
---
​```json
{ "input": { "user_id": { "type": "integer", "required": true } },
  "command": ["wc", "cart", "empty", "--user={user_id}"] }
​```
```

Recipe run types: `wp-cli` · `sql` (parameter-bound) · `php` (sandboxed) · `http`. The AI can even mint its own abilities via the `ability-write` tool. This is the compounding advantage: an ever-growing library of skills + abilities covering the whole WordPress ecosystem.

---

## Why WP-Ultra-MCP

| | WP-Ultra-MCP | Closed/paid alternatives |
|---|---|---|
| **Cost** | Free, GPL open-source | Paid, gated features |
| **Custom abilities** | Declarative `.md`/JSON — no code | Write a PHP plugin |
| **Data ownership** | Your server, your DB | May transit a 3rd-party service |
| **Hubs** | Ability / Skill / Memory Hubs in wp-admin | Limited |
| **Sandbox safety** | Crash-recovery safe-mode keeps the site up | Varies |
| **Elementor** | Schema-driven, server-side (shipped Wave 2) | Often paid-only |

---

## Shipped now

### Wave 1 — Core abilities
- **Files:** `read-file` · `write-file` · `edit-file` · `delete-file` · `list-directory` — jailed to the WP root, executable files sandboxed
- **Code:** `run-wp-cli` (arg-array, no shell injection) · `execute-php` (sandboxed eval)
- **Database:** `execute-wp-query` — parameterized SQL; destructive queries gated behind `confirm: true`
- **Diagnostics:** `read-debug-log`
- **Memory:** `memory-save` · `memory-get` · `memory-list` · `memory-delete` — persistent across sessions
- **Content:** `create-post` · `update-post` · `delete-post` (+ meta, taxonomy terms, featured image)
- **Skills:** `skill-get` · `skill-write` · `skill-edit` · `skill-delete` — reusable markdown prompt docs

### Wave 1.5 — Hubs, declarative abilities & sandbox
- **Declarative ability engine** — `.md`/JSON recipes become real MCP abilities at runtime (`run: wp-cli|sql|php|http`)
- **Ability Hub** — create / upload / edit / delete custom abilities; **`ability-write` / `ability-get` / `ability-delete`** so the AI can manage its own tools
- **Skill Hub** — upload / edit / export `.md` skills, per-skill prompt + agentic toggles, read-only built-ins
- **Memory Hub** — view / add / edit / delete persistent memories
- **Sandbox safe-mode** — if AI-written PHP triggers a fatal, a sentinel suspends it and keeps the site up, with a one-click recovery
- **Connect page** — managed Application-Password list + revoke, and per-client setup tabs (Claude Desktop / Claude Code / Cursor / Gemini / generic HTTP)

### Wave 2 — Elementor (shipped)

Schema-driven Elementor **v4 atomic** layout control. Requires Elementor (free or Pro) with the `e_atomic_elements` experiment enabled.

- **`elementor-list-widgets`** — list all registered Elementor widgets; pass `atomic_only:true` to filter to v4 atomic widgets only (e-heading, e-button, e-image, e-paragraph, e-divider, e-flexbox, e-div-block, …)
- **`elementor-get-widget-schema`** — introspect a widget's full prop schema: each prop's `$$type`, allowed `enum` values, and `default`; use this before setting any widget to avoid guessing
- **`elementor-get-style-schema`** — introspect the style schema for a widget or container (CSS custom-properties, layout, spacing, typography tokens)
- **`elementor-get-content`** — read a page's Elementor data as a compact element tree; pass `element_id` to drill into one node's full settings
- **`elementor-set-content`** — replace a page's entire Elementor data array (atomic-safe write that bypasses Document::save, which would strip atomic widgets; clears the CSS cache)
- **`elementor-add-element`** — insert a new element (container or widget) at a given parent and position; plain scalar settings are auto-wrapped into the `{$$type,value}` form and validated by Elementor's own Props_Parser
- **`elementor-edit-element`** — deep-merge new settings into an existing element without touching sibling props
- **`elementor-delete-element`** — remove an element (and its subtree) from the page
- **`elementor-move-element`** — relocate an element to a new parent and/or position within the tree

Built-in skill **`elementor-v4-architect`** is pre-loaded and teaches the AI the step-by-step atomic workflow: introspect → build → position → read back.

### Wave 3 — Elementor design systems (shipped)

Site-wide design control for Elementor v4. Requires Elementor (free or Pro).

- **`elementor-get-design-system`** — read the active kit's global colors, global typography presets, and design-token variables in one call; use this to understand the current brand palette before making changes
- **`elementor-manage-global-colors`** — set or add brand colors to the kit (e.g. `{colors:[{title:"Brand",color:"#0055FF"}], target:"custom"}`); each color becomes a `--e-global-color-<id>` CSS custom property applied site-wide across all pages
- **`elementor-manage-variables`** — list or create Elementor v4 design-token variables (color, font, size types); reference a variable inside any widget or style prop with the shape `{ "$$type":"global-color-variable", "value":"e-gv-<id>" }` so widgets stay in sync when the token value changes
- **`elementor-list-dynamic-tags`** — list all registered dynamic-tag groups and tags; bind any widget prop to live data with `{ "$$type":"dynamic", "value":{ "name":"post-title", "group":"post", "settings":{} } }` — ACF, JetEngine, and other field-plugin tags appear here when those plugins are active

The built-in **`elementor-v4-architect`** skill is extended with a "Design systems (site-wide)" section that teaches the AI the variable-reference and dynamic-tag binding shapes.

### Wave 3.5 — Global classes & interactions (shipped)

Reusable CSS classes and entrance animations for Elementor v4 elements. Requires Elementor (free or Pro); global classes require the `e_classes` experiment enabled (the `elementor-upsert-global-class` ability can enable it automatically by passing `enable:true`).

- **`elementor-list-global-classes`** — list all existing global classes in the active kit
- **`elementor-upsert-global-class`** — create or update a reusable style class; `props` are atomic CSS props (e.g. `{ "color":{"$$type":"color","value":"#fff"} }`); returns an `e-gc-…` id usable across any page
- **`elementor-apply-class`** — add or remove a global class id on any element (`{post_id, element_id, class_id}`; pass `remove:true` to detach); changes take effect site-wide wherever the class is applied
- **`elementor-set-interaction`** — attach an entrance animation to any element (`{post_id, element_id, trigger:"scrollIn", effect:"fade"|"slide"|"scale", type:"in", duration:600}`); uses Elementor's native interactions system

The built-in **`elementor-v4-architect`** skill is extended with a "Reusable classes & animations" section that teaches the AI the class-creation, application, and interaction-setting shapes.

### Phase A — Elementor Reliability (shipped)

Reliable Elementor builds — schema validation before every write + server-side render check. Catches silently-dropped atomic props that cause broken designs.

- **`elementor-validate`** — dry-run schema validation of Elementor pages; validates atomic settings and container layout props; detects silently-dropped properties before they break the design
- **`elementor-render-check`** — server-side render verification: confirms what actually rendered after `set-content` / `add-element` / `edit-element` / `move-element` (catches design breakage)
- **Validate-before-commit:** Elementor writes now enforce strict atomic-settings validation (with `force:true` escape hatch); container layout properties always validated to prevent broken designs

### Phase B — Elementor Design Tokens (shipped)

Apply a reference's design palette, typography system, and token sizes as Elementor Variables for token-consistent, reference-faithful builds.

- **`elementor-apply-design-tokens`** — create color, font, and size Variables from a perceived reference's palette, typography, and sizes; returns refs in the form `{$$type,value}` for use in atomic-build workflows

### Phase B2 — Elementor Blueprints (shipped)

Insert ready-made structural section skeletons — navbar, hero, feature-grid, CTA, footer — with fresh ids, layout, and placeholder copy (no styling). Style them afterward with design tokens + global classes.

- **`elementor-list-blueprints`** — list available built-in blueprint skeletons with descriptions
- **`elementor-insert-blueprint`** — insert a blueprint skeleton at a given parent and position; re-ids for the page, validates, and writes atomically. Carries layout + placeholder text only; style with tokens + classes

### Wave 4a — Gutenberg core block control (shipped)

Positional-path block tree ops for Gutenberg posts and pages. Core WordPress APIs only — no browser tab required.

- **`gutenberg-get-content`** — read a post's block tree as a compact JSON array (type, attrs, innerBlocks)
- **`gutenberg-list-blocks`** — list all registered block types available on the site (namespace/name + title)
- **`gutenberg-get-block-schema`** — introspect a block type's full attribute schema and default values; use this before inserting to avoid guessing props
- **`gutenberg-insert-block`** — insert a new block at a positional path inside a post's block tree; best-effort attribute validation with unknown-block warning; every write is audit-logged
- **`gutenberg-update-block`** — deep-merge new attributes into an existing block at a given path without touching sibling props
- **`gutenberg-delete-block`** — remove a block (and its innerBlocks subtree) from a post
- **`gutenberg-move-block`** — relocate a block from one positional path to another within the same post

**Tip:** insert container blocks (group/columns/etc.) via `block.markup` (raw block HTML) rather than the structured form, so wrapper markup is preserved.

### Wave 4b — Gutenberg patterns + reusable blocks (shipped)

Reuse WordPress's built-in primitives for fast page building.

- **`gutenberg-list-patterns`** — list registered block patterns (name, title, categories), filterable by search/category
- **`gutenberg-insert-pattern`** — insert a pattern's blocks into a post at a positional parent path + position
- **`gutenberg-manage-reusable-block`** — create/update/get/list synced (reusable) blocks (`wp_block`); reference one into a post via a `core/block` block

### Wave 6 — WooCommerce store control (shipped)

Full WooCommerce store control — 22 abilities covering the complete store lifecycle. Requires WooCommerce active.

**Products (8)**
- **`woo-store-status`** — check WooCommerce availability and store configuration before starting store work
- **`woo-list-products`** — list products with filtering (status, type, category, search, stock); pagination supported
- **`woo-get-product`** — read a single product's full data (price, stock, images, attributes, variations summary)
- **`woo-upsert-product`** — create or update a product (simple, variable, grouped, external); schema-validated via the WooCommerce CRUD API
- **`woo-delete-product`** — delete or trash a product by ID
- **`woo-manage-variation`** — create, update, or delete a variation on a variable product (price, stock, attributes, images)
- **`woo-manage-product-category`** — create, update, delete, or list product categories; supports parent hierarchy and thumbnail
- **`woo-manage-attribute`** — create, update, delete, or list global product attributes and their terms

**Orders (5)**
- **`woo-list-orders`** — list orders with filtering (status, customer, date range); HPOS-safe
- **`woo-get-order`** — read a single order's full data (line items, billing, shipping, status history)
- **`woo-create-order`** — create a new order programmatically (customer, line items, shipping, billing)
- **`woo-update-order`** — update order status, notes, or metadata; HPOS-safe write
- **`woo-refund-order`** — issue a full or partial refund on an order with optional line-item breakdown

**Customers (3)**
- **`woo-list-customers`** — list customers with search and pagination
- **`woo-get-customer`** — read a customer's full profile, billing/shipping addresses, and order stats
- **`woo-upsert-customer`** — create or update a customer account (billing, shipping, role, meta)

**Marketing & Config (4)**
- **`woo-manage-coupon`** — create, update, delete, or list discount coupons (percent/fixed-cart/fixed-product, usage limits, expiry)
- **`woo-get-settings`** — read whitelisted store settings (currency, tax, checkout) and payment gateway list
- **`woo-update-settings`** — update whitelisted store settings; toggle payment gateway enabled/disabled
- **`woo-manage-review`** — create, update, approve, delete, or list product reviews

**Reports (1)**
- **`woo-get-reports`** — fetch sales summary, top-selling products, and low-stock reports with optional date range

**Storefront bridge (1)**
- **`woo-insert-product-block`** — insert a WooCommerce product shortcode block into any Gutenberg (`core/shortcode`) or Elementor page; accepts product IDs, slugs, or category filters

Built-in skill **`woocommerce-architect`** encodes the store-building loop: check status → perceive existing data → build with schema-validated CRUD → verify.

### Wave 7 — SEO (shipped)

Full on-site SEO control — 19 abilities. Works with **Yoast SEO** or **Rank Math** (auto-detected) or a built-in native mode when neither is active.

**Foundation (4)**
- **`seo-status`** — detect the active SEO plugin (Yoast / Rank Math / native), confirm readiness, and report site-level SEO config
- **`seo-get-meta`** — read on-page SEO meta for any post: title, description, focus keyword, robots directives, and Open Graph fields
- **`seo-set-meta`** — write on-page SEO meta (title, description, focus keyword, robots, OG title/description/image) for any post via the active plugin's own API or native meta
- **`seo-analyze-page`** — score a post's SEO: title + meta-desc length, keyword density, readability, internal links, image alt coverage; returns an actionable recommendations list

**Linking + Research (7)**
- **`seo-suggest-internal-links`** — suggest relevant internal-link opportunities for a post based on keyword overlap with other published posts
- **`seo-insert-internal-link`** — insert an internal link anchor into a post's Gutenberg content at the best-match phrase location
- **`seo-link-audit`** — audit internal and external links across one post or the whole site: broken links, nofollow usage, orphan pages
- **`seo-keyword-research`** — analyse keyword opportunities using on-site data (post titles, meta, headings, search-console if available) and AI scoring; no external paid API required
- **`seo-content-gap`** — identify topics covered by the site that are thin or missing compared to a target keyword list
- **`seo-competitor-analysis`** — compare the site's content coverage and keyword footprint against a given competitor URL (on-site fetch + AI analysis; no external SEO-API dependency)
- **`seo-optimize-content`** — rewrite or augment a post's content to improve keyword placement, heading structure, and readability score; returns a diff-style suggestion

**Technical + Local (5)**
- **`seo-manage-sitemap`** — read, regenerate, or toggle the XML sitemap (Yoast / Rank Math / WP native); return the current sitemap URL and post-type inclusion settings
- **`seo-manage-robots`** — read or write the `robots.txt` virtual file; validate directives before writing
- **`seo-manage-redirects`** — create, update, delete, or list 301/302 redirects via Yoast Premium, Rank Math, or a lightweight native store
- **`seo-manage-schema`** — add, update, or remove JSON-LD schema blocks (Article, FAQ, HowTo, BreadcrumbList, etc.) on any post
- **`seo-manage-local-business`** — create or update a LocalBusiness JSON-LD schema (name, address, geo, hours, phone) for local SEO

**Audit + Setup (3)**
- **`seo-site-audit`** — run a site-wide SEO audit: missing meta, duplicate titles, pages blocked by robots, posts with no internal links, images missing alt text; returns a prioritised issues list
- **`seo-bulk-set-meta`** — apply a meta template rule to multiple posts in a single call; dry-run by default (`apply: true` to commit), bounded by `limit`
- **`seo-quick-setup`** — apply Google-recommended baseline settings in one call: set homepage meta, enable sitemap, set `blog_public`, configure permalinks; idempotent

Built-in skill **`seo-architect`** encodes the ranking loop: audit → keyword-research → on-page meta → internal linking → schema → technical SEO → verify.

### Wave 8 — Content Core (shipped)

The everyday-WordPress layer — 17 abilities so the AI never falls back to raw SQL for basic work.

- **Posts:** `list-posts` (filter by type/status/meta/taxonomy/search, paginated) · `get-post` (full read incl. meta + terms) · `search-content` (full-text with highlight snippets) · `duplicate-post` (Elementor-safe clone)
- **Structure:** `manage-term` (taxonomy terms CRUD) · `register-cpt` / `register-taxonomy` (persisted declarative registration) · `manage-menu` (menus, nested items, theme locations)
- **Media:** `media-list` · `media-get` · `media-update` (alt text!) · `media-delete`
- **Site:** `manage-comment` (moderate/reply) · `option-get` / `option-set` (secret-name deny + self-lockout guard) · `list-users` · **`site-snapshot`** — one call returns the whole site orientation (theme, plugins, content counts, users, menus, detected builders) so an AI session starts informed

### Wave 9 — Site Ops + FSE (shipped)

- **Ops:** `export-content` / `import-content` (WXR) · `manage-cron` · `search-replace` (serialized-data-safe, dry-run default) · `maintenance-mode` · `site-health` (core Site Health tests) · **`db-snapshot`** — create/list/restore/delete gzip DB snapshots before risky changes
- **Block-theme design (FSE):** `theme-json-get` / `theme-json-set` (global styles user layer) · `manage-template` (FSE templates + parts) · `custom-css`

### Wave 10 — Forms + Audits (shipped)

- **Forms (unified adapter):** `form-status` · `form-list` · `form-get-entries` · `form-create` — one API across **Contact Form 7, WPForms, Gravity Forms, Fluent Forms**, unified field types mapped to each plugin's native format
- **Audits:** `security-audit` (core/users/salts/updates checks) · `performance-audit` (autoload bloat, transients, revisions, cache detection — scored 0-100)

### Wave 11 — Ecosystem (shipped)

- **Bricks Builder (foundation):** `bricks-status` · `bricks-list-elements` · `bricks-get-content` (compact tree) · `bricks-set-content` (validated write)
- **Multilingual:** `translation-status` · `duplicate-to-language` — WPML / Polylang auto-detected
- **WooCommerce extras:** `woo-manage-shipping-zone` · `woo-manage-tax-rate` · `woo-manage-payment-gateway` (secrets always masked)
- **Devtools:** `send-email` · `render-page` (fatal-marker + title/h1 probe of any URL) · `list-registry` (post types, taxonomies, shortcodes, roles, hooks, image sizes, REST routes) · `purge-cache` (WP Rocket / LiteSpeed / W3TC / Super Cache / Autoptimize / Elementor unified)

### Wave 12 — Platform (shipped)

- **`self-update`** — the AI checks GitHub for a newer release and applies it in place (confirm-gated); new releases also appear natively in the wp-admin Plugins page like any other plugin update

### Wave 13 — Async jobs (shipped)

- **`job-start` / `job-status` / `job-list` / `job-cancel`** — long operations (whole-DB `search-replace`, site-wide `bulk-post-meta`, `site-audit`) run in the background via WP-Cron, one slice per tick, so they finish instead of dying on a request timeout. Progress is reported as processed/total/percent; jobs are cancellable; the handler registry is filterable for future job types.

### Wave 14 — Universal undo (shipped)

- **`undo-list` / `undo-restore` / `undo-last`** — reversible mutations auto-snapshot their before-state into a capped ring buffer (option-set, custom CSS, theme.json global styles, term updates), so the AI can roll any of them back on demand. Extends the post-revision `content-restore` to targets WordPress keeps no revisions for.

### Wave 15 — Playbooks (shipped)

- **`playbook-run` / `playbook-save` / `playbook-list` / `playbook-delete`** — chain many abilities into one declarative multi-step run ("set up a whole blog" as a single command). A step's result feeds the next via `{steps.<save_as>.<path>}` tokens (a lone token keeps its type, so a post id stays an integer), and `{input.*}` fills playbook inputs. Supports dry-run, per-step continue-on-error, and saved reusable playbooks.

### Wave 16 — Event triggers / webhooks (shipped)

- **`trigger-create` / `trigger-list` / `trigger-delete` / `trigger-log`** — react to the site. Register a trigger on a WordPress event (post published/updated, comment, user registration, WooCommerce order placed / status change, form submission across CF7/WPForms/Gravity/Fluent) that POSTs the payload to a **webhook** (optional HMAC signature), auto-runs a saved **playbook** with the event data as inputs, or **logs** it for the AI to poll. Delivery is async via WP-Cron, so a slow endpoint never blocks checkout or publish.

### Wave 18 — Custom widget generator (shipped)

- **`create-atomic-widget` / `list-atomic-widgets` / `delete-atomic-widget`** — the AI mints REAL Elementor v4 atomic widgets from a declarative spec (props: string/textarea/html/number/boolean/select/image/link + optional Twig/CSS). Generated PHP class + Twig template land under `wp-content/wpultra-widgets/` and register as `wpu-<name>` — placeable, editable, and stylable like any core widget. Correct-by-construction (no caller PHP accepted) and crash-quarantined: a widget that fatals is skipped and flagged, never white-screens the site. Compounds with `ability-write`: the AI mints both its own tools *and* its own widgets.

### Wave 17 — Access control (shipped)

- **`manage-access`** — grant non-admin roles a limited set of abilities/categories, and rate-limit any ability (per-minute, per ability/category/default; admins throttled too, to cap runaway loops). Two-layer enforcement: a relaxed baseline permission plus a per-ability gate on WordPress core's `wp_before_execute_ability`. The policy editor stays admin-only, so a granted role can never widen its own access. Empty by default → unchanged admin-only behaviour.

### Planned
Deeper **Bricks** authoring (schema-driven like the Elementor arc), **JetEngine**, form entries export, IndexNow. The goal: literally do everything in WordPress through AI.

---

## Install & connect

1. **Install:** download the release ZIP (or clone) and put the `wp-ultra-mcp/` directory — including its bundled `vendor/` — into `wp-content/plugins/`, then activate it. (Requires WordPress **6.6+** and PHP **8.0+**. No `composer install` needed — `vendor/` is bundled.)
2. **Enable:** wp-admin → **WP-Ultra-MCP** → toggle **AI control** on.
3. **App password:** click **Generate application password**, copy it (shown once).
4. **Connect:** on the same page, pick your AI client tab and paste the shown config. For the npx-bridge clients:

```json
{
  "mcpServers": {
    "wp-ultra-mcp": {
      "command": "npx",
      "args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
      "env": {
        "WP_API_URL": "https://YOUR-SITE/wp-json/mcp/wpultra",
        "WP_API_USERNAME": "your-wp-username",
        "WP_API_PASSWORD": "the application password"
      }
    }
  }
}
```

The MCP endpoint exposes three meta-tools — `discover-abilities`, `get-ability-info`, `execute-ability` — and the AI uses them to introspect and run any ability. Auth is standard WordPress Application Passwords; revoke anytime from the Connect page.

---

## Security model

Full power, bounded blast radius. Every privileged ability requires the plugin to be **enabled** AND the user to have `manage_options` (super-admin on multisite). SQL is always `$wpdb->prepare`d; destructive verbs need `confirm: true`. File writes are jailed to the WP root and executable files confined to a sandbox dir. WP-CLI runs as an argument array (no shell string). The sandbox safe-mode suspends AI-written PHP after a fatal so a bad write can't take the site down.

---

## Repository layout

```
wp-ultra-mcp/            ← the WordPress plugin (install this)
  wp-ultra-mcp.php       plugin entry point
  includes/
    abilities/           one PHP file per built-in ability
    recipes/             declarative-ability engine (parser, executor, CPT)
    admin/               Connect page + Ability/Skill/Memory Hubs
    skills/  memory/  sandbox/  helpers.php  bootstrap-mcp.php
  vendor/                bundled wordpress/mcp-adapter (GPL)
  bin/                   deploy.ps1, build-zip.ps1
tests/                   zero-dependency PHP test harness (run with: php tests/<name>.test.php)
docs/superpowers/        design specs & implementation plans
```

---

## Develop

```powershell
# run the PHP test suite — any PHP 8.x CLI; no dependencies
#   bash:       for f in tests/*.test.php; do php "$f"; done
#   powershell: Get-ChildItem tests\*.test.php | % { php $_.FullName }

# deploy into a local site for live testing
powershell -File wp-ultra-mcp\bin\deploy.ps1

# build a distributable release zip (vendor bundled)
powershell -File wp-ultra-mcp\bin\build-zip.ps1
```

Contributions welcome — new built-in abilities and skills especially. Open an issue or PR.

## License

[GPL-2.0-or-later](LICENSE). WP-Ultra-MCP bundles the `wordpress/mcp-adapter` and `wordpress/php-mcp-schema` packages (also GPL-2.0-or-later). Free to use, modify, and redistribute.
