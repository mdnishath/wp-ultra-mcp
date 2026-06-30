# WP-Ultra-MCP — Wave 7 SEO · Plan 1: Foundation + On-page Meta

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.
>
> **Plan 1 of the Wave 7 program** (spec: `docs/superpowers/specs/2026-07-01-wp-ultra-seo-wave7-design.md`). Wave 7 ships as **v0.11.0** at Plan 4 — do NOT bump version or release here. Built in 4 plans, each independently testable, because the Yoast/Rank Math meta API must be live-verified before later phases. Plan order:
> 1. **Foundation + on-page meta** ← THIS PLAN (installs Yoast; the mode-aware meta driver + native renderer + status/get/set/analyze)
> 2. Internal linking + research
> 3. Technical + local (installs Rank Math; re-verifies the driver across plugins)
> 4. Bulk/audit + quick-setup + skill + ship v0.11.0

**Goal:** Install Yoast on the test site and build the SEO foundation — a mode-aware meta driver (Yoast / Rank Math / native), a native `wp_head` renderer, and 4 abilities (`seo-status`, `seo-get-meta`, `seo-set-meta`, `seo-analyze-page`).

**Architecture:** `includes/seo/` mirrors `includes/woocommerce/`. `setup.php` detects which SEO plugin is active and reports status. `meta.php` is the driver: one canonical SEO field set, routed to Yoast meta keys, Rank Math meta keys, or a native `_wpultra_seo_*` store depending on mode. `head.php` outputs SEO tags on `wp_head` only in native mode. `analyze.php` is a pure on-page scorer. Thin abilities call the engine + audit.

**Tech Stack:** PHP 8.0+ (`declare(strict_types=1)`), WP 7.0, Yoast SEO (installed in Task 1), the WP postmeta + `wp_head`/`document_title_parts` APIs, vendored mcp-adapter, Abilities API. No new Composer/npm deps.

## Global Constraints

