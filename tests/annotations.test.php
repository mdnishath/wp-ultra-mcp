<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';

/**
 * Static invariants over the 305 ability registration files. These scan source
 * text (no WordPress) so they run in the zero-dependency harness and guard
 * against annotation drift as abilities are added.
 */

$DIR = __DIR__ . '/../wp-ultra-mcp/includes/abilities';

/** @return array<string,string> file => full source */
function wpultra_ability_sources(string $dir): array {
    $out = [];
    foreach (glob($dir . '/*.php') as $f) { $out[basename($f)] = (string) file_get_contents($f); }
    return $out;
}

function wpultra_test_is_confirm_gated(string $s): bool {
    if (strpos($s, 'wpultra_require_confirm(') !== false) { return true; }
    if (strpos($s, "confirm'] ?? false) !== true") !== false) { return true; }
    if (strpos($s, "empty(\$input['confirm'])") !== false) { return true; }
    return (bool) preg_match("/\\\$input\\['confirm'\\][^\\n;]*\\)\\s*!==?\\s*true/", $s);
}

function wpultra_test_destructive(string $s): ?string {
    if (preg_match("/'destructive'\\s*=>\\s*(true|false)/", $s, $m)) { return $m[1]; }
    return null;
}

it('every ability file registers exactly one ability with an annotations block', function () use ($DIR) {
    foreach (wpultra_ability_sources($DIR) as $f => $s) {
        assert_eq(1, substr_count($s, 'wp_register_ability('), "$f: one registration");
        assert_true(strpos($s, "'annotations'") !== false, "$f: has annotations");
        assert_true(wpultra_test_destructive($s) !== null, "$f: declares destructive");
    }
});

it('confirm-gated abilities are annotated destructive:true (safety invariant)', function () use ($DIR) {
    $violations = [];
    foreach (wpultra_ability_sources($DIR) as $f => $s) {
        if (wpultra_test_is_confirm_gated($s) && wpultra_test_destructive($s) !== 'true') {
            $violations[] = $f;
        }
    }
    assert_eq([], $violations, 'confirm-gated but not destructive:true: ' . implode(', ', $violations));
});

it('every ability wires a permission_callback (no open endpoints)', function () use ($DIR) {
    $missing = [];
    foreach (wpultra_ability_sources($DIR) as $f => $s) {
        if (strpos($s, "'permission_callback'") === false) { $missing[] = $f; continue; }
        // Must never be publicly open.
        if (preg_match("/'permission_callback'\\s*=>\\s*'__return_true'/", $s)) { $missing[] = $f . ' (__return_true!)'; }
    }
    assert_eq([], $missing, 'abilities without a real permission_callback: ' . implode(', ', $missing));
});

it('known-privileged abilities use the shared or a stricter permission callback', function () use ($DIR) {
    // These must resolve to admin-or-granted (wpultra_permission_callback) or a
    // deliberately stricter callback — never a looser custom one.
    $allowed = ['wpultra_permission_callback', 'wpultra_access_admin_only', 'wpultra_multisite_manage_permission'];
    foreach (['execute-php.php', 'run-wp-cli.php', 'execute-wp-query.php', 'write-file.php', 'delete-file.php', 'manage-plugin-theme.php', 'manage-user.php'] as $f) {
        $s = (string) file_get_contents($DIR . '/' . $f);
        preg_match("/'permission_callback'\\s*=>\\s*'([a-z_]+)'/", $s, $m);
        assert_true(in_array($m[1] ?? '', $allowed, true), "$f uses an approved permission callback (got: " . ($m[1] ?? 'none') . ')');
    }
});

it('the builder add/insert-element family agrees that adding is non-destructive', function () use ($DIR) {
    foreach (['elementor-add-element.php', 'bricks-add-element.php', 'gutenberg-insert-block.php'] as $f) {
        $s = (string) file_get_contents($DIR . '/' . $f);
        assert_eq('false', wpultra_test_destructive($s), "$f: add/insert is additive → destructive:false");
    }
});

run_tests();
