# WP-Ultra-MCP — Elementor Design Reliability (validated, token-driven, feedback-corrected builds)

> Design doc. Status: **approved (mechanism + phasing)**, Phase A ready to plan.
> Builds on the shipped v0.5.0 plugin. Each phase below becomes its own implementation plan + release.

## Problem

When an AI is asked to build a design in Elementor from a **reference** (a URL like Uber, or a design image), the result is frequently **broken** — wrong layout, off styling, unstyled/dropped elements. The user does not want a one-off fix for a specific page; they want the **build mechanism itself** strengthened so that, given an image or URL reference, Elementor reliably produces an as-expected result.

## Root-cause diagnosis (evidence from the v0.5.0 code)

1. **The whole-page build path has zero validation.** `elementor-set-content` → `wpultra_el_write()` (`includes/elementor/engine.php`) JSON-encodes and writes whatever tree it is given. When the AI builds a full page and calls `set-content`, **nothing is validated**; Elementor silently drops malformed atomic props at render time → unstyled / empty / broken output. This is the single biggest hole.
2. **Containers are never validated or wrapped.** `elementor-add-element` validates **widget** props (`wpultra_el_wrap_settings` + `wpultra_el_validate_settings` via `Props_Parser`), but for `e-flexbox` / `e-div-block` containers it passes settings through untouched (`add-element.php` lines 65–68, "out of scope here"). **All layout lives on containers** (flex-direction, gap, padding, width, alignment), so unvalidated container settings are a primary cause of broken *layout*.
3. **The AI builds blind and from scratch.** There is no way for the AI to see its rendered output, no reference-extraction capability (it guesses what Uber looks like from memory), no enforced design tokens, and no library of known-good section structures — so small per-prop mistakes compound into broken pages.

The engine already has the right primitives to fix this: `wpultra_el_validate_settings()` (Props_Parser), `wpultra_el_widget_schema()`, `wpultra_el_style_schema()` (atomic Style_Schema), and the design-system writers in `design.php`. They are simply not applied on the whole-tree and container paths, and there is no feedback or reference layer.

## The mechanism

Replace blind one-shot generation with a **validated, token-driven, feedback-corrected pipeline**:

```
capture reference  →  set design tokens  →  build from blueprints
       ↓                                              ↓
   (URL/image digest)                        validate (dry-run, per-node)
                                                      ↓
                                              write  →  render-check  →  screenshot compare
                                                      ↑__________ self-correct __________↓
```

### Feedback-loop decision (delegated to Claude)

**Primary: client-side screenshot. Universal fallback: pure-PHP server-side render-check. No bundled headless browser.**

- The plugin must work on any WordPress host and stay free/lightweight — bundling headless Chrome violates that and breaks on shared hosts.
- The largest source of "broken" is *deterministically* fixable by validation, with **no rendering at all**.
- The user's own client (Claude Code) already has browser / computer-use / preview tooling; the plugin only needs to hand back a reliable **front-end preview URL** for the client to screenshot and compare.
- A pure-PHP **render-check** (render server-side, return a structural digest) catches "dropped / empty / unstyled" for *any* MCP client without a browser.
- Optional true headless-render screenshot is explicitly deferred (possible later add-on), not core.

## Pillars

| # | Pillar | What it adds |
|---|--------|--------------|
| 1 | **Validate-before-commit** | Validate `set-content` and container settings through `Props_Parser` + atomic `Style_Schema`; new `elementor-validate` dry-run ability returning per-node rejected/coerced props with reasons. |
| 2 | **Server render-check** | `elementor-render-check`: render the post server-side, return a digest (which elements rendered, child counts, whether per-post CSS was generated, empty/zero flags) + the front-end preview URL. |
| 3 | **Reference capture** | `elementor-capture-reference`: URL → fetch + parse → palette / fonts / spacing scale / section structure / key images. Image → client already sees it; ability registers extracted palette+fonts as a starting design system. |
| 4 | **Token-first + blueprints** | Write the reference's colors/typography/spacing into Elementor global tokens first (reuse `design.php`); a library of validated section **blueprints** (navbar, hero, feature-grid, CTA, footer) the AI composes from instead of hand-rolling flexbox. |
| 5 | **Skill (the glue)** | A skill doc encoding the full loop so the AI actually *uses* the mechanism: capture → tokens → blueprint build → validate → write → render-check → screenshot compare → correct. |

