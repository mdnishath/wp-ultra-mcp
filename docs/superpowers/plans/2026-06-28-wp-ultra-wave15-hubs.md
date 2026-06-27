# WP-Ultra-MCP — Wave 1.5 (Hubs & Sandbox) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **This is Wave 1.5 of the program** (`docs/superpowers/specs/2026-06-27-wp-ultra-program.md`, Pillar 2B). It builds on the shipped, live-validated Wave 1 plugin. Build order: (1) Declarative ability engine + Ability Hub, (2) Skill Hub, (3) Sandbox safe-mode, (4) Memory Hub.

**Goal:** Turn WP-Ultra-MCP into an extensible platform: anyone can add custom **abilities** (declarative `.md`/JSON recipes — no PHP) and **skills** (`.md` upload) through admin Hubs, AI-written PHP runs in a crash-recovering sandbox, and memories are managed visually — the compounding "skills + abilities library" moat that beats Novamira.

**Architecture:** A declarative-ability **recipe** (frontmatter + a fenced ```json block) is parsed, validated, stored as a `wpultra_ability` CPT, and registered at runtime as a real MCP ability whose generic executor substitutes parameters into a `wp-cli | sql | php | http` recipe and dispatches to the existing Wave 1 primitives. Three admin Hubs (Ability/Skill/Memory) provide browse + upload + CRUD over the existing CPTs, following the Wave 1 Abilities-page UI pattern (cards, toggles, instant AJAX save). A sandbox runtime shim suspends AI-written PHP on fatal and surfaces a self-heal path.

**Tech Stack:** Same as Wave 1 — PHP 8.0+ (dev on bundled 8.2.30), WP 6.6+ Abilities API, vendored `wordpress/mcp-adapter`. Zero-dep PHP test harness. No new dependencies.

## Global Constraints

- All Wave 1 global constraints still apply (file headers `<?php`+`declare(strict_types=1)`+ABSPATH guard; abilities return array-or-WP_Error; `wpultra_permission_callback` gate; path-jail; parameterized SQL; `proc_open` arg-array; deploy via `bin/deploy.ps1` after every commit).
- Bundled PHP for all local commands: `C:/Users/nisha/AppData/Roaming/Local/lightning-services/php-8.2.30+1/bin/win64/php.exe` (referred to as `$PHP`).
- Local test site WP root: `C:/Users/nisha/Local Sites/wp-connector/app/public` (must be running in Local for live tests).
- Recipe ability namespace: registered as `wpultra/<slug>` (same as built-ins). The CPT for custom abilities is `wpultra_ability`; for skills `wpultra_skill` (already exists); for memory `wpultra_memory` (already exists).
- Recipe `run` types: `wp-cli` | `sql` | `php` | `http`. Recipe execution reuses the Wave 1 primitives (`wpultra_run_wp_cli`, `wpultra_execute_wp_query`, `wpultra_execute_php`) so all their safety (confirm-gate, sandbox, arg-array) is inherited. `http` uses `wp_remote_request`.
- Parameter substitution: `{param}` tokens. For `wp-cli` (arg array) and `http` (url) substitution is literal-but-isolated; for `sql` the tokens substitute into the **params array** (never the SQL string) to preserve `$wpdb->prepare`; for `php` the code is passed to `execute-php` (already `manage_options`-gated + sandbox).
- After each task, run the full PHP suite (`powershell -File E:\wp-connector\tests\run-all.ps1`) and lint; re-deploy with `bin/deploy.ps1`.

---

## File Structure

```
wp-ultra-mcp/includes/
  recipes/
    parser.php          recipe parse + validate (PURE)        — Task 1
    executor.php        param substitution + dispatch          — Task 2
    cpt.php             wpultra_ability CPT + runtime register  — Task 3
  abilities/
    ability-write.php   MCP ability: create/replace a recipe    — Task 5
    ability-get.php     MCP ability: read a recipe              — Task 5
    ability-delete.php  MCP ability: delete a recipe            — Task 5
  admin/
    ability-hub.php     Ability Hub UI (custom abilities + upload) — Task 4
    skill-hub.php       Skill Hub UI (.md upload/manage)         — Task 6
    memory-hub.php      Memory Hub UI                            — Task 8
  sandbox/
    runtime.php         sandbox loader shim + safe-mode gate     — Task 7
tests/
  recipe-parser.test.php      — Task 1
  recipe-executor.test.php    — Task 2
  sandbox.test.php            — Task 7
```

Commands run from `E:\wp-connector`. The main plugin file and `bootstrap-mcp.php` are modified to load the new subsystems.

---

### Task 1: Recipe parser + validator (pure, unit-tested)

**Files:**
- Create: `wp-ultra-mcp/includes/recipes/parser.php`
- Test: `tests/recipe-parser.test.php`

**Interfaces:**
- Consumes: `wpultra_err` (harness-stubbed).
- Produces:
  - `wpultra_recipe_parse(string $raw): array|WP_Error` — parse a recipe document: flat frontmatter (`name`, `description`, `category`, `run`) between `---` fences, plus the FIRST fenced ```json block decoded into the structured recipe (`input`, and one of `command`/`query`/`params`/`code`/`url`/`method`). Returns a normalized array `{name, description, category, run, input, recipe}` or `WP_Error`.
  - `wpultra_recipe_validate(array $r): true|WP_Error` — `name` matches `^[a-z0-9-]+$`; `run` ∈ {wp-cli,sql,php,http}; required recipe keys present per run type (`wp-cli`→`command` array; `sql`→`query` string (+ optional `params` array); `php`→`code` string; `http`→`url` string (+ optional `method`)); `input` is an object of `{type,required?}`.

- [ ] **Step 1: Write the failing test** `tests/recipe-parser.test.php`

```php
<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/recipes/parser.php';

$doc = "---\nname: woo-empty-cart\ndescription: Empty a cart\ncategory: woocommerce\nrun: wp-cli\n---\nNotes here.\n\n```json\n{ \"input\": { \"user_id\": { \"type\": \"integer\", \"required\": true } }, \"command\": [\"wc\", \"cart\", \"empty\", \"--user={user_id}\"] }\n```\n";

it('parses frontmatter + json recipe', function () use ($doc) {
    $r = wpultra_recipe_parse($doc);
    assert_true(!is_wp_error($r), 'parsed');
    assert_eq('woo-empty-cart', $r['name']);
    assert_eq('wp-cli', $r['run']);
    assert_eq('woocommerce', $r['category']);
    assert_eq(true, $r['input']['user_id']['required']);
    assert_eq(['wc', 'cart', 'empty', '--user={user_id}'], $r['recipe']['command']);
});
it('validate accepts a good wp-cli recipe', function () use ($doc) {
    assert_true(wpultra_recipe_validate(wpultra_recipe_parse($doc)) === true, 'valid');
});
it('validate rejects unknown run type', function () {
    $r = ['name' => 'x', 'description' => '', 'category' => '', 'run' => 'bogus', 'input' => [], 'recipe' => []];
    assert_wp_error(wpultra_recipe_validate($r), 'bad run');
});
it('validate rejects bad slug', function () {
    $r = ['name' => 'Bad Slug', 'description' => '', 'category' => '', 'run' => 'php', 'input' => [], 'recipe' => ['code' => '1;']];
    assert_wp_error(wpultra_recipe_validate($r), 'bad slug');
});
it('validate requires command for wp-cli', function () {
    $r = ['name' => 'x', 'description' => '', 'category' => '', 'run' => 'wp-cli', 'input' => [], 'recipe' => []];
    assert_wp_error(wpultra_recipe_validate($r), 'missing command');
});
it('parse errors on invalid json block', function () {
    $bad = "---\nname: x\nrun: php\n---\n```json\n{not json}\n```";
    assert_wp_error(wpultra_recipe_parse($bad), 'bad json');
});

run_tests();
```

- [ ] **Step 2: Run to verify failure**

Run: `& $PHP E:\wp-connector\tests\recipe-parser.test.php`
Expected: FAIL — `wpultra_recipe_parse` undefined.

- [ ] **Step 3: Write `includes/recipes/parser.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/** Parse a declarative-ability recipe document. Returns normalized array or WP_Error. */
function wpultra_recipe_parse(string $raw) {
    $name = ''; $description = ''; $category = 'custom'; $run = '';
    $body = $raw;
    if (preg_match('/^\s*---\s*\n(.*?)\n---\s*\n?(.*)$/s', $raw, $m)) {
        $body = $m[2];
        foreach (explode("\n", $m[1]) as $line) {
            if (!preg_match('/^\s*([A-Za-z_]+)\s*:\s*(.*)$/', $line, $kv)) { continue; }
            $key = strtolower($kv[1]); $val = trim($kv[2]);
            if ($key === 'name') { $name = $val; }
            elseif ($key === 'description') { $description = $val; }
            elseif ($key === 'category') { $category = $val !== '' ? $val : 'custom'; }
            elseif ($key === 'run') { $run = strtolower($val); }
        }
    }
    // Extract the first ```json fenced block.
    $structured = [];
    if (preg_match('/```json\s*\n(.*?)\n```/s', $body, $jm)) {
        $decoded = json_decode(trim($jm[1]), true);
        if (!is_array($decoded)) {
            return wpultra_err('recipe_bad_json', 'The ```json recipe block is not valid JSON.');
        }
        $structured = $decoded;
    }
    $input = is_array($structured['input'] ?? null) ? $structured['input'] : [];
    $recipe = $structured;
    unset($recipe['input']);
    return [
        'name' => $name, 'description' => $description, 'category' => $category, 'run' => $run,
        'input' => $input, 'recipe' => $recipe,
    ];
}

function wpultra_recipe_validate(array $r) {
    if (!preg_match('/^[a-z0-9-]+$/', (string) ($r['name'] ?? ''))) {
        return wpultra_err('recipe_bad_name', 'Recipe name must be lowercase letters, digits, and dashes.');
    }
    $run = (string) ($r['run'] ?? '');
    if (!in_array($run, ['wp-cli', 'sql', 'php', 'http'], true)) {
        return wpultra_err('recipe_bad_run', "run must be one of: wp-cli, sql, php, http.");
    }
    $recipe = is_array($r['recipe'] ?? null) ? $r['recipe'] : [];
    if ($run === 'wp-cli' && !is_array($recipe['command'] ?? null)) {
        return wpultra_err('recipe_missing_command', "wp-cli recipes require a 'command' array.");
    }
    if ($run === 'sql' && !is_string($recipe['query'] ?? null)) {
        return wpultra_err('recipe_missing_query', "sql recipes require a 'query' string.");
    }
    if ($run === 'php' && !is_string($recipe['code'] ?? null)) {
        return wpultra_err('recipe_missing_code', "php recipes require a 'code' string.");
    }
    if ($run === 'http' && !is_string($recipe['url'] ?? null)) {
        return wpultra_err('recipe_missing_url', "http recipes require a 'url' string.");
    }
    if (!is_array($r['input'] ?? null)) {
        return wpultra_err('recipe_bad_input', "'input' must be an object of parameter definitions.");
    }
    return true;
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `& $PHP E:\wp-connector\tests\recipe-parser.test.php`
Expected: all 6 tests `PASS`.

- [ ] **Step 5: Lint + commit**

Run: `& $PHP -l E:\wp-connector\wp-ultra-mcp\includes\recipes\parser.php` → `No syntax errors detected`.
```bash
git add wp-ultra-mcp/includes/recipes/parser.php tests/recipe-parser.test.php
git commit -m "feat(plugin): declarative recipe parser + validator"
```

---

### Task 2: Recipe executor (param substitution + dispatch)

**Files:**
- Create: `wp-ultra-mcp/includes/recipes/executor.php`
- Test: `tests/recipe-executor.test.php`

**Interfaces:**
- Consumes: `wpultra_ok`, `wpultra_err`; Wave 1 primitives `wpultra_run_wp_cli`, `wpultra_execute_wp_query`, `wpultra_execute_php` (stubbed in tests).
- Produces:
  - `wpultra_recipe_subst_scalar(string $tpl, array $input): string` — replace `{key}` with `(string)$input[key]` (missing → empty). Pure.
  - `wpultra_recipe_subst_array(array $tpl, array $input): array` — map subst over each string element. Pure.
  - `wpultra_recipe_execute(array $parsed, array $input): array|WP_Error` — validate required inputs present, then dispatch by `run`: `wp-cli`→`wpultra_run_wp_cli(['args'=>subst(command)])`; `sql`→`wpultra_execute_wp_query(['sql'=>query,'params'=>subst(params),'confirm'=>input.confirm])`; `php`→`wpultra_execute_php(['code'=>subst(code)])`; `http`→`wp_remote_request(subst(url), …)` returning `{status, body}`.

- [ ] **Step 1: Write the failing test** `tests/recipe-executor.test.php`

```php
<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
// Stub Wave 1 primitives to capture what the executor dispatches.
$GLOBALS['__last'] = null;
function wpultra_run_wp_cli($i) { $GLOBALS['__last'] = ['cli', $i]; return ['success' => true, 'exit_code' => 0, 'stdout' => 'ok', 'stderr' => '']; }
function wpultra_execute_wp_query($i) { $GLOBALS['__last'] = ['sql', $i]; return ['success' => true, 'verb' => 'SELECT', 'rows' => [], 'row_count' => 0]; }
function wpultra_execute_php($i) { $GLOBALS['__last'] = ['php', $i]; return ['success' => true, 'return_value' => 1, 'output' => '']; }
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/recipes/executor.php';

it('substitutes scalar tokens', function () {
    assert_eq('--user=42', wpultra_recipe_subst_scalar('--user={user_id}', ['user_id' => 42]));
});
it('substitutes array tokens', function () {
    assert_eq(['wc', '--user=42'], wpultra_recipe_subst_array(['wc', '--user={user_id}'], ['user_id' => 42]));
});
it('wp-cli recipe dispatches subst args to run_wp_cli', function () {
    $parsed = ['run' => 'wp-cli', 'input' => ['user_id' => ['type' => 'integer', 'required' => true]],
        'recipe' => ['command' => ['wc', 'cart', 'empty', '--user={user_id}']]];
    $r = wpultra_recipe_execute($parsed, ['user_id' => 7]);
    assert_true($r['success'], 'ok');
    assert_eq('cli', $GLOBALS['__last'][0]);
    assert_eq(['wc', 'cart', 'empty', '--user=7'], $GLOBALS['__last'][1]['args']);
});
it('sql recipe substitutes into params, not the query string', function () {
    $parsed = ['run' => 'sql', 'input' => ['id' => ['type' => 'integer', 'required' => true]],
        'recipe' => ['query' => 'SELECT * FROM wp_posts WHERE ID = %d', 'params' => ['{id}']]];
    wpultra_recipe_execute($parsed, ['id' => 5]);
    assert_eq('sql', $GLOBALS['__last'][0]);
    assert_eq('SELECT * FROM wp_posts WHERE ID = %d', $GLOBALS['__last'][1]['sql']);
    assert_eq(['5'], $GLOBALS['__last'][1]['params']);
});
it('rejects missing required input', function () {
    $parsed = ['run' => 'php', 'input' => ['x' => ['type' => 'string', 'required' => true]], 'recipe' => ['code' => 'return 1;']];
    assert_wp_error(wpultra_recipe_execute($parsed, []), 'missing input');
});

run_tests();
```

- [ ] **Step 2: Run to verify failure**

Run: `& $PHP E:\wp-connector\tests\recipe-executor.test.php`
Expected: FAIL — `wpultra_recipe_subst_scalar` undefined.

- [ ] **Step 3: Write `includes/recipes/executor.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

function wpultra_recipe_subst_scalar(string $tpl, array $input): string {
    return preg_replace_callback('/\{([a-zA-Z0-9_]+)\}/', function ($m) use ($input) {
        return array_key_exists($m[1], $input) ? (string) $input[$m[1]] : '';
    }, $tpl);
}

function wpultra_recipe_subst_array(array $tpl, array $input): array {
    $out = [];
    foreach ($tpl as $el) { $out[] = is_string($el) ? wpultra_recipe_subst_scalar($el, $input) : $el; }
    return $out;
}

function wpultra_recipe_execute(array $parsed, array $input) {
    foreach ((array) ($parsed['input'] ?? []) as $key => $def) {
        if (!empty($def['required']) && !array_key_exists($key, $input)) {
            return wpultra_err('recipe_missing_input', "Required input '$key' is missing.");
        }
    }
    $run = (string) ($parsed['run'] ?? '');
    $recipe = (array) ($parsed['recipe'] ?? []);

    if ($run === 'wp-cli') {
        $args = wpultra_recipe_subst_array((array) ($recipe['command'] ?? []), $input);
        return wpultra_run_wp_cli(['args' => $args]);
    }
    if ($run === 'sql') {
        $params = wpultra_recipe_subst_array((array) ($recipe['params'] ?? []), $input);
        return wpultra_execute_wp_query([
            'sql' => (string) ($recipe['query'] ?? ''),
            'params' => $params,
            'confirm' => ($input['confirm'] ?? false) === true,
        ]);
    }
    if ($run === 'php') {
        $code = wpultra_recipe_subst_scalar((string) ($recipe['code'] ?? ''), $input);
        return wpultra_execute_php(['code' => $code]);
    }
    if ($run === 'http') {
        $url = wpultra_recipe_subst_scalar((string) ($recipe['url'] ?? ''), $input);
        $method = strtoupper((string) ($recipe['method'] ?? 'GET'));
        $resp = wp_remote_request($url, ['method' => $method, 'timeout' => 20]);
        if (is_wp_error($resp)) { return $resp; }
        return wpultra_ok(['status' => wp_remote_retrieve_response_code($resp), 'body' => wp_remote_retrieve_body($resp)]);
    }
    return wpultra_err('recipe_bad_run', "Unknown run type: $run");
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `& $PHP E:\wp-connector\tests\recipe-executor.test.php`
Expected: 5 tests `PASS`.

- [ ] **Step 5: Lint + commit**

Run: `& $PHP -l ...executor.php`.
```bash
git add wp-ultra-mcp/includes/recipes/executor.php tests/recipe-executor.test.php
git commit -m "feat(plugin): recipe executor — param substitution + safe dispatch"
```

---

### Task 3: `wpultra_ability` CPT + runtime registration of recipe abilities

**Files:**
- Create: `wp-ultra-mcp/includes/recipes/cpt.php`
- Modify: `wp-ultra-mcp/includes/bootstrap-mcp.php` (load recipes in `wpultra_load_abilities`)

**Interfaces:**
- Consumes: parser (`wpultra_recipe_parse`/`validate`), executor (`wpultra_recipe_execute`), `wpultra_ok/err`, `wpultra_permission_callback`.
- Produces:
  - CPT `wpultra_ability` (private). `wpultra_recipe_all(): array` — list of `{slug, name, description, category, run, raw}` from the CPT.
  - `wpultra_recipe_register_all(): void` — on `wp_abilities_api_init` (priority 600), parse each CPT post and `wp_register_ability('wpultra/'.$slug, [...])` with a closure executor; skips invalid recipes (logged).
  - `wpultra_recipe_input_schema(array $input): array` — convert recipe `input` defs to a JSON-Schema `input_schema`.

- [ ] **Step 1: Write `includes/recipes/cpt.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }
require_once __DIR__ . '/parser.php';
require_once __DIR__ . '/executor.php';

add_action('init', function () {
    register_post_type('wpultra_ability', [
        'public' => false, 'show_ui' => false, 'show_in_rest' => false,
        'supports' => ['title', 'editor', 'excerpt', 'revisions'], 'rewrite' => false,
    ]);
});

function wpultra_recipe_all(): array {
    $posts = get_posts(['post_type' => 'wpultra_ability', 'post_status' => 'publish', 'numberposts' => 500]);
    $out = [];
    foreach ($posts as $p) {
        $parsed = wpultra_recipe_parse($p->post_content);
        $out[] = [
            'slug' => $p->post_name, 'post_id' => $p->ID,
            'name' => is_wp_error($parsed) ? $p->post_name : $parsed['name'],
            'description' => $p->post_excerpt,
            'category' => is_wp_error($parsed) ? 'custom' : $parsed['category'],
            'run' => is_wp_error($parsed) ? '' : $parsed['run'],
            'raw' => $p->post_content,
        ];
    }
    return $out;
}

function wpultra_recipe_input_schema(array $input): array {
    $props = []; $required = [];
    foreach ($input as $key => $def) {
        $type = (string) ($def['type'] ?? 'string');
        $props[$key] = ['type' => in_array($type, ['string', 'integer', 'number', 'boolean', 'array', 'object'], true) ? $type : 'string'];
        if (!empty($def['required'])) { $required[] = $key; }
    }
    $schema = ['type' => 'object', 'properties' => (object) $props];
    if ($required) { $schema['required'] = $required; }
    return $schema;
}

function wpultra_recipe_register_all(): void {
    if (!function_exists('wp_register_ability')) { return; }
    foreach (wpultra_recipe_all() as $row) {
        $parsed = wpultra_recipe_parse($row['raw']);
        if (is_wp_error($parsed) || wpultra_recipe_validate($parsed) !== true) { continue; }
        $slug = $row['slug'];
        wp_register_ability('wpultra/' . $slug, [
            'label' => $parsed['name'] !== '' ? $parsed['name'] : $slug,
            'description' => $parsed['description'] !== '' ? $parsed['description'] : ('Custom ability: ' . $slug),
            'category' => 'custom',
            'input_schema' => wpultra_recipe_input_schema($parsed['input']),
            'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean']], 'required' => ['success']],
            'execute_callback' => function (array $in = []) use ($parsed) { return wpultra_recipe_execute($parsed, $in); },
            'permission_callback' => 'wpultra_permission_callback',
            'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'],
                'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false]],
        ]);
    }
}
```

- [ ] **Step 2: Wire into the loader + register a `custom` category**

In `includes/bootstrap-mcp.php`:
- In `wpultra_register_categories()` `$cats`, add `'custom' => 'User-defined declarative abilities.'`.
- In `wpultra_load_abilities()`, after the skills block, add:
```php
if (is_readable(WPULTRA_DIR . 'includes/recipes/cpt.php')) {
    require_once WPULTRA_DIR . 'includes/recipes/cpt.php';
    add_action('wp_abilities_api_init', 'wpultra_recipe_register_all', 600);
}
```

- [ ] **Step 3: Lint + structural test**

Create `tests/recipe-cpt.test.php`:
```php
<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
function wpultra_permission_callback() { return true; }
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/recipes/cpt.php';

