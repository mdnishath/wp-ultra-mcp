# WP-Ultra-MCP — Elementor Reliability Phase A (validate-before-commit + render-check) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> Phase A of the Elementor Design Reliability design (spec: `docs/superpowers/specs/2026-06-28-wp-ultra-elementor-design-reliability-design.md`). Ships as the next minor (v0.6.0). Phases B (reference capture + blueprints) and C (design skill + screenshot loop) get their own plans later. Builds on the shipped v0.5.0 plugin.

**Goal:** No Elementor write path can silently persist invalid atomic settings, and the AI can confirm a build actually rendered — all in pure PHP, no new host dependency.

**Architecture:** One new engine file `includes/elementor/validate.php`. It holds (a) **pure** functions — a depth-guarded tree walker that runs an injectable per-node validator and aggregates a per-node report, an id collector, and a rendered-HTML digest — and (b) **Elementor-bound** functions — a node validator that resolves the atomic widget/container type, wraps scalars, and runs Elementor's `Props_Parser`; plus a server-side render-check. Two new read-only abilities (`elementor-validate` dry-run, `elementor-render-check`) expose these. The three mutating abilities (`set-content`, `add-element`, `edit-element`) route through the validator before writing. Pure functions are unit-tested with the zero-dep harness; Elementor-bound paths are live-tested on the Local site.

**Tech Stack:** PHP 8.0+, WP 6.6+ (target WP 7.0), Elementor 4.1.4 atomic widgets, vendored mcp-adapter, WordPress Abilities API. No new dependencies.

## Global Constraints

- Every PHP file starts with `<?php` + `declare(strict_types=1);` + `if (!defined('ABSPATH')) { exit(); }`. Engine functions return an array on success or `WP_Error` on failure; abilities return `wpultra_ok([...])` or `wpultra_err($code, $message, $data='')`.
- **Ability registration MUST exactly match the codebase shape** — see `includes/abilities/elementor-get-content.php`. `wp_register_ability('wpultra/<slug>', [...])` with keys: `label` (wrapped in `__()`), `description`, `category` => `'elementor'`, `input_schema`, `output_schema`, `execute_callback` (string name of a **named function** in the same file — NOT a closure), `permission_callback` => `'wpultra_permission_callback'`, and a **`meta`** block:
  ```php
  'meta' => [
      'show_in_rest' => true,
      'mcp'          => ['public' => true, 'type' => 'tool'],
      'annotations'  => ['readonly' => <bool>, 'destructive' => <bool>, 'idempotent' => <bool>],
  ],
  ```
  The `meta.mcp.public => true` block is mandatory or the ability never surfaces as an MCP tool.
- `input_schema`/`output_schema` are `['type'=>'object','properties'=>[...plain array...],'required'=>[...],'additionalProperties'=>false]`. `properties` MUST be a plain array, never an `(object)` cast.
- The `elementor` category is **already registered** in `wpultra_register_categories()` — do NOT re-add it.
- **Bootstrap wiring lives in `includes/bootstrap-mcp.php`:** new ability slugs go in BOTH `wpultra_ability_files()` (elementor read group) AND the `'elementor'` array in `wpultra_ability_category_map()`. New engine files go in the Elementor `require_once` loop in `wpultra_load_abilities()` (currently `['setup','schema','tree','engine','coerce','design','classes']`). `tests/bootstrap.test.php` asserts the EXACT ability count (`47` today) and that the category map covers every file exactly once — bump the count with each added ability and keep the map in sync, or that test fails.
- Read-only abilities (`elementor-validate`, `elementor-render-check`) do NOT call `wpultra_audit_log`. The existing mutating abilities keep their audit calls unchanged.
- Bundled PHP for lint/tests: `$PHP = C:/Users/nisha/AppData/Roaming/Local/lightning-services/php-8.2.30+1/bin/win64/php.exe`. Test site root: `C:/Users/nisha/Local Sites/wp-connector/app/public`. Live-test token: `wpultra-test-9a88`.
- Re-run `wp-ultra-mcp/bin/deploy.ps1` after every commit (Local runs the deployed copy). Commands run from `E:\wp-connector`.
- **Test harness API** (`tests/harness.php`): `it($name, fn)`; `assert_eq($expected, $actual)` (strict `===`); `assert_true($cond, $msg='')`; `assert_wp_error($val)`; file ends with `run_tests();`. `WP_Error`, `is_wp_error`, `__()` are stubbed. `tests/run-all.ps1` auto-globs `tests/*.test.php` — no manual registration needed. Do NOT redeclare functions an engine file already defines — `require` it. Engine files reference Elementor classes ONLY inside function bodies, so they load fine without Elementor (mirror `schema.php`/`coerce.php`).
- **Reuse, do not reinvent:** `wpultra_el_compact_prop` (schema.php), `wpultra_el_wrap_settings` (coerce.php), `wpultra_el_validate_settings` (coerce.php), `wpultra_el_raw` (engine.php), `wpultra_el_active` (setup.php) already exist — call them.

## File Structure

