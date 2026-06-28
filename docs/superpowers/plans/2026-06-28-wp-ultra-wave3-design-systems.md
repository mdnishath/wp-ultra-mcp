# WP-Ultra-MCP — Wave 3 (Elementor Design Systems) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`) syntax.
>
> Wave 3 of the program (§2.5). Builds on shipped Wave 1/1.5/2. Grounded in the **real Elementor 4.1.4 design-system API** (verified against the installed source).

**Goal:** MCP abilities to read and write Elementor's site-wide design systems — global colors + typography (kit), design-token **variables**, and **dynamic-tag** discovery — so an AI can manage a site's brand/design system, not just individual widgets.

**Architecture:** A `includes/elementor/design.php` engine reads/writes the active Kit's `_elementor_page_settings` meta (colors/typography) via the Kit document API, lists/creates v4 **variables** via `Variables_Service` (gated on the `e_variables` experiment), and enumerates dynamic tags via `Plugin::$instance->dynamic_tags`. Thin abilities expose get-design-system / manage-global-colors / manage-variables / list-dynamic-tags. Live-tested against the Local site (Elementor 4.1.4).

**Tech Stack:** Same as Wave 1-2. **Global colors/typography need no experiment.** Variables need `e_variables` (default active in 4.x); abilities self-gate and return a clear error when off.

## Global Constraints

- All prior global constraints apply (file headers; abilities return array-or-WP_Error; `wpultra_permission_callback`; canonical registration shape with PLAIN-ARRAY `input_schema` properties; category `elementor`; deploy via `bin/deploy.ps1` after every commit; bundled PHP `$PHP`; test site `C:/Users/nisha/Local Sites/wp-connector/app/public`).
- **Verified Elementor 4.1.4 design API (use exactly these):**
  - Active kit: `\Elementor\Plugin::$instance->kits_manager->get_active_kit()` (Kit document). `->get_settings('system_colors')` reads a setting; `->update_settings(['custom_colors'=>[...]])` writes (merges) into `_elementor_page_settings` meta; `->add_repeater_row('custom_colors', $row)` appends one. `kits_manager->get_current_settings('system_colors')` is a shortcut read.
  - Colors shape: `system_colors`/`custom_colors` = array of `['_id'=>slug, 'title'=>label, 'color'=>'#hex']`. System ids: primary/secondary/text/accent (fixed). CSS var: `--e-global-color-{_id}`.
  - Typography shape: `system_typography`/`custom_typography` = array of `['_id'=>slug,'title'=>label,'typography_typography'=>'custom','typography_font_family'=>..., 'typography_font_weight'=>...]` (prefix `typography_`).
  - After writing kit settings: `\Elementor\Plugin::$instance->files_manager->clear_cache()`.
  - Variables (gate `e_variables`): `\Elementor\Modules\Variables\Storage\Variables_Repository` + `\Elementor\Modules\Variables\Services\Variables_Service` + `\Elementor\Modules\Variables\Services\Batch_Operations\Batch_Processor`. Construct: `$repo = new Variables_Repository($activeKit); $svc = new Variables_Service($repo, new Batch_Processor());`. `$svc->get_variables_list()` → `[id => {type,label,value,...}]`. `$svc->create(['type'=>'global-color-variable','label'=>'Brand','value'=>'#0055FF'])` → `{variable:{id,...}, watermark}`. Types: `global-color-variable`, `global-font-variable`, `global-size-variable`. Stored in `_elementor_global_variables` kit meta. Variable ref in a prop = `{$$type:'global-color-variable', value:'e-gv-<id>'}`.
  - Dynamic tags: `\Elementor\Plugin::$instance->dynamic_tags->get_tags_config()` → `[slug => {name,title,categories,group,...}]`. Atomic binding shape on a prop = `{$$type:'dynamic', value:{name:'post-title', group:'post', settings:{}}}`.
  - Experiment check: `\Elementor\Plugin::$instance->experiments->is_feature_active('e_variables')`.

## File Structure

```
wp-ultra-mcp/includes/
  elementor/design.php          kit colors/typography + variables + dynamic-tags engine   — Task 1
  abilities/
    elementor-get-design-system.php   elementor-list-dynamic-tags.php                      — Task 2
    elementor-manage-global-colors.php  elementor-manage-variables.php                     — Task 3
  skills/built-in/elementor-v4-architect.md   (append a "Design systems" section)          — Task 4
tests/elementor-design.test.php   (pure color-validation helper)                            — Task 1
```

