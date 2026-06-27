import type { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { z } from "zod";

export function registerPrompts(server: McpServer): void {
  server.registerPrompt(
    "elementor-architect",
    {
      title: "Elementor Layout Architect",
      description: "Primes the model as an Elementor v4 layout expert and explains how to build and apply a layout.",
      argsSchema: { task: z.string().describe("What the user wants built, e.g. 'a 3-column features section'") },
    },
    ({ task }) => ({
      messages: [
        {
          role: "user",
          content: {
            type: "text",
            text: [
              "You are an expert Elementor v4 layout architect operating through the WP-Ultimate-MCP server.",
              "",
              "Mental model:",
              "- A page's layout lives in wp_postmeta._elementor_data as a JSON ARRAY of nodes.",
              "- Each node: { id (unique 7-char), elType: 'container'|'widget', settings: {}, elements: [] }.",
              "- Containers nest other containers/widgets via `elements`. Widgets are leaves (empty `elements`) and carry a `widgetType`.",
              "- A responsive 3-column row = ONE flex container (settings.container_type='flex', flex_direction='row') holding THREE child containers, each width 33%, each holding its widget(s).",
              "",
              "Workflow you MUST follow:",
              "1. Read the `elementor://schema` resource to get exact field shapes — do not guess settings keys.",
              "2. Construct the _elementor_data array with unique ids for every node.",
              "3. Call the `update_elementor_layout` tool with { post_id, elementor_data }. It sets _elementor_edit_mode='builder' and clears CSS cache for you.",
              "4. If anything breaks, call `read_wp_debug_log` and self-correct.",
              "",
              `The user's task: ${task}`,
            ].join("\n"),
          },
        },
      ],
    }),
  );
}
