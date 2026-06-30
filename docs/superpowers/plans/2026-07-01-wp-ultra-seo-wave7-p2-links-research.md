# WP-Ultra-MCP — Wave 7 SEO · Plan 2: Internal Linking + Research

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`) syntax.
>
> **Plan 2 of the Wave 7 program** (spec: `docs/superpowers/specs/2026-07-01-wp-ultra-seo-wave7-design.md`). Plan 1 shipped the SEO foundation (branch `feat/seo-wave7`, count 81, Yoast active mode=yoast). Wave 7 ships as **v0.11.0** at Plan 4 — do NOT bump version here. Builds on `includes/seo/{setup,meta,head,analyze}.php` + the `seo` category.

**Goal:** 7 abilities — internal linking (`seo-suggest-internal-links`, `seo-insert-internal-link`, `seo-link-audit`) and on-site+AI research (`seo-keyword-research`, `seo-competitor-analysis`, `seo-content-gap`, `seo-optimize-content`).

**Architecture:** Two new engine files. `includes/seo/links.php` — suggest related posts + anchors, insert a contextual link by wrapping the first unlinked occurrence of an anchor phrase in the post content, and audit the internal-link graph (orphans + broken). `includes/seo/research.php` — pure keyword-gap + competitor-compare diffs over **client/AI-provided** candidate data (no external API; the AI supplies keyword candidates and competitor on-page data — the server never scrapes). Seven thin abilities call them.

**Tech Stack:** PHP 8.0+, WP 7.0, WP post/term queries + `wp_update_post`, the Plan-1 `wpultra_seo_*` engine, vendored mcp-adapter, Abilities API. No new deps. No external/paid API.

## Global Constraints

- Every PHP file: `<?php` + `declare(strict_types=1);` + `if (!defined('ABSPATH')) { exit(); }`. Pure-testable engine files use `if (!defined('ABSPATH') && !defined('WPULTRA_TEST')) {}` (so pure functions load in the harness); WP calls only inside function bodies.
- Engine returns arrays/values or `WP_Error` via `wpultra_err`. Abilities return `wpultra_ok([...])` or the `WP_Error`.
- **Ability registration shape** — copy `wp-ultra-mcp/includes/abilities/seo-get-meta.php` (read) / `seo-set-meta.php` (write): named string `execute_callback`, `properties` PLAIN ARRAY, `permission_callback=>'wpultra_permission_callback'`, `meta=>['show_in_rest'=>true,'mcp'=>['public'=>true,'type'=>'tool'],'annotations'=>[...]]`, `category=>'seo'`.
- Reads (`seo-suggest-internal-links`, `seo-link-audit`, `seo-keyword-research`, `seo-competitor-analysis`, `seo-content-gap`, `seo-optimize-content`): `['readonly'=>true,'destructive'=>false,'idempotent'=>true]`, NO audit. Write (`seo-insert-internal-link`): `['readonly'=>false,'destructive'=>false,'idempotent'=>false]` + `wpultra_audit_log` after.
- **`seo-insert-internal-link` mutates post content** — it MUST refuse reserved CPTs: `if (in_array(get_post_type($id), wpultra_reserved_post_types(), true)) { return wpultra_err('reserved_post_type', ...); }` (the helper is in `helpers.php`). Insert only by wrapping a text occurrence in `<a href>`; never inject arbitrary HTML beyond the anchor.
- **Engine require loop:** add `'links','research'` to the seo loop in BOTH `wpultra_load_abilities()` AND the `wpultra_load_seo_frontend()` function in `wp-ultra-mcp.php` (the seo loop currently `['setup','meta','head','analyze']`). `is_readable` guards.
- **Honest constraint (state it in ability descriptions):** keyword research / competitor analysis provide heuristic + AI guidance, NOT real search-volume or live SERP rank. Keyword candidates + competitor on-page data are provided BY THE CALLER (the AI), not scraped.
- `tests/bootstrap.test.php` count `81` → `88`; keep files↔map in sync.
- Bundled PHP: `$PHP = C:/Users/nisha/AppData/Roaming/Local/lightning-services/php-8.2.30+1/bin/win64/php.exe`. Test site root: `C:/Users/nisha/Local Sites/wp-connector/app/public`. Live token: `wpultra-test-9a88`. Yoast 27.9 active (mode=yoast).
- **Re-run `bin/deploy.ps1` after every commit.** Live probes: token-gated webroot scripts, require engine + ability files, clean up, delete.
- **Harness:** `it`, `assert_eq` (strict), `assert_true`; ends `run_tests();`. Pure tests stub nothing.
- Commit messages: `feat(seo):` / `test(seo):`; end body with `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.

## File Structure

