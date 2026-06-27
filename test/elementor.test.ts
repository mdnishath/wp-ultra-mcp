import { describe, it, expect, vi } from "vitest";
import { ElementorManager } from "../src/managers/elementor.js";

function fakeDb(existing: string | null) {
  return {
    table: (n: string) => `wp_${n}`,
    query: vi.fn(async (sql: string) => {
      if (/SELECT meta_value/.test(sql)) {
        return existing === null ? { rows: [] } : { rows: [{ meta_value: existing }] };
      }
      return { affectedRows: 1, insertId: 1 };
    }),
  } as any;
}

describe("ElementorManager", () => {
  it("getLayout parses stored JSON", async () => {
    const db = fakeDb(JSON.stringify([{ id: "a", elType: "container", elements: [] }]));
    const em = new ElementorManager(db);
    const layout = await em.getLayout(5);
    expect(layout[0]).toMatchObject({ elType: "container" });
  });

  it("getLayout returns [] when no meta", async () => {
    const em = new ElementorManager(fakeDb(null));
    expect(await em.getLayout(5)).toEqual([]);
  });

  it("setLayout validates, writes data + edit_mode", async () => {
    const db = fakeDb(null);
    const em = new ElementorManager(db);
    const r = await em.setLayout(5, [{ id: "a", elType: "container", elements: [] }]);
    expect(r.elements).toBe(1);
    const metaWrites = db.query.mock.calls.map((c: any[]) => c[0]).join("\n");
    expect(metaWrites).toContain("_elementor_data");
    expect(metaWrites).toContain("_elementor_edit_mode");
  });

  it("setLayout rejects invalid JSON string", async () => {
    const em = new ElementorManager(fakeDb(null));
    await expect(em.setLayout(5, "{not json")).rejects.toThrow(/JSON/i);
  });
});