Modify `includes/bootstrap-mcp.php` (`wpultra_ability_files()` += 4 → 36; load design.php in the engine require loop).

---

### Task 1: Design engine + color-validation helper (unit-tested)

**Files:**
- Create: `wp-ultra-mcp/includes/elementor/design.php`, `tests/elementor-design.test.php`
- Modify: `includes/bootstrap-mcp.php` (add `'design'` to the elementor engine require list in `wpultra_load_abilities()`)

**Interfaces:**
- Produces:
  - `wpultra_el_is_hex_color(string $c): bool` — `^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$`. Pure.
  - `wpultra_el_slug(string $s): string` — lowercase, non-alnum→`-`, trim dashes; fallback `'item'` if empty. Pure.
  - `wpultra_el_get_design_system(): array|WP_Error` — `{colors:{system,custom}, typography:{system,custom}, variables:{active,items}}` from the active kit.
  - `wpultra_el_set_global_colors(array $colors, string $target): array|WP_Error` — `$target` ∈ {custom,system}; each `$colors` entry `{id?,title,color}` (color hex-validated); upsert into the kit's `custom_colors`/`system_colors` (by `_id`); write + clear cache. Returns the resulting color list.
  - `wpultra_el_variables_active(): bool` — Elementor active + `experiments->is_feature_active('e_variables')` (try/catch).
  - `wpultra_el_variables_list(): array|WP_Error`, `wpultra_el_variables_create(string $type, string $label, $value): array|WP_Error` — via `Variables_Service` (gated).
  - `wpultra_el_list_dynamic_tags(): array` — from `dynamic_tags->get_tags_config()`, compact to `[{name,title,categories,group}]`.

- [ ] **Step 1: Write the failing test** `tests/elementor-design.test.php`

```php
<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/elementor/design.php';

it('validates hex colors', function () {
    assert_true(wpultra_el_is_hex_color('#0055FF'), '6-digit');
    assert_true(wpultra_el_is_hex_color('#abc'), '3-digit');
    assert_eq(false, wpultra_el_is_hex_color('red'), 'name');
    assert_eq(false, wpultra_el_is_hex_color('#12'), 'short');
});
it('slugifies labels', function () {
    assert_eq('brand-blue', wpultra_el_slug('Brand Blue'));
    assert_eq('my-color-1', wpultra_el_slug('My Color #1'));
    assert_eq('item', wpultra_el_slug('!!!'));
});

run_tests();
```

- [ ] **Step 2: Run to verify failure**

Run: `& $PHP E:\wp-connector\tests\elementor-design.test.php` → FAIL.

