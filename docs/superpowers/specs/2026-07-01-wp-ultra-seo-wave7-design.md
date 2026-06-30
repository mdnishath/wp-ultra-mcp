# WP-Ultra-MCP — Wave 7 (SEO)

> Design doc. Status: **approved (design)**, ready to plan. Builds on the shipped v0.10.0 plugin (Waves 1–6: WP core, Elementor, Gutenberg, WooCommerce). Ships as **v0.11.0**. One comprehensive wave (user-chosen "one-man-army" SEO), built in 4 plans, each independently testable.

## Problem

The plugin controls WordPress, page builders, and WooCommerce — but has **zero SEO capability**. The user wants a "one-man-army" SEO power: top-quality, Google-recommended setup; deep integration with the two dominant SEO plugins (**Yoast** + **Rank Math**); and a plugin-agnostic on-page + internal-linking engine that helps content reach Google's first page — covering both **global/technical** and **local** SEO.

Neither Yoast nor Rank Math is installed on the test site (both will be installed to live-verify, like WooCommerce). No competitor (Novamira/Pro) has any SEO support — this is a genuine greenfield differentiator.

## Principles (carried from Elementor/WooCommerce)

1. **Schema-driven + validated.** Abilities accept documented fields, coerce + validate, report rejected fields — never silent drops.
2. **Hybrid: plugin-aware driver + plugin-agnostic engine.** SEO *meta/config* is read/written through whichever SEO plugin is active (Yoast OR Rank Math); when neither is active, a **native** store + a lightweight `wp_head` renderer make the meta actually work. SEO *analysis, internal linking, and audit* are plugin-agnostic and always work.
3. **No external data dependency (user-chosen).** Keyword research and competitor analysis are **on-site + AI-driven** — no Google Search Console, no paid API. Keyword ideas come from the AI's knowledge cross-referenced against site content; competitor analysis works on competitor on-page data the **client (Claude) fetches** (the Elementor "client perceives the reference" pattern — the server never scrapes). The spec is explicit that these provide heuristic guidance, NOT real search-volume or live SERP rank numbers.
4. **Pure engine, thin abilities.** Logic in `includes/seo/*.php` (the scorer, link-suggester, validators are unit-testable); abilities are wiring + audit.
5. **Graceful degradation.** Every ability checks what's available (which plugin, whether native head is active) and reports it; nothing fatals when a plugin is absent.

## Architecture

`includes/seo/` — module-per-file, mirroring `includes/woocommerce/`:

| File | Responsibility |
|---|---|
| `setup.php` | Detect active SEO plugin (Yoast: `WPSEO_VERSION`/`WPSEO_Options`; Rank Math: `RANK_MATH_VERSION`/`rank_math()`); report mode (`yoast`/`rankmath`/`native`), sitemap/robots/schema state, counts. |
| `meta.php` | The **driver abstraction**: `wpultra_seo_get_meta($post_id)` / `wpultra_seo_set_meta($post_id,$fields)` route to Yoast keys, Rank Math keys, or the native store based on the active mode. Canonical field set: `title, description, focus_keyword, canonical, robots_noindex, robots_nofollow, og_title, og_description, og_image, twitter_title, twitter_description`. |
| `head.php` | **Native mode only** — outputs `<title>`/meta-description/canonical/robots/OG/Twitter/JSON-LD on `wp_head` (+ `document_title_parts`) when NO SEO plugin is active (so the native store renders); no-ops when Yoast/Rank Math is active (avoids double output). |
| `analyze.php` | Pure-ish on-page scorer: keyword in SEO-title/H1/first-paragraph/slug, keyword density, meta-desc length (120–160), title length (≤60), word count, internal/external link counts, images-missing-alt, subheading distribution, approximate Flesch readability → `score 0–100` + per-check `pass/warn/fail` + recommendations. |
| `links.php` | Internal linking: suggest related published posts + anchor text (shared terms/category/keyword overlap); insert a contextual link via the Wave-4a Gutenberg engine; link audit (orphan pages, broken internal links). |
| `technical.php` | Sitemap (drive Yoast/Rank Math, else WP-core `wp-sitemap.xml` toggle), robots.txt (`robots_txt` filter / plugin), redirects (a stored map applied on `template_redirect`; or drive Rank Math redirections), schema/JSON-LD (Article/Product/FAQ/BreadcrumbList). |
| `local.php` | LocalBusiness structured data: NAP (name/address/phone), geo (lat/lng), opening hours, price range, multi-location — drives the plugin's local module if present, else native JSON-LD via `head.php`. |
| `audit.php` | Site-wide scan: missing/too-long/too-short titles+descriptions, duplicate titles, thin content (<300 words), missing image alt, noindex flags, missing focus keyword, orphan pages → actionable report; + bulk meta apply by rule. |
| `research.php` | `wpultra_seo_keyword_plan` (AI/seed → clusters + LSI/related, cross-referenced vs existing site content → gaps) and `wpultra_seo_competitor_compare` (our page vs client-provided competitor on-page data → gap report). Pure where possible. |

