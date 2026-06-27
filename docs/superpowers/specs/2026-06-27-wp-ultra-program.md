# WP-Ultra-MCP — Program Spec (full Novamira free+PRO killer)

**Date:** 2026-06-27
**Status:** Active — supersedes `2026-06-27-wp-ultra-plugin-design.md` (which covered only the free-tier wedge).
**Goal:** A free, open-source WordPress plugin that matches **and beats** Novamira **free + PRO** across the whole surface: schema-driven Elementor (v3+v4 atomic), dynamic data binding, design systems, Gutenberg, Bricks, six custom-field plugin integrations, memory, WP content, WP-CLI, raw SQL, files, execute-php, and skills.

**Delivery model:** Waves. Each wave ships working, tested, installable software with its own implementation plan. "Everything" is the destination; waves are how we get there without shipping broken features. Earlier waves are usable on their own.

---

## 1. Non-negotiable architecture (unchanged, proven)

Bundle the official `wordpress/mcp-adapter` (vendored from Novamira). Register WordPress **abilities** (`wp_register_ability`); the adapter exposes them as MCP tools/resources/prompts at `/wp-json/mcp/wpultra`. Auth = WordPress Application Passwords. We write abilities + engines + admin + skills only. Permission gate: enabled-flag + `manage_options`. Files jailed to a base dir; executable files sandboxed. SQL parameterized. This is identical to the prior spec and validated against Novamira's working code.

---

## 2. The Elementor engine — schema-driven (this is the moat; designed from Novamira's techniques)

The prior "write raw `_elementor_data` JSON" approach is **rejected** — it cannot produce valid widgets. The engine must introspect Elementor's own registry and validate, exactly where Novamira's value lives, plus our improvements.

### 2.1 Schema introspection (`includes/elementor/schema.php`)
- `wpultra_el_widget_schema(string $type): array` — resolve the widget via `Plugin::$instance->widgets_manager->get_widget_types($type)`; for **v3** walk `$widget->get_controls()`; for **v4 atomic** call `$widget::get_props_schema()`. Detect atomic via `instanceof Elementor\Modules\AtomicWidgets\Elements\Base\Atomic_Widget_Base`.
- Produce a **compact schema** per control: `{t, opts?, def?, r?, fields?}` (drop structural/style controls unless requested). For v4, surface the `$$type` key each value must wrap as; surface enums hidden in `Union_Prop_Type` string alternatives.
- `wpultra_el_style_schema()` — pass through `Elementor\Modules\AtomicWidgets\Styles\Style_Schema::get()`; describe Size units, Object shapes, Union alternatives.
- `wpultra_el_list_widgets(filters)` — enumerate `get_widget_types()` with category + source package + `is_atomic`. Progressive disclosure (names + atomic flag only by default).
- **Our delta — schema cache:** memoize per `widget_type + ELEMENTOR_VERSION` in a transient (Novamira re-introspects every call).

### 2.2 Value coercion + validation (`includes/elementor/validate.php`)
- **v4 atomic auto-wrap:** scalar `"#fff"` → `{"$$type":"color","value":"#fff"}`. Union resolution picks the right `$$type` for ambiguous inputs (string vs size vs link/image object). Fill required atomic defaults so the node loads in the editor.
- **v3 path:** classify each settings key as `drop`/`accept_raw`/`validate_against`; resolve responsive suffixes (`padding_tablet`) to a base control + breakpoint range; check enum/switcher values.
- **Two-layer v4 validation:** our shallow coercion, then pass through Elementor's own `Props_Parser::validate()` when available (their proven moat).
- **Error shape:** `{ok, settings, dropped:[], invalid:[{key,value,opts}]}` and **embed the compact schema of any violating widget in the error** so the AI self-corrects in one round-trip.
- **Our delta — conditional (`if`) validation:** only validate controls whose `if` conditions resolve true given current settings (Novamira validates all, warning on invisible controls).

