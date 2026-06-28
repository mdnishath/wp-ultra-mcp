# WP-Ultra-MCP — Wave 4a (Gutenberg core block ops + schema) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> Phase 4a of Wave 4 (spec: `docs/superpowers/specs/2026-06-28-wp-ultra-wave4-gutenberg-design.md`). Ships as **v0.5.0**. Phases 4b (patterns/reusable) and 4c (FSE) get their own plans later. Builds on the shipped v0.4.0 plugin.

**Goal:** Server-side Gutenberg block engine + 7 MCP abilities so an AI can parse a post's block content into a positional-path tree, discover registered block types and their attribute schemas, and insert/update/delete/move blocks — all via core WordPress APIs with no browser tab.

**Architecture:** A small engine under `includes/gutenberg/`. `tree.php` holds pure, depth-guarded functions over the block array tree returned by core `parse_blocks()`, addressing nodes by **positional path** (array of ints, e.g. `[0,2,1]`). `engine.php` loads a post into that tree, serializes it back with core `serialize_blocks()`, and saves via `wp_update_post`. `registry.php` introspects `WP_Block_Type_Registry`. Seven thin abilities expose get-content / list-blocks / get-block-schema / insert / update / delete / move. Pure tree + normalize functions are unit-tested with the zero-dep harness; registry and save paths are live-tested on the Local site.

**Tech Stack:** Same as prior waves — PHP 8.0+, WP 6.6+ (target WP 7.0), vendored mcp-adapter, WordPress Abilities API. No new dependencies; Gutenberg block functions are WordPress core (≥5.0), so no plugin gating is needed.

## Global Constraints

- Every PHP file starts with `<?php` + `declare(strict_types=1);` + `if (!defined('ABSPATH')) { exit(); }`. Abilities return an array on success or `WP_Error` on failure.
- **Ability registration MUST exactly match the codebase shape** — see `includes/abilities/update-post.php`. The call is `wp_register_ability('wpultra/<slug>', [...])` (full namespaced id). Required keys: `label` (wrapped in `__()`), `description`, `category`, `input_schema`, `output_schema`, `execute_callback` (string name of a **named function** defined in the same file — NOT a closure), `permission_callback` => `'wpultra_permission_callback'`, and a **`meta`** block:
  ```php
  'meta' => [
      'show_in_rest' => true,
      'mcp'          => ['public' => true, 'type' => 'tool'],
      'annotations'  => ['readonly' => <bool>, 'destructive' => <bool>, 'idempotent' => <bool>],
  ],
  ```
  **The `meta.mcp.public => true` block is mandatory — without it the ability never surfaces as an MCP tool.**
