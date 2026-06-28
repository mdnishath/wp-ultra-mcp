# WP-Ultra-MCP — Wave 3.5 (Global Classes + Interactions) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`).
>
> Completes the Elementor design-systems pillar (program §2.5). Grounded in the verified Elementor 4.1.4 source research. Builds on shipped Waves 1/1.5/2/3.

**Goal:** MCP abilities for Elementor v4 **global classes** (reusable style classes — define once, apply to many elements) and **interactions** (entrance/scroll animations on elements).

**Architecture:** A `includes/elementor/classes.php` engine wraps `Global_Classes_Repository` (gated on `e_classes`) for list/upsert/delete, applies a class to an element by editing its `classes` prop (reusing the Wave 2 tree ops + engine), and writes an element's `interactions` field (gated on `e_interactions`). Thin abilities expose them. Live-tested against the Local site.

**Tech Stack:** Same as prior waves. **Global classes need the `e_classes` experiment** (often OFF on existing sites — abilities self-gate and the engine can enable it on request). **Interactions need `e_interactions`** (active by default).

## Global Constraints

- All prior constraints apply (file headers; array-or-WP_Error; `wpultra_permission_callback`; canonical registration shape; category `elementor`; deploy after every commit; bundled PHP `$PHP`; test site).
- **Verified Elementor 4.1.4 API (use exactly these):**
  - Active kit: `\Elementor\Plugin::$instance->kits_manager->get_active_kit()`.
  - Global classes (gate `e_classes`): repo `\Elementor\Modules\GlobalClasses\Global_Classes_Repository::make($kit)`. Read: `$repo->all()` → `Global_Classes` object; `->get_items()->all()` → `[id => entry]`; `->get_order()->all()` → `[id,...]`. Write (bulk replace): `$repo->put(array $items, array $order)` where `$items` is `[id => {id,label,type:'class',variants:[...]}]`. Class entry variant: `{meta:{state:null|'hover'|..., breakpoint:null|'desktop'|...}, props:{cssProp:{$$type,value}}}`. Class id format `e-gc-<rand>`. CSS-prop values use the same `{$$type,value}` style form as Wave 2/3 (validated by `Style_Schema`).
  - A widget references global classes via its `classes` prop: `{$$type:'classes', value:['e-gc-id1','e-gc-id2']}`.
  - Experiment toggle: `\Elementor\Plugin::$instance->experiments->is_feature_active('e_classes')`; to enable programmatically: `\Elementor\Plugin::$instance->experiments->set_feature_default_state('e_classes', \Elementor\Core\Experiments\Manager::STATE_ACTIVE)` is NOT persistent — instead persist via `update_option('elementor_experiment-e_classes', \Elementor\Core\Experiments\Manager::STATE_ACTIVE)`.
  - Interactions (gate `e_interactions`): on an atomic element node in `_elementor_data`, set the top-level `interactions` field to a JSON-ENCODED string: `wp_json_encode(['version'=>1,'items'=>[ ['$$type'=>'interaction-item','value'=>[ 'interaction_id'=>['$$type'=>'string','value'=>$id], 'trigger'=>['$$type'=>'string','value'=>'scrollIn'], 'animation'=>['$$type'=>'animation-preset-props','value'=>[ 'effect'=>['$$type'=>'string','value'=>'fade'], 'type'=>['$$type'=>'string','value'=>'in'], 'direction'=>['$$type'=>'string','value'=>''], 'timing_config'=>['$$type'=>'timing-config','value'=>[ 'duration'=>['$$type'=>'size','value'=>['size'=>600,'unit'=>'ms']], 'delay'=>['$$type'=>'size','value'=>['size'=>0,'unit'=>'ms']] ]] ]], 'breakpoints'=>['$$type'=>'interaction-breakpoints','value'=>['excluded'=>['$$type'=>'excluded-breakpoints','value'=>[]]]] ]] ]])`. Base triggers: `load`, `scrollIn`. Base effects: `fade`, `slide`, `scale`. Types: `in`, `out`. Max 5 items/element.

## File Structure

```
wp-ultra-mcp/includes/
  elementor/classes.php   global-classes + interactions engine     — Task 1
  abilities/
    elementor-list-global-classes.php  elementor-upsert-global-class.php
    elementor-apply-class.php  elementor-set-interaction.php        — Task 2
tests/elementor-classes.test.php   (pure helpers)                   — Task 1
```

Modify `includes/bootstrap-mcp.php` (engine require list += `classes`; `wpultra_ability_files()` += 4 → 40).

---

