# WP-Ultra-MCP — Wave 7 SEO · Plan 3: Technical + Local

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`) syntax.
>
> **Plan 3 of the Wave 7 program** (spec: `docs/superpowers/specs/2026-07-01-wp-ultra-seo-wave7-design.md`). Plans 1+2 shipped the SEO foundation + linking/research (branch `feat/seo-wave7`, count 88, Yoast active mode=yoast). Ships as **v0.11.0** at Plan 4 — do NOT bump version here.

**Goal:** Re-verify the meta driver under **Rank Math** (install it, prove cross-plugin), then add 5 technical/local abilities: `seo-manage-sitemap`, `seo-manage-robots`, `seo-manage-redirects`, `seo-manage-schema`, `seo-manage-local-business`.

**Architecture:** `includes/seo/technical.php` — sitemap state/toggle, custom robots.txt rules (via `robots_txt` filter), a stored redirect map applied on `template_redirect`, and per-post JSON-LD schema output on `wp_head`. `includes/seo/local.php` — a stored LocalBusiness record rendered as JSON-LD. Pure builders (redirect-match, JSON-LD) are unit-tested; the WP hooks + option storage are live-tested.

**Tech Stack:** PHP 8.0+, WP 7.0, `robots_txt`/`template_redirect`/`wp_head` hooks + options, Rank Math (installed in Task 1), the Plan-1/2 `wpultra_seo_*` engine. No new deps, no external API.

## Global Constraints

- Every PHP file: `<?php` + `declare(strict_types=1);` + `if (!defined('ABSPATH')) { exit(); }`. Pure-testable files use `if (!defined('ABSPATH') && !defined('WPULTRA_TEST')) {}`.
- Engine returns arrays/values or `WP_Error` via `wpultra_err`. Abilities return `wpultra_ok([...])` or the `WP_Error`.
- **Ability registration shape** — copy `wp-ultra-mcp/includes/abilities/seo-set-meta.php`: named string `execute_callback`, `properties` PLAIN ARRAY, `permission_callback=>'wpultra_permission_callback'`, `meta.mcp`, `category=>'seo'`.
- Multi-action abilities audit ONLY mutating sub-actions (`set`/`add`/`delete`/`enable`/`disable`), NOT `get`/`list`. `wpultra_audit_log('<slug>',...)` after a mutating action.
- **Engine require loop:** add `'technical','local'` to the seo loop in BOTH `wpultra_load_abilities()` AND `wpultra_load_seo_frontend()` (both in `bootstrap-mcp.php`; the loop is currently `['setup','meta','head','analyze','links','research']`). Because `technical.php`/`local.php` register front-end hooks (`robots_txt`, `template_redirect`, `wp_head`), they load through `wpultra_load_seo_frontend()` on every request.
- **`seo-manage-redirects` stores a bounded option map** and only ever redirects request paths that exactly match a stored source; targets sanitized (`esc_url_raw`); redirect type ∈ {301,302}. **`seo-manage-robots`** appends WHITELISTED-shape rules to a stored option surfaced via the `robots_txt` filter (never writes a physical robots.txt file). Schema/local JSON-LD values are escaped for JSON output.
- `tests/bootstrap.test.php` count `88` → `93`; keep files↔map in sync.
- Bundled PHP: `$PHP = C:/Users/nisha/AppData/Roaming/Local/lightning-services/php-8.2.30+1/bin/win64/php.exe`. Test site root: `C:/Users/nisha/Local Sites/wp-connector/app/public`. Live token: `wpultra-test-9a88`. Yoast 27.9 active (mode=yoast) at the start of this plan.
- **Re-run `bin/deploy.ps1` after every commit.** Live probes: token-gated webroot scripts, require engine + ability files, clean up, delete. Follow the ONE-self-request rule (nested `wp_remote_get` to the same Local site deadlocks the single worker — use separate top-level curls to verify rendered output).
- **Harness:** `it`, `assert_eq` (strict), `assert_true`; ends `run_tests();`. Pure tests stub nothing.
- Commit messages: `feat(seo):` / `test(seo):`; end body with `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.

## File Structure

```
wp-ultra-mcp/includes/
  seo/
    technical.php  NEW — sitemap + robots + redirects + schema (Tasks 2–5)
    local.php      NEW — LocalBusiness JSON-LD (Task 6)
  abilities/
    seo-manage-sitemap.php         NEW (Task 3)
    seo-manage-robots.php          NEW (Task 3)
    seo-manage-redirects.php       NEW (Task 4)
    seo-manage-schema.php          NEW (Task 5)
    seo-manage-local-business.php  NEW (Task 6)
  bootstrap-mcp.php   MODIFY — engine loop + 5 slugs
tests/
  seo-technical.test.php  NEW — pure redirect-match + jsonld-builder tests (Task 2)
  bootstrap.test.php      MODIFY — count 88 → 93
```

---

### Task 1: Install Rank Math + re-verify the meta driver (cross-plugin), restore Yoast

**Files:** none (verification task — proves the Plan-1 driver works under `mode=rankmath`; no code).

