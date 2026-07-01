# WP-Ultra-MCP — Wave 7 SEO · Plan 4: Audit + Quick-Setup + Skill + Ship v0.11.0

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`) syntax.
>
> **Final plan of the Wave 7 program** (spec: `docs/superpowers/specs/2026-07-01-wp-ultra-seo-wave7-design.md`). Plans 1–3 shipped foundation + linking/research + technical/local (branch `feat/seo-wave7`, count 93, full suite green, Yoast active mode=yoast). This plan adds site-wide audit + bulk meta + a Google-recommended quick-setup, the `seo-architect` skill, a small redirect-loop-guard cleanup, and bumps the version to **0.11.0** for release. After this plan: a final whole-branch review, then merge/push/release via `finishing-a-development-branch`.

**Goal:** 3 abilities (`seo-site-audit`, `seo-bulk-set-meta`, `seo-quick-setup`; count 93→96) + the `seo-architect` built-in skill + a self-referential-redirect guard + version bump to 0.11.0 + README/changelog.

**Architecture:** `includes/seo/audit.php` — a pure per-post issue classifier + a site-wide scan (reusing the Plan-1 meta/analyze engine + the Plan-2 link audit for orphans) + a bounded bulk-meta applier with a template expander. `seo-quick-setup` applies the plugin-agnostic best-practice baseline we control (sitemap on, discourage-search off). The skill is a Markdown file auto-discovered by `includes/skills/sources.php`.

**Tech Stack:** PHP 8.0+, WP 7.0, the Plans 1–3 `wpultra_seo_*` engine, vendored mcp-adapter, Abilities API. No new deps, no external API.

## Global Constraints

- Every PHP file: `<?php` + `declare(strict_types=1);` + `if (!defined('ABSPATH')) { exit(); }`. Pure-testable files use `if (!defined('ABSPATH') && !defined('WPULTRA_TEST')) {}`.
- Engine returns arrays/values or `WP_Error` via `wpultra_err`. Abilities return `wpultra_ok([...])` or the `WP_Error`.
- **Ability registration shape** — copy `wp-ultra-mcp/includes/abilities/seo-analyze-page.php` (read) / `seo-set-meta.php` (write): named string `execute_callback`, `properties` PLAIN ARRAY, `permission_callback=>'wpultra_permission_callback'`, `meta.mcp`, `category=>'seo'`. `seo-site-audit` READ-ONLY (no audit). `seo-bulk-set-meta` + `seo-quick-setup` WRITE + `wpultra_audit_log` after (but `seo-bulk-set-meta` in `dry_run` mode does NOT write and still counts as read-shaped — audit only when it actually applies).
- **`seo-bulk-set-meta` defaults to `dry_run:true`** (preview only); it writes only when `dry_run:false` (or `apply:true`). It applies via the existing `wpultra_seo_set_meta` driver (so it respects mode + validation). Bounded by `limit`.
- **Engine require loop:** add `'audit'` to the seo loop in BOTH `wpultra_load_abilities()` AND `wpultra_load_seo_frontend()` (currently `['setup','meta','head','analyze','links','research','technical','local']`).
- **Built-in skill** at `wp-ultra-mcp/includes/skills/built-in/seo-architect.md` — auto-discovered by `includes/skills/sources.php`; NO code wiring. YAML frontmatter (`name:` + `description:` + `enable_prompt`/`enable_agentic`) matching `woocommerce-architect.md`/`elementor-v4-architect.md`.
- `tests/bootstrap.test.php` count `93` → `96`; keep files↔map in sync.
- Bundled PHP: `$PHP = C:/Users/nisha/AppData/Roaming/Local/lightning-services/php-8.2.30+1/bin/win64/php.exe`. Test site root: `C:/Users/nisha/Local Sites/wp-connector/app/public`. Live token: `wpultra-test-9a88`. Yoast 27.9 active (mode=yoast).
- **Re-run `bin/deploy.ps1` after every commit.** Live probes: token-gated webroot scripts; clean up + delete.
- **Harness:** `it`, `assert_eq` (strict), `assert_true`; ends `run_tests();`. Pure tests stub nothing.
- Commit messages: `feat(seo):` / `fix(seo):` / `docs(seo):` / `chore(release):`; end body with `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.