### Task 1: Global-classes + interactions engine (unit-tested helpers)

**Files:**
- Create: `wp-ultra-mcp/includes/elementor/classes.php`, `tests/elementor-classes.test.php`
- Modify: `includes/bootstrap-mcp.php` (add `'classes'` to the elementor engine require list)

**Interfaces:**
- Produces:
  - `wpultra_el_gc_id(): string` — `'e-gc-' . <7 hex>`. Pure.
  - `wpultra_el_fade_interaction(string $trigger, string $effect, string $type, int $duration): array` — build the interactions array (version+items) for one preset. Pure (no Elementor needed).
  - `wpultra_el_classes_active(): bool`, `wpultra_el_interactions_active(): bool` — experiment gates (try/catch).
  - `wpultra_el_classes_enable(): array|WP_Error` — persist `elementor_experiment-e_classes` = active; returns the new status.
  - `wpultra_el_gc_repo()` — the repository or null.
  - `wpultra_el_gc_list(): array|WP_Error` — `[{id,label}]` ordered.
  - `wpultra_el_gc_upsert(string $label, array $props, ?string $id): array|WP_Error` — create/replace a class with one default-variant of `$props` (already `{$$type}`-wrapped css props); returns `{id,label}`.
  - `wpultra_el_apply_class(int $post_id, string $element_id, string $class_id, bool $remove): array|WP_Error` — add/remove a class id in the element's `classes` prop (`{$$type:'classes', value:[...]}`), then write (reuses tree + engine).
  - `wpultra_el_set_interaction(int $post_id, string $element_id, array $interactions): array|WP_Error` — set the element node's `interactions` field (JSON string) and write.

- [ ] **Step 1: Write the failing test** `tests/elementor-classes.test.php`

```php
<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/elementor/classes.php';

it('global-class id format', function () {
    assert_true((bool) preg_match('/^e-gc-[a-f0-9]{7}$/', wpultra_el_gc_id()), 'format');
});
it('builds a fade interaction structure', function () {
    $i = wpultra_el_fade_interaction('scrollIn', 'fade', 'in', 600);
    assert_eq(1, $i['version']);
    assert_eq(1, count($i['items']));
    $v = $i['items'][0]['value'];
    assert_eq('interaction-item', $i['items'][0]['$$type']);
    assert_eq('scrollIn', $v['trigger']['value']);
    assert_eq('fade', $v['animation']['value']['effect']['value']);
    assert_eq(600, $v['animation']['value']['timing_config']['value']['duration']['value']['size']);
});

run_tests();
```

- [ ] **Step 2: Run to verify failure**

Run: `& $PHP E:\wp-connector\tests\elementor-classes.test.php` → FAIL.

