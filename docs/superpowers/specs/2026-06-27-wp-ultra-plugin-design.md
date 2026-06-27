# WP-Ultra-MCP (WordPress Plugin) — Design Spec

**Date:** 2026-06-27
**Status:** Awaiting approval
**Supersedes the transport model of:** the Node/stdio `wp-ultimate-mcp` server (kept in-repo as an optional local-only mode; the plugin is the product).
**Goal:** A free, open-source WordPress **plugin** that turns any WordPress site into an MCP server an AI CLI (Claude Code, Gemini CLI, Antigravity) can control over HTTP — install, connect, done. It matches Novamira's capabilities and beats it with deep, server-side **Elementor** control, **raw SQL**, and **self-healing**.

---

## 1. Why a plugin (not the Node server)

The goal is "control/build any WordPress ecosystem." Novamira proves the right shape: a plugin that runs **inside** WordPress and exposes an MCP-over-HTTP endpoint.

- Works on **any host** — local, remote, managed shared hosting — no DB credentials, no `WP_ROOT_PATH`, no same-machine requirement.
- Runs in WP context → already has DB, filesystem, WP-CLI, and every WP/plugin PHP API, gated by WordPress's own auth.
- One-click install + a "Connect" page that hands the AI client a ready config.

The Node server only worked for local dev with direct DB access. It is archived in-repo (`/node-server-archive` reference) for a possible "local power mode" but is not the product.

---

## 2. Architecture — stand on the official adapter

We **do not implement the MCP protocol or transport**. We bundle the same official stack Novamira uses and contribute only abilities + UI + engines.

```
AI CLI (Claude / Gemini / Antigravity)
   │  HTTP (MCP, JSON-RPC over POST)  +  Application-Password Basic Auth
   ▼
https://site.com/wp-json/mcp/wpultra
   │
[ wordpress/mcp-adapter  (WP\MCP\Core\McpAdapter, HttpTransport) ]   ← bundled dep, unmodified
   │  auto-exposes every public ability as MCP tool / resource / prompt
   ▼
WordPress Abilities API  (wp_register_ability)
   │
   ├── Filesystem abilities      (read/write/edit/delete/list, path-jailed)
   ├── Code abilities            (run-wp-cli, execute-php)
   ├── Database ability          (execute-wp-query — parameterized)   ← Novamira lacks
   ├── Diagnostics ability       (read-debug-log)                     ← self-healing
   ├── Elementor engine (server-side)  (get/set/patch layout + schema)← KILLER, FREE
   ├── Gutenberg abilities       (get/write dynamic-block content)    ← parity
   └── Skills system             (markdown skills as CPT → MCP prompts)
```

**Dependencies (bundled via Composer, Jetpack-autoloaded):**
- `wordpress/mcp-adapter` (^0.5) — MCP protocol, transport, tools/resources/prompts from abilities.
- `wordpress/php-mcp-schema` (^0.1) — MCP DTOs (transitive).
- `automattic/jetpack-autoloader` (^5) — version-safe autoload so we don't clash with another plugin bundling the adapter.

Plugin slug/brand placeholder: **`wpultra`** (server route `/wp-json/mcp/wpultra`). Final name TBD with user; spec uses `wpultra` / `WP-Ultra-MCP`.

---

## 3. Bootstrap & MCP server registration

Mirror Novamira's proven sequence:

1. `require` the bundled `vendor/autoload_packages.php`; if `WP\MCP\Core\McpAdapter` is missing, set a dependency `WP_Error`, show an admin notice, and register a stub `/wp-json/mcp/wpultra` route returning 500 with the reason.
2. Gate everything on an **enabled flag** + **domain lock** (`wpultra_enabled` option = '1' AND `wpultra_domain` host matches) — auto-deactivates if the site is cloned to a new domain.
3. `add_filter('mcp_adapter_default_server_config', …)` → rename the default server to id/route/name `wpultra`.
4. `McpAdapter::instance()` on init; the adapter creates the server on `rest_api_init` and exposes the three meta-tools (`discover-abilities`, `get-ability-info`, `execute-ability`) plus all public abilities.
5. Register ability **categories** on `wp_abilities_api_categories_init`; register **abilities** on `wp_abilities_api_init` (only when enabled); apply a per-ability **enable/disable policy** at `PHP_INT_MAX`.

---

## 4. Security model — full power, WordPress-gated

