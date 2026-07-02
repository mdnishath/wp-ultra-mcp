# WP-Ultra-MCP Waves 8–11 — Tier 1→3 gap closure (v0.13.0)

Adds ~46 abilities across 4 waves: Content Core, Site Ops + FSE, Forms + Audits, Ecosystem (Bricks/i18n/Woo-extras/devtools).

## Global Constraints (bind every task)

- PHP 8.0+, `declare(strict_types=1);` + `if (!defined('ABSPATH')) { exit(); }` at top of every file.
- Ability file = `wp-ultra-mcp/includes/abilities/<slug>.php`, one `wp_register_ability('wpultra/<slug>', [...])` call with `label`, `description`, `category`, `input_schema`, `output_schema`, `execute_callback`, `permission_callback => 'wpultra_permission_callback'`. Follow the exact style of `includes/abilities/create-post.php` and `includes/abilities/media-upload.php`.
- Schemas: `'type' => 'object'`, explicit `properties`, `required`, `'additionalProperties' => false`. Enums for fixed choices.
- Success returns `wpultra_ok([...])`; failures return `wpultra_err($code, $message)` (WP_Error). Ability callbacks catch nothing — engine returns WP_Error, callback passes it through.
- Every mutation calls `wpultra_audit_log('<slug>', $summary, $ok)`.
- Destructive/bulk operations gate behind input `confirm: true` (see `execute-wp-query` pattern).
- Engine code lives in `includes/<domain>/…`, split so pure logic (shaping, validation, parsing, name-picking) is testable without WordPress. WP-calling functions are thin.
- Tests: `tests/<name>.test.php`, zero-dependency harness (`require __DIR__ . '/harness.php';`), pure-logic only, stub missing WP functions the way `tests/power-features.test.php` does. Run: `& 'C:\Users\nisha\AppData\Roaming\Local\lightning-services\php-8.2.30+1\bin\win64\php.exe' tests/<name>.test.php`.
- Plugin-adapter domains (forms, i18n, bricks): every ability degrades gracefully when the plugin is absent — return `wpultra_err('<domain>_unavailable', …)` or a status payload saying not installed, never fatal. Mirror `includes/fields/` adapter pattern.
- **DO NOT edit** `includes/bootstrap-mcp.php`, `wp-ultra-mcp.php`, `readme.txt`, or any existing ability file (unless the task explicitly names it). The controller wires registration after each wave.
- DO NOT bump the version; controller bumps to 0.13.0 at the end.
- Text domain `wp-ultra-mcp`; wrap labels/descriptions in `__()`.

## Wave 8 — Content Core (category: content/users/system)

### Task 8A — content read + duplicate (engine `includes/content/engine.php`, test `tests/content-read.test.php`)
- `list-posts` (content): input `post_type` (string, default 'post'; 'any' allowed), `status`, `search`, `meta_key`/`meta_value`, `tax_query` (simplified: `taxonomy`+`terms[]`), `orderby`, `order`, `per_page` (default 20, max 100), `page`. Output: `posts[]` (id, title, slug, status, type, date, modified, author, excerpt trimmed 160 chars, edit link), `total`, `pages`. Use `WP_Query` with `no_found_rows=false`. Never return post_content.
- `get-post` (content): input `id` (req), `fields` enum-array optional (`content`,`meta`,`terms`,`revisions_count`). Output full post incl. raw `post_content` only when requested via fields (default includes content), meta (skip `_`-prefixed unless `include_private_meta:true`), terms grouped by taxonomy.
- `search-content` (content): input `query` (req), `post_types[]`, `per_page`/`page`. LIKE search over title+content via WP_Query `s`; output matches with a ±80-char highlight snippet around first hit (pure fn `wpultra_content_snippet($content, $query, $radius)` — strip tags first; testable).
- `duplicate-post` (content): input `id` (req), `new_status` (default 'draft'), `new_title` optional (default "<title> (Copy)"), `copy_meta` bool default true, `copy_terms` bool default true. Copies Elementor/meta verbatim (`_elementor_data` string-safe: use `wp_slash` on meta values when re-inserting). Output new post id, title, status, edit link.

