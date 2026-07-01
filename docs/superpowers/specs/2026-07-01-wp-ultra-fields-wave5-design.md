# WP-Ultra-MCP — Wave 5: Custom Fields (ACF + Meta Box + Pods)

**Date:** 2026-07-01
**Status:** Design approved, pending spec review
**Ships as:** v0.12.0 (96 → ~112 abilities)
**Branch:** `feat/fields-wave5`

## Goal

Let a normal user build and control a WordPress **custom-field / content-model layer** entirely through AI — define custom fields, read/write field values, and register custom post types & taxonomies — across the three most common free field frameworks: **Advanced Custom Fields (ACF)**, **Meta Box**, and **Pods**.

This is the field-plugin pillar of "do everything in WordPress via AI," and a direct answer to Novamira Pro's 6 field integrations. Our moat vs. Novamira Pro: **free**, **live-verified**, and wp-ultra's unique ability to **write PHP files + run WP-CLI** (lets us handle Meta Box's code-registered field groups, which have no DB storage).

## Scope decisions (locked in brainstorm)

- **In:** ACF (free edition, live-verified) · Meta Box (free, live-verified) · Pods (fully free, live-verified).
- **Out:** **JetEngine** — Crocoblock paid-only, cannot be installed/live-tested without a license. Deferred to a future wave when a license is available. (ACPT/ASE from Novamira Pro's set are also out of scope.)
- **ACF Pro-only paths** (repeater/flexible_content/gallery/clone field types; ACF-managed CPT/taxonomy/options-page registration, which require ACF Pro 6.5+): **code them, but mark `pro_untested`** — they cannot be live-verified on the free edition. Everything testable on the free editions **must** be live-verified (project moat).
- **Architecture:** **Hybrid** — unified `field-*` abilities that auto-route to the active provider for the operations that normalize cleanly (status, read/write values, list/get group, CPT/taxonomy/options-page management); **per-plugin native** abilities only for **field-group definition**, because the three plugins' group storage models genuinely diverge.

## Architecture — hybrid driver

Direct descendant of the Wave 7 SEO hybrid driver (`wpultra_seo_mode()` routing one canonical field-set to Yoast/RankMath/native). Here, `wpultra_fields_providers()` detects which field plugins are active and each unified ability routes to that provider's adapter.

```
includes/fields/
  setup.php          — provider detection + edition + capability matrix;
                       register `fields` ability category;
                       front-end loader wpultra_load_fields_frontend() on init pri 1
                       (ability engine-loop only runs in REST context — same pattern as SEO)
  driver.php         — wpultra_fields_providers(): [{provider, edition, version, caps}]
                       wpultra_fields_route($op, $provider|auto, $args) → adapter dispatch
  adapters/
    acf.php          — get_field/update_field, acf_validate_value; field groups via
                       acf_get_field_groups / acf_import_field_group; acf-post-type CPT (Pro 6.5+)
    metabox.php      — rwmb_meta / rwmb_get_value / update; groups: generate a PHP snippet
                       registering via the `rwmb_meta_boxes` filter into a managed mu-plugin file
                       (Meta Box free stores no groups in DB) — the wp-ultra-only differentiator
    pods.php         — pods()->field / ->save; pods_api()->save_pod / save_field / load_pod
  values.php         — PURE normalize + validate a write batch (atomic value vs. complex
                       consent-wrapper {value, mode:"replace"}); provider-agnostic canonical shape
  cpt.php            — PURE content-model plan builder (canonical CPT/taxonomy args →
                       per-provider registrar instructions)
```

Adapters expose a common internal contract (`read($target,$fields)`, `write($target,$values)`, `list_groups()`, `get_group($key)`, `manage_cpt($plan)`, `manage_taxonomy($plan)`, `manage_options_page($plan)`). Adding JetEngine later = one new adapter file, no ability changes.

### Provider selection

- `field-status` reports every active provider so the AI can see the landscape.
- Unified read/write/list abilities accept an optional `provider` arg. When omitted (`auto`): **read/list** merge across all active providers (namespaced by provider in the result); **write** requires either a single active provider or an explicit `provider` (reject ambiguous writes rather than guess).
- Native `*-define-field-group` abilities are provider-explicit by name.

## Ability surface (~16)

### Unified `field-*` (auto-route)

1. **`field-status`** — active providers, edition (free/pro), version, capability matrix (can-manage-cpt / taxonomy / options-page / complex-types), and counts of groups/CPTs per provider. Call first. `readonly`, idempotent.
2. **`field-read-values`** — read field values from a target `{type: post|user|term|options, id}`. `format_values` (default true) vs. raw; `only_with_values`; optional `fields[]`. Returns `{provider, target, values}`. `readonly`.
3. **`field-write-values`** — batch write to a target. Atomic types take the value directly; complex types (repeater/group/gallery/etc.) require `{value, mode:"replace"}` consent wrapper. Pre-flight validate whole batch; **no partial writes**. Audit-logged. `destructive`.
4. **`field-list-groups`** — list field groups across active providers (optional `provider` filter). Returns key/title/provider/field-count/location.
5. **`field-get-group`** — full schema of one group (fields with type/name/key/sub-fields, location rules). Needed before writing complex values.
6. **`field-list-cpts`** — CPTs registered by field providers.
7. **`field-manage-cpt`** — create/update/delete a CPT via the provider's registrar (ACF `acf-post-type` [Pro], Pods `save_pod`, Meta Box snippet). `destructive`.
8. **`field-list-taxonomies`**
9. **`field-manage-taxonomy`** — create/update/delete a taxonomy via provider. `destructive`.
10. **`field-list-options-pages`**
11. **`field-manage-options-page`** — ACF Pro / Meta Box / Pods options container. `destructive`.

### Per-plugin native field-group definition (formats diverge)

12. **`acf-define-field-group`** — accepts ACF native export payload (`{key?, title, fields[], location[][], ...}`); create or update; missing keys auto-generated; Pro-only field types rejected on free with a clear `pro_untested`/`pro_required` note.
13. **`metabox-define-field-group`** — accepts a Meta Box field-group config; **generates a PHP snippet** registering it via `rwmb_meta_boxes` and writes it to a jailed managed mu-plugin file (sandbox-safe); returns the file path + registered meta-box id.
14. **`pods-define-fields`** — define/extend a Pod's fields via `pods_api()->save_pod` / `save_field`.

*(Delete/edit of a group handled within these `define` abilities via a `mode`/`delete` flag, matching how wp-ultra folds CRUD into single "manage" abilities.)*

Final count depends on Plan 4 bridge abilities (below); target ~16 in the `fields` category + up to 2 bridge abilities.

## Data flow — write example

```
AI → field-write-values { target:{type:post,id:42}, values:{ subtitle:"Hi",
                          features:{ value:[...], mode:"replace" } }, provider?:"acf" }
   → driver: resolve provider (explicit or single active)
   → adapter.validate(batch) against provider field schema
        ACF: acf_validate_value per field ; MB: field sanitize ; Pods: field type coerce
   → if any field invalid → reject whole batch (no partial write)
   → adapter.write() (update_field / rwmb update / pods save)
   → audit log { ability, target, fields, provider }
   → return per-field results { field, status, previous_length?, new_length? }
```

Same canonical envelope regardless of provider. Read is the mirror (merge across providers when `auto`).

## Safety (reuse existing patterns)

- All mutating abilities: `manage_options` permission gate + activity/audit log (Wave 1.5).
- **Reserved-CPT write guard** (Gutenberg wave) applied to `field-manage-cpt` / value writes to core types.
- **Complex-type consent wrapper** prevents silent whole-array replacement.
- Meta Box generated snippet: written only inside the jailed managed path; respects sandbox `.crashed` safe-mode (won't write PHP when AI-PHP is suspended); the file is a normal mu-plugin so a bad snippet is removable, and we `php -l`-lint the generated snippet before writing.
- Options-page / CPT registration bounded to whitelisted arg keys (no arbitrary option writes), mirroring Woo `update-settings` whitelist discipline.
- `field-write-values` writes via the provider's own update function (`update_field` etc.) so provider hooks fire correctly; documented that `acf/save_post` does not fire (no admin submission), same caveat Novamira Pro documents.

## Testing — install-first, then live-verify

- **Plan 1 Task 1** installs ACF (free), Meta Box (free), Pods (free) from wordpress.org into the Local test site's `wp-content/plugins/` and activates them (via WP-CLI `wp plugin install` if available in Local, else drop the unzipped zip + `wp plugin activate`, else activate through a token-gated PHP probe). Record versions in the SDD ledger + memory.
- Pure helpers (`values.php` normalize/validate, `cpt.php` plan builder, MB snippet generator) → zero-dep PHP harness unit tests (`tests/fields-*.test.php`), run via `tests/run-all.ps1`.
- Runtime paths → **live-verified** with the token-gated PHP probe pattern (`wp-content/wpultra-*.php` → curl → delete): for each provider, a full **write → read round-trip**, a **define-group → group appears** check, and a **register-CPT → CPT is queryable** check.
- **Never nest a second `wp_remote_get` to the same Local site** (worker deadlock → sandbox `.crashed`). Use separate top-level curls.
- ACF Pro-only branches: code + mark `pro_untested`; note in the ledger that they are unverified.

## Plan decomposition (one spec → 4 plans, ships v0.12.0)

- **Plan 1 — Foundation + values (crown jewels).** Install 3 plugins; `includes/fields/{setup,driver,values}.php` + acf/metabox/pods adapter skeletons; abilities `field-status`, `field-read-values`, `field-write-values`. Live-verify write→read round-trip on all 3 providers. New `fields` category + front-end loader.
- **Plan 2 — Field groups.** `field-list-groups`, `field-get-group` (unified) + `acf-define-field-group`, `metabox-define-field-group` (snippet generator + `php -l` gate), `pods-define-fields` (native). Live-verify define→appears→read values back.
- **Plan 3 — Content model.** `field-list-cpts` / `field-manage-cpt`, `field-list-taxonomies` / `field-manage-taxonomy`, `field-list-options-pages` / `field-manage-options-page` via the pure `cpt.php` plan builder routed per provider. Reserved-type guard. Live-verify register→queryable (Pods + Meta Box snippet; ACF CPT = Pro, mark untested).
- **Plan 4 — Skill + builder bridge + ship.** `fields-architect` built-in skill (auto-glob) encoding the driver + validated write loop + per-provider group formats; **dynamic-field → builder bridge**: surface custom fields inside Elementor (ACF registers Elementor dynamic tags; expose read of available dynamic tags + a helper to bind a field to an atomic widget) and/or a Gutenberg dynamic-value note; version bump to v0.12.0 (all 3 spots + CHANGELOG + README section); full Wave-5 review → finishing (merge ff → main, push, build zip, `gh release v0.12.0`, deploy).

## Open items to resolve during plan/spec-research

- Exact free-edition capability boundaries per provider (which CPT/taxonomy/options-page ops are free vs. paid in **Meta Box** and **Pods** specifically — ACF's are known Pro 6.5+). Confirm in Plan 1/3 research, not blocking the architecture.
- Meta Box free field-group storage: confirmed no DB storage in core → snippet approach is the plan; verify `rwmb_meta_boxes` filter registration path on the installed version.
- Whether `field-manage-taxonomy` and options-pages warrant splitting Plan 3 if surface grows.

## References

- Competitor: Novamira Pro `C:\Users\nisha\OneDrive\Desktop\novamirapro112\novamira-pro\includes\abilities\{acf,jetengine,metabox,pods,acpt,ase}` — ~13-16 abilities each; per-plugin namespaced. We diverge to hybrid + free + live-verified + PHP-snippet trick.
- Prior in-repo precedent for the hybrid driver: Wave 7 SEO (`includes/seo/`), `wpultra_seo_mode()`.
- Ability authoring rules: `wp-ultra-mcp/includes/abilities/read-file.php` (canonical shape); categories in `wpultra_register_categories()`.