- [ ] **Step 3: Write `includes/elementor/classes.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

function wpultra_el_gc_id(): string {
    return 'e-gc-' . bin2hex(random_bytes(4))[0] . substr(bin2hex(random_bytes(4)), 0, 6);
}

function wpultra_el_fade_interaction(string $trigger, string $effect, string $type, int $duration): array {
    return [
        'version' => 1,
        'items' => [[
            '$$type' => 'interaction-item',
            'value' => [
                'interaction_id' => ['$$type' => 'string', 'value' => 'temp-' . bin2hex(random_bytes(4))],
                'trigger' => ['$$type' => 'string', 'value' => $trigger],
                'animation' => ['$$type' => 'animation-preset-props', 'value' => [
                    'effect' => ['$$type' => 'string', 'value' => $effect],
                    'type' => ['$$type' => 'string', 'value' => $type],
                    'direction' => ['$$type' => 'string', 'value' => ''],
                    'timing_config' => ['$$type' => 'timing-config', 'value' => [
                        'duration' => ['$$type' => 'size', 'value' => ['size' => $duration, 'unit' => 'ms']],
                        'delay' => ['$$type' => 'size', 'value' => ['size' => 0, 'unit' => 'ms']],
                    ]],
                ]],
                'breakpoints' => ['$$type' => 'interaction-breakpoints', 'value' => [
                    'excluded' => ['$$type' => 'excluded-breakpoints', 'value' => []],
                ]],
            ],
        ]],
    ];
}

function wpultra_el_classes_active(): bool {
    if (!class_exists('\\Elementor\\Plugin')) { return false; }
    try { return \Elementor\Plugin::$instance->experiments->is_feature_active('e_classes'); } catch (\Throwable $e) { return false; }
}

function wpultra_el_interactions_active(): bool {
    if (!class_exists('\\Elementor\\Plugin')) { return false; }
    try { return \Elementor\Plugin::$instance->experiments->is_feature_active('e_interactions'); } catch (\Throwable $e) { return false; }
}

function wpultra_el_classes_enable() {
    if (!class_exists('\\Elementor\\Core\\Experiments\\Manager')) { return wpultra_err('elementor_missing', 'Elementor is not active.'); }
    update_option('elementor_experiment-e_classes', \Elementor\Core\Experiments\Manager::STATE_ACTIVE);
    return wpultra_ok(['e_classes' => wpultra_el_classes_active(), 'note' => 'Reload may be required for the change to take full effect.']);
}

function wpultra_el_gc_repo() {
    if (!wpultra_el_classes_active() || !class_exists('\\Elementor\\Modules\\GlobalClasses\\Global_Classes_Repository')) { return null; }
    $kit = (class_exists('\\Elementor\\Plugin')) ? \Elementor\Plugin::$instance->kits_manager->get_active_kit() : null;
    if (!$kit) { return null; }
    try { return \Elementor\Modules\GlobalClasses\Global_Classes_Repository::make($kit); } catch (\Throwable $e) { return null; }
}

function wpultra_el_gc_list() {
    if (!wpultra_el_classes_active()) { return wpultra_err('classes_inactive', 'The Elementor "e_classes" experiment is not active. Call elementor-upsert-global-class with enable=true, or enable it in Elementor > Settings > Features.'); }
    $repo = wpultra_el_gc_repo();
    if (!$repo) { return wpultra_err('classes_unavailable', 'Could not load the Global Classes repository.'); }
    try {
        $all = $repo->all();
        $items = $all->get_items()->all();
        $order = $all->get_order()->all();
        $out = [];
        foreach ($order as $id) {
            if (isset($items[$id])) { $out[] = ['id' => $id, 'label' => $items[$id]['label'] ?? $id]; }
        }
        return $out;
    } catch (\Throwable $e) { return wpultra_err('classes_error', $e->getMessage()); }
}

function wpultra_el_gc_upsert(string $label, array $props, ?string $id = null) {
    if (!wpultra_el_classes_active()) { return wpultra_err('classes_inactive', 'The Elementor "e_classes" experiment is not active.'); }
    $repo = wpultra_el_gc_repo();
    if (!$repo) { return wpultra_err('classes_unavailable', 'Could not load the Global Classes repository.'); }
    try {
        $all = $repo->all();
        $items = $all->get_items()->all();
        $order = $all->get_order()->all();
        $cid = $id ?: wpultra_el_gc_id();
        $items[$cid] = [
            'id' => $cid,
            'label' => $label !== '' ? $label : $cid,
            'type' => 'class',
            'variants' => [[
                'meta' => ['state' => null, 'breakpoint' => null],
                'props' => $props,
            ]],
        ];
        if (!in_array($cid, $order, true)) { $order[] = $cid; }
        $repo->put($items, $order);
        return wpultra_ok(['id' => $cid, 'label' => $items[$cid]['label']]);
    } catch (\Throwable $e) { return wpultra_err('classes_upsert_failed', $e->getMessage()); }
}

function wpultra_el_apply_class(int $post_id, string $element_id, string $class_id, bool $remove = false) {
    $data = wpultra_el_raw($post_id);
    $node = wpultra_el_find($data, $element_id);
    if ($node === null) { return wpultra_err('element_not_found', "No element '$element_id'."); }
    $cur = [];
    if (isset($node['settings']['classes']['value']) && is_array($node['settings']['classes']['value'])) {
        $cur = $node['settings']['classes']['value'];
    }
    if ($remove) { $cur = array_values(array_filter($cur, fn($c) => $c !== $class_id)); }
    elseif (!in_array($class_id, $cur, true)) { $cur[] = $class_id; }
    $merged = wpultra_el_merge_settings($data, $element_id, ['classes' => ['$$type' => 'classes', 'value' => $cur]], false);
    if (is_wp_error($merged)) { return $merged; }
    $w = wpultra_el_write($post_id, $merged);
    if (is_wp_error($w)) { return $w; }
    return wpultra_ok(['post_id' => $post_id, 'element_id' => $element_id, 'classes' => $cur]);
}

function wpultra_el_set_interaction(int $post_id, string $element_id, array $interactions) {
    if (!wpultra_el_interactions_active()) { return wpultra_err('interactions_inactive', 'The Elementor "e_interactions" experiment is not active.'); }
    $data = wpultra_el_raw($post_id);
    $done = wpultra_el_walk($data, $element_id, function (&$node) use ($interactions) {
        $node['interactions'] = wp_json_encode($interactions);
    });
    if (!$done) { return wpultra_err('element_not_found', "No element '$element_id'."); }
    $w = wpultra_el_write($post_id, $data);
    if (is_wp_error($w)) { return $w; }
    return wpultra_ok(['post_id' => $post_id, 'element_id' => $element_id]);
}
```