**Interfaces:** none.

- [ ] **Step 1: Install + activate Rank Math, deactivating Yoast.** Token-gated webroot script (mirror the Yoast install pattern): require wp-load + `wp-admin/includes/{plugin,plugin-install,file,misc,class-wp-upgrader}.php`; token-gate; `plugins_api('plugin_information',['slug'=>'seo-by-rank-math','fields'=>['sections'=>false]])`; `(new Plugin_Upgrader(new Automatic_Upgrader_Skin()))->install($api->download_link)`; `deactivate_plugins('wordpress-seo/wp-seo.php')`; `activate_plugin('seo-by-rank-math/rank-math.php')`; echo `defined('RANK_MATH_VERSION')` + version. curl `?t=wpultra-test-9a88`. Delete the install script.
Expected: Rank Math active, Yoast deactivated.

- [ ] **Step 2: Verify the driver under mode=rankmath.** SEPARATE token-gated probe: require wp-load + the plugin's `includes/seo/{setup,meta}.php` + helpers; `wp_set_current_user(<admin id>)`; assert `wpultra_seo_mode() === 'rankmath'`; create/pick a draft post; `wpultra_seo_set_meta($id, ['title'=>'RM Title','description'=>'<130-char>','focus_keyword'=>'gadget','robots_noindex'=>true])`; then assert via `get_post_meta`: `rank_math_title==='RM Title'`, `rank_math_description===` the desc, `rank_math_focus_keyword==='gadget'`, and `rank_math_robots` is an array containing `'noindex'`. Also `wpultra_seo_get_meta($id)` round-trips (`robots_noindex:true`, `mode:'rankmath'`). Echo JSON. Delete the test post + probe.
Expected: all Rank Math keys hold the driver-written values (proves the rankmath branch of the driver).

- [ ] **Step 3: Restore Yoast baseline.** Token-gated script: `deactivate_plugins('seo-by-rank-math/rank-math.php')`; `activate_plugin('wordpress-seo/wp-seo.php')`; echo `is_plugin_active` for both + `defined('WPSEO_VERSION')`. curl, confirm Yoast active + Rank Math inactive (mode back to yoast). Delete the script.
Expected: mode=yoast restored. (Rank Math stays installed-but-inactive — fine.)

- [ ] **Step 4: Record + commit a ledger/memory note (no repo code).** Record: Rank Math version, the verified `rank_math_*` keys, that the driver's rankmath branch is LIVE-PROVEN, and that the site is back to mode=yoast. (No git commit — this task changes no tracked files. Note it in the SDD ledger.)

---

### Task 2: `technical.php` engine — sitemap/robots/redirect(pure)/schema(pure) + hooks

**Files:**
- Create: `wp-ultra-mcp/includes/seo/technical.php`, `tests/seo-technical.test.php`
- Modify: `bootstrap-mcp.php` (seo engine loop `+technical,local` in BOTH loops)

**Interfaces:**
- Produces:
  - `wpultra_seo_match_redirect(string $path, array $map): ?array` — PURE. Returns the matching `{source,target,type}` (by normalized path equality) or null.
  - `wpultra_seo_build_jsonld(string $type, array $fields): array` — PURE. Builds a JSON-LD assoc for `Article|Product|FAQPage|BreadcrumbList` from fields.
  - `wpultra_seo_sitemap_state(): array`, `wpultra_seo_set_sitemap(bool $enabled): array` — WP: report provider/url/enabled + toggle the WP-core sitemap.
  - `wpultra_seo_get_robots(): array`, `wpultra_seo_set_robots(array $rules, bool $replace): array` — WP: stored custom robots rules (option `wpultra_seo_robots_rules`).
  - `wpultra_seo_redirects(): array`, `wpultra_seo_add_redirect(string,string,int): array`, `wpultra_seo_delete_redirect(string): array` — WP: option `wpultra_seo_redirects`.
  - `wpultra_seo_set_schema(int,string,array): array`, `wpultra_seo_get_schema(int): array` — WP: per-post meta `_wpultra_seo_schema`.
  - Registers: `robots_txt` filter (append stored rules), `template_redirect` action (apply the redirect map), `wp_head` action (echo per-post schema JSON-LD).

- [ ] **Step 1: Write the failing test** — `tests/seo-technical.test.php`