```
wp-ultra-mcp/includes/
  seo/
    links.php     NEW — suggest + wrap-anchor (pure) + insert + audit (Tasks 1–3)
    research.php  NEW — keyword-gap + competitor-compare (pure) (Tasks 4–6)
  abilities/
    seo-suggest-internal-links.php  NEW (Task 2)
    seo-insert-internal-link.php    NEW (Task 2)
    seo-link-audit.php              NEW (Task 3)
    seo-keyword-research.php        NEW (Task 5)
    seo-content-gap.php             NEW (Task 5)
    seo-competitor-analysis.php     NEW (Task 6)
    seo-optimize-content.php        NEW (Task 6)
  bootstrap-mcp.php   MODIFY — engine loop + 7 slugs
  wp-ultra-mcp.php    MODIFY — add 'links','research' to wpultra_load_seo_frontend() loop (Task 1)
tests/
  seo-links.test.php     NEW — pure wrap-anchor + suggest-rank tests (Task 1)
  seo-research.test.php  NEW — pure keyword-gap + competitor-compare tests (Task 4)
  bootstrap.test.php     MODIFY — count 81 → 88
```

---

### Task 1: `links.php` — suggest + pure wrap-anchor + audit engine

**Files:**
- Create: `wp-ultra-mcp/includes/seo/links.php`, `tests/seo-links.test.php`
- Modify: `wp-ultra-mcp/includes/bootstrap-mcp.php` (seo engine loop `+links,research`), `wp-ultra-mcp/includes/wp-ultra-mcp.php` (`wpultra_load_seo_frontend()` loop `+links,research`)