```
wp-ultra-mcp/includes/
  elementor/
    validate.php   NEW — pure tree-walk/report + id collector + render digest (Task 1);
                         atomic node validator + render-check glue (Tasks 2,5)
  abilities/
    elementor-validate.php      NEW — dry-run validation ability (Task 3)
    elementor-render-check.php  NEW — render verification ability (Task 5)
    elementor-set-content.php   MODIFY — strict validation + force flag (Task 4)
    elementor-add-element.php   MODIFY — validate container settings (Task 4)
    elementor-edit-element.php  MODIFY — validate container settings (Task 4)
  bootstrap-mcp.php             MODIFY — wire engine + 2 abilities (Tasks 3,5)
tests/
  elementor-validate.test.php   NEW — pure-logic unit tests (Task 1)
```

Task order: 1, 2, 3, 4, 5, 6, 7.

---

### Task 1: Pure validation + render-digest core (`validate.php`) — TDD

**Files:**
- Create: `wp-ultra-mcp/includes/elementor/validate.php`
- Test: `tests/elementor-validate.test.php`

**Interfaces:**
- Consumes: nothing (pure).
- Produces:
  - `wpultra_el_validate_tree(array $elements, ?callable $validator = null): array` — walks the tree depth-first (depth guard ≤100), runs `$validator($node)` on every node (default `'wpultra_el_validate_node'`, added in Task 2), and returns `['ok'=>bool, 'nodes'=>array, 'summary'=>['total'=>int,'invalid'=>int], 'normalized_tree'=>array]`. Each `nodes` entry is `['id'=>string,'elType'=>string,'widgetType'=>?string,'valid'=>bool,'errors'=>string[]]`. `normalized_tree` is `$elements` with each node's `settings` replaced by the validator's returned `settings`. `ok` is true iff every node is valid.
  - The validator contract: `$validator(array $node): array` returns `['valid'=>bool, 'errors'=>string[], 'settings'=>array]`.
  - `wpultra_el_collect_ids(array $elements, int $depth = 0): array` — all non-empty `id` strings in the tree, depth-guarded.
  - `wpultra_el_render_digest(string $html, array $expectedIds): array` — `['rendered_count'=>int,'present_ids'=>string[],'dropped_ids'=>string[]]` by scanning the HTML for `data-id="..."` markers.

- [ ] **Step 1: Write the failing test**

Create `tests/elementor-validate.test.php`:

```php
<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/elementor/validate.php';

function ev_tree(): array {
    return [[
        'id' => 'row0001', 'elType' => 'e-flexbox', 'settings' => ['gap' => 'BAD'], 'elements' => [
            ['id' => 'head001', 'elType' => 'widget', 'widgetType' => 'e-heading', 'settings' => ['tag' => 'h2'], 'elements' => []],
            ['id' => 'btn0001', 'elType' => 'widget', 'widgetType' => 'e-button', 'settings' => [], 'elements' => []],
        ],
    ]];
}

// Stub validator: marks any node whose settings contain a 'BAD' value invalid; otherwise normalizes
// settings by uppercasing the widgetType into a marker so we can prove normalized_tree is used.
function ev_stub_validator(array $node): array {
    $settings = is_array($node['settings'] ?? null) ? $node['settings'] : [];
    $bad = in_array('BAD', array_values($settings), true);
    return [
        'valid'    => !$bad,
        'errors'   => $bad ? ["invalid setting in {$node['id']}"] : [],
        'settings' => $bad ? $settings : array_merge($settings, ['_n' => 1]),
    ];
}

it('validate_tree aggregates a per-node report with summary', function () {
    $r = wpultra_el_validate_tree(ev_tree(), 'ev_stub_validator');
    assert_eq(3, $r['summary']['total']);
    assert_eq(1, $r['summary']['invalid']);
    assert_eq(false, $r['ok']);
    // node order is depth-first: row, head, btn
    assert_eq('row0001', $r['nodes'][0]['id']);
    assert_eq(false, $r['nodes'][0]['valid']);
    assert_eq('e-heading', $r['nodes'][1]['widgetType']);
    assert_eq(true, $r['nodes'][1]['valid']);
});

it('validate_tree returns ok=true when all nodes pass, and normalizes settings', function () {
    $clean = [['id' => 'a', 'elType' => 'widget', 'widgetType' => 'e-button', 'settings' => ['x' => 'y'], 'elements' => []]];
    $r = wpultra_el_validate_tree($clean, 'ev_stub_validator');
    assert_eq(true, $r['ok']);
    assert_eq(0, $r['summary']['invalid']);
    assert_eq(1, $r['normalized_tree'][0]['settings']['_n']); // validator-normalized settings used
});

it('collect_ids gathers every id depth-first', function () {
    assert_eq(['row0001', 'head001', 'btn0001'], wpultra_el_collect_ids(ev_tree()));
});

it('render_digest reports present and dropped ids from data-id markers', function () {
    $html = '<div class="elementor-element" data-id="row0001"><h2 data-id="head001">Hi</h2></div>';
    $d = wpultra_el_render_digest($html, ['row0001', 'head001', 'btn0001']);
    assert_eq(2, $d['rendered_count']);
    assert_eq(['btn0001'], $d['dropped_ids']);
});

run_tests();
```

- [ ] **Step 2: Run test to verify it fails**

Run: `& $PHP tests/elementor-validate.test.php`
Expected: FATAL — `require ... validate.php` fails (file not found).

- [ ] **Step 3: Write minimal implementation**

Create `wp-ultra-mcp/includes/elementor/validate.php`:

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Walk the element tree, validate each node via $validator, and aggregate a per-node report.
 * $validator(array $node): ['valid'=>bool, 'errors'=>string[], 'settings'=>array].
 * Returns ['ok'=>bool,'nodes'=>[],'summary'=>['total'=>int,'invalid'=>int],'normalized_tree'=>array].
 */
