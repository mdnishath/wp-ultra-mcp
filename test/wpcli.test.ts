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
