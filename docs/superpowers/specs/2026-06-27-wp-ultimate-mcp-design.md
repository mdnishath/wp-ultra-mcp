# WP-Ultimate-MCP — Design Spec

**Date:** 2026-06-27
**Status:** Approved for planning
**Goal:** A portable, open-source WordPress MCP server (Node/TypeScript) that lets an AI CLI client (Claude Code, Gemini CLI, Antigravity) fully control and build any WordPress ecosystem — Core, filesystem, MySQL, WP-CLI, and native Elementor layouts — over a single stdio transport.

---

## 1. Objective & Scope

Build `wp-ultimate-mcp`: a single, configurable MCP server that exposes WordPress as a set of tools, resources, and prompts to an AI client. It is **not** hardcoded to one site — every WordPress-specific value comes from environment configuration, so the same binary points at any local or remote WordPress install.

**Design priorities, in order:**
1. **Full power** over the WordPress ecosystem (DB, files, WP-CLI, Elementor).
2. **Robustness** — no unhandled exception ever reaches the stdio transport; every failure returns an informative error string to the AI.
3. **Portability** — generic, env-driven, publishable to GitHub as-is.
4. **Bounded blast radius** — full power over WordPress without being an accidental host-machine wiper.

**Out of scope (YAGNI):** HTTP/SSE transport, multi-site fan-out, auth/RBAC layers, a web UI, REST API proxying. stdio only.

---

## 2. Safety Posture — "Full power, bounded blast radius"

The user explicitly chose full power. The guardrails below cost nothing in capability but prevent an AI hallucination (e.g. a stray `DELETE FROM wp_posts` or a write to `C:\Windows`) from causing irreversible host damage. Every guard has an escape hatch.

| Concern | Default behaviour | Escape hatch |
|---|---|---|
| Destructive SQL (`DROP`, `TRUNCATE`, `DELETE`/`UPDATE` with no `WHERE`) | **Allowed.** A classifier flags them so they are logged to stderr, never silently blocked. | `WP_MCP_SAFE_MODE=true` → these require an explicit `confirm: true` tool arg. |
| WP-CLI commands | **All allowed.** Runs with cwd pinned to `WP_ROOT_PATH`, hard timeout. | `WP_MCP_SAFE_MODE=true` → denylist (`db drop`, `db reset`, `eval`, `eval-file`) requires `confirm: true`. |
| File writes / reads | **Jailed inside `WP_ROOT_PATH`.** Path-traversal (`..`, absolute escapes, symlink escapes) is resolved and rejected. | `WP_MCP_ALLOW_OUTSIDE_ROOT=true` → writes/reads allowed anywhere on host. |
| SQL injection | Always parameterized (`?` placeholders bound by `mysql2`). Identifiers (table names) validated against `^[A-Za-z0-9_]+$`. | None — this is correctness, not policy. |

**Security invariants (always on, never disabled):**
- Every SQL value is bound, never string-concatenated.
- `run_wp_cli` uses `spawn` with an **argument array** (never a shell string) → no shell-injection surface.
- The path-jail uses `path.resolve` + prefix check on the *real* resolved path, not the input string.

---

## 3. Architecture — Modular Monolith

```
AI CLI (Claude / Gemini / Antigravity)
        │  stdio  (JSON-RPC 2.0, MCP)
        ▼
┌─────────────────────────────────────────────────────────┐
│  wp-ultimate-mcp  (Node 18+, TypeScript strict)          │
│                                                          │
│  index.ts  ─ McpServer + StdioServerTransport bootstrap  │
│     │  registers tools / resources / prompts             │
│     ▼                                                     │
│  Tool handlers (thin)  ── zod validate → call manager    │
│     │                                                     │
│     ▼                                                     │
│  Managers (one responsibility each):                     │
│   • ConfigManager     env → validated typed config       │
│   • DatabaseManager   mysql2 pool, classify, bind        │
│   • FileManager       path-jail, atomic write, mkdir     │
│   • WpCliManager      spawn in WP_ROOT, timeout          │
│   • ElementorManager  _elementor_data read/merge/write   │
└─────────────────────────────────────────────────────────┘
        │             │            │             │
        ▼             ▼            ▼             ▼
   MySQL (mysql2)  filesystem   wp-cli (spawn)  (Elementor via DB)
```

