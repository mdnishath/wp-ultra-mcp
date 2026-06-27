<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }
require_once __DIR__ . '/sources.php';
add_filter('wpultra_discover_abilities_instructions', function ($instructions) {
    $lines = ["## Available Skills", "Call wpultra/skill-get with a slug to load the full skill body."];
    foreach (wpultra_skill_all() as $slug => $s) {
        if (empty($s['enable_agentic'])) { continue; }
        $lines[] = "- `$slug`: " . ($s['description'] ?? '');
    }
    return trim((string) $instructions) . "\n\n" . implode("\n", $lines);
});
