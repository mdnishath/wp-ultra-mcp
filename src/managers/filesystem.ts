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
      let lines = content.split(/\r?\n/);
      // Remove trailing empty line if content ends with newline
      if (lines.length > 0 && lines[lines.length - 1] === "") {
        lines = lines.slice(0, -1);
      }
      if (lines.length > opts.tailLines) {
        content = lines.slice(-opts.tailLines).join("\n");
        truncated = true;
      } else {
        content = lines.join("\n");
      }
    }
    if (opts.maxBytes !== undefined && Buffer.byteLength(content, "utf8") > opts.maxBytes) {
      content = content.slice(0, opts.maxBytes);
      truncated = true;
    }
    return { path: abs, content, truncated };
  }
}
