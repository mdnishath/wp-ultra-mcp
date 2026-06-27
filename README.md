# WP-Ultra-MCP

A free, open-source WordPress plugin that turns any WordPress site into a Model Context Protocol (MCP) server for AI CLI clients like Claude Code and Gemini CLI. Connect your AI directly to your site: read and write files, run SQL, execute WP-CLI, manage content, and run arbitrary PHP — all from within the AI session, with no relay server required.

## Why WP-Ultra-MCP instead of Novamira?

| | WP-Ultra-MCP | Novamira |
|---|---|---|
| **Cost** | Free, open-source | Paid SaaS |
| **ETag / conditional fetch** | Built in — no redundant reads | Not available |
| **Move-element (deep merge)** | Delta-aware, minimal payload | Full-replace only |
| **Data ownership** | Your server, your DB | Data transits Novamira infra |
| **Elementor control** | Schema-driven (Wave 2) | Proprietary |
| **Extensibility** | PHP hooks, skill system | Closed |

## Wave Roadmap

### Wave 1 — Shipped (v0.1.0)

All core server-side abilities are live:

- **Files:** `read-file`, `write-file`, `edit-file`, `delete-file`, `list-directory` — jailed to the WP root
- **WP-CLI:** `run-wp-cli` — execute any WP-CLI command inside the site root
- **SQL:** `execute-wp-query` — parameterized SELECT and destructive queries (destructive gated behind `confirm: true`)
- **PHP:** `execute-php` — run arbitrary PHP in the WP context, capture output and return value
- **Diagnostics:** `get-diagnostics` — PHP version, WP version, active plugins, debug-log tail
- **Memory:** `memory-save`, `memory-load`, `memory-list` — persistent keyed memory across sessions, stored in `wp_options`
- **WP content:** `create-post`, `delete-post` — WordPress post/page CRUD
- **Skills:** `skills-list`, `skills-get`, `skills-create`, `skills-update`, `skills-delete` — reusable named prompt snippets stored in the DB
- **Admin:** Settings page to enable/disable AI control, generate application passwords, and manage skills

### Wave 2 — Planned

- Schema-driven Elementor layout control (`set-layout`, `get-layout`, `move-element`, `update-widget`)
- Design-token and global-style system
- Gutenberg block injection via the Block Editor REST API
- Bricks Builder support
- ACF, Meta Box, and Pods field-plugin integration

### Wave 3 — Future

- Multi-site support
- Read-only safe-mode for staging workflows
- Structured audit log for AI actions

## Install & Connect

1. **Install:** Download or clone this repo. Upload the `wp-ultra-mcp/` directory (including `vendor/`) to `wp-content/plugins/`, then activate via the Plugins screen.
2. **Enable:** Go to **Settings → WP-Ultra-MCP** in wp-admin. Toggle **AI Control** on.
3. **App password:** Click **Generate Application Password**. Copy the password shown.
4. **Paste config:** Add the following to your Claude Code MCP config (or equivalent for Gemini CLI) and restart the MCP session:

```json
{
  "mcpServers": {
    "wpultra": {
      "url": "https://yoursite.example.com/wp-json/mcp/wpultra",
      "auth": {
        "type": "basic",
        "username": "your-wp-username",
        "password": "xxxx xxxx xxxx xxxx xxxx xxxx"
      }
    }
  }
}
```

The MCP session exposes three meta-tools: `discover-abilities`, `get-ability-info`, and `execute-ability`. The AI calls these to introspect and invoke any of the Wave 1 abilities listed above.

## Bundled MCP Adapter

WP-Ultra-MCP ships with the `wordpress/mcp-adapter` package vendored under `wp-ultra-mcp/vendor/`. You do not need to run `composer install` after downloading the release ZIP — everything is included. The adapter handles JSON-RPC 2.0 transport, session negotiation, and MCP protocol conformance; the abilities are registered on top of it via WordPress hooks.

## Repository Layout

```
wp-ultra-mcp/           WordPress plugin (install this)
  abilities/            One PHP file per ability
  admin/                Admin settings page
  bin/                  deploy.ps1, build-zip.ps1
  vendor/               Bundled composer dependencies
  wp-ultra-mcp.php      Plugin entry point
tests/                  PHP unit tests (run with tests/run-all.ps1)
docs/                   Archived Node.js MCP server (superseded by the plugin)
```

## Archived: Node.js MCP Server

The original implementation was a Node.js MCP relay server that proxied requests to WordPress over HTTP. It has been superseded by this plugin and is archived under `docs/`. The plugin approach eliminates the relay, removes the npm/Node dependency, and runs entirely inside WordPress's PHP process.

## Development

Run the test suite (requires PHP 8.x on PATH or the bundled Local PHP):

```powershell
powershell -File tests\run-all.ps1
```

Deploy to a Local by Flywheel site for live testing:

```powershell
powershell -File wp-ultra-mcp\bin\deploy.ps1
```

## License

GPLv2 or later.