**Interfaces:**
- Produces:
  - `wpultra_seo_wrap_anchor(string $content, string $anchor, string $url): array` — PURE. Wraps the FIRST occurrence of `$anchor` that is NOT already inside an `<a>...</a>` in `<a href="$url">$anchor</a>`. Returns `['content'=>string, 'inserted'=>bool]`.
  - `wpultra_seo_rank_candidates(array $source, array $candidates): array` — PURE. Given a source `{keywords:[...]}` and candidate posts `[{id,title,terms:[...],keywords:[...]}]`, score each by term+keyword overlap, return sorted `[{id,title,score}]` (desc, score>0 only).
  - `wpultra_seo_suggest_links(int $post_id, int $limit): array` — WP: gather candidates (other published posts sharing a category/tag or containing the source's focus keyword), rank, return `[{target_id,target_title,target_url,anchor_suggestion,score}]`.
  - `wpultra_seo_insert_link(int $post_id, string $anchor, string $url): array|WP_Error` — WP: reserved-CPT guard; `wpultra_seo_wrap_anchor` on the post content; if inserted, `wp_update_post`; return `['post_id','inserted','anchor']`.
  - `wpultra_seo_link_audit(int $limit): array` — WP: build the internal-link graph over published posts; return `['orphans'=>[{id,title}], 'broken'=>[{post_id,href}], 'counts'=>{...}]`.

- [ ] **Step 1: Write the failing test** — `tests/seo-links.test.php`

```php
<?php
require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../wp-ultra-mcp/includes/seo/links.php';

it('wraps first unlinked occurrence of the anchor', function () {
    $r = wpultra_seo_wrap_anchor('<p>Buy blue widgets today. Blue widgets rock.</p>', 'blue widgets', 'http://x.test/bw');
    assert_eq(true, $r['inserted']);
    assert_true(strpos($r['content'], '<a href="http://x.test/bw">blue widgets</a>') !== false);
    // only the first occurrence wrapped
    assert_eq(1, substr_count($r['content'], '<a href='));
});

it('does not double-wrap an already-linked anchor', function () {
    $html = '<p>See <a href="http://y/">blue widgets</a> here. blue widgets again.</p>';
    $r = wpultra_seo_wrap_anchor($html, 'blue widgets', 'http://x.test/bw');
    // the already-linked first occurrence is skipped; the second (plain) is wrapped
    assert_eq(true, $r['inserted']);
    assert_eq(2, substr_count($r['content'], '<a href='));
});

it('reports not-inserted when anchor absent', function () {
    $r = wpultra_seo_wrap_anchor('<p>Nothing here.</p>', 'blue widgets', 'http://x/bw');
    assert_eq(false, $r['inserted']);
    assert_eq('<p>Nothing here.</p>', $r['content']);
});

it('ranks candidates by term + keyword overlap', function () {
    $source = ['keywords' => ['blue', 'widgets']];
    $cands = [
        ['id' => 1, 'title' => 'A', 'terms' => ['x'], 'keywords' => ['blue', 'widgets']],
        ['id' => 2, 'title' => 'B', 'terms' => ['x'], 'keywords' => ['red']],
        ['id' => 3, 'title' => 'C', 'terms' => ['x'], 'keywords' => ['widgets']],
    ];
    $r = wpultra_seo_rank_candidates($source, $cands);
    assert_eq(1, $r[0]['id']); // most overlap first
    assert_eq(2, count($r));    // id=2 (zero overlap) dropped
});

run_tests();
```

- [ ] **Step 2: Run → fail** — `& $PHP tests/seo-links.test.php` → FAIL.

- [ ] **Step 3: Write `links.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH') && !defined('WPULTRA_TEST')) { /* allow harness load */ }

/** PURE. Wrap the first occurrence of $anchor NOT already inside an <a> in a link. */
function wpultra_seo_wrap_anchor(string $content, string $anchor, string $url): array {
    if ($anchor === '' || stripos($content, $anchor) === false) { return ['content' => $content, 'inserted' => false]; }
    // Split out existing <a>...</a> regions so we only consider text outside them.
    $parts = preg_split('/(<a\b[^>]*>.*?<\/a>)/is', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
    $out = '';
    $done = false;
    foreach ($parts as $seg) {
        if (!$done && stripos($seg, '<a') !== 0) {
            $pos = stripos($seg, $anchor);
            if ($pos !== false) {
                $orig = substr($seg, $pos, strlen($anchor));
                $seg = substr($seg, 0, $pos) . '<a href="' . $url . '">' . $orig . '</a>' . substr($seg, $pos + strlen($anchor));
                $done = true;
            }
        }
        $out .= $seg;
    }
    return ['content' => $out, 'inserted' => $done];
}

/** PURE. Score candidate posts by overlap with the source's keywords. */
function wpultra_seo_rank_candidates(array $source, array $candidates): array {
    $srcKw = array_map('strtolower', $source['keywords'] ?? []);
    $rows = [];
    foreach ($candidates as $c) {
        $kw = array_map('strtolower', $c['keywords'] ?? []);
        $score = count(array_intersect($srcKw, $kw));
        if ($score > 0) { $rows[] = ['id' => $c['id'], 'title' => $c['title'] ?? '', 'score' => $score]; }
    }
    usort($rows, function ($a, $b) { return $b['score'] <=> $a['score']; });
    return $rows;
}

function wpultra_seo_post_keywords(int $post_id): array {
    $kw = [];
    if (function_exists('wpultra_seo_get_meta')) {
        $fk = (string) (wpultra_seo_get_meta($post_id)['focus_keyword'] ?? '');
        if ($fk !== '') { $kw = preg_split('/\s+/', strtolower($fk)); }
    }
    $title = strtolower(get_the_title($post_id));
    foreach (preg_split('/\s+/', $title) as $w) { if (strlen($w) >= 4) { $kw[] = $w; } }
    return array_values(array_unique(array_filter($kw)));
}

function wpultra_seo_suggest_links(int $post_id, int $limit): array {
    $source = ['keywords' => wpultra_seo_post_keywords($post_id)];
    $catIds = wp_get_post_categories($post_id);
    $tagIds = wp_get_post_tags($post_id, ['fields' => 'ids']);
    $args = ['post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 30, 'post__not_in' => [$post_id], 'fields' => 'ids'];
    if ($catIds || $tagIds) { $args['tax_query'] = ['relation' => 'OR']; }
    if ($catIds) { $args['tax_query'][] = ['taxonomy' => 'category', 'field' => 'term_id', 'terms' => $catIds]; }
    if ($tagIds) { $args['tax_query'][] = ['taxonomy' => 'post_tag', 'field' => 'term_id', 'terms' => $tagIds]; }
    $ids = get_posts($args);
    $cands = [];
    foreach ($ids as $id) { $cands[] = ['id' => (int) $id, 'title' => get_the_title($id), 'terms' => [], 'keywords' => wpultra_seo_post_keywords((int) $id)]; }
    $ranked = wpultra_seo_rank_candidates($source, $cands);
    $out = [];
    foreach (array_slice($ranked, 0, max(1, $limit)) as $r) {
        $out[] = ['target_id' => $r['id'], 'target_title' => $r['title'], 'target_url' => get_permalink($r['id']), 'anchor_suggestion' => $r['title'], 'score' => $r['score']];
    }
    return $out;
}

function wpultra_seo_insert_link(int $post_id, string $anchor, string $url) {
    $post = get_post($post_id);
    if (!$post) { return wpultra_err('post_not_found', "No post with id $post_id."); }
    if (in_array($post->post_type, wpultra_reserved_post_types(), true)) {
        return wpultra_err('reserved_post_type', "Post $post_id is plugin-internal; not editable here.");
    }
    $r = wpultra_seo_wrap_anchor((string) $post->post_content, $anchor, esc_url_raw($url));
    if (!$r['inserted']) { return ['post_id' => $post_id, 'inserted' => false, 'anchor' => $anchor]; }
    wp_update_post(['ID' => $post_id, 'post_content' => $r['content']]);
    return ['post_id' => $post_id, 'inserted' => true, 'anchor' => $anchor];
}

function wpultra_seo_link_audit(int $limit): array {
    $ids = get_posts(['post_type' => ['post', 'page'], 'post_status' => 'publish', 'posts_per_page' => max(1, $limit), 'fields' => 'ids']);
    $home = wp_parse_url(home_url(), PHP_URL_HOST);
    $incoming = array_fill_keys(array_map('intval', $ids), 0);
    $broken = [];
    foreach ($ids as $id) {
        $content = (string) get_post_field('post_content', $id);
        if (preg_match_all('/<a\s[^>]*href=["\']([^"\']+)["\']/i', $content, $m)) {
            foreach ($m[1] as $href) {
                $host = wp_parse_url($href, PHP_URL_HOST);
                if ($host && $host !== $home) { continue; }
                $target = url_to_postid($href);
                if ($target && isset($incoming[$target])) { $incoming[$target]++; }
                elseif ($target === 0 && strpos($href, $home ?: 'wp-connector') !== false) { $broken[] = ['post_id' => (int) $id, 'href' => $href]; }
            }
        }
    }
    $orphans = [];
    foreach ($incoming as $pid => $count) { if ($count === 0) { $orphans[] = ['id' => $pid, 'title' => get_the_title($pid)]; } }
    return ['orphans' => $orphans, 'broken' => $broken, 'counts' => ['scanned' => count($ids), 'orphans' => count($orphans), 'broken' => count($broken)]];
}
```

- [ ] **Step 4: Run → pass** — `& $PHP tests/seo-links.test.php` → PASS (4 `it`). Lint `links.php`.

- [ ] **Step 5: Wire the engine loop** — in `bootstrap-mcp.php` `wpultra_load_abilities()` change the seo loop to `['setup','meta','head','analyze','links','research']`; in `wp-ultra-mcp.php` `wpultra_load_seo_frontend()` change ITS seo loop the same way (`research.php` comes in Task 4; `is_readable` guards). Lint both.

- [ ] **Step 6: Commit**

```bash
git add wp-ultra-mcp/includes/seo/links.php tests/seo-links.test.php wp-ultra-mcp/includes/bootstrap-mcp.php wp-ultra-mcp/includes/wp-ultra-mcp.php
git commit -m "feat(seo): internal-link engine (suggest + pure wrap-anchor + audit) + engine-loop wiring"
```

---

### Task 2: `seo-suggest-internal-links` + `seo-insert-internal-link`

**Files:**
- Create: `wp-ultra-mcp/includes/abilities/seo-suggest-internal-links.php`, `seo-insert-internal-link.php`
- Modify: `bootstrap-mcp.php` (2 slugs), `tests/bootstrap.test.php` (81 → 83)

**Interfaces:** Consumes `wpultra_seo_suggest_links`, `wpultra_seo_insert_link`.

- [ ] **Step 1: Write `seo-suggest-internal-links` ability** (read-only)

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/seo-suggest-internal-links', [
    'label'       => __('SEO: Suggest Internal Links', 'wp-ultra-mcp'),
    'description' => __('Suggest related published posts to link to from a given post (ranked by category/tag/keyword overlap) with anchor-text suggestions.', 'wp-ultra-mcp'),
    'category'    => 'seo',
    'input_schema' => ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer'], 'limit' => ['type' => 'integer']], 'required' => ['post_id'], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean'], 'suggestions' => ['type' => 'array']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_seo_suggest_links_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

