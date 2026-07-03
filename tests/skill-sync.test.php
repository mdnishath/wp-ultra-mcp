<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
if (!defined('WPULTRA_DIR')) { define('WPULTRA_DIR', __DIR__ . '/../wp-ultra-mcp/'); }
if (!function_exists('sanitize_title')) {
    function sanitize_title($s) {
        $s = strtolower((string) $s);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);
        return trim((string) $s, '-');
    }
}
require __DIR__ . '/../wp-ultra-mcp/includes/skills/parser.php';
require __DIR__ . '/../wp-ultra-mcp/includes/skills/sync.php';

// ---- validate_doc ----

it('validate_doc accepts good markdown with frontmatter', function () {
    $md = "---\nname: my-skill\ndescription: Does X\n---\nBody content here.";
    assert_eq(true, wpultra_skillsync_validate_doc($md));
});

it('validate_doc accepts good markdown starting with a heading', function () {
    $md = "# My Skill\n\nSome body text.";
    assert_eq(true, wpultra_skillsync_validate_doc($md));
});

it('validate_doc rejects empty document', function () {
    $result = wpultra_skillsync_validate_doc('');
    assert_true(is_string($result), 'empty doc should return an error string');
});

it('validate_doc rejects whitespace-only document', function () {
    $result = wpultra_skillsync_validate_doc("   \n\t  ");
    assert_true(is_string($result), 'whitespace-only doc should return an error string');
});

it('validate_doc rejects a document containing a PHP open tag', function () {
    $md = "# Heading\n\n<?php echo 'hi'; ?>";
    $result = wpultra_skillsync_validate_doc($md);
    assert_true(is_string($result), 'php tag should be rejected');
    assert_contains('PHP', $result);
});

it('validate_doc rejects oversize document', function () {
    $md = "# Heading\n" . str_repeat('a', WPULTRA_SKILLSYNC_MAX_BYTES + 100);
    $result = wpultra_skillsync_validate_doc($md);
    assert_true(is_string($result), 'oversize doc should return an error string');
    assert_contains('byte cap', $result . '');
});

it('validate_doc rejects a document without frontmatter or heading', function () {
    $result = wpultra_skillsync_validate_doc('just some plain text, no marker');
    assert_true(is_string($result), 'doc without --- or # should be rejected');
});

// ---- slug ----

it('slug strips .md extension and sanitizes', function () {
    assert_eq('my-skill', wpultra_skillsync_slug('my-skill.md'));
});

it('slug handles mixed case extension', function () {
    assert_eq('another-skill', wpultra_skillsync_slug('Another-Skill.MD'));
});

it('slug strips directory components', function () {
    assert_eq('nested', wpultra_skillsync_slug('folder/sub/nested.md'));
});

it('slug sanitizes spaces and special chars', function () {
    assert_eq('my-cool-skill', wpultra_skillsync_slug('My Cool Skill!.md'));
});

// ---- plan ----

it('plan marks a brand new slug as to_import', function () {
    $remote = [['slug' => 'brand-new', 'name' => 'brand-new.md']];
    $result = wpultra_skillsync_plan($remote, [], [], false);
    assert_eq(1, count($result['to_import']));
    assert_eq(0, count($result['to_skip']));
    assert_eq('brand-new', $result['to_import'][0]['slug']);
});

it('plan skips an existing slug when overwrite is false', function () {
    $remote = [['slug' => 'already-here', 'name' => 'already-here.md']];
    $result = wpultra_skillsync_plan($remote, ['already-here'], [], false);
    assert_eq(0, count($result['to_import']));
    assert_eq(1, count($result['to_skip']));
    assert_eq('exists', $result['to_skip'][0]['status']);
});

it('plan imports an existing slug when overwrite is true', function () {
    $remote = [['slug' => 'already-here', 'name' => 'already-here.md']];
    $result = wpultra_skillsync_plan($remote, ['already-here'], [], true);
    assert_eq(1, count($result['to_import']));
    assert_eq(0, count($result['to_skip']));
});

it('plan applies an only-slugs filter', function () {
    $remote = [
        ['slug' => 'wanted', 'name' => 'wanted.md'],
        ['slug' => 'unwanted', 'name' => 'unwanted.md'],
    ];
    $result = wpultra_skillsync_plan($remote, [], ['wanted'], false);
    assert_eq(1, count($result['to_import']));
    assert_eq('wanted', $result['to_import'][0]['slug']);
    assert_eq(1, count($result['to_skip']));
    assert_eq('unwanted', $result['to_skip'][0]['slug']);
    assert_eq('not_in_only', $result['to_skip'][0]['status']);
});

it('plan combines only-filter and existing-skip correctly', function () {
    $remote = [
        ['slug' => 'a', 'name' => 'a.md'],
        ['slug' => 'b', 'name' => 'b.md'],
        ['slug' => 'c', 'name' => 'c.md'],
    ];
    // only a,b requested; b already exists locally and overwrite is false
    $result = wpultra_skillsync_plan($remote, ['b'], ['a', 'b'], false);
    $import_slugs = array_map(fn($e) => $e['slug'], $result['to_import']);
    $skip_slugs   = array_map(fn($e) => $e['slug'], $result['to_skip']);
    assert_eq(['a'], $import_slugs);
    sort($skip_slugs);
    assert_eq(['b', 'c'], $skip_slugs);
});

it('plan with empty remote list produces empty results', function () {
    $result = wpultra_skillsync_plan([], ['x'], [], false);
    assert_eq(0, count($result['to_import']));
    assert_eq(0, count($result['to_skip']));
});

run_tests();
