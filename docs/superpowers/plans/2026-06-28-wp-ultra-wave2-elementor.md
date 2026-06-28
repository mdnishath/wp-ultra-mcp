# WP-Ultra-MCP — Wave 2 (Schema-driven Elementor) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> Wave 2 of the program (`docs/superpowers/specs/2026-06-27-wp-ultra-program.md` §2). Builds on the shipped Wave 1 + 1.5 plugin. Grounded in the **real Elementor 4.1.4 atomic-widgets API** (verified against the installed source).

**Goal:** A server-side, schema-driven Elementor engine + MCP abilities so an AI can introspect Elementor's real widget/style schemas and build/edit valid Elementor **v4 atomic** layouts — the headline feature that beats paid tools.

**Architecture:** A small engine under `includes/elementor/` introspects Elementor's widget registry (`get_props_schema()` per atomic widget), compacts schemas for the AI, coerces ergonomic settings into the on-disk `{"$$type","value"}` form, validates via Elementor's own `Props_Parser`, and reads/writes `_elementor_data` directly (atomic-safe — bypassing `Document::save()` which strips atomic widgets) with full CSS-cache invalidation. Thin abilities expose list-widgets / get-schema / get-content / set-content / add-/edit-/delete-/move-element. Pure tree + compaction helpers are unit-tested; Elementor-API-dependent paths are live-tested against the Local site (Elementor 4.1.4 installed).

**Tech Stack:** Same as Wave 1 — PHP 8.0+, WP 6.6+, vendored mcp-adapter. **Requires Elementor 4.x with the `e_atomic_elements` experiment active** for atomic features; abilities self-gate on Elementor presence and degrade gracefully.

## Global Constraints

- All Wave 1/1.5 global constraints apply (file headers `<?php`+`declare(strict_types=1)`+ABSPATH guard; abilities return array-or-WP_Error; `wpultra_permission_callback`; deploy via `bin/deploy.ps1` after every commit; bundled PHP at `C:/Users/nisha/AppData/Roaming/Local/lightning-services/php-8.2.30+1/bin/win64/php.exe` = `$PHP`; test site `C:/Users/nisha/Local Sites/wp-connector/app/public`).
- Ability namespace `wpultra/elementor-*`, category `elementor` (MUST be registered in `wpultra_register_categories()` — WP 7.0 rejects abilities with an unregistered category). Registration uses the canonical shape (`input_schema` as plain-array properties, `execute_callback`, `permission_callback`).
- **Verified Elementor 4.1.4 API (use exactly these):**
  - Enumerate: `\Elementor\Plugin::$instance->widgets_manager->get_widget_types()` → `[name => Widget_Base]`. Single: `get_widget_types($name)`.
  - Atomic check: `$w instanceof \Elementor\Modules\AtomicWidgets\Elements\Base\Atomic_Widget_Base`.
  - Props schema: `$w::get_props_schema()` → `[propKey => Prop_Type]`. Each `Prop_Type`: `::get_key()` (the `$$type` string), `->get_default()`, `->get_setting('enum')` (allowed values on String types), `->get_type()` (`plain|object|array|union`).
  - Wrap a value: `<Prop_Type_Class>::generate($value)` → `['$$type'=>key,'value'=>$value]` (trait `Has_Generate`).
  - Validate: `\Elementor\Modules\AtomicWidgets\Parsers\Props_Parser::make($schema)->parse($settings)` → `Parse_Result` (`->is_valid()`, `->unwrap()`, `->errors()->to_string()`).
  - Style schema: `\Elementor\Modules\AtomicWidgets\Styles\Style_Schema::get()` → `[cssProp => Prop_Type]`.
  - Atomic widget types: `e-heading`, `e-button`, `e-image`, `e-paragraph`, `e-divider`, `e-svg`, `e-youtube`, `e-self-hosted-video`. Atomic containers (elType): `e-flexbox`, `e-div-block`.
  - Node shape: widget = `{id, elType:'widget', widgetType, version, settings:{prop:{$$type,value}}, styles:{}, elements?}`; container = `{id, elType:'e-flexbox'|'e-div-block', settings, styles, elements:[]}`.
  - Read content: `get_post_meta($post_id, '_elementor_data', true)` (JSON string) → `json_decode(..., true)`.
  - Write content (atomic-safe): `update_post_meta($post_id, '_elementor_data', wp_slash(wp_json_encode($elements)))`; set `_elementor_edit_mode`='builder', `_elementor_version`=`ELEMENTOR_VERSION`.
  - CSS invalidation after write: `\Elementor\Plugin::$instance->files_manager->clear_cache()`; `delete_post_meta($post_id, '_elementor_css')`; `do_action('elementor/atomic-widgets/styles/clear')`; `clean_post_cache($post_id)`.
  - Experiment check: `\Elementor\Plugin::$instance->experiments->is_feature_active('e_atomic_elements')`.

## File Structure

```
wp-ultra-mcp/includes/
  elementor/
    setup.php        Elementor/atomic detection + id generator (pure-ish)   — Task 1
    schema.php       introspect + compact widget/style schemas              — Task 2
    tree.php         pure tree ops: compact, find, insert, update, move     — Task 3
    engine.php       read/write _elementor_data + cache invalidation        — Task 4
    coerce.php       ergonomic settings -> {$$type} wrap + Props_Parser      — Task 5
  abilities/
    elementor-list-widgets.php  elementor-get-widget-schema.php
    elementor-get-style-schema.php  elementor-get-content.php               — Task 6
    elementor-set-content.php  elementor-add-element.php
    elementor-edit-element.php  elementor-delete-element.php
    elementor-move-element.php                                              — Task 7
  skills/built-in/elementor-v4-architect.md                                 — Task 8
tests/
  elementor-tree.test.php                                                   — Task 3
  elementor-coerce.test.php                                                 — Task 5
```

Modify `includes/bootstrap-mcp.php` (`wpultra_ability_files()` + `wpultra_register_categories()`), `wp-ultra-mcp.php` (none). Commands run from `E:\wp-connector`.

---

### Task 1: Elementor setup/detection + id generator

**Files:**
- Create: `wp-ultra-mcp/includes/elementor/setup.php`
- Test: covered by Task 3's harness (pure id test) + live smoke in Task 9.

