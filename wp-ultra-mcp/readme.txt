=== WP-Ultra-MCP ===
Contributors: wpultra
Tags: mcp, ai, elementor, wp-cli, automation
Requires at least: 6.6
Requires PHP: 8.0
Stable tag: 0.23.0
License: GPLv2 or later

Turn this WordPress site into an MCP server for AI CLIs (Claude Code, Gemini): raw SQL, WP-CLI, files, execute-php, persistent memory, WP content, skills, and schema-driven Elementor v4 layout control.

== Description ==

WP-Ultra-MCP transforms any WordPress site into a full Model Context Protocol (MCP) server. AI CLI clients like Claude Code and Gemini CLI connect directly to your site, gaining the ability to read and write files, run SQL queries, execute WP-CLI commands, manage WordPress content, run arbitrary PHP, store persistent memory, and invoke reusable skill prompts — all from within the AI session.

Install, enable AI control, generate an application password, and paste the config into your AI client. Then drive your whole WordPress site from the AI.

**Wave 1 abilities (shipped):**
- `wpultra/read-file` / `wpultra/write-file` / `wpultra/edit-file` / `wpultra/delete-file` / `wpultra/list-directory` — jailed filesystem ops
- `wpultra/run-wp-cli` — any WP-CLI command inside the WP root
- `wpultra/execute-wp-query` — parameterized SQL with SELECT/destructive gating
- `wpultra/execute-php` — run arbitrary PHP in the WP context, capture output + return value
- `wpultra/read-debug-log` — tail the WordPress debug.log
- `wpultra/memory-save` / `wpultra/memory-get` / `wpultra/memory-list` / `wpultra/memory-delete` — persistent keyed memory across sessions
- `wpultra/create-post` / `wpultra/update-post` / `wpultra/delete-post` — WordPress content CRUD
- `wpultra/skill-get` / `wpultra/skill-write` / `wpultra/skill-edit` / `wpultra/skill-delete` — reusable skill prompt management
- Admin: top-level **WP-Ultra-MCP** menu — Connect page (enable AI control, generate app password) and Abilities page (enable/disable individual abilities)

**Wave 2 (shipped):** schema-driven Elementor v4 atomic layout control — 9 elementor-* abilities. Requires the `e_atomic_elements` experiment enabled in Elementor.

**Wave 4a (shipped):** Gutenberg core block control — 7 abilities for positional-path block tree ops + block-type discovery (gutenberg-get-content, gutenberg-list-blocks, gutenberg-get-block-schema, gutenberg-insert-block, gutenberg-update-block, gutenberg-delete-block, gutenberg-move-block). Core WordPress APIs only, no browser tab.

**Wave 4b+ (planned):** Gutenberg patterns/reusable blocks, FSE template control, Bricks Builder support, ACF/Meta Box/Pods field-plugin integration.

== Installation ==
1. Upload the release ZIP (with vendor/) and activate.
2. Go to the top-level **WP-Ultra-MCP** menu in wp-admin → Connect page. Enable AI control and generate an application password.
3. Copy the client config into Claude Code / Gemini and restart the MCP session.

== Frequently Asked Questions ==

= Does this require Elementor? =
No. All Wave 1 abilities work without Elementor. The Wave 2 elementor-* abilities require Elementor (free or Pro) with the `e_atomic_elements` experiment enabled.

= Is it safe to leave AI control enabled permanently? =
AI control is disabled by default. Enable it only when you need it. The SQL ability automatically classifies queries as destructive and requires `confirm: true` before executing them. Queries are always considered destructive if they contain `DROP`, `TRUNCATE`, or `ALTER`. `DELETE` and `UPDATE` are treated as destructive only when they are missing a `WHERE` clause. `INSERT` is never gated.

= Does it work with any MCP client? =
Any client that implements the Model Context Protocol 2025 spec. Claude Code and Gemini CLI are tested.

== Changelog ==

