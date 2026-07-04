# WP-Ultra-MCP — Roadmap 3 (Headless WordPress)

> **✅ COMPLETE 2026-07-04 — all 15 items (H1.1–H3.5) built, TDD'd, and live-verified end-to-end** (ability files 271→286, new `headless` category, tests/headless.test.php = 96 green).

Roadmap 1 (35 items) shipped in v0.27.0. Roadmap 2 groups S/A/B/C/F/G live-verified (D + E remain).
**Roadmap 3 is a new pillar: turn WP into a first-class headless CMS backend, and let the AI scaffold + drive the JS frontend.**

## The thesis (the moat)

No competitor pairs an **MCP server** with **headless WordPress**. With WP-Ultra-MCP the AI can:
- [x] read the live **GraphQL schema** (introspection),
- [x] write + run real GraphQL queries against the site,
- [x] **scaffold the frontend** (Next.js or Vite) from that schema,
- [x] wire **preview + on-demand revalidation** so WP edits auto-update the static frontend.

That loop — schema → query → generated frontend → live preview — is only possible when an AI drives both ends. This is the differentiator.

## Framework stance (decided)

- [x] **Default + recommended: Next.js** (App Router, SSG/ISR, draft mode, metadata API). WP content is SEO-critical; Next.js is static-fast AND crawlable, and ISR matches WP's publish/edit model. WPGraphQL + Next.js is the most mature path.
- [x] **Also supported: Vite (React SPA)** for app-like frontends where SEO doesn't matter — member dashboards, internal tools, logged-in portals, or the lightest/fastest dev setup.
- [x] The plugin does **not** force a choice: it makes WP a great headless backend and scaffolds whichever the user picks. `headless-scaffold` takes `framework: next | vite`.

## GraphQL is mandatory

Headless WP without GraphQL is second-class. **WPGraphQL is the required foundation** (Wave H1). REST stays available as a fallback bundle, but every "wow" ability (introspection-driven scaffold, typed queries, WooGraphQL storefront, SEO meta) is built on GraphQL.

**Demand:** 🔥🔥🔥 huge · 🔥🔥 strong · 🔥 solid niche.

---

## 🧩 Wave H1 — Headless backend foundation (GraphQL core)

- [x] **H1.1 · headless-status** 🔥🔥🔥 — detect WPGraphQL / WPGraphQL-JWT / WPGraphQL-for-ACF / WooGraphQL / WPGraphQL-Smart-Cache presence + versions, permalink structure, CORS state, auth mode; report what's missing + a readiness score.
- [x] **H1.2 · headless-setup** 🔥🔥🔥 — install/activate the headless bundle (WPGraphQL + JWT auth + Smart Cache; add WPGraphQL-for-ACF when ACF present, WooGraphQL when Woo present), force pretty permalinks, configure CORS for the frontend origin(s), generate/store the JWT secret. Confirm-gated (installs plugins + writes config).
- [x] **H1.3 · graphql-introspect** 🔥🔥🔥 — return the live WPGraphQL schema (types, fields, queries, mutations; filterable to a subset so responses stay small). **This is the moat: the AI reads the real schema before writing a single query.**
- [x] **H1.4 · graphql-query** 🔥🔥🔥 — execute a GraphQL query/mutation against the site's `/graphql` endpoint (queries read-only by default; mutations confirm-gated). Returns JSON + errors. Lets the AI test + fetch, and powers every downstream ability.
- [x] **H1.5 · headless-expose** 🔥🔥 — register plugin-created CPTs/taxonomies/fields into the GraphQL schema (`show_in_graphql`), expose nav menus + site settings + theme tokens as GraphQL types, so the frontend can query everything WP-Ultra built.
- [x] **H1.6 · headless-rest-bundle** 🔥 — REST fallback for teams that don't want GraphQL: expose menus, settings, and ACF/Meta Box fields over WP core REST with a documented, stable shape.

