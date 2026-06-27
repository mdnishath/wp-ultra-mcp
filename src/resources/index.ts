import type { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import type { Managers } from "../types.js";
import { ELEMENTOR_SCHEMA } from "./elementor-schema.js";

export function registerResources(server: McpServer, managers: Managers): void {
  server.registerResource(
    "elementor-schema",
    "elementor://schema",
    {
      title: "Elementor Atomic Layout Schema",
      description: "The exact JSON blueprint for Elementor v4+ containers and widgets. Read this before generating any layout.",
      mimeType: "application/json",
    },
    async (uri) => ({
      contents: [{ uri: uri.href, mimeType: "application/json", text: JSON.stringify(ELEMENTOR_SCHEMA, null, 2) }],
    }),
  );

  server.registerResource(
    "wpmcp-config",
    "wpmcp://config",
    {
      title: "Active WP-MCP Environment",
      description: "Secrets-redacted snapshot of the WordPress environment this server is operating on.",
      mimeType: "application/json",
    },
    async (uri) => {
      const c = managers.config;
      const snapshot = {
        wpRootPath: c.wpRootPath,
        dbHost: c.dbHost,
        dbName: c.dbName,
        tablePrefix: c.tablePrefix,
        debugLogPath: c.debugLogPath,
        wpCliPath: c.wpCliPath,
        safeMode: c.safeMode,
        allowOutsideRoot: c.allowOutsideRoot,
      };
      return { contents: [{ uri: uri.href, mimeType: "application/json", text: JSON.stringify(snapshot, null, 2) }] };
    },
  );
}