= 0.23.0 =
* Divi / Beaver Builder / Oxygen foundation (Wave 21): unified `pagebuilder-status` / `pagebuilder-get-content` / `pagebuilder-set-content` / `pagebuilder-list-elements` with per-builder adapters (auto-detected driver, explicit `builder` override). Divi: full shortcode-tree parser/serializer ([et_pb_*] with attrs, leaf content, balance validation) writing post_content + enabling the builder. Beaver Builder: flat node map ↔ nested tree (parent/position), validation (ids, parents, module slugs), object-settings storage in _fl_builder_data + cache clear. Oxygen: 4.x ct_builder_json component tree with root wrapper, duplicate-id validation, 3.x shortcodes read-only. Everything degrades gracefully when no builder is installed. 176 → 180 abilities.

= 0.22.0 =
* Elementor Pro surface (Wave 20): `elementor-pro-status` (version, template counts, popups + conditions, form totals), `elementor-manage-library` (theme-builder templates: list/get/create/delete + display conditions in Pro's native include/exclude format, with conditions-cache flush), `elementor-manage-popup` (friendly trigger options — on_click / page_load delay / scroll percent / exit_intent / inactivity / show_times — mapped to Pro's native display settings, plus conditions), `elementor-form-submissions` (read Pro form submissions from the e_submissions tables: distinct forms, filtered lists with flattened field values, get/mark-read/delete). Storage layout verified against a live Pro 4.1.2 install; everything degrades gracefully without Pro. 172 → 176 abilities.

= 0.21.0 =
* One-call page cloner (Wave 19): `elementor-clone-url`. Builds a whole Elementor v4 page from a reference in one call — mints design-token Variables, composes adaptive blueprint sections (navbar/hero/feature-grid/cta/footer/custom) filled with real content, styles sections via global classes (background/text color), validates strictly, writes atomically, and returns a render-check + preview URL. Preferred mode: the AI perceives the reference URL/screenshot itself and passes a structured brief; `url` mode fetches static HTML server-side and derives a rough brief (headings/paragraphs/buttons/palette/fonts heuristics, with a JS-rendered warning). Productizes the proven clone workflow the elementor-v4-architect skill describes. 171 → 172 abilities.

= 0.20.0 =
* Custom widget generator (Wave 18): `create-atomic-widget` / `list-atomic-widgets` / `delete-atomic-widget`. The AI describes a widget declaratively (name, title, props: string/textarea/html/number/boolean/select/image/link, optional Twig template + stylesheet) and gets a REAL Elementor v4 atomic widget — generated PHP class + Twig + CSS under wp-content/wpultra-widgets/, registered as element type `wpu-<name>`, placeable/stylable like any core widget. Generated code comes only from the plugin's own templates (no caller PHP; Twig rejects script/PHP); a widget file that fatals is auto-quarantined and skipped instead of white-screening the site (visible as status "crashed", healed by regenerate). 168 → 171 abilities.

= 0.19.0 =
* Access control (Wave 17): `manage-access` — grant non-admin roles a limited set of abilities/categories, and set per-minute rate limits (per ability, per category, or a default; admins throttled too, to cap runaway loops). Enforced in two layers: a relaxed baseline permission (admin, or a role holding at least one grant) plus a per-ability gate on core's `wp_before_execute_ability` that denies ungranted abilities and over-limit calls. The policy editor itself stays admin-only, so a granted role can never widen its own access. Empty policy by default = unchanged admin-only behaviour. 167 → 168 abilities.

= 0.18.0 =
* Event triggers / webhooks (Wave 16): `trigger-create` / `trigger-list` / `trigger-delete` / `trigger-log`. Register a trigger on a WordPress event (post published/updated, comment posted, user registered, WooCommerce order placed / status changed, form submitted via CF7/WPForms/Gravity/Fluent) that fires an action — POST the event payload to a webhook (optional HMAC signature), auto-run a saved playbook with the event data as inputs, or just log it for the AI to poll. Delivery is async (WP-Cron) so a slow endpoint never blocks checkout/publish. 163 → 167 abilities.

