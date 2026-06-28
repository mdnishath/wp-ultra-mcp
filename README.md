# WP-Ultra-MCP

**Free, open-source.** Turn any WordPress site into a [Model Context Protocol](https://modelcontextprotocol.io) server so AI clients (Claude Code, Claude Desktop, Cursor, Gemini CLI) can build and control the whole site — files, SQL, WP-CLI, PHP, content, Elementor, and more — directly, with no relay service in the middle.

Install the plugin, flip a toggle, paste a config into your AI client. That's it. Your data never leaves your server.

> Inspired by what closed/paid tools like Novamira do — rebuilt as a free plugin, and pushed further with **declarative custom abilities** (no plugin code needed), management **Hubs**, and a crash-recovery **sandbox**.

---

## ✨ The headline: extend the AI's toolset without writing code

Every other tool makes you write a PHP plugin to add a capability. WP-Ultra-MCP lets you (or the AI itself) define a new **ability** from a tiny `.md`/JSON recipe — uploaded in the **Ability Hub** or created over MCP — and it instantly becomes a real tool the AI can call:

```markdown
---
name: woo-empty-cart
description: Empty a WooCommerce customer's cart
category: custom
run: wp-cli
---
​```json
{ "input": { "user_id": { "type": "integer", "required": true } },
  "command": ["wc", "cart", "empty", "--user={user_id}"] }
​```
```

Recipe run types: `wp-cli` · `sql` (parameter-bound) · `php` (sandboxed) · `http`. The AI can even mint its own abilities via the `ability-write` tool. This is the compounding advantage: an ever-growing library of skills + abilities covering the whole WordPress ecosystem.

---

## Why WP-Ultra-MCP

| | WP-Ultra-MCP | Closed/paid alternatives |
|---|---|---|
| **Cost** | Free, GPL open-source | Paid, gated features |
| **Custom abilities** | Declarative `.md`/JSON — no code | Write a PHP plugin |
| **Data ownership** | Your server, your DB | May transit a 3rd-party service |
| **Hubs** | Ability / Skill / Memory Hubs in wp-admin | Limited |
| **Sandbox safety** | Crash-recovery safe-mode keeps the site up | Varies |
| **Elementor** | Schema-driven, server-side (shipped Wave 2) | Often paid-only |

---

## Shipped now

### Wave 1 — Core abilities
- **Files:** `read-file` · `write-file` · `edit-file` · `delete-file` · `list-directory` — jailed to the WP root, executable files sandboxed
- **Code:** `run-wp-cli` (arg-array, no shell injection) · `execute-php` (sandboxed eval)
- **Database:** `execute-wp-query` — parameterized SQL; destructive queries gated behind `confirm: true`
- **Diagnostics:** `read-debug-log`
- **Memory:** `memory-save` · `memory-get` · `memory-list` · `memory-delete` — persistent across sessions
- **Content:** `create-post` · `update-post` · `delete-post` (+ meta, taxonomy terms, featured image)
- **Skills:** `skill-get` · `skill-write` · `skill-edit` · `skill-delete` — reusable markdown prompt docs

### Wave 1.5 — Hubs, declarative abilities & sandbox
- **Declarative ability engine** — `.md`/JSON recipes become real MCP abilities at runtime (`run: wp-cli|sql|php|http`)
- **Ability Hub** — create / upload / edit / delete custom abilities; **`ability-write` / `ability-get` / `ability-delete`** so the AI can manage its own tools
- **Skill Hub** — upload / edit / export `.md` skills, per-skill prompt + agentic toggles, read-only built-ins
- **Memory Hub** — view / add / edit / delete persistent memories
- **Sandbox safe-mode** — if AI-written PHP triggers a fatal, a sentinel suspends it and keeps the site up, with a one-click recovery
- **Connect page** — managed Application-Password list + revoke, and per-client setup tabs (Claude Desktop / Claude Code / Cursor / Gemini / generic HTTP)

### Wave 2 — Elementor (shipped)

Schema-driven Elementor **v4 atomic** layout control. Requires Elementor (free or Pro) with the `e_atomic_elements` experiment enabled.

- **`elementor-list-widgets`** — list all registered Elementor widgets; pass `atomic_only:true` to filter to v4 atomic widgets only (e-heading, e-button, e-image, e-paragraph, e-divider, e-flexbox, e-div-block, …)
- **`elementor-get-widget-schema`** — introspect a widget's full prop schema: each prop's `$$type`, allowed `enum` values, and `default`; use this before setting any widget to avoid guessing
- **`elementor-get-style-schema`** — introspect the style schema for a widget or container (CSS custom-properties, layout, spacing, typography tokens)
- **`elementor-get-content`** — read a page's Elementor data as a compact element tree; pass `element_id` to drill into one node's full settings
- **`elementor-set-content`** — replace a page's entire Elementor data array (atomic-safe write that bypasses Document::save, which would strip atomic widgets; clears the CSS cache)
- **`elementor-add-element`** — insert a new element (container or widget) at a given parent and position; plain scalar settings are auto-wrapped into the `{$$type,value}` form and validated by Elementor's own Props_Parser
- **`elementor-edit-element`** — deep-merge new settings into an existing element without touching sibling props
- **`elementor-delete-element`** — remove an element (and its subtree) from the page
- **`elementor-move-element`** — relocate an element to a new parent and/or position within the tree

Built-in skill **`elementor-v4-architect`** is pre-loaded and teaches the AI the step-by-step atomic workflow: introspect → build → position → read back.

### Wave 3 — Elementor design systems (shipped)

Site-wide design control for Elementor v4. Requires Elementor (free or Pro).

- **`elementor-get-design-system`** — read the active kit's global colors, global typography presets, and design-token variables in one call; use this to understand the current brand palette before making changes
- **`elementor-manage-global-colors`** — set or add brand colors to the kit (e.g. `{colors:[{title:"Brand",color:"#0055FF"}], target:"custom"}`); each color becomes a `--e-global-color-<id>` CSS custom property applied site-wide across all pages
- **`elementor-manage-variables`** — list or create Elementor v4 design-token variables (color, font, size types); reference a variable inside any widget or style prop with the shape `{ "$$type":"global-color-variable", "value":"e-gv-<id>" }` so widgets stay in sync when the token value changes
- **`elementor-list-dynamic-tags`** — list all registered dynamic-tag groups and tags; bind any widget prop to live data with `{ "$$type":"dynamic", "value":{ "name":"post-title", "group":"post", "settings":{} } }` — ACF, JetEngine, and other field-plugin tags appear here when those plugins are active

The built-in **`elementor-v4-architect`** skill is extended with a "Design systems (site-wide)" section that teaches the AI the variable-reference and dynamic-tag binding shapes.

### Wave 3.5 — Global classes & interactions (shipped)

Reusable CSS classes and entrance animations for Elementor v4 elements. Requires Elementor (free or Pro); global classes require the `e_classes` experiment enabled (the `elementor-upsert-global-class` ability can enable it automatically by passing `enable:true`).

- **`elementor-list-global-classes`** — list all existing global classes in the active kit
- **`elementor-upsert-global-class`** — create or update a reusable style class; `props` are atomic CSS props (e.g. `{ "color":{"$$type":"color","value":"#fff"} }`); returns an `e-gc-…` id usable across any page
- **`elementor-apply-class`** — add or remove a global class id on any element (`{post_id, element_id, class_id}`; pass `remove:true` to detach); changes take effect site-wide wherever the class is applied
- **`elementor-set-interaction`** — attach an entrance animation to any element (`{post_id, element_id, trigger:"scrollIn", effect:"fade"|"slide"|"scale", type:"in", duration:600}`); uses Elementor's native interactions system

The built-in **`elementor-v4-architect`** skill is extended with a "Reusable classes & animations" section that teaches the AI the class-creation, application, and interaction-setting shapes.

### Wave 4a — Gutenberg core block control (shipped)

Positional-path block tree ops for Gutenberg posts and pages. Core WordPress APIs only — no browser tab required.

- **`gutenberg-get-content`** — read a post's block tree as a compact JSON array (type, attrs, innerBlocks)
- **`gutenberg-list-blocks`** — list all registered block types available on the site (namespace/name + title)
- **`gutenberg-get-block-schema`** — introspect a block type's full attribute schema and default values; use this before inserting to avoid guessing props
- **`gutenberg-insert-block`** — insert a new block at a positional path inside a post's block tree; best-effort attribute validation with unknown-block warning; every write is audit-logged
- **`gutenberg-update-block`** — deep-merge new attributes into an existing block at a given path without touching sibling props
- **`gutenberg-delete-block`** — remove a block (and its innerBlocks subtree) from a post
- **`gutenberg-move-block`** — relocate a block from one positional path to another within the same post

**Tip:** insert container blocks (group/columns/etc.) via `block.markup` (raw block HTML) rather than the structured form, so wrapper markup is preserved.

### Wave 4b+ — Planned
**Gutenberg patterns/reusable blocks**, **FSE (Full Site Editing)** template control, **Bricks Builder** support, **ACF / JetEngine / Meta Box / Pods** field-plugin integration. The goal: literally do everything in WordPress through AI.

---

## Install & connect

1. **Install:** download the release ZIP (or clone) and put the `wp-ultra-mcp/` directory — including its bundled `vendor/` — into `wp-content/plugins/`, then activate it. (Requires WordPress **6.6+** and PHP **8.0+**. No `composer install` needed — `vendor/` is bundled.)
2. **Enable:** wp-admin → **WP-Ultra-MCP** → toggle **AI control** on.
3. **App password:** click **Generate application password**, copy it (shown once).
4. **Connect:** on the same page, pick your AI client tab and paste the shown config. For the npx-bridge clients:

```json
{
  "mcpServers": {
    "wp-ultra-mcp": {
      "command": "npx",
      "args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
      "env": {
        "WP_API_URL": "https://YOUR-SITE/wp-json/mcp/wpultra",
        "WP_API_USERNAME": "your-wp-username",
        "WP_API_PASSWORD": "the application password"
      }
    }
  }
}
```

The MCP endpoint exposes three meta-tools — `discover-abilities`, `get-ability-info`, `execute-ability` — and the AI uses them to introspect and run any ability. Auth is standard WordPress Application Passwords; revoke anytime from the Connect page.

---

## Security model

Full power, bounded blast radius. Every privileged ability requires the plugin to be **enabled** AND the user to have `manage_options` (super-admin on multisite). SQL is always `$wpdb->prepare`d; destructive verbs need `confirm: true`. File writes are jailed to the WP root and executable files confined to a sandbox dir. WP-CLI runs as an argument array (no shell string). The sandbox safe-mode suspends AI-written PHP after a fatal so a bad write can't take the site down.

---

## Repository layout

```
wp-ultra-mcp/            ← the WordPress plugin (install this)
  wp-ultra-mcp.php       plugin entry point
  includes/
    abilities/           one PHP file per built-in ability
    recipes/             declarative-ability engine (parser, executor, CPT)
    admin/               Connect page + Ability/Skill/Memory Hubs
    skills/  memory/  sandbox/  helpers.php  bootstrap-mcp.php
  vendor/                bundled wordpress/mcp-adapter (GPL)
  bin/                   deploy.ps1, build-zip.ps1
tests/                   zero-dependency PHP test harness (run with: php tests/<name>.test.php)
docs/superpowers/        design specs & implementation plans
```

---

## Develop

```powershell
# run the PHP test suite — any PHP 8.x CLI; no dependencies
#   bash:       for f in tests/*.test.php; do php "$f"; done
#   powershell: Get-ChildItem tests\*.test.php | % { php $_.FullName }

# deploy into a local site for live testing
powershell -File wp-ultra-mcp\bin\deploy.ps1

# build a distributable release zip (vendor bundled)
powershell -File wp-ultra-mcp\bin\build-zip.ps1
```

Contributions welcome — new built-in abilities and skills especially. Open an issue or PR.

## License

[GPL-2.0-or-later](LICENSE). WP-Ultra-MCP bundles the `wordpress/mcp-adapter` and `wordpress/php-mcp-schema` packages (also GPL-2.0-or-later). Free to use, modify, and redistribute.
