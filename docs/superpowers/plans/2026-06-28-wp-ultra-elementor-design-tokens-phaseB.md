# WP-Ultra-MCP — Elementor Design Tokens (Phase B) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> Phase B of Elementor Design Reliability (spec: `docs/superpowers/specs/2026-06-28-wp-ultra-elementor-design-tokens-phaseB.md`). Ships as v0.7.0. Builds on the shipped v0.6.1 plugin.

**Goal:** One `elementor-apply-design-tokens` MCP ability that takes a client-perceived design brief (colors, fonts, sizes) and creates Elementor **Variables** for them, returning `{$$type,value}` refs the AI drops into Phase A-validated atomic builds.

**Architecture:** A pure brief→plan mapper plus two thin Elementor-bound pieces in `includes/elementor/design.php` (an `e_variables` experiment auto-enabler and reuse of the existing `wpultra_el_variables_create`), orchestrated by one new ability. No server-side scraping, no new kit writer — tokens are atomic-native Elementor Variables.

**Tech Stack:** PHP 8.0+, WP 6.6+ (target WP 7.0), Elementor 4.1.4 atomic widgets + Variables module (`e_variables` experiment), vendored mcp-adapter, WordPress Abilities API. No new dependencies.

## Global Constraints

- Every PHP file starts with `<?php` + `declare(strict_types=1);` + `if (!defined('ABSPATH')) { exit(); }`. Engine functions return an array on success or `WP_Error`; abilities return `wpultra_ok([...])` or `wpultra_err($code,$message,$data='')`.
- **Ability registration MUST match the codebase shape** — see `includes/abilities/elementor-manage-global-colors.php`. `wp_register_ability('wpultra/<slug>', [...])` with `label`/`description` wrapped in `__()`, `category => 'elementor'`, `input_schema`, `output_schema`, `execute_callback` (string name of a named function in the same file — NOT a closure), `permission_callback => 'wpultra_permission_callback'`, and the mandatory `meta` block with `mcp => ['public'=>true,'type'=>'tool']`. `properties` MUST be a plain array, never `(object)` cast.
- This is a **mutating** ability: `annotations` readonly=>false, destructive=>true, idempotent=>false; it MUST call `wpultra_audit_log($action,$summary,$ok)` after writing.
- The `elementor` category is already registered — do NOT re-add it.
- **Bootstrap wiring:** the new slug goes in BOTH `wpultra_ability_files()` (elementor design-write group) AND the `'elementor'` array in `wpultra_ability_category_map()`. `tests/bootstrap.test.php` asserts the EXACT ability count (`49` today) and that the category map covers every file exactly once — bump to `50` and keep the map in sync. `design.php`/`setup.php` are already in the Elementor engine require loop (`wpultra_load_abilities`), so new engine functions there need no new require.
- **Reuse, do not reinvent:** `wpultra_el_variables_create(string $type,string $label,$value)`, `wpultra_el_variables_active(): bool`, `wpultra_el_is_hex_color(string): bool` all already exist in the engine — call them. `wpultra_el_atomic_enable()` (setup.php, v0.6.1) is the exact pattern to mirror for the variables auto-enable.
- Bundled PHP for lint/tests: `$PHP = C:/Users/nisha/AppData/Roaming/Local/lightning-services/php-8.2.30+1/bin/win64/php.exe`. Test site root: `C:/Users/nisha/Local Sites/wp-connector/app/public`. Live-test token: `wpultra-test-9a88`.
- Re-run `wp-ultra-mcp/bin/deploy.ps1` after every commit (Local runs the deployed copy). Commands run from `E:\wp-connector`.
- **Test harness API** (`tests/harness.php`): `it($name, fn)`; `assert_eq($expected,$actual)` (strict `===`); `assert_true($cond,$msg='')`; `assert_wp_error($val)`; file ends `run_tests();`. `tests/run-all.ps1` auto-globs `tests/*.test.php`. Engine files reference Elementor only inside function bodies (load fine without Elementor); `require` them in tests rather than redeclaring.

