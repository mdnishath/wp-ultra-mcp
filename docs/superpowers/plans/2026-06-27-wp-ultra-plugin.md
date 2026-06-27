# WP-Ultra-MCP — Wave 1 (Foundation) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **This is Wave 1 of the program** (`docs/superpowers/specs/2026-06-27-wp-ultra-program.md`). Wave 1 = the foundation + memory + WP content + skills + admin. **Elementor (schema-driven), Gutenberg, Bricks, design systems, and field-plugin integrations are LATER waves with their own plans.** Tasks 7, 8, 9 below are **DEFERRED — do NOT implement in Wave 1** (kept for reference; Elementor is being redesigned schema-driven in the Wave 2 plan). Wave 1 adds Task 13 (memory) and Task 14 (WP content).

**Goal:** Build the installable foundation of `WP-Ultra-MCP`: an MCP server plugin with files, WP-CLI, raw SQL, execute-php, diagnostics, persistent memory, WP content management, and a skills system — a useful product on its own and the base every later wave builds on.

**Architecture:** The plugin bundles the official `wordpress/mcp-adapter` (vendored from Novamira's copy) and registers WordPress **abilities** (`wp_register_ability`). The adapter auto-exposes every public ability as an MCP tool/resource/prompt at `/wp-json/mcp/wpultra`. We write only abilities + a server-side Elementor engine + an admin connect page + a skills system. We do not implement the MCP protocol or transport.

**Tech Stack:** PHP 8.0+ (dev/test on bundled PHP 8.2.30), WordPress 6.6+ (Abilities API), `wordpress/mcp-adapter` ^0.5, `wordpress/php-mcp-schema` ^0.1, `automattic/jetpack-autoloader` ^5. No system Composer — deps are vendored. Tests run on a zero-dependency PHP harness with the bundled PHP binary.

## Global Constraints

- Plugin slug/text-domain: **`wp-ultra-mcp`**. Ability namespace: **`wpultra/`**. MCP server id/route/name: **`wpultra`** → endpoint `/wp-json/mcp/wpultra`.
- PHP files start with `<?php` and `if (!defined('ABSPATH')) { exit(); }` (except the test harness and pure-logic files explicitly marked test-includable).
- `declare(strict_types=1);` at the top of every source PHP file.
- Every ability callback returns **either** a plain array matching its `output_schema` **or** a `WP_Error`. Never throws to the adapter.
- All SQL uses `$wpdb->prepare()` with placeholders; identifiers validated against `^[A-Za-z0-9_]+$`. Destructive verbs (`DROP`,`TRUNCATE`, no-`WHERE` `DELETE`/`UPDATE`) require `confirm:true`.
- `run-wp-cli` uses `proc_open` with an **argument array**, never a shell string.
- File operations are jailed under a filterable base dir (default `ABSPATH`); executable files (`.php`, `.htaccess`, `*.ini`, `php.ini`, `web.config`) confined to `WP_CONTENT_DIR/wpultra-sandbox/`.
- Permission gate for all privileged abilities: plugin enabled (`wpultra_enabled` option = '1' AND `wpultra_domain` host matches) AND `current_user_can('manage_options')` (single-site) / `is_super_admin()` (multisite).
- The bundled PHP binary for all local commands: `C:/Users/nisha/AppData/Roaming/Local/lightning-services/php-8.2.30+1/bin/win64/php.exe` (referred to below as `$PHP`).
- The Local test site WP root: `C:/Users/nisha/Local Sites/wp-connector/app/public` (referred to as `$WPROOT`).
- Each ability lives in its own file under `includes/abilities/` and is `require`d by the loader. Registration uses the shared **Ability Skeleton** (defined in Task 4, Step 0) — every ability task reuses it verbatim, changing only name/schema/callback/meta.

---

## File Structure

```
wp-ultra-mcp/                          (plugin root = E:\wp-connector\wp-ultra-mcp)
  wp-ultra-mcp.php                     main: header + constants + guarded require chain
  composer.json                        metadata only (deps are vendored)
  readme.txt                           WordPress plugin readme
  vendor/                              COPIED from Novamira: mcp-adapter, php-mcp-schema, jetpack-autoloader, autoload_packages.php
  includes/
    helpers.php                        pure logic: path-jail, capability, sql classifier, response shaping
    bootstrap-mcp.php                  adapter init, server config filter, categories, ability loader, policy
    elementor/engine.php               server-side _elementor_data validate/read/write/patch (pure transforms + WP write)
    gutenberg/content.php              parse/serialize blocks
    skills/parser.php                  markdown frontmatter parse/render (pure)
    skills/cpt.php                     wpultra_skill CPT
    skills/sources.php                 built-in + user-cpt sources registry
    skills/catalog.php                 agentic catalog injection
    skills/prompts.php                 per-skill MCP prompt registration
    skills/built-in/elementor-architect.md
    skills/built-in/self-healing.md
    abilities/
      read-file.php write-file.php edit-file.php delete-file.php list-directory.php
      run-wp-cli.php execute-php.php
      execute-wp-query.php
      read-debug-log.php
      elementor-get-layout.php elementor-set-layout.php elementor-patch-element.php elementor-schema.php
      gutenberg-get-content.php gutenberg-write-content.php
      skill-get.php skill-write.php skill-edit.php skill-delete.php
    admin/connect-page.php             3-step connect UI
    admin/abilities-page.php           per-ability enable/disable
  assets/admin.css assets/admin.js
  bin/
    deploy.ps1                         copy plugin into Local site plugins dir
    build-zip.ps1                      release zip with vendor bundled
tests/                                 (repo-level, NOT shipped)
  harness.php                          zero-dep assert runner + WP stubs (php-includable)
  helpers.test.php
  elementor-engine.test.php
  skills-parser.test.php
  abilities.test.php
docs/…                                 specs + plans
```

The plugin root is `E:\wp-connector\wp-ultra-mcp`. Tests live at `E:\wp-connector\tests`. Commands run from `E:\wp-connector`.

---

### Task 1: Scaffold, vendored adapter, test harness, deploy script

**Files:**
- Create: `wp-ultra-mcp/wp-ultra-mcp.php`, `wp-ultra-mcp/composer.json`, `wp-ultra-mcp/bin/deploy.ps1`, `wp-ultra-mcp/bin/build-zip.ps1`, `tests/harness.php`, `tests/smoke.test.php`
- Copy: Novamira's `vendor/` → `wp-ultra-mcp/vendor/`

**Interfaces:**
- Produces: a lint-clean plugin entry file; a working test harness with `it($name, $fn)`, `assert_eq($a,$b,$msg)`, `assert_true($c,$msg)`, `assert_throws($fn,$msg)`, and a `run_tests()` that exits non-zero on failure; WP stubs (`WP_Error`, `is_wp_error`, `__`, `apply_filters`, `add_action`, `add_filter`) usable by pure-logic tests; `bin/deploy.ps1` that mirrors the plugin into the Local site.

- [ ] **Step 1: Copy the vendored MCP adapter from Novamira**

Run (PowerShell):
```powershell
$src = 'C:\Users\nisha\OneDrive\Desktop\novamira\vendor'
$dst = 'E:\wp-connector\wp-ultra-mcp\vendor'
New-Item -ItemType Directory -Force -Path $dst | Out-Null
Copy-Item -Recurse -Force "$src\*" $dst
Get-ChildItem $dst -Directory | Select-Object Name
```
Expected: `dst` contains `automattic`, `composer`, `jetpack-autoloader`, `wordpress` and the file `autoload_packages.php`. Confirm `wordpress/mcp-adapter` and `wordpress/php-mcp-schema` are present.

- [ ] **Step 2: Write the plugin main file** `wp-ultra-mcp/wp-ultra-mcp.php`

```php
<?php
/**
 * Plugin Name: WP-Ultra-MCP
 * Description: Turn this WordPress site into an MCP server for AI CLIs — Elementor, SQL, WP-CLI, files, and more.
 * Version: 0.1.0
 * Requires PHP: 8.0
 * Requires at least: 6.6
 * License: GPL-2.0-or-later
 * Text Domain: wp-ultra-mcp
 */

declare(strict_types=1);

if (!defined('ABSPATH')) { exit(); }

define('WPULTRA_VERSION', '0.1.0');
define('WPULTRA_FILE', __FILE__);
define('WPULTRA_DIR', plugin_dir_path(__FILE__));
define('WPULTRA_URL', plugin_dir_url(__FILE__));
define('WPULTRA_VENDOR_AUTOLOAD', WPULTRA_DIR . 'vendor/autoload_packages.php');
define('WPULTRA_MCP_ADAPTER_CLASS', 'WP\\MCP\\Core\\McpAdapter');
define('WPULTRA_SANDBOX_DIR', WP_CONTENT_DIR . '/wpultra-sandbox/');

// Load bundled dependencies (Jetpack autoloader → mcp-adapter).
if (is_readable(WPULTRA_VENDOR_AUTOLOAD)) {
    require_once WPULTRA_VENDOR_AUTOLOAD;
}

require_once WPULTRA_DIR . 'includes/helpers.php';
require_once WPULTRA_DIR . 'includes/bootstrap-mcp.php';

if (is_admin()) {
    require_once WPULTRA_DIR . 'includes/admin/connect-page.php';
    require_once WPULTRA_DIR . 'includes/admin/abilities-page.php';
}

// Boot the MCP adapter + abilities (guarded internally on enabled-flag and adapter availability).
add_action('plugins_loaded', 'wpultra_boot', 20);
```

> Note: `wpultra_boot()` is defined in Task 3 (`bootstrap-mcp.php`). Until Task 3 lands, `wpultra_boot` will be undefined — that is expected; this task only verifies the file's PHP syntax with `php -l`, which does not resolve runtime symbols.

- [ ] **Step 3: Write `composer.json`** `wp-ultra-mcp/composer.json`

```json
{
  "name": "wp-ultra/wp-ultra-mcp",
  "description": "WordPress plugin: MCP server for AI CLIs with deep Elementor control.",
  "type": "wordpress-plugin",
  "license": "GPL-2.0-or-later",
  "require": {
    "php": ">=8.0",
    "wordpress/mcp-adapter": "^0.5",
    "wordpress/php-mcp-schema": "^0.1",
    "automattic/jetpack-autoloader": "^5.0"
  },
  "config": { "allow-plugins": { "automattic/jetpack-autoloader": true } }
}
```

- [ ] **Step 4: Write the test harness** `tests/harness.php`

```php
<?php
// Zero-dependency PHP test harness + minimal WordPress stubs for pure-logic unit tests.
// Run a test file with: php tests/<name>.test.php   (it requires this harness).

declare(strict_types=1);

error_reporting(E_ALL);

$GLOBALS['__tests'] = [];
$GLOBALS['__fail'] = 0;
$GLOBALS['__pass'] = 0;

function it(string $name, callable $fn): void { $GLOBALS['__tests'][] = [$name, $fn]; }

function assert_eq($expected, $actual, string $msg = ''): void {
    if ($expected === $actual) { return; }
    throw new Exception("assert_eq failed: $msg\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true));
}
function assert_true($cond, string $msg = ''): void {
    if ($cond === true) { return; }
    throw new Exception("assert_true failed: $msg (got " . var_export($cond, true) . ')');
}
function assert_contains(string $needle, string $haystack, string $msg = ''): void {
    if (str_contains($haystack, $needle)) { return; }
    throw new Exception("assert_contains failed: $msg\n  needle: $needle\n  haystack: $haystack");
}
function assert_throws(callable $fn, string $msg = ''): void {
    try { $fn(); } catch (\Throwable $e) { return; }
    throw new Exception("assert_throws failed: $msg (no throwable raised)");
}
function assert_wp_error($val, string $msg = ''): void {
    if (is_wp_error($val)) { return; }
    throw new Exception("assert_wp_error failed: $msg (got " . var_export($val, true) . ')');
}

function run_tests(): void {
    foreach ($GLOBALS['__tests'] as [$name, $fn]) {
        try { $fn(); $GLOBALS['__pass']++; echo "  PASS  $name\n"; }
        catch (\Throwable $e) { $GLOBALS['__fail']++; echo "  FAIL  $name\n    " . str_replace("\n", "\n    ", $e->getMessage()) . "\n"; }
    }
    $p = $GLOBALS['__pass']; $f = $GLOBALS['__fail'];
    echo "\n$p passed, $f failed\n";
    exit($f > 0 ? 1 : 0);
}

// ---- Minimal WordPress stubs (only what pure-logic code needs) ----
if (!class_exists('WP_Error')) {
    class WP_Error {
        public array $errors = [];
        public array $error_data = [];
        public function __construct($code = '', $message = '', $data = '') {
            if ($code !== '') { $this->errors[$code][] = $message; if ($data !== '') { $this->error_data[$code] = $data; } }
        }
        public function get_error_code() { return array_key_first($this->errors) ?? ''; }
        public function get_error_message() { $c = $this->get_error_code(); return $c ? ($this->errors[$c][0] ?? '') : ''; }
        public function get_error_data($code = '') { $c = $code ?: $this->get_error_code(); return $this->error_data[$c] ?? null; }
    }
}
if (!function_exists('is_wp_error')) { function is_wp_error($t): bool { return $t instanceof WP_Error; } }
if (!function_exists('__')) { function __($t, $d = 'default') { return $t; } }
if (!function_exists('apply_filters')) { function apply_filters($tag, $value, ...$a) { return $value; } }
if (!function_exists('add_action')) { function add_action(...$a) { return true; } }
if (!function_exists('add_filter')) { function add_filter(...$a) { return true; } }
if (!function_exists('trailingslashit')) { function trailingslashit($s) { return rtrim($s, "/\\") . '/'; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($d, $f = 0, $depth = 512) { return json_encode($d, $f, $depth); } }
```

- [ ] **Step 5: Write a smoke test for the harness** `tests/smoke.test.php`

```php
<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';

it('harness equality works', function () {
    assert_eq(4, 2 + 2, '2+2');
});
it('WP_Error stub works', function () {
    $e = new WP_Error('x', 'boom');
    assert_true(is_wp_error($e), 'is_wp_error');
    assert_eq('boom', $e->get_error_message(), 'message');
});

run_tests();
```

- [ ] **Step 6: Run the harness smoke test**

Run (PowerShell):
```powershell
$PHP = 'C:\Users\nisha\AppData\Roaming\Local\lightning-services\php-8.2.30+1\bin\win64\php.exe'
& $PHP E:\wp-connector\tests\smoke.test.php
```
Expected: prints `PASS` for both tests and `2 passed, 0 failed`, exit code 0.

- [ ] **Step 7: Lint the plugin main file**

Run (PowerShell):
```powershell
$PHP = 'C:\Users\nisha\AppData\Roaming\Local\lightning-services\php-8.2.30+1\bin\win64\php.exe'
& $PHP -l E:\wp-connector\wp-ultra-mcp\wp-ultra-mcp.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 8: Write the deploy script** `wp-ultra-mcp/bin/deploy.ps1`

```powershell
# Mirror the plugin into the Local "wp-connector" site so it can be activated/tested.
$ErrorActionPreference = 'Stop'
$src = 'E:\wp-connector\wp-ultra-mcp'
$dst = 'C:\Users\nisha\Local Sites\wp-connector\app\public\wp-content\plugins\wp-ultra-mcp'
New-Item -ItemType Directory -Force -Path $dst | Out-Null
robocopy $src $dst /MIR /XD tests .git node_modules /NFL /NDL /NJH /NJS /NP | Out-Null
Write-Host "Deployed to $dst"
```

- [ ] **Step 9: Write the build-zip script** `wp-ultra-mcp/bin/build-zip.ps1`

```powershell
# Produce a release zip (vendor bundled, tests excluded).
$ErrorActionPreference = 'Stop'
$src = 'E:\wp-connector\wp-ultra-mcp'
$stage = "$env:TEMP\wp-ultra-mcp"
$zip = 'E:\wp-connector\wp-ultra-mcp.zip'
if (Test-Path $stage) { Remove-Item -Recurse -Force $stage }
New-Item -ItemType Directory -Force -Path $stage | Out-Null
robocopy $src "$stage\wp-ultra-mcp" /MIR /XD .git node_modules /NFL /NDL /NJH /NJS /NP | Out-Null
if (Test-Path $zip) { Remove-Item -Force $zip }
Compress-Archive -Path "$stage\wp-ultra-mcp" -DestinationPath $zip
Write-Host "Built $zip"
```

- [ ] **Step 10: Commit**

```bash
git add wp-ultra-mcp tests
git commit -m "feat(plugin): scaffold, vendored mcp-adapter, test harness, deploy script"
```

---

### Task 2: helpers.php — path-jail, capability, SQL classifier (pure logic, unit-tested)

**Files:**
- Create: `wp-ultra-mcp/includes/helpers.php`
- Test: `tests/helpers.test.php`

**Interfaces:**
- Consumes: nothing (uses WP funcs available at runtime; pure helpers are WP-independent and harness-stubbed).
- Produces:
  - `wpultra_normalize_absolute_path(string $path): string` — collapse `.`/`..`, normalize slashes to `/`, strip trailing slash (keep root). Pure.
  - `wpultra_path_is_within_directory(string $path, string $dir): bool` — true if `$path` equals `$dir` or is under it. Pure.
  - `wpultra_is_valid_identifier(string $name): bool` — `^[A-Za-z0-9_]+$`. Pure.
  - `wpultra_classify_query(string $sql): array` — `['verb'=>string,'destructive'=>bool]`. Pure.
  - `wpultra_filesystem_base_dir(): string` — `apply_filters('wpultra_filesystem_base_dir', ABSPATH)`.
  - `wpultra_path_requires_sandbox(string $path): bool` — true for `.php`/`.htaccess`/`*.ini`/`php.ini`/`web.config`.
  - `wpultra_resolve_path(string $path, bool $must_exist=false): string|WP_Error` — jail resolution (relative→base dir, realpath of parent, within-base check, symlink reject, sandbox check for executable files).
  - `wpultra_is_enabled(): bool`, `wpultra_current_user_can_manage(): bool`, `wpultra_permission_callback(): bool`.
  - `wpultra_ok(array $fields): array` — merge `['success'=>true]` with `$fields`. `wpultra_err(string $code, string $message, $data=''): WP_Error`.

- [ ] **Step 1: Write the failing test** `tests/helpers.test.php`

```php
<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/var/www/wp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';

it('normalize collapses dot-dot and slashes', function () {
    assert_eq('/var/www/wp/x', wpultra_normalize_absolute_path('/var/www/wp/a/../x'));
    assert_eq('/var/www/wp', wpultra_normalize_absolute_path('/var/www/wp/'));
    assert_eq('/a/b', wpultra_normalize_absolute_path('\\a\\b\\'));
});

it('within-directory detects containment and escape', function () {
    assert_true(wpultra_path_is_within_directory('/var/www/wp/x.php', '/var/www/wp'), 'inside');
    assert_true(wpultra_path_is_within_directory('/var/www/wp', '/var/www/wp'), 'equal');
    assert_eq(false, wpultra_path_is_within_directory('/var/www/other', '/var/www/wp'), 'sibling');
    assert_eq(false, wpultra_path_is_within_directory('/etc/passwd', '/var/www/wp'), 'escape');
});

it('identifier validation', function () {
    assert_true(wpultra_is_valid_identifier('wp_posts'), 'ok');
    assert_eq(false, wpultra_is_valid_identifier('posts; DROP'), 'inject');
});

it('classify query verb and destructive flag', function () {
    assert_eq(['verb' => 'SELECT', 'destructive' => false], wpultra_classify_query('  SELECT * FROM wp_posts '));
    assert_eq(['verb' => 'DELETE', 'destructive' => true], wpultra_classify_query('DELETE FROM wp_posts'));
    assert_eq(false, wpultra_classify_query('delete from wp_posts where ID=1')['destructive']);
    assert_eq(true, wpultra_classify_query('DROP TABLE wp_x')['destructive']);
    assert_eq(true, wpultra_classify_query('TRUNCATE wp_x')['destructive']);
});

it('sandbox detection', function () {
    assert_true(wpultra_path_requires_sandbox('/a/b/functions.php'), 'php');
    assert_true(wpultra_path_requires_sandbox('/a/.htaccess'), 'htaccess');
    assert_eq(false, wpultra_path_requires_sandbox('/a/style.css'), 'css');
});

run_tests();
```

- [ ] **Step 2: Run it to verify failure**

Run: `& $PHP E:\wp-connector\tests\helpers.test.php`
Expected: FAIL — `wpultra_normalize_absolute_path` undefined (fatal). (This proves the test runs and the impl is missing.)

- [ ] **Step 3: Write `includes/helpers.php`**

```php
<?php
declare(strict_types=1);

if (!defined('ABSPATH')) { exit(); }

/** Collapse '.'/'..', normalize to forward slashes, strip trailing slash (keep root). Pure. */
function wpultra_normalize_absolute_path(string $path): string {
    $path = str_replace('\\', '/', $path);
    $is_unc = str_starts_with($path, '//');
    $segments = explode('/', $path);
    $out = [];
    foreach ($segments as $seg) {
        if ($seg === '' || $seg === '.') { continue; }
        if ($seg === '..') { array_pop($out); continue; }
        $out[] = $seg;
    }
    $prefix = '';
    if (preg_match('#^[A-Za-z]:#', $path)) { $prefix = ''; }       // windows drive kept as first segment
    elseif ($is_unc) { $prefix = '//'; }
    elseif (str_starts_with($path, '/')) { $prefix = '/'; }
    $joined = $prefix . implode('/', $out);
    return $joined === '' ? '/' : $joined;
}

/** True if $path equals $dir or is nested under it. Pure. */
function wpultra_path_is_within_directory(string $path, string $dir): bool {
    $p = wpultra_normalize_absolute_path($path);
    $d = wpultra_normalize_absolute_path($dir);
    return $p === $d || str_starts_with($p, $d . '/');
}

function wpultra_is_valid_identifier(string $name): bool {
    return (bool) preg_match('/^[A-Za-z0-9_]+$/', $name);
}

/** Return ['verb'=>UPPER, 'destructive'=>bool]. Pure. */
function wpultra_classify_query(string $sql): array {
    $trimmed = trim($sql);
    $verb = strtoupper(preg_split('/\s+/', $trimmed)[0] ?? '');
    $has_where = (bool) preg_match('/\bWHERE\b/i', $trimmed);
    $destructive = false;
    if (in_array($verb, ['DROP', 'TRUNCATE', 'ALTER'], true)) { $destructive = true; }
    if (in_array($verb, ['DELETE', 'UPDATE'], true) && !$has_where) { $destructive = true; }
    return ['verb' => $verb, 'destructive' => $destructive];
}

function wpultra_filesystem_base_dir(): string {
    return (string) apply_filters('wpultra_filesystem_base_dir', ABSPATH);
}

function wpultra_path_requires_sandbox(string $path): bool {
    $name = strtolower(basename($path));
    if (str_ends_with($name, '.php')) { return true; }
    if (str_ends_with($name, '.ini')) { return true; }
    return in_array($name, ['.htaccess', 'php.ini', 'web.config', '.user.ini'], true);
}

/**
 * Resolve a path inside the jail. Returns absolute path string or WP_Error.
 * Relative paths resolve against the base dir. Symlink final targets are rejected.
 */
function wpultra_resolve_path(string $path, bool $must_exist = false) {
    $path = trim($path);
    if ($path === '') { return wpultra_err('missing_path', 'Path is required.'); }

    $base = wpultra_filesystem_base_dir();
    $is_abs = (bool) preg_match('#^([A-Za-z]:[\\\\/]|[\\\\/])#', $path);
    $candidate = $is_abs ? $path : rtrim($base, '/\\') . '/' . $path;

    // Resolve parent via realpath (handles symlinks/.. in the existing portion); append missing tail.
    $real = realpath($candidate);
    if ($real === false) {
        if ($must_exist) { return wpultra_err('path_not_found', "Path does not exist: $candidate"); }
        $parent = realpath(dirname($candidate));
        if ($parent === false) {
            $resolved = wpultra_normalize_absolute_path($candidate);
        } else {
            $resolved = wpultra_normalize_absolute_path($parent . '/' . basename($candidate));
        }
    } else {
        $resolved = wpultra_normalize_absolute_path($real);
    }

    if (!wpultra_path_is_within_directory($resolved, $base)) {
        return wpultra_err('path_outside_base', "Path is outside the allowed base directory: $resolved");
    }
    if (is_link($resolved)) {
        return wpultra_err('symlink_rejected', "Refusing to operate on a symlink: $resolved");
    }
    if (wpultra_path_requires_sandbox($resolved)) {
        $sandbox = wpultra_normalize_absolute_path(WPULTRA_SANDBOX_DIR);
        if (!wpultra_path_is_within_directory($resolved, $sandbox)) {
            return wpultra_err('sandbox_required', "Executable files must be written under the sandbox dir: $sandbox");
        }
    }
    return $resolved;
}

function wpultra_is_enabled(): bool {
    if (get_option('wpultra_enabled') !== '1') { return false; }
    $locked = (string) get_option('wpultra_domain', '');
    if ($locked === '') { return true; }
    $current = wp_parse_url(home_url(), PHP_URL_HOST);
    return $locked === $current;
}

function wpultra_current_user_can_manage(): bool {
    return is_multisite() ? is_super_admin() : current_user_can('manage_options');
}

function wpultra_permission_callback(): bool {
    return wpultra_is_enabled() && wpultra_current_user_can_manage();
}

function wpultra_ok(array $fields): array { return array_merge(['success' => true], $fields); }

function wpultra_err(string $code, string $message, $data = ''): WP_Error {
    return new WP_Error($code, $message, $data);
}
```

> Note: `wpultra_resolve_path`, `wpultra_is_enabled`, etc. call WP runtime functions (`get_option`, `home_url`, `realpath`) not all stubbed in the harness — they are exercised by integration, not unit tests. The unit test only covers the pure functions (normalize, within-dir, identifier, classify, sandbox-detection), which is what Step 1 asserts.

- [ ] **Step 4: Run the test to verify it passes**

Run: `& $PHP E:\wp-connector\tests\helpers.test.php`
Expected: all 6 tests `PASS`, `6 passed, 0 failed`.

- [ ] **Step 5: Lint**

Run: `& $PHP -l E:\wp-connector\wp-ultra-mcp\includes\helpers.php`
Expected: `No syntax errors detected`.

- [ ] **Step 6: Commit**

```bash
git add wp-ultra-mcp/includes/helpers.php tests/helpers.test.php
git commit -m "feat(plugin): helpers — path-jail, capability gate, sql classifier"
```

---

### Task 3: bootstrap-mcp.php — adapter init, server config, categories, ability loader, policy

**Files:**
- Create: `wp-ultra-mcp/includes/bootstrap-mcp.php`

**Interfaces:**
- Consumes: helpers (`wpultra_is_enabled`), the vendored `WP\MCP\Core\McpAdapter`.
- Produces:
  - `wpultra_boot(): void` — the `plugins_loaded` callback that wires everything.
  - `wpultra_mcp_adapter_available(): bool`.
  - `wpultra_ability_files(): array` — list of ability file basenames to load (the loader's single source of truth; every ability task appends its file here).
  - `wpultra_register_categories(): void`, `wpultra_load_abilities(): void`, `wpultra_apply_ability_policy(): void`.

- [ ] **Step 1: Write `includes/bootstrap-mcp.php`**

```php
<?php
declare(strict_types=1);

if (!defined('ABSPATH')) { exit(); }

function wpultra_mcp_adapter_available(): bool {
    return class_exists(WPULTRA_MCP_ADAPTER_CLASS);
}

/** Single source of truth for which ability files to load. Later waves append here. */
function wpultra_ability_files(): array {
    return [
        // filesystem
        'read-file', 'write-file', 'edit-file', 'delete-file', 'list-directory',
        // code & system
        'run-wp-cli', 'execute-php',
        // database + diagnostics
        'execute-wp-query', 'read-debug-log',
        // memory (Wave 1, Task 13)
        'memory-save', 'memory-get', 'memory-list', 'memory-delete',
        // wp content (Wave 1, Task 14)
        'create-post', 'update-post', 'delete-post',
        // skills
        'skill-get', 'skill-write', 'skill-edit', 'skill-delete',
    ];
    // NOTE: elementor-*, gutenberg-*, bricks-*, and field-plugin abilities are added by later waves.
}

function wpultra_register_categories(): void {
    if (!function_exists('wp_register_ability_category')) { return; }
    $cats = [
        'filesystem' => 'Filesystem read/write within the site.',
        'code-execution' => 'Run WP-CLI and PHP.',
        'database' => 'Direct parameterized SQL.',
        'diagnostics' => 'Logs and self-healing.',
        'elementor' => 'Elementor layout engine.',
        'gutenberg' => 'Gutenberg block content.',
        'skills' => 'Reusable AI skill documents.',
    ];
    foreach ($cats as $slug => $desc) {
        wp_register_ability_category($slug, ['label' => $slug, 'description' => __($desc, 'wp-ultra-mcp')]);
    }
}

function wpultra_load_abilities(): void {
    if (!wpultra_is_enabled()) { return; }
    foreach (wpultra_ability_files() as $file) {
        $path = WPULTRA_DIR . 'includes/abilities/' . $file . '.php';
        if (is_readable($path)) { require_once $path; }
    }
    // Skills subsystem (CPT + catalog + per-skill prompts) registers its own abilities/prompts.
    if (is_readable(WPULTRA_DIR . 'includes/skills/cpt.php')) {
        require_once WPULTRA_DIR . 'includes/skills/cpt.php';
        require_once WPULTRA_DIR . 'includes/skills/sources.php';
        require_once WPULTRA_DIR . 'includes/skills/catalog.php';
        require_once WPULTRA_DIR . 'includes/skills/prompts.php';
    }
}

function wpultra_apply_ability_policy(): void {
    if (!function_exists('wp_unregister_ability')) { return; }
    $rules = get_option('wpultra_ability_rules', []);
    if (!is_array($rules)) { return; }
    foreach ($rules as $name => $rule) {
        if (is_array($rule) && !empty($rule['disabled'])) {
            wp_unregister_ability((string) $name);
        }
    }
}

function wpultra_boot(): void {
    if (!wpultra_mcp_adapter_available()) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>WP-Ultra-MCP: bundled MCP Adapter failed to load. Install the release build (with vendor/).</p></div>';
        });
        return;
    }

    // Brand the adapter's default server as "wpultra".
    add_filter('mcp_adapter_default_server_config', function ($config) {
        if (is_array($config)) {
            $config['server_id'] = 'wpultra';
            $config['server_route'] = 'wpultra';
            $config['server_name'] = 'WP-Ultra-MCP';
        }
        return $config;
    });

    if (!wpultra_is_enabled()) { return; }

    add_action('wp_abilities_api_categories_init', 'wpultra_register_categories');
    add_action('wp_abilities_api_init', 'wpultra_load_abilities');
    add_action('wp_abilities_api_init', 'wpultra_apply_ability_policy', PHP_INT_MAX);

    \WP\MCP\Core\McpAdapter::instance();
}
```

- [ ] **Step 2: Lint**

Run: `& $PHP -l E:\wp-connector\wp-ultra-mcp\includes\bootstrap-mcp.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Structural test — file loads under harness with stubs**