- [ ] **Step 3: Write `includes/elementor/design.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

function wpultra_el_is_hex_color(string $c): bool {
    return (bool) preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $c);
}

function wpultra_el_slug(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim((string) $s, '-');
    return $s !== '' ? $s : 'item';
}

function wpultra_el_active_kit() {
    if (!class_exists('\\Elementor\\Plugin')) { return null; }
    try { return \Elementor\Plugin::$instance->kits_manager->get_active_kit(); } catch (\Throwable $e) { return null; }
}

function wpultra_el_get_design_system() {
    $kit = wpultra_el_active_kit();
    if (!$kit) { return wpultra_err('elementor_missing', 'Elementor is not active / no active kit.'); }
    $colors = [
        'system' => (array) $kit->get_settings('system_colors'),
        'custom' => (array) $kit->get_settings('custom_colors'),
    ];
    $typo = [
        'system' => (array) $kit->get_settings('system_typography'),
        'custom' => (array) $kit->get_settings('custom_typography'),
    ];
    $vars = ['active' => wpultra_el_variables_active(), 'items' => []];
    if ($vars['active']) {
        $list = wpultra_el_variables_list();
        $vars['items'] = is_wp_error($list) ? [] : $list;
    }
    return wpultra_ok(['colors' => $colors, 'typography' => $typo, 'variables' => $vars]);
}

function wpultra_el_set_global_colors(array $colors, string $target = 'custom') {
    $kit = wpultra_el_active_kit();
    if (!$kit) { return wpultra_err('elementor_missing', 'Elementor is not active / no active kit.'); }
    $key = $target === 'system' ? 'system_colors' : 'custom_colors';
    $current = (array) $kit->get_settings($key);
    $byId = [];
    foreach ($current as $row) { if (isset($row['_id'])) { $byId[$row['_id']] = $row; } }
    foreach ($colors as $c) {
        $hex = (string) ($c['color'] ?? '');
        if (!wpultra_el_is_hex_color($hex)) { return wpultra_err('bad_color', "Invalid hex color: '$hex'."); }
        $title = (string) ($c['title'] ?? 'Color');
        $id = (string) ($c['id'] ?? '') ?: wpultra_el_slug($title);
        $byId[$id] = ['_id' => $id, 'title' => $title, 'color' => $hex];
    }
    $list = array_values($byId);
    $kit->update_settings([$key => $list]);
    try { \Elementor\Plugin::$instance->files_manager->clear_cache(); } catch (\Throwable $e) {}
    return wpultra_ok([$key => $list]);
}

function wpultra_el_variables_active(): bool {
    if (!class_exists('\\Elementor\\Plugin')) { return false; }
    try {
        return \Elementor\Plugin::$instance->experiments->is_feature_active('e_variables')
            && class_exists('\\Elementor\\Modules\\Variables\\Services\\Variables_Service');
    } catch (\Throwable $e) { return false; }
}

function wpultra_el_variables_service() {
    $kit = wpultra_el_active_kit();
    if (!$kit) { return null; }
    try {
        $repo = new \Elementor\Modules\Variables\Storage\Variables_Repository($kit);
        return new \Elementor\Modules\Variables\Services\Variables_Service($repo, new \Elementor\Modules\Variables\Services\Batch_Operations\Batch_Processor());
    } catch (\Throwable $e) { return null; }
}

function wpultra_el_variables_list() {
    if (!wpultra_el_variables_active()) { return wpultra_err('variables_inactive', 'The Elementor "e_variables" experiment is not active.'); }
    $svc = wpultra_el_variables_service();
    if (!$svc) { return wpultra_err('variables_unavailable', 'Could not load the Variables service.'); }
    try { return (array) $svc->get_variables_list(); } catch (\Throwable $e) { return wpultra_err('variables_error', $e->getMessage()); }
}

function wpultra_el_variables_create(string $type, string $label, $value) {
    if (!wpultra_el_variables_active()) { return wpultra_err('variables_inactive', 'The Elementor "e_variables" experiment is not active.'); }
    $types = ['global-color-variable', 'global-font-variable', 'global-size-variable'];
    if (!in_array($type, $types, true)) { return wpultra_err('bad_variable_type', 'type must be one of: ' . implode(', ', $types)); }
    $svc = wpultra_el_variables_service();
    if (!$svc) { return wpultra_err('variables_unavailable', 'Could not load the Variables service.'); }
    try {
        $res = $svc->create(['type' => $type, 'label' => $label, 'value' => $value]);
        return wpultra_ok(['variable' => $res['variable'] ?? $res]);
    } catch (\Throwable $e) { return wpultra_err('variables_create_failed', $e->getMessage()); }
}

function wpultra_el_list_dynamic_tags(): array {
    if (!class_exists('\\Elementor\\Plugin')) { return []; }
    try {
        $cfg = \Elementor\Plugin::$instance->dynamic_tags->get_tags_config();
    } catch (\Throwable $e) { return []; }
    $out = [];
    foreach ((array) $cfg as $slug => $t) {
        $out[] = [
            'name' => (string) ($t['name'] ?? $slug),
            'title' => (string) ($t['title'] ?? $slug),
            'group' => $t['group'] ?? '',
            'categories' => array_values((array) ($t['categories'] ?? [])),
        ];
    }
    usort($out, fn($a, $b) => strcmp($a['name'], $b['name']));
    return $out;
}
```

- [ ] **Step 4: Wire bootstrap** — in `includes/bootstrap-mcp.php` `wpultra_load_abilities()`, add `'design'` to the elementor engine file list (so it becomes `['setup','schema','tree','engine','coerce','design']`).

- [ ] **Step 5: Run test + lint + full suite**

Run: `& $PHP E:\wp-connector\tests\elementor-design.test.php` → 2 pass. Lint design.php + bootstrap. Full suite `run-all.ps1` → ALL PASS.

- [ ] **Step 6: Commit**

```bash
git add wp-ultra-mcp/includes/elementor/design.php wp-ultra-mcp/includes/bootstrap-mcp.php tests/elementor-design.test.php
git commit -m "feat(plugin): Elementor design-system engine (kit colors/typography, variables, dynamic-tags)"
```

---

### Task 2: Read abilities (get-design-system, list-dynamic-tags)