## File Structure

```
wp-ultra-mcp/includes/
  seo/
    audit.php      NEW — pure issue classifier + template expander + site-audit + bulk-set-meta (Tasks 1–3)
    technical.php  MODIFY — self-referential-redirect guard (Task 5)
  abilities/
    seo-site-audit.php     NEW (Task 2)
    seo-bulk-set-meta.php  NEW (Task 3)
    seo-quick-setup.php    NEW (Task 4)
  skills/built-in/
    seo-architect.md       NEW (Task 5)
  bootstrap-mcp.php   MODIFY — engine loop + 3 slugs (Tasks 2–4)
  wp-ultra-mcp.php    MODIFY — version 0.10.0 → 0.11.0 (Task 6)
  readme.txt          MODIFY — stable tag + changelog (Task 6)
README.md             MODIFY — SEO abilities section (Task 6)
tests/
  seo-audit.test.php  NEW — pure classifier + template tests (Task 1)
  bootstrap.test.php  MODIFY — count 93 → 96
```

---

### Task 1: `audit.php` engine — pure classifier + template expander + site-audit + bulk-set-meta

**Files:**
- Create: `wp-ultra-mcp/includes/seo/audit.php`, `tests/seo-audit.test.php`
- Modify: `bootstrap-mcp.php` (seo engine loop `+audit` in BOTH loops)

**Interfaces:**
- Produces:
  - `wpultra_seo_audit_post(array $d): array` — PURE. Given `{seo_title, seo_desc, focus_keyword, word_count, images_missing_alt, noindex}` returns issues `[{code,severity,message}]`.
  - `wpultra_seo_expand_template(string $tpl, array $tokens): string` — PURE. Replaces `%key%` tokens.
  - `wpultra_seo_site_audit(int $limit): array` — WP: scan + aggregate.
  - `wpultra_seo_bulk_set_meta(array $input): array` — WP: apply meta by rule (dry-run default).

- [ ] **Step 1: Write the failing test** — `tests/seo-audit.test.php`

```php
<?php
require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../wp-ultra-mcp/includes/seo/audit.php';

it('audit_post flags missing title, short desc, thin content, missing alt, noindex', function () {
    $issues = wpultra_seo_audit_post(['seo_title' => '', 'seo_desc' => 'short', 'focus_keyword' => '', 'word_count' => 100, 'images_missing_alt' => 2, 'noindex' => true]);
    $codes = array_map(function ($i) { return $i['code']; }, $issues);
    assert_true(in_array('missing_seo_title', $codes, true));
    assert_true(in_array('meta_description_too_short', $codes, true));
    assert_true(in_array('missing_focus_keyword', $codes, true));
    assert_true(in_array('thin_content', $codes, true));
    assert_true(in_array('missing_image_alt', $codes, true));
    assert_true(in_array('noindex_set', $codes, true));
});

it('audit_post is clean for a good post', function () {
    $issues = wpultra_seo_audit_post(['seo_title' => 'A good SEO title here', 'seo_desc' => str_repeat('x', 140), 'focus_keyword' => 'widgets', 'word_count' => 800, 'images_missing_alt' => 0, 'noindex' => false]);
    assert_eq(0, count($issues));
});

it('expand_template replaces tokens', function () {
    $r = wpultra_seo_expand_template('%title% %sep% %sitename%', ['title' => 'Post', 'sep' => '|', 'sitename' => 'Site']);
    assert_eq('Post | Site', $r);
});

run_tests();
```

- [ ] **Step 2: Run → fail** — `& $PHP tests/seo-audit.test.php` → FAIL.

