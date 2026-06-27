import type { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { z } from "zod";
import type { Managers } from "../types.js";
import { toAiError, textResult } from "../utils/errors.js";
import { classifyQuery } from "../managers/database.js";

export function registerTools(server: McpServer, m: Managers): void {
  // 1. execute_wp_query
  server.registerTool(
    "execute_wp_query",
    {
      title: "Execute WordPress SQL",
      description:
        "Run a parameterized SQL query against the WordPress database. SELECT returns rows; INSERT/UPDATE/DELETE return affected rows. Use ? placeholders and pass values in params.",
      inputSchema: {
        sql: z.string().describe("SQL with ? placeholders"),
        params: z.array(z.union([z.string(), z.number(), z.null()])).optional().describe("Bound values for placeholders"),
        confirm: z.boolean().optional().describe("Set true to run destructive queries when SAFE_MODE is on"),
      },
    },
    async ({ sql, params, confirm }) => {
      try {
        const { destructive } = classifyQuery(sql);
        if (destructive && m.config.safeMode && !confirm) {
          return toAiError(
            new Error("This query is destructive and SAFE_MODE is enabled. Re-run with confirm: true to proceed."),
            "execute_wp_query",
          );
        }
        const r = await m.db.query(sql, params ?? []);
        if (r.rows) {
          return textResult(`verb=${r.verb}; ${r.rows.length} row(s):\n${JSON.stringify(r.rows, null, 2)}`);
        }
        return textResult(`verb=${r.verb}; affectedRows=${r.affectedRows ?? 0}; insertId=${r.insertId ?? "n/a"}`);
      } catch (e) {
        return toAiError(e, "execute_wp_query");
      }
    },
  );

  // 2. write_wp_file
  server.registerTool(
    "write_wp_file",
    {
      title: "Write a file in the WordPress tree",
      description:
        "Atomically write (or append to) a file. Path is relative to WP_ROOT_PATH (or absolute inside it). Parent directories are created automatically.",
      inputSchema: {
        path: z.string().describe("Path relative to WP_ROOT_PATH, e.g. wp-content/plugins/x/x.php"),
        content: z.string(),
        append: z.boolean().optional(),
      },
    },
    async ({ path: p, content, append }) => {
      try {
        const r = await m.files.writeAtomic(p, content, append ?? false);
        return textResult(`Wrote ${r.bytes} bytes to ${r.path}${append ? " (appended)" : ""}.`);
      } catch (e) {
        return toAiError(e, "write_wp_file");
      }
    },
  );

  // 3. read_wp_file
  server.registerTool(
    "read_wp_file",
    {
      title: "Read a file in the WordPress tree",
      description: "Read a file (jailed to WP_ROOT_PATH). Useful for self-healing after a write broke the site.",
      inputSchema: {
        path: z.string(),
        maxBytes: z.number().int().positive().optional().describe("Cap returned content size (default 200000)"),
      },
    },
    async ({ path: p, maxBytes }) => {
      try {
        const r = await m.files.read(p, { maxBytes: maxBytes ?? 200000 });
        return textResult(`${r.path}${r.truncated ? " (truncated)" : ""}:\n${r.content}`);
      } catch (e) {
        return toAiError(e, "read_wp_file");
      }
    },
  );

  // 4. run_wp_cli
  server.registerTool(
    "run_wp_cli",
    {
      title: "Run a WP-CLI command",
      description:
        "Run a WP-CLI command inside WP_ROOT_PATH. Pass the command as an array of arguments, e.g. ['plugin','install','elementor','--activate'].",
      inputSchema: {
        args: z.array(z.string()).min(1).describe("WP-CLI args, e.g. ['cache','flush']"),
        confirm: z.boolean().optional().describe("Required for denylisted commands when SAFE_MODE is on"),
      },
    },
    async ({ args, confirm }) => {
      try {
        if (m.config.safeMode && m.cli.isDenylisted(args) && !confirm) {
          return toAiError(
            new Error(`'${args.join(" ")}' is denylisted under SAFE_MODE. Re-run with confirm: true to proceed.`),
            "run_wp_cli",
          );
        }
        const r = await m.cli.run(args);
        const status = r.exitCode === 0 ? "succeeded" : `failed with exit code ${r.exitCode}`;
        return textResult(`wp ${args.join(" ")} ${status}.\n--- stdout ---\n${r.stdout}\n--- stderr ---\n${r.stderr}`);
      } catch (e) {
        return toAiError(e, "run_wp_cli");
      }
    },
  );

  // 5. read_wp_debug_log
  server.registerTool(
    "read_wp_debug_log",
    {
      title: "Read wp-content/debug.log",
      description: "Return the last N lines of the WordPress debug log for diagnosing fatal errors.",
      inputSchema: { lines: z.number().int().positive().max(5000).optional().describe("How many trailing lines (default 100)") },
    },
    async ({ lines }) => {
      try {
        const r = await m.files.read(m.config.debugLogPath, { tailLines: lines ?? 100 });
        if (r.content.trim() === "") return textResult("debug.log is empty.");
        return textResult(`Last lines of ${r.path}:\n${r.content}`);
      } catch (e) {
        const msg = (e as NodeJS.ErrnoException).code === "ENOENT"
          ? `No debug.log at ${m.config.debugLogPath}. Enable WP_DEBUG and WP_DEBUG_LOG in wp-config.php to capture errors.`
          : null;
        return msg ? textResult(msg) : toAiError(e, "read_wp_debug_log");
      }
    },
  );

  // 6. update_elementor_layout
  server.registerTool(
    "update_elementor_layout",
    {
      title: "Update an Elementor page layout",
      description:
        "Atomically set _elementor_data for a post, switch it to builder edit mode, and clear the cached CSS. Read the elementor://schema resource first.",
      inputSchema: {
        post_id: z.number().int().positive(),
        elementor_data: z
          .union([z.string(), z.array(z.any())])
          .describe("The _elementor_data array (or its JSON string)"),
        clear_css_cache: z.boolean().optional().describe("Clear _elementor_css (default true)"),
      },
    },
    async ({ post_id, elementor_data, clear_css_cache }) => {
      try {
        const r = await m.elementor.setLayout(post_id, elementor_data, clear_css_cache ?? true);
        return textResult(`Updated Elementor layout for post ${r.postId}: ${r.elements} top-level element(s), edit mode = builder.`);
      } catch (e) {
        return toAiError(e, "update_elementor_layout");
      }
    },
  );
}
