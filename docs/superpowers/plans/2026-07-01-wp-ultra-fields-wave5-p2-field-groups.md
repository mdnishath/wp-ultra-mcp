# Wave 5 Plan 2 — Custom Field Groups (list/get + per-provider define) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Let AI enumerate and define custom **field groups** across ACF, Meta Box, and Pods — unified `field-list-groups` / `field-get-group` reads, plus three native `*-define-*` writers matched to each plugin's real storage model.

**Architecture:** Extends the Plan 1 hybrid driver. Two new unified read abilities route to per-provider adapter functions (`wpultra_fields_{provider}_list_groups` / `_get_group`). Three native define abilities use each plugin's real API: ACF `acf_import_field_group`, Pods `pods_api()->save_pod/save_field`, and — for **free Meta Box, which stores no groups in the DB** — an **option-backed always-on `rwmb_meta_boxes` filter** (a refinement over the spec's "generated PHP snippet": DB-backed, revertible via option delete, no generated executable code, no jail/lint/sandbox surface — mirrors the SEO wave's option+always-on-hook pattern).

**Tech Stack:** PHP 8.2, WordPress 7.0 Abilities API, zero-dep PHP harness. Live plugins on the test site: ACF 6.8.5 (free), Meta Box 5.12.1, Pods 3.3.9.

## Global Constraints

- Ability registration MUST use keys `execute_callback`/`input_schema`/`output_schema`/`permission_callback`/`category` (never `callback`/`input`/`output`); `input_schema.properties` MUST be a plain PHP array; category `fields` is already registered. Reference: `wp-ultra-mcp/includes/abilities/read-file.php`.
- Success via `wpultra_ok(array)`, errors via `wpultra_err(code,msg)` (a `WP_Error`); permission `wpultra_permission_callback`; every mutating ability calls `wpultra_audit_log(action, summary)`.
- Every task that adds ability slug(s) MUST: add them to `wpultra_ability_files()` AND `wpultra_ability_category_map()['fields']` in `bootstrap-mcp.php`, AND bump the count assertion in `tests/bootstrap.test.php` (currently 99). Category map must equal the ability-file set (a bootstrap.test.php assertion).
- New engine file `includes/fields/groups.php` must be added to the `fields` require block in BOTH `wpultra_load_abilities()` and (for the Meta Box always-on filter) a new front-end loader — see Task 3.
- Tests live at repo-root `tests/`, globbed by `tests/run-all.ps1`; new suite files require the plugin via `../wp-ultra-mcp/includes/…`. Pure engine files must not call WordPress functions at module load.
- After EVERY commit run `wp-ultra-mcp\bin\deploy.ps1` (may print success yet exit 1 — known quirk; verify deployed file content) BEFORE any live test. Live test = token-gated probe at `…\app\public\wp-content\wpultra-*.php` (token `wpultra-test-9a88`) → `curl` → DELETE. NEVER nest a second `wp_remote_get` to the same Local site in one request.
- Bundled PHP: `C:\Users\nisha\AppData\Roaming\Local\lightning-services\php-8.2.30+1\bin\win64\php.exe`.
- Follow the Plan 1 adapter/ability conventions already in `includes/fields/` (same file style, `declare(strict_types=1)`, `if (!defined('ABSPATH')) exit();`). Subagent git identity: `user.name='wp-mcp'`, `user.email='dev@local'`. Branch: `feat/fields-wave5`.

## File Structure

```
wp-ultra-mcp/includes/fields/
  groups.php                    — NEW: pure group-shape normalizers + the Meta Box option
                                   store/register helpers (wpultra_fields_mb_groups_option,
                                   wpultra_fields_mb_register_groups) + MB list/get from option+filter
  adapters/acf.php              — APPEND: wpultra_fields_acf_list_groups / _get_group / _define_group
  adapters/metabox.php          — APPEND: wpultra_fields_metabox_list_groups / _get_group
                                   (reads option-backed + filter-registered groups)
  adapters/pods.php             — APPEND: wpultra_fields_pods_list_groups / _get_group
wp-ultra-mcp/includes/abilities/
  field-list-groups.php         — NEW unified
  field-get-group.php           — NEW unified
  acf-define-field-group.php    — NEW native
  metabox-define-field-group.php— NEW native (option-backed)
  pods-define-fields.php        — NEW native
wp-ultra-mcp/wp-ultra-mcp.php   — MODIFY: add init-priority loader for the Meta Box groups filter
wp-ultra-mcp/includes/bootstrap-mcp.php — MODIFY: load groups.php + 5 slugs + category map
tests/
  fields-groups.test.php        — NEW: pure normalizer + MB option-store unit tests
  bootstrap.test.php            — MODIFY: count 99 → 104
```

Ability count: 99 → 104 (+5).

---

### Task 1: Unified group read — `field-list-groups` + `field-get-group`

