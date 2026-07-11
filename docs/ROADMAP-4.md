# WP-Ultra-MCP — Roadmap 4 (Bug Fixer + Pixel-Perfect Design)

> **Status: PLANNED** (from deep-scan 2026-07-11, v0.28.1 / 286 abilities).
> Two new pillars: **(1) WP Bug Fixer** — automated diagnosis + remediation (today the plugin is strong on observability, weak on automated fixing); **(2) Pixel-Perfect Design** — responsive control, states, per-element CSS, pixel-diff (today tokens/classes are strong, but the "pixel" tooling is missing).

Roadmaps 1+2+3 complete (286 abilities). This roadmap closes the gaps found in the 2026-07-11 deep-scan.

## The thesis

- [x] **Bug Fixer**: "amar site venge geche" is the #1 client call. One MCP command that finds AND fixes the problem (conflict bisect, permalink fix, DB repair, debug toggle) is directly sellable as a service. All building blocks (render-page probe, error-reports, manage-plugin-theme, wp-config define editor) already exist — only orchestrators are missing.
- [x] **Pixel-Perfect**: the design loop (perceive → build → render-check → screenshot → refine) exists, but the plugin cannot control responsive values, hover states, or per-element CSS, and cannot measure a pixel gap. Close those and "pixel-perfect clone" becomes a true claim.

**Demand:** 🔥🔥🔥 huge · 🔥🔥 strong · 🔥 solid niche.

---

## 🐛 Wave BF1 — Bug Fixer core (the sellable pillar)

- [x] **BF1.1 · debug-mode** 🔥🔥🔥 — safely toggle `WP_DEBUG` / `WP_DEBUG_LOG` / `WP_DEBUG_DISPLAY` / `SCRIPT_DEBUG` / `SAVEQUERIES` in wp-config. Reuse the existing wp-config `define()` editor in `includes/system/security.php` (`wpultra_security_insert_define` — currently wired only to `DISALLOW_FILE_EDIT`). wp-config backed up before every write; status action reports current values. Confirm-gated.
- [x] **BF1.2 · conflict-bisect** 🔥🔥🔥 — automated plugin-conflict hunt: snapshot active plugin set → deactivate all → binary-search re-enable, probing with `render-page` (WSOD/fatal markers) + `error-reports` after each step → report culprit → restore original state (always, even on abort). Optional `theme_check: true` to also swap to a default theme. **The killer bug-fixing ability.**
- [x] **BF1.3 · fix-permalinks** 🔥🔥🔥 — `flush_rewrite_rules()` + regenerate WP's own `.htaccess` rewrite block (via `save_mod_rewrite_rules`) + verify with a `render-page` probe on a known post. Fixes the classic "every page is 404" breakage. `manage-server-rules` deliberately avoids the WP block — this ability owns it.
- [x] **BF1.4 · repair-database** 🔥🔥 — first-class DB repair: `CHECK TABLE` all prefixed tables → `REPAIR TABLE` the broken ones → `dbDelta` core-schema repair option → report per-table status. Auto `db-snapshot` before any repair. Confirm-gated.
- [x] **BF1.5 · php-env-info** 🔥🔥 — one-call environment report: PHP/MySQL versions, memory_limit, max_execution_time, upload limits, loaded extensions (curl/gd/imagick/zip/mbstring…), OPcache state, disk free, server software. Read-only. First step of every hosting-issue diagnosis.
- [x] **BF1.6 · safe-mode-manage** 🔥🔥 — surface sandbox safe-mode as an ability: status / clear the `.crashed` sentinel (confirm-gated) / arm. Today safe-mode can only be cleared in wp-admin, so an AI that trips it is stuck — this unblocks the loop. Guard: clear requires the fatal's cause to be named (from `error-reports`) so it isn't cleared blindly.

## 🩹 Wave BF2 — Bug Fixer reach (deeper diagnosis)

- [x] **BF2.1 · auto-recover** 🔥🔥 — on self-captured fatal (`error-reports` shutdown handler): auto-deactivate the offending plugin (parsed from the error file path) or auto-revert the last undo-ring entry; leverage WP's native recovery-mode/pause-plugin mechanism. Opt-in via config; every action logged to activity-log + reversible.
- [x] **BF2.2 · query-profiler** 🔥🔥 — `SAVEQUERIES`-based profiling: run one request (URL or post) with query capture → top-N slowest queries, total query count/time, duplicate-query detection (Query-Monitor-lite). Auto-disables SAVEQUERIES after the run.
- [x] **BF2.3 · rest-probe** 🔥 — invoke an arbitrary REST route (method, params, auth as current app-password user) and return status/headers/body; complements `list-registry rest-routes` which can only list. The REST twin of `graphql-query`.
- [x] **BF2.4 · js-error-log** 🔥🔥 — front-end error capture without a headless browser: enqueue a tiny `window.onerror`/`unhandledrejection` logger snippet → POST to a REST beacon → ring buffer read/cleared via the ability (same pattern as `error-reports` + the marketing `/track` beacon). Enable/disable per-config; captures the client-side errors `render-page` can never see.
- [x] **BF2.5 · plugin-checksum-verify** 🔥 — extend `security-scan`: verify plugin files against wp.org plugin-repo checksums (downloads.wordpress.org checksum API), report modified/unknown files per plugin. Core-only today.
- [x] **BF2.6 · undo coverage extension** 🔥🔥 — capture BEFORE-state in the undo ring for: file edits (write/edit/delete-file), plugin/theme activate/deactivate, `execute-wp-query` destructive statements (snapshot affected rows where feasible). Today these are irreversible through undo.