function wpultra_seo_suggest_links_cb(array $input) {
    $id = (int) ($input['post_id'] ?? 0);
    if (!get_post($id)) { return wpultra_err('post_not_found', "No post with id $id."); }
    $limit = isset($input['limit']) ? (int) $input['limit'] : 5;
    return wpultra_ok(['suggestions' => wpultra_seo_suggest_links($id, $limit)]);
}
```

- [ ] **Step 2: Write `seo-insert-internal-link` ability** (write + audit)

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/seo-insert-internal-link', [
    'label'       => __('SEO: Insert Internal Link', 'wp-ultra-mcp'),
    'description' => __('Insert a contextual internal link into a post by wrapping the first unlinked occurrence of the anchor text in a link to the target URL. Returns inserted:false if the anchor is not found.', 'wp-ultra-mcp'),
    'category'    => 'seo',
    'input_schema' => ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer'], 'anchor' => ['type' => 'string'], 'target_url' => ['type' => 'string']], 'required' => ['post_id', 'anchor', 'target_url'], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean'], 'inserted' => ['type' => 'boolean']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_seo_insert_link_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

function wpultra_seo_insert_link_cb(array $input) {
    $res = wpultra_seo_insert_link((int) ($input['post_id'] ?? 0), (string) ($input['anchor'] ?? ''), (string) ($input['target_url'] ?? ''));
    wpultra_audit_log('seo-insert-internal-link', is_wp_error($res) ? 'failed' : ('post ' . $res['post_id'] . ' inserted=' . ($res['inserted'] ? '1' : '0')), !is_wp_error($res));
    if (is_wp_error($res)) { return $res; }
    return wpultra_ok(['inserted' => $res['inserted'], 'anchor' => $res['anchor']]);
}
```

- [ ] **Step 3: Wire 2 slugs + bump count** — add to files + map; `tests/bootstrap.test.php` `81` → `83`.

- [ ] **Step 4: Run bootstrap test** — PASS (83). Lint both ability files.

