# Contributing to WP-Ultra-MCP

## Running the tests

The suite is plain PHP with a zero-dependency harness (`tests/harness.php`) —
no Composer, no WordPress install required. It runs the pure/side-effect-free
half of each subsystem.

**Linux / macOS / CI / Git Bash:**

```bash
bash tests/run-all.sh
```

**Windows (PowerShell):**

```powershell
powershell -File tests/run-all.ps1
```

Both auto-detect PHP; override with the `WPULTRA_PHP` environment variable
(PowerShell: `$env:WPULTRA_PHP`). All test files must exit 0.

Run a single file directly: `php tests/<name>.test.php`.

## Before opening a PR

- `php -l` must pass on every changed file under `wp-ultra-mcp/` — a parse
  error in `includes/**` is a white screen. CI enforces this across PHP 8.0–8.3.
- Add or update a `*.test.php` for pure logic you change.
- Static analysis (PHPCS `WordPress-Extra`, PHPStan level 5) runs advisory in
  CI — configs are `phpcs.xml.dist` / `phpstan.neon.dist`. Install locally with
  `composer require --dev wp-coding-standards/wpcs szepeviktor/phpstan-wordpress`.

## Adding a new ability

- One ability per file in `wp-ultra-mcp/includes/abilities/<slug>.php`, delegating
  to an engine in the matching `includes/<domain>/` directory.
- Register the slug in **both** `wpultra_ability_files()` and
  `wpultra_ability_category_map()` (`includes/bootstrap-mcp.php`). The
  `ability_manifest` and `ability_category_map` self-test checks verify these
  stay in sync with disk.
- Every ability needs a `permission_callback` (use `wpultra_permission_callback`
  unless it must be stricter), an `output_schema`, and an `annotations` block.
- Gate destructive actions with `wpultra_require_confirm()` — confirm-gated
  abilities must be annotated `destructive: true` (enforced by
  `tests/annotations.test.php`).

## Security

See [SECURITY.md](SECURITY.md). Report vulnerabilities privately, never in a
public issue.