= 0.17.0 =
* Playbooks (Wave 15): `playbook-run` / `playbook-save` / `playbook-list` / `playbook-delete`. Chain many existing abilities into one declarative multi-step run — "set up a whole blog" as a single command. Each step calls an ability with params that can reference the playbook's `inputs` ({input.key}) and any earlier step's result ({steps.<save_as>.<path>}); a lone {token} keeps its type so a post id stays an integer across steps. Supports dry-run (resolve + validate without executing), per-step continue-on-error, and saved reusable playbooks. Nesting playbook-run is blocked. 159 → 163 abilities.

= 0.16.0 =
* Universal undo (Wave 14): `undo-list` / `undo-restore` / `undo-last`. Reversible mutations now auto-snapshot their before-state into a capped ring buffer — option-set (value or absence), custom-CSS, theme.json global styles, and term updates — so the AI can roll any of them back on demand. Extends the post-revision `content-restore` to targets WordPress keeps no revisions for. 156 → 159 abilities.

= 0.15.0 =
* Async job runner (Wave 13): `job-start` / `job-status` / `job-list` / `job-cancel` — long operations run in the background via WP-Cron (one slice per tick, kicked immediately via loopback) instead of dying on request timeouts. Built-in job types: `search-replace` (serialized-safe, whole DB), `bulk-post-meta` (set a meta value across every matching post), `site-audit` (walk all posts, collect SEO issues). Confirm-gated where destructive; cancellable; progress reported as processed/total/percent. Handler registry is filterable for future job types. 152 → 156 abilities.

= 0.14.0 =
* `self-update` ability — check GitHub for a newer release (`action: check`) or apply it in place (`action: update`, confirm-gated); the AI can now keep the plugin current on any connected site.
* Native update UI — new releases appear in the wp-admin Plugins page like any other plugin update (update_plugins transient integration, `Update URI` header). 151 → 152 abilities.

= 0.13.0 =
* Content Core (Wave 8): `list-posts`, `get-post`, `search-content`, `duplicate-post`, `manage-term`, `register-cpt`, `register-taxonomy`, `manage-menu`, `media-list/get/update/delete`, `manage-comment`, `option-get/set` (sensitive-name + self-lockout guards), `list-users`, and `site-snapshot` — one-call site orientation for AI clients.
* Site Ops + FSE (Wave 9): `export-content`/`import-content` (WXR), `manage-cron`, serialized-data-safe `search-replace` (dry-run default), `maintenance-mode`, `site-health`, `db-snapshot` (create/list/restore/delete, gzip, protected dir), `theme-json-get/set`, `manage-template` (FSE templates/parts), `custom-css`.
* Forms + Audits (Wave 10): unified `form-status/list/get-entries/create` across Contact Form 7, WPForms, Gravity Forms, Fluent Forms; `security-audit` and `performance-audit` with scored findings.
* Ecosystem (Wave 11): Bricks builder foundation (`bricks-status/list-elements/get-content/set-content`), WPML/Polylang `translation-status` + `duplicate-to-language`, Woo `manage-shipping-zone`/`manage-tax-rate`/`manage-payment-gateway` (secret masking), `send-email`, `render-page`, `list-registry`, `purge-cache`. 104 → 151 abilities.

= 0.12.0 =
* Power features: `media-upload` (URL/base64 → media library), `manage-user` (create/update/role/delete), `manage-plugin-theme` (install/activate/update), `content-restore` (revision-based undo/rollback), `pods-define-fields`, and a self-improvement layer — `self-test` diagnostics + per-ability usage/failure stats. 96 → 104 abilities.
* Hardening & bug-fix pass (~45 confirmed fixes across all subsystems): sandbox IIS web.config + NTFS-stream jail, SQL OUTFILE/comment-verb gating, recipe PHP-injection closed (inputs encoded as literals); `wp_slash` on every post/meta write path (Gutenberg, Elementor, SEO links, posts, skills, memory, recipes) so backslashes/JSON survive; Gutenberg innerContent placeholder integrity (insert/move/update no longer drop children); Elementor atomic cache-clear + JSON-encode guard + round-trip system-key preservation; WooCommerce refund-aware/​capped reports, money clamping, address tax recalc, settings validation; SEO `<title>` XSS, duplicate-canonical, multi-hop redirect-loop, and Bengali/CJK-aware length + word counts; Meta Box read/write revived. Full test suite (38 files) green.