- [ ] **Step 5: Deploy + live-verify** — `powershell -File bin/deploy.ps1` (path `wp-ultra-mcp/bin/deploy.ps1`). Probe (require `includes/seo/{setup,meta,links}.php` + 2 ability files + helpers; admin user): create 2 published posts in the same category, one with the other's title phrase in its body. `seo-suggest-internal-links` on post A → assert ≥1 suggestion pointing at post B. `seo-insert-internal-link` `{post_id:A, anchor:'<phrase in A body>', target_url:'<B permalink>'}` → assert `inserted:true`; re-fetch post A content and assert it now contains `<a href="...B...">`. Insert with an absent anchor → `inserted:false`. Force-delete the 2 posts + the probe.

- [ ] **Step 6: Commit**

```bash
git add wp-ultra-mcp/includes/abilities/seo-suggest-internal-links.php wp-ultra-mcp/includes/abilities/seo-insert-internal-link.php wp-ultra-mcp/includes/bootstrap-mcp.php tests/bootstrap.test.php
git commit -m "feat(seo): seo-suggest-internal-links + seo-insert-internal-link"
```

---

### Task 3: `seo-link-audit`

**Files:**
- Create: `wp-ultra-mcp/includes/abilities/seo-link-audit.php`
- Modify: `bootstrap-mcp.php` (1 slug), `tests/bootstrap.test.php` (83 → 84)

**Interfaces:** Consumes `wpultra_seo_link_audit`.

- [ ] **Step 1: Write `seo-link-audit` ability** (read-only)

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/seo-link-audit', [
    'label'       => __('SEO: Link Audit', 'wp-ultra-mcp'),
    'description' => __('Audit the internal-link graph across published posts/pages: orphan pages (no incoming internal links) and broken internal links. limit caps how many posts are scanned.', 'wp-ultra-mcp'),
    'category'    => 'seo',
    'input_schema' => ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer']], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean'], 'orphans' => ['type' => 'array'], 'broken' => ['type' => 'array'], 'counts' => ['type' => 'object']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_seo_link_audit_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

function wpultra_seo_link_audit_cb(array $input) {
    $limit = isset($input['limit']) ? (int) $input['limit'] : 200;
    return wpultra_ok(wpultra_seo_link_audit($limit));
}
```

- [ ] **Step 2: Wire slug + bump count** — add `'seo-link-audit'`; `tests/bootstrap.test.php` `83` → `84`.

- [ ] **Step 3: Run bootstrap test** — PASS (84). Lint the ability file.

- [ ] **Step 4: Deploy + live-verify** — probe: create 2 published posts, one linking to the other (the other gets no incoming link → orphan). `seo-link-audit` → assert `counts.scanned` ≥ 2, the un-linked post appears in `orphans`, and a deliberately-broken internal href (`home_url('/no-such-post-xyz/')`) appears in `broken`. Force-delete the posts + probe.

- [ ] **Step 5: Commit**

```bash
git add wp-ultra-mcp/includes/abilities/seo-link-audit.php wp-ultra-mcp/includes/bootstrap-mcp.php tests/bootstrap.test.php
git commit -m "feat(seo): seo-link-audit (orphans + broken internal links)"
```

---

### Task 4: `research.php` — pure keyword-gap + competitor-compare

**Files:**
- Create: `wp-ultra-mcp/includes/seo/research.php`, `tests/seo-research.test.php`

**Interfaces:**
- Produces:
  - `wpultra_seo_keyword_gaps(array $candidates, array $site_index): array` — PURE. `$candidates` = list of keyword strings; `$site_index` = `[{post_id,title,focus_keyword,title_lc}]`. Returns `['covered'=>[{keyword,post_id}], 'gaps'=>[keyword]]` — a keyword is covered if it equals a focus_keyword or is a substring of a title (case-insensitive).
  - `wpultra_seo_competitor_compare(array $ours, array $theirs): array` — PURE. Each side `{title, headings:[...], word_count:int, keywords:[...]}`. Returns `['missing_headings'=>[...], 'missing_keywords'=>[...], 'word_count_delta'=>int, 'recommendations'=>[...]]`.

- [ ] **Step 1: Write the failing test** — `tests/seo-research.test.php`

```php
<?php
require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../wp-ultra-mcp/includes/seo/research.php';

it('keyword gaps splits covered vs gaps', function () {
    $cands = ['blue widgets', 'red widgets', 'green widgets'];
    $index = [
        ['post_id' => 1, 'title' => 'Best Blue Widgets', 'focus_keyword' => 'blue widgets', 'title_lc' => 'best blue widgets'],
        ['post_id' => 2, 'title' => 'Red Widget Guide', 'focus_keyword' => '', 'title_lc' => 'red widget guide'],
    ];
    $r = wpultra_seo_keyword_gaps($cands, $index);
    $coveredKw = array_map(function ($c) { return $c['keyword']; }, $r['covered']);
    assert_true(in_array('blue widgets', $coveredKw, true)); // focus keyword match
    assert_true(in_array('green widgets', $r['gaps'], true)); // no page
});