**Why this shape:** Tools stay thin and declarative (schema + one manager call). All real logic lives in managers, each with one job, injected as dependencies — so each is unit-testable in isolation, and a tool file never needs to know *how* a query is classified or a path is jailed.

### Component contracts

- **ConfigManager** — `load(): WpMcpConfig`. Reads `process.env` (+ `.env` via dotenv), validates with zod, throws a single human-readable aggregate error on missing/invalid config. Exposes typed getters. No other module touches `process.env`.
- **DatabaseManager** — owns a `mysql2` connection pool. `query(sql, params)`, `classify(sql) → {verb, destructive}`, `getOption(name)`, `upsertPostMeta(postId, key, value)`. Lazy-connects on first use; pool reused across calls.
- **FileManager** — `resolveInJail(relOrAbs) → safeAbsPath | throws`, `writeAtomic(path, content)`, `read(path, {tailLines?})`, `ensureDir(path)`. The only module that touches `fs`.
- **WpCliManager** — `run(args: string[], {timeoutMs}) → {stdout, stderr, code}`. `spawn('wp', args, {cwd: WP_ROOT_PATH})`. Resolves a configurable `wp` binary path.
- **ElementorManager** — depends on DatabaseManager. `getLayout(postId)`, `setLayout(postId, dataJson)` (validates JSON, upserts `_elementor_data`, sets `_elementor_edit_mode='builder'`, bumps `_elementor_version`, clears `_elementor_css`).

### Error strategy

`utils/errors.ts` exports `toAiError(e, context): { content: [{type:'text', text}], isError: true }`. Every tool handler body is wrapped so any throw becomes a structured MCP error result with an actionable message (the failing SQL/path/command + the underlying error). The transport never sees an exception.

---

## 4. Project Layout

```
wp-ultimate-mcp/
  package.json
  tsconfig.json
  .env.example
  README.md
  claude-config.example.json        # ready-to-paste mcpServers entry
  src/
    index.ts                        # bootstrap: server + transport + registration
    config.ts                       # ConfigManager (zod env schema)
    types.ts                        # shared types
    managers/
      database.ts
      filesystem.ts
      wpcli.ts
      elementor.ts
    tools/
      execute-wp-query.ts
      write-wp-file.ts
      read-wp-file.ts
      run-wp-cli.ts
      read-wp-debug-log.ts
      update-elementor-layout.ts
      index.ts                      # registerTools(server, managers)
    resources/
      elementor-schema.ts           # elementor://schema
      config-snapshot.ts            # wpmcp://config (secrets redacted)
      index.ts
    prompts/
      elementor-architect.ts        # primes AI as Elementor v4 expert
      index.ts
    utils/
      errors.ts
      logger.ts                     # stderr only — stdout is reserved for JSON-RPC
```

**Critical constraint:** stdout belongs exclusively to the MCP JSON-RPC stream. All diagnostics go to **stderr** via `logger.ts`. A stray `console.log` corrupts the protocol.

---

## 5. Configuration

Environment variables (validated by zod at startup; server refuses to start on invalid config):

| Var | Required | Default | Purpose |
|---|---|---|---|
| `WP_ROOT_PATH` | ✅ | — | Absolute path to WordPress root. File-jail boundary + WP-CLI cwd. |
| `WP_DB_HOST` | ✅ | — | MySQL host. |
| `WP_DB_PORT` | | `3306` | MySQL port. |
| `WP_DB_NAME` | ✅ | — | Database name. |
| `WP_DB_USER` | ✅ | — | DB user. |
| `WP_DB_PASSWORD` | | `''` | DB password. |
| `WP_TABLE_PREFIX` | | `wp_` | Table prefix for option/meta helpers. |
| `WP_DEBUG_LOG_PATH` | | `${WP_ROOT_PATH}/wp-content/debug.log` | debug.log location. |
| `WP_CLI_PATH` | | `wp` | wp binary (override for bundled Local/Laragon wp). |
| `WP_CLI_TIMEOUT_MS` | | `120000` | WP-CLI hard timeout. |
| `WP_MCP_SAFE_MODE` | | `false` | When true, destructive SQL/WP-CLI need `confirm:true`. |
| `WP_MCP_ALLOW_OUTSIDE_ROOT` | | `false` | When true, file ops may leave `WP_ROOT_PATH`. |

`claude-config.example.json` ships a copy-paste `mcpServers.wp-ultimate-mcp` block with `command`, `args`, and an `env` map of the above.

