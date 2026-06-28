# WP-Ultra-MCP — Elementor Reliability Phase B (apply design tokens from a reference)

> Design doc. Status: **approved (design)**, ready to plan. Sub-phase of the Elementor Design Reliability program (parent spec: `docs/superpowers/specs/2026-06-28-wp-ultra-elementor-design-reliability-design.md`). Builds on the shipped v0.6.1 plugin. Ships as v0.7.0.

## Problem

Phase A made Elementor writes *valid* (no silently-dropped props). The remaining gap is *fidelity*: when recreating a design from a reference (a URL like Uber, or an image), the result should match the reference's look — its colors, fonts, and sizes — not generic defaults. Today the AI hardcodes guessed values per element, so nothing is consistent and nothing is tied to the reference.

## Decision: who perceives the reference

**The client (Claude) perceives the reference; the plugin applies the tokens.** Claude is multimodal — it sees an image directly, and via Claude Code's browser tools it can navigate to a URL and screenshot it. That perception is far more accurate than pure-PHP scraping, especially for JS-rendered sites (Uber and modern SPAs) — exactly the references that matter — which `wp_remote_get` + DOM parsing cannot read (minified/external/JS-injected CSS). So Phase B does **no server-side scraping**. The plugin's durable value-add is the part only it can do: writing the perceived tokens into Elementor's design system and returning the references the build should use. This keeps Phase B pure-PHP-light, consistent with the Phase A feedback-loop decision.

## Decision: tokens are Elementor **Variables**, not classic kit globals

Tokens are written as Elementor **Variables** (`global-color-variable`, `global-font-variable`, `global-size-variable`) via the existing `wpultra_el_variables_create`, **not** the classic kit `custom_colors`/`custom_typography` palette. Reason: the target build path is **atomic v4** widgets, and atomic settings reference a variable cleanly as `{ "$$type": "<variable-type>", "value": "<variable-id>" }`. Classic kit globals are a v3 concept that atomic style props cannot reference cleanly. Variables are the atomic-native token system, give one uniform ref shape for all three families, and reuse code that already exists — so Phase B adds **no new kit writer**. Variables require the `e_variables` experiment; Phase B ensures it is on (mirroring the v0.6.1 atomic-experiment auto-enable).

## Scope: tokens now, blueprints later

Phase B is **design-token application only**. A curated section-blueprint library (navbar/hero/feature-grid/CTA/footer) is deferred to **Phase B2** — it is large curation work, risks staleness across Elementor versions, and is low marginal value once tokens exist and Phase A validation is in place. YAGNI. Weight/line-height/letter-spacing are not tokenized (set per element); only the three variable families above. YAGNI.

## The mechanism

```
client perceives reference  →  design brief  →  elementor-apply-design-tokens
   (image / URL screenshot)     (colors,fonts,sizes)        ↓
                                                     ensure e_variables on, then create
                                                     color + font + size Variables
                                                            ↓
                                                     return token REFS  →  AI references them in
                                                     {$$type, value:id}        Phase A-validated builds
```

The AI then builds (via the Phase A-validated set-content/add-element) using `{ "$$type": "global-color-variable", "value": "<id>" }`-style settings instead of hardcoded values, so the page is token-consistent and reference-faithful, and a later token change re-themes the whole page.

## Component: `elementor-apply-design-tokens` (new ability)

One cohesive ability that takes a design brief and creates all three variable families in a single call, returning their references.

**Input (the client builds this from its perception of the reference):**
```
{
  colors?: [ { role: string, title: string, hex: string } ],          // role e.g. primary/secondary/accent/background/text
  fonts?:  [ { role: string, title: string, family: string } ],       // font-family tokens
  sizes?:  [ { role: string, title: string, size: number, unit: string } ],  // one numeric scale for spacing AND font-size
}
```
At least one of `colors`/`fonts`/`sizes` must be present. Titles non-empty; hex validated via the existing `wpultra_el_is_hex_color`; unit defaults to `px`.

**Output:**
```
{
  success: true,
  colors: [ { title, id, ref: { "$$type": "global-color-variable", "value": id } } ],
  fonts:  [ { title, id, ref: { "$$type": "global-font-variable",  "value": id } } ],
  sizes:  [ { title, id, ref: { "$$type": "global-size-variable",  "value": id } } ],
  notes?: string
}
```