## File Structure

```
wp-ultra-mcp/includes/
  elementor/
    design.php   MODIFY — add pure wpultra_el_build_token_plan (Task 1) + wpultra_el_variables_enable (Task 2)
  abilities/
    elementor-apply-design-tokens.php   NEW — the ability (Task 3)
  bootstrap-mcp.php                     MODIFY — wire the ability (Task 3)
tests/
  elementor-design-tokens.test.php      NEW — pure unit tests for the plan mapper (Task 1)
  bootstrap.test.php                    MODIFY — count 49 → 50 (Task 3)
```

Task order: 1, 2, 3, 4, 5.

---

### Task 1: Pure brief→plan mapper (`wpultra_el_build_token_plan`) — TDD

**Files:**
- Modify: `wp-ultra-mcp/includes/elementor/design.php` (append one pure function)
- Test: `tests/elementor-design-tokens.test.php`

**Interfaces:**
- Consumes: `wpultra_el_is_hex_color(string): bool` (already in design.php).
- Produces: `wpultra_el_build_token_plan(array $brief): array` → `['plan'=>array, 'errors'=>string[]]`. Each `plan` entry is `['family'=>'color'|'font'|'size', 'type'=>'global-color-variable'|'global-font-variable'|'global-size-variable', 'title'=>string, 'value'=>string]`. `value` is the hex (colors), the family (fonts), or `"{size}{unit}"` (sizes, unit defaults `px`). Invalid/empty items are skipped and described in `errors`.

- [ ] **Step 1: Write the failing test**

Create `tests/elementor-design-tokens.test.php`:

```php
<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/elementor/design.php';

it('maps colors, fonts, sizes to variable instructions', function () {
    $brief = [
        'colors' => [['role' => 'primary', 'title' => 'Brand', 'hex' => '#0a84ff']],
        'fonts'  => [['role' => 'heading', 'title' => 'Display', 'family' => 'Inter']],
        'sizes'  => [['role' => 'space-md', 'title' => 'Space M', 'size' => 16, 'unit' => 'px']],
    ];
    $r = wpultra_el_build_token_plan($brief);
    assert_eq([], $r['errors']);
    assert_eq(3, count($r['plan']));
    assert_eq(['color', 'global-color-variable', 'Brand', '#0a84ff'], [$r['plan'][0]['family'], $r['plan'][0]['type'], $r['plan'][0]['title'], $r['plan'][0]['value']]);
    assert_eq('Inter', $r['plan'][1]['value']);
    assert_eq('global-font-variable', $r['plan'][1]['type']);
    assert_eq('16px', $r['plan'][2]['value']);
    assert_eq('global-size-variable', $r['plan'][2]['type']);
});

it('defaults size unit to px and stringifies numeric size', function () {
    $r = wpultra_el_build_token_plan(['sizes' => [['title' => 'Gap', 'size' => 24]]]);
    assert_eq('24px', $r['plan'][0]['value']);
});

it('reports errors for empty title, bad hex, missing family/size — and skips them', function () {
    $brief = [
        'colors' => [['title' => '', 'hex' => '#fff'], ['title' => 'Bad', 'hex' => 'nothex']],
        'fonts'  => [['title' => 'NoFam']],
        'sizes'  => [['title' => 'NoSize']],
    ];
    $r = wpultra_el_build_token_plan($brief);
    assert_eq([], $r['plan']);
    assert_eq(4, count($r['errors']));
});

it('handles a partial brief (only fonts)', function () {
    $r = wpultra_el_build_token_plan(['fonts' => [['title' => 'Body', 'family' => 'Roboto']]]);
    assert_eq([], $r['errors']);
    assert_eq(1, count($r['plan']));
    assert_eq('font', $r['plan'][0]['family']);
});

run_tests();
```