- Every PHP file: `<?php` + `declare(strict_types=1);` + `if (!defined('ABSPATH')) { exit(); }`. (Pure-testable engine files may use the `if (!defined('ABSPATH') && !defined('WPULTRA_TEST')) {}` harness-load guard — see `includes/woocommerce/schema.php` / `bridge.php` for the exact shape.)
- Engine functions return arrays/values or `WP_Error` (via `wpultra_err($code,$msg,$data='')`). Abilities return `wpultra_ok([...])` or the `WP_Error`. Helpers in `wp-ultra-mcp/includes/helpers.php`.
- **Ability registration MUST match the codebase shape** — copy `wp-ultra-mcp/includes/abilities/woo-upsert-product.php` (write) / `woo-get-product.php` (read): `wp_register_ability('wpultra/<slug>',[...])`, `label`/`description` via `__()`, `category=>'seo'`, `input_schema` with `properties` a PLAIN ARRAY (never `(object)`), `output_schema`, a NAMED STRING `execute_callback`, `permission_callback=>'wpultra_permission_callback'`, `meta=>['show_in_rest'=>true,'mcp'=>['public'=>true,'type'=>'tool'],'annotations'=>[...]]`.
- Read abilities (`seo-status`, `seo-get-meta`, `seo-analyze-page`): `['readonly'=>true,'destructive'=>false,'idempotent'=>true]`, NO audit. Write abilities (`seo-set-meta`): `['readonly'=>false,'destructive'=>false,'idempotent'=>false]` + `wpultra_audit_log('<slug>',<summary>,$ok)` after the write.
- **New category `seo`** registered in THREE places (Task 4): `wpultra_register_categories()` `$cats` map; each slug in BOTH `wpultra_ability_files()` and `wpultra_ability_category_map()['seo']`; add a `seo` engine require loop to `wpultra_load_abilities()` (mirror the woocommerce loop, gated on the `seo` category not disabled).
- **`tests/bootstrap.test.php` asserts the EXACT count** (currently `77`). Each task that adds abilities bumps it. Final after this plan: **81**.
- **MODE detection (canonical):** `wpultra_seo_mode()` returns `'yoast'` if `defined('WPSEO_VERSION')`, else `'rankmath'` if `defined('RANK_MATH_VERSION')` (or `class_exists('RankMath\\Helper')`), else `'native'`.
- **Canonical SEO field set** (the driver's contract, same under every mode): `title` (string), `description` (string), `focus_keyword` (string), `canonical` (string url), `robots_noindex` (bool), `robots_nofollow` (bool), `og_title` (string), `og_description` (string), `og_image` (string url), `twitter_title` (string), `twitter_description` (string).
- **Verified meta keys** (confirm live in Task 1/2): Yoast — `_yoast_wpseo_title`, `_yoast_wpseo_metadesc`, `_yoast_wpseo_focuskw`, `_yoast_wpseo_canonical`, `_yoast_wpseo_meta-robots-noindex` (`'1'`=noindex), `_yoast_wpseo_meta-robots-nofollow` (`'1'`=nofollow), `_yoast_wpseo_opengraph-title/-description/-image`, `_yoast_wpseo_twitter-title/-description`. Rank Math — `rank_math_title`, `rank_math_description`, `rank_math_focus_keyword`, `rank_math_canonical_url`, `rank_math_robots` (array containing `'noindex'`/`'nofollow'`), `rank_math_facebook_title/-description/-image`, `rank_math_twitter_title/-description`. Native — `_wpultra_seo_title/_desc/_focuskw/_canonical/_noindex/_nofollow/_og_title/_og_desc/_og_image/_tw_title/_tw_desc`.
- Bundled PHP: `$PHP = C:/Users/nisha/AppData/Roaming/Local/lightning-services/php-8.2.30+1/bin/win64/php.exe`. Test site root: `C:/Users/nisha/Local Sites/wp-connector/app/public`. Live token: `wpultra-test-9a88`.
- **Re-run `wp-ultra-mcp/bin/deploy.ps1` after every commit.** Commands from `E:\wp-connector`. Live-test probes: token-gated webroot scripts, require engine + ability files, `wp_set_current_user(<admin id>)`, clean up, delete the script.
- **Harness:** `it`, `assert_eq` (strict), `assert_true`, `assert_wp_error`; ends `run_tests();`. `tests/run-all.ps1` auto-globs. Pure tests stub nothing WP. Engine files reference WP functions only inside bodies.
- Commit messages: `feat(seo):` / `test(seo):`; end body with `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.

## File Structure

```
wp-ultra-mcp/includes/
  seo/
    setup.php    NEW — wpultra_seo_mode / wpultra_seo_status (Task 1)
    meta.php     NEW — driver: keymap + get/set + pure validate (Task 2)
    head.php     NEW — native wp_head renderer (Task 3)
    analyze.php  NEW — pure on-page scorer (Task 6)
  abilities/
    seo-status.php        NEW (Task 4)
    seo-get-meta.php      NEW (Task 5)
    seo-set-meta.php      NEW (Task 5)
    seo-analyze-page.php  NEW (Task 6)
  bootstrap-mcp.php  MODIFY — register seo category + engine loop + 4 slugs (Tasks 4–6)
tests/
  seo-meta.test.php     NEW — pure validate/keymap tests (Task 2)
  seo-analyze.test.php  NEW — pure scorer tests (Task 6)
  bootstrap.test.php    MODIFY — count 77 → 81 (Tasks 4–6)
```

---

### Task 1: Install Yoast + `setup.php` (mode detection + status)

**Files:**
- Create: `wp-ultra-mcp/includes/seo/setup.php`
- (No unit test — detection is live-verified; calls WP/plugin globals.)

**Interfaces:**
- Produces: `wpultra_seo_mode(): string` (`yoast`|`rankmath`|`native`), `wpultra_seo_status(): array` (keys `mode, plugin_version, sitemap_enabled, posts_total, posts_missing_desc`).

- [ ] **Step 1: Install + activate Yoast SEO on the test site.** If wp-cli is available: `wp --path="C:/Users/nisha/Local Sites/wp-connector/app/public" plugin install wordpress-seo --activate`. Else use the token-gated install pattern (mirror the WooCommerce Plan-1 install script: `plugins_api('plugin_information',['slug'=>'wordpress-seo'])` → `Plugin_Upgrader->install()` → `activate_plugin('wordpress-seo/wp-seo.php')`), curl with `?t=wpultra-test-9a88`, confirm active, delete the script.
Expected: Yoast active; `defined('WPSEO_VERSION')` true. Record the version.

- [ ] **Step 2: Write `setup.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/** Which SEO plugin is driving meta: yoast | rankmath | native. */
function wpultra_seo_mode(): string {
    if (defined('WPSEO_VERSION')) { return 'yoast'; }
    if (defined('RANK_MATH_VERSION') || class_exists('RankMath\\Helper')) { return 'rankmath'; }
    return 'native';
}

function wpultra_seo_plugin_version(): string {
    if (defined('WPSEO_VERSION')) { return (string) WPSEO_VERSION; }
    if (defined('RANK_MATH_VERSION')) { return (string) RANK_MATH_VERSION; }
    return '';
}

function wpultra_seo_status(): array {
    $mode = wpultra_seo_mode();
    $counts = wp_count_posts('post');
    $published = (int) ($counts->publish ?? 0);
    return [
        'mode'            => $mode,
        'plugin_version'  => wpultra_seo_plugin_version(),
        'sitemap_enabled' => (bool) get_option('blog_public', 1),
        'site_name'       => get_bloginfo('name'),
        'home_url'        => home_url('/'),
        'posts_published' => $published,
    ];
}
```

- [ ] **Step 3: Lint** — `& $PHP -l wp-ultra-mcp/includes/seo/setup.php` → `No syntax errors detected`.

- [ ] **Step 4: Live-verify mode detection** (token-gated probe `$WP/wp-content/wpultra-seocheck.php`): require wp-load + token-gate + require the plugin's `includes/seo/setup.php`, echo `wpultra_seo_status()` as JSON. curl it; expect `mode:'yoast'` + a `plugin_version`. **Record the Yoast version + confirm `mode` is `yoast`** (memory note for Plan 3 which switches to Rank Math). Delete the probe.

- [ ] **Step 5: Commit**

```bash
git add wp-ultra-mcp/includes/seo/setup.php
git commit -m "feat(seo): SEO plugin detection + status (yoast/rankmath/native)"
```

---

### Task 2: `meta.php` — the driver (keymap + get/set + pure validation)

**Files:**
- Create: `wp-ultra-mcp/includes/seo/meta.php`
- Test: `tests/seo-meta.test.php`

**Interfaces:**
- Consumes: `wpultra_seo_mode()` (Task 1).
- Produces:
  - `wpultra_seo_validate_meta(array $input): array` — `['clean'=>[...], 'rejected'=>[{field,reason}], 'warnings'=>[{field,note}]]` (PURE). Unknown field → rejected `unknown_field`; coerces booleans; warns title >60 chars, description <120 or >160 chars.
  - `wpultra_seo_keymap(string $mode): array` — canonical field → meta key (for the flat string fields; robots handled specially) (PURE for yoast/native; rankmath robots noted).
  - `wpultra_seo_get_meta(int $post_id): array` — canonical field set for a post, mode-aware.
  - `wpultra_seo_set_meta(int $post_id, array $fields): array|WP_Error` — `['post_id'=>int, 'rejected'=>[...], 'warnings'=>[...]]`.

- [ ] **Step 1: Write the failing test** — `tests/seo-meta.test.php`

```php
<?php
require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../wp-ultra-mcp/includes/seo/meta.php';

it('validate keeps known fields + coerces bool', function () {
    $r = wpultra_seo_validate_meta(['title' => 'Hi', 'robots_noindex' => 'yes']);
    assert_eq('Hi', $r['clean']['title']);
    assert_eq(true, $r['clean']['robots_noindex']);
    assert_eq([], $r['rejected']);
});

it('validate rejects unknown field', function () {
    $r = wpultra_seo_validate_meta(['title' => 'Hi', 'bogus' => 1]);
    assert_eq(1, count($r['rejected']));
    assert_eq('bogus', $r['rejected'][0]['field']);
    assert_eq('unknown_field', $r['rejected'][0]['reason']);
});

it('validate warns on long title and short description', function () {
    $long = str_repeat('a', 70);
    $r = wpultra_seo_validate_meta(['title' => $long, 'description' => 'short']);
    $warnFields = array_map(function ($w) { return $w['field']; }, $r['warnings']);
    assert_true(in_array('title', $warnFields, true));
    assert_true(in_array('description', $warnFields, true));
});

it('keymap maps yoast title key', function () {
    $m = wpultra_seo_keymap('yoast');
    assert_eq('_yoast_wpseo_title', $m['title']);
    assert_eq('_yoast_wpseo_metadesc', $m['description']);
});

it('keymap maps native title key', function () {
    $m = wpultra_seo_keymap('native');
    assert_eq('_wpultra_seo_title', $m['title']);
});

run_tests();
```

- [ ] **Step 2: Run → fail** — `& $PHP tests/seo-meta.test.php` → FAIL (`wpultra_seo_validate_meta` undefined).

- [ ] **Step 3: Write `meta.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH') && !defined('WPULTRA_TEST')) { /* allow harness load */ }

function wpultra_seo_fields(): array {
    return ['title', 'description', 'focus_keyword', 'canonical', 'robots_noindex', 'robots_nofollow', 'og_title', 'og_description', 'og_image', 'twitter_title', 'twitter_description'];
}

function wpultra_seo_bool_fields(): array { return ['robots_noindex', 'robots_nofollow']; }

function wpultra_seo_coerce_bool($v): bool {
    if (is_bool($v)) { return $v; }
    if (is_int($v)) { return $v !== 0; }
    return in_array(strtolower(trim((string) $v)), ['1', 'yes', 'true', 'on'], true);
}

function wpultra_seo_validate_meta(array $input): array {
    $fields = wpultra_seo_fields();
    $bools = wpultra_seo_bool_fields();
    $clean = [];
    $rejected = [];
    $warnings = [];
    foreach ($input as $k => $v) {
        if (!in_array($k, $fields, true)) { $rejected[] = ['field' => $k, 'reason' => 'unknown_field']; continue; }
        if (in_array($k, $bools, true)) { $clean[$k] = wpultra_seo_coerce_bool($v); continue; }
        $clean[$k] = (string) $v;
    }
    if (isset($clean['title']) && strlen($clean['title']) > 60) {
        $warnings[] = ['field' => 'title', 'note' => 'Title over 60 chars may be truncated in search results.'];
    }
    if (isset($clean['description'])) {
        $len = strlen($clean['description']);
        if ($len > 0 && $len < 120) { $warnings[] = ['field' => 'description', 'note' => 'Meta description under 120 chars; aim for 120–160.']; }
        if ($len > 160) { $warnings[] = ['field' => 'description', 'note' => 'Meta description over 160 chars may be truncated.']; }
    }
    return ['clean' => $clean, 'rejected' => $rejected, 'warnings' => $warnings];
}

/** Flat string-field key map per mode. robots_* are handled specially in get/set. */
function wpultra_seo_keymap(string $mode): array {
    if ($mode === 'yoast') {
        return [
            'title' => '_yoast_wpseo_title', 'description' => '_yoast_wpseo_metadesc',
            'focus_keyword' => '_yoast_wpseo_focuskw', 'canonical' => '_yoast_wpseo_canonical',
            'og_title' => '_yoast_wpseo_opengraph-title', 'og_description' => '_yoast_wpseo_opengraph-description',
            'og_image' => '_yoast_wpseo_opengraph-image', 'twitter_title' => '_yoast_wpseo_twitter-title',
            'twitter_description' => '_yoast_wpseo_twitter-description',
        ];
    }
    if ($mode === 'rankmath') {
        return [
            'title' => 'rank_math_title', 'description' => 'rank_math_description',
            'focus_keyword' => 'rank_math_focus_keyword', 'canonical' => 'rank_math_canonical_url',
            'og_title' => 'rank_math_facebook_title', 'og_description' => 'rank_math_facebook_description',
            'og_image' => 'rank_math_facebook_image', 'twitter_title' => 'rank_math_twitter_title',
            'twitter_description' => 'rank_math_twitter_description',
        ];
    }
    return [
        'title' => '_wpultra_seo_title', 'description' => '_wpultra_seo_desc',
        'focus_keyword' => '_wpultra_seo_focuskw', 'canonical' => '_wpultra_seo_canonical',
        'og_title' => '_wpultra_seo_og_title', 'og_description' => '_wpultra_seo_og_desc',
        'og_image' => '_wpultra_seo_og_image', 'twitter_title' => '_wpultra_seo_tw_title',
        'twitter_description' => '_wpultra_seo_tw_desc',
    ];
}

function wpultra_seo_get_meta(int $post_id): array {
    $mode = wpultra_seo_mode();
    $map = wpultra_seo_keymap($mode);
    $out = [];
    foreach ($map as $field => $key) { $out[$field] = (string) get_post_meta($post_id, $key, true); }
    // robots (special per mode)
    if ($mode === 'rankmath') {
        $robots = get_post_meta($post_id, 'rank_math_robots', true);
        $robots = is_array($robots) ? $robots : [];
        $out['robots_noindex'] = in_array('noindex', $robots, true);
        $out['robots_nofollow'] = in_array('nofollow', $robots, true);
    } elseif ($mode === 'yoast') {
        $out['robots_noindex'] = (get_post_meta($post_id, '_yoast_wpseo_meta-robots-noindex', true) === '1');
        $out['robots_nofollow'] = (get_post_meta($post_id, '_yoast_wpseo_meta-robots-nofollow', true) === '1');
    } else {
        $out['robots_noindex'] = ((string) get_post_meta($post_id, '_wpultra_seo_noindex', true) === '1');
        $out['robots_nofollow'] = ((string) get_post_meta($post_id, '_wpultra_seo_nofollow', true) === '1');
    }
    $out['mode'] = $mode;
    return $out;
}

function wpultra_seo_set_meta(int $post_id, array $fields) {
    if (!get_post($post_id)) { return wpultra_err('post_not_found', "No post with id $post_id."); }
    $v = wpultra_seo_validate_meta($fields);
    $mode = wpultra_seo_mode();
    $map = wpultra_seo_keymap($mode);
    foreach ($v['clean'] as $field => $val) {
        if (isset($map[$field])) { update_post_meta($post_id, $map[$field], $val); continue; }
        // robots specials
        if ($field === 'robots_noindex') {
            if ($mode === 'rankmath') { wpultra_seo_rankmath_robots($post_id, 'noindex', (bool) $val); }
            elseif ($mode === 'yoast') { update_post_meta($post_id, '_yoast_wpseo_meta-robots-noindex', $val ? '1' : '2'); }
            else { update_post_meta($post_id, '_wpultra_seo_noindex', $val ? '1' : '0'); }
        } elseif ($field === 'robots_nofollow') {
            if ($mode === 'rankmath') { wpultra_seo_rankmath_robots($post_id, 'nofollow', (bool) $val); }
            elseif ($mode === 'yoast') { update_post_meta($post_id, '_yoast_wpseo_meta-robots-nofollow', $val ? '1' : ''); }
            else { update_post_meta($post_id, '_wpultra_seo_nofollow', $val ? '1' : '0'); }
        }
    }
    return ['post_id' => $post_id, 'rejected' => $v['rejected'], 'warnings' => $v['warnings']];
}

/** Toggle a value inside Rank Math's array-form robots meta. */
function wpultra_seo_rankmath_robots(int $post_id, string $flag, bool $on): void {
    $robots = get_post_meta($post_id, 'rank_math_robots', true);
    $robots = is_array($robots) ? $robots : [];
    $robots = array_values(array_filter($robots, function ($r) use ($flag) { return $r !== $flag; }));
    if ($on) { $robots[] = $flag; }
    update_post_meta($post_id, 'rank_math_robots', $robots);
}
```

- [ ] **Step 4: Run → pass** — `& $PHP tests/seo-meta.test.php` → PASS (5 `it`).

- [ ] **Step 5: Lint + commit**

```bash
& $PHP -l wp-ultra-mcp/includes/seo/meta.php
git add wp-ultra-mcp/includes/seo/meta.php tests/seo-meta.test.php
git commit -m "feat(seo): mode-aware meta driver (yoast/rankmath/native) + pure validation (+tests)"
```

---

### Task 3: `head.php` — native `wp_head` renderer

**Files:**
- Create: `wp-ultra-mcp/includes/seo/head.php`
- (Live-verified; the render is WP-runtime.)

**Interfaces:**
- Consumes: `wpultra_seo_mode()`, `wpultra_seo_get_meta()`.
- Produces: registers `wp_head` + `document_title_parts` + `pre_get_document_title` hooks that output native SEO tags ONLY when `wpultra_seo_mode() === 'native'`. No new ability.

- [ ] **Step 1: Write `head.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/** True when WE own SEO output (no Yoast/Rank Math active). */
function wpultra_seo_native_active(): bool {
    return function_exists('wpultra_seo_mode') && wpultra_seo_mode() === 'native';
}

add_filter('pre_get_document_title', 'wpultra_seo_filter_title', 20);
function wpultra_seo_filter_title($title) {
    if (!wpultra_seo_native_active() || !is_singular()) { return $title; }
    $custom = (string) get_post_meta(get_queried_object_id(), '_wpultra_seo_title', true);
    return $custom !== '' ? $custom : $title;
}

add_action('wp_head', 'wpultra_seo_render_head', 1);
function wpultra_seo_render_head(): void {
    if (!wpultra_seo_native_active() || !is_singular()) { return; }
    $id = get_queried_object_id();
    $m = wpultra_seo_get_meta($id);
    $out = '';
    if ($m['description'] !== '') { $out .= '<meta name="description" content="' . esc_attr($m['description']) . '">' . "\n"; }
    if ($m['canonical'] !== '') { $out .= '<link rel="canonical" href="' . esc_url($m['canonical']) . '">' . "\n"; }
    $robots = [];
    if (!empty($m['robots_noindex'])) { $robots[] = 'noindex'; }
    if (!empty($m['robots_nofollow'])) { $robots[] = 'nofollow'; }
    if ($robots) { $out .= '<meta name="robots" content="' . esc_attr(implode(',', $robots)) . '">' . "\n"; }
    $ogTitle = $m['og_title'] !== '' ? $m['og_title'] : ($m['title'] !== '' ? $m['title'] : get_the_title($id));
    $ogDesc = $m['og_description'] !== '' ? $m['og_description'] : $m['description'];
    $out .= '<meta property="og:title" content="' . esc_attr($ogTitle) . '">' . "\n";
    if ($ogDesc !== '') { $out .= '<meta property="og:description" content="' . esc_attr($ogDesc) . '">' . "\n"; }
    if ($m['og_image'] !== '') { $out .= '<meta property="og:image" content="' . esc_url($m['og_image']) . '">' . "\n"; }
    echo "<!-- WP-Ultra-MCP SEO -->\n" . $out . "<!-- /WP-Ultra-MCP SEO -->\n"; // phpcs:ignore
}
```

- [ ] **Step 2: Lint** — `& $PHP -l wp-ultra-mcp/includes/seo/head.php`.

- [ ] **Step 3: Live-verify native output.** Because Yoast is active (Task 1), native mode is dormant. Probe: temporarily — in the probe script only — assert that with Yoast active, `wpultra_seo_native_active()` returns `false` (so we do NOT double-output). Then a SECOND probe (or the same, after `deactivate_plugins('wordpress-seo/wp-seo.php')` then re-activate at the end) sets `_wpultra_seo_title`/`_wpultra_seo_desc` on a draft post, fetches the post's permalink HTML via ONE `wp_remote_get`, and asserts the `<!-- WP-Ultra-MCP SEO -->` block + the description meta appear, then re-activates Yoast and deletes the post. (Follow the "ONE self-request only" rule from project memory — never nest remote requests.) Delete the probe.
Expected: native_active false under Yoast; native block present + Yoast absent when Yoast deactivated.

- [ ] **Step 4: Commit**

```bash
git add wp-ultra-mcp/includes/seo/head.php
git commit -m "feat(seo): native wp_head SEO renderer (gated to no-plugin mode)"
```

---

### Task 4: `seo-status` ability + `seo` category wiring + native head load

**Files:**
- Create: `wp-ultra-mcp/includes/abilities/seo-status.php`
- Modify: `bootstrap-mcp.php` (register category, engine loop, slug), `tests/bootstrap.test.php` (77 → 78)

**Interfaces:**
- Consumes: `wpultra_seo_status()`.
- Produces: ability `wpultra/seo-status`; the `seo` category + engine require loop other SEO abilities rely on.

- [ ] **Step 1: Write the ability** — `wp-ultra-mcp/includes/abilities/seo-status.php`

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/seo-status', [
    'label'       => __('SEO: Status', 'wp-ultra-mcp'),
    'description' => __('Report the SEO setup: active mode (yoast/rankmath/native), plugin version, sitemap state, site name/url, published post count.', 'wp-ultra-mcp'),
    'category'    => 'seo',
    'input_schema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean'], 'status' => ['type' => 'object']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_seo_status_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

function wpultra_seo_status_cb(array $input) {
    return wpultra_ok(['status' => wpultra_seo_status()]);
}
```

- [ ] **Step 2: Register the `seo` category** in `wpultra_register_categories()` `$cats` (after `'woocommerce' => ...`):
```php
        'seo' => 'SEO: on-page meta, internal links, technical + local SEO (Yoast/Rank Math/native).',
```

- [ ] **Step 3: Wire the engine require loop** in `wpultra_load_abilities()` — after the woocommerce block, add:
```php
    if (!in_array('seo', $disabled, true)) {
        foreach (['setup', 'meta', 'head', 'analyze'] as $sf) {
            $sp = WPULTRA_DIR . 'includes/seo/' . $sf . '.php';
            if (is_readable($sp)) { require_once $sp; }
        }
    }
```
(`analyze.php` is created in Task 6; `is_readable` guards its absence.)

- [ ] **Step 4: Add the slug** to `wpultra_ability_files()` (new `// seo (Wave 7)` group) AND `wpultra_ability_category_map()`:
```php
        // seo (Wave 7, Plan 1)
        'seo-status',
```
```php
        'seo' => ['seo-status'],
```

- [ ] **Step 5: Update `tests/bootstrap.test.php`** — count `77` → `78`; after the woocommerce assertion add:
```php
    assert_true(in_array('seo-status', $files, true), 'has seo');
```

- [ ] **Step 6: Run bootstrap test** — `& $PHP tests/bootstrap.test.php` → PASS (count 78).

- [ ] **Step 7: Lint, deploy, live-verify** — lint the ability + bootstrap; `powershell -File wp-ultra-mcp/bin/deploy.ps1`; confirm `seo-status` registers (MCP `tools/list` or a token-gated call to `wpultra_seo_status_cb([])`) and returns `status.mode:'yoast'`.

- [ ] **Step 8: Commit**

```bash
git add wp-ultra-mcp/includes/abilities/seo-status.php wp-ultra-mcp/includes/bootstrap-mcp.php tests/bootstrap.test.php
git commit -m "feat(seo): seo-status ability + seo category wiring"
```

---

### Task 5: `seo-get-meta` + `seo-set-meta`

**Files:**
- Create: `wp-ultra-mcp/includes/abilities/seo-get-meta.php`, `seo-set-meta.php`
- Modify: `bootstrap-mcp.php` (2 slugs), `tests/bootstrap.test.php` (78 → 80)

**Interfaces:**
- Consumes: `wpultra_seo_get_meta()`, `wpultra_seo_set_meta()`.

- [ ] **Step 1: Write `seo-get-meta` ability** (read-only)

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/seo-get-meta', [
    'label'       => __('SEO: Get Meta', 'wp-ultra-mcp'),
    'description' => __('Get a post\'s SEO meta (title, description, focus_keyword, canonical, robots, OG/Twitter) — mode-aware (Yoast/Rank Math/native).', 'wp-ultra-mcp'),
    'category'    => 'seo',
    'input_schema' => ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer']], 'required' => ['post_id'], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean'], 'meta' => ['type' => 'object']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_seo_get_meta_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