### 2.3 Read/write (`includes/elementor/engine.php`)
- `wpultra_el_read(post_id, {element_id?, full?})` — compact skeleton (id/elType/widgetType/children) by default; single-element drill with full settings; full dump opt-in. Decode `interactions` JSON-string on read.
- `wpultra_el_write(post_id, elements)` — **critical:** if the tree contains any atomic element, **bypass `Document::save()`** (it silently strips atomic widgets) and write `_elementor_data` meta directly with `wp_slash(wp_json_encode())`; else use the Document API. Set `_elementor_edit_mode`, `_elementor_template_type`, `_elementor_version`.
- **Full CSS invalidation:** `files_manager->clear_cache()` + delete `_elementor_css` + `do_action('elementor/atomic-widgets/styles/clear', …)` (local/frontend/preview) + `clean_post_cache()`.
- **Our delta — ETag conflict detection:** snapshot a content hash on read; `wpultra_el_write` rejects if the stored hash changed unless `force:true` (Novamira clobbers silently).

### 2.4 Editing abilities (Wave 2)
`elementor-list-widgets`, `elementor-get-schema` (widget+style), `elementor-get-content`, `elementor-set-content`, `elementor-add-element` (widget / e-flexbox / e-div-block / container / **tree** insert by `parent_id`+`position`), `elementor-edit-element` (shallow **and our delta: deep**-merge), `elementor-delete-element`, **`elementor-move-element`** (our delta — `from_id`→`to_parent_id`+`position`, preserving subtree/styles/id; Novamira lacks this).

### 2.5 Design systems (Wave 3)
`elementor-manage-dynamic-tags` (list/get/apply — composite-key handling for ACF `field_<hash>:name`, JetEngine `meta_field`, etc.; category↔control matching; v4 `{$$type:"dynamic"}` + v3 `__dynamic__` shortcode formats), `elementor-manage-variables` (v4 color/font/size design tokens, `clamp()`/`calc()` support), `elementor-manage-global-classes` (v4), `elementor-manage-global-styles-v3` (kit colors/typography), `elementor-manage-interactions` (v4). `elementor-create-atomic-widget` (code-gen a custom v4 widget mu-plugin) — **Wave 5**.

---

## 2B. Pillar: Hubs, Sandbox & the ever-growing library (the durable moat)

In the AI age the compounding advantage is not any single feature — it is the **library of skills + abilities**, the **Hubs** that let anyone manage and *extend* them, and the **Sandbox** that makes AI-written code safe. This pillar runs across all waves and must surpass Novamira (which has these but gates/limits them).

### Skill Hub (admin UI over the existing skills system)
We already ship the skills engine (CPT `wpultra_skill`, markdown frontmatter parser, agentic catalog, per-skill MCP prompts, CRUD abilities, built-ins). Missing = the **management UI**:
- Browse all skills (built-in + user) as cards; per-skill toggle for `enable_prompt` / `enable_agentic`.
- **Upload `.md` skills** (drag-drop or file picker) → parsed via the existing parser → stored as `wpultra_skill` CPT.
- Inline edit (frontmatter + body), duplicate, delete, and **export** a skill back to `.md`. Import/export = portability between sites.
- Search + category/tag filtering.

### Ability Hub (extend the Abilities page)
We ship the enable/disable toggle page (Wave 1). Grow it into a true hub:
- Search, group-by-category, per-ability **info drawer** (input/output schema, annotations, example call).
- **Declarative custom abilities — our innovation (Novamira requires PHP):** define an ability in a `.md`/YAML doc with frontmatter (`name`, `description`, `input` schema) + a `run` recipe of type `wp-cli` | `sql` | `php` | `http` and a parameter-substituted `command`/`query`/`code`/`url` template. Upload via the Hub → a generic `wpultra_recipe_ability` executor registers it as a real MCP ability at runtime. Non-coders add full WP capabilities with **zero plugin code**. Recipes are sandbox/permission-gated exactly like the built-in code abilities; destructive recipes inherit the confirm gate.
  ```yaml
  name: woo/empty-cart
  description: Empty a WooCommerce customer's cart
  input: { user_id: { type: integer, required: true } }
  run: wp-cli
  command: ["wc", "cart", "empty", "--user={user_id}"]
  ```
- Stored as a `wpultra_ability` CPT (mirrors the skill CPT); listed in the Hub; toggle/edit/delete/export like skills.

### Memory Hub (admin UI over the existing memory system)
We ship memory abilities + CPT. Add a UI: list/search by type (user/feedback/project/reference), inline view/edit/delete, manual add.