### Task 8B — structure: terms, CPT/taxonomy registration, menus (engine `includes/content/structure.php`, test `tests/content-structure.test.php`)
- `manage-term` (content): input `action` enum(list|create|update|delete), `taxonomy` (req), `term_id`, `name`, `slug`, `parent`, `description`, `meta` object, `confirm` (req for delete). list supports `search`, `hide_empty` (default false). Output per action; list returns terms with id/name/slug/parent/count.
- `register-cpt` (content): input `slug` (req, validated with `wpultra_is_valid_identifier`, reject `wpultra_reserved_post_types()`), `singular`/`plural` (req), `public` bool default true, `supports[]` (default title,editor,thumbnail), `has_archive`, `hierarchical`, `menu_icon`, `taxonomies[]`, `show_in_rest` forced true. Persists definitions to option `wpultra_registered_cpts` (assoc slug=>args) and registers on `init` via loader the controller wires. Pure fn `wpultra_structure_build_cpt_args(array $in): array` testable.
- `register-taxonomy` (content): same shape — option `wpultra_registered_taxonomies`, args builder pure fn, `object_types[]` (req).
- `manage-menu` (content): input `action` enum(list-menus|get|create-menu|delete-menu|add-item|update-item|remove-item|assign-location), `menu` (id or name), `item_id`, `item` object (`title`, `url`, `object_id`+`object_type` for post/term links, `parent_item`, `position`), `location`, `confirm` for deletes. Uses `wp_get_nav_menus`, `wp_update_nav_menu_item`, `wp_get_nav_menu_items`, theme locations via `get_theme_mod('nav_menu_locations')`. get returns nested tree (pure tree-builder fn from flat items — testable).

### Task 8C — media mgmt, comments, options, users-list (engines: extend `includes/media/engine.php` (add list fn only), new `includes/content/comments.php`, new `includes/system/options.php`; extend `includes/users/engine.php` (add list fn only); abilities + test `tests/content-manage.test.php`)
- `media-list` (content): input `search`, `mime` (e.g. 'image'), `unattached` bool, `per_page`/`page`. Output items via existing `wpultra_media_shape()` + `total`. Engine fn `wpultra_media_list(array $q)`.
- `media-get` (content): input `id` → `wpultra_media_shape()` + width/height/filesize + attached_to.
- `media-update` (content): input `id`, `alt`/`title`/`caption`/`description`, `attach_to_post` — wraps existing `wpultra_media_update` (extend for reattach).
- `media-delete` (content): input `id`, `force` bool default false, `confirm` req — wraps existing `wpultra_media_delete`.
- `manage-comment` (content): input `action` enum(list|get|approve|unapprove|spam|trash|delete|reply|update), `comment_id`, `post_id`, `status` filter, `content` (for reply/update), `per_page`/`page`, `confirm` for delete. Engine `includes/content/comments.php`, shape fn pure-testable.
- `option-get` (system): input `name` (req). Deny secrets: block names matching pure fn `wpultra_option_is_sensitive($name)` (auth keys/salts, `*_secret*`, `*password*`, `*_key` — case-insensitive, testable). Output value (JSON-safe), `autoload`.
- `option-set` (system): input `name` (req), `value` (any JSON), `confirm` req when overwriting existing. Same sensitive-name deny + deny `wpultra_`-prefixed critical options (`wpultra_enabled`, `wpultra_ability_rules`, `wpultra_disabled_categories`) to prevent self-lockout. Audit-log old→new summary.
- `list-users` (users): input `role`, `search`, `per_page`/`page`. Output id, login, email, display_name, roles, registered, post_count. Engine fn `wpultra_users_list()` in users/engine.php; shape fn pure.

### Task 8D — site-snapshot (engine `includes/system/snapshot.php`, test `tests/site-snapshot.test.php`)
- `site-snapshot` (system): no required input; optional `include[]` enum-array (`plugins`,`themes`,`content`,`users`,`menus`,`elementor`,`woocommerce`,`seo`,`fields`) default all. One call returns: site (name, url, wp_version, php_version, locale, timezone, permalink structure, is_multisite), active theme (+parent), plugins (active w/ version; count inactive), content summary (per public post_type counts via `wp_count_posts`, taxonomy term counts), users per role, menus + locations, detected page builders/SEO/field/form plugins (reuse detection helpers where present; else `class_exists`/`defined` checks in pure-testable map fn `wpultra_snapshot_detect(array $probes): array`). Compact — no post lists. This is the AI's first-call orientation tool.