function wpultra_seo_get_meta_cb(array $input) {
    $id = (int) ($input['post_id'] ?? 0);
    if (!get_post($id)) { return wpultra_err('post_not_found', "No post with id $id."); }
    return wpultra_ok(['meta' => wpultra_seo_get_meta($id)]);
}
```

- [ ] **Step 2: Write `seo-set-meta` ability** (write + audit)

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/seo-set-meta', [
    'label'       => __('SEO: Set Meta', 'wp-ultra-mcp'),
    'description' => __('Set a post\'s SEO meta (title, description, focus_keyword, canonical, robots_noindex, robots_nofollow, og_*, twitter_*). Validated; writes via the active driver. Returns rejected + warnings.', 'wp-ultra-mcp'),
    'category'    => 'seo',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'post_id'             => ['type' => 'integer'],
            'title'               => ['type' => 'string'],
            'description'         => ['type' => 'string'],
            'focus_keyword'       => ['type' => 'string'],
            'canonical'           => ['type' => 'string'],
            'robots_noindex'      => ['type' => 'boolean'],
            'robots_nofollow'     => ['type' => 'boolean'],
            'og_title'            => ['type' => 'string'],
            'og_description'      => ['type' => 'string'],
            'og_image'            => ['type' => 'string'],
            'twitter_title'       => ['type' => 'string'],
            'twitter_description' => ['type' => 'string'],
        ],
        'required'   => ['post_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean'], 'rejected' => ['type' => 'array'], 'warnings' => ['type' => 'array']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_seo_set_meta_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

function wpultra_seo_set_meta_cb(array $input) {
    $id = (int) ($input['post_id'] ?? 0);
    $fields = $input;
    unset($fields['post_id']);
    $res = wpultra_seo_set_meta($id, $fields);
    wpultra_audit_log('seo-set-meta', is_wp_error($res) ? 'failed' : ('post ' . $id), !is_wp_error($res));
    if (is_wp_error($res)) { return $res; }
    return wpultra_ok(['rejected' => $res['rejected'], 'warnings' => $res['warnings']]);
}
```