## Phasing

Each phase is an independent spec→plan→release slice.

- **Phase A — Validation + render-check (Pillars 1 & 2).** Pure PHP, deterministic, no new host deps. Removes the majority of breakage on its own. **Build first.**
- **Phase B — Reference capture + token-first + blueprints (Pillars 3 & 4).** Gives the AI ground truth and known-good building blocks.
- **Phase C — Design skill + screenshot-loop polish (Pillar 5).** Wires the client screenshot comparison into a repeatable, documented workflow; optional headless-render add-on considered here.

---

## Phase A — detailed design (the next implementation plan)

**Goal:** No write path can silently produce broken atomic output, and the AI can confirm a build actually rendered — all in pure PHP.

### A1. Container + whole-tree validation (engine)

New engine helpers in `includes/elementor/` (extend `coerce.php` / a new `validate.php`):

- `wpultra_el_validate_container_settings(array $settings)` — wrap + validate container/style-level props against the atomic **`Style_Schema`** (`wpultra_el_style_schema()`), mirroring how `wpultra_el_validate_settings()` validates widget props. Returns `['ok'=>true,'settings'=>...]` or `wpultra_err('invalid_settings', <Props_Parser error string>, <compact style schema>)`.
- `wpultra_el_validate_tree(array $elements)` — walk the full element tree; for each node run the matching validator (widget → `wpultra_el_validate_settings`, container → `wpultra_el_validate_container_settings`); collect a **per-node report**: `['id', 'elType', 'widgetType?', 'rejected'=>[{prop, reason}], 'coerced'=>[{prop, from, to}]]`. Returns `['ok'=>bool, 'nodes'=>[...], 'normalized_tree'=>array]`. Depth-guarded (reuse the `wpultra_el_walk` ≤100 guard).

### A2. `elementor-validate` ability (new, read-only dry-run)

- Input: `{ post_id?: int, elements?: array }` — validate either a supplied tree or the post's current `_elementor_data`.
- Output: `{ success, ok: bool, nodes: [...per-node report...], summary: { total, invalid, coerced } }`.
- `readonly: true`, `destructive: false`, `idempotent: true`. No audit log (read).
- Lets the AI submit a candidate tree, see exactly which props Elementor would reject, fix them, and only then write — turning silent breakage into actionable errors.

### A3. Wire validation into the write paths

- `elementor-set-content` (`wpultra_elementor_set_content`): before `wpultra_el_write`, run `wpultra_el_validate_tree`. Default **strict**: if any node is invalid, return the per-node report as a `wpultra_err('tree_invalid', ...)` instead of writing. Add an optional `force: bool` input to write anyway (with the report echoed in a `warning`), for deliberate escape-hatch use.
- `elementor-add-element`: route container (`e-flexbox`/`e-div-block`) settings through `wpultra_el_validate_container_settings` instead of the current pass-through (fixes the layout-breakage hole at lines 65–68).
- `elementor-edit-element`: same container-aware validation on merged settings.
- Audit log unchanged on the mutating abilities.

### A4. `elementor-render-check` ability (new, read-only)

- Input: `{ post_id: int }`.
- Strategy (pure PHP, verify exact API against installed Elementor 4.1.4 during implementation):
  - Render the page's Elementor content server-side via the frontend renderer (e.g. `\Elementor\Plugin::$instance->frontend->get_builder_content_for_display($post_id)`), suppressing output.
  - Parse the returned HTML (DOMDocument) into a digest: total rendered elements, count of `.elementor-widget` / container nodes, list of expected element ids that produced **no** DOM output (dropped), and nodes that rendered empty/zero-content.
  - Report whether per-post CSS was generated (presence of the `_elementor_css` meta / generated CSS file), since "rendered but unstyled" usually means missing CSS.
  - Return the front-end **preview URL** (`get_permalink($post_id)` + preview args for drafts) so a browser-capable client can screenshot and visually compare.
