import type { DatabaseManager } from "./database.js";
import { AppError } from "../utils/errors.js";

const ELEMENTOR_VERSION = "3.25.0";

export class ElementorManager {
  constructor(private db: DatabaseManager) {}

  async getLayout(postId: number): Promise<unknown[]> {
    const r = await this.db.query(
      `SELECT meta_value FROM ${this.db.table("postmeta")} WHERE post_id = ? AND meta_key = '_elementor_data' LIMIT 1`,
      [postId],
    );
    const row = r.rows?.[0] as { meta_value?: string } | undefined;
    if (!row?.meta_value) return [];
    try {
      const parsed = JSON.parse(row.meta_value);
      return Array.isArray(parsed) ? parsed : [];
    } catch {
      throw new AppError(`Stored _elementor_data for post ${postId} is not valid JSON`);
    }
  }

  private async setMeta(postId: number, key: string, value: string): Promise<void> {
    const existing = await this.db.query(
      `SELECT meta_id FROM ${this.db.table("postmeta")} WHERE post_id = ? AND meta_key = '${key}' LIMIT 1`,
      [postId],
    );
    const found = existing.rows?.[0] as { meta_id?: number } | undefined;
    if (found?.meta_id) {
      await this.db.query(`UPDATE ${this.db.table("postmeta")} SET meta_value = ? WHERE meta_id = ?`, [value, found.meta_id]);
    } else {
      await this.db.query(
        `INSERT INTO ${this.db.table("postmeta")} (post_id, meta_key, meta_value) VALUES (?, '${key}', ?)`,
        [postId, value],
      );
    }
  }

  async setLayout(postId: number, data: unknown[] | string, clearCss = true): Promise<{ postId: number; elements: number }> {
    let elements: unknown[];
    if (typeof data === "string") {
      try {
        const parsed = JSON.parse(data);
        if (!Array.isArray(parsed)) throw new AppError("elementor_data JSON must be an array of top-level elements");
        elements = parsed;
      } catch (e) {
        if (e instanceof AppError) throw e;
        throw new AppError(`elementor_data is not valid JSON: ${(e as Error).message}`);
      }
    } else {
      elements = data;
    }
    const json = JSON.stringify(elements);
    await this.setMeta(postId, "_elementor_data", json);
    await this.setMeta(postId, "_elementor_edit_mode", "builder");
    await this.setMeta(postId, "_elementor_version", ELEMENTOR_VERSION);
    if (clearCss) {
      await this.db.query(
        `DELETE FROM ${this.db.table("postmeta")} WHERE post_id = ? AND meta_key = '_elementor_css'`,
        [postId],
      );
    }
    return { postId, elements: elements.length };
  }
}
