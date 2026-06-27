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
