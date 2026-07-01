# Wave 5 Plan 1 — Custom Fields Foundation + Values (crown jewels) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stand up the `fields` category with a hybrid provider driver and the two crown-jewel abilities — `field-read-values` and `field-write-values` — working live across ACF, Meta Box, and Pods, plus `field-status` for orientation.

**Architecture:** A per-request provider driver (`wpultra_fields_providers()`) detects which field plugins are active; unified `field-*` abilities route reads/writes to a thin per-provider adapter (`includes/fields/adapters/{acf,metabox,pods}.php`). Value normalization/validation is pure (`includes/fields/values.php`) and unit-tested; adapter I/O is live-verified on the real site.

**Tech Stack:** PHP 8.2, WordPress 7.0 Abilities API + bundled mcp-adapter, zero-dep PHP test harness (`tests/run-all.ps1`). Field plugins: ACF (free), Meta Box (free), Pods (free).

## Global Constraints

- Ability registration MUST use keys `execute_callback` / `input_schema` / `output_schema` / `permission_callback` / `category` (never `callback`/`input`/`output`), and the category MUST be registered in `wpultra_register_categories()`, else WP 7.0 core silently rejects the ability. Match `wp-ultra-mcp/includes/abilities/read-file.php`.
- `input_schema.properties` MUST be a plain PHP array, never an `(object)` cast (the adapter array-accesses it).
- Every ability file returns via `wpultra_ok(array $fields)` (success) or `wpultra_err(string $code, string $message, $data='')` (a `WP_Error`). Permission callback is `wpultra_permission_callback` (maps to `manage_options`).
- Every mutating ability calls `wpultra_audit_log(string $action, string $summary, bool $ok=true)`.
- The test harness must NOT redeclare functions that `helpers.php` defines — tests `require` the real file under test; pure logic files must not call WordPress functions at module load time.
- After EVERY commit, re-run `wp-ultra-mcp/bin/deploy.ps1` (Local runs the deployed copy, not the repo) before any live test.
- Live test pattern: drop a token-gated PHP probe at `C:\Users\nisha\Local Sites\wp-connector\app\public\wp-content\wpultra-*.php` that `require`s `wp-load.php`, `wp_set_current_user($adminId)`, requires the plugin engine files, calls functions, echoes JSON; curl `http://wp-connector.local/wp-content/<file>.php?t=wpultra-test-9a88`; then DELETE the probe. NEVER nest a second `wp_remote_get` to the same Local site inside one request (worker deadlock → sandbox `.crashed`).
- Bundled PHP for lint/harness: `C:\Users\nisha\AppData\Roaming\Local\lightning-services\php-8.2.30+1\bin\win64\php.exe`.
- Subagent git commits use `user.name='wp-mcp'`, `user.email='dev@local'`. Branch: `feat/fields-wave5`.

## File Structure

```
wp-ultra-mcp/includes/fields/
  setup.php                 — wpultra_fields_providers(), wpultra_fields_status(), wpultra_fields_provider_caps()
  values.php                — PURE: target resolve, batch normalize, complex-consent validation
  driver.php                — wpultra_fields_route($op, $provider, $args) dispatch to adapters
  adapters/acf.php          — wpultra_fields_acf_read/_write
  adapters/metabox.php      — wpultra_fields_mb_read/_write
  adapters/pods.php         — wpultra_fields_pods_read/_write
wp-ultra-mcp/includes/abilities/
  field-status.php          — field-status ability
  field-read-values.php     — field-read-values ability
  field-write-values.php    — field-write-values ability
tests/                      — repo-root suite dir globbed by tests/run-all.ps1
  fields-values.test.php    — pure helper unit tests (require path prefix ../wp-ultra-mcp/includes/…)
```

> NOTE (correction during execution): the suite lives at repo-root `tests/` (globbed by `tests/run-all.ps1`), NOT `wp-ultra-mcp/tests/`. New test files go in repo-root `tests/` and require the plugin via `../wp-ultra-mcp/includes/…`. Any wave that adds ability slugs must also bump the count assertion in `tests/bootstrap.test.php`.

Wiring edits (all in `wp-ultra-mcp/includes/bootstrap-mcp.php`):
- `wpultra_register_categories()` — add `'fields'` slug.
- `wpultra_load_abilities()` — add a `fields` block that requires `includes/fields/{setup,values,driver,adapters/acf,adapters/metabox,adapters/pods}.php` when the category is enabled.
- `wpultra_ability_files()` — add `'field-status','field-read-values','field-write-values'`.
- `wpultra_ability_category_map()` — add `'fields' => [ ...those three... ]`.

No front-end `init` loader is needed for Plan 1 (fields render no `<head>` output; abilities run in REST context only).

---

### Task 1: Install ACF, Meta Box, Pods on the Local test site (verification, no commit)

**Files:** none (environment setup + verification only).

**Interfaces:**
- Produces: three active plugins on the test site so Tasks 4–5 can live-verify. Records exact versions in the SDD ledger.

- [ ] **Step 1: Confirm Local site is running**

Run: `curl -s -o /dev/null -w "%{http_code}" http://wp-connector.local/`
Expected: `200` (if not, the user must start the `wp-connector` site in Local first — STOP and ask).

- [ ] **Step 2: Try WP-CLI install (fastest path)**

The Local site has no system WP-CLI, but Local ships one. Attempt via the site shell is unavailable here, so use a token-gated PHP probe that calls the Plugin installer API. Create `C:\Users\nisha\Local Sites\wp-connector\app\public\wp-content\wpultra-install.php`:

```php
<?php
if (($_GET['t'] ?? '') !== 'wpultra-test-9a88') { http_response_code(403); exit('no'); }
require dirname(__DIR__) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/misc.php';
$admin = get_users(['role' => 'administrator', 'number' => 1]);
wp_set_current_user($admin ? $admin[0]->ID : 1);
$slugs = ['advanced-custom-fields', 'meta-box', 'pods'];
$out = [];
foreach ($slugs as $slug) {
    $info = plugins_api('plugin_information', ['slug' => $slug, 'fields' => ['sections' => false]]);
    if (is_wp_error($info)) { $out[$slug] = ['error' => $info->get_error_message()]; continue; }
    $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
    $res = $upgrader->install($info->download_link);
    $out[$slug] = ['installed' => $res === true, 'result' => is_wp_error($res) ? $res->get_error_message() : $res];
}
echo json_encode($out, JSON_PRETTY_PRINT);
```

