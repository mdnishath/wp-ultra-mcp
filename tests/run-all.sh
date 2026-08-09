#!/usr/bin/env bash
# Portable test runner (Linux/macOS CI + Git Bash). Mirrors run-all.ps1.
# Runs every tests/*.test.php with the PHP on PATH (override with $WPULTRA_PHP).
set -uo pipefail

PHP="${WPULTRA_PHP:-php}"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

fail=0
for f in "$DIR"/*.test.php; do
    echo "== $(basename "$f") =="
    if ! "$PHP" "$f"; then
        fail=$((fail + 1))
    fi
done

if [ "$fail" -gt 0 ]; then
    echo "$fail test file(s) failed" >&2
    exit 1
fi
echo "ALL TEST FILES PASSED"
