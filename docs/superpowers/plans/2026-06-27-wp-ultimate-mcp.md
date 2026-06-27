# WP-Ultimate-MCP Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a portable, open-source WordPress MCP server (Node/TypeScript, stdio) that gives an AI CLI client full control over any WordPress ecosystem — MySQL, filesystem, WP-CLI, and native Elementor layouts.

**Architecture:** Modular monolith. A thin `index.ts` boots an `McpServer` over `StdioServerTransport` and registers 6 tools, 2 resources, 1 prompt. All real logic lives in 5 single-responsibility managers (Config, Database, FileSystem, WpCli, Elementor) injected into thin tool handlers. Every handler is wrapped so no exception reaches the transport.

**Tech Stack:** Node ≥18 (dev on 24), TypeScript strict, `@modelcontextprotocol/sdk` ^1.29, `mysql2` ^3.22, `zod` ^3.25, `dotenv` ^16; dev: `tsx`, `vitest`, `@types/node`.

## Global Constraints

- TypeScript **strict mode** on; ESM modules (`"type": "module"`, `"module": "NodeNext"`).
- **stdout is reserved for JSON-RPC.** All logging/diagnostics go to **stderr** only. No `console.log` anywhere in `src/`.
- Every tool handler returns an MCP result object; on failure it returns `toAiError(...)` (`isError: true`) — it must **never throw to the transport**.
- All SQL values are **parameterized** (`?` placeholders); table identifiers validated against `^[A-Za-z0-9_]+$`.
- `run_wp_cli` uses `spawn` with an **argument array**, never a shell string.
- File operations are **jailed to `WP_ROOT_PATH`** unless `WP_MCP_ALLOW_OUTSIDE_ROOT=true`.
- Managers are the only modules that touch their resource: `database.ts`↔mysql2, `filesystem.ts`↔fs, `wpcli.ts`↔child_process, `config.ts`↔process.env.
- zod raw-shape object passed as `inputSchema` to `registerTool` (not `z.object(...)`).

---

## File Structure

```
wp-ultimate-mcp/                  (= repo root, E:\wp-connector)
  package.json                    deps, scripts, bin
  tsconfig.json                   strict ESM
  vitest.config.ts                test config
  .env.example                    documented env vars
  claude-config.example.json      copy-paste mcpServers block
  README.md                       usage
  src/
    index.ts                      bootstrap + registration
    config.ts                     ConfigManager (zod env)
    types.ts                      shared types
    managers/
      database.ts                 DatabaseManager
      filesystem.ts               FileManager
      wpcli.ts                    WpCliManager
      elementor.ts                ElementorManager
    tools/index.ts                registerTools(server, m)
    resources/index.ts            registerResources(server, m)
    resources/elementor-schema.ts ELEMENTOR_SCHEMA constant
    prompts/index.ts              registerPrompts(server)
    utils/errors.ts               toAiError, AppError
    utils/logger.ts               stderr logger
  test/
    config.test.ts
    database.test.ts
    filesystem.test.ts
    wpcli.test.ts
    elementor.test.ts
    smoke.test.ts
```

---

### Task 1: Project scaffold & build config

**Files:**
- Create: `package.json`, `tsconfig.json`, `vitest.config.ts`, `.env.example`, `claude-config.example.json`

**Interfaces:**
- Consumes: nothing.
- Produces: `npm test`, `npm run build`, `npm run dev` scripts; ESM+strict TS settings every later task relies on.

- [ ] **Step 1: Write `package.json`**

```json
{
  "name": "wp-ultimate-mcp",
  "version": "0.1.0",
  "description": "Full-control WordPress MCP server (DB, files, WP-CLI, Elementor) over stdio.",
  "type": "module",
  "bin": { "wp-ultimate-mcp": "dist/index.js" },
  "main": "dist/index.js",
  "files": ["dist", "README.md", ".env.example", "claude-config.example.json"],
  "scripts": {
    "build": "tsc",
    "dev": "tsx src/index.ts",
    "start": "node dist/index.js",
    "test": "vitest run",
    "test:watch": "vitest"
  },
  "engines": { "node": ">=18" },
  "dependencies": {
    "@modelcontextprotocol/sdk": "^1.29.0",
    "dotenv": "^16.4.5",
    "mysql2": "^3.22.5",
    "zod": "^3.25.0"
  },
  "devDependencies": {
    "@types/node": "^22.10.0",
    "tsx": "^4.22.0",
    "typescript": "^5.7.0",
    "vitest": "^4.1.0"
  }
}
```

- [ ] **Step 2: Write `tsconfig.json`**

```json
{
  "compilerOptions": {
    "target": "ES2022",
    "module": "NodeNext",
    "moduleResolution": "NodeNext",
    "outDir": "dist",
    "rootDir": "src",
    "strict": true,
    "noUncheckedIndexedAccess": true,
    "esModuleInterop": true,
    "skipLibCheck": true,
    "declaration": true,
    "sourceMap": true,
    "resolveJsonModule": true
  },
  "include": ["src/**/*"],
  "exclude": ["node_modules", "dist", "test"]
}
```

- [ ] **Step 3: Write `vitest.config.ts`**

```ts
import { defineConfig } from "vitest/config";

export default defineConfig({
  test: { environment: "node", include: ["test/**/*.test.ts"] },
});
```

- [ ] **Step 4: Write `.env.example`**

```bash
# Required
WP_ROOT_PATH=C:/laragon/www/mysite
WP_DB_HOST=127.0.0.1
WP_DB_NAME=mysite
WP_DB_USER=root
# Optional (defaults shown)
WP_DB_PORT=3306
WP_DB_PASSWORD=
WP_TABLE_PREFIX=wp_
# WP_DEBUG_LOG_PATH=        # defaults to ${WP_ROOT_PATH}/wp-content/debug.log
WP_CLI_PATH=wp
WP_CLI_TIMEOUT_MS=120000
WP_MCP_SAFE_MODE=false
WP_MCP_ALLOW_OUTSIDE_ROOT=false
```

- [ ] **Step 5: Write `claude-config.example.json`**

```json
{
  "mcpServers": {
    "wp-ultimate-mcp": {
      "command": "node",
      "args": ["C:/path/to/wp-ultimate-mcp/dist/index.js"],
      "env": {
        "WP_ROOT_PATH": "C:/laragon/www/mysite",
        "WP_DB_HOST": "127.0.0.1",
        "WP_DB_NAME": "mysite",
        "WP_DB_USER": "root",
        "WP_DB_PASSWORD": ""
      }
    }
  }
}
```

- [ ] **Step 6: Install & verify**

Run: `npm install`
Expected: completes, `node_modules/` present, no peer-dep errors.

- [ ] **Step 7: Commit**

```bash
git add package.json tsconfig.json vitest.config.ts .env.example claude-config.example.json package-lock.json
git commit -m "chore: project scaffold and build config"
```

---

### Task 2: Shared types, errors, logger

**Files:**
- Create: `src/types.ts`, `src/utils/errors.ts`, `src/utils/logger.ts`
- Test: `test/errors.test.ts`

**Interfaces:**
- Produces:
  - `class AppError extends Error { constructor(message: string, public detail?: unknown) }`
  - `function toAiError(e: unknown, context: string): ToolResult` where `ToolResult = { content: {type:"text"; text:string}[]; isError?: boolean }`
  - `function textResult(text: string): ToolResult`
  - `logger.info/warn/error(msg: string, meta?: unknown): void` → writes to `process.stderr`.
  - `types.ts` exports `ToolResult`, `WpMcpConfig` (defined in Task 3, re-exported here is NOT done — `WpMcpConfig` lives in config.ts; `types.ts` holds only `ToolResult`).

- [ ] **Step 1: Write the failing test** — `test/errors.test.ts`

```ts
import { describe, it, expect } from "vitest";
import { toAiError, textResult, AppError } from "../src/utils/errors.js";

describe("errors", () => {
  it("textResult wraps text", () => {
    expect(textResult("hi")).toEqual({ content: [{ type: "text", text: "hi" }] });
  });

  it("toAiError marks isError and includes context + message", () => {
    const r = toAiError(new AppError("boom", { sql: "SELECT 1" }), "execute_wp_query");
    expect(r.isError).toBe(true);
    expect(r.content[0].text).toContain("execute_wp_query");
    expect(r.content[0].text).toContain("boom");
  });

  it("toAiError handles non-Error throwables", () => {
    const r = toAiError("raw string fail", "ctx");
    expect(r.isError).toBe(true);
    expect(r.content[0].text).toContain("raw string fail");
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run test/errors.test.ts`
Expected: FAIL — cannot resolve `../src/utils/errors.js`.