Each ability file in `includes/abilities/` requires the relevant engine module + is wired in `bootstrap-mcp.php`. New `seo` ability category.

## Ability surface (~18)

### Status / setup
- **`seo-status`** *(read)* — `{mode: yoast|rankmath|native, plugin_version, sitemap_enabled, robots_state, schema_state, counts:{posts_missing_title,posts_missing_desc,...}}`. The AI's entry point.
- **`seo-quick-setup`** *(write)* — apply a Google-recommended baseline: enable sitemap, set sensible title templates / separators, enable breadcrumbs + organization/website schema, set noindex on thin archive types, ensure canonical — through the active plugin or native. Idempotent; reports what it changed.

### On-page (per post)
- **`seo-get-meta`** *(read)* — the canonical meta field set for a post (mode-aware).
- **`seo-set-meta`** *(write)* — set title / description / focus_keyword / canonical / robots / OG / Twitter (validated: title ≤60, desc 120–160 warn, etc.); writes via the active driver. Returns `rejected`.
- **`seo-analyze-page`** *(read)* — full on-page score + per-check checklist + prioritized recommendations for a post (optionally against a `focus_keyword`).
- **`seo-optimize-content`** *(read)* — target-keyword-driven suggestions: heading structure, keyword placement/density, related terms to add, length/readability fixes, meta improvements (advisory; does not rewrite content itself).