**Files:**
- Create: `wp-ultra-mcp/includes/fields/groups.php`
- Modify: `wp-ultra-mcp/includes/fields/adapters/{acf,metabox,pods}.php` (append list/get)
- Create: `wp-ultra-mcp/includes/abilities/field-list-groups.php`, `field-get-group.php`
- Create: `tests/fields-groups.test.php`
- Modify: `wp-ultra-mcp/includes/bootstrap-mcp.php`, `tests/bootstrap.test.php`

**Interfaces:**
- Produces:
  - `wpultra_fields_acf_list_groups(): array` / `wpultra_fields_metabox_list_groups(): array` / `wpultra_fields_pods_list_groups(): array` — each returns a list of `['key'=>string,'title'=>string,'provider'=>string,'field_count'=>int,'location'=>mixed]`.
  - `wpultra_fields_{acf,metabox,pods}_get_group(string $key): array|WP_Error` — full group `['key','title','provider','fields'=>[[...]],'location'=>mixed]`; `WP_Error('group_not_found')` if absent.
  - `wpultra_fields_mb_stored_groups(): array` (in groups.php) — the Meta Box groups persisted in the `wpultra_mb_groups` option (map id→config); returns `[]` if unset. Used by both the adapter and (Task 3) the filter registrar.
- Consumes: `wpultra_fields_providers()` (setup.php).

- [ ] **Step 1: Write `includes/fields/groups.php`** (pure option accessor + shape helper — safe: `get_option` is a WP call but this file is loaded only in the WP path, mirror seo/technical.php which also calls WP fns; it is NOT required by the pure test except for the option helper which the test stubs `get_option`).

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/** The persisted Meta Box groups map (id => MB group config array). */
function wpultra_fields_mb_stored_groups(): array {
    $v = get_option('wpultra_mb_groups', []);
    return is_array($v) ? $v : [];
}

/** Normalize a raw MB group config to a list entry. Pure. */
function wpultra_fields_mb_group_entry(array $g): array {
    $fields = isset($g['fields']) && is_array($g['fields']) ? $g['fields'] : [];
    return [
        'key'         => (string) ($g['id'] ?? ($g['title'] ?? '')),
        'title'       => (string) ($g['title'] ?? ($g['id'] ?? '')),
        'provider'    => 'metabox',
        'field_count' => count($fields),
        'location'    => $g['post_types'] ?? ($g['taxonomies'] ?? null),
    ];
}
```

- [ ] **Step 2: Append ACF group read to `adapters/acf.php`**

```php
/** @return array<int,array> */
function wpultra_fields_acf_list_groups(): array {
    if (!function_exists('acf_get_field_groups')) { return []; }
    $out = [];
    foreach (acf_get_field_groups() as $g) {
        $fields = function_exists('acf_get_fields') ? (acf_get_fields($g) ?: []) : [];
        $out[] = [
            'key'         => (string) ($g['key'] ?? ''),
            'title'       => (string) ($g['title'] ?? ''),
            'provider'    => 'acf',
            'field_count' => count($fields),
            'location'    => $g['location'] ?? null,
        ];
    }
    return $out;
}

/** @return array|WP_Error */
function wpultra_fields_acf_get_group(string $key) {
    if (!function_exists('acf_get_field_group')) { return new WP_Error('group_not_found', 'ACF not available'); }
    $g = acf_get_field_group($key);
    if (!$g) { return new WP_Error('group_not_found', "ACF field group not found: {$key}"); }
    $fields = function_exists('acf_get_fields') ? (acf_get_fields($g) ?: []) : [];
    $slim = [];
    foreach ($fields as $f) {
        $slim[] = ['key' => $f['key'] ?? '', 'name' => $f['name'] ?? '', 'label' => $f['label'] ?? '', 'type' => $f['type'] ?? ''];
    }
    return ['key' => $g['key'] ?? $key, 'title' => $g['title'] ?? '', 'provider' => 'acf', 'fields' => $slim, 'location' => $g['location'] ?? null];
}
```

- [ ] **Step 3: Append Meta Box group read to `adapters/metabox.php`** (reads BOTH the option store and any PHP-registered groups via the filter)

```php
/** @return array<int,array> */
function wpultra_fields_metabox_list_groups(): array {
    $out = [];
    $seen = [];
    foreach (wpultra_fields_mb_stored_groups() as $g) {
        if (!is_array($g)) { continue; }
        $entry = wpultra_fields_mb_group_entry($g);
        $seen[$entry['key']] = true;
        $out[] = $entry;
    }
    foreach ((array) apply_filters('rwmb_meta_boxes', []) as $g) {
        if (!is_array($g)) { continue; }
        $entry = wpultra_fields_mb_group_entry($g);
        if (isset($seen[$entry['key']])) { continue; } // don't double-count our own filter output
        $out[] = $entry;
    }
    return $out;
}