- `input_schema` / `output_schema` are `['type'=>'object', 'properties'=>[...plain array...], 'required'=>[...], 'additionalProperties'=>false]`. `properties` MUST be a plain array, never an `(object)` cast.
- Success responses use `wpultra_ok([...])`; errors use `wpultra_err($code, $message)` (both in `includes/helpers.php`).
- Ability namespace `wpultra/gutenberg-*`, category `gutenberg` — **already registered** in `wpultra_register_categories()` (line ~88 of `bootstrap-mcp.php`). Do NOT re-add the category.
- **Bootstrap wiring requires THREE edits in `includes/bootstrap-mcp.php`:**
  1. `wpultra_ability_files()` — append the 7 slugs (with a `// gutenberg (Wave 4a)` comment).
  2. `wpultra_ability_category_map()` — add `'gutenberg' => [...7 slugs...]` so the v0.4.0 per-category toggle governs them (without this, `wpultra_file_category` returns `''` and the toggle can't disable them).
  3. `wpultra_load_abilities()` — add a `require_once` loop for the `includes/gutenberg/*.php` engine files, gated on the gutenberg category being enabled, mirroring the Elementor loop (lines ~104–109).
- Every **mutating** ability calls `wpultra_audit_log($action, $summary, $ok)` (in `includes/helpers.php`, shipped v0.4.0) after the write. Reads do NOT log.
- Bundled PHP for lint/tests: `$PHP = C:/Users/nisha/AppData/Roaming/Local/lightning-services/php-8.2.30+1/bin/win64/php.exe`. Test site root: `C:/Users/nisha/Local Sites/wp-connector/app/public`. Live-test token: `wpultra-test-9a88`.
- Re-run `wp-ultra-mcp/bin/deploy.ps1` after every commit (Local runs the deployed copy). Commands run from `E:\wp-connector`.
- **Test harness API** (`tests/harness.php`): `it($name, fn)` registers; `assert_eq($expected, $actual)` (strict `===`); `assert_true($cond)`; `assert_wp_error($val)`; the file ends with `run_tests();`. `WP_Error`, `is_wp_error`, `__()` are stubbed by the harness. Add new test files to `tests/run-all.ps1`. Do NOT redeclare functions the engine file already defines — `require` it.

## Verified WordPress core block API (use exactly these)

- `parse_blocks(string $content): array` — flat list of top-level blocks. Each: `['blockName' => string|null, 'attrs' => array, 'innerBlocks' => array, 'innerHTML' => string, 'innerContent' => array]`. `blockName` is `null` for freeform/whitespace chunks. `innerContent` interleaves literal HTML strings with `null` placeholders — one `null` per `innerBlocks` entry, in order.
- `serialize_blocks(array $blocks): string` — inverse of `parse_blocks`; lossless.
- `WP_Block_Type_Registry::get_instance()` → `->get_all_registered(): array` (`[name => WP_Block_Type]`), `->get_registered(string $name): ?WP_Block_Type`, `->is_registered(string $name): bool`.
- `WP_Block_Type` public props: `->name`, `->title`, `->category` (string|null), `->attributes` (array|null), `->supports` (array|null), `->parent` (array|null), `->description`.
- Post read: `get_post($post_id)->post_content`. Write: `wp_update_post(['ID'=>$post_id, 'post_content'=>$content], true)` → post id or `WP_Error`.

## File Structure

```
wp-ultra-mcp/includes/
  gutenberg/
    tree.php        pure path-addressed tree ops + block normalizer   — Task 1
    engine.php      post load/serialize/save → returns compact tree   — Task 2
    registry.php    block-type discovery + attribute schema           — Task 3
  abilities/
    gutenberg-get-content.php  gutenberg-list-blocks.php
    gutenberg-get-block-schema.php                                    — Task 4
    gutenberg-insert-block.php  gutenberg-update-block.php
    gutenberg-delete-block.php  gutenberg-move-block.php              — Task 5
tests/
  gutenberg-tree.test.php                                             — Task 1
```

Modify `includes/bootstrap-mcp.php` (3 edits, see Global Constraints) and `tests/run-all.ps1`.

---

### Task 1: Pure block-tree engine + normalizer (`tree.php`)

**Files:**
- Create: `wp-ultra-mcp/includes/gutenberg/tree.php`
- Test: `tests/gutenberg-tree.test.php`

**Interfaces:**
- Consumes: core `parse_blocks` (only inside the normalizer's `markup` branch; stubbed in tests).
- Produces:
  - `wpultra_gb_path_to_str(array $path): string` — `[0,2,1]`→`"0/2/1"`; `[]`→`""`.
  - `wpultra_gb_str_to_path(string $s): array` — `"0/2/1"`→`[0,2,1]`; `""`→`[]`; non-numeric segments dropped.
  - `wpultra_gb_compact_tree(array $blocks, array $prefix = []): array` — list; each `['path'=>string,'blockName'=>string,'attrs'=>array,'innerHTML'=>string,'innerBlocks'=>array(recursive)]`. **Skips** `blockName===null` entries but counts their index so paths match the raw array. Depth-guarded (≤100).
  - `wpultra_gb_locate(array $blocks, array $path): ?array` — `['parent_path'=>array,'index'=>int,'node'=>array]` or `null`.
  - `&wpultra_gb_ref(array &$blocks, array $parentPath)` — reference to the child array at `$parentPath` (`[]`=root); returns `null` ref if invalid.
  - `wpultra_gb_insert(array $blocks, array $parentPath, int $pos, array $block)` — new `$blocks` or `WP_Error('block_path_not_found')`. `$pos` clamped to `[0,count]`.
  - `wpultra_gb_remove(array $blocks, array $path)` — new `$blocks` or `WP_Error`.
  - `wpultra_gb_move(array $blocks, array $path, array $toParentPath, int $pos)` — remove then insert; `WP_Error` if invalid.
  - `wpultra_gb_merge_attrs(array $blocks, array $path, array $attrs, bool $deep)` — new `$blocks` or `WP_Error`.
  - `wpultra_gb_normalize_block(array $in)` — AI input → core block shape, or `WP_Error('block_invalid')`. Two modes (markup / structured).

- [ ] **Step 1: Write the failing test**

Create `tests/gutenberg-tree.test.php`:

```php
<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
// parse_blocks is only used by the normalizer 'markup' branch; minimal stub for tests.
if (!function_exists('parse_blocks')) {
    function parse_blocks($content) {
        if (preg_match('/<!--\s*wp:([a-z0-9\/-]+)/i', (string) $content, $m)) {
            return [['blockName' => $m[1], 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []]];
        }
        return [];
    }
}
require __DIR__ . '/../wp-ultra-mcp/includes/gutenberg/tree.php';

function gb_sample(): array {
    return [
        ['blockName' => 'core/paragraph', 'attrs' => ['content' => 'A'], 'innerBlocks' => [], 'innerHTML' => '<p>A</p>', 'innerContent' => ['<p>A</p>']],
        ['blockName' => 'core/group', 'attrs' => [], 'innerHTML' => '', 'innerContent' => [null, null], 'innerBlocks' => [
            ['blockName' => 'core/heading', 'attrs' => ['level' => 2], 'innerBlocks' => [], 'innerHTML' => '<h2>H</h2>', 'innerContent' => ['<h2>H</h2>']],
            ['blockName' => null, 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => "\n", 'innerContent' => ["\n"]],
        ]],
    ];
}

it('path<->str round-trips', function () {
    assert_eq('0/2/1', wpultra_gb_path_to_str([0, 2, 1]));
    assert_eq([0, 2, 1], wpultra_gb_str_to_path('0/2/1'));
    assert_eq([], wpultra_gb_str_to_path(''));
});

it('compact tree attaches paths and skips null-name blocks', function () {
    $t = wpultra_gb_compact_tree(gb_sample());
    assert_eq('0', $t[0]['path']);
    assert_eq('core/paragraph', $t[0]['blockName']);
    assert_eq('1', $t[1]['path']);
    assert_eq('1/0', $t[1]['innerBlocks'][0]['path']);   // heading
    assert_eq(1, count($t[1]['innerBlocks']));            // null-name child skipped
});

it('locate finds nested node with parent + index', function () {
    $loc = wpultra_gb_locate(gb_sample(), [1, 0]);
    assert_eq('core/heading', $loc['node']['blockName']);
    assert_eq([1], $loc['parent_path']);
    assert_eq(0, $loc['index']);
    assert_eq(null, wpultra_gb_locate(gb_sample(), [9]));
});

it('insert at root and nested', function () {
    $blk = ['blockName' => 'core/spacer', 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => ['']];
    $out = wpultra_gb_insert(gb_sample(), [], 1, $blk);
    assert_eq('core/spacer', $out[1]['blockName']);
    $out2 = wpultra_gb_insert(gb_sample(), [1], 0, $blk);
    assert_eq('core/spacer', $out2[1]['innerBlocks'][0]['blockName']);
    assert_wp_error(wpultra_gb_insert(gb_sample(), [9], 0, $blk));
});

it('remove deletes target', function () {
    $out = wpultra_gb_remove(gb_sample(), [0]);
    assert_eq('core/group', $out[0]['blockName']);
    assert_wp_error(wpultra_gb_remove(gb_sample(), [5]));
});

it('move relocates node to new parent and index', function () {
    $out = wpultra_gb_move(gb_sample(), [0], [1], 0); // paragraph into group at index 0
    assert_eq('core/paragraph', $out[0]['innerBlocks'][0]['blockName']);
});

it('merge_attrs shallow', function () {
    $out = wpultra_gb_merge_attrs(gb_sample(), [0], ['content' => 'B', 'align' => 'left'], false);
    assert_eq('B', $out[0]['attrs']['content']);
    assert_eq('left', $out[0]['attrs']['align']);
});

it('normalize structured leaf, container, and markup mode', function () {
    $leaf = wpultra_gb_normalize_block(['name' => 'core/paragraph', 'attributes' => ['content' => 'Hi'], 'inner_html' => '<p>Hi</p>']);
    assert_eq('core/paragraph', $leaf['blockName']);
    assert_eq(['<p>Hi</p>'], $leaf['innerContent']);
    $container = wpultra_gb_normalize_block(['name' => 'core/group', 'inner_blocks' => [['name' => 'core/spacer']]]);
    assert_eq([null], $container['innerContent']); // one null per inner block
    $fromMarkup = wpultra_gb_normalize_block(['markup' => '<!-- wp:separator --><hr/><!-- /wp:separator -->']);
    assert_eq('core/separator', $fromMarkup['blockName']);
    assert_wp_error(wpultra_gb_normalize_block([]));
});

run_tests();
```

- [ ] **Step 2: Run test to verify it fails**

Run: `& $PHP tests/gutenberg-tree.test.php`
Expected: FATAL/FAIL — `require ... gutenberg/tree.php` errors (file not found).

- [ ] **Step 3: Write minimal implementation**

Create `wp-ultra-mcp/includes/gutenberg/tree.php`:

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

function wpultra_gb_path_to_str(array $path): string {
    return implode('/', array_map('strval', $path));
}

function wpultra_gb_str_to_path(string $s): array {
    if ($s === '') { return []; }
    $out = [];
    foreach (explode('/', $s) as $seg) {
        if (is_numeric($seg)) { $out[] = (int) $seg; }
    }
    return $out;
}

function wpultra_gb_compact_tree(array $blocks, array $prefix = []): array {
    if (count($prefix) > 100) { return []; }
    $out = [];
    foreach ($blocks as $i => $b) {
        if (($b['blockName'] ?? null) === null) { continue; } // skip whitespace/freeform
        $path = array_merge($prefix, [$i]);
        $out[] = [
            'path'        => wpultra_gb_path_to_str($path),
            'blockName'   => (string) $b['blockName'],
            'attrs'       => (array) ($b['attrs'] ?? []),
            'innerHTML'   => (string) ($b['innerHTML'] ?? ''),
            'innerBlocks' => wpultra_gb_compact_tree((array) ($b['innerBlocks'] ?? []), $path),
        ];
    }
    return $out;
}

function wpultra_gb_locate(array $blocks, array $path): ?array {
    if (!$path) { return null; }
    $cur = $blocks;
    $parentPath = [];
    $n = count($path);
    for ($d = 0; $d < $n - 1; $d++) {
        $idx = $path[$d];
        if (!isset($cur[$idx]) || !is_array($cur[$idx])) { return null; }
        $parentPath[] = $idx;
        $cur = (array) ($cur[$idx]['innerBlocks'] ?? []);
    }
    $last = $path[$n - 1];
    if (!isset($cur[$last])) { return null; }
    return ['parent_path' => $parentPath, 'index' => $last, 'node' => $cur[$last]];
}

/** Reference to the child array at $parentPath ([]=root). Returns a null ref if any segment is invalid. */
function &wpultra_gb_ref(array &$blocks, array $parentPath) {
    $null = null;
    $ref = &$blocks;
    foreach ($parentPath as $idx) {
        if (!isset($ref[$idx]) || !is_array($ref[$idx])) { return $null; }
        if (!isset($ref[$idx]['innerBlocks']) || !is_array($ref[$idx]['innerBlocks'])) {
            $ref[$idx]['innerBlocks'] = [];
        }
        $ref = &$ref[$idx]['innerBlocks'];
    }
    return $ref;
}

function wpultra_gb_insert(array $blocks, array $parentPath, int $pos, array $block) {
    $ref = &wpultra_gb_ref($blocks, $parentPath);
    if ($ref === null) { return new WP_Error('block_path_not_found', 'Parent path not found: ' . wpultra_gb_path_to_str($parentPath)); }
    $count = count($ref);
    $pos = max(0, min($pos, $count));
    array_splice($ref, $pos, 0, [$block]);
    return $blocks;
}

function wpultra_gb_remove(array $blocks, array $path) {
    $loc = wpultra_gb_locate($blocks, $path);
    if (!$loc) { return new WP_Error('block_path_not_found', 'Path not found: ' . wpultra_gb_path_to_str($path)); }
    $ref = &wpultra_gb_ref($blocks, $loc['parent_path']);
    array_splice($ref, $loc['index'], 1);
    return $blocks;
}

function wpultra_gb_move(array $blocks, array $path, array $toParentPath, int $pos) {
    $loc = wpultra_gb_locate($blocks, $path);
    if (!$loc) { return new WP_Error('block_path_not_found', 'Source path not found: ' . wpultra_gb_path_to_str($path)); }
    $node = $loc['node'];
    $removed = wpultra_gb_remove($blocks, $path);
    if ($removed instanceof WP_Error) { return $removed; }
    // Within the same parent, removal already shifts later indices, so array_splice at $pos
    // yields the desired final index in every case.
    return wpultra_gb_insert($removed, $toParentPath, $pos, $node);
}

function wpultra_gb_merge_attrs(array $blocks, array $path, array $attrs, bool $deep) {
    $loc = wpultra_gb_locate($blocks, $path);
    if (!$loc) { return new WP_Error('block_path_not_found', 'Path not found: ' . wpultra_gb_path_to_str($path)); }
    $ref = &wpultra_gb_ref($blocks, $loc['parent_path']);
    $idx = $loc['index'];
    $existing = (array) ($ref[$idx]['attrs'] ?? []);
    $ref[$idx]['attrs'] = $deep ? array_replace_recursive($existing, $attrs) : array_merge($existing, $attrs);
    return $blocks;
}

function wpultra_gb_normalize_block(array $in) {
    // Mode 1: raw block markup — authoritative; correct innerContent for any block (incl. containers with wrappers).
    if (!empty($in['markup'])) {
        $parsed = parse_blocks((string) $in['markup']);
        foreach ($parsed as $b) {
            if (($b['blockName'] ?? null) !== null) { return $b; }
        }
        return new WP_Error('block_invalid', 'No block found in markup.');
    }
    // Mode 2: structured. Best for leaf blocks; containers get children-only innerContent.
    $name = (string) ($in['name'] ?? '');
    if ($name === '') { return new WP_Error('block_invalid', 'Block needs a `name` or `markup`.'); }
    $innerBlocks = [];
    foreach ((array) ($in['inner_blocks'] ?? []) as $child) {
        $c = wpultra_gb_normalize_block((array) $child);
        if ($c instanceof WP_Error) { return $c; }
        $innerBlocks[] = $c;
    }
    $innerHTML = (string) ($in['inner_html'] ?? '');
    $innerContent = $innerBlocks ? array_fill(0, count($innerBlocks), null) : [$innerHTML];
    return [
        'blockName'    => $name,
        'attrs'        => (array) ($in['attributes'] ?? []),
        'innerBlocks'  => $innerBlocks,
        'innerHTML'    => $innerHTML,
        'innerContent' => $innerContent,
    ];
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `& $PHP tests/gutenberg-tree.test.php`
Expected: `8 passed, 0 failed`.

- [ ] **Step 5: Register the test file and run the suite**

Edit `tests/run-all.ps1` to include `gutenberg-tree.test.php` (follow the existing file-list pattern). Run: `powershell -ExecutionPolicy Bypass -File tests/run-all.ps1`
Expected: `ALL TEST FILES PASSED`.

- [ ] **Step 6: Commit**

```bash
git add wp-ultra-mcp/includes/gutenberg/tree.php tests/gutenberg-tree.test.php tests/run-all.ps1
git commit -m "feat(gutenberg): pure path-addressed block tree engine + tests"
```

---

### Task 2: Post load/serialize/save engine (`engine.php`)

**Files:**
- Create: `wp-ultra-mcp/includes/gutenberg/engine.php`
- Test: live-tested in Task 6 (depends on core `parse_blocks`/`wp_update_post`).

**Interfaces:**
- Consumes: `wpultra_gb_compact_tree` (Task 1); core `parse_blocks`, `serialize_blocks`, `get_post`, `wp_update_post`.
- Produces:
  - `wpultra_gb_load(int $post_id)` — `['post'=>WP_Post,'blocks'=>array]` (raw parsed, incl. null-name) or `WP_Error('post_not_found')`.
  - `wpultra_gb_save(int $post_id, array $blocks)` — serialize + `wp_update_post`; returns the **compact** tree of the re-parsed content, or `WP_Error`.
  - `wpultra_gb_tree(int $post_id)` — load + compact; compact tree or `WP_Error`.

- [ ] **Step 1: Write the implementation**

Create `wp-ultra-mcp/includes/gutenberg/engine.php`:

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

function wpultra_gb_load(int $post_id) {
    $post = get_post($post_id);
    if (!$post) { return new WP_Error('post_not_found', "Post $post_id not found."); }
    return ['post' => $post, 'blocks' => parse_blocks((string) $post->post_content)];
}

function wpultra_gb_save(int $post_id, array $blocks) {
    $content = serialize_blocks($blocks);
    $res = wp_update_post(['ID' => $post_id, 'post_content' => $content], true);
    if (is_wp_error($res)) { return $res; }
    $reloaded = wpultra_gb_load($post_id);
    if (is_wp_error($reloaded)) { return $reloaded; }
    return wpultra_gb_compact_tree($reloaded['blocks']);
}

function wpultra_gb_tree(int $post_id) {
    $loaded = wpultra_gb_load($post_id);
    if (is_wp_error($loaded)) { return $loaded; }
    return wpultra_gb_compact_tree($loaded['blocks']);
}
```

- [ ] **Step 2: Lint**

Run: `& $PHP -l wp-ultra-mcp/includes/gutenberg/engine.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add wp-ultra-mcp/includes/gutenberg/engine.php
git commit -m "feat(gutenberg): post load/serialize/save engine"
```

---

### Task 3: Block-type discovery + schema (`registry.php`)

**Files:**
- Create: `wp-ultra-mcp/includes/gutenberg/registry.php`
- Test: live-tested in Task 6 (depends on `WP_Block_Type_Registry`).

**Interfaces:**
- Consumes: core `WP_Block_Type_Registry`.
- Produces:
  - `wpultra_gb_list_block_types(string $search = '', string $category = ''): array` — list of `['name','title','category','parent']`, filtered by case-insensitive substring on `name.' '.title` and exact `$category`.
  - `wpultra_gb_block_schema(string $name)` — `['name','title','category','description','attributes'=>array,'supports'=>array,'parent'=>array]` or `WP_Error('block_type_not_found')`.
  - `wpultra_gb_is_registered(string $name): bool`.

- [ ] **Step 1: Write the implementation**

Create `wp-ultra-mcp/includes/gutenberg/registry.php`:

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

function wpultra_gb_is_registered(string $name): bool {
    return \WP_Block_Type_Registry::get_instance()->is_registered($name);
}

function wpultra_gb_list_block_types(string $search = '', string $category = ''): array {
    $all = \WP_Block_Type_Registry::get_instance()->get_all_registered();
    $search = strtolower(trim($search));
    $out = [];
    foreach ($all as $name => $bt) {
        $title = (string) ($bt->title ?? '');
        if ($category !== '' && (string) ($bt->category ?? '') !== $category) { continue; }
        if ($search !== '' && strpos(strtolower($name . ' ' . $title), $search) === false) { continue; }
        $out[] = [
            'name'     => (string) $name,
            'title'    => $title,
            'category' => (string) ($bt->category ?? ''),
            'parent'   => is_array($bt->parent ?? null) ? $bt->parent : [],
        ];
    }
    usort($out, fn($a, $b) => strcmp($a['name'], $b['name']));
    return $out;
}

function wpultra_gb_block_schema(string $name) {
    $bt = \WP_Block_Type_Registry::get_instance()->get_registered($name);
    if (!$bt) { return new WP_Error('block_type_not_found', "Block type '$name' is not registered."); }
    return [
        'name'        => (string) $bt->name,
        'title'       => (string) ($bt->title ?? ''),
        'category'    => (string) ($bt->category ?? ''),
        'description' => (string) ($bt->description ?? ''),
        'attributes'  => is_array($bt->attributes ?? null) ? $bt->attributes : [],
        'supports'    => is_array($bt->supports ?? null) ? $bt->supports : [],
        'parent'      => is_array($bt->parent ?? null) ? $bt->parent : [],
    ];
}
```

- [ ] **Step 2: Lint**

Run: `& $PHP -l wp-ultra-mcp/includes/gutenberg/registry.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add wp-ultra-mcp/includes/gutenberg/registry.php
git commit -m "feat(gutenberg): block-type discovery + attribute schema"
```

---

### Task 4: Read abilities (get-content, list-blocks, get-block-schema) + bootstrap wiring

**Files:**
- Create: `wp-ultra-mcp/includes/abilities/gutenberg-get-content.php`, `gutenberg-list-blocks.php`, `gutenberg-get-block-schema.php`
- Modify: `wp-ultra-mcp/includes/bootstrap-mcp.php` (the 3 wiring edits from Global Constraints)

**Interfaces:**
- Consumes: `wpultra_gb_tree` (Task 2), `wpultra_gb_list_block_types`, `wpultra_gb_block_schema` (Task 3); `wpultra_ok`, `wpultra_permission_callback`.
- Produces: abilities `wpultra/gutenberg-get-content`, `wpultra/gutenberg-list-blocks`, `wpultra/gutenberg-get-block-schema`.

- [ ] **Step 1: Write `gutenberg-get-content.php`**

Create `wp-ultra-mcp/includes/abilities/gutenberg-get-content.php`:

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/gutenberg-get-content', [
    'label'       => __('Gutenberg: Get Block Content', 'wp-ultra-mcp'),
    'description' => __('Parse a post/page into a positional-path block tree.', 'wp-ultra-mcp'),
    'category'    => 'gutenberg',
    'input_schema' => [
        'type'       => 'object',
        'properties' => ['post_id' => ['type' => 'integer']],
        'required'   => ['post_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => ['success' => ['type' => 'boolean'], 'blocks' => ['type' => 'array']],
        'required'   => ['success'],
    ],
    'execute_callback'    => 'wpultra_gb_get_content',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_gb_get_content(array $input) {
    $tree = wpultra_gb_tree((int) ($input['post_id'] ?? 0));
    if (is_wp_error($tree)) { return $tree; }
    return wpultra_ok(['blocks' => $tree]);
}
```

- [ ] **Step 2: Write `gutenberg-list-blocks.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/gutenberg-list-blocks', [
    'label'       => __('Gutenberg: List Block Types', 'wp-ultra-mcp'),
    'description' => __('List registered block types, optionally filtered by search/category.', 'wp-ultra-mcp'),
    'category'    => 'gutenberg',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'search'   => ['type' => 'string'],
            'category' => ['type' => 'string'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => ['success' => ['type' => 'boolean'], 'count' => ['type' => 'integer'], 'blocks' => ['type' => 'array']],
        'required'   => ['success'],
    ],
    'execute_callback'    => 'wpultra_gb_list_blocks_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_gb_list_blocks_cb(array $input) {
    $blocks = wpultra_gb_list_block_types((string) ($input['search'] ?? ''), (string) ($input['category'] ?? ''));
    return wpultra_ok(['count' => count($blocks), 'blocks' => $blocks]);
}
```

- [ ] **Step 3: Write `gutenberg-get-block-schema.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/gutenberg-get-block-schema', [
    'label'       => __('Gutenberg: Get Block Schema', 'wp-ultra-mcp'),
    'description' => __('Get the attribute schema + supports for one block type.', 'wp-ultra-mcp'),
    'category'    => 'gutenberg',
    'input_schema' => [
        'type'       => 'object',
        'properties' => ['name' => ['type' => 'string']],
        'required'   => ['name'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => ['success' => ['type' => 'boolean'], 'attributes' => ['type' => 'object'], 'supports' => ['type' => 'object']],
        'required'   => ['success'],
    ],
    'execute_callback'    => 'wpultra_gb_get_block_schema_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_gb_get_block_schema_cb(array $input) {
    $schema = wpultra_gb_block_schema((string) ($input['name'] ?? ''));
    if (is_wp_error($schema)) { return $schema; }
    return wpultra_ok($schema);
}
```

- [ ] **Step 4: Wire bootstrap (3 edits)**

In `wp-ultra-mcp/includes/bootstrap-mcp.php`:
1. In `wpultra_ability_files()`, before the closing `];`, add:
   ```php
       // gutenberg read abilities (Wave 4a)
       'gutenberg-get-content', 'gutenberg-list-blocks', 'gutenberg-get-block-schema',
   ```
2. In `wpultra_ability_category_map()`, add a new entry:
   ```php
       'gutenberg' => [
           'gutenberg-get-content', 'gutenberg-list-blocks', 'gutenberg-get-block-schema',
       ],
   ```
3. In `wpultra_load_abilities()`, right after the Elementor engine `require_once` loop (the `foreach (['setup', ...] as $elf)` block), add:
   ```php
       if (!in_array('gutenberg', $disabled, true)) {
           foreach (['tree', 'engine', 'registry'] as $gbf) {
               $gbp = WPULTRA_DIR . 'includes/gutenberg/' . $gbf . '.php';
               if (is_readable($gbp)) { require_once $gbp; }
           }
       }
   ```

- [ ] **Step 5: Lint all changed files**

Run `& $PHP -l` on the 3 new ability files and `bootstrap-mcp.php`.
Expected: `No syntax errors detected` for each.

- [ ] **Step 6: Deploy + commit**

```bash
powershell -ExecutionPolicy Bypass -File wp-ultra-mcp/bin/deploy.ps1
git add wp-ultra-mcp/includes/abilities/gutenberg-get-content.php wp-ultra-mcp/includes/abilities/gutenberg-list-blocks.php wp-ultra-mcp/includes/abilities/gutenberg-get-block-schema.php wp-ultra-mcp/includes/bootstrap-mcp.php
git commit -m "feat(gutenberg): read abilities (get-content, list-blocks, get-block-schema) + wiring"
```

---

### Task 5: Write abilities (insert, update, delete, move)

**Files:**
- Create: `wp-ultra-mcp/includes/abilities/gutenberg-insert-block.php`, `gutenberg-update-block.php`, `gutenberg-delete-block.php`, `gutenberg-move-block.php`
- Modify: `wp-ultra-mcp/includes/bootstrap-mcp.php` (`wpultra_ability_files()` + the `'gutenberg'` entry in `wpultra_ability_category_map()` — append the 4 write slugs to both).

**Interfaces:**
- Consumes: `wpultra_gb_load`, `wpultra_gb_save` (Task 2); `wpultra_gb_normalize_block`, `wpultra_gb_insert`, `wpultra_gb_merge_attrs`, `wpultra_gb_remove`, `wpultra_gb_move`, `wpultra_gb_locate`, `wpultra_gb_ref`, `wpultra_gb_str_to_path` (Task 1); `wpultra_gb_is_registered` (Task 3); `wpultra_audit_log`, `wpultra_ok` (helpers).
- Produces: abilities `wpultra/gutenberg-insert-block|update-block|delete-block|move-block`.

- [ ] **Step 1: Write `gutenberg-insert-block.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/gutenberg-insert-block', [
    'label'       => __('Gutenberg: Insert Block', 'wp-ultra-mcp'),
    'description' => __('Insert a block at a parent path + position. Provide block.markup (raw) or block.name/attributes/inner_blocks/inner_html.', 'wp-ultra-mcp'),
    'category'    => 'gutenberg',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'post_id'     => ['type' => 'integer'],
            'parent_path' => ['type' => 'string'],
            'position'    => ['type' => 'integer'],
            'block'       => ['type' => 'object'],
        ],
        'required'   => ['post_id', 'block'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => ['success' => ['type' => 'boolean'], 'blocks' => ['type' => 'array'], 'warning' => ['type' => 'string']],
        'required'   => ['success'],
    ],
    'execute_callback'    => 'wpultra_gb_insert_block_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_gb_insert_block_cb(array $input) {
    $post_id = (int) ($input['post_id'] ?? 0);
    $loaded = wpultra_gb_load($post_id);
    if (is_wp_error($loaded)) { return $loaded; }
    $block = wpultra_gb_normalize_block((array) ($input['block'] ?? []));
    if (is_wp_error($block)) { return $block; }
    $warning = (!empty($block['blockName']) && !wpultra_gb_is_registered($block['blockName']))
        ? "Block type '{$block['blockName']}' is not registered (allowed, but verify the name)." : '';
    $parentPath = wpultra_gb_str_to_path((string) ($input['parent_path'] ?? ''));
    $pos = isset($input['position']) ? (int) $input['position'] : PHP_INT_MAX;
    $updated = wpultra_gb_insert($loaded['blocks'], $parentPath, $pos, $block);
    if (is_wp_error($updated)) { return $updated; }
    $tree = wpultra_gb_save($post_id, $updated);
    wpultra_audit_log('gutenberg-insert-block', "post $post_id <- " . ($block['blockName'] ?? '?') . ' @ ' . (string) ($input['parent_path'] ?? '') . "/$pos", !is_wp_error($tree));
    if (is_wp_error($tree)) { return $tree; }
    $res = ['blocks' => $tree];
    if ($warning !== '') { $res['warning'] = $warning; }
    return wpultra_ok($res);
}
```

- [ ] **Step 2: Write `gutenberg-update-block.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/gutenberg-update-block', [
    'label'       => __('Gutenberg: Update Block', 'wp-ultra-mcp'),
    'description' => __('Merge attributes (and optionally innerHTML) of the block at a path.', 'wp-ultra-mcp'),
    'category'    => 'gutenberg',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'post_id'    => ['type' => 'integer'],
            'path'       => ['type' => 'string'],
            'attributes' => ['type' => 'object'],
            'inner_html' => ['type' => 'string'],
            'deep'       => ['type' => 'boolean'],
        ],
        'required'   => ['post_id', 'path'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => ['success' => ['type' => 'boolean'], 'blocks' => ['type' => 'array']],
        'required'   => ['success'],
    ],
    'execute_callback'    => 'wpultra_gb_update_block_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_gb_update_block_cb(array $input) {
    $post_id = (int) ($input['post_id'] ?? 0);
    $loaded = wpultra_gb_load($post_id);
    if (is_wp_error($loaded)) { return $loaded; }
    $path = wpultra_gb_str_to_path((string) ($input['path'] ?? ''));
    $blocks = $loaded['blocks'];
    if (isset($input['attributes']) && is_array($input['attributes'])) {
        $blocks = wpultra_gb_merge_attrs($blocks, $path, (array) $input['attributes'], !empty($input['deep']));
        if (is_wp_error($blocks)) { return $blocks; }
    }
    if (isset($input['inner_html'])) {
        $loc = wpultra_gb_locate($blocks, $path);
        if (!$loc) { return wpultra_err('block_path_not_found', 'Path not found: ' . (string) ($input['path'] ?? '')); }
        $ref = &wpultra_gb_ref($blocks, $loc['parent_path']);
        $ref[$loc['index']]['innerHTML']    = (string) $input['inner_html'];
        $ref[$loc['index']]['innerContent'] = [(string) $input['inner_html']];
        unset($ref);
    }
    $tree = wpultra_gb_save($post_id, $blocks);
    wpultra_audit_log('gutenberg-update-block', "post $post_id @ " . (string) ($input['path'] ?? ''), !is_wp_error($tree));
    if (is_wp_error($tree)) { return $tree; }
    return wpultra_ok(['blocks' => $tree]);
}
```

- [ ] **Step 3: Write `gutenberg-delete-block.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/gutenberg-delete-block', [
    'label'       => __('Gutenberg: Delete Block', 'wp-ultra-mcp'),
    'description' => __('Remove the block at a positional path.', 'wp-ultra-mcp'),
    'category'    => 'gutenberg',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'path'    => ['type' => 'string'],
        ],
        'required'   => ['post_id', 'path'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => ['success' => ['type' => 'boolean'], 'blocks' => ['type' => 'array']],
        'required'   => ['success'],
    ],
    'execute_callback'    => 'wpultra_gb_delete_block_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_gb_delete_block_cb(array $input) {
    $post_id = (int) ($input['post_id'] ?? 0);
    $loaded = wpultra_gb_load($post_id);
    if (is_wp_error($loaded)) { return $loaded; }
    $updated = wpultra_gb_remove($loaded['blocks'], wpultra_gb_str_to_path((string) ($input['path'] ?? '')));
    if (is_wp_error($updated)) { return $updated; }
    $tree = wpultra_gb_save($post_id, $updated);
    wpultra_audit_log('gutenberg-delete-block', "post $post_id @ " . (string) ($input['path'] ?? ''), !is_wp_error($tree));
    if (is_wp_error($tree)) { return $tree; }
    return wpultra_ok(['blocks' => $tree]);
}
```

- [ ] **Step 4: Write `gutenberg-move-block.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/gutenberg-move-block', [
    'label'       => __('Gutenberg: Move Block', 'wp-ultra-mcp'),
    'description' => __('Move the block at a path to a new parent path + position.', 'wp-ultra-mcp'),
    'category'    => 'gutenberg',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'post_id'        => ['type' => 'integer'],
            'path'           => ['type' => 'string'],
            'to_parent_path' => ['type' => 'string'],
            'position'       => ['type' => 'integer'],
        ],
        'required'   => ['post_id', 'path', 'position'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => ['success' => ['type' => 'boolean'], 'blocks' => ['type' => 'array']],
        'required'   => ['success'],
    ],
    'execute_callback'    => 'wpultra_gb_move_block_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_gb_move_block_cb(array $input) {
    $post_id = (int) ($input['post_id'] ?? 0);
    $loaded = wpultra_gb_load($post_id);
    if (is_wp_error($loaded)) { return $loaded; }
    $updated = wpultra_gb_move(
        $loaded['blocks'],
        wpultra_gb_str_to_path((string) ($input['path'] ?? '')),
        wpultra_gb_str_to_path((string) ($input['to_parent_path'] ?? '')),
        (int) ($input['position'] ?? 0)
    );
    if (is_wp_error($updated)) { return $updated; }
    $tree = wpultra_gb_save($post_id, $updated);
    wpultra_audit_log('gutenberg-move-block', "post $post_id " . (string) ($input['path'] ?? '') . ' -> ' . (string) ($input['to_parent_path'] ?? '') . '/' . (int) ($input['position'] ?? 0), !is_wp_error($tree));
    if (is_wp_error($tree)) { return $tree; }
    return wpultra_ok(['blocks' => $tree]);
}
```

- [ ] **Step 5: Wire bootstrap**

In `bootstrap-mcp.php`, append the 4 write slugs to BOTH `wpultra_ability_files()` (under a `// gutenberg write abilities (Wave 4a)` comment) and the `'gutenberg'` entry in `wpultra_ability_category_map()`:
`'gutenberg-insert-block', 'gutenberg-update-block', 'gutenberg-delete-block', 'gutenberg-move-block'`.