function wpultra_el_validate_tree(array $elements, ?callable $validator = null, int $depth = 0): array {
    if ($validator === null) { $validator = 'wpultra_el_validate_node'; }
    $nodes = [];
    $invalid = 0;
    $normalized = [];
    if ($depth > 100) { return ['ok' => true, 'nodes' => [], 'summary' => ['total' => 0, 'invalid' => 0], 'normalized_tree' => $elements]; }
    foreach ($elements as $n) {
        if (!is_array($n)) { $normalized[] = $n; continue; }
        $res = $validator($n);
        $valid = (bool) ($res['valid'] ?? true);
        if (!$valid) { $invalid++; }
        $nodes[] = [
            'id'         => (string) ($n['id'] ?? ''),
            'elType'     => (string) ($n['elType'] ?? ''),
            'widgetType' => isset($n['widgetType']) ? (string) $n['widgetType'] : null,
            'valid'      => $valid,
            'errors'     => array_values((array) ($res['errors'] ?? [])),
        ];
        $n['settings'] = is_array($res['settings'] ?? null) ? $res['settings'] : ($n['settings'] ?? []);
        if (!empty($n['elements']) && is_array($n['elements'])) {
            $child = wpultra_el_validate_tree($n['elements'], $validator, $depth + 1);
            $nodes = array_merge($nodes, $child['nodes']);
            $invalid += $child['summary']['invalid'];
            $n['elements'] = $child['normalized_tree'];
        }
        $normalized[] = $n;
    }
    return [
        'ok'              => $invalid === 0,
        'nodes'           => $nodes,
        'summary'         => ['total' => count($nodes), 'invalid' => $invalid],
        'normalized_tree' => $normalized,
    ];
}

function wpultra_el_collect_ids(array $elements, int $depth = 0): array {
    if ($depth > 100) { return []; }
    $ids = [];
    foreach ($elements as $n) {
        if (!is_array($n)) { continue; }
        if (!empty($n['id'])) { $ids[] = (string) $n['id']; }
        if (!empty($n['elements']) && is_array($n['elements'])) {
            $ids = array_merge($ids, wpultra_el_collect_ids($n['elements'], $depth + 1));
        }
    }
    return $ids;
}