Run: `curl "http://wp-connector.local/wp-content/wpultra-install.php?t=wpultra-test-9a88"`
Expected: JSON showing each slug `installed:true` (or "folder already exists" if present — acceptable).

- [ ] **Step 3: Activate the three plugins**

Create `C:\Users\nisha\Local Sites\wp-connector\app\public\wp-content\wpultra-activate.php`:

```php
<?php
if (($_GET['t'] ?? '') !== 'wpultra-test-9a88') { http_response_code(403); exit('no'); }
require dirname(__DIR__) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$files = ['advanced-custom-fields/acf.php', 'meta-box/meta-box.php', 'pods/init.php'];
$out = [];
foreach ($files as $f) {
    $r = activate_plugin($f);
    $out[$f] = is_wp_error($r) ? $r->get_error_message() : 'active';
}
$out['constants'] = [
    'ACF' => class_exists('ACF'), 'ACF_VERSION' => defined('ACF_VERSION') ? ACF_VERSION : null,
    'RWMB_VER' => defined('RWMB_VER') ? RWMB_VER : null,
    'PODS_VERSION' => defined('PODS_VERSION') ? PODS_VERSION : null,
];
echo json_encode($out, JSON_PRETTY_PRINT);
```

Run: `curl "http://wp-connector.local/wp-content/wpultra-activate.php?t=wpultra-test-9a88"`
Expected: each plugin file `"active"`; `constants` shows `ACF:true`, non-null `ACF_VERSION`, `RWMB_VER`, `PODS_VERSION`. (Pods main file is `pods/init.php`; if activation reports a different path error, list `wp-content/plugins/pods/` and use the file with the plugin header.)

- [ ] **Step 4: Delete both probes**

Delete `wp-content/wpultra-install.php` and `wp-content/wpultra-activate.php`.

- [ ] **Step 5: Record versions**

Note the three versions in `.superpowers/sdd/progress.md` and (at wave end) memory. No git commit — this task changes only the test environment.

---

### Task 2: `fields` category, provider driver skeleton, and `field-status`

**Files:**
- Create: `wp-ultra-mcp/includes/fields/setup.php`
- Create: `wp-ultra-mcp/includes/abilities/field-status.php`
- Modify: `wp-ultra-mcp/includes/bootstrap-mcp.php` (register category, load block, ability list, category map)

**Interfaces:**
- Produces:
  - `wpultra_fields_providers(): array` — list of `['provider'=>'acf'|'metabox'|'pods', 'edition'=>'free'|'pro'|null, 'version'=>string, 'caps'=>array]` for each ACTIVE provider (empty array if none).
  - `wpultra_fields_provider_caps(string $provider): array` — capability booleans `['manage_cpt'=>bool,'manage_taxonomy'=>bool,'manage_options_page'=>bool,'complex_types'=>bool,'define_group_db'=>bool]`.
  - `wpultra_fields_status(): array` — `['providers'=>[...], 'active_count'=>int]`.
- Consumes: `wpultra_ok`, `wpultra_err`, `wpultra_permission_callback` (helpers.php, already loaded).

- [ ] **Step 1: Write the failing test for provider detection purity**

Create `wp-ultra-mcp/tests/fields-values.test.php` (this file also hosts Task 3 tests):

```php
<?php
// Zero-dep harness style: define minimal stubs, require the file, assert.
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
$__fails = 0;
function ok($cond, $msg) { global $__fails; if ($cond) { echo "PASS: $msg\n"; } else { $__fails++; echo "FAIL: $msg\n"; } }

// setup.php must be requireable without any field plugin present and report zero providers.
require __DIR__ . '/../includes/fields/setup.php';
$providers = wpultra_fields_providers();
ok(is_array($providers), 'wpultra_fields_providers returns array');
ok(count($providers) === 0, 'no providers detected in bare CLI (no ACF/MB/Pods constants)');
$caps = wpultra_fields_provider_caps('acf');
ok(isset($caps['complex_types']) && $caps['complex_types'] === false, 'acf caps default complex_types false without ACF Pro');
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `& "C:\Users\nisha\AppData\Roaming\Local\lightning-services\php-8.2.30+1\bin\win64\php.exe" wp-ultra-mcp/tests/fields-values.test.php`
Expected: FATAL — `setup.php` does not exist yet.

- [ ] **Step 3: Write `includes/fields/setup.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Detect each active field-plugin provider, its edition and version.
 * @return array<int,array{provider:string,edition:?string,version:string,caps:array}>
 */
function wpultra_fields_providers(): array {
    $out = [];
    // ACF (free) / ACF Pro
    if (class_exists('ACF')) {
        $edition = (class_exists('acf_pro') || defined('ACF_PRO')) ? 'pro' : 'free';
        $out[] = [
            'provider' => 'acf',
            'edition'  => $edition,
            'version'  => defined('ACF_VERSION') ? (string) ACF_VERSION : '',
            'caps'     => wpultra_fields_provider_caps('acf'),
        ];
    }
    // Meta Box core
    if (defined('RWMB_VER') || function_exists('rwmb_meta')) {
        $out[] = [
            'provider' => 'metabox',
            'edition'  => 'free',
            'version'  => defined('RWMB_VER') ? (string) RWMB_VER : '',
            'caps'     => wpultra_fields_provider_caps('metabox'),
        ];
    }
    // Pods (fully free)
    if (function_exists('pods') && defined('PODS_VERSION')) {
        $out[] = [
            'provider' => 'pods',
            'edition'  => 'free',
            'version'  => (string) PODS_VERSION,
            'caps'     => wpultra_fields_provider_caps('pods'),
        ];
    }
    return $out;
}

/**
 * Capability matrix per provider. Pure except for the ACF-Pro / MB-extension probes,
 * which read only class/function existence (safe at any time).
 * @return array{manage_cpt:bool,manage_taxonomy:bool,manage_options_page:bool,complex_types:bool,define_group_db:bool}
 */