Create `tests/bootstrap.test.php`:
```php
<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
if (!defined('WP_CONTENT_DIR')) { define('WP_CONTENT_DIR', '/tmp/wp-content'); }
if (!defined('WPULTRA_MCP_ADAPTER_CLASS')) { define('WPULTRA_MCP_ADAPTER_CLASS', 'No\\Such\\Class'); }
if (!defined('WPULTRA_DIR')) { define('WPULTRA_DIR', __DIR__ . '/../wp-ultra-mcp/'); }
if (!function_exists('get_option')) { function get_option($k, $d = false) { return $d; } }
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/bootstrap-mcp.php';

it('ability file list is complete and unique', function () {
    $files = wpultra_ability_files();
    assert_eq(20, count($files), 'count');
    assert_eq(count($files), count(array_unique($files)), 'unique');
    assert_true(in_array('execute-wp-query', $files, true), 'has sql');
    assert_true(in_array('memory-save', $files, true), 'has memory');
    assert_true(in_array('create-post', $files, true), 'has wp content');
});
it('adapter-unavailable boot is a no-op (no throw)', function () {
    wpultra_boot();
    assert_true(true, 'did not throw');
});

run_tests();
```
Run: `& $PHP E:\wp-connector\tests\bootstrap.test.php`
Expected: `2 passed, 0 failed`.

