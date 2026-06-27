=== WP-Ultra-MCP ===
Contributors: wpultra
Tags: mcp, ai, elementor, wp-cli, automation
Requires at least: 6.6
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later

Turn this WordPress site into an MCP server for AI CLIs (Claude Code, Gemini). Deep server-side Elementor control, raw SQL, WP-CLI, files, execute-php, and a skills system.

== Description ==

WP-Ultra-MCP transforms any WordPress site into a full Model Context Protocol (MCP) server. AI CLI clients like Claude Code and Gemini CLI connect directly to your site, gaining the ability to read and write files, run SQL queries, execute WP-CLI commands, manage WordPress content, run arbitrary PHP, store persistent memory, and invoke reusable skill prompts — all from within the AI session.

Install, enable AI control, generate an application password, and paste the config into your AI client. Then drive your whole WordPress site from the AI.

**Wave 1 abilities (shipped):**
- `wpultra/read-file` / `wpultra/write-file` / `wpultra/edit-file` / `wpultra/delete-file` / `wpultra/list-directory` — jailed filesystem ops
- `wpultra/run-wp-cli` — any WP-CLI command inside the WP root
- `wpultra/execute-wp-query` — parameterized SQL with SELECT/destructive gating
- `wpultra/execute-php` — run arbitrary PHP in the WP context, capture output + return value
- `wpultra/get-diagnostics` — PHP version, active plugins, WP version, debug-log tail
- `wpultra/memory-save` / `wpultra/memory-load` / `wpultra/memory-list` — persistent keyed memory across sessions
- `wpultra/create-post` / `wpultra/delete-post` — WordPress content CRUD
- `wpultra/skills-list` / `wpultra/skills-get` / `wpultra/skills-create` / `wpultra/skills-update` / `wpultra/skills-delete` — reusable skill prompt management
- Admin settings page: enable/disable AI control, generate app password, manage skills

**Wave 2 (planned):** schema-driven Elementor layout control, design-token system, Gutenberg block injection, Bricks Builder support, ACF/Meta Box/Pods field-plugin integration.

== Installation ==
1. Upload the release ZIP (with vendor/) and activate.
2. Go to WP-Ultra-MCP, enable AI control, generate an application password.
3. Copy the client config into Claude Code / Gemini and restart the MCP session.

== Frequently Asked Questions ==

= Does this require Elementor? =
No. All Wave 1 abilities work without Elementor. Elementor-specific layout tools are planned for Wave 2.

= Is it safe to leave AI control enabled permanently? =
AI control is disabled by default. Enable it only when you need it. The SQL ability gates destructive queries (INSERT, UPDATE, DELETE) behind an explicit `confirm: true` parameter.

= Does it work with any MCP client? =
Any client that implements the Model Context Protocol 2025 spec. Claude Code and Gemini CLI are tested.

== Changelog ==

= 0.1.0 =
* Initial Wave 1 release: files, WP-CLI, SQL, execute-php, diagnostics, memory, WP-content, skills, admin UI.

== Upgrade Notice ==

= 0.1.0 =
Initial release.