## Wave 9 — Site Ops + FSE

### Task 9A — site ops (engine `includes/system/siteops.php`, test `tests/siteops.test.php`)
- `export-content` (system): input `post_types[]` default all public, `status[]`, `path` optional (default `wp-content/uploads/wpultra-exports/export-<date>.xml`, path resolved+jailed via `wpultra_resolve_path`). Uses WP's `export_wp()` (load `wp-admin/includes/export.php`, capture via ob). Output file path + size + counts.
- `import-content` (system): input `path` (req, jailed), `confirm` req. If `WP_Import` class unavailable, return `importer_unavailable` error advising wordpress-importer plugin; else run. Output imported counts.
- `manage-cron` (system): input `action` enum(list|run|delete), `hook`, `timestamp`, `confirm` for delete. list returns events (hook, next_run ISO, schedule, args). run uses `do_action_ref_array` on the hook args after removing… simplest: `wp_schedule_single_event(time()-1,…)` + `spawn_cron()`. Pure shape fn for `_get_cron_array()` structure — testable with fixture array.
- `search-replace` (database): input `search` (req), `replace` (req), `tables[]` optional (default posts+postmeta+options), `dry_run` bool default true, `confirm` req when `dry_run:false`. Serialized-data-safe: recursive pure fn `wpultra_sr_replace_value($value, $search, $replace, &$count)` handling nested arrays/objects from `maybe_unserialize` (testable). Iterates rows in batches of 500 via $wpdb. Output per-table match/replace counts.
- `maintenance-mode` (system): input `action` enum(status|enable|disable), `message` optional. Writes/removes `.maintenance` file in ABSPATH (with `<?php $upgrading = time();` — note WP auto-expires after 10 min; also store custom message in option used by a `wp_maintenance`-compatible drop-in? Keep simple: .maintenance file only, document 10-min auto-expiry in description; `enable` supports `persistent:true` which rewrites timestamp far-future — actually WP compares `time() - $upgrading > 600`, so persistent mode writes `$upgrading = time() + 10*YEAR_IN_SECONDS`… that breaks the comparison the other way (still > 600? no: time() - future = negative < 600 → maintenance stays ON). Use that.) Output current status.
- `site-health` (diagnostics): runs WP core `WP_Site_Health` tests that are sync (`get_tests()['direct']`), collecting status/label per test + debug info summary from `WP_Debug_Data` (sizes skipped). Graceful load from `wp-admin/includes/class-wp-site-health.php`. Output `tests[]` (slug, status good|recommended|critical, label), `critical_count`.
- `db-snapshot` (database): input `action` enum(create|list|restore|delete), `snapshot` (name for restore/delete), `tables[]` optional (default: all tables with $wpdb->prefix), `confirm` req for restore/delete. create: dump via $wpdb (SHOW CREATE TABLE + batched INSERTs) to gzip file under `wp-content/uploads/wpultra-snapshots/<name>.sql.gz` (dir protected with index.php + .htaccess deny). restore: execute statements via `dbDelta`-free raw `$wpdb->query` splitting on `;\n` (pure splitter fn testable, must respect quoted strings). Output name/path/size/tables.

### Task 9B — FSE / block-theme design (engine `includes/fse/engine.php`, new category `fse`, test `tests/fse.test.php`)
- `theme-json-get` (fse): output merged global styles/settings via `WP_Theme_JSON_Resolver::get_merged_data()->get_raw_data()` plus `user` layer separately; input `layer` enum(merged|theme|user) default merged. If resolver class missing → `fse_unavailable`.
- `theme-json-set` (fse): input `settings` object and/or `styles` object, `merge` bool default true. Writes the user layer via the `wp_global_styles` CPT (`WP_Theme_JSON_Resolver::get_user_global_styles_post_id()`), deep-merging over existing user data (pure deep-merge fn — reuse pattern from elementor engine if present, else write `wpultra_fse_deep_merge` — testable). Clears `wp_get_global_*` caches (`wp_theme_json_get_cache`… use `WP_Theme_JSON_Resolver::clean_cached_data()`).
- `manage-template` (fse): input `action` enum(list|get|upsert|delete|reset), `type` enum(wp_template|wp_template_part) default wp_template, `slug`, `content` (block markup), `title`, `confirm` for delete/reset. Uses `get_block_templates()`, `wp_template` CPT writes for customization; block-theme check first (`wp_is_block_theme()` → else `fse_unavailable`). list output: slug, title, source (theme|custom), modified.
- `custom-css` (fse): input `action` enum(get|set|append), `css` (for set/append). Uses `wp_get_custom_css_post()`/`wp_update_custom_css_post()` (works on classic + block themes). Output css + length.

