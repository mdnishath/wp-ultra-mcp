# WP-Ultra-MCP — Elementor Blueprints (Phase B2) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> Phase B2 of Elementor Design Reliability (spec: `docs/superpowers/specs/2026-06-28-wp-ultra-elementor-blueprints-phaseB2.md`). Ships as v0.8.0. Builds on the shipped v0.7.1 plugin.

**Goal:** A library of 5 validated structural section skeletons (navbar/hero/feature-grid/cta/footer) plus `elementor-list-blueprints` and `elementor-insert-blueprint` abilities, so the AI inserts a correct section in one call (fresh ids, Phase A-validated) then styles it with tokens/classes.

**Architecture:** One new engine file `includes/elementor/blueprints.php` holds pure data (`wpultra_el_blueprints`) and a pure recursive id-regenerator (`wpultra_el_blueprint_reid`). Two thin abilities expose list + insert; insert re-ids against the page, validates via Phase A, and writes via the existing engine. Blueprints carry only structure + raw-scalar placeholder copy — no styling.

**Tech Stack:** PHP 8.0+, WP 6.6+, Elementor 4.1.4 atomic widgets, vendored mcp-adapter, WordPress Abilities API. No new dependencies.

## Global Constraints

- Every PHP file starts with `<?php` + `declare(strict_types=1);` + `if (!defined('ABSPATH')) { exit(); }`. Engine functions return arrays / values; abilities return `wpultra_ok([...])` or `wpultra_err($code,$message,$data='')`.
- **Ability registration MUST match the codebase shape** — read abilities see `includes/abilities/elementor-get-content.php`; mutating see `includes/abilities/elementor-set-content.php`. `wp_register_ability('wpultra/<slug>', [...])` with `label`/`description` in `__()`, `category => 'elementor'`, `input_schema`, `output_schema`, named `execute_callback` (string, NOT closure), `permission_callback => 'wpultra_permission_callback'`, and the mandatory `meta` block with `mcp => ['public'=>true,'type'=>'tool']`. `properties` MUST be a plain array, never `(object)` cast.
- `elementor-insert-blueprint` is **mutating**: annotations readonly=>false, destructive=>true, idempotent=>false; it MUST call `wpultra_audit_log` after writing. `elementor-list-blueprints` is **read-only**: readonly=>true, destructive=>false, idempotent=>true; NO audit log.
- The `elementor` category is already registered — do NOT re-add it.
- **Bootstrap wiring:** both new slugs go in `wpultra_ability_files()` AND the `'elementor'` array in `wpultra_ability_category_map()`; add `blueprints` to the Elementor engine require loop in `wpultra_load_abilities()` (currently `['setup','schema','tree','engine','coerce','design','classes','validate']`). `tests/bootstrap.test.php` asserts the EXACT count (`50` today) and that the category map covers every file once — bump to `52` and keep the map in sync.
- **Atomic authoring rules (proven live — blueprints follow them):** settings are RAW scalars (the engine wraps them via each prop's real `$$type`); the only widget props used are e-heading `tag`(string)+`title`(union), e-button `text`(union), e-paragraph `paragraph`(union); containers are `e-flexbox` (elType, no widgetType) with empty settings (no styling). NO color/font/spacing props.
- **Reuse, do not reinvent:** `wpultra_el_new_id(array $tree=[]): string` (setup.php — collision-checks an id against a tree via `wpultra_el_find`), `wpultra_el_find` (tree.php), `wpultra_el_insert`/`wpultra_el_raw`/`wpultra_el_write`/`wpultra_el_compact_tree` (tree/engine), `wpultra_el_validate_tree` (validate.php). Call them.
- Bundled PHP: `$PHP = C:/Users/nisha/AppData/Roaming/Local/lightning-services/php-8.2.30+1/bin/win64/php.exe`. Test site root: `C:/Users/nisha/Local Sites/wp-connector/app/public`. Live token: `wpultra-test-9a88`.
- Re-run `wp-ultra-mcp/bin/deploy.ps1` after every commit. Commands run from `E:\wp-connector`.
- **Harness** (`tests/harness.php`): `it`, `assert_eq($expected,$actual)` strict, `assert_true($cond,$msg='')`, `assert_wp_error`, ends `run_tests();`. `tests/run-all.ps1` auto-globs. Engine files reference Elementor only inside function bodies; `require` them in tests.

## File Structure

```
wp-ultra-mcp/includes/
  elementor/
    blueprints.php   NEW — pure wpultra_el_blueprints() data + wpultra_el_blueprint_reid() (Task 1)
  abilities/
    elementor-list-blueprints.php    NEW (Task 2)
    elementor-insert-blueprint.php   NEW (Task 2)
  bootstrap-mcp.php                  MODIFY — wire engine + 2 abilities (Task 2)
  skills/built-in/elementor-v4-architect.md   MODIFY — blueprint addendum (Task 4)
tests/
  elementor-blueprints.test.php      NEW — pure unit tests (Task 1)
  bootstrap.test.php                 MODIFY — count 50 → 52 (Task 2)
```

Task order: 1, 2, 3, 4.

---

### Task 1: Blueprint library data + pure id-regenerator (`blueprints.php`) — TDD

**Files:**
- Create: `wp-ultra-mcp/includes/elementor/blueprints.php`
- Test: `tests/elementor-blueprints.test.php`

**Interfaces:**
- Consumes: `wpultra_el_new_id(array $tree=[]): string`, `wpultra_el_find` (for the reid collision check at runtime).
- Produces:
  - `wpultra_el_blueprints(): array` — `[ name => ['description'=>string,'summary'=>string,'tree'=>array] ]` for names `navbar`, `hero`, `feature-grid`, `cta`, `footer`. Every node id is the literal placeholder `'bp'` (reid replaces them).
  - `wpultra_el_blueprint_reid(array $tree, array $existing = []): array` — returns `$tree` with EVERY node's `id` replaced by a fresh unique id (checked against `$existing` page nodes and against ids already assigned in this call). Structure + settings preserved.

- [ ] **Step 1: Write the failing test**

Create `tests/elementor-blueprints.test.php`:

```php
<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/elementor/setup.php';
require __DIR__ . '/../wp-ultra-mcp/includes/elementor/tree.php';
require __DIR__ . '/../wp-ultra-mcp/includes/elementor/blueprints.php';

function all_ids(array $nodes, array &$acc): void {
    foreach ($nodes as $n) {
        if (!is_array($n)) { continue; }
        if (isset($n['id'])) { $acc[] = $n['id']; }
        if (!empty($n['elements']) && is_array($n['elements'])) { all_ids($n['elements'], $acc); }
    }
}

it('library exposes the 5 named blueprints, each with a tree', function () {
    $b = wpultra_el_blueprints();
    assert_eq(['navbar', 'hero', 'feature-grid', 'cta', 'footer'], array_keys($b));
    foreach ($b as $name => $bp) {
        assert_true(!empty($bp['description']) && !empty($bp['summary']), "$name has description+summary");
        assert_true(is_array($bp['tree']) && $bp['tree'] !== [], "$name has a tree");
        assert_eq('e-flexbox', $bp['tree'][0]['elType']);
    }
});

it('hero blueprint has the documented structure', function () {
    $hero = wpultra_el_blueprints()['hero']['tree'];
    $kids = $hero[0]['elements'];
    assert_eq(['e-heading', 'e-paragraph', 'e-button'], array_map(fn($n) => $n['widgetType'], $kids));
    assert_eq('h1', $kids[0]['settings']['tag']);            // raw scalar tag
    assert_true(is_string($kids[0]['settings']['title']), 'title is a raw scalar');
});

it('reid replaces every id with a unique fresh id and preserves structure', function () {
    $tree = wpultra_el_blueprints()['feature-grid']['tree'];
    $out = wpultra_el_blueprint_reid($tree);
    $ids = []; all_ids($out, $ids);
    assert_true(!in_array('bp', $ids, true), 'no placeholder bp ids remain');
    assert_eq(count($ids), count(array_unique($ids)), 'all ids unique');
    // structure preserved: same widget types in the same shape
    assert_eq('e-flexbox', $out[0]['elType']);
    assert_eq(3, count($out[0]['elements']));               // 3 columns
});

it('reid avoids ids already on the page', function () {
    $existing = [['id' => 'aaaaaaa', 'elType' => 'e-flexbox', 'elements' => [['id' => 'bbbbbbb', 'elType' => 'widget', 'elements' => []]]]];
    $out = wpultra_el_blueprint_reid(wpultra_el_blueprints()['cta']['tree'], $existing);
    $ids = []; all_ids($out, $ids);
    assert_true(!in_array('aaaaaaa', $ids, true) && !in_array('bbbbbbb', $ids, true), 'no collision with existing ids');
});

run_tests();
```

- [ ] **Step 2: Run test to verify it fails**

Run: `& $PHP tests/elementor-blueprints.test.php`
Expected: FAIL — `blueprints.php` not found / `wpultra_el_blueprints` undefined.

- [ ] **Step 3: Write minimal implementation**

Create `wp-ultra-mcp/includes/elementor/blueprints.php`:

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/** Built-in structural section skeletons. Every node id is the placeholder 'bp' (reid replaces). */
function wpultra_el_blueprints(): array {
    $fx = fn(array $children) => ['id' => 'bp', 'elType' => 'e-flexbox', 'settings' => [], 'elements' => $children];
    $h  = fn(string $t, string $tag = 'h2') => ['id' => 'bp', 'elType' => 'widget', 'widgetType' => 'e-heading', 'settings' => ['tag' => $tag, 'title' => $t], 'elements' => []];
    $pr = fn(string $t) => ['id' => 'bp', 'elType' => 'widget', 'widgetType' => 'e-paragraph', 'settings' => ['paragraph' => $t], 'elements' => []];
    $bt = fn(string $t) => ['id' => 'bp', 'elType' => 'widget', 'widgetType' => 'e-button', 'settings' => ['text' => $t], 'elements' => []];
    return [
        'navbar' => [
            'description' => 'Top navigation bar: brand heading, link group, and a call-to-action button.',
            'summary' => 'e-flexbox row > [heading(brand), flexbox(3 paragraphs), button]',
            'tree' => [$fx([
                $h('Brand', 'h3'),
                $fx([$pr('Home'), $pr('About'), $pr('Contact')]),
                $bt('Get Started'),
            ])],
        ],
        'hero' => [
            'description' => 'Hero section: headline, supporting subhead, and a CTA button.',
            'summary' => 'e-flexbox column > [heading(h1), paragraph, button]',
            'tree' => [$fx([
                $h('Your headline goes here', 'h1'),
                $pr('A short supporting sentence that explains the value.'),
                $bt('Get Started'),
            ])],
        ],
        'feature-grid' => [
            'description' => 'Three-column feature grid, each column a heading + description.',
            'summary' => 'e-flexbox row > 3x [ flexbox column > [heading, paragraph] ]',
            'tree' => [$fx([
                $fx([$h('Feature one'), $pr('Describe the first feature here.')]),
                $fx([$h('Feature two'), $pr('Describe the second feature here.')]),
                $fx([$h('Feature three'), $pr('Describe the third feature here.')]),
            ])],
        ],
        'cta' => [
            'description' => 'Call-to-action band: a heading and a button.',
            'summary' => 'e-flexbox > [heading, button]',
            'tree' => [$fx([
                $h('Ready to get started?'),
                $bt('Sign up'),
            ])],
        ],
        'footer' => [
            'description' => 'Footer with three link columns.',
            'summary' => 'e-flexbox row > 3x [ flexbox column > [heading, 2 paragraphs] ]',
            'tree' => [$fx([
                $fx([$h('Product', 'h4'), $pr('Features'), $pr('Pricing')]),
                $fx([$h('Company', 'h4'), $pr('About'), $pr('Careers')]),
                $fx([$h('Legal', 'h4'), $pr('Privacy'), $pr('Terms')]),
            ])],
        ],
    ];
}

/** Replace every node id in $tree with a fresh unique id, avoiding ids in $existing and ids assigned so far. */
function wpultra_el_blueprint_reid(array $tree, array $existing = []): array {
    $seed = $existing;
    $walk = function (array $nodes) use (&$walk, &$seed): array {
        $out = [];
        foreach ($nodes as $n) {
            if (!is_array($n)) { continue; }
            $id = wpultra_el_new_id($seed);
            $n['id'] = $id;
            $seed[] = ['id' => $id, 'elType' => 'marker', 'elements' => []]; // reserve so later ids differ
            if (!empty($n['elements']) && is_array($n['elements'])) {
                $n['elements'] = $walk($n['elements']);
            }
            $out[] = $n;
        }
        return $out;
    };
    return $walk($tree);
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `& $PHP tests/elementor-blueprints.test.php`
Expected: `4 passed, 0 failed`.

- [ ] **Step 5: Run the full suite**

Run: `powershell -ExecutionPolicy Bypass -File tests/run-all.ps1`
Expected: `ALL TEST FILES PASSED`.

- [ ] **Step 6: Commit**

```bash
git add wp-ultra-mcp/includes/elementor/blueprints.php tests/elementor-blueprints.test.php
git commit -m "feat(elementor): structural blueprint library + pure id-regenerator + tests"
```

---

### Task 2: `list-blueprints` + `insert-blueprint` abilities + wiring

**Files:**
- Create: `wp-ultra-mcp/includes/abilities/elementor-list-blueprints.php`, `wp-ultra-mcp/includes/abilities/elementor-insert-blueprint.php`
- Modify: `wp-ultra-mcp/includes/bootstrap-mcp.php`, `tests/bootstrap.test.php` (50 → 52)

**Interfaces:**
- Consumes: `wpultra_el_blueprints`, `wpultra_el_blueprint_reid` (Task 1); `wpultra_el_raw`, `wpultra_el_insert`, `wpultra_el_write`, `wpultra_el_compact_tree` (engine/tree); `wpultra_el_validate_tree` (validate.php); `wpultra_ok`/`wpultra_err`/`wpultra_audit_log`.
- Produces: abilities `wpultra/elementor-list-blueprints`, `wpultra/elementor-insert-blueprint`.

- [ ] **Step 1: Write `elementor-list-blueprints.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/elementor-list-blueprints', [
    'label'       => __('Elementor: List Blueprints', 'wp-ultra-mcp'),
    'description' => __('List the built-in structural section skeletons (navbar/hero/feature-grid/cta/footer). Pass name to get one blueprint\'s element tree.', 'wp-ultra-mcp'),
    'category'    => 'elementor',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => ['name' => ['type' => 'string']],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => ['success' => ['type' => 'boolean'], 'blueprints' => ['type' => 'array'], 'tree' => ['type' => 'array']],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_elementor_list_blueprints',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_elementor_list_blueprints(array $input) {
    $all = wpultra_el_blueprints();
    $name = (string) ($input['name'] ?? '');
    if ($name !== '') {
        if (!isset($all[$name])) { return wpultra_err('bad_blueprint', "No blueprint '$name'. Available: " . implode(', ', array_keys($all))); }
        return wpultra_ok(['tree' => $all[$name]['tree'], 'blueprints' => [['name' => $name] + array_intersect_key($all[$name], ['description' => 1, 'summary' => 1])]]);
    }
    $list = [];
    foreach ($all as $n => $bp) { $list[] = ['name' => $n, 'description' => $bp['description'], 'summary' => $bp['summary']]; }
    return wpultra_ok(['blueprints' => $list]);
}
```

- [ ] **Step 2: Write `elementor-insert-blueprint.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/elementor-insert-blueprint', [
    'label'       => __('Elementor: Insert Blueprint', 'wp-ultra-mcp'),
    'description' => __('Insert a built-in structural section skeleton into a post (fresh ids, validated). Then style it with design tokens + global classes.', 'wp-ultra-mcp'),
    'category'    => 'elementor',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'post_id'   => ['type' => 'integer'],
            'blueprint' => ['type' => 'string'],
            'parent_id' => ['type' => 'string'],
            'position'  => ['type' => 'integer'],
        ],
        'required'             => ['post_id', 'blueprint'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => ['success' => ['type' => 'boolean'], 'inserted_ids' => ['type' => 'array'], 'elements' => ['type' => 'array']],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_elementor_insert_blueprint',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_bp_collect_ids(array $nodes, array &$acc): void {
    foreach ($nodes as $n) {
        if (!is_array($n)) { continue; }
        if (!empty($n['id'])) { $acc[] = (string) $n['id']; }
        if (!empty($n['elements']) && is_array($n['elements'])) { wpultra_bp_collect_ids($n['elements'], $acc); }
    }
}

function wpultra_elementor_insert_blueprint(array $input) {
    $post_id = (int) ($input['post_id'] ?? 0);
    if ($post_id <= 0 || !get_post($post_id)) { return wpultra_err('bad_post', 'Valid post_id required.'); }
    $name = (string) ($input['blueprint'] ?? '');
    $all = wpultra_el_blueprints();
    if (!isset($all[$name])) { return wpultra_err('bad_blueprint', "No blueprint '$name'. Available: " . implode(', ', array_keys($all))); }

    $page = wpultra_el_raw($post_id);
    $reided = wpultra_el_blueprint_reid($all[$name]['tree'], $page);

    $report = wpultra_el_validate_tree($reided);
    if (!$report['ok']) {
        $bad = array_values(array_filter($report['nodes'], fn($n) => !$n['valid']));
        return wpultra_err('blueprint_invalid', "Blueprint '$name' failed validation on this Elementor version.", ['nodes' => $bad]);
    }
    $tree = $report['normalized_tree'];

    $ids = []; wpultra_bp_collect_ids($tree, $ids);
    $parent = isset($input['parent_id']) && $input['parent_id'] !== '' ? (string) $input['parent_id'] : null;
    $pos = isset($input['position']) ? (int) $input['position'] : PHP_INT_MAX;
    // Insert each top-level blueprint node at the target.
    $updated = $page;
    foreach ($tree as $node) {
        $updated = wpultra_el_insert($updated, $parent, $pos, $node);
        if (is_wp_error($updated)) { return $updated; }
        if ($pos !== PHP_INT_MAX) { $pos++; }
    }
    $w = wpultra_el_write($post_id, $updated);
    wpultra_audit_log('elementor-insert-blueprint', "post $post_id <- blueprint '$name' (" . count($ids) . ' nodes)', !is_wp_error($w));
    if (is_wp_error($w)) { return $w; }
    return wpultra_ok(['inserted_ids' => $ids, 'elements' => wpultra_el_compact_tree($updated)]);
}
```

- [ ] **Step 3: Wire bootstrap + bump count**

In `wp-ultra-mcp/includes/bootstrap-mcp.php`:
1. `wpultra_load_abilities()` engine loop → add `blueprints`:
   ```php
   foreach (['setup', 'schema', 'tree', 'engine', 'coerce', 'design', 'classes', 'validate', 'blueprints'] as $elf) {
   ```
2. `wpultra_ability_files()` → add a group before the closing `];`:
   ```php
       // elementor blueprints (Phase B2)
       'elementor-list-blueprints', 'elementor-insert-blueprint',
   ```
3. `wpultra_ability_category_map()` → add the two slugs to the `'elementor'` array.

In `tests/bootstrap.test.php`, change the count assertion to `assert_eq(52, count($files), 'count');`.

- [ ] **Step 4: Lint + run suite**

Run `& $PHP -l` on both new ability files and `bootstrap-mcp.php` (all `No syntax errors detected`).
Run: `powershell -ExecutionPolicy Bypass -File tests/run-all.ps1`
Expected: `ALL TEST FILES PASSED`.

- [ ] **Step 5: Deploy + commit**

```bash
powershell -ExecutionPolicy Bypass -File wp-ultra-mcp/bin/deploy.ps1
git add wp-ultra-mcp/includes/abilities/elementor-list-blueprints.php wp-ultra-mcp/includes/abilities/elementor-insert-blueprint.php wp-ultra-mcp/includes/bootstrap-mcp.php tests/bootstrap.test.php
git commit -m "feat(elementor): list-blueprints + insert-blueprint abilities + wiring"
```

---

### Task 3: Live verification on the Local site

**Files:**
- Create (temporary): `C:/Users/nisha/Local Sites/wp-connector/app/public/wp-content/wpultra-bpverify.php` — deleted at the end.

**Interfaces:** Consumes the abilities on the real Elementor 4.1.4 runtime. Produces JSON proving each blueprint inserts, validates, renders, and double-insert is collision-free.

- [ ] **Step 1: Ensure the site is running**

`curl -s -o /dev/null -w "%{http_code}" http://wp-connector.local/` → `200`. Plugin deployed (Task 2).

- [ ] **Step 2: Write the token-gated live test script**

Create `…/wp-content/wpultra-bpverify.php`:

```php
<?php
if (($_GET['t'] ?? '') !== 'wpultra-test-9a88') { http_response_code(403); exit('forbidden'); }
require dirname(__DIR__) . '/wp-load.php';
header('Content-Type: application/json');
$admin = get_users(['role' => 'administrator', 'number' => 1]);
if ($admin) { wp_set_current_user($admin[0]->ID); }
$p = WP_PLUGIN_DIR . '/wp-ultra-mcp';
foreach (['helpers', 'elementor/setup', 'elementor/schema', 'elementor/tree', 'elementor/engine', 'elementor/coerce', 'elementor/design', 'elementor/classes', 'elementor/validate', 'elementor/blueprints'] as $f) {
    require_once "$p/includes/$f.php";
}
foreach (['elementor-insert-blueprint', 'elementor-render-check'] as $a) { require_once "$p/includes/abilities/$a.php"; }
wpultra_el_atomic_enable();

$out = [];
foreach (array_keys(wpultra_el_blueprints()) as $name) {
    $pid = wp_insert_post(['post_title' => "bp-$name", 'post_status' => 'publish', 'post_type' => 'page']);
    update_post_meta($pid, '_elementor_edit_mode', 'builder');
    $ins = wpultra_elementor_insert_blueprint(['post_id' => $pid, 'blueprint' => $name]);
    if (is_wp_error($ins)) { $out[$name] = ['err' => $ins->get_error_code() . ': ' . $ins->get_error_message()]; wp_delete_post($pid, true); continue; }
    $rc = wpultra_elementor_render_check(['post_id' => $pid]);
    $out[$name] = ['inserted' => count($ins['inserted_ids']),
        'rendered' => is_wp_error($rc) ? 'ERR' : $rc['rendered_count'],
        'dropped'  => is_wp_error($rc) ? 'ERR' : $rc['dropped_ids']];
    wp_delete_post($pid, true);
}
// double-insert collision check
$pid = wp_insert_post(['post_title' => 'bp-dup', 'post_status' => 'draft', 'post_type' => 'page']);
update_post_meta($pid, '_elementor_edit_mode', 'builder');
wpultra_elementor_insert_blueprint(['post_id' => $pid, 'blueprint' => 'hero']);
wpultra_elementor_insert_blueprint(['post_id' => $pid, 'blueprint' => 'hero']);
$ids = []; wpultra_bp_collect_ids(wpultra_el_raw($pid), $ids);
$out['double_insert_unique'] = (count($ids) === count(array_unique($ids)));
wp_delete_post($pid, true);
echo json_encode($out, JSON_PRETTY_PRINT);
```

- [ ] **Step 3: Run it**

Run: `curl -s "http://wp-connector.local/wp-content/wpultra-bpverify.php?t=wpultra-test-9a88"`
Expected: every blueprint reports `inserted` > 0, `rendered` equal to its node count (navbar 6, hero 3, feature-grid 9, cta 2, footer 12), `dropped` `[]`; and `double_insert_unique: true`.

- [ ] **Step 4: Fix any failures**

If a blueprint returns `blueprint_invalid`, read the failing node(s): a widget prop name is wrong for this Elementor version — fix the setting in `wpultra_el_blueprints()` (e.g. confirm e-button `text` / e-paragraph `paragraph` via `wpultra_el_widget_schema`), re-deploy, re-run. If `rendered` is short, inspect which ids dropped. Do not proceed until all blueprints insert + render and double-insert is unique.

- [ ] **Step 5: Delete the test script**

Run: `rm "C:/Users/nisha/Local Sites/wp-connector/app/public/wp-content/wpultra-bpverify.php"`

- [ ] **Step 6: Commit (only if blueprint/engine fixes were made)**

```bash
git add -A
git commit -m "fix(elementor): live-verification fixes for blueprints"
```

---

### Task 4: Skill addendum + docs + version bump (no release)

**Files:**
- Modify: `wp-ultra-mcp/includes/skills/built-in/elementor-v4-architect.md`, `wp-ultra-mcp/wp-ultra-mcp.php`, `wp-ultra-mcp/readme.txt`, `README.md`.

SCOPE: do NOT merge/push/release — stop after committing on the branch. Merge/release happens after the final whole-branch review via finishing-a-development-branch.

- [ ] **Step 1: Skill addendum**

In `wp-ultra-mcp/includes/skills/built-in/elementor-v4-architect.md`, append a short paragraph under the build loop:
```
## Start sections fast with blueprints
`wpultra/elementor-list-blueprints` shows built-in structural skeletons (navbar, hero, feature-grid, cta, footer). `wpultra/elementor-insert-blueprint` `{post_id, blueprint, parent_id?, position?}` inserts one with fresh ids, validated — it carries layout + placeholder text only (no styling). Then style it with design tokens + global classes, and edit the placeholder copy with `elementor-edit-element`.
```

- [ ] **Step 2: Version + changelog + README**

Set `0.8.0` in `wp-ultra-mcp/wp-ultra-mcp.php` (the `Version:` header AND `WPULTRA_VERSION` — grep `0.7.1`) and `Stable tag: 0.8.0` in `wp-ultra-mcp/readme.txt`. Add a `= 0.8.0 =` changelog entry (follow the existing format) describing `elementor-list-blueprints` + `elementor-insert-blueprint` (insert validated structural section skeletons; style after with tokens/classes). Add a short bullet to the Elementor section of `README.md`.

- [ ] **Step 3: Deploy + full suite**

Run `powershell -ExecutionPolicy Bypass -File wp-ultra-mcp/bin/deploy.ps1` then `powershell -ExecutionPolicy Bypass -File tests/run-all.ps1`.
Expected: `ALL TEST FILES PASSED`.

- [ ] **Step 4: Commit (in-branch only)**

```bash
git add -A
git commit -m "docs(elementor): Phase B2 blueprints — skill addendum, v0.8.0"
```

---

## Self-Review

**Spec coverage:**
- Structural-skeleton decision (no styling) → Task 1 blueprint data (empty container settings, raw-scalar copy) ✓ · 5 blueprints → Task 1 ✓ · re-id fresh ids → Task 1 `wpultra_el_blueprint_reid` ✓ · `list-blueprints` → Task 2 ✓ · `insert-blueprint` (reid → validate → insert → write → audit) → Task 2 ✓ · validation guard → Task 2 callback ✓ · bootstrap wiring + count 52 → Task 2 ✓ · pure + live tests → Tasks 1,3 ✓ · skill addendum → Task 4 ✓ · release v0.8.0 → Task 4 + finishing ✓.

**Placeholder scan:** No TBD/TODO. Every code step shows complete code. The widget prop names (heading tag/title, button text, paragraph paragraph) were confirmed live before writing; Task 3 re-confirms on insert and Task 3 Step 4 says exactly what to fix if a name differs.

**Type/name consistency:**
- `wpultra_el_blueprints(): array` (Task 1) → consumed by both abilities (Task 2) by the same `[name=>['description','summary','tree']]` shape.
- `wpultra_el_blueprint_reid(array $tree, array $existing=[]): array` (Task 1) → called by insert (Task 2) with the page tree as `$existing`.
- `wpultra_bp_collect_ids(array, array&)` defined in `elementor-insert-blueprint.php` (Task 2) and reused by the live script (Task 3) which requires that ability file — no redefinition.
- Reused engine fns by exact name: `wpultra_el_new_id`, `wpultra_el_raw`, `wpultra_el_insert`, `wpultra_el_write`, `wpultra_el_compact_tree`, `wpultra_el_validate_tree`.
- Count bumped once (50→52, Task 2); category map kept in sync.
```