- [ ] **Step 3: Write `audit.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH') && !defined('WPULTRA_TEST')) { /* allow harness load */ }

/** PURE. Classify a post's SEO issues from its extracted data. */
function wpultra_seo_audit_post(array $d): array {
    $issues = [];
    $add = function (string $code, string $sev, string $msg) use (&$issues) { $issues[] = ['code' => $code, 'severity' => $sev, 'message' => $msg]; };
    $title = (string) ($d['seo_title'] ?? '');
    if ($title === '') { $add('missing_seo_title', 'high', 'No SEO title set.'); }
    elseif (strlen($title) > 60) { $add('title_too_long', 'low', 'SEO title over 60 chars.'); }
    $desc = (string) ($d['seo_desc'] ?? '');
    if ($desc === '') { $add('missing_meta_description', 'high', 'No meta description set.'); }
    elseif (strlen($desc) < 120) { $add('meta_description_too_short', 'medium', 'Meta description under 120 chars.'); }
    elseif (strlen($desc) > 160) { $add('meta_description_too_long', 'low', 'Meta description over 160 chars.'); }
    if ((string) ($d['focus_keyword'] ?? '') === '') { $add('missing_focus_keyword', 'medium', 'No focus keyword set.'); }
    if ((int) ($d['word_count'] ?? 0) < 300) { $add('thin_content', 'medium', 'Content under 300 words.'); }
    if ((int) ($d['images_missing_alt'] ?? 0) > 0) { $add('missing_image_alt', 'medium', ((int) $d['images_missing_alt']) . ' image(s) missing alt text.'); }
    if (!empty($d['noindex'])) { $add('noindex_set', 'high', 'Post is set to noindex (excluded from search).'); }
    return $issues;
}

/** PURE. Expand %key% tokens in a template. */
function wpultra_seo_expand_template(string $tpl, array $tokens): string {
    foreach ($tokens as $k => $v) { $tpl = str_replace('%' . $k . '%', (string) $v, $tpl); }
    return $tpl;
}

function wpultra_seo_audit_extract(int $post_id): array {
    $meta = function_exists('wpultra_seo_get_meta') ? wpultra_seo_get_meta($post_id) : [];
    $ex = function_exists('wpultra_seo_extract_post') ? wpultra_seo_extract_post($post_id) : [];
    return [
        'seo_title' => (string) ($meta['title'] ?? ''),
        'seo_desc' => (string) ($meta['description'] ?? ''),
        'focus_keyword' => (string) ($meta['focus_keyword'] ?? ''),
        'noindex' => !empty($meta['robots_noindex']),
        'word_count' => (int) str_word_count((string) ($ex['body_text'] ?? '')),
        'images_missing_alt' => (int) ($ex['images_missing_alt'] ?? 0),
    ];
}

function wpultra_seo_site_audit(int $limit): array {
    $ids = get_posts(['post_type' => ['post', 'page'], 'post_status' => 'publish', 'posts_per_page' => max(1, $limit), 'fields' => 'ids']);
    $byCode = [];
    $rows = [];
    $titles = [];
    foreach ($ids as $id) {
        $data = wpultra_seo_audit_extract((int) $id);
        $issues = wpultra_seo_audit_post($data);
        foreach ($issues as $i) { $byCode[$i['code']] = ($byCode[$i['code']] ?? 0) + 1; }
        if ($issues) { $rows[] = ['post_id' => (int) $id, 'title' => get_the_title($id), 'issues' => $issues]; }
        $t = strtolower(trim(get_the_title($id)));
        if ($t !== '') { $titles[$t][] = (int) $id; }
    }
    $duplicates = [];
    foreach ($titles as $t => $group) { if (count($group) > 1) { $duplicates[] = ['title' => $t, 'post_ids' => $group]; } }
    $orphans = function_exists('wpultra_seo_link_audit') ? (wpultra_seo_link_audit($limit)['orphans'] ?? []) : [];
    return ['scanned' => count($ids), 'issue_counts' => $byCode, 'duplicate_titles' => $duplicates, 'orphans' => $orphans, 'posts' => $rows];
}

function wpultra_seo_bulk_set_meta(array $input): array {
    $filter = (string) ($input['filter'] ?? 'missing_title'); // missing_title | missing_description | all
    $limit = isset($input['limit']) ? (int) $input['limit'] : 50;
    $dry = !array_key_exists('dry_run', $input) ? true : (bool) $input['dry_run'];
    if (!empty($input['apply'])) { $dry = false; }
    $ids = get_posts(['post_type' => ['post', 'page'], 'post_status' => 'publish', 'posts_per_page' => max(1, $limit), 'fields' => 'ids']);
    $sitename = get_bloginfo('name');
    $applied = [];
    $skipped = 0;
    foreach ($ids as $id) {
        $meta = wpultra_seo_get_meta((int) $id);
        $wantTitle = isset($input['title_template']);
        $wantDesc = isset($input['description_template']);
        $wantNoindex = array_key_exists('noindex', $input);
        $matches = ($filter === 'all')
            || ($filter === 'missing_title' && (string) ($meta['title'] ?? '') === '')
            || ($filter === 'missing_description' && (string) ($meta['description'] ?? '') === '');
        if (!$matches) { $skipped++; continue; }
        $fields = [];
        $tokens = ['title' => get_the_title($id), 'sitename' => $sitename, 'sep' => (string) ($input['sep'] ?? '|')];
        if ($wantTitle) { $fields['title'] = wpultra_seo_expand_template((string) $input['title_template'], $tokens); }
        if ($wantDesc) { $fields['description'] = wpultra_seo_expand_template((string) $input['description_template'], $tokens); }
        if ($wantNoindex) { $fields['robots_noindex'] = (bool) $input['noindex']; }
        if (!$fields) { $skipped++; continue; }
        if (!$dry) { wpultra_seo_set_meta((int) $id, $fields); }
        $applied[] = ['post_id' => (int) $id, 'changes' => $fields];
    }
    return ['dry_run' => $dry, 'applied' => $applied, 'applied_count' => count($applied), 'skipped' => $skipped];
}
```

