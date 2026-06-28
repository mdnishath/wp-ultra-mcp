# WP-Ultra-MCP — Wave 4: Gutenberg block control (design)

Date: 2026-06-28
Status: approved (brainstorming) — pending implementation plan
Target release: v0.5.0 (Phase 4a), v0.5.1 (4b), v0.5.2 (4c)

## Goal

Give AI clients **structured control over Gutenberg block content**, the same way Wave 2/3/3.5
gave structured control over Elementor. Today `create-post`/`update-post` only write a raw
`post_content` string — the AI must hand-author `<!-- wp:* -->` block markup with no parsing,
no validation, and no targeted edits. Wave 4 replaces that with a parsed block tree the AI can
inspect and mutate node-by-node, plus block-type discovery, patterns/reusable blocks, and Full
Site Editing (templates + global styles).

**Our edge over Novamira:** Novamira's Gutenberg path needs a human-open browser tab to
finalize. Wave 4 uses only core server APIs (`parse_blocks` / `serialize_blocks` /
`WP_Block_Type_Registry` / `wp_template` CPTs / `wp_global_styles`) — **no browser tab required**.

## Scope

In scope: Gutenberg only (Bricks is a separate later wave). All four capability groups:
block tree ops, block discovery + schema, patterns + reusable blocks, FSE (templates +
theme.json global styles).

Out of scope: Bricks builder; the visual block editor UI; client-side-only block attributes
that have no server registration (handled best-effort, see Error handling).

## Architecture

New directory `wp-ultra-mcp/includes/gutenberg/`, mirroring `includes/elementor/`:

- **`tree.php`** — pure, side-effect-free functions over the block array tree returned by
  `parse_blocks()`:
  - `wpultra_gb_compact_tree($blocks)` — annotate each block with its **path** and a compact
    summary (blockName, attrs, innerBlocks recursively).
  - `wpultra_gb_locate($blocks, $path)` — return `['parent_path', 'index', 'node']` for a path.
  - `wpultra_gb_insert($blocks, $parentPath, $pos, $block)`.
  - `wpultra_gb_remove($blocks, $path)`.
  - `wpultra_gb_move($blocks, $path, $toParentPath, $pos)` (remove-then-insert at final index,
    mirroring `wpultra_el_move` semantics).
  - `wpultra_gb_merge_attrs($blocks, $path, $attrs, $deep)`.
  - Depth-guarded (≤100) like the Elementor tree functions.
- **`registry.php`** — block-type discovery + attribute schema from
  `WP_Block_Type_Registry::get_instance()`.
- **`patterns.php`** — block patterns (`WP_Block_Patterns_Registry`) + reusable/synced blocks
  (the `wp_block` CPT).
- **`fse.php`** — templates / template-parts (`wp_template` / `wp_template_part` CPTs) and
  theme.json-level global styles (`wp_global_styles` CPT / `WP_Theme_JSON_Resolver`).

These are loaded from `bootstrap-mcp.php` alongside the Elementor includes. Each is independently
testable: tree.php is pure; registry/patterns/fse wrap core APIs behind small functions.

## Addressing scheme (the core decision)

Gutenberg blocks have **no persistent id** in `post_content` (clientIds are runtime-only).
Wave 4 addresses blocks by **positional path** — a string like `"0/2/1"` meaning
"root block 0 → its innerBlock 2 → its innerBlock 1". `gutenberg-get-content` returns the path
for every block, so the AI reads first, then edits by path.

Rationale: stateless, mutates none of the user's content, and simple. The one caveat — paths
shift if the tree is mutated between calls — is mitigated by the read-then-write workflow and by
each write returning the updated tree.

(Rejected alternatives: injecting stable ids into block `metadata` — robust but mutates user
content on every block; anchor/`id` attribute — only works for blocks that support it.)

## Write strategy

```
parse_blocks( $post->post_content )   →  tree (array)
   mutate tree by path
serialize_blocks( $tree )             →  post_content string
wp_update_post([...])                 →  save
return wpultra_gb_compact_tree( reparsed )   // confirm
```

Same flow for FSE: templates are posts (`wp_template` CPT), so read/mutate/serialize/save is
identical, just on the template post.

No `Document::save`-style stripping problem exists here — `serialize_blocks` is lossless and core.

## Abilities

All registered in `includes/abilities/`, one file per ability, `gutenberg-*` prefix, category
`gutenberg` (already registered in `bootstrap-mcp.php`, so the v0.4.0 per-category toggle governs
them). Each follows the proven contract: `execute_callback` / `input_schema` (plain array
properties) / `output_schema` / `permission_callback` / registered `category`.

### Phase 4a — core tree + schema (→ v0.5.0)

1. **`gutenberg-get-content`** — input `{post_id}`; output the compact block tree with a `path`
   per node.
2. **`gutenberg-list-blocks`** — list registered block types (name, title, category, supports).
   Optional `category` / `search` filter.