- [ ] **Step 2: Run test to verify it fails**

Run: `& $PHP tests/elementor-design-tokens.test.php`
Expected: FAIL — `wpultra_el_build_token_plan` undefined.

- [ ] **Step 3: Write minimal implementation**

Append to `wp-ultra-mcp/includes/elementor/design.php`:

```php
/**
 * Map a client design brief to a flat list of variable-create instructions (pure, no Elementor).
 * Returns ['plan'=>[{family,type,title,value}], 'errors'=>string[]]. Invalid items are skipped + described.
 */
function wpultra_el_build_token_plan(array $brief): array {
    $map = [
        'colors' => ['family' => 'color', 'type' => 'global-color-variable'],
        'fonts'  => ['family' => 'font',  'type' => 'global-font-variable'],
        'sizes'  => ['family' => 'size',  'type' => 'global-size-variable'],
    ];
    $plan = [];
    $errors = [];
    foreach ($map as $key => $meta) {
        if (!array_key_exists($key, $brief) || $brief[$key] === null) { continue; }
        if (!is_array($brief[$key])) { $errors[] = "$key must be an array."; continue; }
        foreach ($brief[$key] as $i => $item) {
            if (!is_array($item)) { $errors[] = "$key[$i] must be an object."; continue; }
            $title = trim((string) ($item['title'] ?? ''));
            if ($title === '') { $errors[] = "$key item #$i needs a non-empty title."; continue; }
            if ($key === 'colors') {
                $hex = (string) ($item['hex'] ?? '');
                if (!wpultra_el_is_hex_color($hex)) { $errors[] = "color '$title' has invalid hex '$hex'."; continue; }
                $value = $hex;
            } elseif ($key === 'fonts') {
                $value = trim((string) ($item['family'] ?? ''));
                if ($value === '') { $errors[] = "font '$title' needs a family."; continue; }
            } else { // sizes
                if (!isset($item['size']) || !is_numeric($item['size'])) { $errors[] = "size '$title' needs a numeric size."; continue; }
                $unit = trim((string) ($item['unit'] ?? 'px'));
                if ($unit === '') { $unit = 'px'; }
                $value = (string) (0 + $item['size']) . $unit;
            }
            $plan[] = ['family' => $meta['family'], 'type' => $meta['type'], 'title' => $title, 'value' => $value];
        }
    }
    return ['plan' => $plan, 'errors' => $errors];
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `& $PHP tests/elementor-design-tokens.test.php`
Expected: `4 passed, 0 failed`.

- [ ] **Step 5: Run the full suite**

Run: `powershell -ExecutionPolicy Bypass -File tests/run-all.ps1`
Expected: `ALL TEST FILES PASSED`.

- [ ] **Step 6: Commit**

```bash
git add wp-ultra-mcp/includes/elementor/design.php tests/elementor-design-tokens.test.php
git commit -m "feat(elementor): pure design-token brief->plan mapper + tests"
```

---

### Task 2: `e_variables` experiment auto-enabler (`wpultra_el_variables_enable`)

**Files:**
- Modify: `wp-ultra-mcp/includes/elementor/design.php` (append one function)

**Interfaces:**
- Consumes: WP `update_option`/`get_option`; Elementor `Experiments\Manager` constant (guarded).
- Produces: `wpultra_el_variables_enable(): bool` — persists the `e_variables` experiment active; returns true if the option now reads active. Mirrors `wpultra_el_atomic_enable()` (setup.php). NOTE: Elementor resolves experiment state at boot, so a mid-request flip only applies on the NEXT request — callers check `wpultra_el_variables_active()` for the current request and surface an "enabled — re-run" message when still inactive (handled in Task 3).

- [ ] **Step 1: Write the implementation**

Append to `wp-ultra-mcp/includes/elementor/design.php`:

```php
/**
 * Persist the Elementor "e_variables" experiment as active (the Variables token system needs it).
 * Returns true if the option now reads active. Mirrors wpultra_el_atomic_enable(); same boot-time
 * caching caveat — a mid-request flip only takes effect on the next request.
 */
