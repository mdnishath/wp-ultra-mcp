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