**Interfaces:**
- Produces:
  - `wpultra_el_active(): bool` — `class_exists('\Elementor\Plugin')`.
  - `wpultra_el_atomic_active(): bool` — Elementor active AND `experiments->is_feature_active('e_atomic_elements')` (guarded in try/catch → false on any error).
  - `wpultra_el_status(): array` — `{elementor:bool, version:string|null, atomic:bool}`.
  - `wpultra_el_new_id(): string` — 7-char lowercase-hex-ish id (matches Elementor's id charset `[a-f0-9]` is fine; use `[a-z0-9]`). Pure.
  - `wpultra_el_require_atomic(): true|WP_Error` — returns a helpful WP_Error when atomic isn't available.

- [ ] **Step 1: Write `includes/elementor/setup.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

function wpultra_el_active(): bool {
    return class_exists('\\Elementor\\Plugin');
}

function wpultra_el_atomic_active(): bool {
    if (!wpultra_el_active()) { return false; }
    try {
        $p = \Elementor\Plugin::$instance;
        return isset($p->experiments) && $p->experiments->is_feature_active('e_atomic_elements');
    } catch (\Throwable $e) {
        return false;
    }
}

function wpultra_el_status(): array {
    return [
        'elementor' => wpultra_el_active(),
        'version'   => defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : null,
        'atomic'    => wpultra_el_atomic_active(),
    ];
}

function wpultra_el_new_id(): string {
    $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $id = '';
    for ($i = 0; $i < 7; $i++) { $id .= $alphabet[random_int(0, strlen($alphabet) - 1)]; }
    return $id;
}

function wpultra_el_require_atomic() {
    if (!wpultra_el_active()) {
        return wpultra_err('elementor_missing', 'Elementor is not installed/active on this site.');
    }
    if (!wpultra_el_atomic_active()) {
        return wpultra_err('atomic_inactive', 'Elementor v4 atomic elements are not active. Enable the "Editor V4 / atomic elements" experiment in Elementor > Settings > Features.');
    }
    return true;
}
```

- [ ] **Step 2: Lint + commit**

Run: `& $PHP -l E:\wp-connector\wp-ultra-mcp\includes\elementor\setup.php` → `No syntax errors detected`.
```bash
git add wp-ultra-mcp/includes/elementor/setup.php
git commit -m "feat(plugin): Elementor setup/detection + id generator"
```

---

### Task 2: Schema introspection + compaction

**Files:**
- Create: `wp-ultra-mcp/includes/elementor/schema.php`

**Interfaces:**
- Consumes: `wpultra_err`, Elementor classes.
- Produces:
  - `wpultra_el_compact_prop(object $propType): array` — given a Prop_Type, return `{type, enum?, default?}` where `type` = `$propType::get_key()` (call statically via `get_class`), `enum` = `$propType->get_setting('enum')` if non-empty, `default` = `$propType->get_default()`. Defensive try/catch per field.
  - `wpultra_el_widget_schema(string $widgetType): array|WP_Error` — resolve the widget via `widgets_manager->get_widget_types($widgetType)`; if atomic, call `get_props_schema()` and map each prop through `wpultra_el_compact_prop`; return `{widgetType, is_atomic:true, props:{key:{type,enum?,default?}}}`. For a legacy widget return `{widgetType, is_atomic:false, controls:[...]}` from `get_controls()` (key, type, default, options). WP_Error if widget unknown.
  - `wpultra_el_list_widgets(array $filter = []): array` — iterate `get_widget_types()`; for each return `{name, title, is_atomic, elType}` (title via `get_title()`); optional `filter['atomic_only']` true → only atomic. Sorted by name.
  - `wpultra_el_style_schema(): array` — map `Style_Schema::get()` through `wpultra_el_compact_prop`, grouped flat `{cssProp:{type,...}}`.

- [ ] **Step 1: Write `includes/elementor/schema.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/** Compact a Prop_Type object to {type, enum?, default?}. */
function wpultra_el_compact_prop($prop): array {
    $out = [];
    try { $out['type'] = (string) call_user_func([get_class($prop), 'get_key']); } catch (\Throwable $e) { $out['type'] = 'unknown'; }
    try { $enum = $prop->get_setting('enum'); if (is_array($enum) && $enum) { $out['enum'] = array_values($enum); } } catch (\Throwable $e) {}
    try { $def = $prop->get_default(); if ($def !== null) { $out['default'] = $def; } } catch (\Throwable $e) {}
    return $out;
}

function wpultra_el_widget_schema(string $widgetType) {
    if (!wpultra_el_active()) { return wpultra_err('elementor_missing', 'Elementor is not active.'); }
    $w = \Elementor\Plugin::$instance->widgets_manager->get_widget_types($widgetType);
    if (!$w) { return wpultra_err('unknown_widget', "No widget type '$widgetType'."); }
    $is_atomic = $w instanceof \Elementor\Modules\AtomicWidgets\Elements\Base\Atomic_Widget_Base;
    if ($is_atomic) {
        $schema = call_user_func([get_class($w), 'get_props_schema']);
        $props = [];
        foreach ($schema as $key => $prop) {
            if (is_object($prop)) { $props[$key] = wpultra_el_compact_prop($prop); }
        }
        return ['widgetType' => $widgetType, 'is_atomic' => true, 'props' => $props];
    }
    $controls = [];
    foreach ((array) $w->get_controls() as $name => $c) {
        $entry = ['name' => $name, 'type' => $c['type'] ?? '', 'default' => $c['default'] ?? null];
        if (!empty($c['options'])) { $entry['options'] = $c['options']; }
        $controls[] = $entry;
    }
    return ['widgetType' => $widgetType, 'is_atomic' => false, 'controls' => $controls];
}

function wpultra_el_list_widgets(array $filter = []): array {
    if (!wpultra_el_active()) { return []; }
    $atomic_only = !empty($filter['atomic_only']);
    $out = [];
    foreach (\Elementor\Plugin::$instance->widgets_manager->get_widget_types() as $name => $w) {
        $is_atomic = $w instanceof \Elementor\Modules\AtomicWidgets\Elements\Base\Atomic_Widget_Base;
        if ($atomic_only && !$is_atomic) { continue; }
        $out[] = [
            'name' => (string) $name,
            'title' => method_exists($w, 'get_title') ? (string) $w->get_title() : (string) $name,
            'is_atomic' => $is_atomic,
        ];
    }
    usort($out, fn($a, $b) => strcmp($a['name'], $b['name']));
    return $out;
}

function wpultra_el_style_schema(): array {
    if (!class_exists('\\Elementor\\Modules\\AtomicWidgets\\Styles\\Style_Schema')) { return []; }
    $schema = \Elementor\Modules\AtomicWidgets\Styles\Style_Schema::get();
    $out = [];
    foreach ($schema as $cssProp => $prop) {
        if (is_object($prop)) { $out[$cssProp] = wpultra_el_compact_prop($prop); }
    }
    return $out;
}
```

- [ ] **Step 2: Lint + commit**

Run `& $PHP -l ...schema.php` → clean.
```bash
git add wp-ultra-mcp/includes/elementor/schema.php
git commit -m "feat(plugin): Elementor schema introspection + compaction"
```

---

### Task 3: Pure tree operations (unit-tested)

**Files:**
- Create: `wp-ultra-mcp/includes/elementor/tree.php`
- Test: `tests/elementor-tree.test.php`

**Interfaces:**
- Produces (all pure, array-in/array-out):
  - `wpultra_el_compact_tree(array $elements, int $depth=0): array` — shape each node to `{id, elType, widgetType?, children}` (children recursive, capped depth 12).
  - `wpultra_el_walk(array &$elements, string $id, callable $fn): bool` — DFS; call `$fn(&$node, &$siblings, $index)` on the node with `id`; true if found.
  - `wpultra_el_find(array $elements, string $id): ?array` — return a copy of the node with `id`, or null.
  - `wpultra_el_insert(array $elements, ?string $parentId, int $pos, array $node): array|WP_Error` — insert `$node` at `$pos` under `$parentId` (null = root); WP_Error if parent not found.
  - `wpultra_el_remove(array $elements, string $id): array|WP_Error` — remove node `id`.
  - `wpultra_el_move(array $elements, string $id, ?string $toParentId, int $pos): array|WP_Error` — detach node `id` (with its subtree) and re-insert at `toParentId`+`pos`.
  - `wpultra_el_merge_settings(array $elements, string $id, array $settings, bool $deep): array|WP_Error` — shallow or deep-merge `$settings` into node `id`'s `settings`.

- [ ] **Step 1: Write the failing test** `tests/elementor-tree.test.php`

```php
<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/elementor/tree.php';

function el_fix(): array {
    return [[
        'id' => 'row0001', 'elType' => 'e-flexbox', 'settings' => [], 'elements' => [
            ['id' => 'head001', 'elType' => 'widget', 'widgetType' => 'e-heading', 'settings' => ['tag' => ['$$type' => 'string', 'value' => 'h2']], 'elements' => []],
            ['id' => 'btn0001', 'elType' => 'widget', 'widgetType' => 'e-button', 'settings' => [], 'elements' => []],
        ],
    ]];
}

it('compact tree shapes nodes', function () {
    $c = wpultra_el_compact_tree(el_fix());
    assert_eq('row0001', $c[0]['id']);
    assert_eq('e-flexbox', $c[0]['elType']);
    assert_eq('e-heading', $c[0]['children'][0]['widgetType']);
});
it('find returns the node', function () {
    assert_eq('e-button', wpultra_el_find(el_fix(), 'btn0001')['widgetType']);
    assert_eq(null, wpultra_el_find(el_fix(), 'nope'));
});
it('insert under parent at position', function () {
    $node = ['id' => 'img0001', 'elType' => 'widget', 'widgetType' => 'e-image', 'settings' => [], 'elements' => []];
    $out = wpultra_el_insert(el_fix(), 'row0001', 1, $node);
    assert_eq('img0001', $out[0]['elements'][1]['id']);
    assert_eq('btn0001', $out[0]['elements'][2]['id']);
});
it('insert at root', function () {
    $node = ['id' => 'r2', 'elType' => 'e-div-block', 'settings' => [], 'elements' => []];
    $out = wpultra_el_insert(el_fix(), null, 0, $node);
    assert_eq('r2', $out[0]['id']);
});
it('insert errors on missing parent', function () {
    assert_wp_error(wpultra_el_insert(el_fix(), 'nope', 0, ['id' => 'x', 'elType' => 'widget', 'widgetType' => 'e-image', 'elements' => []]));
});
it('remove deletes node', function () {
    $out = wpultra_el_remove(el_fix(), 'head001');
    assert_eq(1, count($out[0]['elements']));
    assert_eq('btn0001', $out[0]['elements'][0]['id']);
});
it('move relocates subtree', function () {
    $out = wpultra_el_move(el_fix(), 'head001', null, 0);
    assert_eq('head001', $out[0]['id']);
    assert_eq(1, count($out[1]['elements']));
});
it('merge settings shallow', function () {
    $out = wpultra_el_merge_settings(el_fix(), 'head001', ['title' => ['$$type' => 'html-v3', 'value' => 'x']], false);
    $node = wpultra_el_find($out, 'head001');
    assert_true(isset($node['settings']['tag']) && isset($node['settings']['title']), 'both keys present');
});

run_tests();
```

- [ ] **Step 2: Run to verify failure**

Run: `& $PHP E:\wp-connector\tests\elementor-tree.test.php` → FAIL (functions undefined).

- [ ] **Step 3: Write `includes/elementor/tree.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

function wpultra_el_compact_tree(array $elements, int $depth = 0): array {
    $out = [];
    foreach ($elements as $n) {
        if (!is_array($n)) { continue; }
        $e = ['id' => $n['id'] ?? '', 'elType' => $n['elType'] ?? ''];
        if (!empty($n['widgetType'])) { $e['widgetType'] = $n['widgetType']; }
        $children = is_array($n['elements'] ?? null) ? $n['elements'] : [];
        $e['children'] = $depth < 12 ? wpultra_el_compact_tree($children, $depth + 1) : [];
        $out[] = $e;
    }
    return $out;
}

function wpultra_el_walk(array &$elements, string $id, callable $fn): bool {
    foreach ($elements as $i => &$n) {
        if (($n['id'] ?? null) === $id) { $fn($n, $elements, $i); return true; }
        if (!empty($n['elements']) && is_array($n['elements'])) {
            if (wpultra_el_walk($n['elements'], $id, $fn)) { return true; }
        }
    }
    return false;
}

function wpultra_el_find(array $elements, string $id): ?array {
    $found = null;
    wpultra_el_walk($elements, $id, function ($node) use (&$found) { $found = $node; });
    return $found;
}

function wpultra_el_insert(array $elements, ?string $parentId, int $pos, array $node) {
    if ($parentId === null || $parentId === '') {
        $pos = max(0, min($pos, count($elements)));
        array_splice($elements, $pos, 0, [$node]);
        return $elements;
    }
    $done = wpultra_el_walk($elements, $parentId, function (&$parent) use ($node, $pos) {
        if (!isset($parent['elements']) || !is_array($parent['elements'])) { $parent['elements'] = []; }
        $p = max(0, min($pos, count($parent['elements'])));
        array_splice($parent['elements'], $p, 0, [$node]);
    });
    return $done ? $elements : wpultra_err('parent_not_found', "No element with id '$parentId'.");
}

function wpultra_el_remove(array $elements, string $id) {
    $done = wpultra_el_walk($elements, $id, function (&$n, &$siblings, $i) { array_splice($siblings, $i, 1); });
    return $done ? $elements : wpultra_err('element_not_found', "No element with id '$id'.");
}

function wpultra_el_move(array $elements, string $id, ?string $toParentId, int $pos) {
    $node = wpultra_el_find($elements, $id);
    if ($node === null) { return wpultra_err('element_not_found', "No element with id '$id'."); }
    $removed = wpultra_el_remove($elements, $id);
    if (is_wp_error($removed)) { return $removed; }
    return wpultra_el_insert($removed, $toParentId, $pos, $node);
}

function wpultra_el_merge_settings(array $elements, string $id, array $settings, bool $deep) {
    $done = wpultra_el_walk($elements, $id, function (&$n) use ($settings, $deep) {
        $cur = is_array($n['settings'] ?? null) ? $n['settings'] : [];
        $n['settings'] = $deep ? array_replace_recursive($cur, $settings) : array_merge($cur, $settings);
    });
    return $done ? $elements : wpultra_err('element_not_found', "No element with id '$id'.");
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `& $PHP E:\wp-connector\tests\elementor-tree.test.php` → all 8 tests PASS.

- [ ] **Step 5: Lint + commit**

```bash
git add wp-ultra-mcp/includes/elementor/tree.php tests/elementor-tree.test.php
git commit -m "feat(plugin): Elementor pure tree ops (compact/find/insert/remove/move/merge)"
```

---

### Task 4: Read/write engine (atomic-safe + cache invalidation)

**Files:**
- Create: `wp-ultra-mcp/includes/elementor/engine.php`

**Interfaces:**
- Consumes: `wpultra_el_compact_tree` (tree.php), `wpultra_ok/err`.
- Produces:
  - `wpultra_el_read(int $post_id, array $opts = []): array|WP_Error` — read `_elementor_data` meta → decode; return `{post_id, elements}` where elements = compact tree by default, or full raw when `$opts['full']` true, or a single element subtree when `$opts['element_id']` set.
  - `wpultra_el_raw(int $post_id): array` — decoded raw `_elementor_data` array (empty array if none/invalid).
  - `wpultra_el_write(int $post_id, array $elements): array|WP_Error` — direct meta write (atomic-safe) + edit_mode/version + cache invalidation. Returns `{post_id, top_level_count}`.

- [ ] **Step 1: Write `includes/elementor/engine.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

function wpultra_el_raw(int $post_id): array {
    $raw = get_post_meta($post_id, '_elementor_data', true);
    if (empty($raw)) { return []; }
    $data = is_string($raw) ? json_decode($raw, true) : $raw;
    return is_array($data) ? $data : [];
}

function wpultra_el_read(int $post_id, array $opts = []) {
    if ($post_id <= 0 || !get_post($post_id)) { return wpultra_err('bad_post', 'Valid post_id required.'); }
    $data = wpultra_el_raw($post_id);
    if (!empty($opts['element_id'])) {
        $node = wpultra_el_find($data, (string) $opts['element_id']);
        if ($node === null) { return wpultra_err('element_not_found', "No element '{$opts['element_id']}'."); }
        return wpultra_ok(['post_id' => $post_id, 'element' => $node]);
    }
    if (!empty($opts['full'])) { return wpultra_ok(['post_id' => $post_id, 'elements' => $data]); }
    return wpultra_ok(['post_id' => $post_id, 'elements' => wpultra_el_compact_tree($data)]);
}

function wpultra_el_write(int $post_id, array $elements) {
    if ($post_id <= 0 || !get_post($post_id)) { return wpultra_err('bad_post', 'Valid post_id required.'); }
    // Atomic-safe: write meta directly (Document::save strips atomic widgets).
    update_post_meta($post_id, '_elementor_data', wp_slash(wp_json_encode($elements)));
    update_post_meta($post_id, '_elementor_edit_mode', 'builder');
    update_post_meta($post_id, '_elementor_version', defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '4.0.0');
    // Full CSS invalidation.
    try {
        if (class_exists('\\Elementor\\Plugin')) {
            $p = \Elementor\Plugin::$instance;
            if (isset($p->files_manager)) { $p->files_manager->clear_cache(); }
        }
        delete_post_meta($post_id, '_elementor_css');
        do_action('elementor/atomic-widgets/styles/clear');
        clean_post_cache($post_id);
    } catch (\Throwable $e) { /* cache clear is best-effort */ }
    return wpultra_ok(['post_id' => $post_id, 'top_level_count' => count($elements)]);
}
```

- [ ] **Step 2: Lint + commit**

```bash
git add wp-ultra-mcp/includes/elementor/engine.php
git commit -m "feat(plugin): Elementor read/write engine (atomic-safe meta write + cache invalidation)"
```

---

### Task 5: Settings coercion + validation (unit-tested for wrap; live for validate)

**Files:**
- Create: `wp-ultra-mcp/includes/elementor/coerce.php`
- Test: `tests/elementor-coerce.test.php`

**Interfaces:**
- Produces:
  - `wpultra_el_already_wrapped($v): bool` — true if `$v` is an array with `$$type` + `value` keys. Pure.
  - `wpultra_el_wrap_value($scalar, string $type): array` — `['$$type'=>$type, 'value'=>$scalar]`. Pure.
  - `wpultra_el_wrap_settings(array $settings, array $compactSchema): array` — for each key present in `$compactSchema`, if the value is NOT already wrapped and the prop `type` is a known scalar (`string,number,boolean,color,url,html,html-v2`), wrap it; otherwise pass the value through unchanged. Unknown keys pass through. Pure (takes the COMPACT schema from Task 2, `{key:{type,...}}`).
  - `wpultra_el_validate_settings(string $widgetType, array $settings): array|WP_Error` — resolve the widget, get its real `get_props_schema()`, run `Props_Parser::make($schema)->parse($settings)`; return `['ok'=>true,'settings'=>unwrap]` or a WP_Error embedding `errors()->to_string()` + the compact schema (so the AI can self-correct). LIVE path (needs Elementor); guarded.

- [ ] **Step 1: Write the failing test** `tests/elementor-coerce.test.php`

```php
<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/elementor/coerce.php';

it('detects wrapped values', function () {
    assert_true(wpultra_el_already_wrapped(['$$type' => 'string', 'value' => 'x']), 'wrapped');
    assert_eq(false, wpultra_el_already_wrapped('plain'), 'scalar');
    assert_eq(false, wpultra_el_already_wrapped(['value' => 'x']), 'no $$type');
});
it('wraps scalar settings per schema', function () {
    $schema = ['tag' => ['type' => 'string'], 'count' => ['type' => 'number'], 'on' => ['type' => 'boolean']];
    $out = wpultra_el_wrap_settings(['tag' => 'h1', 'count' => 3, 'on' => true], $schema);
    assert_eq(['$$type' => 'string', 'value' => 'h1'], $out['tag']);
    assert_eq(['$$type' => 'number', 'value' => 3], $out['count']);
    assert_eq(['$$type' => 'boolean', 'value' => true], $out['on']);
});
it('passes through already-wrapped + unknown-type values', function () {
    $schema = ['title' => ['type' => 'html-v3'], 'tag' => ['type' => 'string']];
    $in = ['title' => ['$$type' => 'html-v3', 'value' => ['content' => 'x']], 'tag' => ['$$type' => 'string', 'value' => 'h2']];
    $out = wpultra_el_wrap_settings($in, $schema);
    assert_eq($in['title'], $out['title']);
    assert_eq($in['tag'], $out['tag']);
});
it('leaves unknown keys untouched', function () {
    $out = wpultra_el_wrap_settings(['foo' => 'bar'], ['tag' => ['type' => 'string']]);
    assert_eq('bar', $out['foo']);
});

run_tests();
```

- [ ] **Step 2: Run to verify failure**

Run: `& $PHP E:\wp-connector\tests\elementor-coerce.test.php` → FAIL.

- [ ] **Step 3: Write `includes/elementor/coerce.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

function wpultra_el_already_wrapped($v): bool {
    return is_array($v) && array_key_exists('$$type', $v) && array_key_exists('value', $v);
}

function wpultra_el_wrap_value($scalar, string $type): array {
    return ['$$type' => $type, 'value' => $scalar];
}

function wpultra_el_wrap_settings(array $settings, array $compactSchema): array {
    $scalarTypes = ['string', 'number', 'boolean', 'color', 'url', 'html', 'html-v2'];
    $out = [];
    foreach ($settings as $key => $val) {
        if (!isset($compactSchema[$key]) || wpultra_el_already_wrapped($val) || is_array($val)) {
            $out[$key] = $val;
            continue;
        }
        $type = (string) ($compactSchema[$key]['type'] ?? '');
        $out[$key] = in_array($type, $scalarTypes, true) ? wpultra_el_wrap_value($val, $type) : $val;
    }
    return $out;
}

function wpultra_el_validate_settings(string $widgetType, array $settings) {
    if (!wpultra_el_active()) { return wpultra_err('elementor_missing', 'Elementor is not active.'); }
    $w = \Elementor\Plugin::$instance->widgets_manager->get_widget_types($widgetType);
    if (!$w || !($w instanceof \Elementor\Modules\AtomicWidgets\Elements\Base\Atomic_Widget_Base)) {
        // Non-atomic or unknown: accept settings as-is (no atomic schema to validate).
        return ['ok' => true, 'settings' => $settings];
    }
    try {
        $schema = call_user_func([get_class($w), 'get_props_schema']);
        $result = \Elementor\Modules\AtomicWidgets\Parsers\Props_Parser::make($schema)->parse($settings);
        if (!$result->is_valid()) {
            $compact = wpultra_el_widget_schema($widgetType);
            return wpultra_err('invalid_settings', 'Settings failed Elementor validation: ' . $result->errors()->to_string(), is_array($compact) ? $compact : null);
        }
        return ['ok' => true, 'settings' => $result->unwrap()];
    } catch (\Throwable $e) {
        return wpultra_err('validate_failed', 'Elementor validation error: ' . $e->getMessage());
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `& $PHP E:\wp-connector\tests\elementor-coerce.test.php` → 4 tests PASS.

- [ ] **Step 5: Lint + commit**

```bash
git add wp-ultra-mcp/includes/elementor/coerce.php tests/elementor-coerce.test.php
git commit -m "feat(plugin): Elementor settings coercion ({\$\$type} wrap) + Props_Parser validation"
```

---

### Task 6: Schema-read abilities (list-widgets, get-widget-schema, get-style-schema, get-content)

**Files:**
- Create: `wp-ultra-mcp/includes/abilities/elementor-list-widgets.php`, `elementor-get-widget-schema.php`, `elementor-get-style-schema.php`, `elementor-get-content.php`
- Modify: `includes/bootstrap-mcp.php` (add `elementor` category; load the elementor engine files in `wpultra_load_abilities()`; append the 4 slugs to `wpultra_ability_files()`)

**Interfaces:**
- Consumes: schema.php, engine.php functions.
- Produces: 4 read abilities (category `elementor`).

- [ ] **Step 1: Wire bootstrap**

In `includes/bootstrap-mcp.php`:
- `wpultra_register_categories()` `$cats` add: `'elementor' => 'Elementor v4 schema-driven layout engine.'`.
- In `wpultra_load_abilities()` (before the abilities loop OR right after requiring helpers), add a guarded block to load the engine so ability callbacks can use it:
```php
foreach (['setup', 'schema', 'tree', 'engine', 'coerce'] as $elf) {
    $elp = WPULTRA_DIR . 'includes/elementor/' . $elf . '.php';
    if (is_readable($elp)) { require_once $elp; }
}
```
- Append to `wpultra_ability_files()`: `'elementor-list-widgets', 'elementor-get-widget-schema', 'elementor-get-style-schema', 'elementor-get-content'` (count 23 → 27). Update `tests/bootstrap.test.php` count `23` → `27` and assert `in_array('elementor-get-content', $files, true)`.

- [ ] **Step 2: Write the 4 read ability files** (canonical skeleton; category `elementor`):

`elementor-list-widgets.php` (SLUG `elementor-list-widgets`, input `{atomic_only:boolean}`, output `{success,widgets}`, readonly):
```php
function wpultra_elementor_list_widgets(array $input) {
    if (!wpultra_el_active()) { return wpultra_err('elementor_missing', 'Elementor is not installed/active.'); }
    return wpultra_ok(['widgets' => wpultra_el_list_widgets(['atomic_only' => ($input['atomic_only'] ?? false) === true])]);
}
```

`elementor-get-widget-schema.php` (SLUG `elementor-get-widget-schema`, input `{widget_type:string(req)}`, output `{success,...schema}`, readonly):
```php
function wpultra_elementor_get_widget_schema(array $input) {
    $type = (string) ($input['widget_type'] ?? '');
    if ($type === '') { return wpultra_err('missing_widget_type', 'widget_type is required.'); }
    $s = wpultra_el_widget_schema($type);
    if (is_wp_error($s)) { return $s; }
    return wpultra_ok($s);
}
```

`elementor-get-style-schema.php` (SLUG `elementor-get-style-schema`, no input, output `{success,style_schema}`, readonly):
```php
function wpultra_elementor_get_style_schema(array $input) {
    if (!wpultra_el_active()) { return wpultra_err('elementor_missing', 'Elementor is not active.'); }
    return wpultra_ok(['style_schema' => wpultra_el_style_schema()]);
}
```

`elementor-get-content.php` (SLUG `elementor-get-content`, input `{post_id:int(req), element_id:string, full:boolean}`, output `{success,...}`, readonly):
```php
function wpultra_elementor_get_content(array $input) {
    $post_id = (int) ($input['post_id'] ?? 0);
    $opts = [];
    if (!empty($input['element_id'])) { $opts['element_id'] = (string) $input['element_id']; }
    if (($input['full'] ?? false) === true) { $opts['full'] = true; }
    return wpultra_el_read($post_id, $opts);
}
```

- [ ] **Step 3: Lint + tests + deploy**

Lint the 4 files + bootstrap. Run `& $PHP E:\wp-connector\tests\bootstrap.test.php` (count 27) + full suite `run-all.ps1` (ALL PASS). Deploy.

- [ ] **Step 4: Live smoke (Elementor)**

Over MCP (or via a wp-load script) call `elementor-list-widgets {atomic_only:true}` → expect `e-heading`, `e-button`, etc. Call `elementor-get-widget-schema {widget_type:"e-heading"}` → expect `props.tag.enum` contains `h1..h6`. Document the result.

- [ ] **Step 5: Commit**

```bash
git add wp-ultra-mcp/includes/abilities/elementor-list-widgets.php wp-ultra-mcp/includes/abilities/elementor-get-widget-schema.php wp-ultra-mcp/includes/abilities/elementor-get-style-schema.php wp-ultra-mcp/includes/abilities/elementor-get-content.php wp-ultra-mcp/includes/bootstrap-mcp.php tests/bootstrap.test.php
git commit -m "feat(plugin): Elementor read abilities (list-widgets, get-widget-schema, get-style-schema, get-content)"
```

---

### Task 7: Mutation abilities (set-content, add/edit/delete/move-element)

**Files:**
- Create: `wp-ultra-mcp/includes/abilities/elementor-set-content.php`, `elementor-add-element.php`, `elementor-edit-element.php`, `elementor-delete-element.php`, `elementor-move-element.php`
- Modify: `includes/bootstrap-mcp.php` (`wpultra_ability_files()` += 5 → 32; bootstrap test count → 32)

**Interfaces:**
- Consumes: tree.php, engine.php, coerce.php, setup.php.
- Produces: 5 mutation abilities (category `elementor`, destructive).

- [ ] **Step 1: Wire bootstrap** — append `'elementor-set-content','elementor-add-element','elementor-edit-element','elementor-delete-element','elementor-move-element'` to `wpultra_ability_files()` (27 → 32). Update `tests/bootstrap.test.php` count `27` → `32` + assert `in_array('elementor-add-element', $files, true)`.

- [ ] **Step 2: Write the 5 ability files** (canonical skeleton; category `elementor`):

`elementor-set-content.php` (SLUG `elementor-set-content`, input `{post_id:int(req), elements:array(req)}`, output `{success,post_id,top_level_count}`, destructive): write a full elements tree (already-wrapped atomic JSON; validation is the AI's responsibility via the schema, but each top-level widget's settings are validated when present):
```php
function wpultra_elementor_set_content(array $input) {
    $post_id = (int) ($input['post_id'] ?? 0);
    $elements = $input['elements'] ?? null;
    if (is_string($elements)) { $elements = json_decode($elements, true); }
    if (!is_array($elements)) { return wpultra_err('bad_elements', 'elements must be an array (or JSON string).'); }
    return wpultra_el_write($post_id, $elements);
}
```

`elementor-add-element.php` (SLUG `elementor-add-element`, input `{post_id:int(req), element_type:string(req), parent_id:string, position:int, widget_type:string, settings:object, element_id:string}`, output `{success,post_id,element_id}`, destructive): build a node (widget or container), wrap+validate its settings, insert it:
```php
function wpultra_elementor_add_element(array $input) {
    $atomic = wpultra_el_require_atomic();
    if (is_wp_error($atomic)) { return $atomic; }
    $post_id = (int) ($input['post_id'] ?? 0);
    if ($post_id <= 0 || !get_post($post_id)) { return wpultra_err('bad_post', 'Valid post_id required.'); }
    $elType = (string) ($input['element_type'] ?? '');
    $id = (string) ($input['element_id'] ?? '') ?: wpultra_el_new_id();
    $settings = (array) ($input['settings'] ?? []);
    $node = ['id' => $id, 'elType' => $elType, 'settings' => [], 'elements' => []];
    if ($elType === 'widget') {
        $wt = (string) ($input['widget_type'] ?? '');
        if ($wt === '') { return wpultra_err('missing_widget_type', "element_type 'widget' requires widget_type."); }
        $node['widgetType'] = $wt;
        $schema = wpultra_el_widget_schema($wt);
        $compact = (is_array($schema) && !empty($schema['props'])) ? $schema['props'] : [];
        $wrapped = wpultra_el_wrap_settings($settings, $compact);
        $valid = wpultra_el_validate_settings($wt, $wrapped);
        if (is_wp_error($valid)) { return $valid; }
        $node['settings'] = $valid['settings'];
    } else {
        // container (e-flexbox / e-div-block): settings passed through (style-level), wrap if scalar via style schema is out of scope here
        $node['settings'] = $settings;
    }
    $data = wpultra_el_raw($post_id);
    $parent = isset($input['parent_id']) && $input['parent_id'] !== '' ? (string) $input['parent_id'] : null;
    $pos = (int) ($input['position'] ?? PHP_INT_MAX);
    $updated = wpultra_el_insert($data, $parent, $pos, $node);
    if (is_wp_error($updated)) { return $updated; }
    $w = wpultra_el_write($post_id, $updated);
    if (is_wp_error($w)) { return $w; }
    return wpultra_ok(['post_id' => $post_id, 'element_id' => $id]);
}
```

`elementor-edit-element.php` (SLUG `elementor-edit-element`, input `{post_id:int(req), element_id:string(req), settings:object(req), deep:boolean}`, output `{success,post_id,element_id}`, destructive): wrap+validate against the target widget's schema, merge:
```php
function wpultra_elementor_edit_element(array $input) {
    $post_id = (int) ($input['post_id'] ?? 0);
    $eid = (string) ($input['element_id'] ?? '');
    if ($post_id <= 0 || $eid === '') { return wpultra_err('bad_input', 'post_id and element_id are required.'); }
    $data = wpultra_el_raw($post_id);
    $node = wpultra_el_find($data, $eid);
    if ($node === null) { return wpultra_err('element_not_found', "No element '$eid'."); }
    $settings = (array) ($input['settings'] ?? []);
    if (($node['elType'] ?? '') === 'widget' && !empty($node['widgetType'])) {
        $schema = wpultra_el_widget_schema((string) $node['widgetType']);
        $compact = (is_array($schema) && !empty($schema['props'])) ? $schema['props'] : [];
        $settings = wpultra_el_wrap_settings($settings, $compact);
        $valid = wpultra_el_validate_settings((string) $node['widgetType'], array_merge((array) ($node['settings'] ?? []), $settings));
        if (is_wp_error($valid)) { return $valid; }
    }
    $updated = wpultra_el_merge_settings($data, $eid, $settings, ($input['deep'] ?? false) === true);
    if (is_wp_error($updated)) { return $updated; }
    $w = wpultra_el_write($post_id, $updated);
    if (is_wp_error($w)) { return $w; }
    return wpultra_ok(['post_id' => $post_id, 'element_id' => $eid]);
}
```

`elementor-delete-element.php` (SLUG `elementor-delete-element`, input `{post_id:int(req), element_id:string(req)}`, output `{success,post_id,element_id}`, destructive):
```php
function wpultra_elementor_delete_element(array $input) {
    $post_id = (int) ($input['post_id'] ?? 0);
    $eid = (string) ($input['element_id'] ?? '');
    if ($post_id <= 0 || $eid === '') { return wpultra_err('bad_input', 'post_id and element_id are required.'); }
    $updated = wpultra_el_remove(wpultra_el_raw($post_id), $eid);
    if (is_wp_error($updated)) { return $updated; }
    $w = wpultra_el_write($post_id, $updated);
    if (is_wp_error($w)) { return $w; }
    return wpultra_ok(['post_id' => $post_id, 'element_id' => $eid]);
}
```

`elementor-move-element.php` (SLUG `elementor-move-element`, input `{post_id:int(req), element_id:string(req), to_parent_id:string, position:int}`, output `{success,post_id,element_id}`, destructive — our delta vs paid tools):
```php
function wpultra_elementor_move_element(array $input) {
    $post_id = (int) ($input['post_id'] ?? 0);
    $eid = (string) ($input['element_id'] ?? '');
    if ($post_id <= 0 || $eid === '') { return wpultra_err('bad_input', 'post_id and element_id are required.'); }
    $to = isset($input['to_parent_id']) && $input['to_parent_id'] !== '' ? (string) $input['to_parent_id'] : null;
    $pos = (int) ($input['position'] ?? PHP_INT_MAX);
    $updated = wpultra_el_move(wpultra_el_raw($post_id), $eid, $to, $pos);
    if (is_wp_error($updated)) { return $updated; }
    $w = wpultra_el_write($post_id, $updated);
    if (is_wp_error($w)) { return $w; }
    return wpultra_ok(['post_id' => $post_id, 'element_id' => $eid]);
}
```

- [ ] **Step 3: Lint + tests + deploy** — lint 5 files + bootstrap; `bootstrap.test.php` count 32; full suite PASS; deploy.

- [ ] **Step 4: Commit**

```bash
git add wp-ultra-mcp/includes/abilities/elementor-set-content.php wp-ultra-mcp/includes/abilities/elementor-add-element.php wp-ultra-mcp/includes/abilities/elementor-edit-element.php wp-ultra-mcp/includes/abilities/elementor-delete-element.php wp-ultra-mcp/includes/abilities/elementor-move-element.php wp-ultra-mcp/includes/bootstrap-mcp.php tests/bootstrap.test.php
git commit -m "feat(plugin): Elementor mutation abilities (set-content, add/edit/delete/move-element)"
```

---

### Task 8: Built-in skill + README + live end-to-end + push

**Files:**
- Create: `wp-ultra-mcp/includes/skills/built-in/elementor-v4-architect.md`
- Modify: `README.md` (mark Wave 2 shipped), `wp-ultra-mcp/wp-ultra-mcp.php` (bump version 0.1.0 → 0.2.0), `wp-ultra-mcp/readme.txt` (Stable tag + changelog)

- [ ] **Step 1: Write the built-in skill** `includes/skills/built-in/elementor-v4-architect.md`:

```markdown
---
name: elementor-v4-architect
description: How to build Elementor v4 (atomic) layouts via the wpultra elementor-* abilities.
enable_prompt: true
enable_agentic: true
---
You build Elementor **v4 atomic** layouts through WP-Ultra-MCP. Never guess settings — introspect the real schema.

Workflow:
1. `wpultra/elementor-list-widgets` (atomic_only:true) to see available widgets (e-heading, e-button, e-image, e-paragraph, e-divider, e-flexbox container, e-div-block container, …).
2. `wpultra/elementor-get-widget-schema` (widget_type) BEFORE setting any widget — it returns each prop's `type` (the `$$type`), `enum` (allowed values), and `default`. e.g. e-heading has `tag` (enum h1..h6) and `title`.
3. Build the page: `wpultra/elementor-add-element` — for a container pass `element_type: "e-flexbox"`; for a widget pass `element_type:"widget"`, `widget_type:"e-heading"`, and `settings`. You may pass plain scalars (`{"tag":"h2"}`) — the engine wraps them into the `{$$type,value}` form and validates them via Elementor's own parser. Complex props (title html, link, image) should be passed already-wrapped per the schema.
4. Position with `parent_id` + `position`. Re-arrange with `wpultra/elementor-move-element`. Tweak with `wpultra/elementor-edit-element`. Remove with `wpultra/elementor-delete-element`.
5. Read structure with `wpultra/elementor-get-content` (compact tree; pass `element_id` to drill into one node's full settings).

Data model: `_elementor_data` is an array of nodes. Widget node = `{id, elType:'widget', widgetType, settings:{prop:{$$type,value}}, styles:{}, elements:[]}`. Container = `{id, elType:'e-flexbox'|'e-div-block', settings, styles, elements:[…]}`. The engine writes atomic-safe (it does NOT route through Document::save, which strips atomic widgets) and clears Elementor's CSS cache for you.

A 3-column section = one `e-flexbox` container (settings display:flex via the style schema) holding three child containers, each holding its widget(s).
```

- [ ] **Step 2: Bump version + README/readme** — set `Version: 0.2.0` in `wp-ultra-mcp.php`, `WPULTRA_VERSION` to `0.2.0`; update `readme.txt` Stable tag + a 0.2.0 changelog line; in `README.md` move Elementor from "planned" to a new "Wave 2 — shipped" subsection listing the 9 elementor-* abilities and removing the `*` Elementor footnote. (Concrete prose, no placeholders.)

- [ ] **Step 3: Full suite + deploy** — `run-all.ps1` (ALL PASS), lint all plugin PHP, `bin/deploy.ps1`.

- [ ] **Step 4: Live end-to-end test (the headline)** — with Elementor active on the Local site:
  1. Create a draft page (via `wpultra/create-post` or SQL).
  2. `elementor-add-element` a `e-flexbox` container at root.
  3. `elementor-add-element` an `e-heading` widget under it with `{tag:"h2", title:<wrapped html-v3 "Hello from AI">}` (use the schema from `get-widget-schema` to shape `title`).
  4. `elementor-get-content` → confirm the tree.
  5. Open the page in the Elementor editor (or front-end) and confirm the heading renders. Document the exact calls + result in the report. If `title` shaping is non-trivial, capture the working wrapped form for the skill.

- [ ] **Step 5: Commit + push**

```bash
git add wp-ultra-mcp/includes/skills/built-in/elementor-v4-architect.md README.md wp-ultra-mcp/wp-ultra-mcp.php wp-ultra-mcp/readme.txt
git commit -m "feat(plugin): Wave 2 Elementor — built-in architect skill, v0.2.0, docs"
git push origin main
```

Then a GitHub release **v0.2.0** with a freshly built `wp-ultra-mcp.zip` (run `bin/build-zip.ps1`, then `gh release create v0.2.0 ...`).

---

## Self-Review

**Spec coverage (program §2):** schema introspection + caching (T2; caching is a follow-on optimization, not blocking), value coercion + two-layer validation (T5 — wrap + Props_Parser), read/write with atomic-bypass + full CSS invalidation + (ETag is a follow-on) (T4), editing abilities incl. move-element + deep-merge (T3 tree + T7 abilities), list/get-schema/get-content/set-content (T6/T7). Design systems (dynamic tags, variables, global classes, interactions) and create-atomic-widget remain **Wave 3+** per spec — explicitly out of scope here.

**Placeholder scan:** engine/tree/coerce tasks carry complete code + tests. Ability tasks carry complete callbacks. The README/version step is concrete prose against named files. No TBD/TODO.

**Type/name consistency:** `wpultra_el_*` names consistent across setup→schema→tree→engine→coerce→abilities. `wpultra_el_widget_schema` returns `{props:{...}}` for atomic — consumed by coerce (T5) and add/edit abilities (T7) as `$schema['props']`. `wpultra_el_compact_tree`/`find`/`insert`/`remove`/`move`/`merge_settings` (T3) consumed by engine (T4) + abilities (T7). `wpultra_ability_files()` grows 23→27 (T6)→32 (T7), bootstrap test updated each time. Category `elementor` registered in T6.

**Known scope honesty:** the `title`/`link`/`image` complex props require already-wrapped values shaped per the schema; the auto-wrap covers scalar props (tag, text, sizes-as-number). The live test (T8 Step 4) verifies a real atomic heading renders; if complex-prop shaping needs a helper, that is a documented Wave 2.1 follow-on, not a blocker for the core.