function wpultra_el_variables_enable(): bool {
    if (!function_exists('update_option')) { return false; }
    $state = class_exists('\\Elementor\\Core\\Experiments\\Manager')
        ? \Elementor\Core\Experiments\Manager::STATE_ACTIVE
        : 'active';
    try {
        update_option('elementor_experiment-e_variables', $state);
    } catch (\Throwable $e) {
        return false;
    }
    return get_option('elementor_experiment-e_variables') === $state;
}
```

- [ ] **Step 2: Lint**

Run: `& $PHP -l wp-ultra-mcp/includes/elementor/design.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Re-run the pure suite (must stay green)**

Run: `& $PHP tests/elementor-design-tokens.test.php`
Expected: `4 passed, 0 failed` (the new function is not exercised by these tests; live-verified in Task 4).

- [ ] **Step 4: Commit**

```bash
git add wp-ultra-mcp/includes/elementor/design.php
git commit -m "feat(elementor): e_variables experiment auto-enabler"
```

---

### Task 3: `elementor-apply-design-tokens` ability + bootstrap wiring

**Files:**
- Create: `wp-ultra-mcp/includes/abilities/elementor-apply-design-tokens.php`
- Modify: `wp-ultra-mcp/includes/bootstrap-mcp.php`
- Modify: `tests/bootstrap.test.php` (count 49 → 50)

**Interfaces:**
- Consumes: `wpultra_el_build_token_plan` (Task 1), `wpultra_el_variables_enable` (Task 2), `wpultra_el_variables_active`, `wpultra_el_variables_create` (design.php), `wpultra_ok`/`wpultra_err`/`wpultra_audit_log` (helpers).
- Produces: ability `wpultra/elementor-apply-design-tokens`.

- [ ] **Step 1: Write `elementor-apply-design-tokens.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/elementor-apply-design-tokens', [
    'label'       => __('Elementor: Apply Design Tokens', 'wp-ultra-mcp'),
    'description' => __('Create Elementor Variables (color/font/size) from a perceived reference\'s palette, fonts, and sizes, and return refs to use in atomic settings as {"$$type","value"}.', 'wp-ultra-mcp'),
    'category'    => 'elementor',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'colors' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'properties' => ['role' => ['type' => 'string'], 'title' => ['type' => 'string'], 'hex' => ['type' => 'string']],
                'required' => ['title', 'hex'], 'additionalProperties' => false,
            ]],
            'fonts' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'properties' => ['role' => ['type' => 'string'], 'title' => ['type' => 'string'], 'family' => ['type' => 'string']],
                'required' => ['title', 'family'], 'additionalProperties' => false,
            ]],
            'sizes' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'properties' => ['role' => ['type' => 'string'], 'title' => ['type' => 'string'], 'size' => ['type' => 'number'], 'unit' => ['type' => 'string']],
                'required' => ['title', 'size'], 'additionalProperties' => false,
            ]],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'colors'  => ['type' => 'array'],
            'fonts'   => ['type' => 'array'],
            'sizes'   => ['type' => 'array'],
            'notes'   => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_elementor_apply_design_tokens',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_elementor_apply_design_tokens(array $input) {
    $brief = [
        'colors' => $input['colors'] ?? null,
        'fonts'  => $input['fonts'] ?? null,
        'sizes'  => $input['sizes'] ?? null,
    ];
    if ($brief['colors'] === null && $brief['fonts'] === null && $brief['sizes'] === null) {
        return wpultra_err('empty_brief', 'Provide at least one of colors, fonts, or sizes.');
    }
    $built = wpultra_el_build_token_plan($brief);
    if (!empty($built['errors'])) {
        return wpultra_err('bad_brief', 'Token brief has errors: ' . implode('; ', $built['errors']), ['errors' => $built['errors']]);
    }
    if ($built['plan'] === []) { return wpultra_err('empty_brief', 'No valid tokens to create.'); }

    if (!wpultra_el_variables_active()) {
        $persisted = wpultra_el_variables_enable();
        if (!wpultra_el_variables_active()) {
            return $persisted
                ? wpultra_err('variables_enabling', 'Elementor "e_variables" experiment has just been enabled for you — re-run this action (Elementor applies the change on the next request).')
                : wpultra_err('variables_inactive', 'Elementor "e_variables" experiment is not active and could not be auto-enabled. Enable it in Elementor > Settings > Features.');
        }
    }

    $famKey = ['color' => 'colors', 'font' => 'fonts', 'size' => 'sizes'];
    $out = ['colors' => [], 'fonts' => [], 'sizes' => []];
    $notes = [];
    foreach ($built['plan'] as $ins) {
        $res = wpultra_el_variables_create($ins['type'], $ins['title'], $ins['value']);
        if (is_wp_error($res)) { $notes[] = $ins['title'] . ': ' . $res->get_error_message(); continue; }
        $var = is_array($res) ? ($res['variable'] ?? $res) : $res;
        $id = is_array($var) ? (string) ($var['id'] ?? ($var['_id'] ?? '')) : '';
        $out[$famKey[$ins['family']]][] = ['title' => $ins['title'], 'id' => $id, 'ref' => ['$$type' => $ins['type'], 'value' => $id]];
    }
    $payload = ['colors' => $out['colors'], 'fonts' => $out['fonts'], 'sizes' => $out['sizes']];
    if ($notes) { $payload['notes'] = implode(' | ', $notes); }
    wpultra_audit_log('elementor-apply-design-tokens', count($built['plan']) . ' token(s); ' . count($notes) . ' failed', $notes === []);
    return wpultra_ok($payload);
}
```