- [ ] **Step 4: Wire bootstrap** — in `wpultra_load_abilities()`, add `'classes'` to the elementor engine require list (`['setup','schema','tree','engine','coerce','design','classes']`).

- [ ] **Step 5: Run test + lint + full suite** — `elementor-classes.test.php` (2 pass), lint, `run-all.ps1` ALL PASS.

- [ ] **Step 6: Commit**

```bash
git add wp-ultra-mcp/includes/elementor/classes.php wp-ultra-mcp/includes/bootstrap-mcp.php tests/elementor-classes.test.php
git commit -m "feat(plugin): Elementor global-classes + interactions engine"
```

---

### Task 2: Abilities (list/upsert global-class, apply-class, set-interaction)

**Files:**
- Create: `wp-ultra-mcp/includes/abilities/elementor-list-global-classes.php`, `elementor-upsert-global-class.php`, `elementor-apply-class.php`, `elementor-set-interaction.php`
- Modify: `includes/bootstrap-mcp.php` (`wpultra_ability_files()` += 4 → 40; bootstrap test → 40)

- [ ] **Step 1: Wire + test count** — append the 4 slugs (36 → 40). Update `tests/bootstrap.test.php` count → 40 + assert `in_array('elementor-upsert-global-class', $files, true)`.

- [ ] **Step 2: Write the 4 ability files** (canonical skeleton; category `elementor`):

`elementor-list-global-classes.php` (readonly):
```php
function wpultra_elementor_list_global_classes(array $input) {
    $list = wpultra_el_gc_list();
    return is_wp_error($list) ? $list : wpultra_ok(['global_classes' => $list]);
}
```

`elementor-upsert-global-class.php` (input `{label:string(req), props:object(req), id:string, enable:boolean}`, destructive). `props` are already-wrapped css props (e.g. `{"color":{"$$type":"color","value":"#ff0000"}}`):
```php
function wpultra_elementor_upsert_global_class(array $input) {
    if (($input['enable'] ?? false) === true && !wpultra_el_classes_active()) {
        $en = wpultra_el_classes_enable();
        if (is_wp_error($en)) { return $en; }
    }
    $label = (string) ($input['label'] ?? '');
    $props = (array) ($input['props'] ?? []);
    if ($props === []) { return wpultra_err('missing_props', 'props (a map of css-prop => {$$type,value}) is required.'); }
    return wpultra_el_gc_upsert($label, $props, isset($input['id']) ? (string) $input['id'] : null);
}
```

`elementor-apply-class.php` (input `{post_id:int(req), element_id:string(req), class_id:string(req), remove:boolean}`, destructive):
```php
function wpultra_elementor_apply_class(array $input) {
    $post_id = (int) ($input['post_id'] ?? 0);
    $eid = (string) ($input['element_id'] ?? '');
    $cid = (string) ($input['class_id'] ?? '');
    if ($post_id <= 0 || $eid === '' || $cid === '') { return wpultra_err('bad_input', 'post_id, element_id, class_id are required.'); }
    return wpultra_el_apply_class($post_id, $eid, $cid, ($input['remove'] ?? false) === true);
}
```

`elementor-set-interaction.php` (input `{post_id:int(req), element_id:string(req), trigger:enum[load,scrollIn], effect:enum[fade,slide,scale], type:enum[in,out], duration:int}`, destructive):
```php
function wpultra_elementor_set_interaction(array $input) {
    $post_id = (int) ($input['post_id'] ?? 0);
    $eid = (string) ($input['element_id'] ?? '');
    if ($post_id <= 0 || $eid === '') { return wpultra_err('bad_input', 'post_id and element_id are required.'); }
    $trigger = in_array(($input['trigger'] ?? ''), ['load', 'scrollIn'], true) ? $input['trigger'] : 'scrollIn';
    $effect = in_array(($input['effect'] ?? ''), ['fade', 'slide', 'scale'], true) ? $input['effect'] : 'fade';
    $type = in_array(($input['type'] ?? ''), ['in', 'out'], true) ? $input['type'] : 'in';
    $duration = max(0, (int) ($input['duration'] ?? 600));
    $interactions = wpultra_el_fade_interaction((string) $trigger, (string) $effect, (string) $type, $duration);
    return wpultra_el_set_interaction($post_id, $eid, $interactions);
}
```