- [ ] **Step 4: Commit**

```bash
git add wp-ultra-mcp/includes/bootstrap-mcp.php tests/bootstrap.test.php
git commit -m "feat(plugin): MCP adapter bootstrap, categories, ability loader, policy"
```

---

### Task 4: Filesystem abilities (read/write/edit/delete/list)

**Files:**
- Create: `wp-ultra-mcp/includes/abilities/read-file.php`, `write-file.php`, `edit-file.php`, `delete-file.php`, `list-directory.php`
- Test: `tests/abilities-fs.test.php`

**Interfaces:**
- Consumes: `wpultra_resolve_path`, `wpultra_ok`, `wpultra_err`, `wpultra_permission_callback` (Task 2).
- Produces: 5 registered abilities. Each ability file, when included, calls `wp_register_ability('wpultra/<slug>', [...])` and defines its `wpultra_<slug_underscored>(array $input)` callback.

**Step 0 — The Ability Skeleton (every ability task reuses this exact shape):**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/SLUG', [
    'label'       => __('LABEL', 'wp-ultra-mcp'),
    'description' => __('DESCRIPTION', 'wp-ultra-mcp'),
    'category'    => 'CATEGORY',
    'input_schema'  => [ 'type' => 'object', 'properties' => [ /* ... */ ], 'required' => [ /* ... */ ], 'additionalProperties' => false ],
    'output_schema' => [ 'type' => 'object', 'properties' => [ 'success' => ['type' => 'boolean'] ], 'required' => ['success'] ],
    'execute_callback'    => 'wpultra_CALLBACK',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        'annotations' => ['readonly' => BOOL, 'destructive' => BOOL, 'idempotent' => BOOL],
    ],
]);

function wpultra_CALLBACK(array $input) { /* return array|WP_Error */ }
```

The callbacks below are complete; wrap each in the skeleton with the indicated SLUG/schema/meta.

- [ ] **Step 1: Write the failing test** `tests/abilities-fs.test.php`

```php
<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
$tmp = sys_get_temp_dir() . '/wpultra_fs_' . uniqid();
mkdir($tmp, 0777, true);
if (!defined('ABSPATH')) { define('ABSPATH', $tmp . '/'); }
if (!defined('WP_CONTENT_DIR')) { define('WP_CONTENT_DIR', $tmp . '/wp-content'); }
if (!defined('WPULTRA_SANDBOX_DIR')) { define('WPULTRA_SANDBOX_DIR', $tmp . '/wp-content/wpultra-sandbox/'); }
if (!function_exists('wp_register_ability')) { function wp_register_ability($n, $a) { $GLOBALS['__ab'][$n] = $a; } }
if (!function_exists('get_option')) { function get_option($k, $d = false) { return $d; } }
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/abilities/write-file.php';
require __DIR__ . '/../wp-ultra-mcp/includes/abilities/read-file.php';
require __DIR__ . '/../wp-ultra-mcp/includes/abilities/edit-file.php';
require __DIR__ . '/../wp-ultra-mcp/includes/abilities/list-directory.php';
require __DIR__ . '/../wp-ultra-mcp/includes/abilities/delete-file.php';

it('write then read roundtrips inside the jail', function () {
    $w = wpultra_write_file(['path' => 'a/b.txt', 'content' => 'hello']);
    assert_true($w['success'], 'write ok');
    $r = wpultra_read_file(['path' => 'a/b.txt']);
    assert_eq('hello', $r['content'], 'read back');
});
it('write blocks traversal outside base', function () {
    assert_wp_error(wpultra_write_file(['path' => '../escape.txt', 'content' => 'x']), 'jail');
});
it('write blocks .php outside sandbox', function () {
    assert_wp_error(wpultra_write_file(['path' => 'wp-content/themes/x/functions.php', 'content' => '<?php']), 'sandbox');
});
it('edit replaces a unique substring', function () {
    wpultra_write_file(['path' => 'e.txt', 'content' => 'foo BAR baz']);
    $e = wpultra_edit_file(['path' => 'e.txt', 'old_string' => 'BAR', 'new_string' => 'QUX']);
    assert_true($e['success'], 'edit ok');
    assert_eq('foo QUX baz', wpultra_read_file(['path' => 'e.txt'])['content']);
});
it('list-directory returns entries', function () {
    $l = wpultra_list_directory(['path' => 'a']);
    assert_true($l['success'], 'list ok');
    assert_true(count($l['entries']) >= 1, 'has entries');
});
it('delete removes a file', function () {
    wpultra_write_file(['path' => 'd.txt', 'content' => 'x']);
    $d = wpultra_delete_file(['path' => 'd.txt']);
    assert_true($d['success'], 'delete ok');
    assert_wp_error(wpultra_read_file(['path' => 'd.txt']), 'gone');
});