it('competitor compare finds missing headings/keywords + word delta', function () {
    $ours = ['title' => 'Mine', 'headings' => ['Intro', 'Pricing'], 'word_count' => 400, 'keywords' => ['blue']];
    $theirs = ['title' => 'Theirs', 'headings' => ['Intro', 'Pricing', 'FAQ'], 'word_count' => 1000, 'keywords' => ['blue', 'cheap']];
    $r = wpultra_seo_competitor_compare($ours, $theirs);
    assert_true(in_array('FAQ', $r['missing_headings'], true));
    assert_true(in_array('cheap', $r['missing_keywords'], true));
    assert_eq(-600, $r['word_count_delta']);
});

run_tests();
```

- [ ] **Step 2: Run → fail** — `& $PHP tests/seo-research.test.php` → FAIL.

- [ ] **Step 3: Write `research.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH') && !defined('WPULTRA_TEST')) { /* allow harness load */ }

function wpultra_seo_keyword_gaps(array $candidates, array $site_index): array {
    $covered = [];
    $gaps = [];
    foreach ($candidates as $kwRaw) {
        $kw = strtolower(trim((string) $kwRaw));
        if ($kw === '') { continue; }
        $hitId = 0;
        foreach ($site_index as $row) {
            $fk = strtolower(trim((string) ($row['focus_keyword'] ?? '')));
            $titleLc = (string) ($row['title_lc'] ?? strtolower((string) ($row['title'] ?? '')));
            if (($fk !== '' && $fk === $kw) || strpos($titleLc, $kw) !== false) { $hitId = (int) $row['post_id']; break; }
        }
        if ($hitId) { $covered[] = ['keyword' => $kwRaw, 'post_id' => $hitId]; }
        else { $gaps[] = $kwRaw; }
    }
    return ['covered' => $covered, 'gaps' => $gaps];
}

function wpultra_seo_competitor_compare(array $ours, array $theirs): array {
    $lc = function ($arr) { return array_map('strtolower', array_map('strval', $arr)); };
    $ourHead = $lc($ours['headings'] ?? []);
    $ourKw = $lc($ours['keywords'] ?? []);
    $missingHeadings = [];
    foreach (($theirs['headings'] ?? []) as $h) { if (!in_array(strtolower((string) $h), $ourHead, true)) { $missingHeadings[] = $h; } }
    $missingKeywords = [];
    foreach (($theirs['keywords'] ?? []) as $k) { if (!in_array(strtolower((string) $k), $ourKw, true)) { $missingKeywords[] = $k; } }
    $delta = (int) ($ours['word_count'] ?? 0) - (int) ($theirs['word_count'] ?? 0);
    $recs = [];
    if ($delta < -200) { $recs[] = 'Competitor content is substantially longer; consider expanding by ~' . abs($delta) . ' words.'; }
    foreach ($missingHeadings as $h) { $recs[] = "Add a section covering \"$h\"."; }
    foreach ($missingKeywords as $k) { $recs[] = "Cover the keyword/term \"$k\"."; }
    return ['missing_headings' => $missingHeadings, 'missing_keywords' => $missingKeywords, 'word_count_delta' => $delta, 'recommendations' => $recs];
}

/** WP helper: build the site keyword index for keyword-gap. */
function wpultra_seo_site_index(int $limit = 200): array {
    $ids = get_posts(['post_type' => ['post', 'page'], 'post_status' => 'publish', 'posts_per_page' => max(1, $limit), 'fields' => 'ids']);
    $idx = [];
    foreach ($ids as $id) {
        $fk = function_exists('wpultra_seo_get_meta') ? (string) (wpultra_seo_get_meta((int) $id)['focus_keyword'] ?? '') : '';
        $title = get_the_title($id);
        $idx[] = ['post_id' => (int) $id, 'title' => $title, 'focus_keyword' => $fk, 'title_lc' => strtolower($title)];
    }
    return $idx;
}
```

- [ ] **Step 4: Run → pass** — `& $PHP tests/seo-research.test.php` → PASS (2 `it`). Lint `research.php`.

- [ ] **Step 5: Commit**

```bash
git add wp-ultra-mcp/includes/seo/research.php tests/seo-research.test.php
git commit -m "feat(seo): pure keyword-gap + competitor-compare research engine (+tests)"
```

---

### Task 5: `seo-keyword-research` + `seo-content-gap`

**Files:**
- Create: `wp-ultra-mcp/includes/abilities/seo-keyword-research.php`, `seo-content-gap.php`
- Modify: `bootstrap-mcp.php` (2 slugs), `tests/bootstrap.test.php` (84 → 86)

**Interfaces:** Consumes `wpultra_seo_keyword_gaps`, `wpultra_seo_site_index`.

- [ ] **Step 1: Write `seo-keyword-research` ability** (read-only)

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/seo-keyword-research', [
    'label'       => __('SEO: Keyword Research', 'wp-ultra-mcp'),
    'description' => __('Given candidate keywords (you, the AI, propose them — there is NO search-volume data), report which the site already targets vs content gaps. If no candidates are given, returns the site\'s current focus keywords as a starting point.', 'wp-ultra-mcp'),
    'category'    => 'seo',
    'input_schema' => ['type' => 'object', 'properties' => ['candidate_keywords' => ['type' => 'array'], 'limit' => ['type' => 'integer']], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean'], 'covered' => ['type' => 'array'], 'gaps' => ['type' => 'array'], 'existing_focus_keywords' => ['type' => 'array']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_seo_keyword_research_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

function wpultra_seo_keyword_research_cb(array $input) {
    $limit = isset($input['limit']) ? (int) $input['limit'] : 200;
    $index = wpultra_seo_site_index($limit);
    $existing = [];
    foreach ($index as $row) { if (!empty($row['focus_keyword'])) { $existing[] = $row['focus_keyword']; } }
    $cands = isset($input['candidate_keywords']) && is_array($input['candidate_keywords']) ? $input['candidate_keywords'] : [];
    $res = wpultra_seo_keyword_gaps($cands, $index);
    return wpultra_ok(['covered' => $res['covered'], 'gaps' => $res['gaps'], 'existing_focus_keywords' => array_values(array_unique($existing))]);
}
```

