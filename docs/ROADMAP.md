# WP-Ultra-MCP — Feature Roadmap (Master List)

Current: **v0.13.0 · 151 abilities** (Waves 1–11 shipped: core, hubs+sandbox, full Elementor arc, Gutenberg, WooCommerce, SEO, custom fields, content core, site ops + FSE, forms + audits, ecosystem).

This is the complete candidate list for what comes next. Items are numbered for easy reference — pick a number, it becomes a wave/plan.

**Recommended order:** `1 → 7 → 12 → 2 → 8`

**Progress tracking:** tick the `[ ]` boxes right here in the file, **or** run `node docs/roadmap-tracker.mjs` and open <http://localhost:4488> — click a task to mark it complete (strikethrough); every click writes back to this file, so the file stays the single source of truth.

---

## 🏗️ Platform Power (makes the plugin itself stronger)

- [x] ~~**1 · `self-update`**~~ — ✅ SHIPPED v0.14.0. GitHub release check + confirm-gated in-place update (API-403 redirect fallback) + native wp-admin Plugins-page update UI. Both live sites self-updated to 0.14.0 via the new ability.
- [ ] **2 · Async job runner (`job-start` / `job-status` / `job-cancel`)** — Long tasks (bulk meta, imports, site-wide audits, big search-replace) run in the background via cron/loopback instead of dying on MCP request timeouts.
- [ ] **3 · Universal undo / rollback** — Auto-snapshot before option/term/menu/template/widget mutations + an `undo-last` ability. Extends the existing post-revision `content-restore` to everything.
- [ ] **4 · Recipe playbooks (multi-step)** — Chain many steps in one declarative recipe — "set up a whole blog" as a single command. Today's recipe engine is single-action.
- [ ] **5 · Webhook / event triggers** — On order placed / form submitted / post published → notify the AI or auto-run a recipe.
- [ ] **6 · Rate-limits + per-ability roles** — Grant non-admin roles a limited ability set; throttle abuse.

## 🎨 Builders

- [ ] **7 · Bricks deep wave** — Element schema introspection, validate, add/edit/delete/move element, global classes, blueprints — the same reliability arc Elementor got. Current 4 Bricks abilities are foundation-only and unverified against a live Bricks install.
- [ ] **8 · `create-atomic-widget`** — AI code-generates a custom Elementor atomic widget (Novamira Pro's signature feature). Compounds with `ability-write`: the AI mints both its own tools *and* its own widgets.
- [ ] **9 · `elementor-clone-url`** — Rebuild a full Elementor page from a URL/screenshot in one ability (the proven manual clone workflow, productized).
- [ ] **10 · Elementor Pro surface** — Theme-builder templates, popups, Pro forms.
- [ ] **11 · Divi / Beaver Builder / Oxygen foundation** — Starter set (status/get/set/list) like the Bricks foundation.

## 🧩 Content & Fields

- [ ] **12 · JetEngine wave** — CPTs, meta boxes, relations, listings. JetEngine 3.4.6 is live on a connected production site, so this is finally live-testable (it was dropped from Wave 5 for lack of a test install).
- [ ] **13 · ACF Pro deep** — Repeater / flexible-content / group nested read-write.
- [ ] **14 · `media-generate`** — AI image API → media library → set as featured image, one flow.
- [ ] **15 · Image editing** — Resize/crop/convert/optimize; bulk AI alt-text fill.
- [ ] **16 · Translation auto-fill** — After `duplicate-to-language`, actually place AI-translated content (WPML/Polylang).
- [ ] **17 · Content calendar** — Plan/list/reschedule scheduled posts in one view.

## 🛒 WooCommerce

- [ ] **18 · CSV / bulk product import-export**
- [ ] **19 · Subscriptions + Bookings** — When those plugins are present.
- [ ] **20 · Abandoned-cart / stock-alert reports**
- [ ] **21 · Woo email template customization**

## 🌐 Site Ops+

- [ ] **22 · Full-site backup + restore** — Files + DB in one archive (extends `db-snapshot`).
- [ ] **23 · Staging clone** — Clone site → subdirectory/subdomain staging.
- [ ] **24 · Multisite network abilities** — create-site, network settings.
- [ ] **25 · htaccess / nginx rules manager** — Beyond redirects: caching + security headers.
- [ ] **26 · User activity log reader** — Who did what, surfaced to the AI.

## 📊 Marketing & Integration

- [ ] **27 · Form → webhook/CRM bridge** — Auto-forward entries.
- [ ] **28 · Newsletter plugins** — MailPoet / MC4WP abilities.
- [ ] **29 · Analytics reader** — Expose Site Kit / GA data (Site Kit live on a connected site).
- [ ] **30 · IndexNow ping + 404 monitor** — SEO extension.
- [ ] **31 · Social auto-post** — Trigger share plugins on publish.

## 🤖 AI-Native (deepens the moat)

- [ ] **32 · Skill marketplace sync** — Auto-import community skills from a GitHub repo.
- [ ] **33 · `site-brain`** — Memory + snapshot fused into a living per-site knowledge base for the AI.
- [ ] **34 · Self-healing v2** — On fatal: auto-rollback the last change + hand the AI a structured error report.
- [ ] **35 · Usage analytics dashboard** — Per-ability usage/failure charts in wp-admin.

---

*Generated 2026-07-02 after shipping v0.13.0. Local file — commit/push intentionally left to a human decision (it reveals the roadmap publicly).*
