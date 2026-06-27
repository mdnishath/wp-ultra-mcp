<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

function wpultra_skill_bool($v, bool $default): bool {
    if ($v === null) { return $default; }
    $v = strtolower(trim((string) $v));
    if ($v === '') { return $default; }
    return in_array($v, ['1', 'true', 'yes', 'on'], true);
}

function wpultra_skill_parse_frontmatter(string $md): array {
    $name = ''; $description = ''; $enable_prompt = null; $enable_agentic = null; $body = $md;
    if (preg_match('/^\s*---\s*\n(.*?)\n---\s*\n?(.*)$/s', $md, $m)) {
        $body = $m[2];
        foreach (explode("\n", $m[1]) as $line) {
            if (!preg_match('/^\s*([A-Za-z_]+)\s*:\s*(.*)$/', $line, $kv)) { continue; }
            $key = strtolower($kv[1]); $val = trim($kv[2]);
            if ($key === 'name') { $name = $val; }
            elseif ($key === 'description') { $description = $val; }
            elseif ($key === 'enable_prompt') { $enable_prompt = $val; }
            elseif ($key === 'enable_agentic') { $enable_agentic = $val; }
        }
    }
    return [
        'name' => $name, 'description' => $description,
        'enable_prompt' => wpultra_skill_bool($enable_prompt, true),
        'enable_agentic' => wpultra_skill_bool($enable_agentic, true),
        'body' => ltrim($body, "\n"),
    ];
}

function wpultra_skill_render_md(array $skill): string {
    $fp = ($skill['enable_prompt'] ?? true) ? 'true' : 'false';
    $fa = ($skill['enable_agentic'] ?? true) ? 'true' : 'false';
    return "---\nname: {$skill['name']}\ndescription: {$skill['description']}\nenable_prompt: $fp\nenable_agentic: $fa\n---\n" . ($skill['body'] ?? '');
}