- [ ] **Step 6: Lint + deploy**

Run `& $PHP -l` on each of the 4 files + `bootstrap-mcp.php`. Then `powershell -ExecutionPolicy Bypass -File wp-ultra-mcp/bin/deploy.ps1`.
Expected: no syntax errors; "Deployed to ...".

- [ ] **Step 7: Commit**

```bash
git add wp-ultra-mcp/includes/abilities/gutenberg-insert-block.php wp-ultra-mcp/includes/abilities/gutenberg-update-block.php wp-ultra-mcp/includes/abilities/gutenberg-delete-block.php wp-ultra-mcp/includes/abilities/gutenberg-move-block.php wp-ultra-mcp/includes/bootstrap-mcp.php
git commit -m "feat(gutenberg): write abilities (insert, update, delete, move)"
```

---

### Task 6: Live verification on the Local site

**Files:**
- Create (temporary): `C:/Users/nisha/Local Sites/wp-connector/app/public/wp-content/wpultra-gbverify.php` — deleted at the end.

**Interfaces:**
- Consumes: every engine + registry function from Tasks 1–3 on the real WordPress runtime.
- Produces: JSON confirmation that discovery + the full insert→update→move→delete cycle work and round-trip through `serialize_blocks`.

- [ ] **Step 1: Ensure the Local site is running**

