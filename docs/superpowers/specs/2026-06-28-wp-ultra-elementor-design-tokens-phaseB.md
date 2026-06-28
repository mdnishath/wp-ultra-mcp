# WP-Ultra-MCP — Elementor Reliability Phase B (apply design tokens from a reference)

> Design doc. Status: **approved (design)**, ready to plan. Sub-phase of the Elementor Design Reliability program (parent spec: `docs/superpowers/specs/2026-06-28-wp-ultra-elementor-design-reliability-design.md`). Builds on the shipped v0.6.1 plugin. Ships as v0.7.0.

## Problem

Phase A made Elementor writes *valid* (no silently-dropped props). The remaining gap is *fidelity*: when recreating a design from a reference (a URL like Uber, or an image), the result should match the reference's look — its colors, fonts, and spacing — not generic defaults. Today the AI hardcodes guessed values per element, so nothing is consistent and nothing is tied to the reference.

## Decision: who perceives the reference

**The client (Claude) perceives the reference; the plugin applies the tokens.** Claude is multimodal — it sees an image directly, and via Claude Code's browser tools it can navigate to a URL and screenshot it. That perception is far more accurate than pure-PHP scraping, especially for JS-rendered sites (Uber and modern SPAs) — exactly the references that matter — which `wp_remote_get` + DOM parsing cannot read (minified/external/JS-injected CSS). So Phase B does **no server-side scraping**. The plugin's durable value-add is the part only it can do: writing the perceived tokens into Elementor's global design system and returning the references the build should use. This also keeps Phase B pure-PHP-light, consistent with the Phase A feedback-loop decision.

## Scope: tokens now, blueprints later

Phase B is **design-token application only**. A curated section-blueprint library (navbar/hero/feature-grid/CTA/footer) is deferred to **Phase B2** — it is large curation work, risks staleness across Elementor versions, and is low marginal value once tokens exist and Phase A validation is in place (the AI can build sections from validated, token-referencing settings). YAGNI.

## The mechanism

```
client perceives reference  →  structured design brief  →  elementor-apply-design-tokens
   (image/URL screenshot)        (colors, type, spacing)        ↓
                                                          writes Elementor global
                                                          colors + typography + size variables
                                                                 ↓
                                                          returns token REFS  →  AI references
                                                          (global-color-variable ids, etc.)     them in element settings
```

The AI then builds (via the Phase A-validated set-content/add-element) using `{$$type: global-color-variable, value: '<ref>'}`-style settings instead of hardcoded values, so the page is token-consistent and reference-faithful, and a later token change re-themes the whole page.

## Component: `elementor-apply-design-tokens` (new ability)

One cohesive ability that takes a full design brief and writes all three token families in a single call, returning the references.

**Input (the client builds this from its perception of the reference):**
```
{
  colors:     [ { role: string, title: string, hex: string } ],   // role e.g. primary/secondary/accent/background/text/muted
  typography: [ { role: string, title: string, font_family: string, weight?: string|int, size?: { size: number, unit: string } } ],
  spacing?:   [ { title: string, size: number, unit: string } ],  // optional spacing scale → size variables
  replace?:   boolean                                             // default false = upsert/merge; true = replace the custom set
}
```

**Output:**
```
{
  success: true,
  colors:     [ { id, title, ref } ],   // ref = how to use it in settings (global-color-variable id / global color _id)
  typography: [ { id, title, ref } ],
  spacing:    [ { id, title, ref } ],
  notes?: string
}
```

**Behavior:** validate inputs (hex colors via the existing `wpultra_el_is_hex_color`; non-empty titles); map roles→titles to kit entries; write each family via the engine functions below; collect each written entry's id and a usable `ref` string; return them. Partial-failure safe: if one family fails (e.g. variables experiment off for spacing), write the others and report the failure in `notes` rather than aborting the whole call. Mutating → calls `wpultra_audit_log`.

## Engine (in `includes/elementor/design.php` — reuse + one addition)

- **Colors** — reuse existing `wpultra_el_set_global_colors(array $colors, 'custom')`. Already writes kit `custom_colors` `[{_id,title,color}]` and clears cache.
- **Spacing** — reuse existing `wpultra_el_variables_create('global-size-variable', $label, $value)` when the `e_variables` experiment is active; if inactive, skip spacing and note it (do not fail the call).
- **Typography — NEW `wpultra_el_set_global_typography(array $items, string $target = 'custom')`.** Mirrors `wpultra_el_set_global_colors`: reads the kit's `custom_typography` (or `system_typography`), upserts entries by `_id` (slug of title), writes back via `$kit->update_settings([...])`, clears file cache. **Each entry's exact field shape must be verified against live Elementor 4.1.4** (the established wave workflow) — expected shape per entry:
  ```
  { _id, title,
    typography_typography: 'custom',
    typography_font_family: <string>,
    typography_font_weight: <string|int>,         // when provided
    typography_font_size: { unit: <string>, size: <number> } }   // when provided
  ```
  Returns the written list or `WP_Error`. Guarded in try/catch like the colors writer (kit writes throw "Access denied" if unauthenticated — real MCP is authed).

The ability is thin orchestration over these three writers plus ref-collection; the brief→kit mapping is the only new pure logic.

## Reference: how the AI uses the returned refs

- Global color variable in a setting: `{ "$$type": "global-color-variable", "value": "<color ref>" }`.
- Global colors also remain usable by their kit `_id`.
- Size variables: `{ "$$type": "global-size-variable", "value": "<spacing ref>" }`.
The ability's job ends at returning the refs; the AI wires them through the Phase A-validated write abilities.

## Bootstrap wiring

`elementor-apply-design-tokens` added to BOTH `wpultra_ability_files()` (elementor design-write group) and the `'elementor'` array in `wpultra_ability_category_map()`; `tests/bootstrap.test.php` count bumped 49 → 50. design.php is already in the Elementor engine require loop, so the new typography writer needs no new require.

## Testing

- **Pure unit (zero-dep harness):** the brief→kit mapping (role/title→entry, slug ids, hex validation rejects bad colors, ref assembly, partial-family handling). Stub the kit writers.
- **Live (token-gated script on the running Local site):** apply a brief with 2 colors + 1 typography + 1 spacing; confirm the kit's `custom_colors`/`custom_typography`/size variables now contain them and the returned refs resolve; confirm a built element can reference a returned color ref and render with it (ties back to Phase A render-check). Confirm the typography field shape against real Elementor 4.1.4.

## Out of scope

- Section blueprint library (Phase B2).
- Design skill encoding the full capture→tokens→build→render→compare loop (Phase C).
- Server-side URL scraping / headless rendering (client perceives instead).
- Per-breakpoint responsive token sets (single set for now; YAGNI).

## Success criteria

- One `elementor-apply-design-tokens` call writes a reference's palette, fonts, and spacing into Elementor's global design system and returns usable refs.
- Colors and typography survive a kit round-trip (re-read shows them); spacing written when `e_variables` is active, gracefully skipped+noted otherwise.
- A subsequent Phase A-validated build can reference a returned token and render with it (live-verified).
- All existing tests stay green; new unit tests green; live verification passes on Elementor 4.1.4. Released as v0.7.0 (50 abilities).
