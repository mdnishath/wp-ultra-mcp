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
