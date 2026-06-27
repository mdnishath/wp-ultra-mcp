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