---

## 6. Tools (6)

Each tool: zod input schema → manager call → text result or `toAiError`.

1. **`execute_wp_query`** — `{ sql: string, params?: (string|number|null)[], confirm?: boolean }`. Classifies the verb; runs parameterized. SELECT → returns rows (JSON, row-capped). INSERT/UPDATE/DELETE/UPSERT → returns `affectedRows`/`insertId`. Destructive + SAFE_MODE + no confirm → returns an error explaining how to confirm. Supports `ON DUPLICATE KEY UPDATE` (caller-supplied).

2. **`write_wp_file`** — `{ path: string, content: string, append?: boolean }`. Resolves through the jail, recursive `mkdir`, **atomic** write (write tmp in same dir + `rename`). Returns bytes written + resolved path.

3. **`read_wp_file`** — `{ path: string, maxBytes?: number }`. Jailed read; supports the self-healing loop (AI re-reads the file it just wrote to diagnose a fatal). Returns content (size-capped) + metadata.

4. **`run_wp_cli`** — `{ args: string[], confirm?: boolean }`. `spawn('wp'|WP_CLI_PATH, args, {cwd: WP_ROOT_PATH})`, timeout. Returns `{stdout, stderr, exitCode}`. Denylisted subcommands gated only under SAFE_MODE.

5. **`read_wp_debug_log`** — `{ lines?: number }` (default 100). Tails the last N lines of `WP_DEBUG_LOG_PATH`. Returns lines + a note if the file is absent (so the AI knows logging may be off).

6. **`update_elementor_layout`** — `{ post_id: number, elementor_data: <JSON string | array>, clear_css_cache?: boolean }`. Validates the layout is well-formed JSON/array, upserts `_elementor_data`, sets `_elementor_edit_mode='builder'`, bumps `_elementor_version`, optionally clears `_elementor_css`. Returns the affected post + confirmation.

---

## 7. Resources (2)

- **`elementor://schema`** (`application/json`) — the exact JSON blueprint of Elementor v4+ atomic structure: the `elType` taxonomy (`container`, `widget`), `settings` shape, `elements` nesting rules, flex/grid container settings, and 3–4 worked element examples (heading, button, image, nested container). This is the anti-hallucination contract — the AI reads it before generating a layout.
- **`wpmcp://config`** (`application/json`) — a **secrets-redacted** snapshot of the active config (root path, db name/host, prefix, safe-mode flags) so the AI knows the environment it is operating in.

---

## 8. Prompt (1)

- **`elementor-architect`** — a parameterized prompt template (`{ task: string }`) that primes the model as an Elementor v4 layout architect: how `_elementor_data` nests containers → widgets, how to build a responsive 3-column layout (one flex container, `flex_direction: row`, three child containers each holding a widget), unique-ID conventions, and the exact handoff to `update_elementor_layout`. References `elementor://schema` for the field-level contract.

---

## 9. Error Handling & Testing

- **Errors:** every handler wrapped → `toAiError`. Messages name the offending input (sql/path/args) and the root cause. DB connection failures, missing files, WP-CLI non-zero exits, JSON parse failures all return structured, actionable text — never a stack trace to stdout.
- **Testing strategy** (for the plan phase): unit tests per manager with mocked `fs`/`child_process`/pool — path-jail traversal cases, SQL classifier verbs, atomic-write tmp cleanup, WP-CLI arg passing, Elementor upsert SQL shape. Tools tested with stubbed managers. A smoke script that boots the server and lists tools/resources over stdio.

---

## 10. Stack & Dependencies

- **Runtime:** Node ≥ 18, TypeScript (strict).
- **Deps:** `@modelcontextprotocol/sdk`, `mysql2`, `zod`, `dotenv`.
- **Dev:** `typescript`, `tsx` (dev run), `@types/node`, a test runner (`vitest`).
- **Transport:** `StdioServerTransport` only.
- **Build:** `tsc` → `dist/`; `bin` entry `wp-ultimate-mcp` → `dist/index.js` with shebang.

---

## 11. Open Questions

None blocking. Remote-environment support (SSH/Docker WP-CLI, remote DB tunnel) is satisfied by configuration (`WP_CLI_PATH` can be a wrapper script, `WP_DB_HOST` can be a tunnel endpoint) without code changes — documented in README, not built as a separate feature.