- [ ] **Step 3: Lint + bootstrap test (40) + full suite + deploy.**

- [ ] **Step 4: Commit**

```bash
git add wp-ultra-mcp/includes/abilities/elementor-list-global-classes.php wp-ultra-mcp/includes/abilities/elementor-upsert-global-class.php wp-ultra-mcp/includes/abilities/elementor-apply-class.php wp-ultra-mcp/includes/abilities/elementor-set-interaction.php wp-ultra-mcp/includes/bootstrap-mcp.php tests/bootstrap.test.php
git commit -m "feat(plugin): Elementor abilities (global classes list/upsert/apply, set-interaction)"
```

---

### Task 3: Skill + docs + version + live test + push

- [ ] **Step 1: Append to the architect skill** `includes/skills/built-in/elementor-v4-architect.md` a "## Reusable classes & animations" section:
```markdown

## Reusable classes & animations
- `wpultra/elementor-upsert-global-class` — create a reusable style class (pass `enable:true` once if the `e_classes` experiment is off). `props` are wrapped css props, e.g. `{ "color":{"$$type":"color","value":"#fff"}, "background":{"$$type":"background","value":{...}} }`. Returns an `e-gc-…` id.
- `wpultra/elementor-list-global-classes` — list existing classes.
- `wpultra/elementor-apply-class` — add/remove a class id on an element (`{post_id, element_id, class_id}`; `remove:true` to detach).
- `wpultra/elementor-set-interaction` — add an entrance animation to an element (`{post_id, element_id, trigger:"scrollIn", effect:"fade"|"slide"|"scale", type:"in", duration:600}`).
```

- [ ] **Step 2: Version + docs** — `wp-ultra-mcp.php` Version + `WPULTRA_VERSION` → `0.3.1`; `readme.txt` Stable tag → 0.3.1 + a `= 0.3.1 =` changelog line ("Wave 3.5 — Elementor global classes (list/upsert/apply) + element interactions (entrance animations); 4 abilities."); `README.md` move global classes + interactions from "Wave 3.5 planned" to shipped (list the 4 abilities).

- [ ] **Step 3: Full suite + lint-all + deploy.**

- [ ] **Step 4: Live test (site running)** — via a wp-load script (authed as admin, like the Wave 3 test):
  1. `wpultra_el_classes_enable()` then confirm `wpultra_el_classes_active()` true.
  2. `wpultra_el_gc_upsert('AI Card', ['color'=>['$$type'=>'color','value'=>'#6d4afe']])` → get the class id; `wpultra_el_gc_list()` → confirm it appears.
  3. On the Wave 2 demo page (or a fresh atomic heading), `wpultra_el_apply_class($pid,$headingId,$classId)` → re-read content → confirm the `classes` prop contains the id.
  4. `wpultra_el_set_interaction($pid,$headingId, wpultra_el_fade_interaction('scrollIn','fade','in',600))` → re-read raw → confirm the element node has an `interactions` string. Fetch the front-end page and confirm `data-interaction` / interactions JSON is present.
  Document results; if a shape is rejected, capture and fix (like Wave 2/3).

- [ ] **Step 5: Commit + push + release** — commit; then (controller) merge to `main`, push, build zip, `gh release create v0.3.1`.

---

## Self-Review

**Spec coverage (§2.5 remainder):** global classes (list/upsert/apply) → T1/T2; interactions (entrance animations) → T1/T2. Completes the deferred Wave 3.5 items.

**Placeholder scan:** engine has complete code + 2 pure helpers unit-tested; abilities complete; live test concrete. No TBD.

**Type/name consistency:** `wpultra_el_gc_*`, `wpultra_el_apply_class`, `wpultra_el_set_interaction`, `wpultra_el_fade_interaction` consistent engine→abilities. Reuses Wave 2 `wpultra_el_raw/find/walk/merge_settings/write`. `wpultra_ability_files()` 36→40; bootstrap test updated. `classes` added to engine require list.

**Risk note:** the interactions prop shape is the most complex/uncertain part (validated by Elementor's `Interactions_Schema` only on the editor save path). The live test (T3 Step 4) verifies it; if the exact wrapping is rejected, the fix is isolated to `wpultra_el_fade_interaction`. Global classes `put()` writes via the repository (authed context, like Wave 3's kit write) — wrapped in try/catch returning WP_Error.