### Sandbox + crash-recovery safe-mode (beat Novamira's safety)
- AI-written executable code (`.php`/`.htaccess`/`*.ini`) is already confined to `WP_CONTENT_DIR/wpultra-sandbox/`. Add the **safety runtime**:
  - A small mu-plugin shim that loads sandbox PHP inside a guard; on a fatal it writes a `.crashed` sentinel.
  - When `.crashed` exists → **safe mode**: all sandbox-loaded code is suspended, an admin notice + a `wpultra/read-debug-log`-driven self-heal prompt is surfaced, and the site stays up. One-click "clear safe mode" after fixing.
  - `execute-php` and recipe `php` runs respect safe mode.

### Ever-growing library (a standing principle, every wave)
Each wave ships new built-in **skills + abilities** toward covering the **whole WP ecosystem** — so "do anything in WordPress" becomes literally true: Elementor, WooCommerce, ACF/JetEngine/Meta Box/Pods, SEO (Yoast/RankMath), media library, users/roles/capabilities, menus, widgets, options, cron/Action Scheduler, comments, taxonomies, multisite. Coverage breadth is tracked as a first-class metric, not an afterthought.

---

## 3. Full feature inventory → waves

**Wave 1 — Foundation (largely the existing plan, Elementor deferred):**
scaffold + vendored adapter + harness; `helpers.php` (path-jail, capability, SQL classifier); MCP bootstrap; filesystem abilities (read/write/edit/delete/list); code (run-wp-cli, execute-php); database (execute-wp-query); diagnostics (read-debug-log); **memory** (CPT `wpultra_memory` + save/get/list/delete); **WP content** (create/update/delete-post incl. meta, taxonomy terms — our delta vs PRO which lacks taxonomy assignment — featured image); skills system (CPT + parser + catalog + prompts + CRUD + built-ins); admin connect + abilities pages. Ships an installable, useful MCP server.

**Wave 2 — Elementor core (the headline):** schema introspection + caching; value coercion + two-layer validation; read/write with atomic-bypass + full cache invalidation + ETag; editing abilities incl. `move-element` and deep-merge. Beats PRO Elementor on its core surface, free.

**Wave 3 — Elementor design systems:** dynamic tags, variables, global classes, global styles v3, interactions.

**Wave 4 — Gutenberg (full) + Bricks:** upgrade Gutenberg beyond dynamic-only (block schema introspection); Bricks builder engine (element tree + dynamic data, mirroring the Elementor approach).

**Wave 5 — Custom fields + advanced:** ACF, then JetEngine / Meta Box / Pods / ACPT / ASE integrations (check-setup / list+get field groups / read+write values / manage field groups), using a shared `target {type:post|user|term|options,id}` abstraction; `create-atomic-widget`; content-model schema/migration skills; dynamic-data-binding skill.

---

## 4. Our differentiators vs Novamira PRO (concrete, from their gaps)

- **Free** (they gate Elementor + all field plugins behind PRO).
- **`move-element`** operation (they have none).
- **ETag conflict detection** on writes (they clobber silently — data loss risk).
- **Deep-merge** settings option (they shallow-merge → silently drop sibling responsive keys).
- **Conditional (`if`) validation** (they validate invisible controls).
- **Schema caching** (they re-introspect every call).
- **Taxonomy term assignment** in create/update-post (PRO lacks it).
- **Clean v4-first core** (no v3-migration bloat inside the insert path).

## 5. Testing & delivery (per wave)
Bundled-PHP harness for pure logic (schema-compaction, coercion/wrap, validation classification, patch/move transforms, parsers) + `php -l` lint per task + a live integration smoke against the Local `wp-connector` site (Elementor installed) at each wave's end. Each wave = its own plan in `docs/superpowers/plans/` and its own SDD execution.

## 6. Constraints / environment (unchanged)
Slug `wp-ultra-mcp`, namespace `wpultra/`, endpoint `/wp-json/mcp/wpultra`. Bundled PHP 8.2.30 at the Local lightning-services path; test site `C:/Users/nisha/Local Sites/wp-connector/app/public` (must be started in Local for live tests; Elementor + ACF/etc. installed per wave). Deps vendored from Novamira (no system Composer).

## 7. Open scope notes
- Elementor v4 "atomic" features require Elementor 4.x + the `e_atomic_elements` experiment; the engine detects version/experiment via a `check-setup` ability and degrades to v3 gracefully.
- Bricks (Wave 4) and the 6 field plugins (Wave 5) each require their plugin installed; abilities self-gate on presence.
- Custom-widget code-gen (`create-atomic-widget`) writes mu-plugins — gated to the sandbox + `manage_options`.