- [ ] **Step 4: Run → pass** — `& $PHP tests/seo-audit.test.php` → PASS (3 `it`). Lint `audit.php`.

- [ ] **Step 5: Wire the engine loop** — extend the seo loop to include `'audit'` in BOTH `wpultra_load_abilities()` AND `wpultra_load_seo_frontend()` (both in `bootstrap-mcp.php`). Lint. Run `& $PHP tests/bootstrap.test.php` (still 93).

- [ ] **Step 6: Commit**

```bash
git add wp-ultra-mcp/includes/seo/audit.php tests/seo-audit.test.php wp-ultra-mcp/includes/bootstrap-mcp.php
git commit -m "feat(seo): audit engine — pure issue classifier + template expander + site-audit + bulk-set-meta"
```

---

### Task 2: `seo-site-audit`

**Files:**
- Create: `wp-ultra-mcp/includes/abilities/seo-site-audit.php`
- Modify: `bootstrap-mcp.php` (1 slug), `tests/bootstrap.test.php` (93 → 94)

**Interfaces:** Consumes `wpultra_seo_site_audit`.

- [ ] **Step 1: Write `seo-site-audit` ability** (read-only)

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/seo-site-audit', [
    'label'       => __('SEO: Site Audit', 'wp-ultra-mcp'),
    'description' => __('Scan published posts/pages for on-page SEO issues (missing/too-long titles + descriptions, missing focus keyword, thin content, missing image alt, noindex), duplicate titles, and orphan pages. limit caps how many posts are scanned.', 'wp-ultra-mcp'),
    'category'    => 'seo',
    'input_schema' => ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer']], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean'], 'scanned' => ['type' => 'integer'], 'issue_counts' => ['type' => 'object'], 'duplicate_titles' => ['type' => 'array'], 'orphans' => ['type' => 'array'], 'posts' => ['type' => 'array']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_seo_site_audit_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