run_tests();
```

- [ ] **Step 2: Run to verify failure**

Run: `& $PHP E:\wp-connector\tests\abilities-fs.test.php`
Expected: FAIL — `wpultra_write_file` undefined.

- [ ] **Step 3: Write the 5 ability files** (each wrapped in the Step 0 skeleton)

`read-file.php` — SLUG `read-file`, category `filesystem`, input `{path:string(req), max_bytes:int}`, output adds `{path,content,truncated}`, meta readonly=true/destructive=false/idempotent=true:
```php
function wpultra_read_file(array $input) {
    $resolved = wpultra_resolve_path((string) ($input['path'] ?? ''), true);
    if (is_wp_error($resolved)) { return $resolved; }
    $max = max(1, (int) ($input['max_bytes'] ?? 200000));
    $content = file_get_contents($resolved);
    if ($content === false) { return wpultra_err('read_failed', "Could not read: $resolved"); }
    $truncated = false;
    if (strlen($content) > $max) { $content = substr($content, 0, $max); $truncated = true; }
    return wpultra_ok(['path' => $resolved, 'content' => $content, 'truncated' => $truncated]);
}
```

`write-file.php` — SLUG `write-file`, input `{path:string(req), content:string(req), append:bool}`, output `{path,bytes_written}`, meta destructive=true:
```php
function wpultra_write_file(array $input) {
    $resolved = wpultra_resolve_path((string) ($input['path'] ?? ''), false);
    if (is_wp_error($resolved)) { return $resolved; }
    $content = (string) ($input['content'] ?? '');
    $append = ($input['append'] ?? false) === true;
    $dir = dirname($resolved);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) { return wpultra_err('mkdir_failed', "Could not create dir: $dir"); }
    if ($append) {
        $ok = file_put_contents($resolved, $content, FILE_APPEND);
    } else {
        $tmp = $resolved . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $content) === false) { return wpultra_err('write_failed', "Could not write tmp for: $resolved"); }
        if (!rename($tmp, $resolved)) { @unlink($tmp); return wpultra_err('rename_failed', "Could not finalize: $resolved"); }
        $ok = strlen($content);
    }
    if ($ok === false) { return wpultra_err('write_failed', "Could not write: $resolved"); }
    return wpultra_ok(['path' => $resolved, 'bytes_written' => strlen($content)]);
}
```

`edit-file.php` — SLUG `edit-file`, input `{path:string(req), old_string:string(req), new_string:string(req)}`, output `{path,replacements}`, meta destructive=true:
```php
function wpultra_edit_file(array $input) {
    $resolved = wpultra_resolve_path((string) ($input['path'] ?? ''), true);
    if (is_wp_error($resolved)) { return $resolved; }
    $old = (string) ($input['old_string'] ?? '');
    $new = (string) ($input['new_string'] ?? '');
    if ($old === '') { return wpultra_err('empty_old_string', 'old_string must be non-empty.'); }
    $content = file_get_contents($resolved);
    if ($content === false) { return wpultra_err('read_failed', "Could not read: $resolved"); }
    $count = substr_count($content, $old);
    if ($count === 0) { return wpultra_err('not_found', 'old_string not found in file.'); }
    if ($count > 1) { return wpultra_err('not_unique', "old_string occurs $count times; make it unique."); }
    $updated = str_replace($old, $new, $content);
    if (file_put_contents($resolved, $updated) === false) { return wpultra_err('write_failed', "Could not write: $resolved"); }
    return wpultra_ok(['path' => $resolved, 'replacements' => 1]);
}
```

`list-directory.php` — SLUG `list-directory`, input `{path:string, limit:int}` (no required), output `{path,entries:[{name,type,size}]}`, meta readonly=true:
```php
function wpultra_list_directory(array $input) {
    $resolved = wpultra_resolve_path((string) ($input['path'] ?? '.'), true);
    if (is_wp_error($resolved)) { return $resolved; }
    if (!is_dir($resolved)) { return wpultra_err('not_a_directory', "Not a directory: $resolved"); }
    $limit = max(1, min(5000, (int) ($input['limit'] ?? 500)));
    $entries = [];
    foreach (scandir($resolved) as $name) {
        if ($name === '.' || $name === '..') { continue; }
        $full = $resolved . '/' . $name;
        $entries[] = ['name' => $name, 'type' => is_dir($full) ? 'dir' : 'file', 'size' => is_file($full) ? filesize($full) : 0];
        if (count($entries) >= $limit) { break; }
    }
    return wpultra_ok(['path' => $resolved, 'entries' => $entries]);
}
```

`delete-file.php` — SLUG `delete-file`, input `{path:string(req)}`, output `{path,deleted}`, meta destructive=true/idempotent=true. Protected paths: `ABSPATH`, `ABSPATH/wp-admin`, `ABSPATH/wp-includes`, `WP_CONTENT_DIR/mu-plugins`:
```php
function wpultra_delete_file(array $input) {
    $resolved = wpultra_resolve_path((string) ($input['path'] ?? ''), false);
    if (is_wp_error($resolved)) { return $resolved; }
    $protected = array_map('wpultra_normalize_absolute_path', [
        rtrim(ABSPATH, '/\\'), ABSPATH . 'wp-admin', ABSPATH . 'wp-includes', WP_CONTENT_DIR . '/mu-plugins',
    ]);
    if (in_array(wpultra_normalize_absolute_path($resolved), $protected, true)) {
        return wpultra_err('protected_path', "Refusing to delete a protected path: $resolved");
    }
    if (!file_exists($resolved)) { return wpultra_ok(['path' => $resolved, 'deleted' => false]); }
    if (is_dir($resolved)) { return wpultra_err('is_directory', 'Refusing to delete a directory.'); }
    if (!unlink($resolved)) { return wpultra_err('delete_failed', "Could not delete: $resolved"); }
    return wpultra_ok(['path' => $resolved, 'deleted' => true]);
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `& $PHP E:\wp-connector\tests\abilities-fs.test.php`
Expected: all 6 tests `PASS`, `6 passed, 0 failed`.

- [ ] **Step 5: Lint all five files**

Run: `Get-ChildItem E:\wp-connector\wp-ultra-mcp\includes\abilities\*.php | ForEach-Object { & $PHP -l $_.FullName }`
Expected: `No syntax errors detected` for each.

- [ ] **Step 6: Commit**

```bash
git add wp-ultra-mcp/includes/abilities tests/abilities-fs.test.php
git commit -m "feat(plugin): filesystem abilities (read/write/edit/delete/list)"
```

---

### Task 5: Code abilities (run-wp-cli, execute-php)

**Files:**
- Create: `wp-ultra-mcp/includes/abilities/run-wp-cli.php`, `execute-php.php`
- Test: `tests/abilities-code.test.php`

**Interfaces:**
- Consumes: `wpultra_ok`, `wpultra_err`, `wpultra_permission_callback`.
- Produces: `wpultra_run_wp_cli(array $input)`, `wpultra_execute_php(array $input)`.

- [ ] **Step 1: Write the failing test** `tests/abilities-code.test.php`

```php
<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/'); }
if (!defined('WPULTRA_CLI_TIMEOUT')) { define('WPULTRA_CLI_TIMEOUT', 30); }
if (!function_exists('wp_register_ability')) { function wp_register_ability($n, $a) {} }
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/abilities/execute-php.php';

it('execute-php returns the return value and captured output', function () {
    $r = wpultra_execute_php(['code' => 'echo "hi"; return 1 + 2;']);
    assert_true($r['success'], 'ok');
    assert_eq('hi', $r['output'], 'echo captured');
    assert_eq('3', (string) $r['return_value'], 'return value');
});
it('execute-php catches a thrown error as success=false', function () {
    $r = wpultra_execute_php(['code' => 'throw new Exception("boom");']);
    assert_eq(false, $r['success'], 'failure flagged');
    assert_contains('boom', (string) $r['error'], 'error message');
});

run_tests();
```

- [ ] **Step 2: Run to verify failure**

Run: `& $PHP E:\wp-connector\tests\abilities-code.test.php`
Expected: FAIL — `wpultra_execute_php` undefined.

- [ ] **Step 3: Write `execute-php.php`** (skeleton SLUG `execute-php`, category `code-execution`, input `{code:string(req)}`, output `{success,return_value,output,error,error_class,warnings}`, meta destructive=true):

```php
function wpultra_execute_php(array $input) {
    $code = (string) ($input['code'] ?? '');
    if ($code === '') { return wpultra_err('empty_code', 'code is required.'); }
    $code = preg_replace('/^\s*<\?php/', '', $code); // tolerate a leading tag
    $warnings = [];
    set_error_handler(function ($no, $str) use (&$warnings) { $warnings[] = $str; return true; });
    $prev = ini_get('max_execution_time');
    if (function_exists('set_time_limit')) { @set_time_limit(defined('WPULTRA_CLI_TIMEOUT') ? WPULTRA_CLI_TIMEOUT : 30); }
    ob_start();
    try {
        $return = eval($code);
        $output = ob_get_clean();
        restore_error_handler();
        if (function_exists('set_time_limit')) { @set_time_limit((int) $prev); }
        if (!is_scalar($return) && $return !== null) { $return = print_r($return, true); }
        return wpultra_ok(['return_value' => $return, 'output' => $output, 'warnings' => $warnings]);
    } catch (\Throwable $e) {
        $output = ob_get_clean();
        restore_error_handler();
        if (function_exists('set_time_limit')) { @set_time_limit((int) $prev); }
        return ['success' => false, 'error' => $e->getMessage(), 'error_class' => get_class($e), 'output' => $output, 'warnings' => $warnings];
    }
}
```

- [ ] **Step 4: Write `run-wp-cli.php`** (skeleton SLUG `run-wp-cli`, category `code-execution`, input `{args:array<string>(req)}`, output `{success,exit_code,stdout,stderr}`, meta destructive=true):

```php
function wpultra_find_wp_cli(): string {
    foreach (['wp', '/usr/local/bin/wp', '/usr/bin/wp'] as $c) {
        if ($c === 'wp') { return 'wp'; }
        if (is_executable($c)) { return $c; }
    }
    return 'wp';
}

function wpultra_run_wp_cli(array $input) {
    if (!function_exists('proc_open')) { return wpultra_err('proc_disabled', 'proc_open is disabled in PHP.'); }
    $args = array_values(array_filter((array) ($input['args'] ?? []), 'is_string'));
    if ($args === []) { return wpultra_err('no_args', 'args must be a non-empty array of strings.'); }
    $cmd = array_merge([wpultra_find_wp_cli()], $args);
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes, ABSPATH);
    if (!is_resource($proc)) { return wpultra_err('spawn_failed', 'Could not start wp-cli.'); }
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $code = proc_close($proc);
    return wpultra_ok(['exit_code' => $code, 'stdout' => (string) $stdout, 'stderr' => (string) $stderr]);
}
```

- [ ] **Step 5: Run the test + lint**

Run: `& $PHP E:\wp-connector\tests\abilities-code.test.php`
Expected: `2 passed, 0 failed`.
Run: `& $PHP -l E:\wp-connector\wp-ultra-mcp\includes\abilities\run-wp-cli.php; & $PHP -l E:\wp-connector\wp-ultra-mcp\includes\abilities\execute-php.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 6: Commit**

```bash
git add wp-ultra-mcp/includes/abilities/run-wp-cli.php wp-ultra-mcp/includes/abilities/execute-php.php tests/abilities-code.test.php
git commit -m "feat(plugin): code abilities (run-wp-cli, execute-php)"
```

---

### Task 6: Database ability (execute-wp-query) + diagnostics (read-debug-log)

**Files:**
- Create: `wp-ultra-mcp/includes/abilities/execute-wp-query.php`, `read-debug-log.php`
- Test: `tests/abilities-db.test.php`

**Interfaces:**
- Consumes: `wpultra_classify_query`, `wpultra_ok`, `wpultra_err`, global `$wpdb`.
- Produces: `wpultra_execute_wp_query(array $input)`, `wpultra_read_debug_log(array $input)`. The query callback's gating logic is unit-tested via a `$wpdb` stub.

- [ ] **Step 1: Write the failing test** `tests/abilities-db.test.php`

```php
<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/'); }
if (!function_exists('wp_register_ability')) { function wp_register_ability($n, $a) {} }
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/abilities/execute-wp-query.php';

// Fake $wpdb capturing prepare() + get_results()/query().
class FakeWpdb {
    public string $last_sql = '';
    public ?array $last_params = null;
    public $insert_id = 7;
    public function prepare($sql, ...$args) { $this->last_sql = $sql; $this->last_params = $args ? (is_array($args[0]) ? $args[0] : $args) : []; return $sql; }
    public function get_results($sql, $output) { return [['ID' => 1]]; }
    public function query($sql) { return 2; }
}

it('SELECT returns rows', function () {
    $GLOBALS['wpdb'] = new FakeWpdb();
    $r = wpultra_execute_wp_query(['sql' => 'SELECT * FROM wp_posts WHERE ID = %d', 'params' => [1]]);
    assert_true($r['success'], 'ok');
    assert_eq('SELECT', $r['verb'], 'verb');
    assert_eq([['ID' => 1]], $r['rows'], 'rows');
});
it('destructive query without confirm is rejected', function () {
    $GLOBALS['wpdb'] = new FakeWpdb();
    $r = wpultra_execute_wp_query(['sql' => 'DROP TABLE wp_x']);
    assert_wp_error($r, 'blocked');
    assert_contains('confirm', $r->get_error_message(), 'hint');
});
it('destructive query with confirm runs', function () {
    $GLOBALS['wpdb'] = new FakeWpdb();
    $r = wpultra_execute_wp_query(['sql' => 'DELETE FROM wp_posts', 'confirm' => true]);
    assert_true($r['success'], 'ran');
    assert_eq(2, $r['rows_affected'], 'affected');
});

run_tests();
```

- [ ] **Step 2: Run to verify failure**

Run: `& $PHP E:\wp-connector\tests\abilities-db.test.php`
Expected: FAIL — `wpultra_execute_wp_query` undefined.

- [ ] **Step 3: Write `execute-wp-query.php`** (skeleton SLUG `execute-wp-query`, category `database`, input `{sql:string(req), params:array, confirm:bool}`, output `{success,verb,rows,row_count,rows_affected,insert_id}`, meta destructive=true):

```php
function wpultra_execute_wp_query(array $input) {
    global $wpdb;
    $sql = (string) ($input['sql'] ?? '');
    if ($sql === '') { return wpultra_err('empty_sql', 'sql is required.'); }
    $params = array_values((array) ($input['params'] ?? []));
    $confirm = ($input['confirm'] ?? false) === true;
    $class = wpultra_classify_query($sql);
    if ($class['destructive'] && !$confirm) {
        return wpultra_err('destructive_unconfirmed', 'This query is destructive. Re-run with confirm: true to proceed.');
    }
    $prepared = $params === [] ? $sql : $wpdb->prepare($sql, $params);
    if ($class['verb'] === 'SELECT') {
        $rows = $wpdb->get_results($prepared, ARRAY_A);
        $rows = is_array($rows) ? $rows : [];
        return wpultra_ok(['verb' => $class['verb'], 'rows' => $rows, 'row_count' => count($rows)]);
    }
    $affected = $wpdb->query($prepared);
    return wpultra_ok(['verb' => $class['verb'], 'rows_affected' => (int) $affected, 'insert_id' => (int) $wpdb->insert_id]);
}
```

> Note: the harness defines `ARRAY_A` if missing — add `if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }` to the top of `tests/abilities-db.test.php` after the harness require.

- [ ] **Step 4: Write `read-debug-log.php`** (skeleton SLUG `read-debug-log`, category `diagnostics`, input `{lines:int}`, output `{success,path,content,exists}`, meta readonly=true):

```php
function wpultra_debug_log_path(): string {
    if (defined('WP_DEBUG_LOG') && is_string(WP_DEBUG_LOG) && WP_DEBUG_LOG !== '') { return WP_DEBUG_LOG; }
    return WP_CONTENT_DIR . '/debug.log';
}

function wpultra_read_debug_log(array $input) {
    $path = wpultra_debug_log_path();
    if (!is_readable($path)) {
        return wpultra_ok(['path' => $path, 'exists' => false, 'content' => '',
            'note' => 'No debug.log found. Set WP_DEBUG and WP_DEBUG_LOG=true in wp-config.php to capture errors.']);
    }
    $n = max(1, min(5000, (int) ($input['lines'] ?? 100)));
    $all = file($path, FILE_IGNORE_NEW_LINES);
    if ($all === false) { return wpultra_err('read_failed', "Could not read: $path"); }
    $tail = array_slice($all, -$n);
    return wpultra_ok(['path' => $path, 'exists' => true, 'content' => implode("\n", $tail)]);
}
```

- [ ] **Step 5: Run the test + lint**

Run: `& $PHP E:\wp-connector\tests\abilities-db.test.php`
Expected: `3 passed, 0 failed`.
Run: `& $PHP -l ...execute-wp-query.php; & $PHP -l ...read-debug-log.php`
Expected: `No syntax errors detected`.

- [ ] **Step 6: Commit**

```bash
git add wp-ultra-mcp/includes/abilities/execute-wp-query.php wp-ultra-mcp/includes/abilities/read-debug-log.php tests/abilities-db.test.php
git commit -m "feat(plugin): database (execute-wp-query) + diagnostics (read-debug-log)"
```

---

### Task 7: Elementor engine — ⚠️ DEFERRED TO WAVE 2 (do NOT implement in Wave 1)

> Superseded by the schema-driven Elementor design in the program spec §2. The raw-JSON engine below is **not** built. Skip to Task 10. (Retained only as a reference contrast.)

#### (reference) Elementor engine (pure transforms)

**Files:**
- Create: `wp-ultra-mcp/includes/elementor/engine.php`
- Test: `tests/elementor-engine.test.php`

**Interfaces:**
- Consumes: `wpultra_err` (harness-stubbed for tests).
- Produces (all pure, array-in/array-out — the WP write path lives in Task 8):
  - `wpultra_elementor_validate_elements(array $elements): true|WP_Error` — each node must have `elType` in `{container,widget}`, `id` string, `elements` array; widgets need `widgetType`.
  - `wpultra_elementor_compact(array $elements, int $depth=0): array` — shape to `{id,elType,widgetType?,children}`.
  - `wpultra_elementor_new_id(): string` — 7-char lowercase alnum.
  - `wpultra_elementor_patch(array $elements, array $op): array|WP_Error` — apply one op: `insert` (append `element` under parent `target_id` or root), `update` (shallow-merge `settings` into node `target_id`), `delete` (remove node `target_id`), `reorder` (set child order of `target_id` to `order` array of child ids).

- [ ] **Step 1: Write the failing test** `tests/elementor-engine.test.php`

```php
<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
require __DIR__ . '/../wp-ultra-mcp/includes/elementor/engine.php';

function fixture(): array {
    return [[
        'id' => 'row0001', 'elType' => 'container', 'settings' => ['flex_direction' => 'row'],
        'elements' => [
            ['id' => 'col0001', 'elType' => 'container', 'settings' => [], 'elements' => [
                ['id' => 'head001', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => ['title' => 'Hi'], 'elements' => []],
            ]],
            ['id' => 'col0002', 'elType' => 'container', 'settings' => [], 'elements' => []],
        ],
    ]];
}

it('validate accepts a well-formed tree', function () {
    assert_true(wpultra_elementor_validate_elements(fixture()) === true, 'valid');
});
it('validate rejects a widget without widgetType', function () {
    $bad = [['id' => 'x', 'elType' => 'widget', 'settings' => [], 'elements' => []]];
    assert_wp_error(wpultra_elementor_validate_elements($bad), 'needs widgetType');
});
it('compact shapes the tree', function () {
    $c = wpultra_elementor_compact(fixture());
    assert_eq('row0001', $c[0]['id']);
    assert_eq('heading', $c[0]['children'][0]['children'][0]['widgetType']);
});
it('new id is 7-char lowercase alnum', function () {
    $id = wpultra_elementor_new_id();
    assert_true((bool) preg_match('/^[a-z0-9]{7}$/', $id), 'format');
});
it('patch update merges settings on target', function () {
    $out = wpultra_elementor_patch(fixture(), ['kind' => 'update', 'target_id' => 'head001', 'settings' => ['title' => 'Bye']]);
    assert_eq('Bye', $out[0]['elements'][0]['elements'][0]['settings']['title']);
});
it('patch delete removes target', function () {
    $out = wpultra_elementor_patch(fixture(), ['kind' => 'delete', 'target_id' => 'col0002']);
    assert_eq(1, count($out[0]['elements']));
});
it('patch insert appends under parent', function () {
    $el = ['id' => 'new0001', 'elType' => 'widget', 'widgetType' => 'button', 'settings' => [], 'elements' => []];
    $out = wpultra_elementor_patch(fixture(), ['kind' => 'insert', 'target_id' => 'col0002', 'element' => $el]);
    assert_eq('new0001', $out[0]['elements'][1]['elements'][0]['id']);
});
it('patch reorder reorders children', function () {
    $out = wpultra_elementor_patch(fixture(), ['kind' => 'reorder', 'target_id' => 'row0001', 'order' => ['col0002', 'col0001']]);
    assert_eq('col0002', $out[0]['elements'][0]['id']);
});
it('patch on missing target errors', function () {
    assert_wp_error(wpultra_elementor_patch(fixture(), ['kind' => 'delete', 'target_id' => 'nope']), 'missing');
});

run_tests();
```

- [ ] **Step 2: Run to verify failure**

Run: `& $PHP E:\wp-connector\tests\elementor-engine.test.php`
Expected: FAIL — engine functions undefined.

- [ ] **Step 3: Write `includes/elementor/engine.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH') && !defined('WPULTRA_TEST')) { exit(); }