**Files:**
- Create: `wp-ultra-mcp/includes/abilities/elementor-get-design-system.php`, `elementor-list-dynamic-tags.php`
- Modify: `includes/bootstrap-mcp.php` (`wpultra_ability_files()` += 2 → 34; bootstrap test → 34)

- [ ] **Step 1: Wire + test count** — append `'elementor-get-design-system','elementor-list-dynamic-tags'` to `wpultra_ability_files()` (32 → 34). Update `tests/bootstrap.test.php` count → 34 + assert `in_array('elementor-get-design-system', $files, true)`.

- [ ] **Step 2: Write the 2 ability files** (canonical skeleton; category `elementor`, readonly):

`elementor-get-design-system.php` (SLUG `elementor-get-design-system`, no input, output `{success,colors,typography,variables}`):
```php
function wpultra_elementor_get_design_system(array $input) {
    return wpultra_el_get_design_system();
}
```

`elementor-list-dynamic-tags.php` (SLUG `elementor-list-dynamic-tags`, no input, output `{success,dynamic_tags}`):
```php
function wpultra_elementor_list_dynamic_tags(array $input) {
    if (!class_exists('\\Elementor\\Plugin')) { return wpultra_err('elementor_missing', 'Elementor is not active.'); }
    return wpultra_ok(['dynamic_tags' => wpultra_el_list_dynamic_tags()]);
}
```

- [ ] **Step 3: Lint + bootstrap test (34) + full suite + deploy.**

- [ ] **Step 4: Commit**

```bash
git add wp-ultra-mcp/includes/abilities/elementor-get-design-system.php wp-ultra-mcp/includes/abilities/elementor-list-dynamic-tags.php wp-ultra-mcp/includes/bootstrap-mcp.php tests/bootstrap.test.php
git commit -m "feat(plugin): Elementor design read abilities (get-design-system, list-dynamic-tags)"
```

---

### Task 3: Write abilities (manage-global-colors, manage-variables)

**Files:**
- Create: `wp-ultra-mcp/includes/abilities/elementor-manage-global-colors.php`, `elementor-manage-variables.php`
- Modify: `includes/bootstrap-mcp.php` (`wpultra_ability_files()` += 2 → 36; bootstrap test → 36)

- [ ] **Step 1: Wire + test count** — append `'elementor-manage-global-colors','elementor-manage-variables'` (34 → 36). Update `tests/bootstrap.test.php` count → 36 + assert `in_array('elementor-manage-global-colors', $files, true)`.

- [ ] **Step 2: Write the 2 ability files** (canonical skeleton; category `elementor`, destructive):

`elementor-manage-global-colors.php` (SLUG `elementor-manage-global-colors`, input `{colors:array(req), target:enum[custom,system]}`, output `{success,custom_colors|system_colors}`): each color `{id?,title,color(hex)}`:
```php
function wpultra_elementor_manage_global_colors(array $input) {
    $colors = $input['colors'] ?? null;
    if (!is_array($colors) || $colors === []) { return wpultra_err('bad_colors', 'colors must be a non-empty array of {title,color}.'); }
    $target = (string) ($input['target'] ?? 'custom');
    if (!in_array($target, ['custom', 'system'], true)) { $target = 'custom'; }
    return wpultra_el_set_global_colors($colors, $target);
}
```

`elementor-manage-variables.php` (SLUG `elementor-manage-variables`, input `{action:enum[list,create](req), type:string, label:string, value:string}`, output `{success,...}`):
```php
function wpultra_elementor_manage_variables(array $input) {
    $action = (string) ($input['action'] ?? '');
    if ($action === 'list') {
        $list = wpultra_el_variables_list();
        return is_wp_error($list) ? $list : wpultra_ok(['variables' => $list]);
    }
    if ($action === 'create') {
        $type = (string) ($input['type'] ?? '');
        $label = (string) ($input['label'] ?? '');
        if ($label === '') { return wpultra_err('missing_label', 'label is required to create a variable.'); }
        return wpultra_el_variables_create($type, $label, $input['value'] ?? '');
    }
    return wpultra_err('bad_action', "action must be 'list' or 'create'.");
}
```

- [ ] **Step 3: Lint + bootstrap test (36) + full suite + deploy.**

- [ ] **Step 4: Commit**

```bash
git add wp-ultra-mcp/includes/abilities/elementor-manage-global-colors.php wp-ultra-mcp/includes/abilities/elementor-manage-variables.php wp-ultra-mcp/includes/bootstrap-mcp.php tests/bootstrap.test.php
git commit -m "feat(plugin): Elementor design write abilities (manage-global-colors, manage-variables)"
```