**Behavior:** ensure `e_variables` is active (auto-enable; if it cannot be enabled, return a clear error). Validate the brief (pure). For each item, call `wpultra_el_variables_create(<type>, title, value)` where value is the hex / family / `"{size}{unit}"`. Collect each created variable's id and assemble its `ref`. **Partial-safe:** if one family's create fails, keep the successes and record the failure in `notes` rather than aborting. Mutating → calls `wpultra_audit_log`.

## Engine (reuse + tiny additions in `includes/elementor/design.php`)

- **Variable creation** — reuse existing `wpultra_el_variables_create(string $type, string $label, $value)` (validates the three types, calls the Variables service, returns `wpultra_ok(['variable'=>...])` or `WP_Error`). Already present.
- **NEW `wpultra_el_variables_enable(): bool`** — persist the `e_variables` experiment active (mirror `wpultra_el_atomic_enable` from setup.php: `update_option('elementor_experiment-e_variables', STATE_ACTIVE)`; return whether the option now reads active). Same caching caveat as atomic (Elementor reads experiment state at boot; a mid-request flip only applies next request). The ability surfaces an "enabled — re-run" message when the current request still sees it inactive, matching the v0.6.1 atomic pattern.
- **NEW pure `wpultra_el_build_token_plan(array $brief): array`** — map the brief to a flat list of `{ family, type, title, value }` create-instructions and a list of validation errors. Pure (no Elementor); unit-tested. `family` ∈ color|font|size; `type` is the variable type; `value` is hex / family / `"{size}{unit}"`. Rejects empty titles and invalid hex.
- The ability orchestrates: enable variables → build plan (pure) → for each instruction call `wpultra_el_variables_create` → assemble refs.

**Variable id / ref shape must be confirmed against live Elementor 4.1.4** (the established wave workflow): `wpultra_el_variables_create` returns the created variable; the live test reads back the id used in `{$$type,value}` and confirms an atomic element can reference it and render. Expected variable id form is an `e-gv-…`-style string (per the parent reliability notes).

## Bootstrap wiring

`elementor-apply-design-tokens` added to BOTH `wpultra_ability_files()` (elementor design-write group) and the `'elementor'` array in `wpultra_ability_category_map()`; `tests/bootstrap.test.php` count bumped 49 → 50. `design.php` and `setup.php` are already in the Elementor engine require loop, so the new engine functions need no new require.

## Reference: how the AI uses the returned refs

The ability returns each token's `ref` object verbatim — the AI drops it straight into an atomic setting:
- Color prop: `{ "$$type": "global-color-variable", "value": "<id>" }`
- Font-family prop: `{ "$$type": "global-font-variable", "value": "<id>" }`
- Size prop (padding/gap/font-size): `{ "$$type": "global-size-variable", "value": "<id>" }`
The ability's job ends at returning the refs; the AI wires them through the Phase A-validated write abilities, whose validation then confirms the referenced props are well-formed.

## Testing

- **Pure unit (zero-dep harness):** `wpultra_el_build_token_plan` — colors/fonts/sizes map to the right variable types and values (`"16px"` assembled from `{size:16,unit:'px'}`, default unit px), empty/invalid items rejected with errors, empty brief → error, partial brief (only fonts) works.
- **Live (token-gated script on the running Local site):** call the ability path with a small brief (2 colors + 1 font + 1 size); confirm `e_variables` gets enabled, the variables are created, and the returned refs carry real ids; then build an atomic element that references a returned color ref and run Phase A `elementor-render-check` to confirm it renders. Confirm the variable id/ref shape against real Elementor 4.1.4.

## Out of scope

- Section blueprint library (Phase B2).
- Design skill encoding the full capture→tokens→build→render→compare loop (Phase C).
- Server-side URL scraping / headless rendering (client perceives instead).
- Classic kit global colors/typography writers; weight/line-height tokenization; per-breakpoint responsive token sets (YAGNI).

## Success criteria

- One `elementor-apply-design-tokens` call creates a reference's color/font/size Variables and returns usable `{$$type,value}` refs.
- `e_variables` is auto-enabled; if it only takes effect next request, the ability says so (no false failure).
- A subsequent Phase A-validated build can reference a returned token and render with it (live-verified on Elementor 4.1.4).
- All existing tests stay green; new unit tests green. Released as v0.7.0 (50 abilities).
