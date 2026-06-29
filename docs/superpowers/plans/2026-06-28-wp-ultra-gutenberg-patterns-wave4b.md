# WP-Ultra-MCP — Gutenberg Patterns + Reusable Blocks (Wave 4b) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> Wave 4b of the Gutenberg arc (spec: `docs/superpowers/specs/2026-06-28-wp-ultra-gutenberg-patterns-wave4b.md`). Ships as v0.9.0. Builds on the shipped v0.8.0 plugin (Wave 4a core block ops).

**Goal:** Three abilities — `gutenberg-list-patterns`, `gutenberg-insert-pattern`, `gutenberg-manage-reusable-block` — that let the AI insert registered block patterns and manage `wp_block` reusable/synced blocks, reusing the Wave 4a block engine.

**Architecture:** One new engine file `includes/gutenberg/patterns.php` wraps `WP_Block_Patterns_Registry` and the `wp_block` CPT behind small functions, plus a pure `wpultra_gb_pattern_blocks()` that turns parsed pattern content into a list of top-level blocks. Three thin abilities call them; insert-pattern parses a pattern then inserts each block via the Wave 4a engine.

**Tech Stack:** PHP 8.0+, WP 6.6+ (target WP 7.0), core Gutenberg block + pattern APIs, vendored mcp-adapter, WordPress Abilities API. No new dependencies.

## Global Constraints

- Every PHP file starts with `<?php` + `declare(strict_types=1);` + `if (!defined('ABSPATH')) { exit(); }`. Engine functions return arrays/values or `WP_Error`; abilities return `wpultra_ok([...])` or `wpultra_err($code,$message,$data='')`.
- **Ability registration MUST match the codebase shape** — see `includes/abilities/gutenberg-insert-block.php` (mutating) and `includes/abilities/gutenberg-list-blocks.php` (read). `wp_register_ability('wpultra/<slug>',[...])` with `label`/`description` in `__()`, `category => 'gutenberg'`, `input_schema`, `output_schema`, named `execute_callback` (string, NOT closure), `permission_callback => 'wpultra_permission_callback'`, and the mandatory `meta` block with `mcp => ['public'=>true,'type'=>'tool']`. `properties` MUST be a plain array.
- `gutenberg-list-patterns` is read-only (readonly=>true, destructive=>false, idempotent=>true; NO audit). `gutenberg-insert-pattern` is mutating (readonly=>false, destructive=>false, idempotent=>false; audit after write). `gutenberg-manage-reusable-block` is mutating on create/update (audit those), read on get/list.
- The `gutenberg` category is already registered — do NOT re-add it.
- **Bootstrap wiring:** the three slugs go in `wpultra_ability_files()` AND the `'gutenberg'` array in `wpultra_ability_category_map()`; add `patterns` to the Gutenberg engine require loop in `wpultra_load_abilities()` (currently `['tree','engine','registry']`). `tests/bootstrap.test.php` asserts the EXACT count (`52` today) and that the category map covers every file once — bump to `55` and keep the map in sync.
- **Reuse, do not reinvent:** `wpultra_gb_load(int): array|WP_Error` (returns `['post'=>WP_Post,'blocks'=>array]`), `wpultra_gb_save(int,array): array|WP_Error` (returns the compact tree), `wpultra_gb_insert(array,array,int,array)` (positional path), `wpultra_gb_str_to_path(string): array`, `wpultra_gb_compact_tree(array): array` — all from Wave 4a (`includes/gutenberg/tree.php`/`engine.php`). Core `parse_blocks`. Call them.
- Bundled PHP for lint/tests: `$PHP = C:/Users/nisha/AppData/Roaming/Local/lightning-services/php-8.2.30+1/bin/win64/php.exe`. Test site root: `C:/Users/nisha/Local Sites/wp-connector/app/public`. Live token: `wpultra-test-9a88`.
- Re-run `wp-ultra-mcp/bin/deploy.ps1` after every commit. Commands run from `E:\wp-connector`.
- **Harness** (`tests/harness.php`): `it`, `assert_eq($expected,$actual)` strict, `assert_true($cond,$msg='')`, `assert_wp_error`, ends `run_tests();`. `tests/run-all.ps1` auto-globs. Engine files reference WP/core functions only inside function bodies (load fine without WP); the pure-helper test stubs `parse_blocks`.