/** Scan rendered Elementor HTML for data-id markers; report which expected ids are present/dropped. */
function wpultra_el_render_digest(string $html, array $expectedIds): array {
    $present = [];
    if (preg_match_all('/data-id="([a-z0-9]+)"/i', $html, $m)) {
        $present = array_values(array_unique($m[1]));
    }
    $dropped = array_values(array_diff(array_map('strval', $expectedIds), $present));
    return ['rendered_count' => count($present), 'present_ids' => $present, 'dropped_ids' => $dropped];
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `& $PHP tests/elementor-validate.test.php`
Expected: `4 passed, 0 failed`.

- [ ] **Step 5: Run the full suite**

Run: `powershell -ExecutionPolicy Bypass -File tests/run-all.ps1`
Expected: `ALL TEST FILES PASSED`.

- [ ] **Step 6: Commit**

```bash
git add wp-ultra-mcp/includes/elementor/validate.php tests/elementor-validate.test.php
git commit -m "feat(elementor): pure tree-validation + render-digest core + tests"
```

---

### Task 2: Atomic node validator (`validate.php`) — Elementor-bound

**Files:**
- Modify: `wp-ultra-mcp/includes/elementor/validate.php` (append two functions)

**Interfaces:**
- Consumes: `wpultra_el_active` (setup.php), `wpultra_el_compact_prop` (schema.php), `wpultra_el_wrap_settings` (coerce.php); Elementor `widgets_manager`, `elements_manager`, `Props_Parser`, atomic base classes.
- Produces:
  - `wpultra_el_atomic_type_object(array $node)` — the atomic widget/element type object for the node, or `null` if Elementor is inactive / type unknown / non-atomic.
  - `wpultra_el_validate_node(array $node): array` — the default validator: `['valid'=>bool,'errors'=>string[],'settings'=>array]`. Non-atomic/unknown nodes pass through unchanged (`valid=>true`). Atomic nodes are scalar-wrapped and parsed via `Props_Parser`; on failure, `errors` carries the parser's messages and `settings` is the wrapped (unsaved) input; on success, `settings` is the parser's unwrapped output.

**NOTE for the implementer (verify live in Task 6):** atomic **widgets** resolve via `\Elementor\Plugin::$instance->widgets_manager->get_widget_types($widgetType)` and are `instanceof \Elementor\Modules\AtomicWidgets\Elements\Base\Atomic_Widget_Base` (already used in `coerce.php`). Atomic **containers** (`e-flexbox`/`e-div-block`) resolve via `\Elementor\Plugin::$instance->elements_manager->get_element_types($elType)`; confirm the container base class name (expected `\Elementor\Modules\AtomicWidgets\Elements\Base\Atomic_Element_Base`) and that `get_props_schema()` exists on it. The code below guards every access in try/catch and falls back to pass-through, so an API mismatch degrades to "no validation" rather than a fatal — but Task 6 must confirm containers actually validate.

- [ ] **Step 1: Append the implementation**

Append to `wp-ultra-mcp/includes/elementor/validate.php`:

```php
/** Resolve the atomic widget/element type object for a node, or null if not atomic/unknown. */
function wpultra_el_atomic_type_object(array $node) {
    if (!function_exists('wpultra_el_active') || !wpultra_el_active()) { return null; }
    $elType = (string) ($node['elType'] ?? '');
    try {
        if ($elType === 'widget') {
            $wt = (string) ($node['widgetType'] ?? '');
            if ($wt === '') { return null; }
            $obj = \Elementor\Plugin::$instance->widgets_manager->get_widget_types($wt);
        } else {
            if ($elType === '') { return null; }
            $obj = \Elementor\Plugin::$instance->elements_manager->get_element_types($elType);
        }
    } catch (\Throwable $e) {
        return null;
    }
    if (!$obj) { return null; }
    if ($obj instanceof \Elementor\Modules\AtomicWidgets\Elements\Base\Atomic_Widget_Base) { return $obj; }
    $elementBase = '\\Elementor\\Modules\\AtomicWidgets\\Elements\\Base\\Atomic_Element_Base';
    if (class_exists($elementBase) && $obj instanceof $elementBase) { return $obj; }
    return null;
}

/** Default per-node validator: scalar-wrap + Props_Parser. Non-atomic nodes pass through. */
function wpultra_el_validate_node(array $node): array {
    $settings = is_array($node['settings'] ?? null) ? $node['settings'] : [];
    $obj = wpultra_el_atomic_type_object($node);
    if ($obj === null) {
        return ['valid' => true, 'errors' => [], 'settings' => $settings];
    }
    try {
        $schema = call_user_func([get_class($obj), 'get_props_schema']);
        $compact = [];
        foreach ($schema as $k => $prop) {
            if (is_object($prop)) { $compact[$k] = wpultra_el_compact_prop($prop); }
        }
        $wrapped = wpultra_el_wrap_settings($settings, $compact);
        $result = \Elementor\Modules\AtomicWidgets\Parsers\Props_Parser::make($schema)->parse($wrapped);
        if (!$result->is_valid()) {
            $errs = array_values(array_filter(array_map('trim', explode("\n", (string) $result->errors()->to_string()))));
            return ['valid' => false, 'errors' => $errs ?: ['settings failed Elementor validation'], 'settings' => $wrapped];
        }
        return ['valid' => true, 'errors' => [], 'settings' => $result->unwrap()];
    } catch (\Throwable $e) {
        return ['valid' => false, 'errors' => ['validation error: ' . $e->getMessage()], 'settings' => $settings];
    }
}
```

- [ ] **Step 2: Lint**

Run: `& $PHP -l wp-ultra-mcp/includes/elementor/validate.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Re-run the pure suite (must stay green)**

Run: `& $PHP tests/elementor-validate.test.php`
Expected: `4 passed, 0 failed` (the stub-validator tests are unaffected; `wpultra_el_validate_node` is exercised live in Task 6).

- [ ] **Step 4: Commit**

```bash
git add wp-ultra-mcp/includes/elementor/validate.php
git commit -m "feat(elementor): atomic node validator (widget + container) via Props_Parser"
```

---

### Task 3: `elementor-validate` dry-run ability + engine wiring

**Files:**
- Create: `wp-ultra-mcp/includes/abilities/elementor-validate.php`
- Modify: `wp-ultra-mcp/includes/bootstrap-mcp.php`
- Modify: `tests/bootstrap.test.php` (ability count 47 → 48)

**Interfaces:**
- Consumes: `wpultra_el_validate_tree` (Task 1), `wpultra_el_raw` (engine.php), `wpultra_ok`/`wpultra_err`.
- Produces: ability `wpultra/elementor-validate`.

- [ ] **Step 1: Write `elementor-validate.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/elementor-validate', [
    'label'       => __('Elementor: Validate Tree', 'wp-ultra-mcp'),
    'description' => __('Dry-run validate an element tree (supplied or a post\'s current content) against Elementor atomic schemas. Returns a per-node report of which settings would be rejected — fix them before writing.', 'wp-ultra-mcp'),
    'category'    => 'elementor',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'post_id'  => ['type' => 'integer'],
            'elements' => ['type' => 'array'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'ok'      => ['type' => 'boolean'],
            'summary' => ['type' => 'object'],
            'nodes'   => ['type' => 'array'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_elementor_validate',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_elementor_validate(array $input) {
    $elements = $input['elements'] ?? null;
    if (is_string($elements)) { $elements = json_decode($elements, true); }
    if (!is_array($elements)) {
        $post_id = (int) ($input['post_id'] ?? 0);
        if ($post_id <= 0 || !get_post($post_id)) { return wpultra_err('bad_input', 'Provide elements (array) or a valid post_id.'); }
        $elements = wpultra_el_raw($post_id);
    }
    $report = wpultra_el_validate_tree($elements);
    return wpultra_ok([
        'ok'      => $report['ok'],
        'summary' => $report['summary'],
        'nodes'   => array_values(array_filter($report['nodes'], fn($n) => !$n['valid'])),
    ]);
}
```

- [ ] **Step 2: Wire bootstrap (engine require + ability slug)**

In `wp-ultra-mcp/includes/bootstrap-mcp.php`:
1. In `wpultra_load_abilities()`, change the Elementor engine loop array to include `validate`:
   ```php
   foreach (['setup', 'schema', 'tree', 'engine', 'coerce', 'design', 'classes', 'validate'] as $elf) {
   ```
2. In `wpultra_ability_files()`, in the elementor read group (the line that starts `'elementor-list-widgets', ...`), append `'elementor-validate'`:
   ```php
   // elementor read abilities (Wave 2, Task 6) + reliability (Phase A)
   'elementor-list-widgets', 'elementor-get-widget-schema', 'elementor-get-style-schema', 'elementor-get-content', 'elementor-validate',
   ```
3. In `wpultra_ability_category_map()`, add `'elementor-validate'` to the `'elementor'` array (e.g. on the same first line):
   ```php
   'elementor-list-widgets', 'elementor-get-widget-schema', 'elementor-get-style-schema', 'elementor-get-content', 'elementor-validate',
   ```

- [ ] **Step 3: Update the bootstrap test count (47 → 48)**

In `tests/bootstrap.test.php`, change:
```php
    assert_eq(48, count($files), 'count');
```

- [ ] **Step 4: Lint + run suite**

Run `& $PHP -l wp-ultra-mcp/includes/abilities/elementor-validate.php` and `& $PHP -l wp-ultra-mcp/includes/bootstrap-mcp.php` (both `No syntax errors detected`).
Run: `powershell -ExecutionPolicy Bypass -File tests/run-all.ps1`
Expected: `ALL TEST FILES PASSED` (bootstrap count + category-map-covers-all assertions pass).

- [ ] **Step 5: Deploy + commit**

```bash
powershell -ExecutionPolicy Bypass -File wp-ultra-mcp/bin/deploy.ps1
git add wp-ultra-mcp/includes/abilities/elementor-validate.php wp-ultra-mcp/includes/bootstrap-mcp.php tests/bootstrap.test.php
git commit -m "feat(elementor): elementor-validate dry-run ability + engine wiring"
```

---

### Task 4: Route the write paths through validation

**Files:**
- Modify: `wp-ultra-mcp/includes/abilities/elementor-set-content.php`
- Modify: `wp-ultra-mcp/includes/abilities/elementor-add-element.php`
- Modify: `wp-ultra-mcp/includes/abilities/elementor-edit-element.php`

**Interfaces:**
- Consumes: `wpultra_el_validate_tree`, `wpultra_el_validate_node` (Tasks 1–2).
- Produces: strict-by-default `set-content` (with `force` escape hatch); container-validated `add-element` / `edit-element`.

- [ ] **Step 1: `set-content` — add `force` to the schema**

In `elementor-set-content.php`, replace the `properties` array inside `input_schema` with:
```php
        'properties' => [
            'post_id'  => ['type' => 'integer'],
            'elements' => ['type' => 'array'],
            'force'    => ['type' => 'boolean'],
        ],
```
And add `'warning'` to the `output_schema.properties`:
```php
            'warning'         => ['type' => 'string'],
```

- [ ] **Step 2: `set-content` — validate before write**

Replace the body of `wpultra_elementor_set_content` with:
```php
function wpultra_elementor_set_content(array $input) {
    $post_id = (int) ($input['post_id'] ?? 0);
    $elements = $input['elements'] ?? null;
    if (is_string($elements)) { $elements = json_decode($elements, true); }
    if (!is_array($elements)) { return wpultra_err('bad_elements', 'elements must be an array (or JSON string).'); }
    $force = ($input['force'] ?? false) === true;
    $report = wpultra_el_validate_tree($elements);
    if (!$report['ok'] && !$force) {
        $bad = array_values(array_filter($report['nodes'], fn($n) => !$n['valid']));
        return wpultra_err('tree_invalid', $report['summary']['invalid'] . ' element(s) have invalid settings. Fix them (see data) or pass force:true to write anyway.', ['summary' => $report['summary'], 'nodes' => $bad]);
    }
    $tree = $force ? $elements : $report['normalized_tree'];
    $res = wpultra_el_write($post_id, $tree);
    if (is_wp_error($res)) { return $res; }
    if (!$report['ok'] && $force) {
        $res['warning'] = $report['summary']['invalid'] . ' element(s) failed validation but were written (force=true).';
    }
    return $res;
}
```

- [ ] **Step 3: `add-element` — validate container settings**

In `elementor-add-element.php`, replace the `else` branch (the container case at lines ~65–68) with:
```php
    } else {
        // container (e-flexbox / e-div-block): validate atomic container props (layout: flex/gap/padding/width).
        $nv = wpultra_el_validate_node(['elType' => $elType, 'settings' => $settings]);
        if (!$nv['valid']) { return wpultra_err('invalid_settings', 'Container settings failed validation: ' . implode('; ', $nv['errors'])); }
        $node['settings'] = $nv['settings'];
    }
```

- [ ] **Step 4: `edit-element` — validate container settings on edit**

In `elementor-edit-element.php`, after the existing widget validation `if` block (ends at line ~52, before `$updated = wpultra_el_merge_settings(...)`), add an `elseif` for containers:
```php
    if (($node['elType'] ?? '') === 'widget' && !empty($node['widgetType'])) {
        $schema = wpultra_el_widget_schema((string) $node['widgetType']);
        $compact = (is_array($schema) && !empty($schema['props'])) ? $schema['props'] : [];
        $settings = wpultra_el_wrap_settings($settings, $compact);
        $valid = wpultra_el_validate_settings((string) $node['widgetType'], array_merge((array) ($node['settings'] ?? []), $settings));
        if (is_wp_error($valid)) { return $valid; }
    } elseif (($node['elType'] ?? '') !== 'widget') {
        // container: validate the merged result so layout props can't be silently dropped.
        $merged = array_merge((array) ($node['settings'] ?? []), $settings);
        $nv = wpultra_el_validate_node(['elType' => (string) $node['elType'], 'settings' => $merged]);
        if (!$nv['valid']) { return wpultra_err('invalid_settings', 'Container settings failed validation: ' . implode('; ', $nv['errors'])); }
        $settings = $nv['settings'];
    }
```
(Replace the current single `if (... 'widget' ...) { ... }` block with the `if/elseif` above; the rest of the function is unchanged.)

- [ ] **Step 5: Lint all three**

Run `& $PHP -l` on `elementor-set-content.php`, `elementor-add-element.php`, `elementor-edit-element.php`.
Expected: `No syntax errors detected` for each.

- [ ] **Step 6: Run suite + deploy + commit**

Run: `powershell -ExecutionPolicy Bypass -File tests/run-all.ps1` (Expected: `ALL TEST FILES PASSED` — pure validation logic already covered in Task 1; these are Elementor-bound and live-tested in Task 6).
```bash
powershell -ExecutionPolicy Bypass -File wp-ultra-mcp/bin/deploy.ps1
git add wp-ultra-mcp/includes/abilities/elementor-set-content.php wp-ultra-mcp/includes/abilities/elementor-add-element.php wp-ultra-mcp/includes/abilities/elementor-edit-element.php
git commit -m "feat(elementor): validate-before-commit on set-content (strict+force), add/edit containers"
```

---

### Task 5: `elementor-render-check` ability + render glue

**Files:**
- Modify: `wp-ultra-mcp/includes/elementor/validate.php` (append `wpultra_el_render_check`)
- Create: `wp-ultra-mcp/includes/abilities/elementor-render-check.php`
- Modify: `wp-ultra-mcp/includes/bootstrap-mcp.php` (`wpultra_ability_files()` + `'elementor'` category list)
- Modify: `tests/bootstrap.test.php` (ability count 48 → 49)

**Interfaces:**
- Consumes: `wpultra_el_collect_ids`, `wpultra_el_render_digest` (Task 1), `wpultra_el_raw` (engine.php); Elementor `frontend` renderer.
- Produces: `wpultra_el_render_check(int $post_id)` and ability `wpultra/elementor-render-check`.

**NOTE for the implementer (verify live in Task 6):** confirm the frontend render call. Expected: `\Elementor\Plugin::$instance->frontend->get_builder_content_for_display($post_id)` returns the rendered HTML (Elementor wraps elements in `.elementor-element` with `data-id="<id>"`). If that method is unavailable/empty for this version, fall back to `get_builder_content($post_id, true)`. Confirm per-post CSS detection — `_elementor_css` post meta is set after CSS generation; if empty post-render, treat `css_generated=false`.

- [ ] **Step 1: Append `wpultra_el_render_check` to `validate.php`**

```php
/** Render the post's Elementor content server-side and report what actually rendered. */
function wpultra_el_render_check(int $post_id) {
    if ($post_id <= 0 || !get_post($post_id)) { return wpultra_err('bad_post', 'Valid post_id required.'); }
    $expected = wpultra_el_collect_ids(function_exists('wpultra_el_raw') ? wpultra_el_raw($post_id) : []);
    $html = '';
    try {
        if (class_exists('\\Elementor\\Plugin')) {
            $frontend = \Elementor\Plugin::$instance->frontend;
            if (method_exists($frontend, 'get_builder_content_for_display')) {
                $html = (string) $frontend->get_builder_content_for_display($post_id);
            } elseif (method_exists($frontend, 'get_builder_content')) {
                $html = (string) $frontend->get_builder_content($post_id, true);
            }
        }
    } catch (\Throwable $e) {
        return wpultra_err('render_failed', 'Elementor render failed: ' . $e->getMessage());
    }
    $digest = wpultra_el_render_digest($html, $expected);
    $css = function_exists('get_post_meta') ? get_post_meta($post_id, '_elementor_css', true) : '';
    $preview = function_exists('get_permalink') ? (string) get_permalink($post_id) : '';
    return wpultra_ok([
        'post_id'        => $post_id,
        'preview_url'    => $preview,
        'expected_count' => count($expected),
        'rendered_count' => $digest['rendered_count'],
        'dropped_ids'    => $digest['dropped_ids'],
        'css_generated'  => !empty($css),
    ]);
}
```

- [ ] **Step 2: Write `elementor-render-check.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/elementor-render-check', [
    'label'       => __('Elementor: Render Check', 'wp-ultra-mcp'),
    'description' => __('Render a post\'s Elementor content server-side and report which elements actually rendered, any dropped element ids, whether CSS was generated, and the front-end preview URL (screenshot it to compare against the reference).', 'wp-ultra-mcp'),
    'category'    => 'elementor',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => ['post_id' => ['type' => 'integer']],
        'required'   => ['post_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'        => ['type' => 'boolean'],
            'preview_url'    => ['type' => 'string'],
            'expected_count' => ['type' => 'integer'],
            'rendered_count' => ['type' => 'integer'],
            'dropped_ids'    => ['type' => 'array'],
            'css_generated'  => ['type' => 'boolean'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_elementor_render_check',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_elementor_render_check(array $input) {
    return wpultra_el_render_check((int) ($input['post_id'] ?? 0));
}
```

- [ ] **Step 3: Wire bootstrap + bump count (48 → 49)**

In `bootstrap-mcp.php`, append `'elementor-render-check'` to the elementor read group in `wpultra_ability_files()` AND to the `'elementor'` array in `wpultra_ability_category_map()` (same line you edited in Task 3, after `'elementor-validate'`).
In `tests/bootstrap.test.php`, change the count assertion to `assert_eq(49, count($files), 'count');`.

- [ ] **Step 4: Lint + run suite**

Run `& $PHP -l` on `validate.php`, `elementor-render-check.php`, `bootstrap-mcp.php` (all clean).
Run: `powershell -ExecutionPolicy Bypass -File tests/run-all.ps1`
Expected: `ALL TEST FILES PASSED`.

- [ ] **Step 5: Deploy + commit**

```bash
powershell -ExecutionPolicy Bypass -File wp-ultra-mcp/bin/deploy.ps1
git add wp-ultra-mcp/includes/elementor/validate.php wp-ultra-mcp/includes/abilities/elementor-render-check.php wp-ultra-mcp/includes/bootstrap-mcp.php tests/bootstrap.test.php
git commit -m "feat(elementor): elementor-render-check ability + server render glue"
```

---

### Task 6: Live verification on the Local site

**Files:**
- Create (temporary): `C:/Users/nisha/Local Sites/wp-connector/app/public/wp-content/wpultra-elverify.php` — deleted at the end.

**Interfaces:**
- Consumes: the validator + render-check on the real Elementor 4.1.4 runtime.
- Produces: JSON confirmation that invalid trees are caught, valid trees write + render with CSS, and container props validate.

- [ ] **Step 1: Ensure the Local site is running**

Confirm `curl -s -o /dev/null -w "%{http_code}" http://wp-connector.local/` returns `200` (start it in Local if not). Plugin must be deployed (Tasks 3–5 ran deploy). Elementor "Editor V4 / atomic elements" experiment must be active (the engine targets atomic widgets).

- [ ] **Step 2: Write the token-gated live test script**

Create `…/wp-content/wpultra-elverify.php`:

```php
<?php
if (($_GET['t'] ?? '') !== 'wpultra-test-9a88') { http_response_code(403); exit('forbidden'); }
require dirname(__DIR__) . '/wp-load.php';
header('Content-Type: application/json');
$admin = get_users(['role' => 'administrator', 'number' => 1]);
if ($admin) { wp_set_current_user($admin[0]->ID); }
$p = WP_PLUGIN_DIR . '/wp-ultra-mcp';
foreach (['helpers', 'elementor/setup', 'elementor/schema', 'elementor/tree', 'elementor/engine', 'elementor/coerce', 'elementor/validate'] as $f) {
    require_once "$p/includes/$f.php";
}
$out = ['atomic_active' => function_exists('wpultra_el_atomic_active') ? wpultra_el_atomic_active() : null];

// A valid heading widget + a valid flexbox container holding it.
$validTree = [[
    'id' => 'cont001', 'elType' => 'e-flexbox', 'settings' => [], 'elements' => [
        ['id' => 'head001', 'elType' => 'widget', 'widgetType' => 'e-heading', 'settings' => ['title' => 'Hello'], 'elements' => []],
    ],
]];
$rValid = wpultra_el_validate_tree($validTree);
$out['valid_tree_ok'] = $rValid['ok'];

// An invalid heading: bogus prop value that Props_Parser must reject.
$badTree = [[
    'id' => 'head002', 'elType' => 'widget', 'widgetType' => 'e-heading', 'settings' => ['tag' => ['$$type' => 'string', 'value' => 'not-a-tag']], 'elements' => [],
]];
$rBad = wpultra_el_validate_tree($badTree);
$out['bad_tree_flagged'] = !$rBad['ok'];
$out['bad_tree_errors'] = $rBad['nodes'][0]['errors'] ?? [];

// Container validation: e-flexbox with a clearly bogus setting should be caught by the node validator.
$nvBadContainer = wpultra_el_validate_node(['elType' => 'e-flexbox', 'settings' => ['gap' => ['$$type' => 'size', 'value' => 'not-a-size']]]);
$out['container_validates'] = ($nvBadContainer['valid'] === false);

// End-to-end write + render-check on a real page.
$pid = wp_insert_post(['post_title' => 'el-verify', 'post_status' => 'draft', 'post_type' => 'page']);
update_post_meta($pid, '_elementor_edit_mode', 'builder');
wpultra_el_write($pid, $rValid['normalized_tree']);
$rc = wpultra_el_render_check($pid);
$out['render_check'] = is_wp_error($rc) ? $rc->get_error_message() : [
    'expected' => $rc['expected_count'], 'rendered' => $rc['rendered_count'],
    'dropped' => $rc['dropped_ids'], 'css' => $rc['css_generated'], 'preview' => $rc['preview_url'],
];
wp_delete_post($pid, true);
echo json_encode($out, JSON_PRETTY_PRINT);
```

- [ ] **Step 3: Run it**

Run: `curl -s "http://wp-connector.local/wp-content/wpultra-elverify.php?t=wpultra-test-9a88"`
Expected: `atomic_active: true`, `valid_tree_ok: true`, `bad_tree_flagged: true` (with non-empty `bad_tree_errors`), `container_validates: true`, and `render_check` with `expected: 2`, `rendered: 2`, `dropped: []`, `css: true`, a non-empty `preview`.

- [ ] **Step 4: Fix any failures**

If `container_validates` is false, the container type didn't resolve — inspect `wpultra_el_atomic_type_object`: confirm `elements_manager->get_element_types('e-flexbox')` returns an object and the `Atomic_Element_Base` class name; adjust, re-deploy, re-run. If `render_check.rendered` is 0 or `css` is false, confirm the frontend render method name and `_elementor_css` meta on this version; adjust `wpultra_el_render_check`, re-deploy, re-run. Do not proceed until every key matches.

- [ ] **Step 5: Delete the test script**

Run: `rm "C:/Users/nisha/Local Sites/wp-connector/app/public/wp-content/wpultra-elverify.php"`

- [ ] **Step 6: Commit (only if engine fixes were made)**

```bash
git add -A
git commit -m "fix(elementor): live-verification fixes for validate/render-check"
```

---

### Task 7: Docs + version bump + release v0.6.0

**Files:**
- Modify: `wp-ultra-mcp/wp-ultra-mcp.php` (version header + `WPULTRA_VERSION`), `wp-ultra-mcp/readme.txt` (stable tag + changelog), `README.md` (ability count + reliability section).

- [ ] **Step 1: Bump versions**

Set `0.6.0` in `wp-ultra-mcp/wp-ultra-mcp.php` (the `Version:` header AND the `WPULTRA_VERSION` constant — grep for `0.5.0` to find both) and `Stable tag: 0.6.0` in `wp-ultra-mcp/readme.txt`.

- [ ] **Step 2: Update changelog + README**

Add a `= 0.6.0 =` entry to `readme.txt` describing the 2 new abilities (`elementor-validate`, `elementor-render-check`) and validate-before-commit on writes. Update the ability count to **49** in `README.md` and add a short "Reliable Elementor builds — schema validation before write + server-side render check" bullet.

- [ ] **Step 3: Deploy + full suite**

Run: `powershell -ExecutionPolicy Bypass -File wp-ultra-mcp/bin/deploy.ps1` then `powershell -ExecutionPolicy Bypass -File tests/run-all.ps1`.
Expected: `ALL TEST FILES PASSED`.

- [ ] **Step 4: Commit, merge, build zip, release**

```bash
git add -A
git commit -m "docs(elementor): Phase A reliability — validate + render-check, v0.6.0"
git checkout main
git merge --ff-only feat/elementor-reliability
git push origin main
powershell -ExecutionPolicy Bypass -File wp-ultra-mcp/bin/build-zip.ps1
gh release create v0.6.0 --title "v0.6.0 — Reliable Elementor builds" --notes "Phase A: schema validation before every Elementor write (strict set-content with force escape hatch; container layout props now validated) + elementor-render-check to confirm what actually rendered. Catches silently-dropped atomic props that cause broken designs. Pure PHP, no new dependencies."
```

(If `build-zip.ps1` emits a zip path, attach it to the release as prior waves did: `gh release upload v0.6.0 <path>`.)

---

## Self-Review

**Spec coverage (Phase A scope):**
- Container + whole-tree validation → Tasks 1,2,4 ✓ · `elementor-validate` dry-run → Task 3 ✓ · strict set-content + force → Task 4 ✓ · `elementor-render-check` + preview URL → Tasks 1,5 ✓ · per-category toggle / bootstrap wiring → Tasks 3,5 ✓ · unit + live tests → Tasks 1,6 ✓ · release v0.6.0 → Task 7 ✓.
- Phases B (reference capture/blueprints) and C (skill/screenshot loop) are explicitly out of this plan — separate later plans, per the spec's phasing.

**Placeholder scan:** No TBD/TODO. Every code step shows complete code; every command shows expected output. The two "verify live" notes (atomic container base class, frontend render method) are deliberate — they name the exact expected API plus a guarded fallback, and Task 6 confirms them on the real runtime (the established Elementor-wave workflow).

**Type/name consistency:**
- Validator contract is uniform everywhere: `(array $node) => ['valid'=>bool,'errors'=>string[],'settings'=>array]` — used by the stub in Task 1, the default `wpultra_el_validate_node` in Task 2, and the write paths in Task 4.
- `wpultra_el_validate_tree` returns the same shape (`ok`/`nodes`/`summary`/`normalized_tree`) consumed by `elementor-validate` (Task 3) and `set-content` (Task 4).
- `wpultra_el_collect_ids` + `wpultra_el_render_digest` (Task 1) feed `wpultra_el_render_check` (Task 5).
- Reused existing functions by exact name: `wpultra_el_compact_prop`, `wpultra_el_wrap_settings`, `wpultra_el_validate_settings`, `wpultra_el_widget_schema`, `wpultra_el_raw`, `wpultra_el_write`, `wpultra_el_active`, `wpultra_el_atomic_active`.
- Ability count is bumped incrementally (47→48 in Task 3, 48→49 in Task 5) so `bootstrap.test.php` stays green at every commit; category map kept in sync in the same edits.
```
