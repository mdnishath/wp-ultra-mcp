export interface ToolResult {
  [key: string]: unknown;
  content: { type: "text"; text: string }[];
  isError?: boolean;
}

import type { WpMcpConfig } from "./config.js";
import type { DatabaseManager } from "./managers/database.js";
import type { FileManager } from "./managers/filesystem.js";
import type { WpCliManager } from "./managers/wpcli.js";
import type { ElementorManager } from "./managers/elementor.js";

export interface Managers {
  config: WpMcpConfig;
  db: DatabaseManager;
  files: FileManager;
  cli: WpCliManager;
  elementor: ElementorManager;
}