Confirm `http://wp-connector.local/` returns 200 (start it in Local if not). The plugin must be deployed (Tasks 4/5 ran deploy).

- [ ] **Step 2: Write the token-gated live test script**

Create `…/wp-content/wpultra-gbverify.php`:

```php
<?php
if (($_GET['t'] ?? '') !== 'wpultra-test-9a88') { http_response_code(403); exit('forbidden'); }
require dirname(__DIR__) . '/wp-load.php';
header('Content-Type: application/json');
$admin = get_users(['role' => 'administrator', 'number' => 1]);
if ($admin) { wp_set_current_user($admin[0]->ID); }
$p = WP_PLUGIN_DIR . '/wp-ultra-mcp';
require_once $p . '/includes/helpers.php';
require_once $p . '/includes/gutenberg/tree.php';
require_once $p . '/includes/gutenberg/engine.php';
require_once $p . '/includes/gutenberg/registry.php';

$out = [];
$out['list_has_paragraph'] = (bool) array_filter(wpultra_gb_list_block_types('paragraph'), fn($b) => $b['name'] === 'core/paragraph');
$sch = wpultra_gb_block_schema('core/heading');
$out['heading_schema_ok'] = !is_wp_error($sch) && isset($sch['attributes']);

$pid = wp_insert_post(['post_title' => 'gb-verify', 'post_status' => 'draft',
    'post_content' => "<!-- wp:paragraph --><p>One</p><!-- /wp:paragraph -->\n<!-- wp:paragraph --><p>Two</p><!-- /wp:paragraph -->"]);
$out['parsed_two'] = count(wpultra_gb_tree($pid)) === 2;

$loaded = wpultra_gb_load($pid);
$blk = wpultra_gb_normalize_block(['markup' => '<!-- wp:heading --><h2>Title</h2><!-- /wp:heading -->']);
$t1 = wpultra_gb_save($pid, wpultra_gb_insert($loaded['blocks'], [], 1, $blk));
$out['after_insert'] = is_wp_error($t1) ? $t1->get_error_message() : array_map(fn($b) => $b['blockName'], $t1);

$loaded = wpultra_gb_load($pid);
$t2 = wpultra_gb_save($pid, wpultra_gb_merge_attrs($loaded['blocks'], [1], ['level' => 3], false));
$out['after_update_level'] = is_wp_error($t2) ? $t2->get_error_message() : ($t2[1]['attrs']['level'] ?? null);

$loaded = wpultra_gb_load($pid);
$t3 = wpultra_gb_save($pid, wpultra_gb_move($loaded['blocks'], [1], [], 0));
$out['after_move'] = is_wp_error($t3) ? $t3->get_error_message() : array_map(fn($b) => $b['blockName'], $t3);

$loaded = wpultra_gb_load($pid);
$t4 = wpultra_gb_save($pid, wpultra_gb_remove($loaded['blocks'], [0]));
$out['after_delete'] = is_wp_error($t4) ? $t4->get_error_message() : array_map(fn($b) => $b['blockName'], $t4);

wp_delete_post($pid, true);
echo json_encode($out, JSON_PRETTY_PRINT);
```