| Concern | Policy |
|---|---|
| Who can call abilities | `permission_callback` = enabled-flag AND `current_user_can('manage_options')` (single-site) / `is_super_admin()` (multisite). The MCP request is authenticated by WordPress Application Passwords before any callback runs. |
| File operations | Jailed under a filterable base dir (default `ABSPATH`) via `realpath` + base-prefix check; final-path symlink writes rejected; `.php`/`.htaccess`/`ini`-class writes confined to a sandbox dir (`wp-content/wpultra-sandbox/`); core dirs (`wp-admin`, `wp-includes`, `mu-plugins`) protected from delete. |
| SQL | **Always parameterized** (`$wpdb->prepare` with placeholders). Identifiers validated `^[A-Za-z0-9_]+$`. Destructive verbs (`DROP`/`TRUNCATE`/no-WHERE `DELETE`/`UPDATE`) require an explicit `confirm:true` arg. |
| WP-CLI | `proc_open` with an **argument array** (no shell string), cwd `ABSPATH`, timeout. |
| execute-php | `eval` inside a `set_time_limit` + error-handler + output-buffer + `try/catch Throwable` wrapper, returning structured error/class on failure. (Same trust model as Novamira: gated to `manage_options`.) |

Every ability returns either a structured array (matching its `output_schema`) or a `WP_Error`; the adapter turns `WP_Error`/`{success:false}` into a proper MCP `isError` with structured repair hints appended.

---

## 5. Abilities (the product surface)

All FREE. Namespace `wpultra/…`. Each: JSON-Schema `input_schema`, `output_schema`, `execute_callback`, shared `wpultra_permission_callback`, `meta.mcp.public=true`, `annotations`.

**Filesystem** (`category: filesystem`)
1. `wpultra/read-file` — read a file (jailed), size-capped.
2. `wpultra/write-file` — atomic write (tmp+rename), recursive mkdir, append option; sandbox rules for executable files.
3. `wpultra/edit-file` — `old_string`/`new_string` surgical replace.
4. `wpultra/delete-file` — delete with protected-path guard.
5. `wpultra/list-directory` — list with limit + metadata.

**Code & system** (`category: code-execution`)
6. `wpultra/run-wp-cli` — `proc_open` array, cwd ABSPATH, timeout; returns `{success,exit_code,stdout,stderr}`.
7. `wpultra/execute-php` — sandboxed `eval`, returns value/output/errors.

**Database** (`category: database`) — *Novamira has nothing here*
8. `wpultra/execute-wp-query` — parameterized SQL via `$wpdb`; `{sql, params?, confirm?}`; SELECT→rows (capped), writes→affected/insertId; destructive gated by `confirm`.

**Diagnostics** (`category: diagnostics`)
9. `wpultra/read-debug-log` — tail last N lines of `WP_CONTENT_DIR/debug.log` (or `WP_DEBUG_LOG` path); helpful message if absent. Enables the self-healing loop.

**Elementor engine** (`category: elementor`) — *the killer, server-side, no browser*
10. `wpultra/elementor-get-layout` — return parsed `_elementor_data` for a post (compact tree: id/elType/widgetType/children counts).
11. `wpultra/elementor-set-layout` — validate + write a full `_elementor_data` array; set `_elementor_edit_mode='builder'`, bump `_elementor_version`, clear `_elementor_css`. Writes via Elementor's document API when available (`\Elementor\Plugin::$instance->documents->get($id)->save(['elements'=>…])`), else direct meta upsert. Optimistic-lock on a content hash.
12. `wpultra/elementor-patch-element` — surgical ops without replacing the page: `insert-container`, `update-settings`, `delete-element`, `reorder` (targets by element `id`). This is the "richer operations" Novamira's Gutenberg engine punted on.
13. Resource `wpultra/elementor-schema` (`meta.mcp.type=resource`) — the atomic container/widget JSON blueprint (anti-hallucination contract).

**Gutenberg** (`category: gutenberg`) — parity, lean
14. `wpultra/gutenberg-get-content` — `parse_blocks` → compact block tree.
15. `wpultra/gutenberg-write-content` — write a block spec; **fast server-side path for dynamic/`save:null` blocks** (serialize via `serialize_blocks`). For static blocks that need JS-`save()` validation, return a clear error telling the agent which blocks can't be written server-side (we deliberately do **not** ship Novamira's browser-tab finalizer in v1 — documented limitation, revisit later). Elementor is the recommended builder path.

