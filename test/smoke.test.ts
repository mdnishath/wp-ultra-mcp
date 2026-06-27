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