```php
<?php
require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../wp-ultra-mcp/includes/seo/technical.php';

it('match_redirect matches normalized path', function () {
    $map = [['source' => '/old-page/', 'target' => 'http://x/new/', 'type' => 301]];
    $r = wpultra_seo_match_redirect('/old-page/', $map);
    assert_eq('http://x/new/', $r['target']);
    assert_eq(301, $r['type']);
    assert_eq(null, wpultra_seo_match_redirect('/other/', $map));
});

it('match_redirect is trailing-slash tolerant', function () {
    $map = [['source' => '/old', 'target' => 'http://x/new', 'type' => 302]];
    assert_true(wpultra_seo_match_redirect('/old/', $map) !== null); // normalized equal
});

it('build_jsonld Article has required keys', function () {
    $j = wpultra_seo_build_jsonld('Article', ['headline' => 'Hi', 'author' => 'Ann', 'date' => '2026-01-01']);
    assert_eq('https://schema.org', $j['@context']);
    assert_eq('Article', $j['@type']);
    assert_eq('Hi', $j['headline']);
});

it('build_jsonld FAQPage builds mainEntity from qa pairs', function () {
    $j = wpultra_seo_build_jsonld('FAQPage', ['qa' => [['q' => 'Q1?', 'a' => 'A1']]]);
    assert_eq('FAQPage', $j['@type']);
    assert_eq('Q1?', $j['mainEntity'][0]['name']);
    assert_eq('A1', $j['mainEntity'][0]['acceptedAnswer']['text']);
});

run_tests();
```

- [ ] **Step 2: Run → fail** — `& $PHP tests/seo-technical.test.php` → FAIL.

- [ ] **Step 3: Write `technical.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH') && !defined('WPULTRA_TEST')) { /* allow harness load */ }

/** PURE. Normalize a path for comparison: leading slash, single trailing slash, lowercased. */
function wpultra_seo_norm_path(string $p): string {
    $p = strtolower(trim($p));
    if ($p === '') { return '/'; }
    if ($p[0] !== '/') { $p = '/' . $p; }
    return rtrim($p, '/') . '/';
}

/** PURE. Find a redirect whose source matches $path (normalized). */
function wpultra_seo_match_redirect(string $path, array $map): ?array {
    $n = wpultra_seo_norm_path($path);
    foreach ($map as $r) {
        if (wpultra_seo_norm_path((string) ($r['source'] ?? '')) === $n) { return $r; }
    }
    return null;
}

/** PURE. Build a JSON-LD assoc for a supported schema type. */
function wpultra_seo_build_jsonld(string $type, array $f): array {
    $base = ['@context' => 'https://schema.org', '@type' => $type];
    switch ($type) {
        case 'Article':
            return $base + array_filter([
                'headline' => $f['headline'] ?? '', 'author' => isset($f['author']) ? ['@type' => 'Person', 'name' => $f['author']] : null,
                'datePublished' => $f['date'] ?? '', 'image' => $f['image'] ?? '',
            ]);
        case 'Product':
            return $base + array_filter([
                'name' => $f['name'] ?? '', 'description' => $f['description'] ?? '', 'image' => $f['image'] ?? '',
                'offers' => isset($f['price']) ? ['@type' => 'Offer', 'price' => (string) $f['price'], 'priceCurrency' => $f['currency'] ?? 'USD'] : null,
            ]);
        case 'FAQPage':
            $entities = [];
            foreach (($f['qa'] ?? []) as $pair) {
                $entities[] = ['@type' => 'Question', 'name' => (string) ($pair['q'] ?? ''), 'acceptedAnswer' => ['@type' => 'Answer', 'text' => (string) ($pair['a'] ?? '')]];
            }
            return $base + ['mainEntity' => $entities];
        case 'BreadcrumbList':
            $items = [];
            $i = 1;
            foreach (($f['items'] ?? []) as $it) {
                $items[] = ['@type' => 'ListItem', 'position' => $i++, 'name' => (string) ($it['name'] ?? ''), 'item' => (string) ($it['url'] ?? '')];
            }
            return $base + ['itemListElement' => $items];
        default:
            return $base + $f;
    }
}

// ---- WP: sitemap ----
function wpultra_seo_sitemap_state(): array {
    $mode = function_exists('wpultra_seo_mode') ? wpultra_seo_mode() : 'native';
    $url = home_url('/wp-sitemap.xml');
    if ($mode === 'yoast') { $url = home_url('/sitemap_index.xml'); }
    if ($mode === 'rankmath') { $url = home_url('/sitemap_index.xml'); }
    $disabled = (bool) get_option('wpultra_seo_sitemap_disabled', false);
    return ['provider' => $mode === 'native' ? 'wp-core' : $mode, 'url' => $url, 'enabled' => !$disabled];
}
function wpultra_seo_set_sitemap(bool $enabled): array {
    update_option('wpultra_seo_sitemap_disabled', !$enabled);
    return wpultra_seo_sitemap_state();
}
add_filter('wp_sitemaps_enabled', 'wpultra_seo_sitemaps_enabled_filter');
function wpultra_seo_sitemaps_enabled_filter($enabled) {
    return get_option('wpultra_seo_sitemap_disabled', false) ? false : $enabled;
}

// ---- WP: robots ----
function wpultra_seo_get_robots(): array {
    $rules = get_option('wpultra_seo_robots_rules', []);
    return ['rules' => is_array($rules) ? $rules : []];
}
function wpultra_seo_set_robots(array $rules, bool $replace): array {
    $clean = [];
    foreach ($rules as $r) { $r = trim((string) $r); if ($r !== '') { $clean[] = $r; } }
    if (!$replace) { $clean = array_merge((get_option('wpultra_seo_robots_rules', []) ?: []), $clean); }
    update_option('wpultra_seo_robots_rules', array_values(array_unique($clean)));
    return wpultra_seo_get_robots();
}
add_filter('robots_txt', 'wpultra_seo_robots_filter', 20);
function wpultra_seo_robots_filter($output) {
    $rules = get_option('wpultra_seo_robots_rules', []);
    if (is_array($rules) && $rules) { $output .= "\n# WP-Ultra-MCP SEO\n" . implode("\n", array_map('sanitize_text_field', $rules)) . "\n"; }
    return $output;
}

// ---- WP: redirects ----
function wpultra_seo_redirects(): array {
    $m = get_option('wpultra_seo_redirects', []);
    return ['redirects' => is_array($m) ? $m : []];
}
function wpultra_seo_add_redirect(string $source, string $target, int $type): array {
    $map = get_option('wpultra_seo_redirects', []);
    if (!is_array($map)) { $map = []; }
    $type = in_array($type, [301, 302], true) ? $type : 301;
    $n = wpultra_seo_norm_path($source);
    $map = array_values(array_filter($map, function ($r) use ($n) { return wpultra_seo_norm_path((string) ($r['source'] ?? '')) !== $n; }));
    $map[] = ['source' => $source, 'target' => esc_url_raw($target), 'type' => $type];
    update_option('wpultra_seo_redirects', $map);
    return ['redirects' => $map];
}
function wpultra_seo_delete_redirect(string $source): array {
    $map = get_option('wpultra_seo_redirects', []);
    $n = wpultra_seo_norm_path($source);
    $map = array_values(array_filter(is_array($map) ? $map : [], function ($r) use ($n) { return wpultra_seo_norm_path((string) ($r['source'] ?? '')) !== $n; }));
    update_option('wpultra_seo_redirects', $map);
    return ['redirects' => $map];
}
add_action('template_redirect', 'wpultra_seo_apply_redirects', 0);
function wpultra_seo_apply_redirects() {
    if (is_admin()) { return; }
    $path = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $hit = wpultra_seo_match_redirect($path, get_option('wpultra_seo_redirects', []) ?: []);
    if ($hit && !empty($hit['target'])) { wp_redirect($hit['target'], (int) ($hit['type'] ?? 301)); exit; }
}

// ---- WP: schema ----
function wpultra_seo_set_schema(int $post_id, string $type, array $fields): array {
    if (!get_post($post_id)) { return ['ok' => false]; }
    update_post_meta($post_id, '_wpultra_seo_schema', ['type' => $type, 'fields' => $fields]);
    return ['post_id' => $post_id, 'type' => $type];
}
function wpultra_seo_get_schema(int $post_id): array {
    $s = get_post_meta($post_id, '_wpultra_seo_schema', true);
    return is_array($s) ? $s : [];
}
add_action('wp_head', 'wpultra_seo_render_schema', 5);
function wpultra_seo_render_schema() {
    if (!is_singular()) { return; }
    $s = wpultra_seo_get_schema(get_queried_object_id());
    if (empty($s['type'])) { return; }
    $json = wpultra_seo_build_jsonld((string) $s['type'], is_array($s['fields'] ?? null) ? $s['fields'] : []);
    echo "\n<script type=\"application/ld+json\">" . wp_json_encode($json) . "</script>\n"; // phpcs:ignore
}
```