function wpultra_seo_site_audit_cb(array $input) {
    $limit = isset($input['limit']) ? (int) $input['limit'] : 200;
    return wpultra_ok(wpultra_seo_site_audit($limit));
}
```

- [ ] **Step 2: Wire slug + bump count** — add `'seo-site-audit'`; `tests/bootstrap.test.php` `93` → `94`.

- [ ] **Step 3: Run bootstrap test** — PASS (94). Lint the ability file.

- [ ] **Step 4: Deploy + live-verify** — `powershell -File wp-ultra-mcp/bin/deploy.ps1`. Probe (require `includes/seo/{setup,meta,analyze,links,audit}.php` + the ability + helpers): create 2 published posts, one with NO SEO title/description + thin content (should raise issues), one duplicate-titled pair. `seo-site-audit {limit:200}` → assert `scanned` ≥ 2, `issue_counts` has `missing_seo_title` and `thin_content` ≥ 1, and `duplicate_titles` is present. Force-delete the test posts + probe.

- [ ] **Step 5: Commit**

```bash
git add wp-ultra-mcp/includes/abilities/seo-site-audit.php wp-ultra-mcp/includes/bootstrap-mcp.php tests/bootstrap.test.php
git commit -m "feat(seo): seo-site-audit (site-wide SEO issue scan)"
```

---

### Task 3: `seo-bulk-set-meta`

**Files:**
- Create: `wp-ultra-mcp/includes/abilities/seo-bulk-set-meta.php`
- Modify: `bootstrap-mcp.php` (1 slug), `tests/bootstrap.test.php` (94 → 95)

**Interfaces:** Consumes `wpultra_seo_bulk_set_meta`.

- [ ] **Step 1: Write `seo-bulk-set-meta` ability** (write — audit only when it applies)

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/seo-bulk-set-meta', [
    'label'       => __('SEO: Bulk Set Meta', 'wp-ultra-mcp'),
    'description' => __('Apply SEO meta across many posts by rule. filter: missing_title|missing_description|all. title_template/description_template support %title% %sitename% %sep%. noindex sets robots. DRY-RUN by default (preview); pass dry_run:false (or apply:true) to write. limit caps scope.', 'wp-ultra-mcp'),
    'category'    => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'filter' => ['type' => 'string', 'enum' => ['missing_title', 'missing_description', 'all']],
            'title_template' => ['type' => 'string'], 'description_template' => ['type' => 'string'],
            'noindex' => ['type' => 'boolean'], 'sep' => ['type' => 'string'],
            'dry_run' => ['type' => 'boolean'], 'apply' => ['type' => 'boolean'], 'limit' => ['type' => 'integer'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean'], 'dry_run' => ['type' => 'boolean'], 'applied' => ['type' => 'array'], 'applied_count' => ['type' => 'integer'], 'skipped' => ['type' => 'integer']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_seo_bulk_set_meta_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

function wpultra_seo_bulk_set_meta_cb(array $input) {
    $res = wpultra_seo_bulk_set_meta($input);
    if (!$res['dry_run']) { wpultra_audit_log('seo-bulk-set-meta', 'applied ' . $res['applied_count'], true); }
    return wpultra_ok($res);
}
```

- [ ] **Step 2: Wire slug + bump count** — add `'seo-bulk-set-meta'`; `tests/bootstrap.test.php` `94` → `95`.

- [ ] **Step 3: Run bootstrap test** — PASS (95). Lint the ability file.

- [ ] **Step 4: Deploy + live-verify** — probe: create 2 published posts with NO SEO title. `seo-bulk-set-meta {filter:'missing_title', title_template:'%title% %sep% %sitename%'}` (default dry_run) → assert `dry_run:true`, `applied_count` ≥ 2, and the posts' Yoast title is STILL empty (dry-run wrote nothing — check `get_post_meta(id,'_yoast_wpseo_title',true)===''`). Then `{filter:'missing_title', title_template:'%title% %sep% %sitename%', apply:true}` → assert `dry_run:false`; re-check `_yoast_wpseo_title` now equals the expanded template. Force-delete the posts + probe.

- [ ] **Step 5: Commit**

```bash
git add wp-ultra-mcp/includes/abilities/seo-bulk-set-meta.php wp-ultra-mcp/includes/bootstrap-mcp.php tests/bootstrap.test.php
git commit -m "feat(seo): seo-bulk-set-meta (rule-based bulk meta, dry-run default)"
```

---

### Task 4: `seo-quick-setup`