- [ ] **Step 2: Write `seo-content-gap` ability** (read-only) — same engine, framed as "which of these target topics has no page".

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/seo-content-gap', [
    'label'       => __('SEO: Content Gap', 'wp-ultra-mcp'),
    'description' => __('Given a list of target topics/keywords, list which have NO dedicated page on the site (content gaps) vs which are already covered. Heuristic (title/focus-keyword match); no external data.', 'wp-ultra-mcp'),
    'category'    => 'seo',
    'input_schema' => ['type' => 'object', 'properties' => ['topics' => ['type' => 'array'], 'limit' => ['type' => 'integer']], 'required' => ['topics'], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean'], 'covered' => ['type' => 'array'], 'gaps' => ['type' => 'array']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_seo_content_gap_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

function wpultra_seo_content_gap_cb(array $input) {
    $topics = is_array($input['topics'] ?? null) ? $input['topics'] : [];
    $limit = isset($input['limit']) ? (int) $input['limit'] : 200;
    $res = wpultra_seo_keyword_gaps($topics, wpultra_seo_site_index($limit));
    return wpultra_ok(['covered' => $res['covered'], 'gaps' => $res['gaps']]);
}
```

- [ ] **Step 3: Wire 2 slugs + bump count** — add both; `tests/bootstrap.test.php` `84` → `86`.

- [ ] **Step 4: Run bootstrap test** — PASS (86). Lint both ability files.

- [ ] **Step 5: Deploy + live-verify** — probe: ensure a published post titled with "blue widgets" exists (create one). `seo-keyword-research` `{candidate_keywords:['blue widgets','teleport widgets']}` → assert `blue widgets` in `covered` (with the post id) and `teleport widgets` in `gaps`. `seo-content-gap` `{topics:['blue widgets','teleport widgets']}` → same split. Force-delete the test post + probe.

- [ ] **Step 6: Commit**

```bash
git add wp-ultra-mcp/includes/abilities/seo-keyword-research.php wp-ultra-mcp/includes/abilities/seo-content-gap.php wp-ultra-mcp/includes/bootstrap-mcp.php tests/bootstrap.test.php
git commit -m "feat(seo): seo-keyword-research + seo-content-gap (on-site coverage vs gaps)"
```

---

### Task 6: `seo-competitor-analysis` + `seo-optimize-content`

**Files:**
- Create: `wp-ultra-mcp/includes/abilities/seo-competitor-analysis.php`, `seo-optimize-content.php`
- Modify: `bootstrap-mcp.php` (2 slugs), `tests/bootstrap.test.php` (86 → 88)

**Interfaces:** Consumes `wpultra_seo_competitor_compare`, `wpultra_seo_extract_post` + `wpultra_seo_score` (Plan 1 analyze.php).

- [ ] **Step 1: Write `seo-competitor-analysis` ability** (read-only) — our post vs client-provided competitor on-page data.

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/seo-competitor-analysis', [
    'label'       => __('SEO: Competitor Analysis', 'wp-ultra-mcp'),
    'description' => __('Compare our post against a competitor page. YOU (the AI) fetch the competitor page and pass its on-page data as competitor={title,headings[],word_count,keywords[]} — the server does NOT scrape. Returns missing headings/keywords + word-count delta + recommendations.', 'wp-ultra-mcp'),
    'category'    => 'seo',
    'input_schema' => ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer'], 'competitor' => ['type' => 'object']], 'required' => ['post_id', 'competitor'], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean'], 'missing_headings' => ['type' => 'array'], 'missing_keywords' => ['type' => 'array'], 'word_count_delta' => ['type' => 'integer'], 'recommendations' => ['type' => 'array']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_seo_competitor_analysis_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

function wpultra_seo_competitor_analysis_cb(array $input) {
    $id = (int) ($input['post_id'] ?? 0);
    if (!get_post($id)) { return wpultra_err('post_not_found', "No post with id $id."); }
    $d = wpultra_seo_extract_post($id);
    $ours = [
        'title' => $d['title'] ?? '',
        'headings' => array_filter([$d['h1'] ?? '']),
        'word_count' => str_word_count((string) ($d['body_text'] ?? '')),
        'keywords' => array_filter([$d['focus_keyword'] ?? '']),
    ];
    $theirs = is_array($input['competitor'] ?? null) ? $input['competitor'] : [];
    return wpultra_ok(wpultra_seo_competitor_compare($ours, $theirs));
}
```

- [ ] **Step 2: Write `seo-optimize-content` ability** (read-only) — scorer + targeted improvement plan.

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/seo-optimize-content', [
    'label'       => __('SEO: Optimize Content', 'wp-ultra-mcp'),
    'description' => __('Score a post for a target keyword and return a prioritized content improvement plan (the failing/warning on-page checks as actionable steps). Optional focus_keyword override. Advisory — does not rewrite content.', 'wp-ultra-mcp'),
    'category'    => 'seo',
    'input_schema' => ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer'], 'focus_keyword' => ['type' => 'string']], 'required' => ['post_id'], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean'], 'score' => ['type' => 'integer'], 'improvements' => ['type' => 'array']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_seo_optimize_content_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

