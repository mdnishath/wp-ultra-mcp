---
name: elementor-v4-architect
description: How to build reliable, reference-faithful Elementor v4 (atomic) designs via the wpultra elementor-* abilities — the validated build loop, the correct authoring rules, and how styling/design-tokens actually work.
enable_prompt: true
enable_agentic: true
---
You build **Elementor v4 atomic** designs through WP-Ultra-MCP. The cardinal rule: **never guess settings — introspect the real schema, validate before writing, and render-check after.** Most "broken" Elementor output comes from putting values in the wrong place or with the wrong type; the rules below prevent that.

## The reliable build loop

1. **Perceive the reference** (if any). You are multimodal: look at the design image directly, or browse the URL and screenshot it. Extract its palette (hex + roles), fonts, spacing scale, and section structure yourself — do NOT expect the server to scrape it.
2. **Apply design tokens** — `wpultra/elementor-apply-design-tokens` with `{colors:[{role,title,hex}], fonts:[{role,title,family}], sizes:[{role,title,size,unit}]}`. It creates Elementor Variables and returns refs like `{ "$$type":"global-color-variable", "value":"e-gv-…" }`. Use these refs everywhere instead of hardcoding values, so the page is token-consistent and re-themable.
3. **Introspect schemas** — `wpultra/elementor-list-widgets` (`atomic_only:true`) then `wpultra/elementor-get-widget-schema` for each widget BEFORE setting it. The schema gives each prop's `type` (its `$$type`), `enum`, and `default`. Read it — prop names and types are not what you'd guess (see Authoring rules).
4. **Build** with `wpultra/elementor-add-element` (one node at a time) or assemble the full tree and `wpultra/elementor-set-content` (whole page).
5. **Validate first** — `wpultra/elementor-validate` (dry-run on your tree, or on a `post_id`) returns a per-node report of any rejected props with reasons. Fix them. `elementor-set-content` is **strict by default**: it refuses to write an invalid tree (pass `force:true` only to deliberately bypass). This is what stops silently-broken designs.
6. **Render-check** — `wpultra/elementor-render-check` `{post_id}` renders server-side and reports which elements actually rendered, any dropped ids, whether CSS was generated, and a `preview_url`. Screenshot the `preview_url` and compare it to the reference; correct and repeat.

## Authoring rules (these prevent the common breakages)

- **Pass RAW scalars for settings — do not pre-wrap them.** Give `{"tag":"h2","title":"Hello"}`, NOT `{"title":{"$$type":"string","value":"Hello"}}`. The engine wraps each scalar using the prop's real `$$type` from the schema. Guessing the wrapper is the #1 cause of `invalid_value` — e.g. e-heading's `title` is a **union defaulting to `html-v3`** (a `{content,children}` shape), so a hand-wrapped `{$$type:"string"}` is rejected, while a raw `"Hello"` validates. When a prop genuinely needs a structured value (link, image, a token ref), use the exact shape from `get-widget-schema`.
- **Visual styling is NOT a widget setting.** Atomic widgets carry only content/behavior props. e-heading's props are exactly `classes, tag, title, link, attributes, _cssid, display-conditions` — there is **no `color`, `font`, `padding`, or `background` prop**. Putting `color` in `settings` → `unknown_prop`. **Style through global classes (below).** (`_`-prefixed system keys like `_cssid` are allowed and ignored by validation.)
- **Build layout with containers, not bare styling.** A section = an `e-flexbox` (or `e-div-block`) container holding widgets/sub-containers. A 3-column row = one `e-flexbox` (row direction) holding three child `e-flexbox` columns. Container layout props (flex/gap/padding/width) ARE validated now — introspect with `elementor/elementor-get-style-schema`.
- **Experiments auto-enable but apply on the NEXT request.** `e_atomic_elements`, `e_classes`, and `e_variables` are turned on for you on first use, but Elementor only reads the change on the next request. If an ability returns an `*_enabling` / "re-run this action" message, just call it again — it will succeed.

## How styling + design tokens actually reach an element

Styling is applied via **global classes**, and tokens flow into those classes:

1. `wpultra/elementor-apply-design-tokens` → create color/font/size Variables, get back refs.
2. `wpultra/elementor-upsert-global-class` → create a reusable class whose `props` are wrapped CSS props that reference the tokens, e.g.
   `{"label":"BrandHeading","enable":true,"props":{"color":{"$$type":"global-color-variable","value":"e-gv-…"},"font-size":{"$$type":"global-size-variable","value":"e-gv-…"}}}`.
   (Plain values work too: `{"color":{"$$type":"color","value":"#0a84ff"}}`.) Returns an `e-gc-…` id.
3. Attach the class to elements — either the widget's `classes` prop in `set-content`/`add-element` as the wrapped form `{"$$type":"classes","value":["e-gc-…"]}`, or `wpultra/elementor-apply-class` `{post_id, element_id, class_id}` (`remove:true` to detach).
4. Add motion with `wpultra/elementor-set-interaction` `{post_id, element_id, trigger:"scrollIn", effect:"fade"|"slide"|"scale", type:"in", duration:600}`.

The node-local `styles` array is not authored directly — global classes are the styling mechanism.

## Reference

- **Read:** `elementor-list-widgets`, `elementor-get-widget-schema`, `elementor-get-style-schema`, `elementor-get-content` (compact tree; pass `element_id` for one node's full settings), `elementor-validate`, `elementor-render-check`.
- **Structure (write):** `elementor-add-element` (container: `element_type:"e-flexbox"`; widget: `element_type:"widget"`,`widget_type`,`settings`,`parent_id`,`position`), `elementor-edit-element`, `elementor-move-element`, `elementor-delete-element`, `elementor-set-content` (strict; `force:true` to bypass).
- **Design system:** `elementor-get-design-system`, `elementor-apply-design-tokens` (reference → Variables), `elementor-manage-global-colors`, `elementor-manage-variables`, `elementor-list-dynamic-tags` (bind data: `{"$$type":"dynamic","value":{"name":"post-title","group":"post","settings":{}}}` — ACF/JetEngine tags appear when those plugins are installed).
- **Classes & motion:** `elementor-upsert-global-class`, `elementor-list-global-classes`, `elementor-apply-class`, `elementor-set-interaction`.

Data model: `_elementor_data` is an array of nodes. Widget = `{id, elType:"widget", widgetType, settings:{prop:{$$type,value}}, elements:[]}`; container = `{id, elType:"e-flexbox"|"e-div-block", settings, elements:[…]}`. The engine writes atomic-safe (it bypasses `Document::save`, which would strip atomic widgets) and clears Elementor's CSS cache for you. Reserved CPTs (skills/memory/abilities) are write-protected.

## Start sections fast with blueprints

`wpultra/elementor-list-blueprints` shows built-in structural skeletons (navbar, hero, feature-grid, cta, footer). `wpultra/elementor-insert-blueprint` `{post_id, blueprint, parent_id?, position?}` inserts one with fresh ids, validated — it carries layout + placeholder text only (no styling). Then style it with design tokens + global classes, and edit the placeholder copy with `elementor-edit-element`.
