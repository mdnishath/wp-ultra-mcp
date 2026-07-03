# WP-Ultra-MCP — Feature Roadmap (Master List)

Current: **v0.13.0 · 151 abilities** (Waves 1–11 shipped: core, hubs+sandbox, full Elementor arc, Gutenberg, WooCommerce, SEO, custom fields, content core, site ops + FSE, forms + audits, ecosystem).

This is the complete candidate list for what comes next. Items are numbered for easy reference — pick a number, it becomes a wave/plan.

**Recommended order:** `1 → 7 → 12 → 2 → 8`

**Progress tracking:** tick the `[ ]` boxes right here in the file, **or** run `node docs/roadmap-tracker.mjs` and open <http://localhost:4488> — click a task to mark it complete (strikethrough); every click writes back to this file, so the file stays the single source of truth.

---

## 🏗️ Platform Power (makes the plugin itself stronger)

- [x] ~~**1 · `self-update`**~~ — ✅ SHIPPED v0.14.0. GitHub release check + confirm-gated in-place update (API-403 redirect fallback) + native wp-admin Plugins-page update UI. Both live sites self-updated to 0.14.0 via the new ability.
- [x] ~~**2 · Async job runner (`job-start` / `job-status` / `job-list` / `job-cancel`)**~~ — ✅ SHIPPED v0.15.0. WP-Cron slice-per-tick (loopback-kicked); built-in types search-replace / bulk-post-meta / site-audit; confirm-gated, cancellable, filterable registry. Verified end-to-end on a live site (real cron processed a site-audit to done).
- [x] ~~**3 · Universal undo / rollback**~~ — ✅ SHIPPED v0.16.0. Auto-snapshot ring buffer before option-set / custom-css / theme.json / term-update; `undo-list` / `undo-restore` / `undo-last`. Absent-sentinel undoes a newly-created option by deleting it. Live round-trip verified in production.
- [x] ~~**4 · Recipe playbooks (multi-step)**~~ — ✅ SHIPPED v0.17.0. `playbook-run` / `playbook-save` / `playbook-list` / `playbook-delete` chain many abilities; `{input.*}` + `{steps.<save_as>.<path>}` token passing (lone token keeps type); dry-run, continue-on-error, saved playbooks, no-nesting guard. Verified live in production (create → publish via captured id).
- [x] ~~**5 · Webhook / event triggers**~~ — ✅ SHIPPED v0.18.0. `trigger-create` / `trigger-list` / `trigger-delete` / `trigger-log` on post/comment/user/Woo-order/form events → webhook (HMAC) / auto-playbook / log. Async via WP-Cron. Verified live in production (real publish → trigger logged).
- [x] ~~**6 · Rate-limits + per-ability roles**~~ — ✅ SHIPPED v0.19.0. `manage-access` (admin-only) grants non-admin roles a limited ability/category set + per-minute rate limits (per ability/category/default). Two-layer: relaxed baseline + per-ability gate on `wp_before_execute_ability`. Live-verified. **Entire Platform Power tier (#1-#6) complete.**

## 🎨 Builders

- [x] ~~**7 · Bricks deep wave**~~ — ✅ SHIPPED v0.24.0. 8 abilities: add/edit/delete/move element (dual parent↔children consistency + cycle guard), validate (+registry check), get-element-schema, manage-global-class, insert-blueprint (5 skeletons). Live 10-step chain verified on real WP; schema introspection awaits a live Bricks install. **🎨 Builders tier (#7–#11) COMPLETE.**
- [x] ~~**8 · `create-atomic-widget`**~~ — ✅ SHIPPED v0.20.0. Declarative spec → real Elementor v4 atomic widget (PHP+Twig+CSS, element type `wpu-<name>`), correct-by-construction + crash-quarantined. Live-verified: minted → registered → placed → rendered (0 drops) → quarantine/regen cycle proven.
- [x] ~~**9 · `elementor-clone-url`**~~ — ✅ SHIPPED v0.21.0. One call: AI brief (or static-URL auto-extract) → tokens + adaptive blueprint sections + section colors via global classes + strict validation + atomic write + render-check. Live-verified: 26/26 nodes rendered, 0 dropped.
- [x] ~~**10 · Elementor Pro surface**~~ — ✅ SHIPPED v0.22.0. `elementor-pro-status` / `elementor-manage-library` (theme-builder templates + display conditions) / `elementor-manage-popup` (friendly triggers → native settings) / `elementor-form-submissions` (e_submissions reader). Verified against live Pro 4.1.2 in production (create→conditions→delete cycle + real submissions read).
- [x] ~~**11 · Divi / Beaver Builder / Oxygen foundation**~~ — ✅ SHIPPED v0.23.0. Unified `pagebuilder-status/get-content/set-content/list-elements` adapter domain: Divi shortcode parser/serializer (round-trip tested), Beaver flat-node↔tree with validation, Oxygen 4 JSON trees. **Builders tier (#7–#11) — #7 Bricks deep বাদে সব done.**

## 🧩 Content & Fields

- [x] ~~**12 · JetEngine wave**~~ — ✅ SHIPPED v0.25.0. `jetengine-status` / `-manage-cpt` / `-manage-taxonomy` / `-manage-meta-box`. **Production-proven on live JetEngine 3.4.6**: created a CPT with 3 typed fields → JetEngine itself registered it next request (post_type_exists=true) → full round-trip get → delete. Relations/listings inventoried read-only.
- [x] ~~**13 · ACF Pro deep**~~ — ✅ SHIPPED v0.26.0. `field-manage-rows` — repeater/flexible/group row ops, production-proven on live SCF 6.9 (full set/add/update/delete cycle, exact ACF meta format verified).
- [x] ~~**14 · `media-generate`**~~ — ✅ SHIPPED v0.26.0. `media-generate` — url/base64 or server-side OpenAI prompt (wpultra_openai_api_key) → media library + alt + featured, one call.
- [x] ~~**15 · Image editing**~~ — ✅ SHIPPED v0.26.0. `media-edit-image` (ordered resize/crop/rotate/convert/quality ops) + `media-bulk-alt` (list-missing → AI writes → set). Live-verified resize+webp.
- [x] ~~**16 · Translation auto-fill**~~ — ✅ SHIPPED v0.26.0. `translation-set-content` — JSON-safe find→replace inside Elementor data + title/content/meta. Bengali/quotes/backslash round-trip proven.
- [x] ~~**17 · Content calendar**~~ — ✅ SHIPPED v0.26.0. `content-calendar` — day-grouped schedule, reschedule, spread drafts evenly. Live-verified.

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