function wpultra_elementor_validate_elements(array $elements) {
    foreach ($elements as $node) {
        if (!is_array($node)) { return wpultra_err('invalid_node', 'Each element must be an object.'); }
        $type = $node['elType'] ?? null;
        if ($type !== 'container' && $type !== 'widget') { return wpultra_err('invalid_eltype', "elType must be 'container' or 'widget'."); }
        if (!isset($node['id']) || !is_string($node['id']) || $node['id'] === '') { return wpultra_err('missing_id', 'Each element needs a non-empty string id.'); }
        if ($type === 'widget' && empty($node['widgetType'])) { return wpultra_err('missing_widget_type', "Widget '{$node['id']}' needs a widgetType."); }
        $children = $node['elements'] ?? [];
        if (!is_array($children)) { return wpultra_err('invalid_children', "elements must be an array on '{$node['id']}'."); }
        $deep = wpultra_elementor_validate_elements($children);
        if (is_wp_error($deep)) { return $deep; }
    }
    return true;
}

function wpultra_elementor_compact(array $elements, int $depth = 0): array {
    $out = [];
    foreach ($elements as $node) {
        if (!is_array($node)) { continue; }
        $entry = ['id' => $node['id'] ?? '', 'elType' => $node['elType'] ?? ''];
        if (!empty($node['widgetType'])) { $entry['widgetType'] = $node['widgetType']; }
        $children = is_array($node['elements'] ?? null) ? $node['elements'] : [];
        $entry['children'] = $depth < 8 ? wpultra_elementor_compact($children, $depth + 1) : [];
        $out[] = $entry;
    }
    return $out;
}

function wpultra_elementor_new_id(): string {
    $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $id = '';
    for ($i = 0; $i < 7; $i++) { $id .= $alphabet[random_int(0, strlen($alphabet) - 1)]; }
    return $id;
}

/** Recursively find a node by id and apply $mutator(&$node, &$siblings, $index). Returns true if applied. */
function wpultra_elementor_walk(array &$elements, string $id, callable $mutator): bool {
    foreach ($elements as $i => &$node) {
        if (($node['id'] ?? null) === $id) { $mutator($node, $elements, $i); return true; }
        if (!empty($node['elements']) && is_array($node['elements'])) {
            if (wpultra_elementor_walk($node['elements'], $id, $mutator)) { return true; }
        }
    }
    return false;
}

function wpultra_elementor_patch(array $elements, array $op) {
    $kind = $op['kind'] ?? '';
    $target = (string) ($op['target_id'] ?? '');

    if ($kind === 'insert') {
        $el = $op['element'] ?? null;
        if (!is_array($el)) { return wpultra_err('missing_element', 'insert requires an element object.'); }
        $valid = wpultra_elementor_validate_elements([$el]);
        if (is_wp_error($valid)) { return $valid; }
        if ($target === '') { $elements[] = $el; return $elements; }
        $done = wpultra_elementor_walk($elements, $target, function (&$node) use ($el) {
            if (!isset($node['elements']) || !is_array($node['elements'])) { $node['elements'] = []; }
            $node['elements'][] = $el;
        });
        return $done ? $elements : wpultra_err('target_not_found', "No element with id '$target'.");
    }
    if ($kind === 'update') {
        $settings = (array) ($op['settings'] ?? []);
        $done = wpultra_elementor_walk($elements, $target, function (&$node) use ($settings) {
            $node['settings'] = array_merge(is_array($node['settings'] ?? null) ? $node['settings'] : [], $settings);
        });
        return $done ? $elements : wpultra_err('target_not_found', "No element with id '$target'.");
    }
    if ($kind === 'delete') {
        $done = wpultra_elementor_walk($elements, $target, function (&$node, &$siblings, $i) { array_splice($siblings, $i, 1); });
        return $done ? $elements : wpultra_err('target_not_found', "No element with id '$target'.");
    }
    if ($kind === 'reorder') {
        $order = array_values((array) ($op['order'] ?? []));
        $done = wpultra_elementor_walk($elements, $target, function (&$node) use ($order) {
            $byId = [];
            foreach (($node['elements'] ?? []) as $c) { $byId[$c['id'] ?? ''] = $c; }
            $new = [];
            foreach ($order as $cid) { if (isset($byId[$cid])) { $new[] = $byId[$cid]; unset($byId[$cid]); } }
            foreach ($byId as $leftover) { $new[] = $leftover; }
            $node['elements'] = $new;
        });
        return $done ? $elements : wpultra_err('target_not_found', "No element with id '$target'.");
    }
    return wpultra_err('unknown_op', "Unknown patch kind: $kind");
}
```

> Note: the test defines `WPULTRA_TEST` before requiring engine.php so the `ABSPATH` guard allows loading. Add `define('WPULTRA_TEST', true);` at the top of `tests/elementor-engine.test.php` (after the harness require). The engine uses `wpultra_err` — the test must require `helpers.php` too, OR define a local `wpultra_err`; require helpers (it loads cleanly with ABSPATH defined). Add to the test: `if (!defined('ABSPATH')) { define('ABSPATH','/tmp/'); }` and `require __DIR__.'/../wp-ultra-mcp/includes/helpers.php';` BEFORE requiring engine.php.

- [ ] **Step 4: Run the test to verify it passes**

Run: `& $PHP E:\wp-connector\tests\elementor-engine.test.php`
Expected: all 9 tests `PASS`, `9 passed, 0 failed`.

- [ ] **Step 5: Lint**

Run: `& $PHP -l E:\wp-connector\wp-ultra-mcp\includes\elementor\engine.php`
Expected: `No syntax errors detected`.

- [ ] **Step 6: Commit**

```bash
git add wp-ultra-mcp/includes/elementor/engine.php tests/elementor-engine.test.php
git commit -m "feat(plugin): Elementor engine — validate/compact/patch (pure transforms)"
```

---

### Task 8: Elementor abilities — ⚠️ DEFERRED TO WAVE 2 (do NOT implement in Wave 1)

> Superseded by program spec §2.4. Skip to Task 10.

#### (reference) Elementor abilities

**Files:**
- Create: `wp-ultra-mcp/includes/abilities/elementor-get-layout.php`, `elementor-set-layout.php`, `elementor-patch-element.php`, `elementor-schema.php`
- Test: covered by lint + the engine unit tests (Task 7); the WP-write paths are exercised in the Task 12 integration smoke.

**Interfaces:**
- Consumes: engine (`wpultra_elementor_validate_elements/compact/patch`), global `$wpdb`, `wpultra_ok/err`.
- Produces: 3 tool abilities + 1 resource ability. All require the engine: each file starts with `require_once WPULTRA_DIR . 'includes/elementor/engine.php';`.

- [ ] **Step 1: Write `elementor-get-layout.php`** (SLUG `elementor-get-layout`, category `elementor`, input `{post_id:int(req)}`, output `{success,post_id,elements}` (compacted), meta readonly=true):

```php
require_once WPULTRA_DIR . 'includes/elementor/engine.php';
function wpultra_elementor_get_layout(array $input) {
    $post_id = (int) ($input['post_id'] ?? 0);
    if ($post_id <= 0) { return wpultra_err('bad_post_id', 'post_id must be a positive integer.'); }
    $raw = get_post_meta($post_id, '_elementor_data', true);
    if (empty($raw)) { return wpultra_ok(['post_id' => $post_id, 'elements' => []]); }
    $data = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($data)) { return wpultra_err('corrupt_data', "_elementor_data for post $post_id is not valid JSON."); }
    return wpultra_ok(['post_id' => $post_id, 'elements' => wpultra_elementor_compact($data)]);
}
```

- [ ] **Step 2: Write `elementor-set-layout.php`** (SLUG `elementor-set-layout`, category `elementor`, input `{post_id:int(req), elements:array(req), clear_css:bool}`, output `{success,post_id,element_count}`, meta destructive=true):

```php
require_once WPULTRA_DIR . 'includes/elementor/engine.php';
function wpultra_elementor_set_layout(array $input) {
    $post_id = (int) ($input['post_id'] ?? 0);
    if ($post_id <= 0) { return wpultra_err('bad_post_id', 'post_id must be a positive integer.'); }
    $elements = $input['elements'] ?? null;
    if (is_string($elements)) { $elements = json_decode($elements, true); }
    if (!is_array($elements)) { return wpultra_err('bad_elements', 'elements must be an array (or its JSON string).'); }
    $valid = wpultra_elementor_validate_elements($elements);
    if (is_wp_error($valid)) { return $valid; }

    // Prefer Elementor's document API (regenerates CSS correctly); fall back to direct meta.
    if (class_exists('\\Elementor\\Plugin')) {
        $doc = \Elementor\Plugin::$instance->documents->get($post_id);
        if ($doc) {
            $doc->save(['elements' => $elements, 'settings' => []]);
            return wpultra_ok(['post_id' => $post_id, 'element_count' => count($elements), 'via' => 'document_api']);
        }
    }
    update_post_meta($post_id, '_elementor_data', wp_slash(wp_json_encode($elements)));
    update_post_meta($post_id, '_elementor_edit_mode', 'builder');
    update_post_meta($post_id, '_elementor_version', defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '3.25.0');
    if (($input['clear_css'] ?? true) === true) { delete_post_meta($post_id, '_elementor_css'); }
    return wpultra_ok(['post_id' => $post_id, 'element_count' => count($elements), 'via' => 'meta']);
}
```

- [ ] **Step 3: Write `elementor-patch-element.php`** (SLUG `elementor-patch-element`, category `elementor`, input `{post_id:int(req), kind:enum[insert,update,delete,reorder](req), target_id:string, element:object, settings:object, order:array}`, output `{success,post_id,kind}`, meta destructive=true):

```php
require_once WPULTRA_DIR . 'includes/elementor/engine.php';
function wpultra_elementor_patch_element(array $input) {
    $post_id = (int) ($input['post_id'] ?? 0);
    if ($post_id <= 0) { return wpultra_err('bad_post_id', 'post_id must be a positive integer.'); }
    $raw = get_post_meta($post_id, '_elementor_data', true);
    $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : (is_array($raw) ? $raw : []);
    if (!is_array($data)) { return wpultra_err('corrupt_data', "_elementor_data for post $post_id is not valid JSON."); }
    $op = [
        'kind' => (string) ($input['kind'] ?? ''),
        'target_id' => (string) ($input['target_id'] ?? ''),
        'element' => $input['element'] ?? null,
        'settings' => (array) ($input['settings'] ?? []),
        'order' => (array) ($input['order'] ?? []),
    ];
    $patched = wpultra_elementor_patch($data, $op);
    if (is_wp_error($patched)) { return $patched; }
    $set = wpultra_elementor_set_layout(['post_id' => $post_id, 'elements' => $patched]);
    if (is_wp_error($set)) { return $set; }
    return wpultra_ok(['post_id' => $post_id, 'kind' => $op['kind']]);
}
```

- [ ] **Step 4: Write `elementor-schema.php`** — a RESOURCE ability (`meta.mcp.type=resource`, SLUG `elementor-schema`, category `elementor`, no input). Its callback returns the atomic blueprint:

```php
function wpultra_elementor_schema(array $input = []) {
    return wpultra_ok(['schema' => [
        'description' => 'Elementor _elementor_data is a JSON array of nodes. Each node: {id(7-char), elType:container|widget, settings:{}, elements:[]}. Widgets carry widgetType and have empty elements.',
        'containerSettings' => ['container_type' => 'flex|grid', 'flex_direction' => 'row|column', 'flex_gap' => ['unit' => 'px', 'size' => 20], 'width' => ['unit' => '%', 'size' => 100]],
        'examples' => [
            'heading' => ['id' => 'h1aaaaa', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => ['title' => 'Hello', 'header_size' => 'h2'], 'elements' => []],
            'button' => ['id' => 'b1aaaaa', 'elType' => 'widget', 'widgetType' => 'button', 'settings' => ['text' => 'Click', 'link' => ['url' => '#']], 'elements' => []],
            'threeColumnRow' => ['id' => 'rowaaaa', 'elType' => 'container', 'settings' => ['container_type' => 'flex', 'flex_direction' => 'row'], 'elements' => [
                ['id' => 'colaaa1', 'elType' => 'container', 'settings' => ['width' => ['unit' => '%', 'size' => 33]], 'elements' => []],
                ['id' => 'colaaa2', 'elType' => 'container', 'settings' => ['width' => ['unit' => '%', 'size' => 33]], 'elements' => []],
                ['id' => 'colaaa3', 'elType' => 'container', 'settings' => ['width' => ['unit' => '%', 'size' => 33]], 'elements' => []],
            ]],
        ],
        'rules' => ['Top-level is an ARRAY of containers.', 'Widgets are leaves (empty elements).', 'Every id unique per page.', 'Use elementor-set-layout to write; it sets edit_mode=builder and clears CSS cache.'],
    ]]);
}
```
The registration uses `meta.mcp.type=resource` and `permission_callback => '__return_true'` is NOT used — keep `wpultra_permission_callback` (resource read still requires the gate). Set `meta.annotations.readonly=true`.

- [ ] **Step 5: Lint all four files**

Run: `& $PHP -l` on each of the four files.
Expected: `No syntax errors detected`.

- [ ] **Step 6: Commit**

```bash
git add wp-ultra-mcp/includes/abilities/elementor-*.php
git commit -m "feat(plugin): Elementor abilities (get/set/patch layout + schema resource)"
```

---

### Task 9: Gutenberg abilities — ⚠️ DEFERRED TO WAVE 4 (do NOT implement in Wave 1)

> Gutenberg is part of the Wave 4 builder wave. Skip to Task 10.

#### (reference) Gutenberg abilities

**Files:**
- Create: `wp-ultra-mcp/includes/abilities/gutenberg-get-content.php`, `gutenberg-write-content.php`
- Test: lint + Task 12 integration smoke.

**Interfaces:**
- Consumes: WP `parse_blocks`, `serialize_blocks`, `get_post`, `wp_update_post`, `wpultra_ok/err`.
- Produces: `wpultra_gutenberg_get_content(array $input)`, `wpultra_gutenberg_write_content(array $input)`.

- [ ] **Step 1: Write `gutenberg-get-content.php`** (SLUG `gutenberg-get-content`, category `gutenberg`, input `{post_id:int(req), include_raw:bool}`, output `{success,post_id,blocks,raw_content?}`, meta readonly=true):

```php
function wpultra_gb_shape_blocks(array $blocks, int $depth = 0): array {
    $out = [];
    foreach ($blocks as $b) {
        if (empty($b['blockName'])) { continue; }
        $entry = ['name' => $b['blockName'], 'inner_block_count' => count($b['innerBlocks'] ?? [])];
        if (!empty($b['attrs'])) { $entry['attributes'] = $b['attrs']; }
        if ($depth < 4 && !empty($b['innerBlocks'])) { $entry['innerBlocks'] = wpultra_gb_shape_blocks($b['innerBlocks'], $depth + 1); }
        $out[] = $entry;
    }
    return $out;
}
function wpultra_gutenberg_get_content(array $input) {
    $post_id = (int) ($input['post_id'] ?? 0);
    $post = get_post($post_id);
    if (!$post) { return wpultra_err('post_not_found', "No post $post_id."); }
    $blocks = parse_blocks($post->post_content);
    $result = ['post_id' => $post_id, 'blocks' => wpultra_gb_shape_blocks($blocks)];
    if (($input['include_raw'] ?? false) === true) { $result['raw_content'] = $post->post_content; }
    return wpultra_ok($result);
}
```

- [ ] **Step 2: Write `gutenberg-write-content.php`** (SLUG `gutenberg-write-content`, category `gutenberg`, input `{post_id:int(req), block_spec:array(req)}`, output `{success,post_id}` or error with `finalization_required`, meta destructive=true):

```php
function wpultra_gb_spec_to_block(array $spec): array {
    return [
        'blockName' => $spec['name'] ?? null,
        'attrs' => (array) ($spec['attributes'] ?? []),
        'innerBlocks' => array_map('wpultra_gb_spec_to_block', (array) ($spec['innerBlocks'] ?? [])),
        'innerHTML' => '', 'innerContent' => [],
    ];
}
function wpultra_gb_is_dynamic(string $name): bool {
    if (!class_exists('WP_Block_Type_Registry')) { return false; }
    $type = WP_Block_Type_Registry::get_instance()->get_registered($name);
    return $type && $type->is_dynamic();
}
function wpultra_gb_collect_names(array $specs, array &$names): void {
    foreach ($specs as $s) { if (!empty($s['name'])) { $names[] = $s['name']; } if (!empty($s['innerBlocks'])) { wpultra_gb_collect_names($s['innerBlocks'], $names); } }
}
function wpultra_gutenberg_write_content(array $input) {
    $post_id = (int) ($input['post_id'] ?? 0);
    if (!get_post($post_id)) { return wpultra_err('post_not_found', "No post $post_id."); }
    $spec = $input['block_spec'] ?? null;
    if (!is_array($spec) || $spec === []) { return wpultra_err('bad_block_spec', 'block_spec must be a non-empty array.'); }
    $names = []; wpultra_gb_collect_names($spec, $names);
    $static = array_values(array_unique(array_filter($names, fn($n) => !wpultra_gb_is_dynamic((string) $n))));
    if ($static !== []) {
        return wpultra_err('finalization_required',
            'These blocks need JS-side save() validation and cannot be written server-side: ' . implode(', ', $static)
            . '. Use the Elementor engine (elementor-set-layout) for visual layout, or write only dynamic blocks.',
            ['static_blocks' => $static]);
    }
    $blocks = array_map('wpultra_gb_spec_to_block', $spec);
    $content = serialize_blocks($blocks);
    $res = wp_update_post(['ID' => $post_id, 'post_content' => $content], true);
    if (is_wp_error($res)) { return $res; }
    return wpultra_ok(['post_id' => $post_id]);
}
```

- [ ] **Step 3: Lint both files**

Run: `& $PHP -l` on each.
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add wp-ultra-mcp/includes/abilities/gutenberg-*.php
git commit -m "feat(plugin): Gutenberg abilities (get-content, write-content dynamic-only)"
```