function wpultra_seo_optimize_content_cb(array $input) {
    $id = (int) ($input['post_id'] ?? 0);
    if (!get_post($id)) { return wpultra_err('post_not_found', "No post with id $id."); }
    $data = wpultra_seo_extract_post($id);
    if (!empty($input['focus_keyword'])) { $data['focus_keyword'] = (string) $input['focus_keyword']; }
    $scored = wpultra_seo_score($data);
    $improvements = [];
    foreach ($scored['checks'] as $c) {
        if ($c['status'] !== 'pass') { $improvements[] = ['priority' => ($c['status'] === 'fail' ? 'high' : 'medium'), 'check' => $c['id'], 'action' => $c['message']]; }
    }
    return wpultra_ok(['score' => $scored['score'], 'improvements' => $improvements]);
}
```

- [ ] **Step 3: Wire 2 slugs + bump count** — add both; `tests/bootstrap.test.php` `86` → `88`.

- [ ] **Step 4: Run the FULL suite** — `powershell -File tests/run-all.ps1` → `ALL TEST FILES PASSED` (bootstrap 88, seo-links 4, seo-research 2, nothing regressed). Lint both ability files.

- [ ] **Step 5: Deploy + live-verify** — probe: pick a real post id. `seo-competitor-analysis` `{post_id, competitor:{title:'X', headings:['Intro','Pricing','FAQ'], word_count:1500, keywords:['cheap widgets']}}` → assert `missing_headings` includes the ones our post lacks + a `word_count_delta` + recommendations. `seo-optimize-content` `{post_id, focus_keyword:'widgets'}` → assert a numeric `score` + an `improvements` array with `priority`/`check`/`action`. Delete the probe.

- [ ] **Step 6: Commit**

```bash
git add wp-ultra-mcp/includes/abilities/seo-competitor-analysis.php wp-ultra-mcp/includes/abilities/seo-optimize-content.php wp-ultra-mcp/includes/bootstrap-mcp.php tests/bootstrap.test.php
git commit -m "feat(seo): seo-competitor-analysis + seo-optimize-content"
```

---

## Plan 2 Done — exit criteria

- 7 abilities under `seo`; `tests/bootstrap.test.php` count = **88**; full suite green.
- Live-verified: suggest + insert an internal link (content wrapped); link audit finds orphans + broken; keyword-research/content-gap split covered vs gaps over the live site; competitor-compare + optimize-content produce actionable output.
- Honest data constraint stated in every research ability's description (AI supplies candidates/competitor data; no search-volume/SERP).
- Do NOT bump plugin version (Plan 4 ships v0.11.0).

## Self-Review notes (done during planning)

- **Spec coverage (Plan 2 slice):** suggest/insert/audit internal links ✓ (Tasks 1–3), keyword-research ✓ + content-gap ✓ (Task 5), competitor-analysis ✓ + optimize-content ✓ (Task 6). Technical/local/audit/quick-setup/skill are Plans 3–4.
- **Type consistency:** `wpultra_seo_wrap_anchor` returns `['content','inserted']` consumed by `wpultra_seo_insert_link`; `wpultra_seo_keyword_gaps` returns `['covered','gaps']` consumed by both research abilities; `wpultra_seo_competitor_compare` shape matches the competitor ability output_schema; `wpultra_seo_extract_post`/`wpultra_seo_score` reused from Plan 1 analyze.php. Count chain 81→83→84→86→88 monotonic; engine loop extended once (Task 1, `+links,research`) in BOTH `wpultra_load_abilities` and `wpultra_load_seo_frontend`.
- **Safety:** `seo-insert-internal-link` guards reserved CPTs + only wraps text in `<a>` (no arbitrary HTML); URL `esc_url_raw`'d. Research abilities are read-only + honest about data source.
- **Placeholders:** none — concrete code/commands throughout.