/** @return array|WP_Error */
function wpultra_fields_metabox_get_group(string $key) {
    foreach (wpultra_fields_mb_stored_groups() as $g) {
        if (is_array($g) && (string) ($g['id'] ?? '') === $key) {
            $fields = [];
            foreach ((array) ($g['fields'] ?? []) as $f) {
                $fields[] = ['key' => $f['id'] ?? '', 'name' => $f['id'] ?? '', 'label' => $f['name'] ?? '', 'type' => $f['type'] ?? ''];
            }
            return ['key' => $key, 'title' => $g['title'] ?? $key, 'provider' => 'metabox', 'fields' => $fields, 'location' => $g['post_types'] ?? null];
        }
    }
    foreach ((array) apply_filters('rwmb_meta_boxes', []) as $g) {
        if (is_array($g) && (string) ($g['id'] ?? ($g['title'] ?? '')) === $key) {
            $fields = [];
            foreach ((array) ($g['fields'] ?? []) as $f) {
                $fields[] = ['key' => $f['id'] ?? '', 'name' => $f['id'] ?? '', 'label' => $f['name'] ?? '', 'type' => $f['type'] ?? ''];
            }
            return ['key' => $key, 'title' => $g['title'] ?? $key, 'provider' => 'metabox', 'fields' => $fields, 'location' => $g['post_types'] ?? null];
        }
    }
    return new WP_Error('group_not_found', "Meta Box field group not found: {$key}");
}
```

- [ ] **Step 4: Append Pods group read to `adapters/pods.php`**

```php
/** @return array<int,array> */
function wpultra_fields_pods_list_groups(): array {
    if (!function_exists('pods_api')) { return []; }
    $out = [];
    $pods = pods_api()->load_pods();
    foreach ((array) $pods as $p) {
        $name = is_array($p) ? ($p['name'] ?? '') : (is_object($p) ? ($p->pod ?? ($p->name ?? '')) : '');
        if ($name === '') { continue; }
        $fields = pods_api()->load_fields(['pod' => $name]);
        $out[] = ['key' => (string) $name, 'title' => (string) $name, 'provider' => 'pods', 'field_count' => is_array($fields) ? count($fields) : 0, 'location' => is_array($p) ? ($p['type'] ?? null) : null];
    }
    return $out;
}

/** @return array|WP_Error */
function wpultra_fields_pods_get_group(string $key) {
    if (!function_exists('pods_api')) { return new WP_Error('group_not_found', 'Pods not available'); }
    $pod = pods_api()->load_pod(['name' => $key]);
    if (!$pod) { return new WP_Error('group_not_found', "Pod not found: {$key}"); }
    $fields = pods_api()->load_fields(['pod' => $key]);
    $slim = [];
    foreach ((array) $fields as $f) {
        $fn = is_array($f) ? ($f['name'] ?? '') : (is_object($f) ? ($f->name ?? '') : '');
        $ft = is_array($f) ? ($f['type'] ?? '') : (is_object($f) ? ($f->type ?? '') : '');
        $slim[] = ['key' => (string) $fn, 'name' => (string) $fn, 'label' => (string) $fn, 'type' => (string) $ft];
    }
    return ['key' => $key, 'title' => $key, 'provider' => 'pods', 'fields' => $slim, 'location' => is_array($pod) ? ($pod['type'] ?? null) : null];
}
```

- [ ] **Step 5: Write `field-list-groups` ability** (`includes/abilities/field-list-groups.php`) — loops ALL active providers (or an optional `provider` filter), never uses pick_provider (listing is non-ambiguous):

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/field-list-groups', [
    'label'       => __('List Field Groups', 'wp-ultra-mcp'),
    'description' => __('Lists custom field groups across all active field providers (ACF, Meta Box, Pods). Optionally filter to one provider. Each entry gives key, title, provider, field_count, and location binding.', 'wp-ultra-mcp'),
    'category'    => 'fields',
    'input_schema'  => [
        'type' => 'object',
        'properties' => [ 'provider' => ['type' => 'string', 'enum' => ['acf', 'metabox', 'pods']] ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => ['success' => ['type' => 'boolean'], 'groups' => ['type' => 'array']],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_field_list_groups',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

function wpultra_field_list_groups(array $input) {
    $filter = isset($input['provider']) ? (string) $input['provider'] : '';
    $groups = [];
    foreach (wpultra_fields_providers() as $p) {
        $name = $p['provider'];
        if ($filter !== '' && $filter !== $name) { continue; }
        $fn = "wpultra_fields_{$name}_list_groups";
        if (function_exists($fn)) { $groups = array_merge($groups, $fn()); }
    }
    return wpultra_ok(['groups' => $groups]);
}
```