- [ ] **Step 4: Run → pass** — `& $PHP tests/seo-technical.test.php` → PASS (4 `it`). Lint `technical.php`.

- [ ] **Step 5: Wire the engine loop** — extend the seo loop to `['setup','meta','head','analyze','links','research','technical','local']` in BOTH `wpultra_load_abilities()` AND `wpultra_load_seo_frontend()` (both in `bootstrap-mcp.php`; `local.php` comes Task 6, `is_readable` guards). Lint. Run `& $PHP tests/bootstrap.test.php` (still 88 — no ability added yet).

- [ ] **Step 6: Commit**

```bash
git add wp-ultra-mcp/includes/seo/technical.php tests/seo-technical.test.php wp-ultra-mcp/includes/bootstrap-mcp.php
git commit -m "feat(seo): technical engine — sitemap/robots/redirects(pure match)/schema(pure jsonld) + hooks"
```

---

### Task 3: `seo-manage-sitemap` + `seo-manage-robots`

**Files:**
- Create: `wp-ultra-mcp/includes/abilities/seo-manage-sitemap.php`, `seo-manage-robots.php`
- Modify: `bootstrap-mcp.php` (2 slugs), `tests/bootstrap.test.php` (88 → 90)

**Interfaces:** Consumes `wpultra_seo_sitemap_state/_set_sitemap`, `wpultra_seo_get_robots/_set_robots`.

