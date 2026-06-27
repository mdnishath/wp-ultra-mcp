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
