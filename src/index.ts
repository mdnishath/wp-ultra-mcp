#!/usr/bin/env node
import "dotenv/config";
import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { loadConfig } from "./config.js";
import { DatabaseManager } from "./managers/database.js";
import { FileManager } from "./managers/filesystem.js";
import { WpCliManager } from "./managers/wpcli.js";
import { ElementorManager } from "./managers/elementor.js";
import { registerTools } from "./tools/index.js";
import { registerResources } from "./resources/index.js";
import { registerPrompts } from "./prompts/index.js";
import { logger } from "./utils/logger.js";
import type { Managers } from "./types.js";

export function buildServer(managers: Managers): McpServer {
  const server = new McpServer({ name: "wp-ultimate-mcp", version: "0.1.0" });
  registerTools(server, managers);
  registerResources(server, managers);
  registerPrompts(server);
  return server;
}

async function main(): Promise<void> {
  const config = loadConfig();
  const db = new DatabaseManager(config);
  const managers: Managers = {
    config,
    db,
    files: new FileManager(config),
    cli: new WpCliManager(config),
    elementor: new ElementorManager(db),
  };
  const server = buildServer(managers);
  const transport = new StdioServerTransport();
  await server.connect(transport);
  logger.info(`wp-ultimate-mcp connected. Root=${config.wpRootPath} DB=${config.dbName}@${config.dbHost}`);

  const shutdown = async () => {
    await db.close().catch(() => {});
    process.exit(0);
  };
  process.on("SIGINT", shutdown);
  process.on("SIGTERM", shutdown);
}

const isDirectRun = process.argv[1] && import.meta.url === new URL(`file://${process.argv[1]}`).href;
if (isDirectRun || process.env.WP_MCP_FORCE_START === "1") {
  main().catch((e) => {
    logger.error(`Fatal: ${e instanceof Error ? e.message : String(e)}`);
    process.exit(1);
  });
}