- [ ] **Step 2: Wire bootstrap**

In `wp-ultra-mcp/includes/bootstrap-mcp.php`:
1. In `wpultra_ability_files()`, the elementor design-write group line currently reads:
   ```php
       // elementor design write abilities (Wave 3, Task 3)
       'elementor-manage-global-colors', 'elementor-manage-variables',
   ```
   Append the new slug:
   ```php
       // elementor design write abilities (Wave 3, Task 3) + design tokens (Phase B)
       'elementor-manage-global-colors', 'elementor-manage-variables', 'elementor-apply-design-tokens',
   ```
2. In `wpultra_ability_category_map()`, in the `'elementor'` array, add `'elementor-apply-design-tokens'` next to `'elementor-manage-global-colors', 'elementor-manage-variables',`.

- [ ] **Step 3: Update the bootstrap test count (49 → 50)**

In `tests/bootstrap.test.php`, change the count assertion to:
```php
    assert_eq(50, count($files), 'count');
```

- [ ] **Step 4: Lint + run suite**

Run `& $PHP -l` on `elementor-apply-design-tokens.php` and `bootstrap-mcp.php` (both `No syntax errors detected`).
Run: `powershell -ExecutionPolicy Bypass -File tests/run-all.ps1`
Expected: `ALL TEST FILES PASSED` (count + category-map-covers-all assertions pass).

- [ ] **Step 5: Deploy + commit**

```bash
powershell -ExecutionPolicy Bypass -File wp-ultra-mcp/bin/deploy.ps1
git add wp-ultra-mcp/includes/abilities/elementor-apply-design-tokens.php wp-ultra-mcp/includes/bootstrap-mcp.php tests/bootstrap.test.php
git commit -m "feat(elementor): elementor-apply-design-tokens ability + wiring"
```

---

### Task 4: Live verification on the Local site

**Files:**
- Create (temporary): `C:/Users/nisha/Local Sites/wp-connector/app/public/wp-content/wpultra-tokverify.php` — deleted at the end.

