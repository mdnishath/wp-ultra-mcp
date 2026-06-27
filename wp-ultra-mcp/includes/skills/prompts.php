<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }
require_once __DIR__ . '/sources.php';
add_action('wp_abilities_api_init', function () {
    if (!function_exists('wp_register_ability')) { return; }
    foreach (wpultra_skill_all() as $slug => $s) {
        if (empty($s['enable_prompt']) || empty($s['body'])) { continue; }
        $body = (string) $s['body'];
        wp_register_ability('wpultra/skill-prompt-' . $slug, [
            'label' => 'Skill: ' . $slug,
            'description' => (string) ($s['description'] ?? $slug),
            'category' => 'skills',
            'input_schema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
            'execute_callback' => function () use ($body) { return ['messages' => [['role' => 'user', 'content' => ['type' => 'text', 'text' => $body]]]]; },
            'permission_callback' => 'wpultra_permission_callback',
            'meta' => ['mcp' => ['public' => true, 'type' => 'prompt']],
        ]);
    }
}, 500);