- [ ] **Step 3: Run it**

Run: `curl -s "http://wp-connector.local/wp-content/wpultra-gbverify.php?t=wpultra-test-9a88"`
Expected: `list_has_paragraph: true`, `heading_schema_ok: true`, `parsed_two: true`, `after_insert: ["core/paragraph","core/heading","core/paragraph"]`, `after_update_level: 3`, `after_move: ["core/heading","core/paragraph","core/paragraph"]`, `after_delete: ["core/paragraph","core/paragraph"]`.

- [ ] **Step 4: Fix any failures**

If a step returns a `WP_Error` message instead of the expected array, debug the relevant engine function (most likely `wpultra_gb_ref` reference handling or `serialize_blocks` innerContent), fix, re-deploy, re-run. Do not proceed until all keys match.

- [ ] **Step 5: Delete the test script**

Run: `rm "C:/Users/nisha/Local Sites/wp-connector/app/public/wp-content/wpultra-gbverify.php"`

- [ ] **Step 6: Commit (only if engine fixes were made)**

```bash
git add -A
git commit -m "fix(gutenberg): live-verification fixes for block engine"
```

---

### Task 7: Docs + readme + version bump + release v0.5.0

**Files:**
- Modify: `wp-ultra-mcp/wp-ultra-mcp.php` (version header + `WPULTRA_VERSION`), `wp-ultra-mcp/readme.txt` (stable tag + changelog), `README.md` (ability count + Gutenberg section).