## Wave 10 — Forms + Audits

### Task 10A — forms adapter domain (files `includes/forms/setup.php`, `includes/forms/adapters/{cf7,wpforms,gravity,fluent}.php`, new category `forms`, test `tests/forms.test.php`)
Adapter contract (mirror `includes/fields/`): `wpultra_forms_detect(): array` (plugin => version|null), driver resolution order cf7→wpforms→gravity→fluent or explicit `plugin` input.
- `form-status` (forms): detected plugins, per-plugin form counts.
- `form-list` (forms): input `plugin` optional. Output forms: id, title, plugin, shortcode string, entries_count (null where plugin stores none — CF7 has no entries without Flamingo; report `entries_supported` bool. CF7+Flamingo detection included).
- `form-get-entries` (forms): input `form_id` (req), `plugin`, `per_page`/`page`, `search`. WPForms (wpforms_entries table, only if Pro), Gravity (GFAPI::get_entries), Fluent (fluentform_submissions), CF7 via Flamingo posts. Output entries with fields flattened (pure flattener fn per adapter shape — testable with fixture arrays).
- `form-create` (forms): input `plugin` (req), `title` (req), `fields[]` (unified: `type` enum(text|email|textarea|select|checkbox|radio|number|date|file), `label`, `required`, `options[]`). Each adapter maps unified fields → native format (CF7 form-tag markup string, WPForms/Fluent JSON structures, Gravity GFAPI::add_form). Pure mapper fns per adapter (`wpultra_forms_cf7_markup(array $fields): string` etc.) — the test file's core. Unsupported plugin combos → clear error.

### Task 10B — audits (engine `includes/system/audits.php`, test `tests/audits.test.php`)
- `security-audit` (diagnostics): checks (each: id, status pass|warn|fail, detail): core version vs latest (`get_core_updates`), file editing disabled (DISALLOW_FILE_EDIT), debug display off in production, users named 'admin', admin count, weak table prefix (wp_), SSL on, directory listing sentinel (uploads index.php exists), xmlrpc reachable (option-based heuristics only, no HTTP), plugin/theme updates pending count, inactive plugins count, salts defined + not placeholder. Pure rule-evaluator fn taking a context array → findings (testable).
- `performance-audit` (diagnostics): autoloaded options total bytes + top 10 largest, transient count + expired count, posts/postmeta row counts, revisions count (suggest limit), attachment count vs files, active plugin count, object cache present (`wp_using_ext_object_cache`), page-cache plugin detected (map probe), cron overdue events. Same pure rule-evaluator pattern. Output findings + `score` 0-100 (pure scoring fn — testable).

## Wave 11 — Ecosystem

### Task 11A — Bricks builder foundation (engine `includes/bricks/engine.php`, new category `bricks`, test `tests/bricks.test.php`)
Bricks stores page content in postmeta `_bricks_page_content_2` (array of elements: id, name, parent, children[], settings). Theme check: `defined('BRICKS_VERSION')` or template `bricks` active.
- `bricks-status` (bricks): installed/active, version, per-post-type enabled list (option `bricks_global_settings` → postTypes).
- `bricks-list-elements` (bricks): registered element names via `\Bricks\Elements::$elements` keys + label/category when available; graceful empty when class missing.
- `bricks-get-content` (bricks): input `post_id`, optional `element_id` drill-down. Output compact tree (pure tree-builder from flat array — testable; mirrors elementor-get-content UX).
- `bricks-set-content` (bricks): input `post_id`, `elements[]` (flat array, validated: every element has id/name; parents referenced exist — pure validator fn testable), `confirm` req. Writes meta + `_bricks_editor_mode = 'bricks'`, clears Bricks cache if class exists.

