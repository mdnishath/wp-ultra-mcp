# WP-Ultra-MCP

**Free, open-source.** Turn any WordPress site into a [Model Context Protocol](https://modelcontextprotocol.io) server so AI clients (Claude Code, Claude Desktop, Cursor, Gemini CLI) can build and control the whole site — files, SQL, WP-CLI, PHP, content, Elementor*, and more — directly, with no relay service in the middle.

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
| **Elementor** | Schema-driven, server-side *(Wave 2)* | Often paid-only |

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

### Wave 2+ — Planned
Schema-driven **Elementor** (introspected widget schemas, v4 atomic widgets, dynamic tags, global styles), **Gutenberg** & **Bricks**, and **ACF / JetEngine / Meta Box / Pods** field-plugin integration. The goal: literally do everything in WordPress through AI.

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
tests/                   zero-dependency PHP test harness (run-all.ps1)
docs/superpowers/        design specs & implementation plans
src/                     archived early Node/stdio prototype (superseded — not the product)
```

---

## Develop

```powershell
# run the PHP test suite (uses a bundled PHP path; see tests/run-all.ps1)
powershell -File tests\run-all.ps1

# deploy into a local site for live testing
powershell -File wp-ultra-mcp\bin\deploy.ps1

# build a distributable release zip (vendor bundled)
powershell -File wp-ultra-mcp\bin\build-zip.ps1
```

Contributions welcome — new built-in abilities and skills especially. Open an issue or PR.

## License

[GPL-2.0-or-later](LICENSE). WP-Ultra-MCP bundles the `wordpress/mcp-adapter` and `wordpress/php-mcp-schema` packages (also GPL-2.0-or-later). Free to use, modify, and redistribute.

\* Elementor support is planned for Wave 2; it is not yet shipped.