**Files:**
- Create: `wp-ultra-mcp/includes/abilities/seo-quick-setup.php`
- Modify: `bootstrap-mcp.php` (1 slug), `tests/bootstrap.test.php` (95 → 96)

**Interfaces:** Consumes `wpultra_seo_set_sitemap` (technical.php) + `wpultra_seo_status`.

- [ ] **Step 1: Write `seo-quick-setup` ability** (write + audit) — applies the plugin-agnostic baseline we control + returns recommendations.

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/seo-quick-setup', [
    'label'       => __('SEO: Quick Setup', 'wp-ultra-mcp'),
    'description' => __('Apply a Google-recommended baseline: enable the XML sitemap, ensure the site is not discouraging search engines, and return a prioritized checklist of what the AI should do next (fill meta, set focus keywords, add internal links). Idempotent.', 'wp-ultra-mcp'),
    'category'    => 'seo',
    'input_schema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean'], 'applied' => ['type' => 'array'], 'recommendations' => ['type' => 'array']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_seo_quick_setup_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true]],
]);

function wpultra_seo_quick_setup_cb(array $input) {
    $applied = [];
    // 1. Ensure search engines are allowed to index the site.
    if (!get_option('blog_public')) { update_option('blog_public', 1); $applied[] = 'Enabled search-engine indexing (blog_public).'; }
    else { $applied[] = 'Search-engine indexing already enabled.'; }
    // 2. Enable the sitemap.
    if (function_exists('wpultra_seo_set_sitemap')) { $sm = wpultra_seo_set_sitemap(true); $applied[] = 'Sitemap enabled: ' . ($sm['url'] ?? ''); }
    $mode = function_exists('wpultra_seo_mode') ? wpultra_seo_mode() : 'native';
    $recommendations = [
        'Run seo-site-audit to find posts missing titles/descriptions and thin content.',
        'Use seo-bulk-set-meta with a title template (e.g. "%title% %sep% %sitename%") to fill missing SEO titles.',
        'Set a focus keyword per key page and use seo-analyze-page / seo-optimize-content to improve it.',
        'Use seo-suggest-internal-links + seo-insert-internal-link to build internal links and fix orphan pages (seo-link-audit).',
        'Add structured data with seo-manage-schema, and LocalBusiness data with seo-manage-local-business if local.',
    ];
    if ($mode !== 'native') { $recommendations[] = "SEO plugin active ($mode): title templates + breadcrumbs + org schema are configured in the {$mode} plugin's own settings."; }
    wpultra_audit_log('seo-quick-setup', 'baseline applied', true);
    return wpultra_ok(['applied' => $applied, 'recommendations' => $recommendations]);
}
```

- [ ] **Step 2: Wire slug + bump count** — add `'seo-quick-setup'`; `tests/bootstrap.test.php` `95` → `96`.

- [ ] **Step 3: Run bootstrap test** — PASS (96). Lint the ability file.

- [ ] **Step 4: Deploy + live-verify** — probe: `seo-quick-setup {}` → assert `applied` is a non-empty array mentioning sitemap, and `recommendations` is a non-empty array. Run it AGAIN → assert it's idempotent (no error, sitemap already enabled). Confirm `wpultra_seo_status()` shows sitemap enabled. Delete the probe.

- [ ] **Step 5: Commit**

```bash
git add wp-ultra-mcp/includes/abilities/seo-quick-setup.php wp-ultra-mcp/includes/bootstrap-mcp.php tests/bootstrap.test.php
git commit -m "feat(seo): seo-quick-setup (Google-recommended baseline + checklist)"
```

---

### Task 5: `seo-architect` skill + self-referential-redirect guard

**Files:**
- Create: `wp-ultra-mcp/includes/skills/built-in/seo-architect.md`
- Modify: `wp-ultra-mcp/includes/seo/technical.php` (redirect-loop guard)

**Interfaces:** none (skill auto-discovered; the guard is internal to `wpultra_seo_add_redirect`).

- [ ] **Step 1: Add the self-referential-redirect guard** to `wpultra_seo_add_redirect` in `technical.php` — reject a redirect whose target path equals its source path (prevents an infinite redirect loop). Immediately after the `$type` clamp line and before building the new entry, add:

```php
    $targetPath = (string) wp_parse_url($target, PHP_URL_PATH);
    if ($targetPath !== '' && wpultra_seo_norm_path($targetPath) === wpultra_seo_norm_path($source)) {
        return wpultra_err('redirect_loop', 'Redirect source and target resolve to the same path (would loop).');
    }
