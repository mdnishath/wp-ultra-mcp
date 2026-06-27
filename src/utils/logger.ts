function write(level: string, msg: string, meta?: unknown): void {
  const line = meta === undefined ? `[${level}] ${msg}` : `[${level}] ${msg} ${safe(meta)}`;
  process.stderr.write(line + "\n");
}

function safe(meta: unknown): string {
  try { return typeof meta === "string" ? meta : JSON.stringify(meta); }
  catch { return String(meta); }
}

export const logger = {
  info: (m: string, meta?: unknown) => write("INFO", m, meta),
  warn: (m: string, meta?: unknown) => write("WARN", m, meta),
  error: (m: string, meta?: unknown) => write("ERROR", m, meta),
};
