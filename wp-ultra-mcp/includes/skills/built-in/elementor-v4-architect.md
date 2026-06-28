---
name: elementor-v4-architect
description: How to build Elementor v4 (atomic) layouts via the wpultra elementor-* abilities.
enable_prompt: true
enable_agentic: true
---
You build Elementor **v4 atomic** layouts through WP-Ultra-MCP. Never guess settings — introspect the real schema.

Workflow:
1. `wpultra/elementor-list-widgets` (atomic_only:true) to see available widgets (e-heading, e-button, e-image, e-paragraph, e-divider, e-flexbox container, e-div-block container, …).
2. `wpultra/elementor-get-widget-schema` (widget_type) BEFORE setting any widget — it returns each prop's `type` (the `$$type`), `enum` (allowed values), and `default`. e.g. e-heading has `tag` (enum h1..h6) and `title`.
3. Build the page: `wpultra/elementor-add-element` — for a container pass `element_type: "e-flexbox"`; for a widget pass `element_type:"widget"`, `widget_type:"e-heading"`, and `settings`. You may pass plain scalars (`{"tag":"h2"}`) — the engine wraps them into the `{$$type,value}` form and validates them via Elementor's own parser. Complex props (title html, link, image) should be passed already-wrapped per the schema.
4. Position with `parent_id` + `position`. Re-arrange with `wpultra/elementor-move-element`. Tweak with `wpultra/elementor-edit-element`. Remove with `wpultra/elementor-delete-element`.
5. Read structure with `wpultra/elementor-get-content` (compact tree; pass `element_id` to drill into one node's full settings).

Data model: `_elementor_data` is an array of nodes. Widget node = `{id, elType:'widget', widgetType, settings:{prop:{$$type,value}}, styles:{}, elements:[]}`. Container = `{id, elType:'e-flexbox'|'e-div-block', settings, styles, elements:[…]}`. The engine writes atomic-safe (it does NOT route through Document::save, which strips atomic widgets) and clears Elementor's CSS cache for you.

A 3-column section = one `e-flexbox` container (settings display:flex via the style schema) holding three child containers, each holding its widget(s).

## Design systems (site-wide)
- `wpultra/elementor-get-design-system` — read the kit's global colors, typography, and variables.
- `wpultra/elementor-manage-global-colors` — set/add brand colors (e.g. `{colors:[{title:"Brand",color:"#0055FF"}], target:"custom"}`). They become `--e-global-color-<id>` CSS vars site-wide.
- `wpultra/elementor-manage-variables` — list/create v4 design-token variables (color/font/size). Reference one in a widget/style prop as `{ "$$type":"global-color-variable", "value":"e-gv-<id>" }`.
- `wpultra/elementor-list-dynamic-tags` — list available dynamic tags. Bind a prop to data with `{ "$$type":"dynamic", "value":{ "name":"post-title", "group":"post", "settings":{} } }` (ACF/JetEngine tags appear here when those plugins are installed).

## Reusable classes & animations
- `wpultra/elementor-upsert-global-class` — create a reusable style class (pass `enable:true` once if the `e_classes` experiment is off). `props` are wrapped css props, e.g. `{ "color":{"$$type":"color","value":"#fff"}, "background":{"$$type":"background","value":{...}} }`. Returns an `e-gc-…` id.
- `wpultra/elementor-list-global-classes` — list existing classes.
- `wpultra/elementor-apply-class` — add/remove a class id on an element (`{post_id, element_id, class_id}`; `remove:true` to detach).
- `wpultra/elementor-set-interaction` — add an entrance animation to an element (`{post_id, element_id, trigger:"scrollIn", effect:"fade"|"slide"|"scale", type:"in", duration:600}`).
