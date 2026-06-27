export interface ToolResult {
  content: { type: "text"; text: string }[];
  isError?: boolean;
}