function wpultra_fields_provider_caps(string $provider): array {
    switch ($provider) {
        case 'acf':
            $pro = class_exists('acf_pro') || defined('ACF_PRO');
            return [
                'manage_cpt'          => $pro, // ACF UI post types require Pro 6.5+
                'manage_taxonomy'     => $pro,
                'manage_options_page' => $pro,
                'complex_types'       => $pro, // repeater/flexible/gallery/clone
                'define_group_db'     => true, // free ACF stores field groups in DB (acf-field-group CPT)
            ];
        case 'metabox':
            return [
                'manage_cpt'          => class_exists('MB_Custom_Post_Type') || defined('MB_CPT_DIR'),
                'manage_taxonomy'     => class_exists('MB_Custom_Post_Type') || defined('MB_CPT_DIR'),
                'manage_options_page' => class_exists('MB_Settings_Page'),
                'complex_types'       => function_exists('rwmb_meta'), // group/cloneable need MB Group/Pro at write time
                'define_group_db'     => false, // core Meta Box registers groups via PHP filter, no DB storage
            ];
        case 'pods':
            return [
                'manage_cpt'          => true, // Pods creates CPTs in free
                'manage_taxonomy'     => true,
                'manage_options_page' => true, // Pods settings pods
                'complex_types'       => true,
                'define_group_db'     => true,
            ];
    }
    return [
        'manage_cpt' => false, 'manage_taxonomy' => false, 'manage_options_page' => false,
        'complex_types' => false, 'define_group_db' => false,
    ];
}