- [ ] **Step 3: Write `src/types.ts`**

```ts
export interface ToolResult {
  content: { type: "text"; text: string }[];
  isError?: boolean;
}
```

- [ ] **Step 4: Write `src/utils/logger.ts`**

```ts
function write(level: string, msg: string, meta?: unknown): void {
  const line = meta === undefined ? `[${level}] ${msg}` : `[${level}] ${msg} ${safe(meta)}`;
  process.stderr.write(line + "\n");
}

function safe(meta: unknown): string {
  try { return typeof meta === "string" ? meta : JSON.stringify(meta); }
  catch { return String(meta); }
}

export const logger = {
  info: (m: string, meta?: unknown) => write("INFO", m, meta),
  warn: (m: string, meta?: unknown) => write("WARN", m, meta),
  error: (m: string, meta?: unknown) => write("ERROR", m, meta),
};
```

- [ ] **Step 5: Write `src/utils/errors.ts`**

```ts
import type { ToolResult } from "../types.js";
import { logger } from "./logger.js";

export class AppError extends Error {
  constructor(message: string, public detail?: unknown) {
    super(message);
    this.name = "AppError";
  }
}

export function textResult(text: string): ToolResult {
  return { content: [{ type: "text", text }] };
}

export function toAiError(e: unknown, context: string): ToolResult {
  const message = e instanceof Error ? e.message : String(e);
  const detail = e instanceof AppError && e.detail !== undefined ? ` | detail: ${safeStringify(e.detail)}` : "";
  logger.error(`[${context}] ${message}${detail}`);
  return {
    content: [{ type: "text", text: `Error in ${context}: ${message}${detail}` }],
    isError: true,
  };
}

function safeStringify(v: unknown): string {
  try { return typeof v === "string" ? v : JSON.stringify(v); }
  catch { return String(v); }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `npx vitest run test/errors.test.ts`
Expected: PASS (3 tests).

- [ ] **Step 7: Commit**

```bash
git add src/types.ts src/utils/errors.ts src/utils/logger.ts test/errors.test.ts
git commit -m "feat: shared types, AppError, toAiError, stderr logger"
```

---

### Task 3: ConfigManager (zod env validation)

**Files:**
- Create: `src/config.ts`
- Test: `test/config.test.ts`

**Interfaces:**
- Consumes: `AppError` from `utils/errors.js`.
- Produces:
  - `interface WpMcpConfig { wpRootPath, dbHost, dbPort, dbName, dbUser, dbPassword, tablePrefix, debugLogPath, wpCliPath, wpCliTimeoutMs, safeMode, allowOutsideRoot }` (string fields except `dbPort:number`, `wpCliTimeoutMs:number`, `safeMode:boolean`, `allowOutsideRoot:boolean`).
  - `function loadConfig(env?: NodeJS.ProcessEnv): WpMcpConfig` — validates, throws `AppError` listing every problem; derives `debugLogPath` default from `wpRootPath`; normalizes `wpRootPath` via `path.resolve`.

- [ ] **Step 1: Write the failing test** — `test/config.test.ts`

```ts
import { describe, it, expect } from "vitest";
import { loadConfig } from "../src/config.js";

const base = {
  WP_ROOT_PATH: "/var/www/site",
  WP_DB_HOST: "127.0.0.1",
  WP_DB_NAME: "site",
  WP_DB_USER: "root",
};