**Interfaces:** Consumes nothing. Produces a tagged GitHub release.

- [ ] **Step 1: Bump versions**

Set version `0.5.0` in `wp-ultra-mcp/wp-ultra-mcp.php` (the `Version:` header and the `WPULTRA_VERSION` constant — grep for the current `0.4.0` to find both) and `Stable tag: 0.5.0` in `wp-ultra-mcp/readme.txt`.

- [ ] **Step 2: Update changelog + README**

Add a `= 0.5.0 =` entry to `readme.txt` listing the 7 Gutenberg abilities (get-content, list-blocks, get-block-schema, insert/update/delete/move-block). Update the ability count and add a short "Gutenberg block control" bullet list to `README.md`.

- [ ] **Step 3: Deploy + run full test suite**

Run: `powershell -ExecutionPolicy Bypass -File wp-ultra-mcp/bin/deploy.ps1` then `powershell -ExecutionPolicy Bypass -File tests/run-all.ps1`.
Expected: `ALL TEST FILES PASSED`.

- [ ] **Step 4: Commit, build zip, release**

```bash
git add -A
git commit -m "docs(gutenberg): Wave 4a — 7 block abilities, v0.5.0, readme/changelog"
git push origin main
powershell -ExecutionPolicy Bypass -File wp-ultra-mcp/bin/build-zip.ps1
gh release create v0.5.0 --title "v0.5.0 — Gutenberg core block control" --notes "Wave 4a: parse/insert/update/delete/move blocks by positional path + block-type discovery & schema. Core WordPress APIs only — no browser tab."
```