## File Structure

```
wp-ultra-mcp/includes/
  gutenberg/
    patterns.php   NEW — pattern registry + reusable-block CPT wrappers + pure wpultra_gb_pattern_blocks (Task 1)
  abilities/
    gutenberg-list-patterns.php          NEW (Task 2)
    gutenberg-insert-pattern.php         NEW (Task 2)
    gutenberg-manage-reusable-block.php  NEW (Task 2)
  bootstrap-mcp.php                      MODIFY — wire engine + 3 abilities (Task 2)
tests/
  gutenberg-patterns.test.php            NEW — pure unit test (Task 1)
  bootstrap.test.php                     MODIFY — count 52 → 55 (Task 2)
```

Task order: 1, 2, 3, 4.

---

### Task 1: Patterns + reusable-block engine (`patterns.php`) — TDD on the pure helper

**Files:**
- Create: `wp-ultra-mcp/includes/gutenberg/patterns.php`
- Test: `tests/gutenberg-patterns.test.php`

**Interfaces:**
- Consumes: core `parse_blocks` (pure helper, stubbed in test), `WP_Block_Patterns_Registry`, `get_posts`/`get_post`/`wp_insert_post`/`wp_update_post` (inside bodies only).
- Produces:
  - `wpultra_gb_pattern_blocks(string $content): array` — the non-null top-level blocks of `parse_blocks($content)` (skips whitespace/null-name chunks). PURE (modulo the stubbed `parse_blocks`).
  - `wpultra_gb_list_patterns(string $search='', string $category=''): array` — `[{name,title,categories,description}]`.
  - `wpultra_gb_get_pattern(string $name)` — the registered pattern record (incl. `content`) or `WP_Error('pattern_not_found')`.
  - `wpultra_gb_reusable_list(string $search=''): array` — `[{id,title,slug,modified}]`.
  - `wpultra_gb_reusable_get(int $id)` — `['id','title','content']` or `WP_Error('reusable_not_found')`.
  - `wpultra_gb_reusable_save(array $args)` — create/update a `wp_block`; `['id','title']` or `WP_Error`.

- [ ] **Step 1: Write the failing test**

Create `tests/gutenberg-patterns.test.php`:

```php
<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
// Stub parse_blocks: return one real block per "<!-- wp:NAME" marker, plus a null-name whitespace chunk.
if (!function_exists('parse_blocks')) {
    function parse_blocks($content) {
        $out = [];
        if (preg_match_all('/<!--\s*wp:([a-z0-9\/-]+)/i', (string) $content, $m)) {
            foreach ($m[1] as $name) {
                $out[] = ['blockName' => $name, 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []];
            }
        }
        $out[] = ['blockName' => null, 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => "\n", 'innerContent' => ["\n"]];
        return $out;
    }
}
require __DIR__ . '/../wp-ultra-mcp/includes/gutenberg/patterns.php';

it('pattern_blocks returns top-level blocks and skips null-name chunks', function () {
    $blocks = wpultra_gb_pattern_blocks('<!-- wp:heading --><!-- /wp:heading --><!-- wp:paragraph --><!-- /wp:paragraph -->');
    assert_eq(2, count($blocks));
    assert_eq('heading', $blocks[0]['blockName']);
    assert_eq('paragraph', $blocks[1]['blockName']);
});

it('pattern_blocks on empty content is an empty array', function () {
    assert_eq([], wpultra_gb_pattern_blocks(''));
});

run_tests();
```

- [ ] **Step 2: Run test to verify it fails**

Run: `& $PHP tests/gutenberg-patterns.test.php`
Expected: FAIL — `patterns.php` not found / `wpultra_gb_pattern_blocks` undefined.

- [ ] **Step 3: Write minimal implementation**