---

### Task 10: Skills system (CPT + parser + sources + catalog + prompts + 4 abilities + built-ins)

**Files:**
- Create: `wp-ultra-mcp/includes/skills/parser.php`, `cpt.php`, `sources.php`, `catalog.php`, `prompts.php`, `built-in/elementor-architect.md`, `built-in/self-healing.md`, and abilities `skill-get.php`, `skill-write.php`, `skill-edit.php`, `skill-delete.php`
- Test: `tests/skills-parser.test.php`

**Interfaces:**
- Consumes: WP CPT/post APIs, `wpultra_ok/err`.
- Produces:
  - `wpultra_skill_parse_frontmatter(string $md): array` — `['name','description','enable_prompt','enable_agentic','body']`. Pure.
  - `wpultra_skill_render_md(array $skill): string`. Pure.
  - CPT `wpultra_skill`; `wpultra_skill_sources()`, `wpultra_skill_all()`, catalog filter, per-skill prompt registration; 4 CRUD abilities `wpultra_skill_get/write/edit/delete`.

- [ ] **Step 1: Write the failing test** `tests/skills-parser.test.php`

```php
<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/skills/parser.php';

it('parses frontmatter and body', function () {
    $md = "---\nname: my-skill\ndescription: Does X\nenable_prompt: false\n---\nBody line 1\nBody line 2";
    $s = wpultra_skill_parse_frontmatter($md);
    assert_eq('my-skill', $s['name']);
    assert_eq('Does X', $s['description']);
    assert_eq(false, $s['enable_prompt']);
    assert_eq(true, $s['enable_agentic'], 'defaults true');
    assert_contains('Body line 1', $s['body']);
});
it('handles missing frontmatter gracefully', function () {
    $s = wpultra_skill_parse_frontmatter('just a body');
    assert_eq('', $s['name']);
    assert_contains('just a body', $s['body']);
});
it('render round-trips', function () {
    $md = wpultra_skill_render_md(['name' => 's', 'description' => 'd', 'enable_prompt' => true, 'enable_agentic' => true, 'body' => 'hello']);
    $s = wpultra_skill_parse_frontmatter($md);
    assert_eq('s', $s['name']);
    assert_contains('hello', $s['body']);
});

run_tests();
```

- [ ] **Step 2: Run to verify failure**

Run: `& $PHP E:\wp-connector\tests\skills-parser.test.php`
Expected: FAIL — `wpultra_skill_parse_frontmatter` undefined.

- [ ] **Step 3: Write `includes/skills/parser.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

function wpultra_skill_bool($v, bool $default): bool {
    if ($v === null) { return $default; }
    $v = strtolower(trim((string) $v));
    if ($v === '') { return $default; }
    return in_array($v, ['1', 'true', 'yes', 'on'], true);
}

function wpultra_skill_parse_frontmatter(string $md): array {
    $name = ''; $description = ''; $enable_prompt = null; $enable_agentic = null; $body = $md;
    if (preg_match('/^\s*---\s*\n(.*?)\n---\s*\n?(.*)$/s', $md, $m)) {
        $body = $m[2];
        foreach (explode("\n", $m[1]) as $line) {
            if (!preg_match('/^\s*([A-Za-z_]+)\s*:\s*(.*)$/', $line, $kv)) { continue; }
            $key = strtolower($kv[1]); $val = trim($kv[2]);
            if ($key === 'name') { $name = $val; }
            elseif ($key === 'description') { $description = $val; }
            elseif ($key === 'enable_prompt') { $enable_prompt = $val; }
            elseif ($key === 'enable_agentic') { $enable_agentic = $val; }
        }
    }
    return [
        'name' => $name, 'description' => $description,
        'enable_prompt' => wpultra_skill_bool($enable_prompt, true),
        'enable_agentic' => wpultra_skill_bool($enable_agentic, true),
        'body' => ltrim($body, "\n"),
    ];
}

function wpultra_skill_render_md(array $skill): string {
    $fp = ($skill['enable_prompt'] ?? true) ? 'true' : 'false';
    $fa = ($skill['enable_agentic'] ?? true) ? 'true' : 'false';
    return "---\nname: {$skill['name']}\ndescription: {$skill['description']}\nenable_prompt: $fp\nenable_agentic: $fa\n---\n" . ($skill['body'] ?? '');
}
```

- [ ] **Step 4: Run the parser test to verify it passes**

Run: `& $PHP E:\wp-connector\tests\skills-parser.test.php`
Expected: `3 passed, 0 failed`.

- [ ] **Step 5: Write `includes/skills/cpt.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }
add_action('init', function () {
    register_post_type('wpultra_skill', [
        'public' => false, 'show_ui' => false, 'show_in_rest' => false,
        'supports' => ['title', 'editor', 'excerpt', 'revisions'], 'rewrite' => false,
    ]);
});
```

- [ ] **Step 6: Write `includes/skills/sources.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }
require_once __DIR__ . '/parser.php';

/** Return all skills (built-in + user CPT) as ['slug'=>['name','description','body','enable_prompt','enable_agentic','source']]. */
function wpultra_skill_all(): array {
    $skills = [];
    foreach (glob(__DIR__ . '/built-in/*.md') ?: [] as $file) {
        $slug = basename($file, '.md');
        $parsed = wpultra_skill_parse_frontmatter((string) file_get_contents($file));
        $skills[$slug] = $parsed + ['source' => 'built-in', 'slug' => $slug];
    }
    $posts = get_posts(['post_type' => 'wpultra_skill', 'post_status' => 'publish', 'numberposts' => 200]);
    foreach ($posts as $p) {
        $skills[$p->post_name] = [
            'name' => $p->post_name, 'description' => $p->post_excerpt, 'body' => $p->post_content,
            'enable_prompt' => get_post_meta($p->ID, '_enable_prompt', true) !== '0',
            'enable_agentic' => get_post_meta($p->ID, '_enable_agentic', true) !== '0',
            'source' => 'user-cpt', 'slug' => $p->post_name, 'post_id' => $p->ID,
        ];
    }
    return $skills;
}
```

- [ ] **Step 7: Write `includes/skills/catalog.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }
require_once __DIR__ . '/sources.php';
add_filter('wpultra_discover_abilities_instructions', function ($instructions) {
    $lines = ["## Available Skills", "Call wpultra/skill-get with a slug to load the full skill body."];
    foreach (wpultra_skill_all() as $slug => $s) {
        if (empty($s['enable_agentic'])) { continue; }
        $lines[] = "- `$slug`: " . ($s['description'] ?? '');
    }
    return trim((string) $instructions) . "\n\n" . implode("\n", $lines);
});
```

- [ ] **Step 8: Write `includes/skills/prompts.php`** (register each `enable_prompt` skill as an MCP prompt ability):

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }
require_once __DIR__ . '/sources.php';
add_action('wp_abilities_api_init', function () {
    if (!function_exists('wp_register_ability')) { return; }
    foreach (wpultra_skill_all() as $slug => $s) {
        if (empty($s['enable_prompt']) || empty($s['body'])) { continue; }
        $body = (string) $s['body'];
        wp_register_ability('wpultra/skill-prompt-' . $slug, [
            'label' => 'Skill: ' . $slug,
            'description' => (string) ($s['description'] ?? $slug),
            'category' => 'skills',
            'input_schema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
            'execute_callback' => function () use ($body) { return ['messages' => [['role' => 'user', 'content' => ['type' => 'text', 'text' => $body]]]]; },
            'permission_callback' => 'wpultra_permission_callback',
            'meta' => ['mcp' => ['public' => true, 'type' => 'prompt']],
        ]);
    }
}, 500);
```

- [ ] **Step 9: Write the 4 skill CRUD abilities** (skeleton; category `skills`). Callbacks:

`skill-get.php` (SLUG `skill-get`, input `{slug:string(req)}`, output `{success,slug,body,description}`, readonly):
```php
require_once WPULTRA_DIR . 'includes/skills/sources.php';
function wpultra_skill_get(array $input) {
    $slug = (string) ($input['slug'] ?? '');
    $all = wpultra_skill_all();
    if (!isset($all[$slug])) { return wpultra_err('not_found', "No skill '$slug'."); }
    return wpultra_ok(['slug' => $slug, 'body' => $all[$slug]['body'] ?? '', 'description' => $all[$slug]['description'] ?? '']);
}
```

`skill-write.php` (SLUG `skill-write`, input `{slug:string(req), description:string, body:string(req), enable_prompt:bool, enable_agentic:bool, on_conflict:enum[fail,replace]}`, output `{success,slug,post_id}`, destructive):
```php
require_once WPULTRA_DIR . 'includes/skills/sources.php';
function wpultra_skill_write(array $input) {
    $slug = sanitize_title((string) ($input['slug'] ?? ''));
    if ($slug === '') { return wpultra_err('bad_slug', 'slug is required.'); }
    $existing = get_page_by_path($slug, OBJECT, 'wpultra_skill');
    $on_conflict = (string) ($input['on_conflict'] ?? 'fail');
    if ($existing && $on_conflict !== 'replace') {
        return wpultra_err('conflict', "Skill '$slug' exists. Pass on_conflict: 'replace' to overwrite.");
    }
    $postarr = [
        'post_type' => 'wpultra_skill', 'post_status' => 'publish', 'post_title' => $slug, 'post_name' => $slug,
        'post_excerpt' => (string) ($input['description'] ?? ''), 'post_content' => (string) ($input['body'] ?? ''),
    ];
    if ($existing) { $postarr['ID'] = $existing->ID; }
    $id = wp_insert_post($postarr, true);
    if (is_wp_error($id)) { return $id; }
    update_post_meta($id, '_enable_prompt', ($input['enable_prompt'] ?? true) ? '1' : '0');
    update_post_meta($id, '_enable_agentic', ($input['enable_agentic'] ?? true) ? '1' : '0');
    return wpultra_ok(['slug' => $slug, 'post_id' => (int) $id]);
}
```

`skill-edit.php` (SLUG `skill-edit`, input `{slug:string(req), old_string:string(req), new_string:string(req)}`, output `{success,slug}`, destructive): load the skill body, do a unique `str_replace` (mirror edit-file's uniqueness rule), `wp_update_post` the content. Return `wpultra_err('not_found'/'not_unique')` as edit-file does.
```php
require_once WPULTRA_DIR . 'includes/skills/sources.php';
function wpultra_skill_edit(array $input) {
    $slug = (string) ($input['slug'] ?? '');
    $post = get_page_by_path($slug, OBJECT, 'wpultra_skill');
    if (!$post) { return wpultra_err('not_found', "No user skill '$slug' to edit."); }
    $old = (string) ($input['old_string'] ?? ''); $new = (string) ($input['new_string'] ?? '');
    if ($old === '') { return wpultra_err('empty_old_string', 'old_string must be non-empty.'); }
    $count = substr_count($post->post_content, $old);
    if ($count === 0) { return wpultra_err('not_found', 'old_string not found.'); }
    if ($count > 1) { return wpultra_err('not_unique', "old_string occurs $count times."); }
    $res = wp_update_post(['ID' => $post->ID, 'post_content' => str_replace($old, $new, $post->post_content)], true);
    if (is_wp_error($res)) { return $res; }
    return wpultra_ok(['slug' => $slug]);
}
```

`skill-delete.php` (SLUG `skill-delete`, input `{slug:string(req)}`, output `{success,slug,deleted}`, destructive/idempotent):
```php
function wpultra_skill_delete(array $input) {
    $slug = (string) ($input['slug'] ?? '');
    $post = get_page_by_path($slug, OBJECT, 'wpultra_skill');
    if (!$post) { return wpultra_ok(['slug' => $slug, 'deleted' => false]); }
    wp_delete_post($post->ID, true);
    return wpultra_ok(['slug' => $slug, 'deleted' => true]);
}
```

- [ ] **Step 10: Write the two built-in skills**

`built-in/elementor-architect.md`:
```markdown
---
name: elementor-architect
description: How to build Elementor layouts via the wpultra MCP server.
enable_prompt: true
enable_agentic: true
---
You are an Elementor v4 layout architect using WP-Ultra-MCP.

