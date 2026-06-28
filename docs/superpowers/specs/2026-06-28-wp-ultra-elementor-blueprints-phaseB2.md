# WP-Ultra-MCP — Elementor Reliability Phase B2 (section blueprint library)

> Design doc. Status: **approved (design)**, ready to plan. Sub-phase of the Elementor Design Reliability program. Builds on the shipped v0.7.1 plugin. Ships as v0.8.0.

## Problem

The AI can now build atomic Elementor designs correctly (Phase A validation, Phase B tokens, Phase C skill). But it still hand-builds every common section (navbar, hero, feature grid, CTA, footer) from scratch each time — repetitive, slower, and a chance to mis-structure the flexbox nesting. A small library of known-good **structural skeletons** lets the AI insert a correct section in one call and then style it with tokens/classes.

## Decision: structural skeletons, no hardcoded styling

Blueprints are **structure only** — flexbox/widget layout trees with placeholder text and raw-scalar content props (per the proven authoring pattern). They carry **no color/font/spacing/visual props**. Rationale: structure is stable across Elementor versions, but visual styling is per-design and would make blueprints stale and opinionated. After inserting a blueprint, the AI styles it using Phase B design tokens + global classes (Phase C skill). This keeps the library small, maintainable, and composable, while still removing the repetitive layout work — which is where mis-nesting bugs happen.

## The five blueprints

Universal landing-page sections, each a validated atomic tree of `e-flexbox` containers + `e-heading`/`e-paragraph`/`e-button` widgets, content as raw scalars, placeholder copy:

- `navbar` — `e-flexbox` (row): a brand `e-heading` + an `e-flexbox` holding 3 nav-link `e-paragraph`s + a `e-button`.
- `hero` — `e-flexbox` (column): `e-heading` (headline) + `e-paragraph` (subhead) + `e-button` (CTA).
- `feature-grid` — `e-flexbox` (row) holding 3 child `e-flexbox` columns, each `e-heading` + `e-paragraph`.
- `cta` — `e-flexbox`: `e-heading` + `e-button`.
- `footer` — `e-flexbox` (row) holding 3 child `e-flexbox` columns, each a `e-heading` + 2 `e-paragraph` links.

Each blueprint is stored with stable placeholder ids; on insert, every node id is regenerated fresh so multiple inserts never collide.

## Components

### `wpultra_el_blueprints(): array` (pure, in `includes/elementor/blueprints.php`)
Returns `[ name => ['description'=>string, 'summary'=>string, 'tree'=>array] ]`. `tree` is the atomic element array (raw-scalar settings). Pure data + a builder — no Elementor calls.

### `wpultra_el_blueprint_reid(array $tree, array $existing = []): array` (pure)
Recursively replace every node's `id` with a fresh 7-char id (via `wpultra_el_new_id`, collision-checked against `$existing` ids already on the page and against ids already assigned within this tree). Returns the re-id'd tree. Pure (id generation is the only side-channel; seeded by `$existing`). Unit-tested for uniqueness + structure preservation.

### `elementor-list-blueprints` (ability, read-only)
Input: none (optional `name` to fetch one). Output: `{success, blueprints:[{name, description, summary}]}` (or one blueprint's tree if `name` given). No audit log.

### `elementor-insert-blueprint` (ability, mutating)
Input: `{post_id, blueprint, parent_id?, position?}`. Flow:
1. Look up the blueprint by name (`bad_blueprint` error if unknown).
2. `wpultra_el_blueprint_reid` against the page's existing ids — pass the current page tree `wpultra_el_raw($post_id)` as the collision seed (`wpultra_el_new_id($seed)` already checks an id against a tree via `wpultra_el_find`).
3. Validate the re-id'd tree via `wpultra_el_validate_tree` (Phase A) — refuse on invalid (should never happen for a curated blueprint; this is a guard that also proves the blueprint stays valid as Elementor evolves).
4. Insert at `parent_id`/`position` (default append at root) via `wpultra_el_insert`, then `wpultra_el_write`.
5. `wpultra_audit_log`. Return `{success, inserted_ids:[…], blocks/elements: compact tree}` so the AI can then target specific nodes to style/edit.

## Engine reuse

`wpultra_el_validate_tree` (validate.php), `wpultra_el_insert` + `wpultra_el_write` + `wpultra_el_raw` (tree/engine), `wpultra_el_new_id` (setup.php), `wpultra_el_compact_tree` (tree). The only new pure logic is the blueprint data + the re-id walker.

## Bootstrap wiring

Both ability slugs into `wpultra_ability_files()` (a new `// elementor blueprints (Phase B2)` group) AND the `'elementor'` array in `wpultra_ability_category_map()`; `tests/bootstrap.test.php` count 50 → 52. `blueprints.php` added to the Elementor engine require loop in `wpultra_load_abilities()`.

## Skill addendum

Add a short paragraph to `includes/skills/built-in/elementor-v4-architect.md`: "To start a common section fast, `elementor-list-blueprints` then `elementor-insert-blueprint` — it inserts a validated structural skeleton (navbar/hero/feature-grid/cta/footer) with fresh ids; then style it with design tokens + global classes."

## Testing

- **Pure unit (zero-dep harness):** every blueprint tree has the expected top-level `e-flexbox` and the documented child structure; `wpultra_el_blueprint_reid` produces all-unique ids, regenerates ids that collide with `$existing`, and preserves tree shape/settings.
- **Live (token-gated script on the running Local site):** for each of the 5 blueprints, insert into a draft page, confirm `elementor-validate` passes on the result and `elementor-render-check` reports the expected element count rendered with no dropped ids; insert two of the same blueprint and confirm no id collision.

## Out of scope

- Styled/branded blueprints (the AI styles post-insert via tokens + global classes).
- Full page-level templates / multi-section pages (compose by inserting several blueprints).
- Gutenberg/Bricks blueprints (separate).
- User-defined blueprints (the built-in 5 only; a user-blueprint store is a later option).

## Success criteria

- `elementor-list-blueprints` returns the 5 sections; `elementor-insert-blueprint` inserts any of them with fresh ids, passing Phase A validation, at the requested location.
- Inserting the same blueprint twice never collides ids.
- Each inserted blueprint renders (live `render-check`) with no dropped elements.
- All existing tests stay green; new unit tests green. Released as v0.8.0 (52 abilities).