## 🎨 Wave H2 — Frontend scaffold + preview + auth

- [x] **H2.1 · headless-scaffold** 🔥🔥🔥 — generate a starter frontend project for `framework: next | vite`. Returns the full file manifest (package.json, GraphQL client, queries for posts/pages/menus/settings, dynamic slug routes, layout, `.env` template, README) that Claude Code writes into the frontend repo. **Next.js:** App Router + SSG/ISR + draft mode + metadata API + sitemap. **Vite:** React SPA + graphql-request + router. Framework choice is the caller's; Next.js is the guided default.
- [x] **H2.2 · headless-preview** 🔥🔥🔥 — set up headless draft preview (the #1 headless pain): a preview secret, a WP-side `preview_post_link` filter pointing the editor's Preview button at the frontend's preview route with a token, and the draft-fetch query. Next.js draft-mode + Vite guarded-route recipes.
- [x] **H2.3 · headless-auth** 🔥🔥 — JWT issue/refresh helpers (WPGraphQL JWT) + the Application-Password path (already used by MCP); wire authenticated GraphQL for logged-in queries + mutations (comments, form submits, Woo cart).
- [x] **H2.4 · headless-revalidate** 🔥🔥🔥 — on-content-change rebuild bridge: reuse the **triggers engine** so publish/update fires a POST to the frontend's revalidate endpoint (Next.js on-demand ISR) or a generic build webhook. **The killer feature — edit in WP, the static frontend updates itself.**

## 🚀 Wave H3 — Full-site headless build + storefront + deploy

- [x] **H3.1 · headless-build-site** 🔥🔥🔥 — the headless cousin of F6 design-from-brief: from a brief OR the existing WP content model, scaffold + generate all page components/queries mapping WP → frontend routes (home, blog index, single, page, archive, search) and theme tokens → Tailwind/CSS config. Reuses `design-from-brief` + `graphql-introspect`.
- [x] **H3.2 · headless-woo** 🔥🔥 — WooGraphQL storefront scaffold: product grid, single product, cart + checkout via GraphQL mutations, for a fully headless store.
- [x] **H3.3 · headless-seo** 🔥🔥 — pull Yoast/Rank Math meta via WPGraphQL SEO addon into the frontend metadata API + a headless-aware sitemap/robots strategy (canonical, OG, schema). Reuses the existing SEO driver.
- [x] **H3.4 · headless-deploy** 🔥🔥 — deployment config generator: Vercel/Netlify project config + env vars (GraphQL endpoint, preview secret, revalidate token) + build-hook wiring, and a WP-side deploy-hook trigger so publishing kicks a rebuild. Honest: emits config + instructions, never holds deploy credentials.
- [x] **H3.5 · graphql-persisted-queries** 🔥 — WPGraphQL Smart Cache allowlisted/persisted queries for production performance + security (block arbitrary queries, cache by query id).

---

## Build order

**H1 first, always** — nothing works without GraphQL + introspection + query. Then **H2** (the scaffold + preview + revalidate loop is where users feel the magic). **H3** is the "AI builds a whole headless site/store and wires deploy" showcase.

## Architecture notes

- [x] **Where the frontend lives:** the MCP filesystem abilities are jailed to the WP install. The frontend project lives in a separate repo, so scaffold abilities RETURN a file manifest/plan that **Claude Code** (with filesystem access to that repo) writes — the plugin owns the WP side + emits templates; the AI writes the frontend.
- [x] **Plugin dependencies:** WPGraphQL (+ JWT, ACF, Woo, Smart Cache addons) are the backend requirements. `headless-setup` installs them; everything else assumes they're present and degrades with a clear error otherwise (same pattern as the SEO/fields hybrid drivers).
- [x] **Reuse:** triggers engine (revalidate), design-from-brief (build-site), SEO driver (headless-seo), existing CPT/taxonomy registration (headless-expose), Application-Password auth (headless-auth).
- [x] **New category:** `headless`.