describe("loadConfig", () => {
  it("loads required fields and applies defaults", () => {
    const c = loadConfig(base);
    expect(c.dbPort).toBe(3306);
    expect(c.tablePrefix).toBe("wp_");
    expect(c.wpCliPath).toBe("wp");
    expect(c.wpCliTimeoutMs).toBe(120000);
    expect(c.safeMode).toBe(false);
    expect(c.allowOutsideRoot).toBe(false);
    expect(c.dbPassword).toBe("");
  });

  it("derives debugLogPath from wpRootPath", () => {
    const c = loadConfig(base);
    expect(c.debugLogPath.replace(/\\/g, "/")).toContain("/wp-content/debug.log");
  });

  it("parses booleans and numbers from strings", () => {
    const c = loadConfig({ ...base, WP_MCP_SAFE_MODE: "true", WP_DB_PORT: "3307", WP_MCP_ALLOW_OUTSIDE_ROOT: "1" });
    expect(c.safeMode).toBe(true);
    expect(c.dbPort).toBe(3307);
    expect(c.allowOutsideRoot).toBe(true);
  });

  it("throws AppError listing all missing required vars", () => {
    expect(() => loadConfig({})).toThrowError(/WP_ROOT_PATH.*WP_DB_HOST.*WP_DB_NAME.*WP_DB_USER/s);
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run test/config.test.ts`
Expected: FAIL — cannot resolve `../src/config.js`.

- [ ] **Step 3: Write `src/config.ts`**

```ts
import { z } from "zod";
import path from "node:path";
import { AppError } from "./utils/errors.js";

export interface WpMcpConfig {
  wpRootPath: string;
  dbHost: string;
  dbPort: number;
  dbName: string;
  dbUser: string;
  dbPassword: string;
  tablePrefix: string;
  debugLogPath: string;
  wpCliPath: string;
  wpCliTimeoutMs: number;
  safeMode: boolean;
  allowOutsideRoot: boolean;
}

const bool = (def: boolean) =>
  z.union([z.string(), z.boolean(), z.undefined()]).transform((v) => {
    if (v === undefined) return def;
    if (typeof v === "boolean") return v;
    return ["1", "true", "yes", "on"].includes(v.toLowerCase());
  });

const numWithDefault = (def: number) =>
  z.union([z.string(), z.undefined()]).transform((v, ctx) => {
    if (v === undefined || v === "") return def;
    const n = Number(v);
    if (!Number.isFinite(n)) {
      ctx.addIssue({ code: z.ZodIssueCode.custom, message: "must be a number" });
      return z.NEVER;
    }
    return n;
  });

const schema = z.object({
  WP_ROOT_PATH: z.string().min(1, "WP_ROOT_PATH is required"),
  WP_DB_HOST: z.string().min(1, "WP_DB_HOST is required"),
  WP_DB_NAME: z.string().min(1, "WP_DB_NAME is required"),
  WP_DB_USER: z.string().min(1, "WP_DB_USER is required"),
  WP_DB_PASSWORD: z.string().optional().default(""),
  WP_DB_PORT: numWithDefault(3306),
  WP_TABLE_PREFIX: z.string().optional().default("wp_"),
  WP_DEBUG_LOG_PATH: z.string().optional(),
  WP_CLI_PATH: z.string().optional().default("wp"),
  WP_CLI_TIMEOUT_MS: numWithDefault(120000),
  WP_MCP_SAFE_MODE: bool(false),
  WP_MCP_ALLOW_OUTSIDE_ROOT: bool(false),
});

export function loadConfig(env: NodeJS.ProcessEnv = process.env): WpMcpConfig {
  const parsed = schema.safeParse(env);
  if (!parsed.success) {
    const msgs = parsed.error.issues.map((i) => `${i.path.join(".")}: ${i.message}`).join("; ");
    throw new AppError(`Invalid configuration: ${msgs}`, parsed.error.flatten());
  }
  const v = parsed.data;
  const wpRootPath = path.resolve(v.WP_ROOT_PATH);
  return {
    wpRootPath,
    dbHost: v.WP_DB_HOST,
    dbPort: v.WP_DB_PORT,
    dbName: v.WP_DB_NAME,
    dbUser: v.WP_DB_USER,
    dbPassword: v.WP_DB_PASSWORD,
    tablePrefix: v.WP_TABLE_PREFIX,
    debugLogPath: v.WP_DEBUG_LOG_PATH
      ? path.resolve(v.WP_DEBUG_LOG_PATH)
      : path.join(wpRootPath, "wp-content", "debug.log"),
    wpCliPath: v.WP_CLI_PATH,
    wpCliTimeoutMs: v.WP_CLI_TIMEOUT_MS,
    safeMode: v.WP_MCP_SAFE_MODE,
    allowOutsideRoot: v.WP_MCP_ALLOW_OUTSIDE_ROOT,
  };
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npx vitest run test/config.test.ts`
Expected: PASS (4 tests). Note: the "all missing" test relies on zod reporting each required field; verify the regex matches the joined message order — if zod orders differently, the `/s` flag + interleaving still matches since all four names appear.

- [ ] **Step 5: Commit**

```bash
git add src/config.ts test/config.test.ts
git commit -m "feat: zod-validated ConfigManager"
```

---

### Task 4: DatabaseManager

**Files:**
- Create: `src/managers/database.ts`
- Test: `test/database.test.ts`

**Interfaces:**
- Consumes: `WpMcpConfig`, `AppError`.
- Produces:
  - `interface QueryClassification { verb: string; destructive: boolean }`
  - `function classifyQuery(sql: string): QueryClassification` — pure; verb = first keyword uppercased; `destructive=true` for `DROP`/`TRUNCATE`/`ALTER`, or `DELETE`/`UPDATE` lacking a `WHERE`.
  - `interface PoolLike { query(sql: string, params?: unknown[]): Promise<[unknown, unknown]>; end(): Promise<void> }`
  - `class DatabaseManager` constructed as `new DatabaseManager(config, poolFactory?)` where `poolFactory?: (cfg) => PoolLike` (defaults to real mysql2 pool). Methods: `query(sql, params?) → Promise<{ rows?: unknown[]; affectedRows?: number; insertId?: number; verb: string }>`; `getOption(name: string) → Promise<string | null>`; `table(name: string) → string` (prefix + identifier validation); `close() → Promise<void>`.

- [ ] **Step 1: Write the failing test** — `test/database.test.ts`

```ts
import { describe, it, expect, vi } from "vitest";
import { DatabaseManager, classifyQuery } from "../src/managers/database.js";
import type { WpMcpConfig } from "../src/config.js";

const cfg = { tablePrefix: "wp_", dbHost: "h", dbPort: 3306, dbName: "d", dbUser: "u", dbPassword: "" } as WpMcpConfig;

function fakePool(impl: (sql: string, params?: unknown[]) => unknown) {
  return { query: vi.fn(async (sql: string, params?: unknown[]) => [impl(sql, params), []]), end: vi.fn(async () => {}) };
}

describe("classifyQuery", () => {
  it("flags DELETE without WHERE as destructive", () => {
    expect(classifyQuery("DELETE FROM wp_posts")).toEqual({ verb: "DELETE", destructive: true });
  });
  it("DELETE with WHERE is not destructive", () => {
    expect(classifyQuery("delete from wp_posts where ID=1").destructive).toBe(false);
  });
  it("DROP is destructive", () => {
    expect(classifyQuery("DROP TABLE wp_x").destructive).toBe(true);
  });
  it("SELECT is non-destructive", () => {
    expect(classifyQuery("  SELECT * FROM wp_posts ")).toEqual({ verb: "SELECT", destructive: false });
  });
});

describe("DatabaseManager", () => {
  it("returns rows for SELECT", async () => {
    const pool = fakePool(() => [{ ID: 1 }]);
    const db = new DatabaseManager(cfg, () => pool);
    const r = await db.query("SELECT * FROM wp_posts");
    expect(r.verb).toBe("SELECT");
    expect(r.rows).toEqual([{ ID: 1 }]);
  });

  it("returns affectedRows/insertId for INSERT", async () => {
    const pool = fakePool(() => ({ affectedRows: 1, insertId: 42 }));
    const db = new DatabaseManager(cfg, () => pool);
    const r = await db.query("INSERT INTO wp_posts (post_title) VALUES (?)", ["x"]);
    expect(r.affectedRows).toBe(1);
    expect(r.insertId).toBe(42);
    expect(pool.query).toHaveBeenCalledWith("INSERT INTO wp_posts (post_title) VALUES (?)", ["x"]);
  });

  it("getOption returns the option_value", async () => {
    const pool = fakePool(() => [{ option_value: "https://site.test" }]);
    const db = new DatabaseManager(cfg, () => pool);
    expect(await db.getOption("siteurl")).toBe("https://site.test");
  });

  it("getOption returns null when absent", async () => {
    const pool = fakePool(() => []);
    const db = new DatabaseManager(cfg, () => pool);
    expect(await db.getOption("missing")).toBeNull();
  });

  it("table() rejects bad identifiers", () => {
    const db = new DatabaseManager(cfg, () => fakePool(() => []));
    expect(() => db.table("posts; DROP")).toThrow();
    expect(db.table("posts")).toBe("wp_posts");
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run test/database.test.ts`
Expected: FAIL — cannot resolve `../src/managers/database.js`.

- [ ] **Step 3: Write `src/managers/database.ts`**

```ts
import mysql from "mysql2/promise";
import type { WpMcpConfig } from "../config.js";
import { AppError } from "../utils/errors.js";

export interface QueryClassification {
  verb: string;
  destructive: boolean;
}

export interface PoolLike {
  query(sql: string, params?: unknown[]): Promise<[unknown, unknown]>;
  end(): Promise<void>;
}

export interface QueryResult {
  verb: string;
  rows?: unknown[];
  affectedRows?: number;
  insertId?: number;
}

export function classifyQuery(sql: string): QueryClassification {
  const trimmed = sql.trim();
  const verb = (trimmed.split(/\s+/)[0] ?? "").toUpperCase();
  const hasWhere = /\bWHERE\b/i.test(trimmed);
  let destructive = false;
  if (verb === "DROP" || verb === "TRUNCATE" || verb === "ALTER") destructive = true;
  if ((verb === "DELETE" || verb === "UPDATE") && !hasWhere) destructive = true;
  return { verb, destructive };
}

type PoolFactory = (cfg: WpMcpConfig) => PoolLike;

const defaultFactory: PoolFactory = (cfg) =>
  mysql.createPool({
    host: cfg.dbHost,
    port: cfg.dbPort,
    user: cfg.dbUser,
    password: cfg.dbPassword,
    database: cfg.dbName,
    waitForConnections: true,
    connectionLimit: 5,
    namedPlaceholders: false,
  }) as unknown as PoolLike;

export class DatabaseManager {
  private pool: PoolLike | null = null;
  constructor(private cfg: WpMcpConfig, private factory: PoolFactory = defaultFactory) {}

  private getPool(): PoolLike {
    if (!this.pool) this.pool = this.factory(this.cfg);
    return this.pool;
  }

  table(name: string): string {
    if (!/^[A-Za-z0-9_]+$/.test(name)) {
      throw new AppError(`Invalid table identifier: ${name}`);
    }
    return `${this.cfg.tablePrefix}${name}`;
  }

  async query(sql: string, params: unknown[] = []): Promise<QueryResult> {
    const { verb } = classifyQuery(sql);
    const [result] = await this.getPool().query(sql, params);
    if (Array.isArray(result)) {
      return { verb, rows: result as unknown[] };
    }
    const r = result as { affectedRows?: number; insertId?: number };
    return { verb, affectedRows: r.affectedRows, insertId: r.insertId };
  }

  async getOption(name: string): Promise<string | null> {
    const r = await this.query(
      `SELECT option_value FROM ${this.table("options")} WHERE option_name = ? LIMIT 1`,
      [name],
    );
    const row = r.rows?.[0] as { option_value?: string } | undefined;
    return row?.option_value ?? null;
  }

  async close(): Promise<void> {
    if (this.pool) {
      await this.pool.end();
      this.pool = null;
    }
  }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npx vitest run test/database.test.ts`
Expected: PASS (9 tests).

> Note: WordPress `wp_postmeta` has no unique key on `(post_id, meta_key)` by default, so a blind `INSERT ... ON DUPLICATE KEY UPDATE` would not work for post meta. The ElementorManager (Task 6) therefore does explicit read-then-update/insert. DatabaseManager intentionally exposes only the generic `query` primitive plus `getOption`/`table` — no post-meta upsert helper.

- [ ] **Step 5: Commit**

```bash
git add src/managers/database.ts test/database.test.ts
git commit -m "feat: DatabaseManager with query classifier and bound helpers"
```

---

### Task 5: FileManager (path-jail, atomic write, tail read)

**Files:**
- Create: `src/managers/filesystem.ts`
- Test: `test/filesystem.test.ts`

**Interfaces:**
- Consumes: `WpMcpConfig`, `AppError`.
- Produces: `class FileManager(config)` with:
  - `resolveInJail(p: string): string` — resolves `p` (relative → against `wpRootPath`); if `allowOutsideRoot` is false and the resolved path is not inside `wpRootPath`, throws `AppError`. Returns absolute path.
  - `writeAtomic(p: string, content: string, append?: boolean): Promise<{ path: string; bytes: number }>` — recursive mkdir of dir, then for non-append: write to `${path}.<rand>.tmp` in same dir + `rename`; for append: `appendFile`.
  - `read(p: string, opts?: { maxBytes?: number; tailLines?: number }): Promise<{ path: string; content: string; truncated: boolean }>`.

- [ ] **Step 1: Write the failing test** — `test/filesystem.test.ts`

```ts
import { describe, it, expect, beforeEach, afterEach } from "vitest";
import os from "node:os";
import path from "node:path";
import fs from "node:fs/promises";
import { FileManager } from "../src/managers/filesystem.js";
import type { WpMcpConfig } from "../src/config.js";

let root: string;
function cfg(extra: Partial<WpMcpConfig> = {}): WpMcpConfig {
  return { wpRootPath: root, allowOutsideRoot: false, ...extra } as WpMcpConfig;
}

beforeEach(async () => {
  root = await fs.mkdtemp(path.join(os.tmpdir(), "wpmcp-"));
});
afterEach(async () => {
  await fs.rm(root, { recursive: true, force: true });
});

describe("FileManager jail", () => {
  it("resolves relative path inside root", () => {
    const fm = new FileManager(cfg());
    expect(fm.resolveInJail("wp-content/x.php")).toBe(path.join(root, "wp-content/x.php"));
  });

  it("blocks traversal escape", () => {
    const fm = new FileManager(cfg());
    expect(() => fm.resolveInJail("../../etc/passwd")).toThrow(/outside/i);
  });

  it("allows escape when allowOutsideRoot", () => {
    const fm = new FileManager(cfg({ allowOutsideRoot: true }));
    expect(() => fm.resolveInJail(path.join(os.tmpdir(), "z.txt"))).not.toThrow();
  });
});

describe("FileManager write/read", () => {
  it("atomic write creates nested dirs and reads back", async () => {
    const fm = new FileManager(cfg());
    const w = await fm.writeAtomic("wp-content/plugins/x/x.php", "<?php echo 1;");
    expect(w.bytes).toBeGreaterThan(0);
    const r = await fm.read("wp-content/plugins/x/x.php");
    expect(r.content).toBe("<?php echo 1;");
  });

  it("append adds to existing file", async () => {
    const fm = new FileManager(cfg());
    await fm.writeAtomic("a.txt", "one\n");
    await fm.writeAtomic("a.txt", "two\n", true);
    const r = await fm.read("a.txt");
    expect(r.content).toBe("one\ntwo\n");
  });

  it("tailLines returns last N lines", async () => {
    const fm = new FileManager(cfg());
    await fm.writeAtomic("log.txt", "l1\nl2\nl3\nl4\n");
    const r = await fm.read("log.txt", { tailLines: 2 });
    expect(r.content.trim().split("\n")).toEqual(["l3", "l4"]);
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run test/filesystem.test.ts`
Expected: FAIL — cannot resolve `../src/managers/filesystem.js`.

- [ ] **Step 3: Write `src/managers/filesystem.ts`**

```ts
import fs from "node:fs/promises";
import path from "node:path";
import { randomBytes } from "node:crypto";
import type { WpMcpConfig } from "../config.js";
import { AppError } from "../utils/errors.js";

export class FileManager {
  constructor(private cfg: WpMcpConfig) {}

  resolveInJail(p: string): string {
    const abs = path.isAbsolute(p) ? path.resolve(p) : path.resolve(this.cfg.wpRootPath, p);
    if (this.cfg.allowOutsideRoot) return abs;
    const root = path.resolve(this.cfg.wpRootPath);
    const rel = path.relative(root, abs);
    const escapes = rel === "" ? false : rel.startsWith("..") || path.isAbsolute(rel);
    if (escapes) {
      throw new AppError(`Path is outside WP_ROOT_PATH jail: ${abs} (set WP_MCP_ALLOW_OUTSIDE_ROOT=true to override)`);
    }
    return abs;
  }

  async writeAtomic(p: string, content: string, append = false): Promise<{ path: string; bytes: number }> {
    const abs = this.resolveInJail(p);
    await fs.mkdir(path.dirname(abs), { recursive: true });
    const bytes = Buffer.byteLength(content, "utf8");
    if (append) {
      await fs.appendFile(abs, content, "utf8");
      return { path: abs, bytes };
    }
    const tmp = `${abs}.${randomBytes(6).toString("hex")}.tmp`;
    await fs.writeFile(tmp, content, "utf8");
    await fs.rename(tmp, abs);
    return { path: abs, bytes };
  }

  async read(
    p: string,
    opts: { maxBytes?: number; tailLines?: number } = {},
  ): Promise<{ path: string; content: string; truncated: boolean }> {
    const abs = this.resolveInJail(p);
    let content = await fs.readFile(abs, "utf8");
    let truncated = false;
    if (opts.tailLines !== undefined) {
      const lines = content.split(/\r?\n/);
      if (lines.length > opts.tailLines) {
        content = lines.slice(-opts.tailLines).join("\n");
        truncated = true;
      }
    }
    if (opts.maxBytes !== undefined && Buffer.byteLength(content, "utf8") > opts.maxBytes) {
      content = content.slice(0, opts.maxBytes);
      truncated = true;
    }
    return { path: abs, content, truncated };
  }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npx vitest run test/filesystem.test.ts`
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add src/managers/filesystem.ts test/filesystem.test.ts
git commit -m "feat: FileManager with path-jail and atomic write"
```

---

### Task 6: WpCliManager & ElementorManager

**Files:**
- Create: `src/managers/wpcli.ts`, `src/managers/elementor.ts`
- Test: `test/wpcli.test.ts`, `test/elementor.test.ts`

**Interfaces:**
- WpCli Produces: `interface CliResult { stdout: string; stderr: string; exitCode: number }`; `type SpawnFn = (cmd, args, opts) => ChildProcessLike`; `class WpCliManager(config, spawnFn?)` with `run(args: string[]): Promise<CliResult>` and `isDenylisted(args: string[]): boolean`.
- Elementor Produces: `class ElementorManager(db: DatabaseManager)` with `getLayout(postId): Promise<unknown[]>` and `setLayout(postId, data: unknown[] | string, clearCss?: boolean): Promise<{ postId: number; elements: number }>`.

- [ ] **Step 1: Write failing test** — `test/wpcli.test.ts`

```ts
import { describe, it, expect, vi } from "vitest";
import { EventEmitter } from "node:events";
import { WpCliManager } from "../src/managers/wpcli.js";
import type { WpMcpConfig } from "../src/config.js";

const cfg = { wpCliPath: "wp", wpRootPath: "/var/www", wpCliTimeoutMs: 5000, safeMode: false } as WpMcpConfig;

function fakeSpawn(stdout: string, stderr: string, code: number) {
  return vi.fn(() => {
    const cp: any = new EventEmitter();
    cp.stdout = new EventEmitter();
    cp.stderr = new EventEmitter();
    cp.kill = vi.fn();
    setTimeout(() => {
      cp.stdout.emit("data", Buffer.from(stdout));
      cp.stderr.emit("data", Buffer.from(stderr));
      cp.emit("close", code);
    }, 0);
    return cp;
  });
}

describe("WpCliManager", () => {
  it("runs and captures stdout/exit", async () => {
    const spawn = fakeSpawn("plugin activated", "", 0);
    const cli = new WpCliManager(cfg, spawn as any);
    const r = await cli.run(["plugin", "activate", "elementor"]);
    expect(r.stdout).toContain("plugin activated");
    expect(r.exitCode).toBe(0);
    expect(spawn).toHaveBeenCalledWith("wp", ["plugin", "activate", "elementor"], expect.objectContaining({ cwd: "/var/www" }));
  });

  it("detects denylisted subcommands", () => {
    const cli = new WpCliManager(cfg, fakeSpawn("", "", 0) as any);
    expect(cli.isDenylisted(["db", "drop"])).toBe(true);
    expect(cli.isDenylisted(["eval", "..."])).toBe(true);
    expect(cli.isDenylisted(["plugin", "list"])).toBe(false);
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run test/wpcli.test.ts`
Expected: FAIL — cannot resolve module.

- [ ] **Step 3: Write `src/managers/wpcli.ts`**

```ts
import { spawn as nodeSpawn } from "node:child_process";
import type { WpMcpConfig } from "../config.js";
import { AppError } from "../utils/errors.js";

export interface CliResult {
  stdout: string;
  stderr: string;
  exitCode: number;
}

interface ChildLike {
  stdout: { on(ev: "data", cb: (c: Buffer) => void): void };
  stderr: { on(ev: "data", cb: (c: Buffer) => void): void };
  on(ev: "close", cb: (code: number | null) => void): void;
  on(ev: "error", cb: (err: Error) => void): void;
  kill(signal?: string): void;
}

export type SpawnFn = (cmd: string, args: string[], opts: { cwd: string }) => ChildLike;

const DENYLIST = [
  ["db", "drop"],
  ["db", "reset"],
  ["eval"],
  ["eval-file"],
];

export class WpCliManager {
  constructor(private cfg: WpMcpConfig, private spawnFn: SpawnFn = nodeSpawn as unknown as SpawnFn) {}

  isDenylisted(args: string[]): boolean {
    return DENYLIST.some((deny) => deny.every((seg, i) => args[i] === seg));
  }

  run(args: string[]): Promise<CliResult> {
    return new Promise((resolve, reject) => {
      let stdout = "";
      let stderr = "";
      let settled = false;
      const child = this.spawnFn(this.cfg.wpCliPath, args, { cwd: this.cfg.wpRootPath });
      const timer = setTimeout(() => {
        if (settled) return;
        settled = true;
        child.kill("SIGKILL");
        reject(new AppError(`wp-cli timed out after ${this.cfg.wpCliTimeoutMs}ms`, { args }));
      }, this.cfg.wpCliTimeoutMs);

      child.stdout.on("data", (c) => (stdout += c.toString()));
      child.stderr.on("data", (c) => (stderr += c.toString()));
      child.on("error", (err) => {
        if (settled) return;
        settled = true;
        clearTimeout(timer);
        reject(new AppError(`Failed to spawn wp-cli ('${this.cfg.wpCliPath}'): ${err.message}`, { args }));
      });
      child.on("close", (code) => {
        if (settled) return;
        settled = true;
        clearTimeout(timer);
        resolve({ stdout, stderr, exitCode: code ?? -1 });
      });
    });
  }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npx vitest run test/wpcli.test.ts`
Expected: PASS (3 tests).

- [ ] **Step 5: Write failing test** — `test/elementor.test.ts`

```ts
import { describe, it, expect, vi } from "vitest";
import { ElementorManager } from "../src/managers/elementor.js";

function fakeDb(existing: string | null) {
  return {
    table: (n: string) => `wp_${n}`,
    query: vi.fn(async (sql: string) => {
      if (/SELECT meta_value/.test(sql)) {
        return existing === null ? { rows: [] } : { rows: [{ meta_value: existing }] };
      }
      return { affectedRows: 1, insertId: 1 };
    }),
  } as any;
}

describe("ElementorManager", () => {
  it("getLayout parses stored JSON", async () => {
    const db = fakeDb(JSON.stringify([{ id: "a", elType: "container", elements: [] }]));
    const em = new ElementorManager(db);
    const layout = await em.getLayout(5);
    expect(layout[0]).toMatchObject({ elType: "container" });
  });

  it("getLayout returns [] when no meta", async () => {
    const em = new ElementorManager(fakeDb(null));
    expect(await em.getLayout(5)).toEqual([]);
  });

  it("setLayout validates, writes data + edit_mode", async () => {
    const db = fakeDb(null);
    const em = new ElementorManager(db);
    const r = await em.setLayout(5, [{ id: "a", elType: "container", elements: [] }]);
    expect(r.elements).toBe(1);
    const metaWrites = db.query.mock.calls.map((c: any[]) => c[0]).join("\n");
    expect(metaWrites).toContain("_elementor_data");
    expect(metaWrites).toContain("_elementor_edit_mode");
  });

  it("setLayout rejects invalid JSON string", async () => {
    const em = new ElementorManager(fakeDb(null));
    await expect(em.setLayout(5, "{not json")).rejects.toThrow(/JSON/i);
  });
});
```

- [ ] **Step 6: Run test to verify it fails**

Run: `npx vitest run test/elementor.test.ts`
Expected: FAIL — cannot resolve module.

- [ ] **Step 7: Write `src/managers/elementor.ts`**

```ts
import type { DatabaseManager } from "./database.js";
import { AppError } from "../utils/errors.js";

const ELEMENTOR_VERSION = "3.25.0";

export class ElementorManager {
  constructor(private db: DatabaseManager) {}

  async getLayout(postId: number): Promise<unknown[]> {
    const r = await this.db.query(
      `SELECT meta_value FROM ${this.db.table("postmeta")} WHERE post_id = ? AND meta_key = '_elementor_data' LIMIT 1`,
      [postId],
    );
    const row = r.rows?.[0] as { meta_value?: string } | undefined;
    if (!row?.meta_value) return [];
    try {
      const parsed = JSON.parse(row.meta_value);
      return Array.isArray(parsed) ? parsed : [];
    } catch {
      throw new AppError(`Stored _elementor_data for post ${postId} is not valid JSON`);
    }
  }

  private async setMeta(postId: number, key: string, value: string): Promise<void> {
    const existing = await this.db.query(
      `SELECT meta_id FROM ${this.db.table("postmeta")} WHERE post_id = ? AND meta_key = ? LIMIT 1`,
      [postId, key],
    );
    const found = existing.rows?.[0] as { meta_id?: number } | undefined;
    if (found?.meta_id) {
      await this.db.query(`UPDATE ${this.db.table("postmeta")} SET meta_value = ? WHERE meta_id = ?`, [value, found.meta_id]);
    } else {
      await this.db.query(
        `INSERT INTO ${this.db.table("postmeta")} (post_id, meta_key, meta_value) VALUES (?, ?, ?)`,
        [postId, key, value],
      );
    }
  }

  async setLayout(postId: number, data: unknown[] | string, clearCss = true): Promise<{ postId: number; elements: number }> {
    let elements: unknown[];
    if (typeof data === "string") {
      try {
        const parsed = JSON.parse(data);
        if (!Array.isArray(parsed)) throw new AppError("elementor_data JSON must be an array of top-level elements");
        elements = parsed;
      } catch (e) {
        if (e instanceof AppError) throw e;
        throw new AppError(`elementor_data is not valid JSON: ${(e as Error).message}`);
      }
    } else {
      elements = data;
    }
    const json = JSON.stringify(elements);
    await this.setMeta(postId, "_elementor_data", json);
    await this.setMeta(postId, "_elementor_edit_mode", "builder");
    await this.setMeta(postId, "_elementor_version", ELEMENTOR_VERSION);
    if (clearCss) {
      await this.db.query(
        `DELETE FROM ${this.db.table("postmeta")} WHERE post_id = ? AND meta_key = '_elementor_css'`,
        [postId],
      );
    }
    return { postId, elements: elements.length };
  }
}
```

- [ ] **Step 8: Run test to verify it passes**

Run: `npx vitest run test/elementor.test.ts test/wpcli.test.ts`
Expected: PASS (7 tests).

- [ ] **Step 9: Commit**

```bash
git add src/managers/wpcli.ts src/managers/elementor.ts test/wpcli.test.ts test/elementor.test.ts
git commit -m "feat: WpCliManager and ElementorManager"
```

---

### Task 7: Elementor schema resource & architect prompt

**Files:**
- Create: `src/resources/elementor-schema.ts`, `src/resources/index.ts`, `src/prompts/index.ts`
- Test: covered by smoke test in Task 9 (no isolated unit test — these are static content + registration glue).

**Interfaces:**
- Consumes: `McpServer` from SDK, managers (for config-snapshot resource).
- Produces:
  - `export const ELEMENTOR_SCHEMA: object` — the atomic-widget blueprint.
  - `export function registerResources(server, managers): void` — registers `elementor://schema` and `wpmcp://config`.
  - `export function registerPrompts(server): void` — registers `elementor-architect`.

- [ ] **Step 1: Write `src/resources/elementor-schema.ts`**

```ts
export const ELEMENTOR_SCHEMA = {
  description:
    "Elementor stores page layout as a JSON array in wp_postmeta._elementor_data. Each node has a unique id, an elType, settings, and a nested elements array.",
  node: {
    id: "7-char lowercase alphanumeric, unique per page (e.g. 'a1b2c3d')",
    elType: "'container' | 'widget'",
    settings: "object of element-specific settings (see widgets below)",
    elements: "array of child nodes (containers/widgets); empty for leaf widgets",
    widgetType: "present only when elType='widget' (e.g. 'heading','button','image')",
  },
  containerSettings: {
    container_type: "'flex' | 'grid'",
    flex_direction: "'row' | 'column' | 'row-reverse' | 'column-reverse'",
    flex_gap: { unit: "px", size: 20 },
    content_width: "'boxed' | 'full'",
    width: { unit: "%", size: 100 },
  },
  examples: {
    heading: { id: "h1aaaaa", elType: "widget", widgetType: "heading", settings: { title: "Hello", header_size: "h2" }, elements: [] },
    button: { id: "b1aaaaa", elType: "widget", widgetType: "button", settings: { text: "Click me", link: { url: "#" } }, elements: [] },
    image: { id: "i1aaaaa", elType: "widget", widgetType: "image", settings: { image: { url: "https://example.com/x.jpg" } }, elements: [] },
    threeColumnRow: {
      id: "rowaaaa",
      elType: "container",
      settings: { container_type: "flex", flex_direction: "row", flex_gap: { unit: "px", size: 20 } },
      elements: [
        { id: "colaaa1", elType: "container", settings: { width: { unit: "%", size: 33 } }, elements: [] },
        { id: "colaaa2", elType: "container", settings: { width: { unit: "%", size: 33 } }, elements: [] },
        { id: "colaaa3", elType: "container", settings: { width: { unit: "%", size: 33 } }, elements: [] },
      ],
    },
  },
  rules: [
    "Top-level _elementor_data is an ARRAY of container nodes.",
    "Widgets are always leaves: their elements array is empty.",
    "Every id must be unique within the page.",
    "After writing _elementor_data, set _elementor_edit_mode='builder' (the update_elementor_layout tool does this for you).",
  ],
};
```

- [ ] **Step 2: Write `src/resources/index.ts`**

```ts
import type { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import type { Managers } from "../types.js";
import { ELEMENTOR_SCHEMA } from "./elementor-schema.js";

export function registerResources(server: McpServer, managers: Managers): void {
  server.registerResource(
    "elementor-schema",
    "elementor://schema",
    {
      title: "Elementor Atomic Layout Schema",
      description: "The exact JSON blueprint for Elementor v4+ containers and widgets. Read this before generating any layout.",
      mimeType: "application/json",
    },
    async (uri) => ({
      contents: [{ uri: uri.href, mimeType: "application/json", text: JSON.stringify(ELEMENTOR_SCHEMA, null, 2) }],
    }),
  );

  server.registerResource(
    "wpmcp-config",
    "wpmcp://config",
    {
      title: "Active WP-MCP Environment",
      description: "Secrets-redacted snapshot of the WordPress environment this server is operating on.",
      mimeType: "application/json",
    },
    async (uri) => {
      const c = managers.config;
      const snapshot = {
        wpRootPath: c.wpRootPath,
        dbHost: c.dbHost,
        dbName: c.dbName,
        tablePrefix: c.tablePrefix,
        debugLogPath: c.debugLogPath,
        wpCliPath: c.wpCliPath,
        safeMode: c.safeMode,
        allowOutsideRoot: c.allowOutsideRoot,
      };
      return { contents: [{ uri: uri.href, mimeType: "application/json", text: JSON.stringify(snapshot, null, 2) }] };
    },
  );
}
```

- [ ] **Step 3: Add `Managers` type to `src/types.ts`**

Append to `src/types.ts`:

```ts
import type { WpMcpConfig } from "./config.js";
import type { DatabaseManager } from "./managers/database.js";
import type { FileManager } from "./managers/filesystem.js";
import type { WpCliManager } from "./managers/wpcli.js";
import type { ElementorManager } from "./managers/elementor.js";

export interface Managers {
  config: WpMcpConfig;
  db: DatabaseManager;
  files: FileManager;
  cli: WpCliManager;
  elementor: ElementorManager;
}
```

- [ ] **Step 4: Write `src/prompts/index.ts`**

```ts
import type { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { z } from "zod";

export function registerPrompts(server: McpServer): void {
  server.registerPrompt(
    "elementor-architect",
    {
      title: "Elementor Layout Architect",
      description: "Primes the model as an Elementor v4 layout expert and explains how to build and apply a layout.",
      argsSchema: { task: z.string().describe("What the user wants built, e.g. 'a 3-column features section'") },
    },
    ({ task }) => ({
      messages: [
        {
          role: "user",
          content: {
            type: "text",
            text: [
              "You are an expert Elementor v4 layout architect operating through the WP-Ultimate-MCP server.",
              "",
              "Mental model:",
              "- A page's layout lives in wp_postmeta._elementor_data as a JSON ARRAY of nodes.",
              "- Each node: { id (unique 7-char), elType: 'container'|'widget', settings: {}, elements: [] }.",
              "- Containers nest other containers/widgets via `elements`. Widgets are leaves (empty `elements`) and carry a `widgetType`.",
              "- A responsive 3-column row = ONE flex container (settings.container_type='flex', flex_direction='row') holding THREE child containers, each width 33%, each holding its widget(s).",
              "",
              "Workflow you MUST follow:",
              "1. Read the `elementor://schema` resource to get exact field shapes — do not guess settings keys.",
              "2. Construct the _elementor_data array with unique ids for every node.",
              "3. Call the `update_elementor_layout` tool with { post_id, elementor_data }. It sets _elementor_edit_mode='builder' and clears CSS cache for you.",
              "4. If anything breaks, call `read_wp_debug_log` and self-correct.",
              "",
              `The user's task: ${task}`,
            ].join("\n"),
          },
        },
      ],
    }),
  );
}
```

- [ ] **Step 5: Build to verify types compile**

Run: `npx tsc --noEmit`
Expected: no errors. (Resources/prompts compile against the SDK and `Managers` type.)

- [ ] **Step 6: Commit**

```bash
git add src/resources src/prompts src/types.ts
git commit -m "feat: elementor schema resource, config snapshot, architect prompt"
```

---

### Task 8: Tool registration (6 tools)

**Files:**
- Create: `src/tools/index.ts`
- Test: `test/tools.test.ts`

**Interfaces:**
- Consumes: `Managers`, `toAiError`, `textResult`, `classifyQuery`.
- Produces: `export function registerTools(server: McpServer, m: Managers): void` registering `execute_wp_query`, `write_wp_file`, `read_wp_file`, `run_wp_cli`, `read_wp_debug_log`, `update_elementor_layout`.

- [ ] **Step 1: Write the failing test** — `test/tools.test.ts`

```ts
import { describe, it, expect, vi } from "vitest";
import { registerTools } from "../src/tools/index.js";
import type { Managers } from "../src/types.js";

// Capture registered handlers via a fake server.
function fakeServer() {
  const handlers: Record<string, Function> = {};
  return {
    handlers,
    registerTool: (name: string, _schema: unknown, handler: Function) => {
      handlers[name] = handler;
    },
  } as any;
}

function fakeManagers(over: Partial<Managers> = {}): Managers {
  return {
    config: { safeMode: false } as any,
    db: { query: vi.fn(async () => ({ verb: "SELECT", rows: [{ ID: 1 }] })) } as any,
    files: {
      writeAtomic: vi.fn(async () => ({ path: "/r/x.php", bytes: 10 })),
      read: vi.fn(async () => ({ path: "/r/x.php", content: "data", truncated: false })),
    } as any,
    cli: { run: vi.fn(async () => ({ stdout: "ok", stderr: "", exitCode: 0 })), isDenylisted: () => false } as any,
    elementor: { setLayout: vi.fn(async () => ({ postId: 5, elements: 1 })) } as any,
    ...over,
  };
}

describe("registerTools", () => {
  it("registers all six tools", () => {
    const s = fakeServer();
    registerTools(s, fakeManagers());
    expect(Object.keys(s.handlers).sort()).toEqual(
      ["execute_wp_query", "read_wp_debug_log", "read_wp_file", "run_wp_cli", "update_elementor_layout", "write_wp_file"].sort(),
    );
  });

  it("execute_wp_query returns rows", async () => {
    const s = fakeServer();
    const m = fakeManagers();
    registerTools(s, m);
    const r = await s.handlers["execute_wp_query"]({ sql: "SELECT 1" });
    expect(r.isError).toBeUndefined();
    expect(r.content[0].text).toContain("ID");
  });

  it("execute_wp_query blocks destructive in safe mode without confirm", async () => {
    const s = fakeServer();
    registerTools(s, fakeManagers({ config: { safeMode: true } as any }));
    const r = await s.handlers["execute_wp_query"]({ sql: "DROP TABLE wp_x" });
    expect(r.isError).toBe(true);
    expect(r.content[0].text).toContain("confirm");
  });

  it("write_wp_file returns confirmation", async () => {
    const s = fakeServer();
    registerTools(s, fakeManagers());
    const r = await s.handlers["write_wp_file"]({ path: "x.php", content: "<?php" });
    expect(r.content[0].text).toContain("bytes");
  });

  it("run_wp_cli surfaces non-zero exit as text (not isError)", async () => {
    const s = fakeServer();
    const m = fakeManagers({ cli: { run: vi.fn(async () => ({ stdout: "", stderr: "boom", exitCode: 1 })), isDenylisted: () => false } as any });
    registerTools(s, m);
    const r = await s.handlers["run_wp_cli"]({ args: ["plugin", "list"] });
    expect(r.content[0].text).toContain("exit code 1");
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run test/tools.test.ts`
Expected: FAIL — cannot resolve `../src/tools/index.js`.

- [ ] **Step 3: Write `src/tools/index.ts`**

```ts
import type { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { z } from "zod";
import type { Managers } from "../types.js";
import { toAiError, textResult } from "../utils/errors.js";
import { classifyQuery } from "../managers/database.js";

export function registerTools(server: McpServer, m: Managers): void {
  // 1. execute_wp_query
  server.registerTool(
    "execute_wp_query",
    {
      title: "Execute WordPress SQL",
      description:
        "Run a parameterized SQL query against the WordPress database. SELECT returns rows; INSERT/UPDATE/DELETE return affected rows. Use ? placeholders and pass values in params.",
      inputSchema: {
        sql: z.string().describe("SQL with ? placeholders"),
        params: z.array(z.union([z.string(), z.number(), z.null()])).optional().describe("Bound values for placeholders"),
        confirm: z.boolean().optional().describe("Set true to run destructive queries when SAFE_MODE is on"),
      },
    },
    async ({ sql, params, confirm }) => {
      try {
        const { destructive } = classifyQuery(sql);
        if (destructive && m.config.safeMode && !confirm) {
          return toAiError(
            new Error("This query is destructive and SAFE_MODE is enabled. Re-run with confirm: true to proceed."),
            "execute_wp_query",
          );
        }
        const r = await m.db.query(sql, params ?? []);
        if (r.rows) {
          return textResult(`verb=${r.verb}; ${r.rows.length} row(s):\n${JSON.stringify(r.rows, null, 2)}`);
        }
        return textResult(`verb=${r.verb}; affectedRows=${r.affectedRows ?? 0}; insertId=${r.insertId ?? "n/a"}`);
      } catch (e) {
        return toAiError(e, "execute_wp_query");
      }
    },
  );

  // 2. write_wp_file
  server.registerTool(
    "write_wp_file",
    {
      title: "Write a file in the WordPress tree",
      description:
        "Atomically write (or append to) a file. Path is relative to WP_ROOT_PATH (or absolute inside it). Parent directories are created automatically.",
      inputSchema: {
        path: z.string().describe("Path relative to WP_ROOT_PATH, e.g. wp-content/plugins/x/x.php"),
        content: z.string(),
        append: z.boolean().optional(),
      },
    },
    async ({ path: p, content, append }) => {
      try {
        const r = await m.files.writeAtomic(p, content, append ?? false);
        return textResult(`Wrote ${r.bytes} bytes to ${r.path}${append ? " (appended)" : ""}.`);
      } catch (e) {
        return toAiError(e, "write_wp_file");
      }
    },
  );

  // 3. read_wp_file
  server.registerTool(
    "read_wp_file",
    {
      title: "Read a file in the WordPress tree",
      description: "Read a file (jailed to WP_ROOT_PATH). Useful for self-healing after a write broke the site.",
      inputSchema: {
        path: z.string(),
        maxBytes: z.number().int().positive().optional().describe("Cap returned content size (default 200000)"),
      },
    },
    async ({ path: p, maxBytes }) => {
      try {
        const r = await m.files.read(p, { maxBytes: maxBytes ?? 200000 });
        return textResult(`${r.path}${r.truncated ? " (truncated)" : ""}:\n${r.content}`);
      } catch (e) {
        return toAiError(e, "read_wp_file");
      }
    },
  );

  // 4. run_wp_cli
  server.registerTool(
    "run_wp_cli",
    {
      title: "Run a WP-CLI command",
      description:
        "Run a WP-CLI command inside WP_ROOT_PATH. Pass the command as an array of arguments, e.g. ['plugin','install','elementor','--activate'].",
      inputSchema: {
        args: z.array(z.string()).min(1).describe("WP-CLI args, e.g. ['cache','flush']"),
        confirm: z.boolean().optional().describe("Required for denylisted commands when SAFE_MODE is on"),
      },
    },
    async ({ args, confirm }) => {
      try {
        if (m.config.safeMode && m.cli.isDenylisted(args) && !confirm) {
          return toAiError(
            new Error(`'${args.join(" ")}' is denylisted under SAFE_MODE. Re-run with confirm: true to proceed.`),
            "run_wp_cli",
          );
        }
        const r = await m.cli.run(args);
        const status = r.exitCode === 0 ? "succeeded" : `failed with exit code ${r.exitCode}`;
        return textResult(`wp ${args.join(" ")} ${status}.\n--- stdout ---\n${r.stdout}\n--- stderr ---\n${r.stderr}`);
      } catch (e) {
        return toAiError(e, "run_wp_cli");
      }
    },
  );

  // 5. read_wp_debug_log
  server.registerTool(
    "read_wp_debug_log",
    {
      title: "Read wp-content/debug.log",
      description: "Return the last N lines of the WordPress debug log for diagnosing fatal errors.",
      inputSchema: { lines: z.number().int().positive().max(5000).optional().describe("How many trailing lines (default 100)") },
    },
    async ({ lines }) => {
      try {
        const r = await m.files.read(m.config.debugLogPath, { tailLines: lines ?? 100 });
        if (r.content.trim() === "") return textResult("debug.log is empty.");
        return textResult(`Last lines of ${r.path}:\n${r.content}`);
      } catch (e) {
        const msg = (e as NodeJS.ErrnoException).code === "ENOENT"
          ? `No debug.log at ${m.config.debugLogPath}. Enable WP_DEBUG and WP_DEBUG_LOG in wp-config.php to capture errors.`
          : null;
        return msg ? textResult(msg) : toAiError(e, "read_wp_debug_log");
      }
    },
  );

  // 6. update_elementor_layout
  server.registerTool(
    "update_elementor_layout",
    {
      title: "Update an Elementor page layout",
      description:
        "Atomically set _elementor_data for a post, switch it to builder edit mode, and clear the cached CSS. Read the elementor://schema resource first.",
      inputSchema: {
        post_id: z.number().int().positive(),
        elementor_data: z
          .union([z.string(), z.array(z.any())])
          .describe("The _elementor_data array (or its JSON string)"),
        clear_css_cache: z.boolean().optional().describe("Clear _elementor_css (default true)"),
      },
    },
    async ({ post_id, elementor_data, clear_css_cache }) => {
      try {
        const r = await m.elementor.setLayout(post_id, elementor_data, clear_css_cache ?? true);
        return textResult(`Updated Elementor layout for post ${r.postId}: ${r.elements} top-level element(s), edit mode = builder.`);
      } catch (e) {
        return toAiError(e, "update_elementor_layout");
      }
    },
  );
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npx vitest run test/tools.test.ts`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add src/tools/index.ts test/tools.test.ts
git commit -m "feat: register all six MCP tools"
```

---

### Task 9: Server bootstrap & smoke test

**Files:**
- Create: `src/index.ts`, `test/smoke.test.ts`, `README.md`

**Interfaces:**
- Consumes: everything. Produces: a runnable server; `export function buildServer(managers): McpServer` (exported for the smoke test) and a `main()` that wires config → managers → server → stdio transport.

- [ ] **Step 1: Write the failing test** — `test/smoke.test.ts`

```ts
import { describe, it, expect, vi } from "vitest";
import { buildServer } from "../src/index.js";
import type { Managers } from "../src/types.js";

function stubManagers(): Managers {
  return {
    config: { wpRootPath: "/r", dbHost: "h", dbName: "d", tablePrefix: "wp_", debugLogPath: "/r/wp-content/debug.log", wpCliPath: "wp", safeMode: false, allowOutsideRoot: false } as any,
    db: { query: vi.fn(async () => ({ verb: "SELECT", rows: [] })) } as any,
    files: { read: vi.fn(), writeAtomic: vi.fn() } as any,
    cli: { run: vi.fn(), isDenylisted: () => false } as any,
    elementor: { setLayout: vi.fn() } as any,
  };
}

describe("buildServer", () => {
  it("builds an McpServer without throwing", () => {
    const server = buildServer(stubManagers());
    expect(server).toBeTruthy();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run test/smoke.test.ts`
Expected: FAIL — cannot resolve `../src/index.js`.

- [ ] **Step 3: Write `src/index.ts`**

```ts
#!/usr/bin/env node
import "dotenv/config";
import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { loadConfig } from "./config.js";
import { DatabaseManager } from "./managers/database.js";
import { FileManager } from "./managers/filesystem.js";
import { WpCliManager } from "./managers/wpcli.js";
import { ElementorManager } from "./managers/elementor.js";
import { registerTools } from "./tools/index.js";
import { registerResources } from "./resources/index.js";
import { registerPrompts } from "./prompts/index.js";
import { logger } from "./utils/logger.js";
import type { Managers } from "./types.js";

export function buildServer(managers: Managers): McpServer {
  const server = new McpServer({ name: "wp-ultimate-mcp", version: "0.1.0" });
  registerTools(server, managers);
  registerResources(server, managers);
  registerPrompts(server);
  return server;
}

async function main(): Promise<void> {
  const config = loadConfig();
  const db = new DatabaseManager(config);
  const managers: Managers = {
    config,
    db,
    files: new FileManager(config),
    cli: new WpCliManager(config),
    elementor: new ElementorManager(db),
  };
  const server = buildServer(managers);
  const transport = new StdioServerTransport();
  await server.connect(transport);
  logger.info(`wp-ultimate-mcp connected. Root=${config.wpRootPath} DB=${config.dbName}@${config.dbHost}`);

  const shutdown = async () => {
    await db.close().catch(() => {});
    process.exit(0);
  };
  process.on("SIGINT", shutdown);
  process.on("SIGTERM", shutdown);
}

const isDirectRun = process.argv[1] && import.meta.url === new URL(`file://${process.argv[1]}`).href;
if (isDirectRun || process.env.WP_MCP_FORCE_START === "1") {
  main().catch((e) => {
    logger.error(`Fatal: ${e instanceof Error ? e.message : String(e)}`);
    process.exit(1);
  });
}
```

> Note: the `isDirectRun` guard lets the smoke test import `buildServer` without booting `main()`. On Windows the `file://` URL comparison can differ by slash/drive-letter casing; the test only imports `buildServer`, so `main()` won't run during tests regardless.

- [ ] **Step 4: Run test to verify it passes**

Run: `npx vitest run test/smoke.test.ts`
Expected: PASS (1 test).

- [ ] **Step 5: Run the full suite + build**

Run: `npm test && npm run build`
Expected: all tests pass; `tsc` emits `dist/` with no errors.

- [ ] **Step 6: Manual stdio boot check**

Run (PowerShell): create a throwaway `.env` from `.env.example` pointing at any reachable DB, then:
`node dist/index.js`
Expected: process stays alive, stderr shows the "connected" line, stdout silent. Ctrl+C exits. (If DB unreachable, it still connects lazily — the server boots; queries fail with a clean error.)

- [ ] **Step 7: Write `README.md`**

```markdown
# WP-Ultimate-MCP

A portable, open-source MCP server that gives AI CLI clients (Claude Code, Gemini CLI, Antigravity) full control over any WordPress site: MySQL, filesystem, WP-CLI, and native Elementor layouts.

## Install
\`\`\`bash
npm install && npm run build
\`\`\`

## Configure
Copy `.env.example` → `.env` and fill in `WP_ROOT_PATH`, `WP_DB_*`. Or set them in your MCP client config (see `claude-config.example.json`).

## Tools
- `execute_wp_query` — parameterized SQL (SELECT/INSERT/UPDATE/DELETE/UPSERT)
- `write_wp_file` / `read_wp_file` — atomic, jailed to `WP_ROOT_PATH`
- `run_wp_cli` — any WP-CLI command, run inside the WP root
- `read_wp_debug_log` — tail `wp-content/debug.log` for self-healing
- `update_elementor_layout` — set `_elementor_data` + builder edit mode

## Resources & Prompt
- `elementor://schema` — atomic widget/container blueprint (anti-hallucination)
- `wpmcp://config` — redacted environment snapshot
- `elementor-architect` prompt — primes the model to build layouts

## Safety
Full power by default. Set `WP_MCP_SAFE_MODE=true` to gate destructive SQL/WP-CLI behind `confirm:true`. File ops are jailed to `WP_ROOT_PATH` unless `WP_MCP_ALLOW_OUTSIDE_ROOT=true`.
\`\`\`
```

- [ ] **Step 8: Commit**

```bash
git add src/index.ts test/smoke.test.ts README.md
git commit -m "feat: server bootstrap, stdio transport, smoke test, README"
```

---

## Self-Review

**Spec coverage:**
- Safety posture (§2) → Task 3 (flags), Task 5 (jail), Task 8 (confirm gates). ✅
- 5 managers (§3) → Tasks 3–6. ✅
- Project layout (§4) → all tasks; file paths match. ✅
- Config (§5, 12 vars) → Task 3 schema covers all. ✅
- 6 tools (§6) → Task 8. ✅
- 2 resources (§7) → Task 7. ✅
- 1 prompt (§8) → Task 7. ✅
- Error strategy (§9) → Task 2 + every tool wrapped. ✅
- Stack (§10) → Task 1. ✅

**Placeholder scan:** No TBD/TODO; every code step has complete code. ✅

**Type consistency:** `classifyQuery`, `DatabaseManager.query` shape (`{verb, rows?, affectedRows?, insertId?}`), `FileManager.writeAtomic/read`, `WpCliManager.run/isDenylisted`, `ElementorManager.setLayout` signatures match between definition (Tasks 3–6) and consumption (Tasks 7–8). `Managers` interface (Task 7 Step 3) matches `buildServer`/`main` usage (Task 9). `ToolResult` consistent. ✅

**Known WordPress caveat documented:** `wp_postmeta` upsert semantics handled via read-then-write in ElementorManager (Task 6), with a note in Task 4 explaining why DatabaseManager exposes no post-meta upsert helper (no unique key on `(post_id, meta_key)`).