**Interfaces:**
- Consumes: the ability callback + engine on the real Elementor 4.1.4 runtime.
- Produces: JSON confirming variables get created and a returned ref renders.

- [ ] **Step 1: Ensure the Local site is running**

Confirm `curl -s -o /dev/null -w "%{http_code}" http://wp-connector.local/` → `200`. Plugin deployed (Task 3 ran deploy).

- [ ] **Step 2: Write the token-gated live test script**

Create `…/wp-content/wpultra-tokverify.php`:

```php
<?php
if (($_GET['t'] ?? '') !== 'wpultra-test-9a88') { http_response_code(403); exit('forbidden'); }
require dirname(__DIR__) . '/wp-load.php';
header('Content-Type: application/json');
$admin = get_users(['role' => 'administrator', 'number' => 1]);
if ($admin) { wp_set_current_user($admin[0]->ID); }
$p = WP_PLUGIN_DIR . '/wp-ultra-mcp';
foreach (['helpers', 'elementor/setup', 'elementor/schema', 'elementor/tree', 'elementor/engine', 'elementor/coerce', 'elementor/design', 'elementor/validate'] as $f) {
    require_once "$p/includes/$f.php";
}
require_once "$p/includes/abilities/elementor-apply-design-tokens.php";

// Make sure e_variables can be evaluated; enable + (it applies next request, so call twice across requests
// is ideal, but within one request we at least persist it and report).
$out = ['variables_active_before' => function_exists('wpultra_el_variables_active') ? wpultra_el_variables_active() : null];
wpultra_el_variables_enable();
$out['variables_active_after_enable_same_request'] = wpultra_el_variables_active();

$res = wpultra_elementor_apply_design_tokens([
    'colors' => [['role' => 'primary', 'title' => 'TokBrand', 'hex' => '#0a84ff']],
    'fonts'  => [['role' => 'heading', 'title' => 'TokDisplay', 'family' => 'Inter']],
    'sizes'  => [['role' => 'space', 'title' => 'TokSpace', 'size' => 24, 'unit' => 'px']],
]);
$out['apply'] = is_wp_error($res) ? [$res->get_error_code() => $res->get_error_message()] : $res;
echo json_encode($out, JSON_PRETTY_PRINT);
```

- [ ] **Step 3: Run it (twice — variables experiment applies on the second request)**

Run: `curl -s "http://wp-connector.local/wp-content/wpultra-tokverify.php?t=wpultra-test-9a88"` then run the SAME curl again.
Expected on the second run: `variables_active_before: true`, and `apply.success: true` with `apply.colors[0].id` a non-empty string and `apply.colors[0].ref` = `{"$$type":"global-color-variable","value":"<id>"}` (likewise fonts/sizes). If the first run returns the `variables_enabling` re-run message, that is correct behavior — the second run should succeed.

- [ ] **Step 4: Confirm a returned ref renders (ties to Phase A)**

Extend the script (or add a second token-gated script) to: create a draft page, `wpultra_el_write` a single `e-heading` whose color setting uses the returned color `ref`, then call `wpultra_el_render_check($pid)` and confirm `rendered_count >= 1` and `dropped_ids` is empty. If the heading's color prop key differs, inspect `wpultra_el_widget_schema('e-heading')` for the correct color prop and use it. Delete the draft.

- [ ] **Step 5: Fix any failures**

If `apply.colors[0].id` is empty, the variable-id field name differs — inspect the array returned by `wpultra_el_variables_create('global-color-variable',...)` (dump `$res['variable']`), adjust the `$var['id'] ?? $var['_id']` extraction in the ability to the real key, re-deploy, re-run. If `variables_create` errors, confirm the `e_variables` experiment is active on this request (it applies next request after enable) and that the Variables service classes match `design.php`. Do not proceed until `apply.success` is true with non-empty ids and the render check passes.

- [ ] **Step 6: Delete the test script(s)**