- Output: `{ success, preview_url, rendered_count, dropped_ids: [...], empty_ids: [...], css_generated: bool, notes }`.
- `readonly: true`, `idempotent: true`. No write.

### A5. Bootstrap wiring + tests + release

- Wire the 2 new abilities (`elementor-validate`, `elementor-render-check`) into `bootstrap-mcp.php`: `wpultra_ability_files()` + the `'elementor'` entry in `wpultra_ability_category_map()`; ensure any new engine file (`validate.php`) is required in the Elementor load loop.
- **Unit tests** (zero-dep harness): `wpultra_el_validate_tree` per-node reporting (valid tree, one bad widget prop, one bad container prop, coercion case); container wrap/validate happy + reject paths. Pure logic — stub `Props_Parser`/`Style_Schema` boundaries as the existing Elementor tests do.
- **Live verification** on the running Local site (token-gated script pattern): build a small known-good tree via `set-content` → `render-check` shows it rendered with CSS; build a deliberately-broken tree → `validate` flags the exact props and strict `set-content` refuses; container layout props validate.
- Version bump + readme/changelog + `gh release` (next patch/minor; ~49 abilities after Phase A).

### Phase A success criteria

- `set-content` cannot silently write a tree with invalid atomic props (strict by default; `force` is explicit).
- Container layout props are validated on add/edit/set — no more silently-dropped flex/gap/padding.
- The AI can dry-run `elementor-validate` and get a precise, per-node, prop-level rejection report.
- `elementor-render-check` confirms whether a build actually rendered (+ CSS) and returns a preview URL for visual comparison.
- All existing tests still pass; new unit tests green; live verification passes on real Elementor 4.1.4.

---

## Phases B & C — outline (separate plans later)

**Phase B — reference capture + token-first + blueprints**
- `elementor-capture-reference`: for a URL, `wp_remote_get` the page, parse with DOMDocument, and extract a digest — dominant color palette (from inline/linked CSS + common style attrs), font families, a spacing scale, section structure (header/hero/sections/footer), and key image URLs. For an image reference, the client already sees it; the ability accepts an explicit palette/fonts payload and registers them as a starting design system.
- Token-first helper that maps a captured palette/type/spacing onto Elementor global colors/typography/variables via the existing `design.php` writers, returning the token ids the build should reference.
- A versioned **blueprint library** (validated atomic section recipes: navbar, hero, feature-grid, CTA, footer) the AI inserts and then customizes — composition from known-good parts rather than hand-rolling every flexbox. Likely lives alongside the declarative recipe engine.

**Phase C — design skill + screenshot loop**
- A skill doc (sibling to the planned Gutenberg/Elementor skills) encoding the end-to-end loop and the order of operations (capture → tokens → blueprint build → `validate` → `set-content` → `render-check` → client screenshot of `preview_url` → compare to reference → correct → repeat).
- Guidance for the client-side visual compare step (Claude Code browser/computer-use/preview). Optional: evaluate an opt-in server-side headless-render screenshot for browser-less clients as a separate, clearly-gated add-on.

## Out of scope

- Bundling a headless browser / server-side pixel screenshots in core (deferred, optional, Phase C at the earliest).
- Pixel-perfect cloning guarantees — the target is *as-expected, non-broken, reference-faithful*, not byte-identical.
- Non-atomic (Elementor v3 classic) widget deep validation — the engine targets v4 atomic widgets, consistent with prior waves.
- Bricks / other builders (separate wave).

## Testing strategy

Consistent with prior waves: pure logic (path/tree/validation reporting) unit-tested with the zero-dep harness; Elementor-runtime paths (Props_Parser, Style_Schema, frontend render, kit writes) live-tested via token-gated scripts on the running Local site, then re-deployed. Re-run `bin/deploy.ps1` after every commit.