= 0.11.0 =
* Wave 7 — SEO: 19 new abilities for full on-site SEO. Works with Yoast or Rank Math (auto-detected) or a built-in native mode. On-page meta (title/description/focus keyword/robots/OG), page scoring + content optimization, internal-link suggestions/insertion/audit, keyword research + content-gap + competitor analysis (on-site + AI, no external API), technical SEO (sitemap, robots, 301/302 redirects, JSON-LD schema), LocalBusiness structured data, a site-wide SEO audit, rule-based bulk meta, and a Google-recommended quick-setup. New seo-architect skill encodes the ranking loop.

= 0.10.0 =
* Wave 6 — WooCommerce: 22 new abilities for full store control. Products (simple/variable/grouped/external + variations, categories, global attributes), HPOS-safe orders (create/update/status/refund) + customers, coupons, whitelisted store settings + payment-gateway toggle, product reviews, sales/top-product/low-stock reports, and a storefront bridge that inserts product blocks into Gutenberg/Elementor pages as shortcodes. New woocommerce-architect skill encodes the store-building loop. All schema-validated, all via the WooCommerce CRUD API.

= 0.9.0 =
* Wave 4b — Gutenberg patterns + reusable/synced blocks.
* `gutenberg-list-patterns` — list registered block patterns (name, title, categories), filterable by search/category.
* `gutenberg-insert-pattern` — insert a registered pattern's blocks into a post at a positional parent path + position.
* `gutenberg-manage-reusable-block` — create/update/get/list synced (reusable) blocks (the `wp_block` CPT); reference one in a post by inserting a `core/block` block via `gutenberg-insert-block`.

= 0.8.0 =
* Phase B2 — Elementor blueprints: insert validated structural section skeletons (navbar, hero, feature-grid, cta, footer) with fresh ids.
* `elementor-list-blueprints` — list available built-in blueprints with descriptions.
* `elementor-insert-blueprint` — insert a blueprint skeleton (layout + placeholder text only, no styling) at a given parent and position; re-ids for the page, validates, and writes atomically. Style with design tokens + global classes afterward.

= 0.7.1 =
* Phase C — rewrote the built-in `elementor-v4-architect` skill to encode the proven atomic authoring loop: perceive reference → apply design tokens → introspect schema → build with raw scalars → validate (dry-run) → set-content (strict) → render-check → compare. Documents the non-obvious rules that prevent broken designs (pass raw scalars not hand-wrapped `{$$type}`; visual styling lives in global classes, not widget settings; experiments auto-enable and apply next request) and how design tokens flow into elements via global classes.
* Removed the obsolete `elementor-architect` skill that referenced non-existent abilities (elementor-schema/set-layout/patch-element).

= 0.7.0 =
* Phase B — Elementor design tokens: create color/font/size Variables from a perceived reference's palette/fonts/sizes and get back `{$$type,value}` refs to use in atomic builds.
* `elementor-apply-design-tokens` — apply a reference's design palette, typography system, and token sizes as Elementor Variables; returns refs for token-consistent, reference-faithful designs.

= 0.6.1 =
* Elementor abilities now auto-enable the required "Editor V4 / atomic elements" experiment — on plugin activation (when Elementor is present) and on first use. No more manual experiment toggle before the Elementor tools work.
* Honest runtime message: if the experiment is flipped on mid-request, Elementor applies it on the next request, so the ability asks you to re-run rather than reporting a misleading failure.

= 0.6.0 =
* Phase A: Reliable Elementor builds — schema validation before every write + server-side render check.
* `elementor-validate` — dry-run schema validation of Elementor pages/sections/containers; validates atomic settings, container layout props, and detects silently-dropped props before write.
* `elementor-render-check` — server-side render verification: confirms what actually rendered after set-content/add/edit/move (catches design breakage from dropped props).
* Elementor writes now validate before commit: `elementor-set-content` enforces strict atomic-settings validation (with `force:true` escape hatch); container layout properties always validated to prevent broken designs.