## 📐 Wave PP1 — Pixel-Perfect core (responsive + states + per-element CSS)

- [x] **PP1.1 · elementor-set-responsive** 🔥🔥🔥 — first-class device-specific styling: set/read per-element style values per breakpoint (desktop/tablet/mobile + custom), manage kit breakpoints. Today `includes/elementor/classes.php` hardcodes `'breakpoint' => null` and no ability targets tablet/mobile values — **the single biggest pixel-perfect gap.**
- [x] **PP1.2 · state variants (hover/focus/active)** 🔥🔥🔥 — extend `elementor-upsert-global-class` (and element-level where the schema allows) to write state variants; `meta.state` is hardcoded `null` today. Button hover color is currently impossible — table stakes for any real design.
- [x] **PP1.3 · element-custom-css** 🔥🔥 — surface per-element raw Custom CSS (Elementor Pro's per-element CSS field; graceful error without Pro) + a free-path fallback that routes scoped CSS through a generated global class. Ends the "wrap everything in a global class" workaround.
- [x] **PP1.4 · pixel-diff** 🔥🔥🔥 — upgrade `visual-diff`: accept two client-captured screenshots (URL/base64), compare server-side with GD/Imagick → mismatch %, bounding boxes of changed regions, and a diff-heatmap image saved to uploads. GD is always available in WP; no headless browser needed — the AI supplies the pixels, the server does the math. **Closes the "is it actually pixel-perfect?" loop with a number instead of eyeballs.**
- [x] **PP1.5 · inspect-element** 🔥🔥 — CSS readback without a browser: given a post + element id, parse the generated Elementor CSS (`_elementor_css` / css files) + global classes + kit variables and return the **resolved declared styles** for that element (what CSS will actually ship), flagging token vs hardcoded values. Not getComputedStyle, but enough to measure and close most gaps server-side.

## 🎨 Wave PP2 — Pixel-Perfect reach (fonts + audit + parity)

- [x] **PP2.1 · manage-fonts** 🔥🔥 — upload font files → register `@font-face` (Elementor Custom Fonts API when available, else custom-css fallback), list/delete; optional Google-Fonts helper (enqueue or download-and-self-host for GDPR). Fonts are half of pixel-perfect; today only token *names* exist.
- [x] **PP2.2 · design-audit** 🔥 — page-wide consistency report: every element's spacing/typography/color settings, hardcoded-value vs token usage %, off-scale spacing values, contrast warnings. The "why does it look almost right?" detector.
- [x] **PP2.3 · gutenberg/bricks token parity** 🔥 — `apply-design-tokens` counterparts: Gutenberg (mint into theme.json user layer via existing `theme-json-set` engine) and Bricks (global colors/variables). Elementor-only today.

---

## Build order

**BF1 first** (BF1.1 → BF1.3 → BF1.2 → BF1.5 → BF1.4 → BF1.6) — cheapest wins first, and the pillar is directly sellable to clients. Then **PP1** (PP1.1 responsive is the headline; PP1.4 pixel-diff makes the claim provable). BF2 and PP2 follow demand.

## Architecture notes

- [ ] **Reuse over new code:** BF1.1 reuses the wp-config define editor (`system/security.php`); BF1.2 orchestrates `manage-plugin-theme` + `render-page` + `error-reports`; BF1.4 wraps `$wpdb` + `db-snapshot`; BF2.4 clones the `error-reports` ring + `/track` beacon pattern; PP1.4 uses GD/Imagick already shipped with WP; PP1.5 parses CSS the plugin already triggers Elementor to generate.
- [ ] **Safety pattern unchanged:** destructive = confirm-gated; every mutation → activity-log; snapshots/backups before repair; bisect always restores original plugin state; auto-recover opt-in + reversible.
- [ ] **No headless browser — still.** Perception (screenshots) stays with the calling AI; the server measures (pixel-diff), parses (inspect-element), and mutates. Same moat architecture as Roadmaps 1–3.
- [ ] **Categories:** BF waves → existing `diagnostics`/`system` (or new `bugfix`); PP waves → existing `elementor`/`builders`.
