# Changelog

All notable changes to WP-Ultra-MCP are recorded here. The authoritative,
user-facing changelog ships in [`wp-ultra-mcp/readme.txt`](wp-ultra-mcp/readme.txt);
this file mirrors it and tracks unreleased work.

The format is loosely based on [Keep a Changelog](https://keepachangelog.com/).

## [0.31.1]
- Fix: `wpultra_seo_strlen()` recursed infinitely (a self-call typo instead of
  `mb_strlen()`) whenever the mbstring extension was loaded — `seo-analyze-page`
  hung until OOM on virtually every production host. Caught by CI's first run.

## [0.31.0] — Roadmap 5: Hardening + Consistency + Reach (complete, 45/45)

Tracked in [`docs/ROADMAP-5.md`](docs/ROADMAP-5.md). All waves complete
(S1, S2, C1, T1, F1, F2). Ability count 305 → 311.

### Security
- Bumped minimum WordPress to **6.9** (the Abilities API is core-only from 6.9);
  older installs now show an explicit admin notice instead of silently
  registering nothing.
- DB-snapshot and backup directories are now hardened for Apache **and** IIS
  (`.htaccess` + `web.config` + `index.php`) and live under an unguessable
  per-site directory suffix, so the dumps aren't reachable on nginx either.
- The per-role access gate now **fails closed**: role grants are honoured only
  when the enforcing `wp_before_execute_ability` hook is verified to fire
  (surfaced as the `access_gate_wired` self-test check).
- `system` and `users` (and `manage-plugin-theme`, `manage-user`,
  `roles-manage`, `multisite-manage`, `site-migrate`, `staging-clone`,
  `option-set`) can no longer be delegated to a non-admin role.
- `delete-plugin` / `update-plugin` are now `confirm`-gated.
- Snapshot dumper escapes table identifiers and validates them against
  `SHOW TABLES`; `render-page` verifies SSL for external URLs; `execute-php`
  logs a hash of the snippet instead of its source.
- Added per-endpoint opt-out for the public REST routes
  (`wpultra_public_endpoints_disabled`).

### Added
- Central audit hook — every non-readonly ability execution is logged once
  (covers the ~51 write abilities that never self-logged).
- Shared helpers: `wpultra_require_confirm()`, `wpultra_paginate()`,
  `wpultra_log_throwable()`, `wpultra_manager_output_schema()`.
- Optional `limit`/`offset` pagination on the list abilities.
- Abilities admin page now lists **all 305** abilities (generated from the
  category map) with a search filter.
- `uninstall.php` with an opt-in "delete all data" toggle; deactivation clears
  all `wpultra_*` cron events.
- Translations are now loadable (`load_plugin_textdomain` + `Domain Path`).
- Test runner works cross-platform (`tests/run-all.sh`), GitHub Actions CI,
  and PHPCS/PHPStan configs.
- **Undo for content** — a `post` undo type capturing post fields + builder
  postmeta (`_elementor_data`, Bricks, Gutenberg), wired into `update-post`
  and every builder mutation engine. The single biggest safety upgrade.
- New abilities: `core-update` (WordPress core, confirm-gated), theme
  install/update/delete completing `manage-plugin-theme`, `manage-widgets`
  (classic sidebars), `manage-theme-mods`, `manage-transients`,
  `manage-app-passwords` (list/revoke), `register-block-pattern`.
- **Third-party adapters** (no new tool-count — they extend existing
  abilities):
  - Ninja Forms as a fifth forms driver (list/entries/create through NF's own
    model factory; field map reads the modern `nf3_fields` columns with a
    meta-table fallback).
  - LMS adapters for LearnDash / LifterLMS / Tutor LMS on `lms-manage`
    (`detect-plugins` + list-courses/get-course/enroll/progress via each
    plugin's own API — `ld_update_course_access`, `llms_enroll_student`,
    `tutor_utils()->do_enroll`).
  - Membership adapters for MemberPress / Paid Memberships Pro on
    `membership-manage` (list-levels / member-status / assign / remove via
    `pmpro_changeMembershipLevel` and the `MeprTransaction` manual-gateway
    pattern; remove is confirm-gated).
  - TranslatePress + Weglot on the i18n surface: `translation-status` gains a
    `third_party` coverage block, `translation-set-content` gains
    `trp_strings` (human translations written into TRP's dictionary), and
    `duplicate-to-language` explains the in-place model mismatch instead of a
    generic error.

### Changed
- Audit/stats writes are **buffered per request** and flushed once on
  shutdown — a 20-ability playbook chain does 2 option writes instead of 40;
  in-request readers flush before reading so a chain sees its own trail.
- The 16 top-level boot hooks collapsed into three ordered dispatchers
  (`wpultra_runtime_boot_early` @5 firewall-first, `wpultra_runtime_boot` @20,
  `wpultra_runtime_init` @1). The updater deliberately stays outside the
  enabled-flag gate so auto-updates work with AI control switched off.

### Fixed
- Updater no longer risks a blocking network call on the transient read path
  (admin cold-cache stalls).
- Ability Hub's default recipe example now saves successfully.
- Admin CSS/JS consolidated into `assets/` and enqueued — the Activity and Usage
  Stats screens are no longer unstyled.
- `destructive` annotations normalized (confirm-gated ⇒ destructive).
- Windows path comparison is case-insensitive (no more spurious
  `path_outside_base`).

## [0.30.1]
- Fix: unattended auto-updates never fired (updater was gated behind `is_admin()`).

## [0.30.0]
- Connect-page copy-button fix on plain-http `.local` domains.
- Security: `manage-access` can no longer delegate RCE-class
  abilities/categories to a non-admin role.
- Security: the MCP endpoint is gated to admins-or-granted-roles.
- Security: `run-wp-cli` catches `--exec` / `--require` global-flag bypass.

## [0.29.0]
- Roadmap 4: WP Bug Fixer + Pixel-Perfect Design (19 new abilities, 286 → 305).

_Earlier history: see the `== Changelog ==` section of
[`wp-ultra-mcp/readme.txt`](wp-ultra-mcp/readme.txt)._