### Task 11B — multilingual adapter (engine `includes/i18n/engine.php`, new category `multilingual`, test `tests/i18n.test.php`)
Detect WPML (`ICL_SITEPRESS_VERSION`) / Polylang (`function pll_languages_list`).
- `translation-status` (multilingual): active plugin, languages (code, name, default flag), per-post-type translated/untranslated counts (Polylang: `pll_count_posts`-based; WPML: query `icl_translations` — keep simple counts).
- `duplicate-to-language` (multilingual): input `post_id` (req), `target_lang` (req), `overwrite` bool default false. Polylang: create copy post, `pll_set_post_language`, `pll_save_post_translations`. WPML: use `wpml_element_type` filters + duplicate via core copy + `SitePress` API if available, else `i18n_unavailable`-style graceful error naming what's missing. Copy meta with wp_slash (Elementor-safe like duplicate-post). Pure fn: language-code validation against provided list.

### Task 11C — Woo extras (files `includes/woocommerce/shipping.php` (new), abilities, test `tests/woo-extras.test.php`)
Follow existing woo ability style (see `includes/abilities/woo-get-settings.php`, guard `wpultra_woo_available()` if helper exists in includes/woocommerce/setup.php — reuse it).
- `woo-manage-shipping-zone` (woocommerce): `action` enum(list|get|create|update|delete|add-method|update-method|remove-method), zones via `WC_Shipping_Zones`; methods flat_rate|free_shipping|local_pickup with `settings` object (title, cost, min_amount). `confirm` for deletes.
- `woo-manage-tax-rate` (woocommerce): `action` enum(list|create|update|delete), fields country, state, postcode, city, rate, name, class, priority, compound, shipping; via `WC_Tax` CRUD. `confirm` for delete.
- `woo-manage-payment-gateway` (woocommerce): `action` enum(list|get|enable|disable|update-settings), gateway id, `settings` object (title, description + gateway-specific passthrough). Via `WC()->payment_gateways()->payment_gateways()` and each gateway's `update_option`. Never expose secret-looking setting values (reuse/replicate sensitive-name pure fn — import from system/options.php if wired, else local copy `wpultra_woo_setting_is_sensitive` — testable).

### Task 11D — devtools (engine `includes/system/devtools.php`, test `tests/devtools.test.php`)
- `send-email` (system): input `to` (req, email-validated), `subject` (req), `body` (req), `html` bool default false. `wp_mail` + capture `wp_mail_failed` hook for error detail. Output sent bool + mailer info (SMTP plugin detected via probe map).
- `render-page` (diagnostics): input `url` or `post_id` (one req), `checks[]` optional. Server-side `wp_remote_get` of the permalink (sslverify false for local), report: http status, title tag, h1 count, fatal-error markers ("Fatal error", "There has been a critical error"), body length, load ms. Pure HTML-probe fns (`wpultra_devtools_extract_title`, `wpultra_devtools_count_tag` — regex-based, testable). Generic sibling of elementor-render-check.
- `list-registry` (diagnostics): input `what` enum(post-types|taxonomies|shortcodes|roles|hooks|image-sizes|rest-routes), `hook` (when what=hooks, req: list callbacks attached to that hook with priority). Output arrays of compact descriptors. Pure shape fns testable.
- `purge-cache` (system): input `scope` enum(all|page-cache|object-cache|elementor) default all. Unified: `wp_cache_flush()`, known plugin purges via probe map (WP Rocket `rocket_clean_domain`, LiteSpeed `do_action('litespeed_purge_all')`, W3TC `w3tc_flush_all`, WP Super Cache `wp_cache_clear_cache`, SG/Breeze/Autoptimize equivalents — call only when function/action exists), Elementor CSS cache clear. Output which layers were purged/skipped.

## Controller wiring (after each wave — NOT subagent work)

1. Append slugs to `wpultra_ability_files()` + `wpultra_ability_category_map()` (+ new categories in `wpultra_register_categories()`: fse, forms, bricks, multilingual).
2. Add engine loader blocks in `wpultra_load_abilities()` (and frontend loader if a domain needs always-on hooks — Wave 8B CPT/taxonomy re-registration on `init` needs one: load structure.php on plugins_loaded and hook `init` to register persisted CPTs/taxonomies).
3. Run full test suite `tests/run-all.ps1`; fix; commit per wave.
4. End: bump 0.12.0→0.13.0 (plugin header + WPULTRA_VERSION + readme.txt), update README shipped-list, rebuild zip via `wp-ultra-mcp/bin/build-zip.ps1`, deploy via `bin/deploy.ps1`, final whole-branch review.
