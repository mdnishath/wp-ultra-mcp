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