- A page's layout is a JSON array in _elementor_data: nodes {id(7-char), elType:container|widget, settings:{}, elements:[]}.
- A responsive 3-column row = ONE flex container (settings.container_type='flex', flex_direction='row') with THREE child containers (width 33%), each holding its widget(s).

Workflow:
1. Read the `wpultra/elementor-schema` resource for exact field shapes.
2. Build the elements array with unique ids.
3. Call `wpultra/elementor-set-layout` with {post_id, elements}. It sets edit_mode=builder and clears CSS cache.
4. For surgical edits use `wpultra/elementor-patch-element` (insert/update/delete/reorder).
5. If something breaks, call `wpultra/read-debug-log` and self-correct.
```

`built-in/self-healing.md`:
```markdown
---
name: self-healing
description: Recover from fatal errors after writing PHP/theme code.
enable_prompt: true
enable_agentic: true
---
When you write PHP (functions.php, a plugin, execute-php) and the site breaks:
1. Call `wpultra/read-debug-log` with lines: 100 to read the latest fatal.
2. Identify the file and line from the stack trace.
3. Use `wpultra/read-file` to inspect, `wpultra/edit-file` to fix the exact offending code, then re-check the log.
4. If a write made the site unrecoverable, delete the offending sandbox file with `wpultra/delete-file`.
```

- [ ] **Step 11: Lint all skill PHP files**

Run `& $PHP -l` on `parser.php`, `cpt.php`, `sources.php`, `catalog.php`, `prompts.php`, and the four `skill-*.php` abilities.
Expected: `No syntax errors detected` for each.

- [ ] **Step 12: Commit**

```bash
git add wp-ultra-mcp/includes/skills tests/skills-parser.test.php
git commit -m "feat(plugin): skills system (CPT, parser, catalog, prompts, CRUD, built-ins)"
```

---

### Task 11: Admin UI — connect page + abilities page

**Files:**
- Create: `wp-ultra-mcp/includes/admin/connect-page.php`, `abilities-page.php`, `assets/admin.css`
- Test: lint + a structural include test.

**Interfaces:**
- Consumes: WP admin/options/Application-Password APIs, `wpultra_ability_files`.
- Produces: an admin menu "WP-Ultra-MCP" with two pages; `wpultra_connect_render()`, `wpultra_abilities_render()`, and handlers for enable toggle + app-password generation.

- [ ] **Step 1: Write `includes/admin/connect-page.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

add_action('admin_menu', function () {
    add_menu_page('WP-Ultra-MCP', 'WP-Ultra-MCP', 'manage_options', 'wpultra', 'wpultra_connect_render', 'dashicons-rest-api', 80);
    add_submenu_page('wpultra', 'Abilities', 'Abilities', 'manage_options', 'wpultra-abilities', 'wpultra_abilities_render');
});

add_action('admin_post_wpultra_enable', function () {
    if (!current_user_can('manage_options') || !check_admin_referer('wpultra_enable')) { wp_die('forbidden'); }
    update_option('wpultra_enabled', '1');
    update_option('wpultra_domain', wp_parse_url(home_url(), PHP_URL_HOST));
    wp_safe_redirect(admin_url('admin.php?page=wpultra&enabled=1'));
    exit;
});

add_action('admin_post_wpultra_gen_password', function () {
    if (!current_user_can('manage_options') || !check_admin_referer('wpultra_gen_password')) { wp_die('forbidden'); }
    $user_id = get_current_user_id();
    [$password] = WP_Application_Passwords::create_new_application_password($user_id, ['name' => 'WP-Ultra-MCP']);
    set_transient('wpultra_app_password_' . $user_id, $password, 300);
    wp_safe_redirect(admin_url('admin.php?page=wpultra&pw=1'));
    exit;
});

function wpultra_connect_render(): void {
    $enabled = get_option('wpultra_enabled') === '1';
    $endpoint = rest_url('mcp/wpultra');
    $user = wp_get_current_user();
    $pw = get_transient('wpultra_app_password_' . get_current_user_id());
    echo '<div class="wrap"><h1>WP-Ultra-MCP</h1>';

    // Step 1: enable
    echo '<h2>1. Enable</h2>';
    if ($enabled) {
        echo '<p>✅ AI control is ON for ' . esc_html((string) get_option('wpultra_domain')) . '</p>';
    } else {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('wpultra_enable'); echo '<input type="hidden" name="action" value="wpultra_enable">';
        echo '<button class="button button-primary">Turn on AI control for this site</button></form>';
    }

    // Step 2: app password
    echo '<h2>2. Application Password</h2>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('wpultra_gen_password'); echo '<input type="hidden" name="action" value="wpultra_gen_password">';
    echo '<button class="button">Generate application password</button></form>';
    if ($pw) { echo '<p><strong>Copy now (shown once):</strong> <code>' . esc_html($pw) . '</code></p>'; }

    // Step 3: client config
    $shown_pw = $pw ?: 'YOUR_APP_PASSWORD';
    $http = [
        'mcpServers' => ['wp-ultra-mcp' => [
            'command' => 'npx', 'args' => ['-y', '@automattic/mcp-wordpress-remote@latest'],
            'env' => ['WP_API_URL' => $endpoint, 'WP_API_USERNAME' => $user->user_login, 'WP_API_PASSWORD' => $shown_pw],
        ]],
    ];
    echo '<h2>3. Connect your AI client</h2><p>Endpoint: <code>' . esc_html($endpoint) . '</code></p>';
    echo '<pre style="background:#1e1e1e;color:#ddd;padding:12px;overflow:auto">' . esc_html(wp_json_encode($http, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre>';
    echo '</div>';
}
```

- [ ] **Step 2: Write `includes/admin/abilities-page.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

add_action('admin_post_wpultra_save_abilities', function () {
    if (!current_user_can('manage_options') || !check_admin_referer('wpultra_save_abilities')) { wp_die('forbidden'); }
    $disabled = array_map('sanitize_text_field', (array) ($_POST['disabled'] ?? []));
    $rules = [];
    foreach ($disabled as $name) { $rules['wpultra/' . $name] = ['disabled' => true]; }
    update_option('wpultra_ability_rules', $rules);
    wp_safe_redirect(admin_url('admin.php?page=wpultra-abilities&saved=1'));
    exit;
});

function wpultra_abilities_render(): void {
    $rules = (array) get_option('wpultra_ability_rules', []);
    echo '<div class="wrap"><h1>Abilities</h1><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('wpultra_save_abilities'); echo '<input type="hidden" name="action" value="wpultra_save_abilities"><ul>';
    foreach (wpultra_ability_files() as $slug) {
        $checked = empty($rules['wpultra/' . $slug]['disabled']) ? '' : 'checked';
        echo '<li><label><input type="checkbox" name="disabled[]" value="' . esc_attr($slug) . '" ' . $checked . '> Disable <code>wpultra/' . esc_html($slug) . '</code></label></li>';
    }
    echo '</ul><button class="button button-primary">Save</button></form></div>';
}
```

- [ ] **Step 3: Write `assets/admin.css`** (minimal):

```css
.wrap pre { max-width: 900px; }
.wrap h2 { margin-top: 1.5em; }
```

- [ ] **Step 4: Structural test** `tests/admin.test.php`

```php
<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
if (!defined('WPULTRA_DIR')) { define('WPULTRA_DIR', __DIR__ . '/../wp-ultra-mcp/'); }
if (!defined('WP_CONTENT_DIR')) { define('WP_CONTENT_DIR', '/tmp'); }
if (!function_exists('get_option')) { function get_option($k, $d = false) { return $d; } }
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/bootstrap-mcp.php';
require __DIR__ . '/../wp-ultra-mcp/includes/admin/connect-page.php';
require __DIR__ . '/../wp-ultra-mcp/includes/admin/abilities-page.php';

it('admin render functions are defined', function () {
    assert_true(function_exists('wpultra_connect_render'), 'connect');
    assert_true(function_exists('wpultra_abilities_render'), 'abilities');
});

run_tests();
```
Run: `& $PHP E:\wp-connector\tests\admin.test.php`
Expected: `1 passed, 0 failed`. Lint both admin files.

- [ ] **Step 5: Commit**

```bash
git add wp-ultra-mcp/includes/admin wp-ultra-mcp/assets tests/admin.test.php
git commit -m "feat(plugin): admin connect page + abilities enable/disable page"
```

---

### Task 12: Packaging, README, and live integration smoke

**Files:**
- Create: `wp-ultra-mcp/readme.txt`, `README.md` (repo root, update), `tests/run-all.ps1`
- Verify: deploy to the Local site, activate, enable, exercise the endpoint.

**Interfaces:**
- Consumes: everything. Produces: a runnable, installable plugin + a green full test run.

- [ ] **Step 1: Write `tests/run-all.ps1`** (runs every `*.test.php` with bundled PHP, fails on any non-zero):

```powershell
$ErrorActionPreference = 'Stop'
$PHP = 'C:\Users\nisha\AppData\Roaming\Local\lightning-services\php-8.2.30+1\bin\win64\php.exe'
$fail = 0
Get-ChildItem 'E:\wp-connector\tests\*.test.php' | ForEach-Object {
    Write-Host "== $($_.Name) =="
    & $PHP $_.FullName
    if ($LASTEXITCODE -ne 0) { $fail++ }
}
if ($fail -gt 0) { Write-Error "$fail test file(s) failed"; exit 1 } else { Write-Host "ALL TEST FILES PASSED" }
```

- [ ] **Step 2: Run the full PHP test suite**

Run: `powershell -File E:\wp-connector\tests\run-all.ps1`
Expected: every test file prints its passes and ends with `ALL TEST FILES PASSED`.

- [ ] **Step 3: Lint every PHP file in the plugin**

Run (PowerShell):
```powershell
$PHP = 'C:\Users\nisha\AppData\Roaming\Local\lightning-services\php-8.2.30+1\bin\win64\php.exe'
$bad = 0
Get-ChildItem 'E:\wp-connector\wp-ultra-mcp' -Recurse -Filter *.php | Where-Object { $_.FullName -notlike '*\vendor\*' } | ForEach-Object {
    $out = & $PHP -l $_.FullName 2>&1
    if ($out -notmatch 'No syntax errors') { $bad++; Write-Host "LINT FAIL: $($_.FullName)`n$out" }
}
if ($bad -gt 0) { Write-Error "$bad file(s) failed lint" } else { Write-Host "ALL PHP LINT CLEAN" }
```
Expected: `ALL PHP LINT CLEAN`.

- [ ] **Step 4: Write `readme.txt`** (WordPress plugin readme):

```
=== WP-Ultra-MCP ===
Contributors: wpultra
Tags: mcp, ai, elementor, wp-cli, automation
Requires at least: 6.6
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later

Turn this WordPress site into an MCP server for AI CLIs (Claude Code, Gemini). Deep server-side Elementor control, raw SQL, WP-CLI, files, execute-php, and a skills system.

== Description ==
Install, enable AI control, generate an application password, and paste the config into your AI client. Then drive your whole WordPress site from the AI.

== Installation ==
1. Upload the release ZIP (with vendor/) and activate.
2. Go to WP-Ultra-MCP, enable AI control, generate an application password.
3. Copy the client config into Claude Code / Gemini and restart the MCP session.
```

- [ ] **Step 5: Update repo `README.md`** — replace the Node-server README with a plugin-focused one: what it is, the kill-shots vs Novamira, install/connect steps, the ability list, the bundled-adapter note, and that the Node server is archived under `docs/`. (Write concrete prose; no placeholders.)

- [ ] **Step 6: Deploy to the Local site and activate**

Run: `powershell -File E:\wp-connector\wp-ultra-mcp\bin\deploy.ps1`
Then (the user must have the `wp-connector` site running in Local). Using the Local site shell or bundled WP-CLI, run:
```
wp plugin activate wp-ultra-mcp
wp plugin install elementor --activate
```
Expected: both activate without fatal errors. If WP-CLI isn't on PATH, activate via the site's wp-admin Plugins screen and install Elementor there.

> Note: This step requires the Local site to be **started** in the Local app (its PHP+MySQL only run then). If the site is stopped, mark this step blocked and ask the user to start it.

- [ ] **Step 7: Enable + smoke the MCP endpoint**

1. In wp-admin → WP-Ultra-MCP: click Enable, then Generate application password (copy it).
2. From a shell with `curl`, initialize the MCP server and list tools:
```bash
curl -s -u "<wp-user>:<app-password>" -X POST "http://wp-connector.local/wp-json/mcp/wpultra" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
```
Expected: a JSON-RPC result listing the three adapter meta-tools (`discover-abilities`, `get-ability-info`, `execute-ability`).
3. Discover abilities, then execute a SELECT:
```bash
curl -s -u "<wp-user>:<app-password>" -X POST "http://wp-connector.local/wp-json/mcp/wpultra" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"mcp-adapter-execute-ability","arguments":{"ability":"wpultra/execute-wp-query","input":{"sql":"SELECT ID,post_title FROM wp_posts LIMIT 3"}}}}'
```
Expected: a result containing up to 3 post rows.
4. Create a draft page, then set an Elementor 3-column layout via `wpultra/elementor-set-layout`, and confirm in the Elementor editor that three columns render.

> Note: the exact MCP `tools/call` envelope for the adapter's execute-ability tool should be confirmed against the adapter's `tools/list` output in sub-step 1; adjust the `params` shape to match what the adapter advertises. Document the working envelope in the report.

- [ ] **Step 8: Commit**

```bash
git add wp-ultra-mcp/readme.txt README.md tests/run-all.ps1
git commit -m "chore(plugin): readme, full-suite runner, integration smoke notes"
```

---

### Task 13: Memory subsystem (CPT + save/get/list/delete)

**Files:**
- Create: `wp-ultra-mcp/includes/memory/cpt.php`, `wp-ultra-mcp/includes/abilities/memory-save.php`, `memory-get.php`, `memory-list.php`, `memory-delete.php`
- Modify: `wp-ultra-mcp/includes/bootstrap-mcp.php` (require `memory/cpt.php` inside `wpultra_load_abilities`)
- Test: `tests/memory.test.php`

**Interfaces:**
- Consumes: WP post APIs, `wpultra_ok/err`.
- Produces: CPT `wpultra_memory`; abilities `wpultra_memory_save/get/list/delete`. Data model: `{id=post->ID, name=post_title, description=post_excerpt, type=_wpultra_memory_type meta (user|feedback|project|reference), content=post_content, updated_at=post_modified_gmt}`.

- [ ] **Step 1: Write `includes/memory/cpt.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }
add_action('init', function () {
    register_post_type('wpultra_memory', [
        'public' => false, 'show_ui' => false, 'show_in_rest' => false,
        'supports' => ['title', 'editor', 'excerpt', 'revisions'], 'rewrite' => false,
    ]);
});
function wpultra_memory_shape(WP_Post $p): array {
    return [
        'id' => $p->ID, 'name' => $p->post_title, 'description' => $p->post_excerpt,
        'type' => (string) get_post_meta($p->ID, '_wpultra_memory_type', true),
        'updated_at' => $p->post_modified_gmt,
    ];
}
```

- [ ] **Step 2: Write the failing test** `tests/memory.test.php` (unit-tests the input-validation guards; full CRUD is integration). 

```php
<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
if (!function_exists('wp_register_ability')) { function wp_register_ability($n, $a) {} }
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/abilities/memory-save.php';