it('input schema converts defs', function () {
    $s = wpultra_recipe_input_schema(['user_id' => ['type' => 'integer', 'required' => true], 'note' => ['type' => 'string']]);
    assert_eq('object', $s['type']);
    assert_eq(['user_id'], $s['required']);
});

run_tests();
```
Run: `& $PHP E:\wp-connector\tests\recipe-cpt.test.php` → `1 passed`. Lint cpt.php + bootstrap-mcp.php. Re-run `tests\bootstrap.test.php` (still passes).

- [ ] **Step 4: Commit**

```bash
git add wp-ultra-mcp/includes/recipes/cpt.php wp-ultra-mcp/includes/bootstrap-mcp.php tests/recipe-cpt.test.php
git commit -m "feat(plugin): wpultra_ability CPT + runtime registration of recipe abilities"
```

---

### Task 4: Ability Hub admin page (custom abilities + .md/JSON upload)

**Files:**
- Create: `wp-ultra-mcp/includes/admin/ability-hub.php`
- Modify: `wp-ultra-mcp/wp-ultra-mcp.php` (require it when admin), `includes/admin/connect-page.php` (add submenu "Ability Hub")

**Interfaces:**
- Consumes: `wpultra_recipe_all`, `wpultra_recipe_parse/validate`, WP post APIs.
- Produces: `wpultra_ability_hub_render()`; `admin_post_wpultra_save_recipe` (create/replace from textarea or uploaded file); `admin_post_wpultra_delete_recipe`.

This page reuses the Wave 1 Abilities-page CSS classes (`.wpu-card`, `.wpu-row`, shadows) — follow that file's visual pattern. Structure:
1. A **"+ New custom ability"** card with: a `<textarea name="recipe">` pre-filled with a commented example recipe, OR a file input (`<input type="file" accept=".md,.json,.txt">`) — on submit, the handler reads the file/textarea, parses+validates, and on success creates/updates a `wpultra_ability` CPT post (title/name = recipe `name`, excerpt = description, content = raw). On validation error, re-render with the `WP_Error` message.
2. A **"Custom abilities"** list (cards) of existing recipes: each shows name, slug `wpultra/<slug>`, category, `run` badge, and **Edit** (loads raw into the textarea) + **Delete** buttons. Empty-state message when none.

- [ ] **Step 1: Write `includes/admin/ability-hub.php`** — handlers + render. Handlers MUST: `current_user_can('manage_options')` + `check_admin_referer`. Save handler:
```php
add_action('admin_post_wpultra_save_recipe', function () {
    if (!current_user_can('manage_options') || !check_admin_referer('wpultra_save_recipe')) { wp_die('forbidden'); }
    $raw = (string) ($_POST['recipe'] ?? '');
    if (!empty($_FILES['recipe_file']['tmp_name']) && is_uploaded_file($_FILES['recipe_file']['tmp_name'])) {
        $raw = (string) file_get_contents($_FILES['recipe_file']['tmp_name']);
    }
    $parsed = wpultra_recipe_parse($raw);
    $err = is_wp_error($parsed) ? $parsed : (wpultra_recipe_validate($parsed) === true ? null : wpultra_recipe_validate($parsed));
    if ($err) { set_transient('wpultra_recipe_err_' . get_current_user_id(), $err->get_error_message(), 60); wp_safe_redirect(admin_url('admin.php?page=wpultra-ability-hub&err=1')); exit; }
    $slug = sanitize_title($parsed['name']);
    $existing = get_page_by_path($slug, OBJECT, 'wpultra_ability');
    $arr = ['post_type' => 'wpultra_ability', 'post_status' => 'publish', 'post_title' => $slug, 'post_name' => $slug, 'post_excerpt' => $parsed['description'], 'post_content' => $raw];
    if ($existing) { $arr['ID'] = $existing->ID; }
    wp_insert_post($arr, true);
    wp_safe_redirect(admin_url('admin.php?page=wpultra-ability-hub&saved=1')); exit;
});
```
Delete handler mirrors `admin_post_wpultra_delete_recipe` with nonce, `wp_delete_post((int)$_POST['post_id'], true)`. Render lists `wpultra_recipe_all()` and shows the example template in the textarea. (Full HTML/CSS follows the Abilities-page pattern — cards with shadows, a monospace textarea, primary buttons; escape all output.)

- [ ] **Step 2: Register the submenu**

In `includes/admin/connect-page.php` `admin_menu` callback, add:
```php
add_submenu_page('wpultra', 'Ability Hub', 'Ability Hub', 'manage_options', 'wpultra-ability-hub', 'wpultra_ability_hub_render');
```
And in `wp-ultra-mcp.php` admin block, `require_once WPULTRA_DIR . 'includes/admin/ability-hub.php';`.

- [ ] **Step 3: Lint + live smoke**

Lint ability-hub.php. Deploy (`bin/deploy.ps1`). In wp-admin (Local site running) → WP-Ultra-MCP → Ability Hub: paste the example recipe (a `php` recipe returning a constant), Save → confirm it appears in the list; then over MCP run `discover-abilities` and confirm `wpultra/<your-recipe>` appears and `execute-ability` runs it. Document the result.

- [ ] **Step 4: Commit**

```bash
git add wp-ultra-mcp/includes/admin/ability-hub.php wp-ultra-mcp/includes/admin/connect-page.php wp-ultra-mcp/wp-ultra-mcp.php
git commit -m "feat(plugin): Ability Hub — create/upload declarative custom abilities"
```

---

### Task 5: MCP abilities so the AI can manage recipes (ability-write/get/delete)

**Files:**
- Create: `wp-ultra-mcp/includes/abilities/ability-write.php`, `ability-get.php`, `ability-delete.php`
- Modify: `includes/bootstrap-mcp.php` (`wpultra_ability_files()` — append the 3 slugs)

**Interfaces:**
- Consumes: parser/validate, `wpultra_ok/err`, WP post APIs.
- Produces: `wpultra_ability_write/get/delete` callbacks (category `custom`), registered via the Step-0 Ability Skeleton (Wave 1 plan Task 4).

- [ ] **Step 1: Append the 3 slugs** to `wpultra_ability_files()` in bootstrap-mcp.php: `'ability-write', 'ability-get', 'ability-delete'` (list becomes 23). Update `tests/bootstrap.test.php` count assertion `20` → `23` and add `assert_true(in_array('ability-write', $files, true), 'has recipe crud');`.

- [ ] **Step 2: Write the 3 ability files** (skeleton; category `custom`):

`ability-write.php` (SLUG `ability-write`, input `{recipe:string(req)}` — the raw `.md`/json recipe doc, output `{success,slug}`, destructive):
```php
function wpultra_ability_write(array $input) {
    $raw = (string) ($input['recipe'] ?? '');
    $parsed = wpultra_recipe_parse($raw);
    if (is_wp_error($parsed)) { return $parsed; }
    $valid = wpultra_recipe_validate($parsed);
    if (is_wp_error($valid)) { return $valid; }
    $slug = sanitize_title($parsed['name']);
    $existing = get_page_by_path($slug, OBJECT, 'wpultra_ability');
    $arr = ['post_type' => 'wpultra_ability', 'post_status' => 'publish', 'post_title' => $slug, 'post_name' => $slug, 'post_excerpt' => $parsed['description'], 'post_content' => $raw];
    if ($existing) { $arr['ID'] = $existing->ID; }
    $id = wp_insert_post($arr, true);
    if (is_wp_error($id)) { return $id; }
    return wpultra_ok(['slug' => $slug, 'post_id' => (int) $id]);
}
```
`ability-get.php` (SLUG `ability-get`, input `{slug:string(req)}`, output `{success,slug,recipe}`, readonly) — returns the raw recipe doc via `get_page_by_path`.
`ability-delete.php` (SLUG `ability-delete`, input `{slug:string(req)}`, output `{success,slug,deleted}`, destructive/idempotent) — `wp_delete_post`, idempotent.
Each requires `require_once WPULTRA_DIR . 'includes/recipes/parser.php';` where it uses the parser.

- [ ] **Step 3: Lint + bootstrap test + full suite**

Run `tests/bootstrap.test.php` (now asserts 23) and `tests/run-all.ps1`. Lint the 3 files.

- [ ] **Step 4: Commit**

```bash
git add wp-ultra-mcp/includes/abilities/ability-*.php wp-ultra-mcp/includes/bootstrap-mcp.php tests/bootstrap.test.php
git commit -m "feat(plugin): MCP recipe-management abilities (ability-write/get/delete)"
```

---

### Task 6: Skill Hub admin page (.md upload/manage)

**Files:**
- Create: `wp-ultra-mcp/includes/admin/skill-hub.php`
- Modify: `wp-ultra-mcp.php` (require when admin), `connect-page.php` (submenu "Skill Hub")

**Interfaces:**
- Consumes: `wpultra_skill_all` (Wave 1 skills/sources.php), `wpultra_skill_parse_frontmatter`/`render_md`, WP post APIs.
- Produces: `wpultra_skill_hub_render()`; `admin_post_wpultra_save_skill` (create/replace from textarea or uploaded `.md`); `admin_post_wpultra_delete_skill`; per-skill `enable_prompt`/`enable_agentic` toggle via reuse of an AJAX handler.

This page mirrors the Ability Hub + Abilities-page visuals:
1. **"+ Upload / new skill"** card: file input (`.md`) OR a textarea pre-filled with a skill template (frontmatter `name/description/enable_prompt/enable_agentic` + body). On save: parse frontmatter, store as `wpultra_skill` CPT (mirrors Wave 1 `skill-write` logic), set the two meta flags.
2. **"Skills"** list cards grouped built-in vs user: each shows name + description + two toggle switches (Prompt / Agentic) + **Export** (download `.md` via `wpultra_skill_render_md`) + **Edit/Delete** (user skills only; built-ins are read-only — Export only). Reuse the `.wpu-switch` CSS.

- [ ] **Step 1: Write `includes/admin/skill-hub.php`** — handlers (nonce + cap) + render. The save handler reuses the Wave 1 `skill-write` shape (sanitize_title slug, `wp_insert_post`, `update_post_meta` for `_enable_prompt`/`_enable_agentic`). Export = an `admin_post_wpultra_export_skill` that sets `Content-Disposition: attachment; filename=<slug>.md` and echoes `wpultra_skill_render_md(...)`. Built-in skills (source `built-in`) cannot be edited/deleted (guard in handlers).

- [ ] **Step 2: Register submenu + require**, same pattern as Task 4 Step 2 (`page=wpultra-skill-hub`, render `wpultra_skill_hub_render`).

- [ ] **Step 3: Lint + live smoke** — deploy; upload a `.md` skill in the Hub; confirm it appears, the Prompt/Agentic toggles persist, Export downloads a valid `.md`, and over MCP `skill-get <slug>` returns the body.

- [ ] **Step 4: Commit**

```bash
git add wp-ultra-mcp/includes/admin/skill-hub.php wp-ultra-mcp/includes/admin/connect-page.php wp-ultra-mcp/wp-ultra-mcp.php
git commit -m "feat(plugin): Skill Hub — upload/manage/export .md skills"
```

---

### Task 7: Sandbox runtime + crash-recovery safe-mode

**Files:**
- Create: `wp-ultra-mcp/includes/sandbox/runtime.php`
- Modify: `wp-ultra-mcp.php` (require early), `includes/abilities/execute-php.php` (respect safe mode)
- Test: `tests/sandbox.test.php`

**Interfaces:**
- Consumes: `wpultra_ok/err`.
- Produces:
  - `wpultra_sandbox_dir(): string` (== `WPULTRA_SANDBOX_DIR`), `wpultra_sandbox_crashed(): bool` (true if `<sandbox>/.crashed` exists), `wpultra_sandbox_mark_crashed(string $detail): void`, `wpultra_sandbox_clear(): void`.
  - A shutdown handler that, when a fatal occurs while a sandbox file is executing, writes `.crashed` with the error. A guard `wpultra_sandbox_guard(callable $fn)` that wraps risky execution.

- [ ] **Step 1: Write the failing test** `tests/sandbox.test.php`

```php
<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
$tmp = sys_get_temp_dir() . '/wpu_sb_' . uniqid();
mkdir($tmp, 0777, true);
if (!defined('ABSPATH')) { define('ABSPATH', $tmp . '/'); }
if (!defined('WPULTRA_SANDBOX_DIR')) { define('WPULTRA_SANDBOX_DIR', $tmp . '/sandbox/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/sandbox/runtime.php';

it('starts not crashed', function () { assert_eq(false, wpultra_sandbox_crashed()); });
it('mark + detect + clear crashed', function () {
    wpultra_sandbox_mark_crashed('boom in widget.php');
    assert_eq(true, wpultra_sandbox_crashed());
    wpultra_sandbox_clear();
    assert_eq(false, wpultra_sandbox_crashed());
});

run_tests();
```

- [ ] **Step 2: Run to verify failure**

Run: `& $PHP E:\wp-connector\tests\sandbox.test.php`
Expected: FAIL — `wpultra_sandbox_crashed` undefined.

- [ ] **Step 3: Write `includes/sandbox/runtime.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

function wpultra_sandbox_dir(): string { return defined('WPULTRA_SANDBOX_DIR') ? WPULTRA_SANDBOX_DIR : (WP_CONTENT_DIR . '/wpultra-sandbox/'); }
function wpultra_sandbox_sentinel(): string { return rtrim(wpultra_sandbox_dir(), '/\\') . '/.crashed'; }
function wpultra_sandbox_crashed(): bool { return file_exists(wpultra_sandbox_sentinel()); }

function wpultra_sandbox_mark_crashed(string $detail): void {
    $dir = wpultra_sandbox_dir();
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    @file_put_contents(wpultra_sandbox_sentinel(), $detail);
}

function wpultra_sandbox_clear(): void { @unlink(wpultra_sandbox_sentinel()); }

/** Run $fn; if it triggers a fatal, a registered shutdown handler records .crashed. */
function wpultra_sandbox_guard(callable $fn) {
    $GLOBALS['__wpultra_sb_running'] = true;
    register_shutdown_function(function () {
        $e = error_get_last();
        if (!empty($GLOBALS['__wpultra_sb_running']) && $e && in_array($e['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR], true)) {
            wpultra_sandbox_mark_crashed($e['message'] . ' @ ' . ($e['file'] ?? '?') . ':' . ($e['line'] ?? '?'));
        }
    });
    try { return $fn(); }
    finally { $GLOBALS['__wpultra_sb_running'] = false; }
}
```

- [ ] **Step 4: Wire safe-mode into execute-php + admin notice**

- In `wp-ultra-mcp.php` (after constants), `require_once WPULTRA_DIR . 'includes/sandbox/runtime.php';` and add an admin notice + a `clear safe mode` admin-post handler:
```php
add_action('admin_notices', function () {
    if (function_exists('wpultra_sandbox_crashed') && wpultra_sandbox_crashed()) {
        $url = wp_nonce_url(admin_url('admin-post.php?action=wpultra_clear_safe'), 'wpultra_clear_safe');
        echo '<div class="notice notice-error"><p><strong>WP-Ultra-MCP safe mode:</strong> AI-written sandbox code crashed and is suspended. <a href="' . esc_url($url) . '">Clear safe mode</a> after fixing.</p></div>';
    }
});
add_action('admin_post_wpultra_clear_safe', function () {
    if (current_user_can('manage_options') && check_admin_referer('wpultra_clear_safe')) { wpultra_sandbox_clear(); }
    wp_safe_redirect(admin_url('admin.php?page=wpultra')); exit;
});
```
- In `includes/abilities/execute-php.php`, at the top of `wpultra_execute_php`, after the empty-code check, add:
```php
if (function_exists('wpultra_sandbox_crashed') && wpultra_sandbox_crashed()) {
    return wpultra_err('safe_mode', 'Sandbox safe mode is active after a crash. Read the debug log, fix the offending sandbox file, then clear safe mode in wp-admin.');
}
```
Wrap the `eval` call in `wpultra_sandbox_guard(function () use ($code) { return eval($code); })` so a fatal records the sentinel. (Adjust the existing try/catch to call through the guard.)

- [ ] **Step 5: Run the test + full suite + lint**

Run `tests/sandbox.test.php` (2 pass), `tests/run-all.ps1`, lint runtime.php + execute-php.php + wp-ultra-mcp.php.

- [ ] **Step 6: Commit**

```bash
git add wp-ultra-mcp/includes/sandbox/runtime.php wp-ultra-mcp/includes/abilities/execute-php.php wp-ultra-mcp/wp-ultra-mcp.php tests/sandbox.test.php
git commit -m "feat(plugin): sandbox crash-recovery safe-mode (suspend AI PHP on fatal)"
```

---

### Task 8: Memory Hub admin page

**Files:**
- Create: `wp-ultra-mcp/includes/admin/memory-hub.php`
- Modify: `wp-ultra-mcp.php` (require when admin), `connect-page.php` (submenu "Memory Hub")

**Interfaces:**
- Consumes: `wpultra_memory_shape` (Wave 1 memory/cpt.php), WP post APIs.
- Produces: `wpultra_memory_hub_render()`; `admin_post_wpultra_save_memory` (add/edit); `admin_post_wpultra_delete_memory`.

Page (Abilities-page visuals):
1. **"+ New memory"** card: fields `name`, `type` (select user/feedback/project/reference), `description`, `content` (textarea). Save → `wp_insert_post` to `wpultra_memory` + `_wpultra_memory_type` meta (mirror Wave 1 `memory-save`).
2. **Memories list** grouped by type, each card: name, type badge, description, a **View/Edit** (loads into the form) + **Delete**. A type filter at top. Empty-state message.

- [ ] **Step 1: Write `includes/admin/memory-hub.php`** — handlers (nonce+cap) + render, reusing the Wave 1 memory data model (`require_once WPULTRA_DIR . 'includes/memory/cpt.php';`). Save validates `type ∈ {user,feedback,project,reference}` and non-empty name (same as `memory-save`). Delete = `wp_delete_post(id, true)`.

- [ ] **Step 2: Register submenu + require** (`page=wpultra-memory-hub`, render `wpultra_memory_hub_render`).

- [ ] **Step 3: Lint + live smoke** — deploy; add a memory in the Hub; confirm it lists; over MCP `memory-list` returns it; delete from the Hub; confirm gone.

- [ ] **Step 4: Commit**

```bash
git add wp-ultra-mcp/includes/admin/memory-hub.php wp-ultra-mcp/includes/admin/connect-page.php wp-ultra-mcp/wp-ultra-mcp.php
git commit -m "feat(plugin): Memory Hub — view/add/edit/delete persistent memories"
```

---

## Self-Review

**Spec coverage (Pillar 2B):**
- Skill Hub (browse/upload .md/export/toggle) → Task 6. ✅
- Ability Hub + declarative `.md`/JSON custom abilities (recipe parser/executor/CPT/registration/UI/CRUD) → Tasks 1–5. ✅
- Memory Hub → Task 8. ✅
- Sandbox + crash-recovery safe-mode → Task 7. ✅
- Recipe run types wp-cli|sql|php|http reusing Wave 1 primitives with inherited safety → Task 2. ✅
- Ever-growing library principle → built-ins continue to be added per wave (not a single task; the recipe engine + Hubs make it open-ended). ✅

**Placeholder scan:** Engine tasks (1–3,7) carry complete code + tests. The admin-UI tasks (4,6,8) specify exact handlers/menus/data-models and explicitly reuse the already-built Abilities-page CSS pattern rather than re-pasting ~200 lines of identical card/toggle HTML — the handler logic (the non-boilerplate part) is given in full; the implementer follows `includes/admin/abilities-page.php` for the visual shell. This is a deliberate DRY reuse of a shipped, reviewed pattern, not a placeholder.

**Type/name consistency:** `wpultra_recipe_parse/validate` (Task 1) consumed by executor-not (executor takes the already-parsed array), by cpt.php registration (Task 3), and by ability-write/Hub (Tasks 4,5). `wpultra_recipe_execute(parsed,input)` (Task 2) called by the closure in cpt.php (Task 3). `wpultra_ability` CPT (Task 3) used by Hub (4) + CRUD (5). `wpultra_sandbox_crashed/mark_crashed/clear/guard` (Task 7) consistent across runtime + execute-php wiring. `wpultra_ability_files()` count 20→23 (Task 5) matches the bootstrap test update.

**Security:** recipe `sql` substitutes into the bound `params` array (never the SQL string); `php` recipes route through `execute-php` (manage_options-gated + safe-mode); all Hub handlers check capability + nonce; recipe abilities use `wpultra_permission_callback`; custom-ability execution inherits Wave 1 confirm-gate for destructive sql/wp-cli.