---

### Task 4: Skill update + docs + version + live test + push

**Files:**
- Modify: `wp-ultra-mcp/includes/skills/built-in/elementor-v4-architect.md` (append a Design systems section), `README.md`, `wp-ultra-mcp/wp-ultra-mcp.php` (0.2.0 → 0.3.0), `wp-ultra-mcp/readme.txt`

- [ ] **Step 1: Append to the architect skill** a "## Design systems" section:
```markdown

## Design systems (site-wide)
- `wpultra/elementor-get-design-system` — read the kit's global colors, typography, and variables.
- `wpultra/elementor-manage-global-colors` — set/add brand colors (e.g. `{colors:[{title:"Brand",color:"#0055FF"}], target:"custom"}`). They become `--e-global-color-<id>` CSS vars site-wide.
- `wpultra/elementor-manage-variables` — list/create v4 design-token variables (color/font/size). Reference one in a widget/style prop as `{ "$$type":"global-color-variable", "value":"e-gv-<id>" }`.
- `wpultra/elementor-list-dynamic-tags` — list available dynamic tags. Bind a prop to data with `{ "$$type":"dynamic", "value":{ "name":"post-title", "group":"post", "settings":{} } }` (ACF/JetEngine tags appear here when those plugins are installed).
```

- [ ] **Step 2: Version + docs** — `wp-ultra-mcp.php` Version + `WPULTRA_VERSION` → `0.3.0`; `readme.txt` Stable tag → 0.3.0 + a `= 0.3.0 =` changelog line ("Wave 3 — Elementor design systems: global colors/typography, design-token variables, dynamic-tag discovery (4 abilities)."); in `README.md` add a "Wave 3 — Elementor design systems (shipped)" bullet group under the shipped section listing the 4 abilities + note global-classes/interactions are Wave 3.5.

- [ ] **Step 3: Full suite + lint-all + deploy.**

- [ ] **Step 4: Live test (site running)** — via a wp-load script or MCP:
  1. `elementor-get-design-system` → confirm it returns the kit's system colors (primary/secondary/text/accent) + variables.active.
  2. `elementor-manage-global-colors` with `{colors:[{title:"WP Ultra Brand", color:"#6d4afe"}], target:"custom"}` → then re-read design-system → confirm the custom color appears; confirm `--e-global-color-<id>:#6d4afe` is emitted in the site's global CSS (fetch the kit CSS or a page and grep).
  3. If `variables.active`, `elementor-manage-variables {action:"create", type:"global-color-variable", label:"BrandRed", value:"#ff0000"}` → re-list → confirm it appears.
  4. `elementor-list-dynamic-tags` → confirm core tags returned.
  Document results.

- [ ] **Step 5: Commit + push + release**

```bash
git add wp-ultra-mcp/includes/skills/built-in/elementor-v4-architect.md README.md wp-ultra-mcp/wp-ultra-mcp.php wp-ultra-mcp/readme.txt
git commit -m "feat(plugin): Wave 3 Elementor design systems — skill, v0.3.0, docs"
```
Then (controller): merge to `main`, push, build zip, `gh release create v0.3.0`.

---

## Self-Review

**Spec coverage (§2.5):** global styles v3 (kit colors+typography) → Task 1/3; variables → Task 1/3; dynamic tags (list + binding shape) → Task 1/2 + skill. **Global classes (`e_classes`) and interactions (`e_interactions`) are deferred to Wave 3.5** (often-off / complex experiments) — explicitly out of scope, noted in README.

**Placeholder scan:** engine has complete code + the two pure helpers unit-tested; abilities have complete callbacks; live test has concrete steps. No TBD.

**Type/name consistency:** `wpultra_el_*` design names consistent engine→abilities. `wpultra_el_set_global_colors($colors,$target)`, `wpultra_el_variables_list/create`, `wpultra_el_list_dynamic_tags` consumed by Task 2/3 abilities. `wpultra_ability_files()` grows 32→34 (T2)→36 (T3); bootstrap test updated each step. `design` added to the engine require list (T1).

**API grounding:** every Elementor call (kits_manager->get_active_kit/get_settings/update_settings, Variables_Service, dynamic_tags->get_tags_config, files_manager->clear_cache, experiments->is_feature_active) is from the verified 4.1.4 research and guarded with class_exists/try-catch so the plugin never fatals when Elementor or an experiment is absent.
