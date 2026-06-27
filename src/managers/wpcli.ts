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