3. **`gutenberg-get-block-schema`** — input `{name}`; output that block type's `attributes`
   schema + supports.
4. **`gutenberg-insert-block`** — input `{post_id, parent_path?, position?, block}` where
   `block = {name, attributes?, innerBlocks?, innerHTML?}`; insert and return new tree.
5. **`gutenberg-update-block`** — input `{post_id, path, attributes?, innerHTML?, deep?}`;
   merge (or replace) attributes / innerHTML of the targeted block.
6. **`gutenberg-delete-block`** — input `{post_id, path}`.
7. **`gutenberg-move-block`** — input `{post_id, path, to_parent_path?, position}`.

### Phase 4b — patterns + reusable blocks (→ v0.5.1)

8. **`gutenberg-list-patterns`** — registered block patterns (title, name, categories, preview
   of block content).
9. **`gutenberg-insert-pattern`** — input `{post_id, pattern_name, parent_path?, position?}`;
   parse the pattern's `content` to blocks and insert them.
10. **`gutenberg-manage-reusable-block`** — actions create/update/get/list on the `wp_block`
    CPT; plus insert a `core/block` reference (`{ref: <id>}`) into a post at a path.

### Phase 4c — FSE (→ v0.5.2)

11. **`gutenberg-list-templates`** — `wp_template` + `wp_template_part` for the active block
    theme (slug, title, type, source).
12. **`gutenberg-get-template`** / **`gutenberg-set-template-content`** — read/write a template's
    block content using the same tree ops. Templates are resolved via the block-template resolver
    (`get_block_templates()` / `get_block_template()`), which returns both file-based (theme) and
    DB-stored templates. A file-based template is **materialized into a `wp_template` post on first
    write** (the standard FSE customization path), then edited like any other post.
13. **`gutenberg-manage-global-styles`** — read and merge theme.json-level global styles
    (the active theme's `wp_global_styles` post), the Gutenberg analog of the Elementor
    design-system abilities.

## Data flow

- **Read:** `post_content` → `parse_blocks` → `wpultra_gb_compact_tree` (paths attached).
- **Write:** locate by path → mutate tree → `serialize_blocks` → `wp_update_post` → re-parse →
  return updated tree.
- **Discovery:** registry/patterns/fse functions read core registries directly; no post involved.

## Error handling

- Invalid / out-of-range path → `wpultra_err('block_path_not_found', ...)`.
- Unknown block type on insert → **allowed** (Gutenberg permits unregistered blocks) but the
  response includes a `warning` that the block type is not registered.
- Attribute validation is **best-effort**: validate against the registry schema when the block
  type is server-registered; skip silently when it isn't (many attrs are JS-only). Never block a
  write solely because an attribute is unknown.
- FSE abilities → `wpultra_err('not_a_block_theme', ...)` if the active theme is not a block
  theme (checked via `wp_is_block_theme()`).
- Missing/invalid `post_id` → standard input error.

## Audit + security

Every mutating ability (insert/update/delete/move block, insert-pattern, manage-reusable-block
writes, set-template-content, manage-global-styles writes) calls `wpultra_audit_log()` (v0.4.0)
with action + a short summary + ok flag. Reads are not logged. All inherit the standard
`permission_callback` (authenticated, `edit_posts` / `edit_theme_options` as appropriate).

## Testing

- **Unit (zero-dep harness, `tests/gutenberg-tree.test.php`):** the pure tree functions —
  locate / insert / remove / move / merge-attrs by path, depth guard, round-trip
  parse→mutate→serialize equality. Added to `tests/run-all.ps1`.
- **Live (token-gated script on the Local `wp-connector` site):** registry discovery, a full
  insert→update→move→delete cycle on a real post, pattern insert, reusable-block create+insert,
  and — switching to / confirming a block theme (e.g. Twenty Twenty-Four) — FSE template read/write
  and global-styles read/merge. Script dropped at
  `…/app/public/wp-content/wpultra-*.php`, curled with the test token, then deleted (the standard
  Wave-2/3 live-test pattern).

## Skill

A `gutenberg` skill doc (parallel to the Elementor skills) teaching the AI the block model,
the positional-path addressing, and when to use each ability. Authored via the Skill Hub /
`skill-write`.

## Release plan

Phased, each phase independently shippable and live-verified before the next:
- **v0.5.0** — Phase 4a (core tree + schema), tree.php + registry.php + abilities 1–7 + unit tests.
- **v0.5.1** — Phase 4b (patterns + reusable), patterns.php + abilities 8–10.
- **v0.5.2** — Phase 4c (FSE), fse.php + abilities 11–13 + the gutenberg skill doc.

Each: feature branch → main (ff) → push → `bin/deploy.ps1` → `bin/build-zip.ps1` →
`gh release create`. Re-deploy after every commit (Local runs the deployed copy).
