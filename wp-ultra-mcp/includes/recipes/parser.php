<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/** Parse a declarative-ability recipe document. Returns normalized array or WP_Error. */
function wpultra_recipe_parse(string $raw) {
    $name = ''; $description = ''; $category = 'custom'; $run = '';
    $body = $raw;
    if (preg_match('/^\s*---\s*\n(.*?)\n---\s*\n?(.*)$/s', $raw, $m)) {
        $body = $m[2];
        foreach (explode("\n", $m[1]) as $line) {
            if (!preg_match('/^\s*([A-Za-z_]+)\s*:\s*(.*)$/', $line, $kv)) { continue; }
            $key = strtolower($kv[1]); $val = trim($kv[2]);
            if ($key === 'name') { $name = $val; }
            elseif ($key === 'description') { $description = $val; }
            elseif ($key === 'category') { $category = $val !== '' ? $val : 'custom'; }
            elseif ($key === 'run') { $run = strtolower($val); }
        }
    }
    // Extract the LAST ```json fenced block — so an illustrative example earlier in the prose
    // doesn't get executed instead of the real payload (which conventionally comes last).
    $structured = [];
    if (preg_match_all('/```json\s*\n(.*?)\n```/s', $body, $jm) && !empty($jm[1])) {
        $decoded = json_decode(trim((string) end($jm[1])), true);
        if (!is_array($decoded)) {
            return wpultra_err('recipe_bad_json', 'The ```json recipe block is not valid JSON.');
        }
        $structured = $decoded;
    }
    $input = is_array($structured['input'] ?? null) ? $structured['input'] : [];
    $recipe = $structured;
    unset($recipe['input']);
    return [
        'name' => $name, 'description' => $description, 'category' => $category, 'run' => $run,
        'input' => $input, 'recipe' => $recipe,
    ];
}

function wpultra_recipe_validate(array $r) {
    if (!preg_match('/^[a-z0-9-]+$/', (string) ($r['name'] ?? ''))) {
        return wpultra_err('recipe_bad_name', 'Recipe name must be lowercase letters, digits, and dashes.');
    }
    $run = (string) ($r['run'] ?? '');
    if (!in_array($run, ['wp-cli', 'sql', 'php', 'http'], true)) {
        return wpultra_err('recipe_bad_run', "run must be one of: wp-cli, sql, php, http.");
    }
    $recipe = is_array($r['recipe'] ?? null) ? $r['recipe'] : [];
    if ($run === 'wp-cli' && !is_array($recipe['command'] ?? null)) {
        return wpultra_err('recipe_missing_command', "wp-cli recipes require a 'command' array.");
    }
    if ($run === 'sql' && !is_string($recipe['query'] ?? null)) {
        return wpultra_err('recipe_missing_query', "sql recipes require a 'query' string.");
    }
    if ($run === 'php' && !is_string($recipe['code'] ?? null)) {
        return wpultra_err('recipe_missing_code', "php recipes require a 'code' string.");
    }
    if ($run === 'http' && !is_string($recipe['url'] ?? null)) {
        return wpultra_err('recipe_missing_url', "http recipes require a 'url' string.");
    }
    if (!is_array($r['input'] ?? null)) {
        return wpultra_err('recipe_bad_input', "'input' must be an object of parameter definitions.");
    }
    return true;
}
