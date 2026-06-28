=== WP-Ultra-MCP ===
Contributors: wpultra
Tags: mcp, ai, elementor, wp-cli, automation
Requires at least: 6.6
Requires PHP: 8.0
Stable tag: 0.5.0
License: GPLv2 or later

Turn this WordPress site into an MCP server for AI CLIs (Claude Code, Gemini): raw SQL, WP-CLI, files, execute-php, persistent memory, WP content, skills, and schema-driven Elementor v4 layout control.

== Description ==

WP-Ultra-MCP transforms any WordPress site into a full Model Context Protocol (MCP) server. AI CLI clients like Claude Code and Gemini CLI connect directly to your site, gaining the ability to read and write files, run SQL queries, execute WP-CLI commands, manage WordPress content, run arbitrary PHP, store persistent memory, and invoke reusable skill prompts — all from within the AI session.

Install, enable AI control, generate an application password, and paste the config into your AI client. Then drive your whole WordPress site from the AI.

**Wave 1 abilities (shipped):**
- `wpultra/read-file` / `wpultra/write-file` / `wpultra/edit-file` / `wpultra/delete-file` / `wpultra/list-directory` — jailed filesystem ops
- `wpultra/run-wp-cli` — any WP-CLI command inside the WP root
- `wpultra/execute-wp-query` — parameterized SQL with SELECT/destructive gating
- `wpultra/execute-php` — run arbitrary PHP in the WP context, capture output + return value
- `wpultra/read-debug-log` — tail the WordPress debug.log
- `wpultra/memory-save` / `wpultra/memory-get` / `wpultra/memory-list` / `wpultra/memory-delete` — persistent keyed memory across sessions
- `wpultra/create-post` / `wpultra/update-post` / `wpultra/delete-post` — WordPress content CRUD
- `wpultra/skill-get` / `wpultra/skill-write` / `wpultra/skill-edit` / `wpultra/skill-delete` — reusable skill prompt management
- Admin: top-level **WP-Ultra-MCP** menu — Connect page (enable AI control, generate app password) and Abilities page (enable/disable individual abilities)

**Wave 2 (shipped):** schema-driven Elementor v4 atomic layout control — 9 elementor-* abilities. Requires the `e_atomic_elements` experiment enabled in Elementor.

**Wave 4a (shipped):** Gutenberg core block control — 7 abilities for positional-path block tree ops + block-type discovery (gutenberg-get-content, gutenberg-list-blocks, gutenberg-get-block-schema, gutenberg-insert-block, gutenberg-update-block, gutenberg-delete-block, gutenberg-move-block). Core WordPress APIs only, no browser tab.

**Wave 4b+ (planned):** Gutenberg patterns/reusable blocks, FSE template control, Bricks Builder support, ACF/Meta Box/Pods field-plugin integration.

== Installation ==
1. Upload the release ZIP (with vendor/) and activate.
2. Go to the top-level **WP-Ultra-MCP** menu in wp-admin → Connect page. Enable AI control and generate an application password.
3. Copy the client config into Claude Code / Gemini and restart the MCP session.

== Frequently Asked Questions ==

= Does this require Elementor? =
No. All Wave 1 abilities work without Elementor. The Wave 2 elementor-* abilities require Elementor (free or Pro) with the `e_atomic_elements` experiment enabled.

= Is it safe to leave AI control enabled permanently? =
AI control is disabled by default. Enable it only when you need it. The SQL ability automatically classifies queries as destructive and requires `confirm: true` before executing them. Queries are always considered destructive if they contain `DROP`, `TRUNCATE`, or `ALTER`. `DELETE` and `UPDATE` are treated as destructive only when they are missing a `WHERE` clause. `INSERT` is never gated.

= Does it work with any MCP client? =
Any client that implements the Model Context Protocol 2025 spec. Claude Code and Gemini CLI are tested.

== Changelog ==

= 0.5.0 =
* Wave 4a — Gutenberg core block control via positional-path tree ops + block-type discovery; core WordPress APIs only, no browser tab.
* `gutenberg-get-content` — read a post's block tree as a compact JSON array (type, attrs, innerBlocks).
* `gutenberg-list-blocks` — list all registered block types available on the site (namespace/name + title).
* `gutenberg-get-block-schema` — introspect a block type's full attribute schema and default values.
* `gutenberg-insert-block` — insert a new block at a positional path inside a post's content; best-effort attribute validation with unknown-block warning.
* `gutenberg-update-block` — deep-merge new attributes into an existing block at a given path; unknown blocks emit a warning but are allowed.
* `gutenberg-delete-block` — remove a block (and its innerBlocks subtree) from a post.
* `gutenberg-move-block` — relocate a block from one positional path to another within the same post.

= 0.4.0 =
* Activity log — a capped audit trail of every privileged action (PHP, WP-CLI, SQL writes, file writes/edits/deletes, HTTP recipes): who ran it, when, and the outcome. New "Activity" admin page with a one-click clear.
* Per-category capability toggles — switch a whole group (Filesystem, Code Execution, Database, Elementor, etc.) off from the Abilities page; disabled categories' abilities never register.
* Repo cleanup — removed the archived Node/stdio prototype (src/, npm tooling) that was superseded by the plugin and no longer shipped.

= 0.3.2 =
* Security & robustness hardening pass (no new abilities):
* Fixed stored XSS in the Ability/Skill/Memory Hubs (AI-written names are now esc_js-escaped in the delete confirm dialog).
* delete-file now refuses wp-config.php and other site-critical files, not just core directories.
* Closed sandbox executable-extension bypasses (.phtml/.php5/.pht/trailing dot/space) and the sandbox dir now ships a deny-.htaccess + index.php so written code can't be run by URL.
* run-wp-cli drains stdout/stderr concurrently (no more 64KB-buffer deadlock) and enforces a wall-clock timeout.
* SQL destructive-query gate switched to an allow-list (DELETE/UPDATE always need confirm; GRANT/RENAME/CTE/unknown verbs too).
* http recipes use wp_safe_remote_request (SSRF protection against internal hosts).
* read-file streams up to max_bytes instead of loading whole files (OOM fix); paths with control/null bytes are rejected.
* Elementor: collision-checked element ids, depth-guarded tree walk, CSS cache cleared after global-class upsert, real version stamp only.
* Content abilities validate post_type, refuse the plugin's internal CPTs, and memory-save won't clobber a non-memory post.
* New WPULTRA_RECIPE_RUN_TYPES constant (and wpultra_recipe_allowed_run_types filter) to lock down which declarative-recipe run types are allowed — e.g. define('WPULTRA_RECIPE_RUN_TYPES','http') to stop the AI minting php/sql/wp-cli recipes. Disabled types are neither registered nor executable. Default: all allowed (unchanged behavior).

= 0.3.1 =
* Wave 3.5 — Elementor global classes (list/upsert/apply) + element interactions/entrance animations; 4 abilities.

= 0.3.0 =
* Wave 3 — Elementor design systems: global colors/typography, design-token variables, dynamic-tag discovery (4 abilities: elementor-get-design-system, elementor-list-dynamic-tags, elementor-manage-global-colors, elementor-manage-variables).

= 0.2.0 =
* Wave 2 — schema-driven Elementor v4 engine: list-widgets, get-widget-schema, get-style-schema, get-content, set-content, add/edit/delete/move-element (9 abilities). Built-in elementor-v4-architect skill.

= 0.1.0 =
* Initial Wave 1 release: files, WP-CLI, SQL, execute-php, diagnostics, memory, WP-content, skills, admin UI.

== Upgrade Notice ==

= 0.1.0 =
Initial release.
