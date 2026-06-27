<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/skills/parser.php';

it('parses frontmatter and body', function () {
    $md = "---\nname: my-skill\ndescription: Does X\nenable_prompt: false\n---\nBody line 1\nBody line 2";
    $s = wpultra_skill_parse_frontmatter($md);
    assert_eq('my-skill', $s['name']);
    assert_eq('Does X', $s['description']);
    assert_eq(false, $s['enable_prompt']);
    assert_eq(true, $s['enable_agentic'], 'defaults true');
    assert_contains('Body line 1', $s['body']);
});
it('handles missing frontmatter gracefully', function () {
    $s = wpultra_skill_parse_frontmatter('just a body');
    assert_eq('', $s['name']);
    assert_contains('just a body', $s['body']);
});
it('render round-trips', function () {
    $md = wpultra_skill_render_md(['name' => 's', 'description' => 'd', 'enable_prompt' => true, 'enable_agentic' => true, 'body' => 'hello']);
    $s = wpultra_skill_parse_frontmatter($md);
    assert_eq('s', $s['name']);
    assert_contains('hello', $s['body']);
});

run_tests();
