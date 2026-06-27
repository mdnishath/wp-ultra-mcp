import type { ToolResult } from "../types.js";
import { logger } from "./logger.js";

export class AppError extends Error {
  constructor(message: string, public detail?: unknown) {
    super(message);
    this.name = "AppError";
  }
}

export function textResult(text: string): ToolResult {
  return { content: [{ type: "text", text }] };
}

export function toAiError(e: unknown, context: string): ToolResult {
  const message = e instanceof Error ? e.message : String(e);
  const detail = e instanceof AppError && e.detail !== undefined ? ` | detail: ${safeStringify(e.detail)}` : "";
  logger.error(`[${context}] ${message}${detail}`);
  return {
    content: [{ type: "text", text: `Error in ${context}: ${message}${detail}` }],
    isError: true,
  };
}

function safeStringify(v: unknown): string {
  try { return typeof v === "string" ? v : JSON.stringify(v); }
  catch { return String(v); }
}