- [ ] **Step 6: Write `field-get-group` ability** (`includes/abilities/field-get-group.php`) — requires `key`; `provider` optional (defaults to searching each active provider until found):

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/field-get-group', [
    'label'       => __('Get Field Group', 'wp-ultra-mcp'),
    'description' => __('Returns the full schema (fields with key/name/label/type + location) of one custom field group by key. Specify provider to disambiguate; otherwise each active provider is searched.', 'wp-ultra-mcp'),
    'category'    => 'fields',
    'input_schema'  => [
        'type' => 'object',
        'properties' => [
            'key'      => ['type' => 'string'],
            'provider' => ['type' => 'string', 'enum' => ['acf', 'metabox', 'pods']],
        ],
        'required' => ['key'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => ['success' => ['type' => 'boolean'], 'group' => ['type' => 'object']],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_field_get_group',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

function wpultra_field_get_group(array $input) {
    $key = (string) ($input['key'] ?? '');
    if ($key === '') { return wpultra_err('key_required', 'key is required'); }
    $only = isset($input['provider']) ? (string) $input['provider'] : '';
    foreach (wpultra_fields_providers() as $p) {
        $name = $p['provider'];
        if ($only !== '' && $only !== $name) { continue; }
        $fn = "wpultra_fields_{$name}_get_group";
        if (!function_exists($fn)) { continue; }
        $g = $fn($key);
        if (!is_wp_error($g)) { return wpultra_ok(['group' => (object) $g]); }
    }
    return wpultra_err('group_not_found', "No field group '{$key}' found in the active provider(s).");
}
```

- [ ] **Step 7: Wire bootstrap + write pure test**

In `bootstrap-mcp.php`: add `'groups'` to the `fields` require list in `wpultra_load_abilities()` (so the block becomes `['setup', 'values', 'driver', 'groups', 'adapters/acf', 'adapters/metabox', 'adapters/pods']` — **groups.php must load BEFORE the adapters** since `wpultra_fields_metabox_list_groups` calls `wpultra_fields_mb_stored_groups`/`wpultra_fields_mb_group_entry`; PHP resolves function calls at call time so order is not strictly required, but keep groups before adapters for clarity). Add slugs `'field-list-groups', 'field-get-group'` to `wpultra_ability_files()` (new `// fields (Wave 5, Plan 2)` line) and to `wpultra_ability_category_map()['fields']`.

Create `tests/fields-groups.test.php`:
```php
<?php
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
$__fails = 0;
function ok($c, $m) { global $__fails; echo ($c ? "PASS" : "FAIL") . ": $m\n"; if (!$c) { $GLOBALS['__fails']++; } }
$__opt = [];
function get_option($k, $d = false) { return $GLOBALS['__opt'][$k] ?? $d; }
require __DIR__ . '/../wp-ultra-mcp/includes/fields/groups.php';
$GLOBALS['__opt']['wpultra_mb_groups'] = ['grp1' => ['id' => 'grp1', 'title' => 'G1', 'post_types' => ['post'], 'fields' => [['id' => 'a', 'type' => 'text'], ['id' => 'b', 'type' => 'text']]]];
$stored = wpultra_fields_mb_stored_groups();
ok(isset($stored['grp1']), 'mb_stored_groups reads option');
$entry = wpultra_fields_mb_group_entry($stored['grp1']);
ok($entry['key'] === 'grp1' && $entry['field_count'] === 2 && $entry['provider'] === 'metabox', 'mb_group_entry shape correct');
$empty = wpultra_fields_mb_group_entry(['id' => 'x']);
ok($empty['field_count'] === 0, 'mb_group_entry no-fields → 0');
echo "\n" . ($__fails === 0 ? 'ALL PASS' : "$__fails FAILED") . "\n";
exit($__fails === 0 ? 0 : 1);
```
Bump `tests/bootstrap.test.php`: `assert_eq(99, ...)` → `assert_eq(101, ...)`.

- [ ] **Step 8: Lint, test, deploy, live-verify**

Lint the 3 abilities + groups.php + adapters. Run `& $PHP tests\fields-groups.test.php` → ALL PASS. Run `powershell -File tests\run-all.ps1` → ALL TEST FILES PASSED (bootstrap now 101). Deploy. Live probe: register an ACF local group + a Pods pod in the probe, then call `wpultra_field_list_groups([])` and `wpultra_field_get_group(['key'=>'<acf key>'])` through the loaded engine; assert the ACF group and its fields appear. Delete probe.

- [ ] **Step 9: Commit** — `git ... commit -m "feat(fields): field-list-groups + field-get-group unified across acf/metabox/pods (Wave 5 Plan 2)"`

---

### Task 2: `acf-define-field-group` (native ACF)

**Files:**
- Create: `wp-ultra-mcp/includes/abilities/acf-define-field-group.php`
- Modify: `wp-ultra-mcp/includes/fields/adapters/acf.php` (append `wpultra_fields_acf_define_group`)
- Modify: `bootstrap-mcp.php` (+1 slug), `tests/bootstrap.test.php` (101 → 102)

**Interfaces:**
- Produces: `wpultra_fields_acf_define_group(array $payload, string $mode): array|WP_Error` — `mode` in {create, update, delete}. Returns `['key'=>string,'id'=>int,'mode'=>string]`.

- [ ] **Step 1: Append `wpultra_fields_acf_define_group` to `adapters/acf.php`**

```php
/**
 * Create/update/delete an ACF field group from a native-export payload.
 * @return array|WP_Error
 */
function wpultra_fields_acf_define_group(array $payload, string $mode) {
    if (!function_exists('acf_import_field_group')) { return new WP_Error('acf_unavailable', 'ACF is not active'); }
    if ($mode === 'delete') {
        $key = (string) ($payload['key'] ?? '');
        if ($key === '') { return new WP_Error('key_required', 'delete requires payload.key'); }
        $g = acf_get_field_group($key);
        if (!$g) { return new WP_Error('group_not_found', "ACF group not found: {$key}"); }
        acf_delete_field_group($g['ID'] ?? $key);
        return ['key' => $key, 'id' => (int) ($g['ID'] ?? 0), 'mode' => 'delete'];
    }
    if (empty($payload['title'])) { return new WP_Error('title_required', 'payload.title is required'); }
    // Reject ACF-Pro-only field types on the free edition (they silently drop otherwise).
    $edition = (class_exists('acf_pro') || defined('ACF_PRO')) ? 'pro' : 'free';
    if ($edition === 'free') {
        $pro_types = ['repeater', 'flexible_content', 'gallery', 'clone', 'group'];
        foreach ((array) ($payload['fields'] ?? []) as $f) {
            if (in_array($f['type'] ?? '', $pro_types, true)) {
                return new WP_Error('pro_field_type', "Field type '{$f['type']}' requires ACF Pro (this site runs ACF free).");
            }
        }
    }
    if (empty($payload['key'])) { $payload['key'] = 'group_' . substr(md5($payload['title'] . wp_rand()), 0, 13); }
    if (!isset($payload['location'])) { $payload['location'] = []; }
    $result = acf_import_field_group($payload); // returns the imported group array (with ID)
    if (!is_array($result)) { return new WP_Error('acf_import_failed', 'acf_import_field_group did not return a group'); }
    return ['key' => (string) ($result['key'] ?? $payload['key']), 'id' => (int) ($result['ID'] ?? 0), 'mode' => $mode];
}
```

- [ ] **Step 2: Write the ability** (`includes/abilities/acf-define-field-group.php`) — input `{payload:object, mode:enum[create,update,delete] default create}`; calls the adapter; audit-logs; returns `wpultra_ok(result)`. Follow the Plan 1 ability shape exactly (keys, permission, meta annotations `readonly:false, destructive:true, idempotent:false`). Description: "Create/update/delete an ACF field group from a native ACF export payload (`{key?, title, fields[], location[][], ...}`). Pro-only field types (repeater/flexible_content/gallery/clone/group) are rejected on ACF free." The callback:
```php
function wpultra_acf_define_field_group(array $input) {
    $payload = (array) ($input['payload'] ?? []);
    $mode = (string) ($input['mode'] ?? 'create');
    if (!in_array($mode, ['create', 'update', 'delete'], true)) { return wpultra_err('bad_mode', 'mode must be create, update, or delete'); }
    if (!$payload) { return wpultra_err('payload_required', 'payload is required'); }
    $res = wpultra_fields_acf_define_group($payload, $mode);
    if (is_wp_error($res)) { return $res; }
    wpultra_audit_log('acf-define-field-group', "mode={$mode} key={$res['key']}");
    return wpultra_ok($res);
}
```
(Ability input_schema: `payload` type object, `mode` enum. Register with slug `wpultra/acf-define-field-group`, category `fields`.)

- [ ] **Step 3: Wire + bump** — add `'acf-define-field-group'` to `wpultra_ability_files()` + category map; bump `tests/bootstrap.test.php` 101 → 102.

- [ ] **Step 4: Lint, deploy, live-verify** — probe: call `wpultra_acf_define_field_group(['payload'=>['title'=>'WPUltra P2 ACF','fields'=>[['key'=>'field_p2','name'=>'p2sub','label'=>'Sub','type'=>'text']],'location'=>[[['param'=>'post_type','operator'=>'==','value'=>'post']]]],'mode'=>'create'])`; assert returned key; then `acf_get_field_group($key)` non-null; then delete via `mode=delete`; assert gone. Also assert a `repeater` field returns `pro_field_type` error. Run full suite. Delete probe.

- [ ] **Step 5: Commit** — `feat(fields): acf-define-field-group (native ACF export payload, pro-type guard) (Wave 5 Plan 2)`

---

### Task 3: `metabox-define-field-group` (option-backed, always-on filter)

**Files:**
- Modify: `wp-ultra-mcp/includes/fields/groups.php` (append option store/register helpers)
- Create: `wp-ultra-mcp/includes/abilities/metabox-define-field-group.php`
- Modify: `wp-ultra-mcp/wp-ultra-mcp.php` (register the always-on `rwmb_meta_boxes` filter loader)
- Modify: `bootstrap-mcp.php` (+1 slug), `tests/bootstrap.test.php` (102 → 103), `tests/fields-groups.test.php` (append store test)

**Interfaces:**
- Produces:
  - `wpultra_fields_mb_save_group(array $config, string $mode): array|WP_Error` — upsert/delete a group in the `wpultra_mb_groups` option; `mode` in {create/update, delete}. Returns `['id'=>string,'mode'=>string,'count'=>int]`.
  - `wpultra_fields_mb_register_groups(array $mb): array` — the `rwmb_meta_boxes` filter callback: appends all stored groups to `$mb`.
  - `wpultra_load_fields_frontend(): void` — the init-loader that requires the fields engine + adds the filter (loaded on every request, like `wpultra_load_seo_frontend`).

- [ ] **Step 1: Append option store + registrar to `includes/fields/groups.php`**

```php
/** Upsert or delete a Meta Box group in the persisted option. @return array|WP_Error */
function wpultra_fields_mb_save_group(array $config, string $mode) {
    $groups = wpultra_fields_mb_stored_groups();
    $id = (string) ($config['id'] ?? '');
    if ($id === '' || !preg_match('/^[a-z0-9_]+$/i', $id)) {
        return new WP_Error('id_invalid', 'config.id is required and must match [a-z0-9_]+');
    }
    if ($mode === 'delete') {
        unset($groups[$id]);
        update_option('wpultra_mb_groups', $groups, false);
        return ['id' => $id, 'mode' => 'delete', 'count' => count($groups)];
    }
    if (empty($config['title'])) { return new WP_Error('title_required', 'config.title is required'); }
    if (empty($config['fields']) || !is_array($config['fields'])) { return new WP_Error('fields_required', 'config.fields[] is required'); }
    // Minimal shape guard: each field needs id + type.
    foreach ($config['fields'] as $f) {
        if (empty($f['id']) || empty($f['type'])) { return new WP_Error('field_invalid', 'each field needs id and type'); }
    }
    if (empty($config['post_types'])) { $config['post_types'] = ['post']; }
    $groups[$id] = $config;
    update_option('wpultra_mb_groups', $groups, false);
    return ['id' => $id, 'mode' => 'upsert', 'count' => count($groups)];
}

/** rwmb_meta_boxes filter callback: register all stored groups. */
function wpultra_fields_mb_register_groups(array $mb): array {
    foreach (wpultra_fields_mb_stored_groups() as $g) {
        if (is_array($g) && !empty($g['fields'])) { $mb[] = $g; }
    }
    return $mb;
}
```

- [ ] **Step 2: Add the always-on loader to `wp-ultra-mcp.php`** — after the SEO loader line (`add_action('init', 'wpultra_load_seo_frontend', 1);`) add:
```php
// Load the fields engine on every request (front-end + admin) so the Meta Box
// rwmb_meta_boxes filter registers persisted groups; the ability engine-loop only
// runs on REST calls, so persisted MB groups need this separate always-on hook.
add_action('init', 'wpultra_load_fields_frontend', 1);
```
And define `wpultra_load_fields_frontend()` in `bootstrap-mcp.php` next to `wpultra_load_seo_frontend()`:
```php
function wpultra_load_fields_frontend(): void {
    if (!wpultra_is_enabled()) { return; }
    if (in_array('fields', wpultra_disabled_categories(), true)) { return; }
    foreach (['setup', 'values', 'driver', 'groups'] as $ff) {
        $fp = WPULTRA_DIR . 'includes/fields/' . $ff . '.php';
        if (is_readable($fp)) { require_once $fp; }
    }
    if (function_exists('wpultra_fields_mb_register_groups')) {
        add_filter('rwmb_meta_boxes', 'wpultra_fields_mb_register_groups');
    }
}
```

- [ ] **Step 3: Write the ability** (`includes/abilities/metabox-define-field-group.php`) — input `{config:object, mode:enum[create,update,delete] default create}`; maps create/update → `wpultra_fields_mb_save_group($config, 'upsert')`, delete → delete; audit-logs; `wpultra_ok`. Description: "Create/update/delete a Meta Box field group. Persisted in the `wpultra_mb_groups` option and registered on every request via the `rwmb_meta_boxes` filter (works on free Meta Box, which stores no groups in the DB). config = `{id, title, post_types[], fields:[{id,type,name?,...}]}`." Slug `wpultra/metabox-define-field-group`, category `fields`, `destructive:true`. Callback mirrors Task 2's shape.

- [ ] **Step 4: Wire + bump + append test** — add slug to `wpultra_ability_files()` + category map; bump bootstrap 102 → 103. Append to `tests/fields-groups.test.php` a store round-trip test using stubbed `update_option`:
```php
function update_option($k, $v, $a = null) { $GLOBALS['__opt'][$k] = $v; return true; }
$r = wpultra_fields_mb_save_group(['id' => 'g2', 'title' => 'G2', 'post_types' => ['post'], 'fields' => [['id' => 'x', 'type' => 'text']]], 'upsert');
ok(is_array($r) && $r['id'] === 'g2' && $r['count'] >= 1, 'mb_save_group upsert stores');
$reg = wpultra_fields_mb_register_groups([]);
ok(count($reg) >= 1 && $reg[0]['id'] === 'g2', 'mb_register_groups appends stored group to filter output');
$bad = wpultra_fields_mb_save_group(['id' => 'BAD ID', 'title' => 'x', 'fields' => [['id' => 'a', 'type' => 'text']]], 'upsert');
ok(is_wp_error($bad), 'mb_save_group rejects invalid id');
```
(Move the final `echo/exit` lines to the end of the file so appended assertions run before them.)

- [ ] **Step 5: Lint, deploy, live-verify** — probe: `wpultra_metabox_define_field_group(['config'=>['id'=>'wpultra_p2mb','title'=>'P2 MB','post_types'=>['post'],'fields'=>[['id'=>'p2_mb','type'=>'text','name'=>'P2 MB Field']]],'mode'=>'create'])`; then confirm it appears in `wpultra_fields_metabox_list_groups()` AND that a value write→read round-trips (`wpultra_fields_mb_write` + `wpultra_fields_mb_read` on the new field on a throwaway post); then delete via mode=delete and confirm gone from the list. NOTE: within the probe, after saving the option you must `add_filter('rwmb_meta_boxes', 'wpultra_fields_mb_register_groups')` yourself (the probe is a fresh request) OR just assert against `wpultra_fields_metabox_list_groups()` which reads the option directly. Delete probe.

- [ ] **Step 6: Commit** — `feat(fields): metabox-define-field-group (option-backed rwmb_meta_boxes filter, no MB Builder needed) (Wave 5 Plan 2)`

---

### Task 4: `pods-define-fields` (native Pods) + re-verify native Pods value path

**Files:**
- Create: `wp-ultra-mcp/includes/abilities/pods-define-fields.php`
- Modify: `wp-ultra-mcp/includes/fields/adapters/pods.php` (append `wpultra_fields_pods_define`)
- Modify: `bootstrap-mcp.php` (+1 slug), `tests/bootstrap.test.php` (103 → 104)

**Interfaces:**
- Produces: `wpultra_fields_pods_define(array $payload, string $mode): array|WP_Error` — creates/extends a Pod and its fields, or deletes a field. Returns `['pod'=>string,'fields'=>string[],'mode'=>string]`.

- [ ] **Step 1: Append `wpultra_fields_pods_define` to `adapters/pods.php`**

```php
/**
 * Create/extend a Pod + its fields (mode create/update) or delete a field (mode delete).
 * payload: { pod: string, pod_type?: 'post_type'|'taxonomy'|'user'..., fields?: [{name,type,label?}], delete_field?: string }
 * @return array|WP_Error
 */
function wpultra_fields_pods_define(array $payload, string $mode) {
    if (!function_exists('pods_api')) { return new WP_Error('pods_unavailable', 'Pods is not active'); }
    $pod = (string) ($payload['pod'] ?? '');
    if ($pod === '') { return new WP_Error('pod_required', 'payload.pod is required'); }
    $api = pods_api();
    if ($mode === 'delete') {
        $field = (string) ($payload['delete_field'] ?? '');
        if ($field === '') { return new WP_Error('field_required', 'delete requires payload.delete_field'); }
        $api->delete_field(['pod' => $pod, 'name' => $field]);
        return ['pod' => $pod, 'fields' => [], 'mode' => 'delete'];
    }
    // Ensure the pod exists (create it if a pod_type is given and it's absent).
    $existing = $api->load_pod(['name' => $pod]);
    if (!$existing) {
        $pod_type = (string) ($payload['pod_type'] ?? '');
        if ($pod_type === '') { return new WP_Error('pod_not_found', "Pod '{$pod}' does not exist; pass pod_type to create it."); }
        $saved = $api->save_pod(['name' => $pod, 'type' => $pod_type]);
        if (is_wp_error($saved)) { return $saved; }
    }
    $added = [];
    foreach ((array) ($payload['fields'] ?? []) as $f) {
        $name = (string) ($f['name'] ?? '');
        $type = (string) ($f['type'] ?? 'text');
        if ($name === '') { continue; }
        $res = $api->save_field(['pod' => $pod, 'name' => $name, 'type' => $type, 'label' => $f['label'] ?? $name]);
        if (is_wp_error($res)) { return $res; }
        $added[] = $name;
    }
    return ['pod' => $pod, 'fields' => $added, 'mode' => $mode];
}
```

- [ ] **Step 2: Write the ability** (`includes/abilities/pods-define-fields.php`) — input `{payload:object, mode:enum[create,update,delete] default create}`; audit-logs; `wpultra_ok`. Description: "Create or extend a Pod and its fields, or delete a field. payload = `{pod, pod_type?(post_type|taxonomy|user|...), fields?:[{name,type,label?}], delete_field?}`. Creating a Pod requires pod_type when the Pod does not yet exist." Slug `wpultra/pods-define-fields`, category `fields`, `destructive:true`. Callback mirrors Task 2.

- [ ] **Step 3: Wire + bump** — add slug + category map; bump bootstrap 103 → 104.

- [ ] **Step 4: Lint, deploy, live-verify (define + Plan-1 carry-forward re-verify)** — probe: create a Pod `wpultra_p2pod` of type `post_type` with a text field `p2podfield`; assert `wpultra_fields_pods_list_groups()` shows it; create a post of that CPT; **write a value via `wpultra_fields_pods_write` and read it back via `wpultra_fields_pods_read`** — assert the round-trip returns the bare scalar AND confirms the NATIVE `$pod->field()` path now runs (the Plan 1 carry-forward: with a real registered Pod, `is_defined()` is true so write takes the native `save()` path and read the native `field()` path — they reconverge). Record the result in the ledger for the Plan 1 asymmetry note. Then delete the field + delete the pod (`pods_api()->delete_pod(['name'=>'wpultra_p2pod'])`) to clean up. Delete probe.

- [ ] **Step 5: Commit** — `feat(fields): pods-define-fields (native Pods API) + native Pods value path re-verified (Wave 5 Plan 2)`

---

## Self-Review

**Spec coverage (Plan 2 slice of the design doc):** `field-list-groups` → Task 1 ✓; `field-get-group` → Task 1 ✓; `acf-define-field-group` (native export) → Task 2 ✓; `metabox-define-field-group` → Task 3 ✓ (option-backed refinement of the spec's snippet idea — documented); `pods-define-fields` → Task 4 ✓. ACF-Pro-type guard (`pro_untested`) → Task 2 ✓. Plan-1 carry-forward (native Pods path re-verify once a real Pod exists) → Task 4 ✓.

**Deviation from the design spec (intentional, documented here):** the spec described Meta Box group definition as "generate a PHP snippet → managed mu-plugin file (+ `php -l` gate)". This plan instead **persists groups in the `wpultra_mb_groups` option and registers them via an always-on `rwmb_meta_boxes` filter**. Rationale: same outcome (persistent MB groups without MB Builder Pro) with strictly less risk — no generated executable code, no filesystem jail/sandbox surface, no lint step, and instant revert via option delete; consistent with the SEO wave's option+always-on-hook pattern. No functional capability is lost.

**Placeholder scan:** Tasks 2/3/4 describe the ability *file* in prose ("mirror Task 2's shape / Plan 1 ability shape") rather than re-pasting the full `wp_register_ability(...)` block, but the callback body (the non-boilerplate part) is shown in full for each, and the registration boilerplate is identical to the fully-shown `field-list-groups`/`field-get-group` blocks in Task 1 and Plan 1's abilities — the implementer has a complete concrete reference. All engine functions are shown in full. No TBD/TODO.

**Type consistency:** adapter function names follow `wpultra_fields_{provider}_{op}` used by `wpultra_fields_route`; `list_groups`/`get_group`/`define_group`/`define` are called directly by the abilities (not via route) so their names need only match between the adapter and the ability — verified consistent. `wpultra_fields_mb_stored_groups` / `_mb_group_entry` / `_mb_save_group` / `_mb_register_groups` all in groups.php, consistent between definition and callers. Count math: 99 →(T1 +2) 101 →(T2 +1) 102 →(T3 +1) 103 →(T4 +1) 104.

## Notes for later plans
- Plan 3: `field-manage-cpt`/`-taxonomy`/`-options-page` (pure `cpt.php` plan builder) + the reserved-CPT/core-type write guard the Plan-1 final review flagged.
- Plan 4: `fields-architect` skill + Elementor/Gutenberg dynamic-field bridge + v0.12.0 ship. Include the carry-forward negative-path tests for `complex_consent`/`field_invalid` (Plan 1 Minor) if not already added.