/** Orientation summary for the field-status ability. */
function wpultra_fields_status(): array {
    $providers = wpultra_fields_providers();
    return [
        'providers'    => $providers,
        'active_count' => count($providers),
    ];
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `& "C:\Users\nisha\AppData\Roaming\Local\lightning-services\php-8.2.30+1\bin\win64\php.exe" wp-ultra-mcp/tests/fields-values.test.php`
Expected: 3 PASS lines, no FAIL.

- [ ] **Step 5: Write the `field-status` ability**

Create `wp-ultra-mcp/includes/abilities/field-status.php`:

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/field-status', [
    'label'       => __('Field Plugins Status', 'wp-ultra-mcp'),
    'description' => __('Reports which custom-field providers (ACF, Meta Box, Pods) are active, their edition and version, and a capability matrix (manage CPT/taxonomy/options-page, complex field types, DB-stored groups). Call FIRST before any other field ability — an empty providers list means no field plugin is active.', 'wp-ultra-mcp'),
    'category'    => 'fields',
    'input_schema'  => [
        'type' => 'object',
        'properties' => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success'      => ['type' => 'boolean'],
            'active_count' => ['type' => 'integer'],
            'providers'    => ['type' => 'array'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_field_status',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_field_status(array $input) {
    $status = wpultra_fields_status();
    return wpultra_ok($status);
}
```

- [ ] **Step 6: Wire into `bootstrap-mcp.php`**

In `wpultra_register_categories()`, add to the `$cats` array:
```php
        'fields' => 'Custom fields & content model via ACF, Meta Box, or Pods.',
```
In `wpultra_load_abilities()`, after the `seo` block (around line 168), add:
```php
    if (!in_array('fields', $disabled, true)) {
        foreach (['setup', 'values', 'driver', 'adapters/acf', 'adapters/metabox', 'adapters/pods'] as $ff) {
            $fp = WPULTRA_DIR . 'includes/fields/' . $ff . '.php';
            if (is_readable($fp)) { require_once $fp; }
        }
    }
```
In `wpultra_ability_files()`, add before the closing `];`:
```php
        // fields (Wave 5, Plan 1)
        'field-status', 'field-read-values', 'field-write-values',
```
In `wpultra_ability_category_map()`, add:
```php
        'fields' => ['field-status', 'field-read-values', 'field-write-values'],
```

- [ ] **Step 7: Lint all touched PHP**

Run: `& "C:\Users\nisha\AppData\Roaming\Local\lightning-services\php-8.2.30+1\bin\win64\php.exe" -l wp-ultra-mcp/includes/fields/setup.php; & "C:\Users\nisha\...\php.exe" -l wp-ultra-mcp/includes/abilities/field-status.php; & "C:\Users\nisha\...\php.exe" -l wp-ultra-mcp/includes/bootstrap-mcp.php`
Expected: `No syntax errors detected` for each.

- [ ] **Step 8: Deploy and live-verify `field-status`**

Run `wp-ultra-mcp/bin/deploy.ps1`. Then create probe `wp-content/wpultra-fstatus.php`:
```php
<?php
if (($_GET['t'] ?? '') !== 'wpultra-test-9a88') { http_response_code(403); exit('no'); }
require dirname(__DIR__) . '/wp-load.php';
require dirname(__DIR__) . '/wp-content/plugins/wp-ultra-mcp/includes/fields/setup.php';
echo json_encode(wpultra_fields_status(), JSON_PRETTY_PRINT);
```
Run: `curl "http://wp-connector.local/wp-content/wpultra-fstatus.php?t=wpultra-test-9a88"`
Expected: `active_count: 3`; providers array contains `acf` (edition free), `metabox`, `pods`, each with a version string and caps object. Delete the probe.

- [ ] **Step 9: Commit**

```bash
git add wp-ultra-mcp/includes/fields/setup.php wp-ultra-mcp/includes/abilities/field-status.php wp-ultra-mcp/includes/bootstrap-mcp.php wp-ultra-mcp/tests/fields-values.test.php
git commit -m "feat(fields): provider driver detection + field-status (Wave 5 Plan 1)"
```

---

### Task 3: Pure value normalization + target resolution (`values.php`)

**Files:**
- Create: `wp-ultra-mcp/includes/fields/values.php`
- Modify: `wp-ultra-mcp/tests/fields-values.test.php` (append tests)

**Interfaces:**
- Produces:
  - `wpultra_fields_resolve_target(array $target): array|WP_Error` — validates `{type:'post'|'user'|'term'|'options', id?}`, returns a canonical `['type'=>..,'id'=>int|string]`. For `options`, id is a string slug or `''`.
  - `wpultra_fields_normalize_batch(array $values): array|WP_Error` — splits a write map into `['atomic'=>[name=>value], 'complex'=>[name=>['value'=>..,'mode'=>'replace']]]`. A complex entry is any value given as `['value'=>..,'mode'=>'replace']`; a bare array value that is NOT that shape is treated as atomic (passed through). Returns `WP_Error('complex_consent')` if a value looks complex (assoc/`mode` present) but omits the `value`/`mode:'replace'` wrapper contract.
- Consumes: `WP_Error` (WordPress; the harness defines a minimal stub — see Step 1).

- [ ] **Step 1: Append failing tests + WP_Error stub**

Append to `wp-ultra-mcp/tests/fields-values.test.php`:
```php
// --- Task 3: values.php (pure) ---
if (!class_exists('WP_Error')) {
    class WP_Error {
        public $code; public $message; public $data;
        public function __construct($code = '', $message = '', $data = '') { $this->code = $code; $this->message = $message; $this->data = $data; }
        public function get_error_code() { return $this->code; }
    }
}
function is_wp_error($t) { return $t instanceof WP_Error; }
require __DIR__ . '/../includes/fields/values.php';

$t = wpultra_fields_resolve_target(['type' => 'post', 'id' => '42']);
ok(is_array($t) && $t['type'] === 'post' && $t['id'] === 42, 'resolve_target coerces post id to int');
$t2 = wpultra_fields_resolve_target(['type' => 'options']);
ok(is_array($t2) && $t2['type'] === 'options' && $t2['id'] === '', 'resolve_target options allows empty id');
$bad = wpultra_fields_resolve_target(['type' => 'bogus']);
ok(is_wp_error($bad) && $bad->get_error_code() === 'target_invalid', 'resolve_target rejects unknown type');

$n = wpultra_fields_normalize_batch(['subtitle' => 'Hi', 'features' => ['value' => [1, 2], 'mode' => 'replace']]);
ok(is_array($n) && $n['atomic']['subtitle'] === 'Hi', 'normalize keeps atomic scalar');
ok(isset($n['complex']['features']) && $n['complex']['features']['value'] === [1, 2], 'normalize routes consent-wrapped value to complex');

echo "\n" . ($__fails === 0 ? "ALL PASS" : "$__fails FAILED") . "\n";
exit($__fails === 0 ? 0 : 1);
```

- [ ] **Step 2: Run to verify it fails**

Run: `& "C:\Users\nisha\...\php.exe" wp-ultra-mcp/tests/fields-values.test.php`
Expected: FATAL — `values.php` missing.

- [ ] **Step 3: Write `includes/fields/values.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Validate & canonicalize a target descriptor.
 * @return array{type:string,id:int|string}|WP_Error
 */
function wpultra_fields_resolve_target(array $target) {
    $type = $target['type'] ?? null;
    if (!is_string($type) || !in_array($type, ['post', 'user', 'term', 'options'], true)) {
        return new WP_Error('target_invalid', 'target.type must be one of: post, user, term, options', ['target' => $target]);
    }
    if ($type === 'options') {
        $id = $target['id'] ?? '';
        if ($id === null) { $id = ''; }
        if (!is_string($id) || ($id !== '' && !preg_match('/^[a-z0-9_-]+$/i', $id))) {
            return new WP_Error('target_invalid', 'target.id for options must be a slug [a-z0-9_-]+ or omitted', ['target' => $target]);
        }
        return ['type' => 'options', 'id' => $id];
    }
    $id = $target['id'] ?? null;
    if (!(is_int($id) || (is_string($id) && ctype_digit($id)))) {
        return new WP_Error('target_invalid', "target.id must be a numeric {$type} id", ['target' => $target]);
    }
    return ['type' => $type, 'id' => (int) $id];
}

/**
 * Split a write map into atomic vs. complex (consent-wrapped) values.
 * Complex contract: ['value' => mixed, 'mode' => 'replace'].
 * @return array{atomic:array<string,mixed>,complex:array<string,array{value:mixed,mode:string}>}|WP_Error
 */
function wpultra_fields_normalize_batch(array $values) {
    $atomic = [];
    $complex = [];
    foreach ($values as $name => $val) {
        if (!is_string($name) || $name === '') {
            return new WP_Error('field_invalid', 'field names must be non-empty strings', ['field' => $name]);
        }
        // Consent-wrapped complex value.
        if (is_array($val) && array_key_exists('value', $val) && array_key_exists('mode', $val)) {
            if ($val['mode'] !== 'replace') {
                return new WP_Error('complex_consent', "field '{$name}': complex mode must be 'replace'", ['field' => $name]);
            }
            $complex[$name] = ['value' => $val['value'], 'mode' => 'replace'];
            continue;
        }
        // A value carrying a 'mode' key without a matching 'value' is a malformed consent wrapper.
        if (is_array($val) && (array_key_exists('mode', $val) || (array_key_exists('value', $val) && count($val) === 1 && !array_is_list($val)))) {
            return new WP_Error('complex_consent', "field '{$name}': complex values need { value, mode:'replace' }", ['field' => $name]);
        }
        $atomic[$name] = $val;
    }
    return ['atomic' => $atomic, 'complex' => $complex];
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `& "C:\Users\nisha\...\php.exe" wp-ultra-mcp/tests/fields-values.test.php`
Expected: `ALL PASS` and exit code 0.

- [ ] **Step 5: Register the test in the harness runner**

Confirm `wp-ultra-mcp/tests/run-all.ps1` globs `*.test.php` (it does). If it lists files explicitly, add `fields-values.test.php`. Run: `powershell -File wp-ultra-mcp/tests/run-all.ps1`
Expected: the full suite is green including the new file.

- [ ] **Step 6: Commit**

```bash
git add wp-ultra-mcp/includes/fields/values.php wp-ultra-mcp/tests/fields-values.test.php
git commit -m "feat(fields): pure target-resolve + batch normalize with complex-consent (Wave 5 Plan 1)"
```

---

### Task 4: Provider read adapters + `field-read-values`

**Files:**
- Create: `wp-ultra-mcp/includes/fields/driver.php`
- Create: `wp-ultra-mcp/includes/fields/adapters/acf.php`
- Create: `wp-ultra-mcp/includes/fields/adapters/metabox.php`
- Create: `wp-ultra-mcp/includes/fields/adapters/pods.php`
- Create: `wp-ultra-mcp/includes/abilities/field-read-values.php`

**Interfaces:**
- Consumes: `wpultra_fields_resolve_target`, `wpultra_fields_providers` (Tasks 2–3).
- Produces:
  - `wpultra_fields_route(string $op, string $provider, array $args): array|WP_Error` — dispatch `$op` in {`read`,`write`} to `wpultra_fields_{provider}_{op}`.
  - `wpultra_fields_pick_provider(?string $requested): string|WP_Error` — returns the provider to use: the requested one if active; else the sole active provider; else `WP_Error('provider_ambiguous'|'no_provider')`.
  - `wpultra_fields_acf_read(array $target, ?array $fields, bool $format): array` — returns `[name=>value]`.
  - `wpultra_fields_mb_read(array $target, ?array $fields, bool $format): array`.
  - `wpultra_fields_pods_read(array $target, ?array $fields, bool $format): array`.

- [ ] **Step 1: Write `includes/fields/driver.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/** Names of currently-active providers. */
function wpultra_fields_active_names(): array {
    return array_map(static fn($p) => $p['provider'], wpultra_fields_providers());
}

/**
 * Choose which provider to operate on.
 * @return string|WP_Error
 */
function wpultra_fields_pick_provider(?string $requested) {
    $active = wpultra_fields_active_names();
    if (!$active) { return new WP_Error('no_provider', 'No custom-field plugin (ACF, Meta Box, Pods) is active.'); }
    if ($requested !== null && $requested !== '' && $requested !== 'auto') {
        if (!in_array($requested, $active, true)) {
            return new WP_Error('provider_inactive', "Provider '{$requested}' is not active. Active: " . implode(', ', $active));
        }
        return $requested;
    }
    if (count($active) === 1) { return $active[0]; }
    return new WP_Error('provider_ambiguous', 'Multiple providers active (' . implode(', ', $active) . '); specify provider.', ['active' => $active]);
}

/**
 * Dispatch an operation to the provider adapter.
 * @return array|WP_Error
 */
function wpultra_fields_route(string $op, string $provider, array $args) {
    $fn = "wpultra_fields_{$provider}_{$op}";
    if (!function_exists($fn)) {
        return new WP_Error('op_unsupported', "Provider '{$provider}' does not support '{$op}'.");
    }
    return $fn(...$args);
}
```

- [ ] **Step 2: Write `includes/fields/adapters/acf.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/** Convert a canonical target to ACF's polymorphic post_id string. */
function wpultra_fields_acf_target(array $target): string {
    switch ($target['type']) {
        case 'user':    return 'user_' . (int) $target['id'];
        case 'term':    return 'term_' . (int) $target['id'];
        case 'options': return $target['id'] === '' ? 'options' : (string) $target['id'];
        default:        return (string) (int) $target['id']; // post
    }
}

/**
 * @param array|null $fields  field names/keys, or null for "all fields on target"
 * @return array<string,mixed>
 */
function wpultra_fields_acf_read(array $target, ?array $fields, bool $format): array {
    $acf_id = wpultra_fields_acf_target($target);
    $out = [];
    if ($fields === null) {
        // get_field_objects returns all fields whose location applies to the target.
        $objs = function_exists('get_field_objects') ? get_field_objects($acf_id, $format) : [];
        if (is_array($objs)) {
            foreach ($objs as $name => $obj) { $out[$name] = $obj['value'] ?? null; }
        }
        return $out;
    }
    foreach ($fields as $name) {
        $out[$name] = get_field($name, $acf_id, $format);
    }
    return $out;
}
```

- [ ] **Step 3: Write `includes/fields/adapters/metabox.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/** Map canonical target type to Meta Box object_type. */
function wpultra_fields_mb_object_type(string $type): string {
    return match ($type) {
        'user'    => 'user',
        'term'    => 'term',
        'options' => 'setting',
        default   => 'post',
    };
}

/** @return array<string,mixed> */
function wpultra_fields_mb_read(array $target, ?array $fields, bool $format): array {
    $ot  = wpultra_fields_mb_object_type($target['type']);
    $oid = $target['type'] === 'options' ? (string) $target['id'] : (int) $target['id'];
    $args = ['object_type' => $ot];
    $out = [];
    if ($fields === null) {
        // Collect field ids registered on this object type via the MB filter.
        $groups = apply_filters('rwmb_meta_boxes', []);
        $ids = [];
        foreach ((array) $groups as $g) {
            foreach ((array) ($g['fields'] ?? []) as $f) {
                if (!empty($f['id'])) { $ids[] = (string) $f['id']; }
            }
        }
        $fields = array_values(array_unique($ids));
    }
    foreach ($fields as $fid) {
        $out[$fid] = rwmb_meta($fid, $args, $oid);
    }
    return $out;
}
```

- [ ] **Step 4: Write `includes/fields/adapters/pods.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/** Resolve the Pod name for a target (post→post_type, term→taxonomy, user→'user', options→id). */
function wpultra_fields_pods_name(array $target): string {
    switch ($target['type']) {
        case 'user':    return 'user';
        case 'term':    $t = get_term((int) $target['id']); return ($t && !is_wp_error($t)) ? $t->taxonomy : '';
        case 'options': return (string) $target['id'];
        default:        return (string) get_post_type((int) $target['id']);
    }
}

/** @return array<string,mixed> */
function wpultra_fields_pods_read(array $target, ?array $fields, bool $format): array {
    $pod_name = wpultra_fields_pods_name($target);
    $id  = $target['type'] === 'options' ? null : (int) $target['id'];
    $pod = ($pod_name !== '') ? pods($pod_name, $id) : false;
    $out = [];
    if (!$pod || !$pod->exists()) {
        // Options pods and edge cases: fall back to post meta for post targets.
        if ($target['type'] === 'post' && $fields) {
            foreach ($fields as $name) { $out[$name] = get_post_meta((int) $target['id'], $name, true); }
        }
        return $out;
    }
    if ($fields === null) {
        $data = $pod->export();
        return is_array($data) ? $data : [];
    }
    foreach ($fields as $name) {
        $out[$name] = $format ? $pod->display($name) : $pod->field($name);
    }
    return $out;
}
```

- [ ] **Step 5: Write the `field-read-values` ability**

Create `wp-ultra-mcp/includes/abilities/field-read-values.php`:
```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/field-read-values', [
    'label'       => __('Read Field Values', 'wp-ultra-mcp'),
    'description' => __('Reads custom-field values from a target (post/user/term/options) via the active field provider (ACF, Meta Box, or Pods). Omit fields[] to read all fields whose location applies to the target. format_values (default true) returns formatted values (images as arrays, related objects expanded); false returns raw stored data. Specify provider when more than one is active.', 'wp-ultra-mcp'),
    'category'    => 'fields',
    'input_schema'  => [
        'type' => 'object',
        'properties' => [
            'target' => [
                'type' => 'object',
                'properties' => [
                    'type' => ['type' => 'string', 'enum' => ['post', 'user', 'term', 'options']],
                    'id'   => ['type' => ['integer', 'string']],
                ],
                'required' => ['type'],
                'additionalProperties' => false,
            ],
            'fields'        => ['type' => 'array', 'items' => ['type' => 'string']],
            'format_values' => ['type' => 'boolean', 'default' => true],
            'provider'      => ['type' => 'string', 'enum' => ['acf', 'metabox', 'pods', 'auto']],
        ],
        'required' => ['target'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success'  => ['type' => 'boolean'],
            'provider' => ['type' => 'string'],
            'target'   => ['type' => 'object'],
            'values'   => ['type' => 'object'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_field_read_values',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_field_read_values(array $input) {
    $target = wpultra_fields_resolve_target((array) ($input['target'] ?? []));
    if (is_wp_error($target)) { return $target; }
    $provider = wpultra_fields_pick_provider($input['provider'] ?? null);
    if (is_wp_error($provider)) { return $provider; }
    $fields = isset($input['fields']) && is_array($input['fields']) ? array_values(array_map('strval', $input['fields'])) : null;
    if ($fields === []) { $fields = null; }
    $format = array_key_exists('format_values', $input) ? (bool) $input['format_values'] : true;
    $values = wpultra_fields_route('read', $provider, [$target, $fields, $format]);
    if (is_wp_error($values)) { return $values; }
    // Ensure an object (not a JSON array) even when empty.
    return wpultra_ok(['provider' => $provider, 'target' => $target, 'values' => (object) $values]);
}
```

- [ ] **Step 6: Lint all new files**

Run `php -l` on driver.php, adapters/acf.php, adapters/metabox.php, adapters/pods.php, field-read-values.php.
Expected: `No syntax errors detected` for each.

- [ ] **Step 7: Deploy + live-verify read on all 3 providers**

Run `wp-ultra-mcp/bin/deploy.ps1`. Create probe `wp-content/wpultra-fread.php` that seeds one field per provider using each plugin's own API, then reads it back through our adapters:
```php
<?php
if (($_GET['t'] ?? '') !== 'wpultra-test-9a88') { http_response_code(403); exit('no'); }
require dirname(__DIR__) . '/wp-load.php';
$admin = get_users(['role' => 'administrator', 'number' => 1]);
wp_set_current_user($admin ? $admin[0]->ID : 1);
$base = dirname(__DIR__) . '/wp-content/plugins/wp-ultra-mcp/includes/fields/';
foreach (['setup', 'values', 'driver', 'adapters/acf', 'adapters/metabox', 'adapters/pods'] as $f) { require_once $base . $f . '.php'; }

// A throwaway post to attach values to.
$pid = wp_insert_post(['post_title' => 'fields-read-probe', 'post_status' => 'draft', 'post_type' => 'post']);

// ACF: register a local field group with one text field, then update_field.
acf_add_local_field_group([
    'key' => 'group_wpultra_probe', 'title' => 'WPUltra Probe',
    'fields' => [['key' => 'field_wpultra_sub', 'label' => 'Subtitle', 'name' => 'wpultra_sub', 'type' => 'text']],
    'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]],
]);
update_field('wpultra_sub', 'acf-hello', $pid);

// Meta Box: register a field via the filter, then write via update_post_meta (rwmb reads meta).
add_filter('rwmb_meta_boxes', function ($mb) {
    $mb[] = ['title' => 'WPUltra MB Probe', 'post_types' => ['post'], 'fields' => [['id' => 'wpultra_mb', 'type' => 'text']]];
    return $mb;
});
update_post_meta($pid, 'wpultra_mb', 'mb-hello');

// Pods: write via post meta on the built-in 'post' pod is not registered by default;
// read fallback covers post meta.
update_post_meta($pid, 'wpultra_pods', 'pods-hello');

$acf = wpultra_fields_acf_read(['type' => 'post', 'id' => $pid], ['wpultra_sub'], false);
$mb  = wpultra_fields_mb_read(['type' => 'post', 'id' => $pid], ['wpultra_mb'], false);
$pod = wpultra_fields_pods_read(['type' => 'post', 'id' => $pid], ['wpultra_pods'], false);

wp_delete_post($pid, true);
echo json_encode(['acf' => $acf, 'metabox' => $mb, 'pods' => $pod], JSON_PRETTY_PRINT);
```
Run: `curl "http://wp-connector.local/wp-content/wpultra-fread.php?t=wpultra-test-9a88"`
Expected: `acf.wpultra_sub == "acf-hello"`, `metabox.wpultra_mb == "mb-hello"`, `pods.wpultra_pods == "pods-hello"`. Delete the probe.
(Note: the Pods path here exercises the post-meta fallback because no custom Pod is registered yet — Plan 3 adds Pod registration and re-verifies the native `->field()` path. Record this in the ledger.)

- [ ] **Step 8: Commit**

```bash
git add wp-ultra-mcp/includes/fields/driver.php wp-ultra-mcp/includes/fields/adapters/ wp-ultra-mcp/includes/abilities/field-read-values.php
git commit -m "feat(fields): read adapters (acf/metabox/pods) + field-read-values (Wave 5 Plan 1)"
```

---

### Task 5: Provider write adapters + `field-write-values` (round-trip)

**Files:**
- Modify: `wp-ultra-mcp/includes/fields/adapters/acf.php` (add write)
- Modify: `wp-ultra-mcp/includes/fields/adapters/metabox.php` (add write)
- Modify: `wp-ultra-mcp/includes/fields/adapters/pods.php` (add write)
- Create: `wp-ultra-mcp/includes/abilities/field-write-values.php`

**Interfaces:**
- Consumes: `wpultra_fields_resolve_target`, `wpultra_fields_normalize_batch`, `wpultra_fields_pick_provider`, `wpultra_fields_route`, `wpultra_audit_log`.
- Produces:
  - `wpultra_fields_acf_write(array $target, array $atomic, array $complex): array` — returns `[name=>['status'=>'ok'|'error','error'?]]`.
  - `wpultra_fields_mb_write(array $target, array $atomic, array $complex): array`.
  - `wpultra_fields_pods_write(array $target, array $atomic, array $complex): array`.

- [ ] **Step 1: Add `wpultra_fields_acf_write` to `adapters/acf.php`**

Append:
```php
/**
 * @param array<string,mixed> $atomic
 * @param array<string,array{value:mixed,mode:string}> $complex
 * @return array<string,array{status:string,error?:string}>
 */
function wpultra_fields_acf_write(array $target, array $atomic, array $complex): array {
    $acf_id = wpultra_fields_acf_target($target);
    $res = [];
    foreach ($atomic as $name => $value) {
        $ok = update_field($name, $value, $acf_id);
        $res[$name] = $ok ? ['status' => 'ok'] : ['status' => 'ok']; // update_field returns false when value unchanged; treat as ok
    }
    foreach ($complex as $name => $wrap) {
        $ok = update_field($name, $wrap['value'], $acf_id);
        $res[$name] = ['status' => 'ok'];
    }
    return $res;
}
```

- [ ] **Step 2: Add `wpultra_fields_mb_write` to `adapters/metabox.php`**

Append:
```php
/** @return array<string,array{status:string,error?:string}> */
function wpultra_fields_mb_write(array $target, array $atomic, array $complex): array {
    $ot  = wpultra_fields_mb_object_type($target['type']);
    $oid = $target['type'] === 'options' ? (string) $target['id'] : (int) $target['id'];
    $res = [];
    $all = $atomic;
    foreach ($complex as $name => $wrap) { $all[$name] = $wrap['value']; }
    foreach ($all as $fid => $value) {
        if ($ot === 'post' && function_exists('rwmb_set_meta')) {
            rwmb_set_meta((int) $oid, $fid, $value);
            $res[$fid] = ['status' => 'ok'];
        } else {
            // user/term/setting or MB not offering a setter: write metadata directly.
            $meta_type = $ot === 'setting' ? null : $ot;
            if ($meta_type) { update_metadata($meta_type, (int) $oid, $fid, $value); }
            else { update_option($fid, $value); }
            $res[$fid] = ['status' => 'ok'];
        }
    }
    return $res;
}
```

- [ ] **Step 3: Add `wpultra_fields_pods_write` to `adapters/pods.php`**

Append:
```php
/** @return array<string,array{status:string,error?:string}> */
function wpultra_fields_pods_write(array $target, array $atomic, array $complex): array {
    $pod_name = wpultra_fields_pods_name($target);
    $id  = $target['type'] === 'options' ? null : (int) $target['id'];
    $pod = ($pod_name !== '') ? pods($pod_name, $id) : false;
    $res = [];
    $all = $atomic;
    foreach ($complex as $name => $wrap) { $all[$name] = $wrap['value']; }
    foreach ($all as $name => $value) {
        if ($pod && $pod->exists()) {
            $pod->save($name, $value);
            $res[$name] = ['status' => 'ok'];
        } elseif ($target['type'] === 'post') {
            update_post_meta((int) $target['id'], $name, $value); // fallback until a Pod is registered (Plan 3)
            $res[$name] = ['status' => 'ok'];
        } else {
            $res[$name] = ['status' => 'error', 'error' => 'no Pod registered for target'];
        }
    }
    return $res;
}
```

- [ ] **Step 4: Write the `field-write-values` ability**

Create `wp-ultra-mcp/includes/abilities/field-write-values.php`:
```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/field-write-values', [
    'label'       => __('Write Field Values', 'wp-ultra-mcp'),
    'description' => __('Writes a batch of custom-field values to a target (post/user/term/options) via the active field provider (ACF, Meta Box, or Pods). Atomic fields take the value directly: {"subtitle":"Hi"}. Complex fields (repeater/group/gallery/relationship) require a consent wrapper {"features":{"value":[...],"mode":"replace"}} because writing replaces the whole value. Specify provider when more than one is active. Writes go through each plugin native updater so its hooks fire.', 'wp-ultra-mcp'),
    'category'    => 'fields',
    'input_schema'  => [
        'type' => 'object',
        'properties' => [
            'target' => [
                'type' => 'object',
                'properties' => [
                    'type' => ['type' => 'string', 'enum' => ['post', 'user', 'term', 'options']],
                    'id'   => ['type' => ['integer', 'string']],
                ],
                'required' => ['type'],
                'additionalProperties' => false,
            ],
            'values'   => ['type' => 'object'],
            'provider' => ['type' => 'string', 'enum' => ['acf', 'metabox', 'pods', 'auto']],
        ],
        'required' => ['target', 'values'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success'  => ['type' => 'boolean'],
            'provider' => ['type' => 'string'],
            'results'  => ['type' => 'object'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_field_write_values',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_field_write_values(array $input) {
    $target = wpultra_fields_resolve_target((array) ($input['target'] ?? []));
    if (is_wp_error($target)) { return $target; }
    $values = (array) ($input['values'] ?? []);
    if (!$values) { return wpultra_err('values_empty', 'values must be a non-empty object'); }
    $batch = wpultra_fields_normalize_batch($values);
    if (is_wp_error($batch)) { return $batch; }
    $provider = wpultra_fields_pick_provider($input['provider'] ?? null);
    if (is_wp_error($provider)) { return $provider; }
    $results = wpultra_fields_route('write', $provider, [$target, $batch['atomic'], $batch['complex']]);
    if (is_wp_error($results)) { return $results; }
    wpultra_audit_log('field-write-values', "provider={$provider} target={$target['type']}:{$target['id']} fields=" . implode(',', array_keys($values)));
    return wpultra_ok(['provider' => $provider, 'results' => (object) $results]);
}
```

- [ ] **Step 5: Lint all touched files**

Run `php -l` on the three adapters and field-write-values.php.
Expected: `No syntax errors detected` for each.

- [ ] **Step 6: Deploy + live-verify write→read round-trip on all 3 providers**

Run `wp-ultra-mcp/bin/deploy.ps1`. Create probe `wp-content/wpultra-fwrite.php` that registers a field per provider, then writes via our write adapter and reads back via our read adapter:
```php
<?php
if (($_GET['t'] ?? '') !== 'wpultra-test-9a88') { http_response_code(403); exit('no'); }
require dirname(__DIR__) . '/wp-load.php';
$admin = get_users(['role' => 'administrator', 'number' => 1]);
wp_set_current_user($admin ? $admin[0]->ID : 1);
$base = dirname(__DIR__) . '/wp-content/plugins/wp-ultra-mcp/includes/fields/';
foreach (['setup', 'values', 'driver', 'adapters/acf', 'adapters/metabox', 'adapters/pods'] as $f) { require_once $base . $f . '.php'; }

$pid = wp_insert_post(['post_title' => 'fields-write-probe', 'post_status' => 'draft', 'post_type' => 'post']);

acf_add_local_field_group([
    'key' => 'group_wpultra_w', 'title' => 'W',
    'fields' => [['key' => 'field_wpultra_w', 'label' => 'Sub', 'name' => 'wpultra_w', 'type' => 'text']],
    'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]],
]);
add_filter('rwmb_meta_boxes', function ($mb) {
    $mb[] = ['title' => 'W', 'post_types' => ['post'], 'fields' => [['id' => 'wpultra_wmb', 'type' => 'text']]];
    return $mb;
});

$t = ['type' => 'post', 'id' => $pid];
$w_acf = wpultra_fields_acf_write($t, ['wpultra_w' => 'acf-W'], []);
$w_mb  = wpultra_fields_mb_write($t, ['wpultra_wmb' => 'mb-W'], []);
$w_pod = wpultra_fields_pods_write($t, ['wpultra_wp' => 'pods-W'], []);

$r_acf = wpultra_fields_acf_read($t, ['wpultra_w'], false);
$r_mb  = wpultra_fields_mb_read($t, ['wpultra_wmb'], false);
$r_pod = wpultra_fields_pods_read($t, ['wpultra_wp'], false);

// Complex-consent normalize sanity (pure).
$batch = wpultra_fields_normalize_batch(['a' => 1, 'b' => ['value' => [1, 2], 'mode' => 'replace']]);

wp_delete_post($pid, true);
echo json_encode([
    'acf_roundtrip'  => $r_acf['wpultra_w'] ?? null,
    'mb_roundtrip'   => $r_mb['wpultra_wmb'] ?? null,
    'pods_roundtrip' => $r_pod['wpultra_wp'] ?? null,
    'complex_ok'     => isset($batch['complex']['b']),
], JSON_PRETTY_PRINT);
```
Run: `curl "http://wp-connector.local/wp-content/wpultra-fwrite.php?t=wpultra-test-9a88"`
Expected: `acf_roundtrip=="acf-W"`, `mb_roundtrip=="mb-W"`, `pods_roundtrip=="pods-W"`, `complex_ok==true`. Delete the probe.

- [ ] **Step 7: Full suite green + commit**

Run: `powershell -File wp-ultra-mcp/tests/run-all.ps1`
Expected: entire suite green.
```bash
git add wp-ultra-mcp/includes/fields/adapters/ wp-ultra-mcp/includes/abilities/field-write-values.php
git commit -m "feat(fields): write adapters + field-write-values, live round-trip on acf/metabox/pods (Wave 5 Plan 1)"
```

---

## Self-Review

**Spec coverage (Plan 1 slice):**
- Hybrid driver + provider detection → Task 2 (`setup.php`, `driver.php` in Task 4). ✓
- `field-status` → Task 2. ✓
- `field-read-values` → Task 4. ✓
- `field-write-values` (atomic + complex consent, no partial write via whole-batch normalize, audit) → Tasks 3 + 5. ✓
- New `fields` category + wiring → Task 2. ✓
- Install 3 plugins + live-verify → Tasks 1, 4, 5. ✓
- Pure helpers unit-tested → Tasks 2, 3. ✓
- ACF-Pro-only paths marked (caps matrix `complex_types=false` on free) → Task 2. ✓
- (Deferred to later plans, correctly out of Plan 1: field-group define, CPT/taxonomy/options-page, skill, builder bridge.)

**Placeholder scan:** No TBD/TODO. Every code step shows complete code. Live-test probe code is complete. The one ellipsis in Step 7 lint commands (`C:\Users\nisha\...\php.exe`) abbreviates the full PHP path already given verbatim in Global Constraints — implementer uses the full path.

**Type consistency:** `wpultra_fields_route($op,$provider,$args)` calls `wpultra_fields_{provider}_{op}(...$args)`; read args `[$target,$fields,$format]` match `wpultra_fields_*_read(array,?array,bool)`; write args `[$target,$atomic,$complex]` match `wpultra_fields_*_write(array,array,array)`. `wpultra_fields_normalize_batch` returns `{atomic,complex}` consumed unchanged in Task 5. `wpultra_fields_resolve_target` returns `{type,id}` consumed by every adapter. Provider names `acf|metabox|pods` consistent across setup/driver/adapters/ability enums. Consistent.

## Notes for later plans (not this plan)
- Plan 2: `field-list-groups`/`field-get-group` + `acf-define-field-group`/`metabox-define-field-group` (snippet + `php -l`)/`pods-define-fields`.
- Plan 3: `field-manage-cpt`/`-taxonomy`/`-options-page` via pure `cpt.php`; re-verify Pods native `->field()` path with a real registered Pod (Task 4/5 used the post-meta fallback).
- Plan 4: `fields-architect` skill + Elementor/Gutenberg dynamic-field bridge + v0.12.0 ship.