Create `wp-ultra-mcp/includes/gutenberg/patterns.php`:

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/** Non-null top-level blocks of a parsed pattern/content string. Pure (modulo parse_blocks). */
function wpultra_gb_pattern_blocks(string $content): array {
    $out = [];
    foreach (parse_blocks($content) as $b) {
        if (($b['blockName'] ?? null) !== null) { $out[] = $b; }
    }
    return $out;
}

function wpultra_gb_list_patterns(string $search = '', string $category = ''): array {
    if (!class_exists('WP_Block_Patterns_Registry')) { return []; }
    $all = \WP_Block_Patterns_Registry::get_instance()->get_all_registered();
    $search = strtolower(trim($search));
    $out = [];
    foreach ($all as $p) {
        $name = (string) ($p['name'] ?? '');
        $title = (string) ($p['title'] ?? '');
        $cats = array_values((array) ($p['categories'] ?? []));
        if ($category !== '' && !in_array($category, $cats, true)) { continue; }
        if ($search !== '' && strpos(strtolower($name . ' ' . $title), $search) === false) { continue; }
        $out[] = ['name' => $name, 'title' => $title, 'categories' => $cats, 'description' => (string) ($p['description'] ?? '')];
    }
    usort($out, fn($a, $b) => strcmp($a['name'], $b['name']));
    return $out;
}

function wpultra_gb_get_pattern(string $name) {
    if (!class_exists('WP_Block_Patterns_Registry')) { return wpultra_err('patterns_unavailable', 'Block patterns registry unavailable.'); }
    $reg = \WP_Block_Patterns_Registry::get_instance();
    if (!$reg->is_registered($name)) { return wpultra_err('pattern_not_found', "No registered pattern '$name'."); }
    return $reg->get_registered($name);
}

function wpultra_gb_reusable_list(string $search = ''): array {
    $args = ['post_type' => 'wp_block', 'post_status' => 'publish', 'numberposts' => 200];
    if ($search !== '') { $args['s'] = $search; }
    $out = [];
    foreach (get_posts($args) as $p) {
        $out[] = ['id' => $p->ID, 'title' => $p->post_title, 'slug' => $p->post_name, 'modified' => $p->post_modified_gmt];
    }
    return $out;
}

function wpultra_gb_reusable_get(int $id) {
    $p = get_post($id);
    if (!$p || $p->post_type !== 'wp_block') { return wpultra_err('reusable_not_found', "No reusable block with id $id."); }
    return ['id' => $p->ID, 'title' => $p->post_title, 'content' => $p->post_content];
}

