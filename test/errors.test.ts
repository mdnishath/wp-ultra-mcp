import { describe, it, expect } from "vitest";
import { toAiError, textResult, AppError } from "../src/utils/errors.js";

describe("errors", () => {
  it("textResult wraps text", () => {
    expect(textResult("hi")).toEqual({ content: [{ type: "text", text: "hi" }] });
  });

  it("toAiError marks isError and includes context + message", () => {
    const r = toAiError(new AppError("boom", { sql: "SELECT 1" }), "execute_wp_query");
    expect(r.isError).toBe(true);
    expect(r.content[0].text).toContain("execute_wp_query");
    expect(r.content[0].text).toContain("boom");
  });

  it("toAiError handles non-Error throwables", () => {
    const r = toAiError("raw string fail", "ctx");
    expect(r.isError).toBe(true);
    expect(r.content[0].text).toContain("raw string fail");
  });
});