- [ ] **Step 3: Wire 2 slugs + bump count** — add `'seo-get-meta','seo-set-meta'` to files + map; `tests/bootstrap.test.php` `78` → `80`.

- [ ] **Step 4: Run bootstrap test** — PASS (count 80). Lint both ability files.

- [ ] **Step 5: Deploy + live-verify** — `powershell -File wp-ultra-mcp/bin/deploy.ps1`. Probe (require `includes/seo/{setup,meta}.php` + the 2 ability files + helpers; admin user): pick/create a draft post; `seo-set-meta` with `{post_id, title:'My SEO Title', description:'<a 130-char string>', focus_keyword:'widget', robots_noindex:true}` → assert empty `rejected`; `seo-get-meta` → assert title/description/focus_keyword round-trip AND `robots_noindex:true` AND `mode:'yoast'`; confirm the underlying Yoast key (`get_post_meta($id,'_yoast_wpseo_title',true)`) equals the title (proves driver wrote Yoast's store). Pass an unknown field → assert it's in `rejected`. Pass a 70-char title → assert a `warnings` entry. Delete the probe (leave or delete the test post).

- [ ] **Step 6: Commit**

```bash
git add wp-ultra-mcp/includes/abilities/seo-get-meta.php wp-ultra-mcp/includes/abilities/seo-set-meta.php wp-ultra-mcp/includes/bootstrap-mcp.php tests/bootstrap.test.php
git commit -m "feat(seo): seo-get-meta + seo-set-meta (mode-aware, validated)"
```

---

### Task 6: `analyze.php` pure scorer + `seo-analyze-page`

**Files:**
- Create: `wp-ultra-mcp/includes/seo/analyze.php`, `wp-ultra-mcp/includes/abilities/seo-analyze-page.php`
- Test: `tests/seo-analyze.test.php`
- Modify: `bootstrap-mcp.php` (1 slug), `tests/bootstrap.test.php` (80 → 81)

**Interfaces:**
- Produces:
  - `wpultra_seo_score(array $data): array` — PURE. Input `{title, meta_description, focus_keyword, h1, first_paragraph, body_text, slug, internal_links, external_links, images_total, images_missing_alt}`. Output `{score:0-100, checks:[{id,status:pass|warn|fail,message}], recommendations:[...]}`.
  - `wpultra_seo_extract_post(int $post_id): array` — gather the `$data` shape from a real post (title via SEO meta or post title, body via `get_post_field('post_content')` stripped, etc.).

- [ ] **Step 1: Write the failing test** — `tests/seo-analyze.test.php`

```php
<?php
require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../wp-ultra-mcp/includes/seo/analyze.php';

it('perfect-ish page scores high and keyword checks pass', function () {
    $data = [
        'title' => 'Best Blue Widgets Guide', 'meta_description' => str_repeat('Blue widgets are great. ', 6),
        'focus_keyword' => 'blue widgets', 'h1' => 'Best Blue Widgets',
        'first_paragraph' => 'Blue widgets are the best widgets you can buy.',
        'body_text' => str_repeat('blue widgets are useful and blue widgets help. ', 40),
        'slug' => 'best-blue-widgets', 'internal_links' => 3, 'external_links' => 1,
        'images_total' => 2, 'images_missing_alt' => 0,
    ];
    $r = wpultra_seo_score($data);
    assert_true($r['score'] >= 70, 'score should be high, got ' . $r['score']);
    $byId = [];
    foreach ($r['checks'] as $c) { $byId[$c['id']] = $c['status']; }
    assert_eq('pass', $byId['keyword_in_title']);
    assert_eq('pass', $byId['keyword_in_h1']);
    assert_eq('pass', $byId['keyword_in_first_paragraph']);
});

it('missing keyword + no meta scores low with fails', function () {
    $data = [
        'title' => 'Untitled', 'meta_description' => '', 'focus_keyword' => 'blue widgets',
        'h1' => 'Hello', 'first_paragraph' => 'Welcome to my site.', 'body_text' => 'Some short text.',
        'slug' => 'hello', 'internal_links' => 0, 'external_links' => 0, 'images_total' => 1, 'images_missing_alt' => 1,
    ];
    $r = wpultra_seo_score($data);
    assert_true($r['score'] < 50, 'score should be low, got ' . $r['score']);
    $byId = [];
    foreach ($r['checks'] as $c) { $byId[$c['id']] = $c['status']; }
    assert_eq('fail', $byId['keyword_in_title']);
    assert_eq('fail', $byId['has_meta_description']);
    assert_eq('fail', $byId['images_have_alt']);
});

run_tests();
```

- [ ] **Step 2: Run → fail** — `& $PHP tests/seo-analyze.test.php` → FAIL (`wpultra_seo_score` undefined).

- [ ] **Step 3: Write `analyze.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH') && !defined('WPULTRA_TEST')) { /* allow harness load */ }

function wpultra_seo_score(array $d): array {
    $kw = strtolower(trim((string) ($d['focus_keyword'] ?? '')));
    $has = function ($hay) use ($kw) { return $kw !== '' && strpos(strtolower((string) $hay), $kw) !== false; };
    $checks = [];
    $add = function (string $id, string $status, string $msg) use (&$checks) { $checks[] = ['id' => $id, 'status' => $status, 'message' => $msg]; };

    $add('keyword_set', $kw !== '' ? 'pass' : 'warn', $kw !== '' ? "Focus keyword: \"$kw\"." : 'No focus keyword set.');
    $add('keyword_in_title', $has($d['title'] ?? '') ? 'pass' : 'fail', 'Focus keyword in the SEO title.');
    $add('keyword_in_h1', $has($d['h1'] ?? '') ? 'pass' : 'warn', 'Focus keyword in the H1.');
    $add('keyword_in_first_paragraph', $has($d['first_paragraph'] ?? '') ? 'pass' : 'warn', 'Focus keyword in the opening paragraph.');
    $add('keyword_in_slug', $kw !== '' && strpos((string) ($d['slug'] ?? ''), str_replace(' ', '-', $kw)) !== false ? 'pass' : 'warn', 'Focus keyword in the URL slug.');

    $titleLen = strlen((string) ($d['title'] ?? ''));
    $add('title_length', ($titleLen > 0 && $titleLen <= 60) ? 'pass' : ($titleLen === 0 ? 'fail' : 'warn'), "SEO title length ($titleLen) ≤ 60.");
    $descLen = strlen((string) ($d['meta_description'] ?? ''));
    $add('has_meta_description', $descLen > 0 ? 'pass' : 'fail', 'Meta description is set.');
    $add('meta_description_length', ($descLen >= 120 && $descLen <= 160) ? 'pass' : ($descLen === 0 ? 'fail' : 'warn'), "Meta description length ($descLen) in 120–160.");

    $words = str_word_count(strip_tags((string) ($d['body_text'] ?? '')));
    $add('content_length', $words >= 300 ? 'pass' : 'warn', "Content word count ($words) ≥ 300.");

    // keyword density
    $density = 0.0;
    if ($kw !== '' && $words > 0) { $density = round((substr_count(strtolower((string) $d['body_text']), $kw) * (1 + substr_count($kw, ' ')) / max(1, $words)) * 100, 2); }
    $add('keyword_density', ($density >= 0.5 && $density <= 3.0) ? 'pass' : 'warn', "Keyword density ($density%) within 0.5–3%.");

    $add('has_internal_links', (int) ($d['internal_links'] ?? 0) >= 1 ? 'pass' : 'warn', 'At least one internal link.');
    $add('has_external_links', (int) ($d['external_links'] ?? 0) >= 1 ? 'pass' : 'warn', 'At least one outbound link.');
    $imgMissing = (int) ($d['images_missing_alt'] ?? 0);
    $add('images_have_alt', $imgMissing === 0 ? 'pass' : 'fail', $imgMissing === 0 ? 'All images have alt text.' : "$imgMissing image(s) missing alt text.");

    $weights = ['fail' => 0, 'warn' => 0.5, 'pass' => 1];
    $total = count($checks);
    $sum = 0.0;
    foreach ($checks as $c) { $sum += $weights[$c['status']]; }
    $score = $total > 0 ? (int) round(($sum / $total) * 100) : 0;
    $recs = [];
    foreach ($checks as $c) { if ($c['status'] !== 'pass') { $recs[] = $c['message']; } }
    return ['score' => $score, 'checks' => $checks, 'recommendations' => $recs];
}

function wpultra_seo_extract_post(int $post_id): array {
    $post = get_post($post_id);
    if (!$post) { return []; }
    $meta = function_exists('wpultra_seo_get_meta') ? wpultra_seo_get_meta($post_id) : [];
    $content = (string) $post->post_content;
    $text = trim(strip_tags($content));
    $firstPara = '';
    if (preg_match('/<p[^>]*>(.*?)<\/p>/is', $content, $mm)) { $firstPara = trim(strip_tags($mm[1])); }
    if ($firstPara === '') { $firstPara = substr($text, 0, 200); }
    $h1 = '';
    if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $content, $h)) { $h1 = trim(strip_tags($h[1])); }
    if ($h1 === '') { $h1 = get_the_title($post_id); }
    $internal = 0; $external = 0; $home = wp_parse_url(home_url(), PHP_URL_HOST);
    if (preg_match_all('/<a\s[^>]*href=["\']([^"\']+)["\']/i', $content, $links)) {
        foreach ($links[1] as $href) {
            $host = wp_parse_url($href, PHP_URL_HOST);
            if (!$host || $host === $home) { $internal++; } else { $external++; }
        }
    }
    $imgTotal = preg_match_all('/<img\s/i', $content, $x);
    $imgNoAlt = preg_match_all('/<img\s(?:(?!alt=)[^>])*?>/i', $content, $y);
    return [
        'title' => $meta['title'] ?? get_the_title($post_id),
        'meta_description' => $meta['description'] ?? '',
        'focus_keyword' => $meta['focus_keyword'] ?? '',
        'h1' => $h1, 'first_paragraph' => $firstPara, 'body_text' => $text,
        'slug' => $post->post_name, 'internal_links' => $internal, 'external_links' => $external,
        'images_total' => (int) $imgTotal, 'images_missing_alt' => (int) $imgNoAlt,
    ];
}
```

- [ ] **Step 4: Run → pass** — `& $PHP tests/seo-analyze.test.php` → PASS (2 `it`).

- [ ] **Step 5: Write `seo-analyze-page` ability** (read-only)

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/seo-analyze-page', [
    'label'       => __('SEO: Analyze Page', 'wp-ultra-mcp'),
    'description' => __('Score a post\'s on-page SEO (keyword placement, density, meta length, content length, links, image alt) and return a prioritized checklist + recommendations. Optional focus_keyword override.', 'wp-ultra-mcp'),
    'category'    => 'seo',
    'input_schema' => ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer'], 'focus_keyword' => ['type' => 'string']], 'required' => ['post_id'], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean'], 'score' => ['type' => 'integer'], 'checks' => ['type' => 'array'], 'recommendations' => ['type' => 'array']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_seo_analyze_page_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