it('memory-save rejects an invalid type', function () {
    assert_wp_error(wpultra_memory_save(['name' => 'n', 'description' => 'd', 'content' => 'c', 'type' => 'bogus']), 'bad type');
});
it('memory-save rejects a missing name', function () {
    assert_wp_error(wpultra_memory_save(['description' => 'd', 'content' => 'c', 'type' => 'user']), 'no name');
});

run_tests();
```

- [ ] **Step 3: Run to verify failure**

Run: `& $PHP E:\wp-connector\tests\memory.test.php`
Expected: FAIL — `wpultra_memory_save` undefined.

- [ ] **Step 4: Write the four ability files** (each in the Step-0 skeleton, category `skills` → register a `memory` category in Task 3's `wpultra_register_categories` is NOT required; reuse category `diagnostics`? No — add `'memory' => 'Persistent cross-session memory.'` to the categories map in bootstrap-mcp.php Task 3, and use category `memory`).

`memory-save.php` (SLUG `memory-save`, input `{id:int, name:string, description:string, content:string, type:enum[user,feedback,project,reference]}`, output `{success,id}`, destructive):
```php
function wpultra_memory_save(array $input) {
    $type = (string) ($input['type'] ?? '');
    if (!in_array($type, ['user', 'feedback', 'project', 'reference'], true)) {
        return wpultra_err('bad_type', "type must be one of user|feedback|project|reference.");
    }
    $name = trim((string) ($input['name'] ?? ''));
    if ($name === '') { return wpultra_err('missing_name', 'name is required.'); }
    $postarr = [
        'post_type' => 'wpultra_memory', 'post_status' => 'publish', 'post_title' => $name,
        'post_excerpt' => (string) ($input['description'] ?? ''), 'post_content' => (string) ($input['content'] ?? ''),
    ];
    if (!empty($input['id'])) { $postarr['ID'] = (int) $input['id']; }
    $id = wp_insert_post($postarr, true);
    if (is_wp_error($id)) { return $id; }
    update_post_meta((int) $id, '_wpultra_memory_type', $type);
    return wpultra_ok(['id' => (int) $id]);
}
```

`memory-list.php` (SLUG `memory-list`, input `{type:string}`, output `{success,memories:[{id,name,description,type,updated_at}]}`, readonly):
```php
function wpultra_memory_list(array $input) {
    $args = ['post_type' => 'wpultra_memory', 'post_status' => 'publish', 'numberposts' => 500, 'orderby' => 'title', 'order' => 'ASC'];
    $posts = get_posts($args);
    $out = [];
    $filter = (string) ($input['type'] ?? '');
    foreach ($posts as $p) {
        $shaped = wpultra_memory_shape($p);
        if ($filter !== '' && $shaped['type'] !== $filter) { continue; }
        $out[] = $shaped;
    }
    return wpultra_ok(['memories' => $out]);
}
```

`memory-get.php` (SLUG `memory-get`, input `{id:int(req)}`, output `{success,id,name,description,type,content}`, readonly):
```php
function wpultra_memory_get(array $input) {
    $id = (int) ($input['id'] ?? 0);
    $p = get_post($id);
    if (!$p || $p->post_type !== 'wpultra_memory') { return wpultra_err('not_found', "No memory $id."); }
    return wpultra_ok(wpultra_memory_shape($p) + ['content' => $p->post_content]);
}
```

`memory-delete.php` (SLUG `memory-delete`, input `{id:int(req)}`, output `{success,id,deleted}`, destructive/idempotent):
```php
function wpultra_memory_delete(array $input) {
    $id = (int) ($input['id'] ?? 0);
    $p = get_post($id);
    if (!$p || $p->post_type !== 'wpultra_memory') { return wpultra_ok(['id' => $id, 'deleted' => false]); }
    wp_delete_post($id, true);
    return wpultra_ok(['id' => $id, 'deleted' => true]);
}
```

Each `*.php` requires the CPT helper: start the files that call `wpultra_memory_shape` with `require_once WPULTRA_DIR . 'includes/memory/cpt.php';`.

- [ ] **Step 5: Wire the CPT loader + category**

In `includes/bootstrap-mcp.php`: inside `wpultra_load_abilities()`, after the abilities loop, add:
```php
if (is_readable(WPULTRA_DIR . 'includes/memory/cpt.php')) { require_once WPULTRA_DIR . 'includes/memory/cpt.php'; }
```
And in `wpultra_register_categories()` add `'memory' => 'Persistent cross-session memory.'` to the `$cats` map.

- [ ] **Step 6: Run the test + lint**

Run: `& $PHP E:\wp-connector\tests\memory.test.php`
Expected: `2 passed, 0 failed`.
Lint all five memory files.

- [ ] **Step 7: Commit**

```bash
git add wp-ultra-mcp/includes/memory wp-ultra-mcp/includes/abilities/memory-*.php wp-ultra-mcp/includes/bootstrap-mcp.php tests/memory.test.php
git commit -m "feat(plugin): persistent memory (CPT + save/get/list/delete)"
```

---

### Task 14: WP content abilities (create/update/delete-post)

**Files:**
- Create: `wp-ultra-mcp/includes/abilities/create-post.php`, `update-post.php`, `delete-post.php`
- Test: `tests/wp-content.test.php`

**Interfaces:**
- Consumes: WP post APIs, `wpultra_ok/err`.
- Produces: `wpultra_create_post/update_post/delete_post`. Our delta vs PRO: taxonomy term assignment via `wp_set_post_terms`.

- [ ] **Step 1: Write the failing test** `tests/wp-content.test.php`

```php
<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
if (!function_exists('wp_register_ability')) { function wp_register_ability($n, $a) {} }
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/abilities/create-post.php';
require __DIR__ . '/../wp-ultra-mcp/includes/abilities/delete-post.php';

it('create-post requires a title', function () {
    assert_wp_error(wpultra_create_post(['content' => 'x']), 'no title');
});
it('delete-post requires a post_id', function () {
    assert_wp_error(wpultra_delete_post([]), 'no id');
});

run_tests();
```

- [ ] **Step 2: Run to verify failure**

Run: `& $PHP E:\wp-connector\tests\wp-content.test.php`
Expected: FAIL — `wpultra_create_post` undefined.

- [ ] **Step 3: Write `create-post.php`** (SLUG `create-post`, category `diagnostics`→ no; add category `content` to bootstrap categories map and use it; input `{title:string(req), content:string, status:enum[publish,draft,pending,private,future], post_type:string, excerpt:string, slug:string, parent:int, author:int, date:string, meta:object, terms:object}`, output `{success,post_id,permalink,edit_url}`, destructive):

```php
function wpultra_create_post(array $input) {
    $title = (string) ($input['title'] ?? $input['post_title'] ?? '');
    if (trim($title) === '') { return wpultra_err('missing_title', 'title is required.'); }
    $postarr = [
        'post_title' => $title,
        'post_content' => (string) ($input['content'] ?? $input['post_content'] ?? ''),
        'post_excerpt' => (string) ($input['excerpt'] ?? ''),
        'post_status' => (string) ($input['status'] ?? 'draft'),
        'post_type' => (string) ($input['post_type'] ?? 'page'),
    ];
    if (!empty($input['slug'])) { $postarr['post_name'] = sanitize_title((string) $input['slug']); }
    if (!empty($input['parent'])) { $postarr['post_parent'] = (int) $input['parent']; }
    if (!empty($input['author'])) { $postarr['post_author'] = (int) $input['author']; }
    if (!empty($input['date'])) { $postarr['post_date'] = (string) $input['date']; }
    if (!empty($input['meta']) && is_array($input['meta'])) { $postarr['meta_input'] = $input['meta']; }
    $id = wp_insert_post($postarr, true);
    if (is_wp_error($id)) { return $id; }
    if (!empty($input['terms']) && is_array($input['terms'])) {
        foreach ($input['terms'] as $tax => $terms) { wp_set_post_terms((int) $id, (array) $terms, (string) $tax); }
    }
    return wpultra_ok(['post_id' => (int) $id, 'permalink' => get_permalink($id), 'edit_url' => get_edit_post_link($id, 'raw')]);
}
```

- [ ] **Step 4: Write `update-post.php`** (SLUG `update-post`, input `{post_id:int(req), title, content, status, excerpt, slug, menu_order, featured_image_id, meta, terms}`, output `{success,post_id,updated_fields}`, destructive):

```php
function wpultra_update_post(array $input) {
    $id = (int) ($input['post_id'] ?? $input['id'] ?? 0);
    if ($id <= 0 || !get_post($id)) { return wpultra_err('not_found', 'Valid post_id is required.'); }
    $postarr = ['ID' => $id]; $updated = [];
    $map = ['title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt', 'status' => 'post_status'];
    foreach ($map as $in => $col) { if (array_key_exists($in, $input)) { $postarr[$col] = (string) $input[$in]; $updated[] = $in; } }
    if (array_key_exists('slug', $input)) { $postarr['post_name'] = sanitize_title((string) $input['slug']); $updated[] = 'slug'; }
    if (array_key_exists('menu_order', $input)) { $postarr['menu_order'] = (int) $input['menu_order']; $updated[] = 'menu_order'; }
    if (count($postarr) > 1) { $res = wp_update_post($postarr, true); if (is_wp_error($res)) { return $res; } }
    if (!empty($input['meta']) && is_array($input['meta'])) {
        foreach ($input['meta'] as $k => $v) { update_post_meta($id, (string) $k, $v); }
        $updated[] = 'meta';
    }
    if (!empty($input['terms']) && is_array($input['terms'])) {
        foreach ($input['terms'] as $tax => $terms) { wp_set_post_terms($id, (array) $terms, (string) $tax); }
        $updated[] = 'terms';
    }
    if (array_key_exists('featured_image_id', $input)) {
        $fid = (int) $input['featured_image_id'];
        if ($fid === 0) { delete_post_thumbnail($id); } else { set_post_thumbnail($id, $fid); }
        $updated[] = 'featured_image';
    }
    return wpultra_ok(['post_id' => $id, 'updated_fields' => $updated]);
}
```

- [ ] **Step 5: Write `delete-post.php`** (SLUG `delete-post`, input `{post_id:int(req), force:bool}`, output `{success,post_id,result}`, destructive):

```php
function wpultra_delete_post(array $input) {
    $id = (int) ($input['post_id'] ?? $input['id'] ?? 0);
    if ($id <= 0) { return wpultra_err('missing_id', 'post_id is required.'); }
    $p = get_post($id);
    if (!$p) { return wpultra_err('not_found', "No post $id."); }
    $force = ($input['force'] ?? false) === true;
    if ($force || $p->post_status === 'trash') { wp_delete_post($id, true); $result = 'deleted'; }
    else { wp_trash_post($id); $result = 'trashed'; }
    return wpultra_ok(['post_id' => $id, 'result' => $result]);
}
```

- [ ] **Step 6: Add the `content` category**

In `includes/bootstrap-mcp.php` `wpultra_register_categories()` `$cats` map add `'content' => 'WordPress posts, pages, and CPTs.'`. Use `category => 'content'` in all three ability registrations.

- [ ] **Step 7: Run the test + lint**

Run: `& $PHP E:\wp-connector\tests\wp-content.test.php`
Expected: `2 passed, 0 failed`. Lint all three files.

- [ ] **Step 8: Commit**

```bash
git add wp-ultra-mcp/includes/abilities/create-post.php wp-ultra-mcp/includes/abilities/update-post.php wp-ultra-mcp/includes/abilities/delete-post.php wp-ultra-mcp/includes/bootstrap-mcp.php tests/wp-content.test.php
git commit -m "feat(plugin): WP content abilities (create/update/delete-post + taxonomy)"
```

---

## Self-Review

**Wave 1 task order for execution:** 1, 2, 3, 4, 5, 6, 13, 14, 10, 11, 12. (Tasks 7, 8, 9 are DEFERRED — skip.) Task 12's integration smoke uses `execute-wp-query` + `create-post` + `memory-save` instead of the deferred Elementor calls.

**Spec coverage:**
- Plugin architecture on bundled mcp-adapter (§2,§3) → Tasks 1, 3. ✅
- Security model (§4): permission gate → Task 2; path-jail/sandbox → Task 2; SQL params/confirm → Tasks 2, 6; wp-cli arg array → Task 5; execute-php wrapper → Task 5. ✅
- 19 abilities (§5): filesystem (T4), code (T5), SQL+diagnostics (T6), Elementor 3+schema (T7,T8), Gutenberg (T9), skills CRUD (T10). ✅
- Resources (§5 elementor-schema, §8 skills prompts) → T8 (resource), T10 (per-skill prompts). ✅
- Admin connect + abilities pages (§6) → T11. ✅
- Packaging/build/zip (§7) → T1 (build-zip), T12 (readme, integration). ✅
- Testing strategy (§8) → harness (T1) + per-task unit tests + T12 integration. ✅

**Placeholder scan:** No TBD/TODO. Every code step has complete code. The two "write concrete prose" steps (README §12.5) and the integration envelope (§12.7) are explicitly flagged as needing confirmation against live output — acceptable because they depend on runtime values, and the surrounding steps give the exact shape to verify.

**Type/name consistency:** Ability slugs in `wpultra_ability_files()` (Task 3) match the 19 ability files created in Tasks 4–10 (filesystem 5, code 2, db/diag 2, elementor 4, gutenberg 2, skills 4 = 19). Callback names follow `wpultra_<slug_with_underscores>`. `wpultra_ok`/`wpultra_err`/`wpultra_resolve_path`/`wpultra_classify_query` (Task 2) are consumed consistently. Engine functions (`wpultra_elementor_validate_elements/compact/patch/new_id/walk`, Task 7) match their use in Task 8. Skills parser/sources signatures (Task 10) match catalog/prompts/abilities use.

**Known constraints documented:** No system Composer → deps vendored from Novamira (T1); tests are bundled-PHP harness + lint, with live integration gated on the Local site running (T12). v1 ships Gutenberg dynamic-block writes only (static blocks return `finalization_required`) — per the approved spec.