(If `build-zip.ps1` emits a zip path, attach it to the release as prior waves did.)

---

## Self-Review

**Spec coverage:**
- Block tree ops → Tasks 1,2,5 ✓ · Block discovery + schema → Tasks 3,4 ✓ · Positional-path addressing → Task 1 ✓ · Write strategy (parse→mutate→serialize→save) → Task 2 ✓ · Best-effort validation / unknown-block warning → Task 5 insert (`warning`) ✓ · Audit on writes → Task 5 ✓ · Per-category toggle wiring → Task 4 Step 4 (category_map) ✓ · Unit + live tests → Tasks 1,6 ✓ · Release v0.5.0 → Task 7 ✓.
- Patterns/reusable (4b), FSE (4c), and the gutenberg skill doc are explicitly **out of this plan** — separate later plans, consistent with the phased release in the spec.

**Placeholder scan:** No TBD/TODO/"handle edge cases". Every code step shows complete code; every command shows expected output.

**Type/name consistency:**
- Registration uses the verified codebase shape: `wp_register_ability('wpultra/<slug>', [...])`, named `execute_callback` functions, and the mandatory `meta.mcp.public=>true` block (checked against `update-post.php`).
- Engine functions (`wpultra_gb_*` in tree/engine/registry) are distinct from ability callbacks (`wpultra_gb_*_cb` / `wpultra_gb_get_content`) — no collisions. `wpultra_gb_ref` shares the same `(&$blocks, $parentPath)` signature everywhere it's used (tree insert/remove/merge + update-block innerHTML).
- Path is an int-array internally, string at ability boundaries (`wpultra_gb_str_to_path` converts in every ability).
- Harness calls match `tests/harness.php`: `it`, `assert_eq($expected,$actual)`, `assert_wp_error`, `run_tests()`.
- Bootstrap wiring covers all three required sites (`wpultra_ability_files`, `wpultra_ability_category_map`, `wpultra_load_abilities` require-loop).
```