function wpultra_seo_analyze_page_cb(array $input) {
    $id = (int) ($input['post_id'] ?? 0);
    if (!get_post($id)) { return wpultra_err('post_not_found', "No post with id $id."); }
    $data = wpultra_seo_extract_post($id);
    if (!empty($input['focus_keyword'])) { $data['focus_keyword'] = (string) $input['focus_keyword']; }
    $res = wpultra_seo_score($data);
    return wpultra_ok($res);
}
```

- [ ] **Step 6: Wire slug + bump count** — add `'seo-analyze-page'` to files + map; `tests/bootstrap.test.php` `80` → `81`.

- [ ] **Step 7: Run the FULL suite** — `powershell -File tests/run-all.ps1` → `ALL TEST FILES PASSED` (bootstrap 81, seo-meta 5, seo-analyze 2, nothing regressed). Lint the new files.

- [ ] **Step 8: Deploy + live-verify** — `powershell -File wp-ultra-mcp/bin/deploy.ps1`. Probe: create a post with a known body (an H1, a focus keyword in title + first paragraph, a 130-char meta via `seo-set-meta`, an internal link, an image without alt). `seo-analyze-page` → assert a numeric `score`, `checks` has `keyword_in_title:pass` and `images_have_alt:fail`. Delete the probe + post.

- [ ] **Step 9: Commit**

```bash
git add wp-ultra-mcp/includes/seo/analyze.php wp-ultra-mcp/includes/abilities/seo-analyze-page.php wp-ultra-mcp/includes/bootstrap-mcp.php tests/seo-analyze.test.php tests/bootstrap.test.php
git commit -m "feat(seo): pure on-page scorer + seo-analyze-page"
```

---

## Plan 1 Done — exit criteria

- Yoast installed + active; `seo-status` reports `mode:yoast`.
- 4 SEO abilities under a new `seo` category; `tests/bootstrap.test.php` count = **81**; full suite green.
- Live-verified: set/get meta round-trips through Yoast's own keys (driver proven); native renderer outputs only when no plugin; analyze scores a real post.
- **Record verified meta keys + the Yoast version + the native-head hook facts** into project memory + note for Plan 3 (which installs Rank Math and re-verifies the driver).
- Do NOT bump plugin version (Plan 4 ships v0.11.0).

## Self-Review notes (done during planning)

- **Spec coverage (Plan 1 slice):** mode detection + status ✓ (Task 1), driver yoast/rankmath/native + validation ✓ (Task 2), native head renderer ✓ (Task 3), seo-status ✓ (Task 4), get/set-meta ✓ (Task 5), analyze ✓ (Task 6). Internal-linking/research/technical/local/audit/quick-setup/skill are Plans 2–4.
- **Type consistency:** canonical field set identical across `wpultra_seo_fields`/keymap/get/set; `wpultra_seo_score` input keys match `wpultra_seo_extract_post` output keys; count chain 77→78→80→81 monotonic; engine require loop added once (Task 4) as `['setup','meta','head','analyze']`.
- **Placeholders:** none — concrete code/commands throughout. The native-head live test (Task 3 Step 3) documents the deactivate→verify→reactivate sequence following the project's ONE-self-request rule.
- **Rank Math note:** the rankmath branch of the driver is written now but only live-exercised in Plan 3 (when Rank Math is installed); under Yoast (Plan 1) it's covered by the keymap unit test + the live Yoast round-trip.