= 0.5.0 =
* Wave 4a — Gutenberg core block control via positional-path tree ops + block-type discovery; core WordPress APIs only, no browser tab.
* `gutenberg-get-content` — read a post's block tree as a compact JSON array (type, attrs, innerBlocks).
* `gutenberg-list-blocks` — list all registered block types available on the site (namespace/name + title).
* `gutenberg-get-block-schema` — introspect a block type's full attribute schema and default values.
* `gutenberg-insert-block` — insert a new block at a positional path inside a post's content; best-effort attribute validation with unknown-block warning; container blocks with children should be inserted via `block.markup` (raw block HTML) to preserve wrapper markup.
* `gutenberg-update-block` — deep-merge new attributes into an existing block at a given path.
* `gutenberg-delete-block` — remove a block (and its innerBlocks subtree) from a post.
* `gutenberg-move-block` — relocate a block from one positional path to another within the same post.

= 0.4.0 =
* Activity log — a capped audit trail of every privileged action (PHP, WP-CLI, SQL writes, file writes/edits/deletes, HTTP recipes): who ran it, when, and the outcome. New "Activity" admin page with a one-click clear.
* Per-category capability toggles — switch a whole group (Filesystem, Code Execution, Database, Elementor, etc.) off from the Abilities page; disabled categories' abilities never register.
* Repo cleanup — removed the archived Node/stdio prototype (src/, npm tooling) that was superseded by the plugin and no longer shipped.

= 0.3.2 =
* Security & robustness hardening pass (no new abilities):
* Fixed stored XSS in the Ability/Skill/Memory Hubs (AI-written names are now esc_js-escaped in the delete confirm dialog).
* delete-file now refuses wp-config.php and other site-critical files, not just core directories.
* Closed sandbox executable-extension bypasses (.phtml/.php5/.pht/trailing dot/space) and the sandbox dir now ships a deny-.htaccess + index.php so written code can't be run by URL.
* run-wp-cli drains stdout/stderr concurrently (no more 64KB-buffer deadlock) and enforces a wall-clock timeout.
* SQL destructive-query gate switched to an allow-list (DELETE/UPDATE always need confirm; GRANT/RENAME/CTE/unknown verbs too).
* http recipes use wp_safe_remote_request (SSRF protection against internal hosts).
* read-file streams up to max_bytes instead of loading whole files (OOM fix); paths with control/null bytes are rejected.
* Elementor: collision-checked element ids, depth-guarded tree walk, CSS cache cleared after global-class upsert, real version stamp only.
* Content abilities validate post_type, refuse the plugin's internal CPTs, and memory-save won't clobber a non-memory post.
* New WPULTRA_RECIPE_RUN_TYPES constant (and wpultra_recipe_allowed_run_types filter) to lock down which declarative-recipe run types are allowed — e.g. define('WPULTRA_RECIPE_RUN_TYPES','http') to stop the AI minting php/sql/wp-cli recipes. Disabled types are neither registered nor executable. Default: all allowed (unchanged behavior).

= 0.3.1 =
* Wave 3.5 — Elementor global classes (list/upsert/apply) + element interactions/entrance animations; 4 abilities.

= 0.3.0 =
* Wave 3 — Elementor design systems: global colors/typography, design-token variables, dynamic-tag discovery (4 abilities: elementor-get-design-system, elementor-list-dynamic-tags, elementor-manage-global-colors, elementor-manage-variables).

= 0.2.0 =
* Wave 2 — schema-driven Elementor v4 engine: list-widgets, get-widget-schema, get-style-schema, get-content, set-content, add/edit/delete/move-element (9 abilities). Built-in elementor-v4-architect skill.

= 0.1.0 =
* Initial Wave 1 release: files, WP-CLI, SQL, execute-php, diagnostics, memory, WP-content, skills, admin UI.

== Upgrade Notice ==

= 0.1.0 =
Initial release.
