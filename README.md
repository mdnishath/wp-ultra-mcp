# WP-Ultimate-MCP

A portable, open-source MCP server that gives AI CLI clients (Claude Code, Gemini CLI, Antigravity) full control over any WordPress site: MySQL, filesystem, WP-CLI, and native Elementor layouts.

## Install
```bash
npm install && npm run build
```

## Configure
Copy `.env.example` → `.env` and fill in `WP_ROOT_PATH`, `WP_DB_*`. Or set them in your MCP client config (see `claude-config.example.json`).

## Tools
- `execute_wp_query` — parameterized SQL (SELECT/INSERT/UPDATE/DELETE/UPSERT)
- `write_wp_file` / `read_wp_file` — atomic, jailed to `WP_ROOT_PATH`
- `run_wp_cli` — any WP-CLI command, run inside the WP root
- `read_wp_debug_log` — tail `wp-content/debug.log` for self-healing
- `update_elementor_layout` — set `_elementor_data` + builder edit mode

## Resources & Prompt
- `elementor://schema` — atomic widget/container blueprint (anti-hallucination)
- `wpmcp://config` — redacted environment snapshot
- `elementor-architect` prompt — primes the model to build layouts

## Safety
Full power by default. Set `WP_MCP_SAFE_MODE=true` to gate destructive SQL/WP-CLI behind `confirm:true`. File ops are jailed to `WP_ROOT_PATH` unless `WP_MCP_ALLOW_OUTSIDE_ROOT=true`.
