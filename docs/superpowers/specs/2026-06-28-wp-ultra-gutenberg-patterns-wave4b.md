# WP-Ultra-MCP — Wave 4b (Gutenberg patterns + reusable/synced blocks)

> Design doc. Status: **approved (design)**, ready to plan. Phase 4b of the Gutenberg arc (parent spec: `docs/superpowers/specs/2026-06-28-wp-ultra-wave4-gutenberg-design.md`, which outlines 4b). Builds on the shipped v0.8.0 plugin (Wave 4a core block ops). Ships as v0.9.0.

## Problem

Wave 4a gave the AI node-by-node Gutenberg block control (parse/insert/update/delete/move by positional path + block-type discovery). But to build pages fast it should also use the two reuse primitives WordPress already ships: **block patterns** (predefined layouts registered by core/themes/plugins) and **reusable/synced blocks** (the `wp_block` CPT). These are the Gutenberg analogue of the Elementor blueprints + design-system work.

## Scope

Three abilities over a small new engine file, reusing the Wave 4a block engine end-to-end. **No FSE** (templates / theme.json → Wave 4c). **No pattern authoring** — read + insert the built-in/registered patterns only (creating new patterns is a later option). Reusable-block management is full CRUD on `wp_block`; *inserting* a reusable block into a post needs no new ability — it is a single `core/block` reference block inserted with the existing `gutenberg-insert-block` (`<!-- wp:block {"ref":<id>} /-->`).

## Components

### `includes/gutenberg/patterns.php` (engine)
- `wpultra_gb_list_patterns(string $search = '', string $category = ''): array` — `WP_Block_Patterns_Registry::get_instance()->get_all_registered()` → list of `['name','title','categories','description','viewportWidth']`, filtered by case-insensitive substring on `name.' '.title` and by exact category. Sorted by name.
- `wpultra_gb_get_pattern(string $name)` — one registered pattern's full record (incl. `content`) or `WP_Error('pattern_not_found')`.
- `wpultra_gb_reusable_list(string $search = ''): array` — `wp_block` posts → `[{id,title,slug,modified}]` (optionally title-filtered).
- `wpultra_gb_reusable_get(int $id)` — `['id','title','content']` or `WP_Error('reusable_not_found')` (404 / wrong post_type guarded).
- `wpultra_gb_reusable_save(array $args)` — create or update a `wp_block` post (`['id'?,'title','content']`); returns `['id','title']` or `WP_Error`. Create via `wp_insert_post(['post_type'=>'wp_block','post_status'=>'publish',...], true)`; update via `wp_update_post`. On update, guard that the target post is actually a `wp_block`.

### `gutenberg-list-patterns` (ability, read-only)
Input `{search?, category?}`. Output `{success, count, patterns:[{name,title,categories,description}]}`. No audit.

### `gutenberg-insert-pattern` (ability, mutating)
Input `{post_id, pattern_name, parent_path?, position?}`. Flow: `wpultra_gb_get_pattern` (→ `pattern_not_found`); `parse_blocks` the pattern's `content` (skip null-name whitespace chunks); load the post via `wpultra_gb_load`; insert each parsed top-level block at `parent_path`/`position` via `wpultra_gb_insert` (default append at root; increment position per block); `wpultra_gb_save`; `wpultra_audit_log`. Returns `{success, inserted: <count>, blocks: <compact tree>}`.

### `gutenberg-manage-reusable-block` (ability, mutating on writes)
Input `{action: 'create'|'update'|'get'|'list', id?, title?, content?, search?}`. Dispatches to the engine reusable functions. `create`/`update` call `wpultra_audit_log`; `get`/`list` do not. Output shape per action (`{success, id, title}` for create/update; `{success, ...}` for get; `{success, count, blocks:[…]}` for list).

## Engine reuse

`wpultra_gb_load`, `wpultra_gb_save`, `wpultra_gb_insert`, `wpultra_gb_compact_tree` (Wave 4a); core `parse_blocks`, `WP_Block_Patterns_Registry`, `wp_insert_post`/`wp_update_post`/`get_post`/`get_posts`. The only new logic is the registry/CPT wrappers + the insert-pattern parse→insert glue.

## Bootstrap wiring

The three slugs into `wpultra_ability_files()` (a `// gutenberg patterns (Wave 4b)` group) AND the `'gutenberg'` array in `wpultra_ability_category_map()`; `tests/bootstrap.test.php` count 52 → 55. `patterns.php` added to the Gutenberg engine require loop in `wpultra_load_abilities()` (currently `['tree','engine','registry']`).

## Safety

- `wp_block` is a normal core CPT (NOT one of the plugin's reserved CPTs `wpultra_memory/skill/ability`), so `manage-reusable-block` may write it; the update path still guards that the target id is a `wp_block` before mutating, so it can't clobber an arbitrary post.
- `insert-pattern` writes to the given `post_id` via the Wave 4a save path (which already refuses the reserved CPTs).
- All mutating paths call `wpultra_audit_log`.

## Testing

- **Pure unit (zero-dep harness):** the parse→insert glue shape — a small helper `wpultra_gb_pattern_blocks(string $content): array` that returns the non-null top-level blocks of parsed content (stub `parse_blocks`), asserting whitespace chunks are skipped and count is right. (Registry/CPT calls are live-tested.)
- **Live (token-gated script on the running Local site):** `list-patterns` returns core patterns; `insert-pattern` of a known core pattern adds its blocks to a draft post (compact tree shows them); `manage-reusable-block` create→get→list round-trips; insert a `core/block` ref via the existing `gutenberg-insert-block` and confirm it serializes.

## Out of scope

- FSE templates / template-parts / theme.json global styles (Wave 4c).
- Authoring/registering NEW block patterns.
- Synced-block *detach* / conversion semantics (just CRUD + reference insert).
- Bricks / Elementor (separate).

## Success criteria

- `gutenberg-list-patterns` lists registered patterns (filterable); `gutenberg-insert-pattern` inserts a pattern's blocks at a positional path on a post.
- `gutenberg-manage-reusable-block` does create/update/get/list on `wp_block`, and a created reusable block can be referenced into a post via `core/block`.
- All existing tests stay green; new unit test green; live verification passes. Released as v0.9.0 (55 abilities).