```
(`wpultra_seo_add_redirect` now returns `array|WP_Error`; its caller `seo-manage-redirects.php` already does `is_wp_error`? — it does NOT currently; UPDATE `wpultra_seo_manage_redirects_cb` so the `add` branch checks `if (is_wp_error($res)) { return $res; }` before the audit/return.)

- [ ] **Step 2: Lint + run the full suite** — `& $PHP -l wp-ultra-mcp/includes/seo/technical.php` + `& $PHP -l wp-ultra-mcp/includes/abilities/seo-manage-redirects.php`; `powershell -File tests/run-all.ps1` → `ALL TEST FILES PASSED` (count still 96; no regression — seo-technical tests still green).

- [ ] **Step 3: Write the skill** — `wp-ultra-mcp/includes/skills/built-in/seo-architect.md`. FIRST read `wp-ultra-mcp/includes/skills/built-in/woocommerce-architect.md` for the exact frontmatter shape. Required frontmatter: `name: SEO Architect` + a one-line `description:` + `enable_prompt: true` + `enable_agentic: true`. Body — write it fully (the prose IS the deliverable), encoding the ranking loop + the real ability slugs + the honest data constraint:
  - **The loop:** `seo-status` first (which plugin/mode, sitemap state) → `seo-quick-setup` (baseline) → `seo-site-audit` (find issues) → fix meta (`seo-set-meta` or `seo-bulk-set-meta` for scale) → per-page optimization (`seo-analyze-page` + `seo-optimize-content` toward a focus keyword) → internal linking (`seo-suggest-internal-links` + `seo-insert-internal-link`, fix orphans via `seo-link-audit`) → structured data (`seo-manage-schema`, `seo-manage-local-business`) → technical (`seo-manage-sitemap`, `seo-manage-robots`, `seo-manage-redirects`) → keyword/content strategy (`seo-keyword-research`, `seo-content-gap`, `seo-competitor-analysis`).
  - **Driver fact:** the meta abilities are mode-aware — they read/write Yoast, Rank Math, OR a native store automatically; the same ability works under any of them.
  - **Honest constraint (state it prominently):** there is NO real search-volume or live SERP-rank data (no external API). `seo-keyword-research`/`seo-content-gap` work from AI-proposed candidate keywords cross-referenced against the site; `seo-competitor-analysis` needs the AI to fetch the competitor page and pass its on-page data (the server never scrapes). Frame all keyword/competitor output as heuristic guidance.
  - **Gotchas:** `seo-set-meta` returns `rejected` + `warnings` — read them; `seo-bulk-set-meta` is dry-run by default (pass `apply:true` to write); `seo-insert-internal-link` wraps the first unlinked occurrence of the anchor (returns `inserted:false` if the phrase isn't in the content); title ≤60, meta description 120–160; the native `wp_head` SEO output only runs when NO SEO plugin is active.
  - **End-to-end recipe** ("make my site SEO-ready and help it rank"): the ordered loop above.

- [ ] **Step 4: Verify the skill parses + loads** — deploy (`powershell -File wp-ultra-mcp/bin/deploy.ps1`); token-gated probe requires `includes/skills/sources.php`, calls the loader (`wpultra_skill_all()` — inspect sources.php for the exact name), asserts `seo-architect` is a key with a non-empty `name`+`body`. curl, confirm, delete the probe.

- [ ] **Step 5: Commit**

```bash
git add wp-ultra-mcp/includes/seo/technical.php wp-ultra-mcp/includes/abilities/seo-manage-redirects.php wp-ultra-mcp/includes/skills/built-in/seo-architect.md
git commit -m "feat(seo): seo-architect skill + self-referential-redirect guard"
```

---

### Task 6: Version bump v0.11.0 + README + changelog

**Files:**
- Modify: `wp-ultra-mcp/wp-ultra-mcp.php` (header `Version:` + `WPULTRA_VERSION`), `wp-ultra-mcp/readme.txt` (Stable tag + changelog), `README.md` (SEO abilities section + any total)

**Interfaces:** none. No count change.

- [ ] **Step 1: Bump the plugin version** — in `wp-ultra-mcp/wp-ultra-mcp.php`: header `* Version: 0.10.0` → `* Version: 0.11.0`, and `define('WPULTRA_VERSION', '0.10.0');` → `'0.11.0'`.

- [ ] **Step 2: Update `readme.txt`** — `Stable tag: 0.10.0` → `0.11.0`; add a `= 0.11.0 =` entry at the TOP of the `== Changelog ==`:

```
= 0.11.0 =
* Wave 7 — SEO: 19 new abilities for full on-site SEO. Works with Yoast or Rank Math (auto-detected) or a built-in native mode. On-page meta (title/description/focus keyword/robots/OG), page scoring + content optimization, internal-link suggestions/insertion/audit, keyword research + content-gap + competitor analysis (on-site + AI, no external API), technical SEO (sitemap, robots, 301/302 redirects, JSON-LD schema), LocalBusiness structured data, a site-wide SEO audit, rule-based bulk meta, and a Google-recommended quick-setup. New seo-architect skill encodes the ranking loop.
```

- [ ] **Step 3: Update `README.md`** — add an SEO section to the abilities listing (mirror the WooCommerce/Elementor sections) grouping the 19 Wave-7 abilities (foundation 4, linking+research 7, technical+local 5, audit+setup 3) + the `seo-architect` skill; update any plugin-wide "N abilities" total to **96** if present.

- [ ] **Step 4: Run the full suite once more** — `powershell -File tests/run-all.ps1` → `ALL TEST FILES PASSED` (count 96). Lint `wp-ultra-mcp.php`.

- [ ] **Step 5: Deploy** — `powershell -File wp-ultra-mcp/bin/deploy.ps1`.

- [ ] **Step 6: Commit**

```bash
git add wp-ultra-mcp/wp-ultra-mcp.php wp-ultra-mcp/readme.txt README.md
git commit -m "chore(release): Wave 7 SEO, v0.11.0 (96 abilities)"
```

---

## Plan 4 Done — exit criteria

- `seo-site-audit`, `seo-bulk-set-meta` (dry-run default), `seo-quick-setup` live-verified; `tests/bootstrap.test.php` count = **96**; full suite green.
- `seo-architect` skill parses + loads; self-referential-redirect guard added + suite green.
- Version is **0.11.0** in all three spots; README + changelog updated.
- **After this plan:** a final whole-branch review of the entire Wave 7 (from `main`), then `finishing-a-development-branch` → merge to main, push, build zip (`bin/build-zip.ps1`), `gh release create v0.11.0`.

## Self-Review notes (done during planning)

- **Spec coverage (Plan 4 slice):** site-audit ✓ (Task 2), bulk-set-meta ✓ (Task 3), quick-setup ✓ (Task 4), `seo-architect` skill ✓ (Task 5), ship v0.11.0 ✓ (Task 6). The carried self-referential-redirect Minor is fixed (Task 5).
- **Safety:** `seo-bulk-set-meta` dry-run-by-default (writes only on `apply:true`/`dry_run:false`), bounded by `limit`, applies via the validated `wpultra_seo_set_meta` driver; `seo-quick-setup` idempotent + only flips `blog_public`/sitemap (bounded); the redirect guard prevents self-loops.
- **Type consistency:** `wpultra_seo_audit_post`/`_expand_template` pure + unit-tested; site-audit reuses `wpultra_seo_get_meta`/`_extract_post`/`_link_audit`; bulk uses `wpultra_seo_set_meta`; count chain 93→94→95→96 monotonic; engine loop extended once (Task 1, `+audit`) in BOTH loaders.
- **Placeholders:** none — concrete code/commands; the skill body is specified section-by-section (implementer writes the prose, which IS the deliverable).
