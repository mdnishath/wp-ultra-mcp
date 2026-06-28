<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

function wpultra_sandbox_dir(): string { return defined('WPULTRA_SANDBOX_DIR') ? WPULTRA_SANDBOX_DIR : (WP_CONTENT_DIR . '/wpultra-sandbox/'); }
function wpultra_sandbox_sentinel(): string { return rtrim(wpultra_sandbox_dir(), '/\\') . '/.crashed'; }
function wpultra_sandbox_crashed(): bool { return file_exists(wpultra_sandbox_sentinel()); }

function wpultra_sandbox_mark_crashed(string $detail): void {
    $dir = wpultra_sandbox_dir();
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    @file_put_contents(wpultra_sandbox_sentinel(), $detail);
}

function wpultra_sandbox_clear(): void { @unlink(wpultra_sandbox_sentinel()); }

/** Run $fn; if it triggers a fatal, a registered shutdown handler records .crashed. */
function wpultra_sandbox_guard(callable $fn) {
    $GLOBALS['__wpultra_sb_running'] = true;
    register_shutdown_function(function () {
        $e = error_get_last();
        if (!empty($GLOBALS['__wpultra_sb_running']) && $e && in_array($e['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR], true)) {
            wpultra_sandbox_mark_crashed($e['message'] . ' @ ' . ($e['file'] ?? '?') . ':' . ($e['line'] ?? '?'));
        }
    });
    try { return $fn(); }
    finally { $GLOBALS['__wpultra_sb_running'] = false; }
}
