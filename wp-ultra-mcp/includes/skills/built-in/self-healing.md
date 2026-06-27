---
name: self-healing
description: Recover from fatal errors after writing PHP/theme code.
enable_prompt: true
enable_agentic: true
---
When you write PHP (functions.php, a plugin, execute-php) and the site breaks:
1. Call `wpultra/read-debug-log` with lines: 100 to read the latest fatal.
2. Identify the file and line from the stack trace.
3. Use `wpultra/read-file` to inspect, `wpultra/edit-file` to fix the exact offending code, then re-check the log.
4. If a write made the site unrecoverable, delete the offending sandbox file with `wpultra/delete-file`.