- [ ] **Step 1: Write `seo-manage-sitemap` ability** (write — mutating on enable/disable)

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/seo-manage-sitemap', [
    'label'       => __('SEO: Manage Sitemap', 'wp-ultra-mcp'),
    'description' => __('Read the active sitemap (provider + URL + enabled), or enable/disable the WP-core sitemap. action: get|enable|disable.', 'wp-ultra-mcp'),
    'category'    => 'seo',
    'input_schema' => ['type' => 'object', 'properties' => ['action' => ['type' => 'string', 'enum' => ['get', 'enable', 'disable']]], 'required' => ['action'], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean'], 'sitemap' => ['type' => 'object']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_seo_manage_sitemap_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

function wpultra_seo_manage_sitemap_cb(array $input) {
    $action = (string) ($input['action'] ?? 'get');
    if ($action === 'enable') { $s = wpultra_seo_set_sitemap(true); }
    elseif ($action === 'disable') { $s = wpultra_seo_set_sitemap(false); }
    else { $s = wpultra_seo_sitemap_state(); }
    if ($action !== 'get') { wpultra_audit_log('seo-manage-sitemap', $action, true); }
    return wpultra_ok(['sitemap' => $s]);
}
```

- [ ] **Step 2: Write `seo-manage-robots` ability** (write — mutating on set)

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/seo-manage-robots', [
    'label'       => __('SEO: Manage Robots', 'wp-ultra-mcp'),
    'description' => __('Read or set custom robots.txt rules (appended via the robots_txt filter; no physical file written). action: get|set. rules = array of directive lines; replace=true overwrites, else appends.', 'wp-ultra-mcp'),
    'category'    => 'seo',
    'input_schema' => ['type' => 'object', 'properties' => ['action' => ['type' => 'string', 'enum' => ['get', 'set']], 'rules' => ['type' => 'array'], 'replace' => ['type' => 'boolean']], 'required' => ['action'], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean'], 'rules' => ['type' => 'array']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_seo_manage_robots_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

function wpultra_seo_manage_robots_cb(array $input) {
    $action = (string) ($input['action'] ?? 'get');
    if ($action === 'set') {
        $rules = is_array($input['rules'] ?? null) ? $input['rules'] : [];
        $res = wpultra_seo_set_robots($rules, !empty($input['replace']));
        wpultra_audit_log('seo-manage-robots', 'set ' . count($res['rules']) . ' rules', true);
    } else { $res = wpultra_seo_get_robots(); }
    return wpultra_ok(['rules' => $res['rules']]);
}
```

- [ ] **Step 3: Wire 2 slugs + bump count** — add both; `tests/bootstrap.test.php` `88` → `90`.

- [ ] **Step 4: Run bootstrap test** — PASS (90). Lint both ability files.

- [ ] **Step 5: Deploy + live-verify** — `powershell -File wp-ultra-mcp/bin/deploy.ps1`. Probe (require `includes/seo/{setup,technical}.php` + 2 ability files + helpers): `seo-manage-sitemap {action:get}` → assert `sitemap.url` non-empty + `sitemap.provider` (yoast, since Yoast active). `seo-manage-robots {action:set, rules:['Disallow: /private/'], replace:true}` → assert `rules` contains it; then a SEPARATE top-level `curl home_url('/robots.txt')` → assert the body contains `Disallow: /private/` and the `# WP-Ultra-MCP SEO` marker. Reset the robots rules to empty at the end (`seo-manage-robots {action:set,rules:[],replace:true}`). Delete the probe.

- [ ] **Step 6: Commit**

```bash
git add wp-ultra-mcp/includes/abilities/seo-manage-sitemap.php wp-ultra-mcp/includes/abilities/seo-manage-robots.php wp-ultra-mcp/includes/bootstrap-mcp.php tests/bootstrap.test.php
git commit -m "feat(seo): seo-manage-sitemap + seo-manage-robots"
```

---

### Task 4: `seo-manage-redirects`

**Files:**
- Create: `wp-ultra-mcp/includes/abilities/seo-manage-redirects.php`
- Modify: `bootstrap-mcp.php` (1 slug), `tests/bootstrap.test.php` (90 → 91)

**Interfaces:** Consumes `wpultra_seo_redirects/_add_redirect/_delete_redirect`.

- [ ] **Step 1: Write `seo-manage-redirects` ability** (write — mutating on add/delete)

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/seo-manage-redirects', [
    'label'       => __('SEO: Manage Redirects', 'wp-ultra-mcp'),
    'description' => __('Manage a redirect map applied on the front end. action: list|add|delete. add needs source (path), target (URL), type (301|302). delete needs source.', 'wp-ultra-mcp'),
    'category'    => 'seo',
    'input_schema' => ['type' => 'object', 'properties' => ['action' => ['type' => 'string', 'enum' => ['list', 'add', 'delete']], 'source' => ['type' => 'string'], 'target' => ['type' => 'string'], 'type' => ['type' => 'integer']], 'required' => ['action'], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean'], 'redirects' => ['type' => 'array']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_seo_manage_redirects_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

function wpultra_seo_manage_redirects_cb(array $input) {
    $action = (string) ($input['action'] ?? 'list');
    if ($action === 'add') {
        $src = (string) ($input['source'] ?? '');
        if ($src === '' || empty($input['target'])) { return wpultra_err('missing_fields', 'add requires source + target.'); }
        $res = wpultra_seo_add_redirect($src, (string) $input['target'], (int) ($input['type'] ?? 301));
        wpultra_audit_log('seo-manage-redirects', "add $src", true);
    } elseif ($action === 'delete') {
        $res = wpultra_seo_delete_redirect((string) ($input['source'] ?? ''));
        wpultra_audit_log('seo-manage-redirects', 'delete ' . (string) ($input['source'] ?? ''), true);
    } else { $res = wpultra_seo_redirects(); }
    return wpultra_ok(['redirects' => $res['redirects']]);
}
```

- [ ] **Step 2: Wire slug + bump count** — add `'seo-manage-redirects'`; `tests/bootstrap.test.php` `90` → `91`.

- [ ] **Step 3: Run bootstrap test** — PASS (91). Lint the ability file.

- [ ] **Step 4: Deploy + live-verify** — probe: `seo-manage-redirects {action:add, source:'/wpultra-redir-test/', target: home_url('/'), type:301}` → assert the map contains it. Then a SEPARATE top-level `curl -sI -o /dev/null -w "%{http_code} %{redirect_url}" home_url('/wpultra-redir-test/')` (no `-L`) → assert the status is `301` and the Location is the home URL. `seo-manage-redirects {action:delete, source:'/wpultra-redir-test/'}` → assert removed; re-curl → assert it's no longer a 301 (404/200). Delete the probe. (Two separate curls — never nest.)

- [ ] **Step 5: Commit**

```bash
git add wp-ultra-mcp/includes/abilities/seo-manage-redirects.php wp-ultra-mcp/includes/bootstrap-mcp.php tests/bootstrap.test.php
git commit -m "feat(seo): seo-manage-redirects (301/302 map on template_redirect)"
```

---

### Task 5: `seo-manage-schema`

**Files:**
- Create: `wp-ultra-mcp/includes/abilities/seo-manage-schema.php`
- Modify: `bootstrap-mcp.php` (1 slug), `tests/bootstrap.test.php` (91 → 92)

**Interfaces:** Consumes `wpultra_seo_set_schema/_get_schema` + `wpultra_seo_build_jsonld`.

- [ ] **Step 1: Write `seo-manage-schema` ability** (write — mutating on set/delete)

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/seo-manage-schema', [
    'label'       => __('SEO: Manage Schema', 'wp-ultra-mcp'),
    'description' => __('Attach JSON-LD structured data to a post (rendered in wp_head). action: get|set|delete. set needs post_id, type (Article|Product|FAQPage|BreadcrumbList), fields (type-specific). Additive to any SEO plugin schema.', 'wp-ultra-mcp'),
    'category'    => 'seo',
    'input_schema' => ['type' => 'object', 'properties' => ['action' => ['type' => 'string', 'enum' => ['get', 'set', 'delete']], 'post_id' => ['type' => 'integer'], 'type' => ['type' => 'string'], 'fields' => ['type' => 'object']], 'required' => ['action', 'post_id'], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean'], 'schema' => ['type' => 'object'], 'preview' => ['type' => 'object']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_seo_manage_schema_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

function wpultra_seo_manage_schema_cb(array $input) {
    $id = (int) ($input['post_id'] ?? 0);
    if (!get_post($id)) { return wpultra_err('post_not_found', "No post with id $id."); }
    $action = (string) ($input['action'] ?? 'get');
    if ($action === 'set') {
        $type = (string) ($input['type'] ?? '');
        if (!in_array($type, ['Article', 'Product', 'FAQPage', 'BreadcrumbList'], true)) { return wpultra_err('bad_type', 'type must be Article|Product|FAQPage|BreadcrumbList.'); }
        $fields = is_array($input['fields'] ?? null) ? $input['fields'] : [];
        wpultra_seo_set_schema($id, $type, $fields);
        wpultra_audit_log('seo-manage-schema', "set $type on $id", true);
        return wpultra_ok(['schema' => ['type' => $type, 'fields' => $fields], 'preview' => wpultra_seo_build_jsonld($type, $fields)]);
    }
    if ($action === 'delete') {
        delete_post_meta($id, '_wpultra_seo_schema');
        wpultra_audit_log('seo-manage-schema', "delete on $id", true);
        return wpultra_ok(['schema' => []]);
    }
    return wpultra_ok(['schema' => wpultra_seo_get_schema($id)]);
}
```

- [ ] **Step 2: Wire slug + bump count** — add `'seo-manage-schema'`; `tests/bootstrap.test.php` `91` → `92`.

- [ ] **Step 3: Run bootstrap test** — PASS (92). Lint the ability file.

- [ ] **Step 4: Deploy + live-verify** — probe: create a published post. `seo-manage-schema {action:set, post_id:<id>, type:'FAQPage', fields:{qa:[{q:'What is X?',a:'X is Y.'}]}}` → assert `preview['@type']==='FAQPage'`. Then a SEPARATE top-level `curl <post permalink>` → assert the HTML contains `application/ld+json` AND `"FAQPage"` AND `"What is X?"`. `seo-manage-schema {action:delete, post_id:<id>}` → assert empty; force-delete the post + probe.

- [ ] **Step 5: Commit**

```bash
git add wp-ultra-mcp/includes/abilities/seo-manage-schema.php wp-ultra-mcp/includes/bootstrap-mcp.php tests/bootstrap.test.php
git commit -m "feat(seo): seo-manage-schema (JSON-LD structured data in wp_head)"
```

---

### Task 6: `local.php` + `seo-manage-local-business`

**Files:**
- Create: `wp-ultra-mcp/includes/seo/local.php`, `wp-ultra-mcp/includes/abilities/seo-manage-local-business.php`
- Modify: `bootstrap-mcp.php` (1 slug), `tests/bootstrap.test.php` (92 → 93)

**Interfaces:**
- Produces: `wpultra_seo_build_local_jsonld(array $data): array` (PURE — add a test to `seo-technical.test.php`); `wpultra_seo_local_get(): array`, `wpultra_seo_local_set(array): array` (WP option `wpultra_seo_local`); registers a `wp_head` action rendering LocalBusiness JSON-LD when configured.

- [ ] **Step 1: Add a pure test** to `tests/seo-technical.test.php` (before `run_tests();`) + require local.php at its top:

```php
// (add near the top, alongside the technical.php require)
require_once __DIR__ . '/../wp-ultra-mcp/includes/seo/local.php';

it('build_local_jsonld has LocalBusiness type + address', function () {
    $j = wpultra_seo_build_local_jsonld(['name' => 'Acme', 'type' => 'Store', 'street' => '1 Main', 'city' => 'Springfield', 'phone' => '555']);
    assert_eq('Store', $j['@type']);
    assert_eq('Acme', $j['name']);
    assert_eq('1 Main', $j['address']['streetAddress']);
    assert_eq('555', $j['telephone']);
});
```

- [ ] **Step 2: Run → fail** — `& $PHP tests/seo-technical.test.php` → FAIL (`wpultra_seo_build_local_jsonld` undefined).

- [ ] **Step 3: Write `local.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH') && !defined('WPULTRA_TEST')) { /* allow harness load */ }

/** PURE. Build LocalBusiness JSON-LD from a stored record. */
function wpultra_seo_build_local_jsonld(array $d): array {
    $type = (string) ($d['type'] ?? 'LocalBusiness');
    $j = ['@context' => 'https://schema.org', '@type' => $type !== '' ? $type : 'LocalBusiness'];
    if (!empty($d['name'])) { $j['name'] = (string) $d['name']; }
    if (!empty($d['url'])) { $j['url'] = (string) $d['url']; }
    if (!empty($d['phone'])) { $j['telephone'] = (string) $d['phone']; }
    if (!empty($d['price_range'])) { $j['priceRange'] = (string) $d['price_range']; }
    if (!empty($d['logo'])) { $j['logo'] = (string) $d['logo']; }
    $addr = array_filter([
        'streetAddress' => $d['street'] ?? '', 'addressLocality' => $d['city'] ?? '',
        'addressRegion' => $d['region'] ?? '', 'postalCode' => $d['postal'] ?? '', 'addressCountry' => $d['country'] ?? '',
    ]);
    if ($addr) { $j['address'] = ['@type' => 'PostalAddress'] + $addr; }
    if (!empty($d['lat']) && !empty($d['lng'])) { $j['geo'] = ['@type' => 'GeoCoordinates', 'latitude' => (string) $d['lat'], 'longitude' => (string) $d['lng']]; }
    if (!empty($d['hours']) && is_array($d['hours'])) { $j['openingHours'] = array_values(array_map('strval', $d['hours'])); }
    return $j;
}

function wpultra_seo_local_get(): array {
    $d = get_option('wpultra_seo_local', []);
    return is_array($d) ? $d : [];
}
function wpultra_seo_local_set(array $data): array {
    $allowed = ['name', 'type', 'url', 'phone', 'price_range', 'logo', 'street', 'city', 'region', 'postal', 'country', 'lat', 'lng', 'hours'];
    $clean = [];
    foreach ($allowed as $k) { if (array_key_exists($k, $data)) { $clean[$k] = $data[$k]; } }
    update_option('wpultra_seo_local', $clean);
    return $clean;
}
add_action('wp_head', 'wpultra_seo_render_local', 6);
function wpultra_seo_render_local() {
    if (!is_front_page() && !is_home()) { return; }
    $d = wpultra_seo_local_get();
    if (empty($d['name'])) { return; }
    echo "\n<script type=\"application/ld+json\">" . wp_json_encode(wpultra_seo_build_local_jsonld($d)) . "</script>\n"; // phpcs:ignore
}
```

- [ ] **Step 4: Run → pass** — `& $PHP tests/seo-technical.test.php` → PASS (5 `it` now). Lint `local.php`.

- [ ] **Step 5: Write `seo-manage-local-business` ability** (write — mutating on set)

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/seo-manage-local-business', [
    'label'       => __('SEO: Manage Local Business', 'wp-ultra-mcp'),
    'description' => __('Read or set LocalBusiness structured data (NAP, geo, hours, price range) rendered as JSON-LD on the home page. action: get|set. Fields: name, type, url, phone, price_range, logo, street, city, region, postal, country, lat, lng, hours[].', 'wp-ultra-mcp'),
    'category'    => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => ['type' => 'string', 'enum' => ['get', 'set']],
            'name' => ['type' => 'string'], 'type' => ['type' => 'string'], 'url' => ['type' => 'string'], 'phone' => ['type' => 'string'],
            'price_range' => ['type' => 'string'], 'logo' => ['type' => 'string'], 'street' => ['type' => 'string'], 'city' => ['type' => 'string'],
            'region' => ['type' => 'string'], 'postal' => ['type' => 'string'], 'country' => ['type' => 'string'], 'lat' => ['type' => 'string'], 'lng' => ['type' => 'string'], 'hours' => ['type' => 'array'],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean'], 'local' => ['type' => 'object'], 'preview' => ['type' => 'object']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_seo_manage_local_business_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

function wpultra_seo_manage_local_business_cb(array $input) {
    $action = (string) ($input['action'] ?? 'get');
    if ($action === 'set') {
        $data = $input;
        unset($data['action']);
        $clean = wpultra_seo_local_set($data);
        wpultra_audit_log('seo-manage-local-business', 'set', true);
        return wpultra_ok(['local' => $clean, 'preview' => wpultra_seo_build_local_jsonld($clean)]);
    }
    $d = wpultra_seo_local_get();
    return wpultra_ok(['local' => $d, 'preview' => $d ? wpultra_seo_build_local_jsonld($d) : []]);
}
```

- [ ] **Step 6: Wire slug + bump count** — add `'seo-manage-local-business'`; `tests/bootstrap.test.php` `92` → `93`.

- [ ] **Step 7: Run the FULL suite** — `powershell -File tests/run-all.ps1` → `ALL TEST FILES PASSED` (bootstrap 93, seo-technical 5, all prior green). Lint the new files.

- [ ] **Step 8: Deploy + live-verify** — probe: `seo-manage-local-business {action:set, name:'Acme Widgets', type:'Store', street:'1 Main St', city:'Springfield', phone:'555-1000', price_range:'$$'}` → assert `preview['@type']==='Store'` + `preview.address.streetAddress`. Then a SEPARATE top-level `curl home_url('/')` → assert the HTML contains `application/ld+json` AND `"Acme Widgets"` AND `"PostalAddress"`. Reset (`seo-manage-local-business {action:set}` with empty — or leave it; note in report). Delete the probe.

- [ ] **Step 9: Commit**

```bash
git add wp-ultra-mcp/includes/seo/local.php wp-ultra-mcp/includes/abilities/seo-manage-local-business.php wp-ultra-mcp/includes/bootstrap-mcp.php tests/seo-technical.test.php tests/bootstrap.test.php
git commit -m "feat(seo): local.php + seo-manage-local-business (LocalBusiness JSON-LD)"
```

---

## Plan 3 Done — exit criteria

- Meta driver LIVE-PROVEN under Rank Math (rankmath keys) + site restored to mode=yoast.
- 5 technical/local abilities under `seo`; `tests/bootstrap.test.php` count = **93**; full suite green.
- Live-verified: sitemap reported; custom robots rule appears in `/robots.txt`; a 301 redirect works then is removed; per-post JSON-LD schema renders in the page; LocalBusiness JSON-LD renders on the home page.
- Do NOT bump plugin version (Plan 4 ships v0.11.0).

## Self-Review notes (done during planning)

- **Spec coverage (Plan 3 slice):** cross-plugin driver re-verify ✓ (Task 1), sitemap ✓ + robots ✓ (Task 3), redirects ✓ (Task 4), schema ✓ (Task 5), local-business ✓ (Task 6). Bulk/audit/quick-setup/skill are Plan 4.
- **Safety:** redirects only fire on exact normalized-path match, targets `esc_url_raw`'d, type ∈ {301,302}; robots rules `sanitize_text_field`'d + appended via filter (no physical file); schema/local via `wp_json_encode` (escaped); local-set whitelists keys. All mutating sub-actions audit; get/list don't.
- **Type consistency:** `wpultra_seo_match_redirect`/`_build_jsonld`/`_build_local_jsonld` pure + unit-tested; option keys (`wpultra_seo_redirects`/`_robots_rules`/`_sitemap_disabled`/`_local`, meta `_wpultra_seo_schema`) consistent between get/set; count chain 88→90→91→92→93 monotonic; engine loop extended once (Task 2) to include technical,local in BOTH loaders.
- **Placeholders:** none — concrete code/commands throughout. Live redirect/robots/schema/local verification uses SEPARATE top-level curls (never nested `wp_remote_get`) per the Local single-worker gotcha.