function wpultra_gb_reusable_save(array $args) {
    $title = (string) ($args['title'] ?? '');
    $id = (int) ($args['id'] ?? 0);
    if ($id > 0) {
        $existing = get_post($id);
        if (!$existing || $existing->post_type !== 'wp_block') { return wpultra_err('reusable_not_found', "No reusable block with id $id to update."); }
        $data = ['ID' => $id];
        if ($title !== '') { $data['post_title'] = $title; }
        if (array_key_exists('content', $args)) { $data['post_content'] = (string) $args['content']; }
        $res = wp_update_post($data, true);
    } else {
        if ($title === '') { return wpultra_err('missing_title', 'title is required to create a reusable block.'); }
        $res = wp_insert_post(['post_type' => 'wp_block', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => (string) ($args['content'] ?? '')], true);
    }
    if (is_wp_error($res)) { return $res; }
    $pid = (int) $res;
    $p = get_post($pid);
    return ['id' => $pid, 'title' => $p ? $p->post_title : $title];
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `& $PHP tests/gutenberg-patterns.test.php`
Expected: `2 passed, 0 failed`.

- [ ] **Step 5: Run the full suite**

Run: `powershell -ExecutionPolicy Bypass -File tests/run-all.ps1`
Expected: `ALL TEST FILES PASSED`.

- [ ] **Step 6: Commit**

```bash
git add wp-ultra-mcp/includes/gutenberg/patterns.php tests/gutenberg-patterns.test.php
git commit -m "feat(gutenberg): patterns + reusable-block engine + pure helper test"
```

---

### Task 2: The three abilities + bootstrap wiring

**Files:**
- Create: `wp-ultra-mcp/includes/abilities/gutenberg-list-patterns.php`, `gutenberg-insert-pattern.php`, `gutenberg-manage-reusable-block.php`
- Modify: `wp-ultra-mcp/includes/bootstrap-mcp.php`, `tests/bootstrap.test.php` (52 → 55)

**Interfaces:**
- Consumes: all Task 1 functions; `wpultra_gb_load`, `wpultra_gb_save`, `wpultra_gb_insert`, `wpultra_gb_str_to_path` (Wave 4a); `wpultra_ok`/`wpultra_err`/`wpultra_audit_log`.
- Produces: abilities `wpultra/gutenberg-list-patterns`, `wpultra/gutenberg-insert-pattern`, `wpultra/gutenberg-manage-reusable-block`.

- [ ] **Step 1: Write `gutenberg-list-patterns.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/gutenberg-list-patterns', [
    'label'       => __('Gutenberg: List Patterns', 'wp-ultra-mcp'),
    'description' => __('List registered block patterns (name, title, categories), optionally filtered by search/category.', 'wp-ultra-mcp'),
    'category'    => 'gutenberg',
    'input_schema' => [
        'type'       => 'object',
        'properties' => ['search' => ['type' => 'string'], 'category' => ['type' => 'string']],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => ['success' => ['type' => 'boolean'], 'count' => ['type' => 'integer'], 'patterns' => ['type' => 'array']],
        'required'   => ['success'],
    ],
    'execute_callback'    => 'wpultra_gb_list_patterns_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_gb_list_patterns_cb(array $input) {
    $patterns = wpultra_gb_list_patterns((string) ($input['search'] ?? ''), (string) ($input['category'] ?? ''));
    return wpultra_ok(['count' => count($patterns), 'patterns' => $patterns]);
}
```

- [ ] **Step 2: Write `gutenberg-insert-pattern.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/gutenberg-insert-pattern', [
    'label'       => __('Gutenberg: Insert Pattern', 'wp-ultra-mcp'),
    'description' => __('Insert a registered block pattern\'s blocks into a post at a positional parent path + position.', 'wp-ultra-mcp'),
    'category'    => 'gutenberg',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'post_id'      => ['type' => 'integer'],
            'pattern_name' => ['type' => 'string'],
            'parent_path'  => ['type' => 'string'],
            'position'     => ['type' => 'integer'],
        ],
        'required'   => ['post_id', 'pattern_name'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => ['success' => ['type' => 'boolean'], 'inserted' => ['type' => 'integer'], 'blocks' => ['type' => 'array']],
        'required'   => ['success'],
    ],
    'execute_callback'    => 'wpultra_gb_insert_pattern_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_gb_insert_pattern_cb(array $input) {
    $post_id = (int) ($input['post_id'] ?? 0);
    $name = (string) ($input['pattern_name'] ?? '');
    $pat = wpultra_gb_get_pattern($name);
    if (is_wp_error($pat)) { return $pat; }
    $blocks = wpultra_gb_pattern_blocks((string) ($pat['content'] ?? ''));
    if ($blocks === []) { return wpultra_err('empty_pattern', "Pattern '$name' parsed to no blocks."); }
    $loaded = wpultra_gb_load($post_id);
    if (is_wp_error($loaded)) { return $loaded; }
    $parentPath = wpultra_gb_str_to_path((string) ($input['parent_path'] ?? ''));
    $pos = isset($input['position']) ? (int) $input['position'] : PHP_INT_MAX;
    $updated = $loaded['blocks'];
    foreach ($blocks as $b) {
        $updated = wpultra_gb_insert($updated, $parentPath, $pos, $b);
        if (is_wp_error($updated)) { return $updated; }
        if ($pos !== PHP_INT_MAX) { $pos++; }
    }
    $tree = wpultra_gb_save($post_id, $updated);
    wpultra_audit_log('gutenberg-insert-pattern', "post $post_id <- pattern '$name' (" . count($blocks) . ' blocks)', !is_wp_error($tree));
    if (is_wp_error($tree)) { return $tree; }
    return wpultra_ok(['inserted' => count($blocks), 'blocks' => $tree]);
}
```

- [ ] **Step 3: Write `gutenberg-manage-reusable-block.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/gutenberg-manage-reusable-block', [
    'label'       => __('Gutenberg: Manage Reusable Block', 'wp-ultra-mcp'),
    'description' => __('Create/update/get/list synced (reusable) blocks (the wp_block CPT). Reference one in a post by inserting a core/block block: markup "<!-- wp:block {\\"ref\\":<id>} /-->".', 'wp-ultra-mcp'),
    'category'    => 'gutenberg',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['create', 'update', 'get', 'list']],
            'id'      => ['type' => 'integer'],
            'title'   => ['type' => 'string'],
            'content' => ['type' => 'string'],
            'search'  => ['type' => 'string'],
        ],
        'required'   => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => ['success' => ['type' => 'boolean'], 'id' => ['type' => 'integer'], 'title' => ['type' => 'string'], 'content' => ['type' => 'string'], 'count' => ['type' => 'integer'], 'blocks' => ['type' => 'array']],
        'required'   => ['success'],
    ],
    'execute_callback'    => 'wpultra_gb_manage_reusable_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_gb_manage_reusable_cb(array $input) {
    $action = (string) ($input['action'] ?? '');
    if ($action === 'list') {
        $list = wpultra_gb_reusable_list((string) ($input['search'] ?? ''));
        return wpultra_ok(['count' => count($list), 'blocks' => $list]);
    }
    if ($action === 'get') {
        $r = wpultra_gb_reusable_get((int) ($input['id'] ?? 0));
        return is_wp_error($r) ? $r : wpultra_ok($r);
    }
    if ($action === 'create' || $action === 'update') {
        $args = ['title' => (string) ($input['title'] ?? '')];
        if (isset($input['content'])) { $args['content'] = (string) $input['content']; }
        if ($action === 'update') { $args['id'] = (int) ($input['id'] ?? 0); }
        $r = wpultra_gb_reusable_save($args);
        if (is_wp_error($r)) { return $r; }
        wpultra_audit_log('gutenberg-manage-reusable-block', "$action reusable block {$r['id']}", true);
        return wpultra_ok($r);
    }
    return wpultra_err('bad_action', 'action must be one of: create, update, get, list.');
}
```

- [ ] **Step 4: Wire bootstrap + bump count**

In `wp-ultra-mcp/includes/bootstrap-mcp.php`:
1. `wpultra_load_abilities()` Gutenberg engine loop → add `patterns`:
   ```php
   foreach (['tree', 'engine', 'registry', 'patterns'] as $gbf) {
   ```
2. `wpultra_ability_files()` → after the gutenberg write group, add:
   ```php
       // gutenberg patterns (Wave 4b)
       'gutenberg-list-patterns', 'gutenberg-insert-pattern', 'gutenberg-manage-reusable-block',
   ```
3. `wpultra_ability_category_map()` → add the same three slugs to the `'gutenberg'` array.

In `tests/bootstrap.test.php`, change the count assertion to `assert_eq(55, count($files), 'count');`.

- [ ] **Step 5: Lint + run suite**

Run `& $PHP -l` on the 3 new ability files + `bootstrap-mcp.php` (all `No syntax errors detected`).
Run: `powershell -ExecutionPolicy Bypass -File tests/run-all.ps1`
Expected: `ALL TEST FILES PASSED`.

- [ ] **Step 6: Deploy + commit**

```bash
powershell -ExecutionPolicy Bypass -File wp-ultra-mcp/bin/deploy.ps1
git add wp-ultra-mcp/includes/abilities/gutenberg-list-patterns.php wp-ultra-mcp/includes/abilities/gutenberg-insert-pattern.php wp-ultra-mcp/includes/abilities/gutenberg-manage-reusable-block.php wp-ultra-mcp/includes/bootstrap-mcp.php tests/bootstrap.test.php
git commit -m "feat(gutenberg): list-patterns + insert-pattern + manage-reusable-block abilities + wiring"
```

---

### Task 3: Live verification on the Local site

**Files:**
- Create (temporary): `C:/Users/nisha/Local Sites/wp-connector/app/public/wp-content/wpultra-gbpatverify.php` — deleted at the end.

**Interfaces:** Consumes the abilities on the real WP 7.0 runtime. Produces JSON proving patterns list, a pattern inserts, and reusable CRUD + reference round-trip.

- [ ] **Step 1: Ensure the site is running**

`curl -s -o /dev/null -w "%{http_code}" http://wp-connector.local/` → `200`. Plugin deployed (Task 2).

- [ ] **Step 2: Write the token-gated live test script**

Create `…/wp-content/wpultra-gbpatverify.php`:

```php
<?php
if (($_GET['t'] ?? '') !== 'wpultra-test-9a88') { http_response_code(403); exit('forbidden'); }
require dirname(__DIR__) . '/wp-load.php';
header('Content-Type: application/json');
$admin = get_users(['role' => 'administrator', 'number' => 1]);
if ($admin) { wp_set_current_user($admin[0]->ID); }
$p = WP_PLUGIN_DIR . '/wp-ultra-mcp';
foreach (['helpers', 'gutenberg/tree', 'gutenberg/engine', 'gutenberg/registry', 'gutenberg/patterns'] as $f) {
    require_once "$p/includes/$f.php";
}
foreach (['gutenberg-list-patterns', 'gutenberg-insert-pattern', 'gutenberg-manage-reusable-block', 'gutenberg-insert-block', 'gutenberg-get-content'] as $a) {
    require_once "$p/includes/abilities/$a.php";
}

$out = [];
$listed = wpultra_gb_list_patterns_cb([]);
$out['pattern_count'] = $listed['count'] ?? 0;
$first = $listed['patterns'][0]['name'] ?? '';
$out['first_pattern'] = $first;

// insert that pattern into a draft post
$pid = wp_insert_post(['post_title' => 'gbpat', 'post_status' => 'draft', 'post_content' => '']);
$ins = $first !== '' ? wpultra_gb_insert_pattern_cb(['post_id' => $pid, 'pattern_name' => $first]) : ['skipped' => true];
$out['insert_pattern'] = is_wp_error($ins) ? $ins->get_error_message() : ['inserted' => $ins['inserted'] ?? null, 'top_blocks' => is_array($ins['blocks'] ?? null) ? count($ins['blocks']) : null];

// reusable block CRUD
$cre = wpultra_gb_manage_reusable_cb(['action' => 'create', 'title' => 'RB Test', 'content' => '<!-- wp:paragraph --><p>Reused</p><!-- /wp:paragraph -->']);
$rid = is_wp_error($cre) ? 0 : ($cre['id'] ?? 0);
$got = $rid ? wpultra_gb_manage_reusable_cb(['action' => 'get', 'id' => $rid]) : ['err' => 'no id'];
$lst = wpultra_gb_manage_reusable_cb(['action' => 'list']);
$out['reusable'] = ['created_id' => $rid, 'get_ok' => !is_wp_error($got) && ($got['id'] ?? 0) === $rid, 'list_count' => $lst['count'] ?? 0];

// reference the reusable block into the post via core/block ref using insert-block markup
if ($rid) {
    $ref = wpultra_gb_insert_block_cb(['post_id' => $pid, 'block' => ['markup' => '<!-- wp:block {"ref":' . $rid . '} /-->']]);
    $content = get_post($pid)->post_content;
    $out['ref_inserted'] = !is_wp_error($ref) && strpos($content, '"ref":' . $rid) !== false;
}

if ($rid) { wp_delete_post($rid, true); }
wp_delete_post($pid, true);
echo json_encode($out, JSON_PRETTY_PRINT);
```

- [ ] **Step 3: Run it**

Run: `curl -s "http://wp-connector.local/wp-content/wpultra-gbpatverify.php?t=wpultra-test-9a88"`
Expected: `pattern_count` > 0, a non-empty `first_pattern`; `insert_pattern.inserted` > 0; `reusable.created_id` > 0, `reusable.get_ok: true`, `reusable.list_count` ≥ 1; `ref_inserted: true`.

- [ ] **Step 4: Fix any failures**

If `pattern_count` is 0, core patterns may be unregistered on this install — confirm `WP_Block_Patterns_Registry::get_instance()->get_all_registered()` returns rows (core registers patterns on `init`; the wp-load bootstrap should have fired it). If `insert_pattern` errors, inspect the parsed blocks / the `wpultra_gb_insert` path. If `ref_inserted` is false, check the serialized content for the `core/block` ref. Re-deploy after any engine fix and re-run. Do not proceed until all keys match.

- [ ] **Step 5: Delete the test script**

Run: `rm "C:/Users/nisha/Local Sites/wp-connector/app/public/wp-content/wpultra-gbpatverify.php"`

- [ ] **Step 6: Commit (only if engine/ability fixes were made)**

```bash
git add -A
git commit -m "fix(gutenberg): live-verification fixes for patterns/reusable blocks"
```

---

### Task 4: Docs + version bump (no release)

**Files:**
- Modify: `wp-ultra-mcp/wp-ultra-mcp.php`, `wp-ultra-mcp/readme.txt`, `README.md`.

SCOPE: do NOT merge/push/release — stop after committing on the branch. Merge/release happens after the final whole-branch review via finishing-a-development-branch.

- [ ] **Step 1: Version + changelog + README**

Set `0.9.0` in `wp-ultra-mcp/wp-ultra-mcp.php` (Version header AND `WPULTRA_VERSION` — grep `0.8.0`) and `Stable tag: 0.9.0` in `wp-ultra-mcp/readme.txt`. Add a `= 0.9.0 =` changelog entry (follow the existing format) describing `gutenberg-list-patterns`, `gutenberg-insert-pattern`, `gutenberg-manage-reusable-block` (insert registered block patterns; manage `wp_block` synced blocks). Add a short bullet to the Gutenberg section of `README.md`.

- [ ] **Step 2: Deploy + full suite**

Run `powershell -ExecutionPolicy Bypass -File wp-ultra-mcp/bin/deploy.ps1` then `powershell -ExecutionPolicy Bypass -File tests/run-all.ps1`.
Expected: `ALL TEST FILES PASSED`.

- [ ] **Step 3: Commit (in-branch only)**

```bash
git add -A
git commit -m "docs(gutenberg): Wave 4b patterns + reusable blocks, v0.9.0"
```

---

## Self-Review

**Spec coverage:**
- patterns.php engine (registry + reusable CPT + pure helper) → Task 1 ✓ · `gutenberg-list-patterns` → Task 2 ✓ · `gutenberg-insert-pattern` (parse→insert via Wave 4a) → Task 2 ✓ · `gutenberg-manage-reusable-block` (create/update/get/list) → Task 2 ✓ · reference-insert via existing `gutenberg-insert-block` (no new ability) → Task 3 live proof ✓ · bootstrap wiring + count 55 → Task 2 ✓ · audit on writes → Task 2 callbacks ✓ · wp_block update guards post_type → Task 1 `reusable_get`/`reusable_save` ✓ · pure + live tests → Tasks 1,3 ✓ · release v0.9.0 → Task 4 + finishing ✓.

**Placeholder scan:** No TBD/TODO. Every code step shows complete code; every command shows expected output. Registry/CPT calls are WP-runtime-bound and live-verified in Task 3 (the established pattern).

**Type/name consistency:**
- `wpultra_gb_pattern_blocks(string): array`, `wpultra_gb_get_pattern(string)`, `wpultra_gb_list_patterns(string,string): array`, `wpultra_gb_reusable_{list,get,save}` (Task 1) consumed by the abilities (Task 2) by the same names/shapes.
- Reused Wave 4a fns by exact name: `wpultra_gb_load`, `wpultra_gb_save`, `wpultra_gb_insert`, `wpultra_gb_str_to_path`.
- Count bumped once (52→55, Task 2); category map kept in sync.
```