Run: `rm "C:/Users/nisha/Local Sites/wp-connector/app/public/wp-content/wpultra-tokverify.php"` (and any second script created).

- [ ] **Step 7: Commit (only if engine/ability fixes were made)**

```bash
git add -A
git commit -m "fix(elementor): live-verification fixes for design-token apply"
```

---

### Task 5: Docs + version bump (no release — finishing handled separately)

**Files:**
- Modify: `wp-ultra-mcp/wp-ultra-mcp.php` (version header + `WPULTRA_VERSION`), `wp-ultra-mcp/readme.txt` (stable tag + changelog), `README.md` (Elementor section).

- [ ] **Step 1: Bump versions to 0.7.0**

Set `0.7.0` in `wp-ultra-mcp/wp-ultra-mcp.php` (the `Version:` header AND the `WPULTRA_VERSION` constant — grep for `0.6.1`) and `Stable tag: 0.7.0` in `wp-ultra-mcp/readme.txt`.

- [ ] **Step 2: Changelog + README**

Add a `= 0.7.0 =` entry to `readme.txt` (follow the existing changelog format) describing `elementor-apply-design-tokens` — create Elementor color/font/size Variables from a perceived reference and get back refs to use in atomic builds. Add a short bullet to the Elementor section of `README.md` (e.g. "Apply a reference's palette/fonts/sizes as Elementor Variables (`elementor-apply-design-tokens`) for token-consistent, reference-faithful builds").

- [ ] **Step 3: Deploy + full suite**

Run: `powershell -ExecutionPolicy Bypass -File wp-ultra-mcp/bin/deploy.ps1` then `powershell -ExecutionPolicy Bypass -File tests/run-all.ps1`.
Expected: `ALL TEST FILES PASSED`.

- [ ] **Step 4: Commit (in-branch only; do NOT merge/push/release)**

```bash
git add -A
git commit -m "docs(elementor): Phase B design tokens — apply-design-tokens, v0.7.0"
```

The merge to main, push, zip build, and `gh release create v0.7.0` happen after the final whole-branch review, via the finishing-a-development-branch skill (outward-facing — done with the user).

---

## Self-Review

**Spec coverage:**
- Client-perceives decision (no scraping) → reflected in the ability taking a brief, not a URL (Task 3) ✓ · Variables-not-kit decision → Tasks 1–3 use `global-*-variable` types via `wpultra_el_variables_create` ✓ · token-only scope (blueprints deferred) → no blueprint tasks ✓ · `elementor-apply-design-tokens` ability → Task 3 ✓ · `e_variables` auto-enable + "re-run" message → Tasks 2,3 ✓ · partial-safe (notes) → Task 3 callback ✓ · audit on write → Task 3 ✓ · pure unit + live tests → Tasks 1,4 ✓ · bootstrap wiring + count 50 → Task 3 ✓ · release v0.7.0 → Task 5 ✓.

**Placeholder scan:** No TBD/TODO. Every code step shows complete code; every command shows expected output. The two "verify live" items (variable-id field name; e-heading color prop key) name the exact thing to check with a concrete fallback, resolved in Task 4 — the established Elementor-wave workflow.

**Type/name consistency:**
- `wpultra_el_build_token_plan(array $brief): array` returns `['plan'=>[{family,type,title,value}], 'errors'=>[]]` — produced in Task 1, consumed identically in Task 3.
- `wpultra_el_variables_enable(): bool` (Task 2) consumed in Task 3 with the same active-check-then-message pattern as the v0.6.1 atomic enabler.
- Reused engine fns by exact name: `wpultra_el_variables_create`, `wpultra_el_variables_active`, `wpultra_el_is_hex_color`.
- The `ref` shape `{"$$type":<variable-type>,"value":<id>}` is consistent between the ability output (Task 3) and the spec's "how the AI uses refs".
- Count bumped exactly once (49→50, Task 3); category map kept in sync in the same edit.