**Skills** (`category: skills`) — copy Novamira's clean pattern
16–19. `skill-get` / `skill-write` / `skill-edit` / `skill-delete` — markdown skills (frontmatter `name/description/enable_prompt/enable_agentic`) stored as a private `wpultra_skill` CPT; agentic catalog prepended to discover-abilities instructions; per-skill MCP **prompt** registration for `enable_prompt` skills.
- Ship a built-in **`elementor-architect`** skill (the layout playbook) and a **`self-healing`** skill (read debug.log → fix → retry).

---

## 6. Admin UI — install & connect

Three-step admin page (mirror Novamira's connect UX):
1. **Enable** — toggle "Turn on AI control for this site" (writes `wpultra_enabled` + `wpultra_domain`).
2. **Generate credential** — create a WordPress **Application Password** named "WP-Ultra-MCP" (shown once).
3. **Connect** — render copy-paste client configs for Claude Code / Gemini CLI / Cursor, both the `@automattic/mcp-wordpress-remote` npx form and the direct `"type":"http"` form, with the live endpoint URL + credentials filled in.

Plus an **Abilities** admin tab to enable/disable individual abilities (policy stored in an option, applied at `PHP_INT_MAX`).

---

## 7. Packaging & distribution

- **Composer** project; `composer install` vendors the adapter + autoloader; build script produces a **release ZIP with `vendor/` bundled** (source ZIP intentionally won't run, with a clear activation error — same as Novamira).
- Plugin header (Name, Version, License GPL-2.0-or-later, Requires PHP 8.0, Requires WP 6.6 for Abilities API).
- Optional self-hosted updater (hook `site_transient_update_plugins`) — **deferred**; v1 ships as a manual-install ZIP + GitHub release.
- Repo layout:
```
wp-ultra-mcp/                     (plugin root, shipped)
  wp-ultra-mcp.php                main plugin file (bootstrap)
  composer.json
  includes/
    helpers.php                   path-jail, capability, response shaping
    bootstrap-mcp.php             adapter init + server config + categories
    admin/connect-page.php        3-step connect UI
    admin/abilities-page.php      enable/disable policy UI
    abilities/                    one file per ability (read-file.php, …, elementor-*.php, …)
    elementor/engine.php          server-side _elementor_data read/write/patch
    gutenberg/content.php         parse/serialize blocks
    skills/                       cpt.php, parser.php, sources.php, catalog.php, prompts.php, abilities/
  assets/                         admin css/js
  build/                          release-zip script
docs/…                            specs + plans
```

---

## 8. Testing strategy

- **PHP unit tests** (Brain Monkey / WP-Mock or a lightweight harness) for pure logic: path-jail traversal cases, SQL classifier + `prepare` shaping, Elementor layout validate/patch transforms, block spec → `serialize_blocks`, skill frontmatter parser.
- **Integration** against a local WP (wp-env / Docker): activate plugin, enable, hit `/wp-json/mcp/wpultra` with an Application Password, run `discover-abilities` → `execute-ability` for a SELECT and an Elementor set-layout; assert the post renders.
- A scripted **MCP smoke** using the npx remote bridge to confirm an end-to-end tool call.

---

## 9. Differentiators vs Novamira (summary)

| | Novamira | WP-Ultra-MCP |
|---|---|---|
| Elementor | PRO-gated, shallow | **FREE, deep, server-side** (get/set/patch + schema) |
| Page-build reliability | Gutenberg needs a human-open browser tab | Elementor writes **100% server-side** |
| Raw SQL | ✗ | ✓ parameterized `execute-wp-query` |
| Self-healing | implicit | first-class `read-debug-log` + built-in skill |
| Surgical edits | replace-content only | `elementor-patch-element` (insert/update/delete/reorder) |
| Cost | free core, paid builders | **all free** |

---

## 10. Open questions / decisions

1. **Final plugin name + slug** (spec uses `WP-Ultra-MCP` / `wpultra`). — needs user pick.
2. **Gutenberg static-block finalizer**: v1 ships server-side dynamic-block writes only; the browser-tab finalizer for static blocks is **out of scope for v1** (documented limitation). Confirm acceptable.
3. **execute-php**: ship in v1 (same trust model as Novamira) vs defer. Spec includes it; behind the same `manage_options` gate.
4. **Adapter availability**: confirm `wordpress/mcp-adapter` ^0.5 is installable via Composer/Packagist in the plan's first task (fallback: vendor the files directly from Novamira's bundle).