### Keyword / content research (on-site + AI)
- **`seo-keyword-research`** *(read)* — seed/topic → keyword clusters + LSI/related terms (AI-driven), cross-referenced against existing site content → which already have a targeting page vs **content gaps**. Explicitly no search-volume numbers.
- **`seo-competitor-analysis`** *(read)* — input: our `post_id` + client-fetched competitor on-page data (`{title, headings[], word_count, keywords[], schema_types[]}`); output: a gap report (what the competitor covers that we don't; length/heading/keyword deltas).
- **`seo-content-gap`** *(read)* — across the site, find topics/keywords with no dedicated page (from a provided keyword list or the site's own term graph).

### Internal linking
- **`seo-suggest-internal-links`** *(read)* — for a post, related published posts + suggested anchor text + where to place (paragraph index).
- **`seo-insert-internal-link`** *(write)* — insert a contextual internal link into a post via the Gutenberg engine (anchor text → target post URL at a positional path).
- **`seo-link-audit`** *(read)* — orphan pages (no incoming internal links), broken internal links, over-linked/under-linked pages.

### Technical / global
- **`seo-manage-sitemap`** *(write/read)* — read sitemap state; enable/disable; (Yoast/Rank Math or WP-core).
- **`seo-manage-redirects`** *(write)* — CRUD a redirect map (source → target, 301/302); applied on `template_redirect` (native) or via Rank Math if present; list.
- **`seo-manage-robots`** *(write/read)* — read/append `robots.txt` rules (via `robots_txt` filter store) and per-post robots (noindex/nofollow through `seo-set-meta`).
- **`seo-manage-schema`** *(write)* — per-post structured data type (Article/Product/FAQ/HowTo/BreadcrumbList) + fields → JSON-LD (drive plugin or native `head.php`).

### Bulk / audit
- **`seo-site-audit`** *(read)* — the site-wide scan (above) → categorized issues + counts + the worst offenders, prioritized.
- **`seo-bulk-set-meta`** *(write)* — apply meta by rule across many posts (e.g. a title template `%title% | %sitename%` to all posts missing a title; set noindex on a tag archive). Reports per-post applied/skipped.

### Local SEO
- **`seo-manage-local-business`** *(write/read)* — LocalBusiness: name, type, address (NAP), phone, geo, opening hours, price range, logo, sameAs; single or multi-location → JSON-LD (plugin local module or native).

### Skill
- **`seo-architect`** built-in skill — the ranking loop: status → keyword/intent → on-page (meta + content + schema) → internal links → technical/local → audit → iterate. Encodes the on-site+AI workflow and the plugin-driver/native gotchas. Mirrors `woocommerce-architect`.

## Verified API facts (to confirm live, like prior waves)

- **Yoast meta keys:** `_yoast_wpseo_title`, `_yoast_wpseo_metadesc`, `_yoast_wpseo_focuskw`, `_yoast_wpseo_canonical`, `_yoast_wpseo_meta-robots-noindex` (`1`/`2`), `_yoast_wpseo_meta-robots-nofollow`, `_yoast_wpseo_opengraph-title/-description/-image`, `_yoast_wpseo_twitter-title/-description/-image`, `_yoast_wpseo_bctitle`. Detect: `defined('WPSEO_VERSION')`.
- **Rank Math meta keys:** `rank_math_title`, `rank_math_description`, `rank_math_focus_keyword`, `rank_math_canonical_url`, `rank_math_robots` (array, e.g. `['noindex']`), `rank_math_facebook_title/-description/-image`, `rank_math_twitter_title/-description`. Detect: `class_exists('RankMath\\Helper')` / `defined('RANK_MATH_VERSION')`.
- **Native:** store under `_wpultra_seo_*`; render on `wp_head` (priority 1) + `document_title_parts` / `pre_get_document_title`; gate the whole renderer on "no Yoast and no Rank Math active".
- **WP core sitemap:** `wp-sitemap.xml` (WP 5.5+), toggle via `wp_sitemaps_enabled` filter / `blog_public`. **robots.txt:** `robots_txt` filter. **Redirects:** `template_redirect` hook + a stored option map. **Schema:** JSON-LD `<script type="application/ld+json">` on `wp_head`.

## Bootstrap wiring

- New ability slugs into a `// seo (Wave 7)` group in `wpultra_ability_files()` + a new **`'seo'`** category in `wpultra_ability_category_map()` + `wpultra_register_categories()`.
- `includes/seo/*` added to the engine require loop in `wpultra_load_abilities()` (gated on the `seo` category).
- `head.php` registers its `wp_head`/title hooks at load (native-mode-gated internally).
- `tests/bootstrap.test.php` ability count updated (77 → ~95).
- `seo-architect` skill auto-globbed from `includes/skills/built-in/`.
- Version → **0.11.0** (header, `WPULTRA_VERSION`, readme.txt) at the ship plan; README + changelog updated.

## Safety

- `seo-set-meta` / `seo-bulk-set-meta` validate field lengths/values; writes go through the driver (plugin's sanitization or our coercion). All mutating abilities call `wpultra_audit_log`.
- `seo-manage-redirects` stores a bounded option map; redirect targets sanitized; no open-redirect to off-site unless explicitly given an absolute URL the caller intended.
- Native `head.php` only outputs when no SEO plugin is active (no duplicate tags / conflicts).
- `seo-bulk-set-meta` and `seo-site-audit` paginate; bulk writes report skipped vs applied; destructive-ish bulk ops default to a `dry_run` preview unless `apply:true`.
- Reserved-CPT writes still refused by the underlying engines.

## Testing

- **Install Yoast on the test site** (`wp plugin install wordpress-seo --activate`); live-verify the meta driver + analyze + links against Yoast. **Then install + switch to Rank Math** and re-verify the SAME abilities (proves the driver abstraction). **Then deactivate both** and verify native mode (meta store + `wp_head` output + WP-core sitemap/robots/redirect/JSON-LD).
- **Pure unit (zero-dep harness):** the on-page scorer (keyword-in-title/H1/first-para, density math, length thresholds), the keyword-plan builder, the competitor-compare diff, the redirect-map validator, meta field validation. Plugin-meta + head-output paths are live-tested.
- **Live (token-gated scripts):** set/get meta round-trips under each mode; analyze a real post; suggest + insert an internal link (Gutenberg); a small site audit; quick-setup idempotence; native head output rendered in page source; a redirect 301; LocalBusiness JSON-LD present. Record verified meta keys + hook facts into memory + the skill.

## Out of scope (explicit)

- Real keyword search-volume, keyword difficulty, and live SERP rank tracking (no external/paid API; no GSC OAuth) — heuristic/AI guidance only.
- SEO plugins other than Yoast + Rank Math (AIOSEO/SEOPress could be added later via the same driver seam).
- Automated content writing/rewriting (the AI does that through existing content abilities; SEO abilities advise, they don't ghost-write).
- Backlink building / off-site SEO, social posting.
- Paid Yoast/Rank Math PRO-only features (drive the free-tier surface; note Pro extensions where relevant).

## Success criteria

- From a fresh AI session, the AI can: detect the SEO setup; apply a Google-recommended baseline; set/get on-page meta for any post under Yoast, Rank Math, OR native mode (same abilities, same fields); score a page and get prioritized fixes; research keywords + find content gaps; suggest and insert internal links with proper anchors; run a site-wide audit and bulk-fix meta; manage sitemap/robots/redirects/schema; and configure local-business structured data — **all without leaving the WP site, all degrading gracefully by available plugin.**
- The `seo-architect` skill lets a normal user say "get my site SEO-ready and help it rank" and have the AI follow a reliable on-site loop.
- Every ability live-verified across Yoast / Rank Math / native; verified meta keys + hooks recorded.

## Decomposition (4 plans, ship as v0.11.0)

1. **Foundation + on-page meta** — `setup.php` + `meta.php` driver (yoast/rankmath/native) + `head.php` native renderer + `seo-status`, `seo-get-meta`, `seo-set-meta`, `seo-analyze-page`. (Installs Yoast; verifies driver + native.)
2. **Internal linking + research** — `links.php` + `research.php` + `seo-suggest-internal-links`, `seo-insert-internal-link`, `seo-link-audit`, `seo-keyword-research`, `seo-competitor-analysis`, `seo-content-gap`, `seo-optimize-content`.
3. **Technical + local** — `technical.php` + `local.php` + `seo-manage-sitemap`, `seo-manage-redirects`, `seo-manage-robots`, `seo-manage-schema`, `seo-manage-local-business`. (Installs Rank Math; re-verifies the driver across plugins.)
4. **Bulk/audit + quick-setup + skill + ship** — `audit.php` + `seo-site-audit`, `seo-bulk-set-meta`, `seo-quick-setup` + `seo-architect` skill + v0.11.0 release.
