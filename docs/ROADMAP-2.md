# WP-Ultra-MCP — Roadmap 2 (High-Demand Features)

Roadmap 1 (all 35 items) shipped in v0.27.0 · **220 abilities**.
This is the next horizon, chosen by **market demand** — what WordPress agencies and site owners actually pay for and search for. Numbered for pick-and-run.

**Demand:** 🔥🔥🔥 huge · 🔥🔥 strong · 🔥 solid niche. Tick what you want; each group becomes a wave.

---

## 📊 Status — 42 / 42 done 🏁 ROADMAP-2 COMPLETE (last updated 2026-07-04)

**Tier S ✅ · A ✅ · B ✅ · C ✅ · D ✅ · E ✅ · F ✅ · G ✅ — ALL live-verified on the Local test site.**

- [x] ****S** — Tier S (security/perf/woo bulk)** — S1–S4 — 29 — ✅ done
- [x] ****A** — Growth & Money** — A1–A5 — 30 — ✅ done
- [x] ****B** — Store Power (WooCommerce)** — B1–B6 — 31 — ✅ done
- [x] ****C** — Site Safety & Health** — C1–C4 — 32 — ✅ done
- [x] ****F** — AI-Native Moat** — F1–F6 — 33 — ✅ done
- [x] ****G** — Ops & Compliance** — G1–G5 — 34 — ✅ done
- [x] ****D** — Content & SEO Reach** — D1–D6 — 35 — ✅ done
- [x] ****E** — Business Verticals** — E1–E6 — 36 — ✅ done

Ability count: **220 → 271 files** (289 registered live). Waves 29–36 (**57 new abilities**) are all live-verified on the Local test site but **uncommitted** — ship together (version bump + zip + release still pending). Next: commit + release, then Roadmap-3 (headless).

---

## 🔥 Tier S — In progress now (Wave 29)

- [x] **S1 · AI content pipeline** — `content-plan` (keyword → outline) + `content-generate` (blocks → SEO post + featured image + links).
- [x] **S2 · Security hardening + malware scan** — harden fixes (file-edit/xmlrpc/login-limit/headers) + core-checksum & suspicious-code scan. *(live-verified 2026-07-03: all 5 measures + 3 scans; xmlrpc fully inert, login lockout proven, planted uploads-malware detected, 3491 core files checksum-clean; nginx .htaccess caveat → `partial` status)*
- [x] **S3 · Performance optimizer** — DB cleanup (revisions/transients/orphans) + bulk image WebP + cache rules. *(live-verified 2026-07-03: dry-run→confirm→delete cycle exact (keep-3 revisions proven), 2560px JPEG→1920px WebP −87%, new `optimize-cache` ability added; fixed compose `</IfModule>` dedupe [Apache-fatal] + preset-clobber via merge-aware rewrite)*
- [x] **S4 · WooCommerce bulk ops** — bulk price/sale/stock/status/category edits, dry-run. *(live-verified 2026-07-03: dry-run preview→confirm→persist on Woo 10.9.1, −20%/+10% price math exact, sale schedule/stock-adjust/visibility/category-swap all proven; fixed on_sale-empty select-all + silently-dropped price-range meta_query → active-price PHP filter)*

## 💰 Group A — Growth & Money

- [x] **A1 · Email campaigns** 🔥🔥🔥 — compose + queue-send + schedule real newsletters (not just subscribe). *(live-verified 2026-07-03: 3 recipients / batch 2 sent to "sent" via real WP-Cron chain, test-send + confirm gates)*
- [x] **A2 · A/B testing** 🔥🔥 — headline / CTA / price / hero variants + conversion tracking + auto-winner. *(live-verified: cookie assignment sticky, title swap rendered, beacon conversions, z-test winner, apply-winner wrote post)*
- [x] **A3 · Lead capture + CRM-lite** 🔥🔥 — capture → leads CPT with pipeline stages, notes, export. *(live-verified: CRUD/stages/notes/dedupe/stats/CSV + WPForms-hook auto-capture)*
- [x] **A4 · Popup / optin campaigns** 🔥🔥 — exit-intent / scroll optins + A/B + conversion stats. *(live-verified: footer render + variant pick + impression/conversion beacons → stats/winner)*
- [x] **A5 · Affiliate / referral tracking** 🔥 — referral links, commissions, payout report. *(live-verified: ?ref= cookie + click count, Woo order → pending referral, approve→paid, payout report; fixed new_order-fires-empty total=0 via status-change re-sync)*

## 🛒 Group B — Store Power (WooCommerce)

- [x] **B1 · Dynamic pricing & discounts** 🔥🔥 — cart/qty/role rules, BOGO, tiered pricing. *(live-verified 2026-07-04: real WC cart — tier 10%@5+ → unit 100→90, BOGO b2g1 → −50 fee, total 640 matches preview, NO compounding over 3 recalcs)*
- [x] **B2 · Order fulfillment + shipping labels** 🔥🔥 — status workflow, tracking numbers, print-ready packing slips, notify. *(live-verified: DHL tracking URL, custom wc-shipped status under HPOS + auto shipped_at, transition rail blocks bad moves, slip = priceless pick list, notify email + flag)*
- [x] **B3 · Product reviews + Q&A engine** 🔥 — verified reviews, review requests, photo reviews, Q&A. *(live-verified: candidate→send→0 cycle, photo+verified review via wc_customer_bought_product, Q&A ask/answer/tree/moderation)*
- [x] **B4 · Wishlist + back-in-stock** 🔥🔥 — wishlist + stock-alert subscribe/notify. *(live-verified: wishlist ops+stats, subscribe/dupe-guard, restock → cron event → alert sent + subs cleared)*
- [x] **B5 · Points & loyalty / gift cards** 🔥 — earn/spend points, gift-card coupons. *(live-verified: 500 order → 500 pts, hook re-fire NO double-award, redeem 200pts→100 email-locked coupon, gift voucher create/list)*
- [x] **B6 · Multi-currency + geo pricing** 🔥 — currency switch, geo-based prices. *(live-verified: ?currency=USD cookie sticky, ৳200 → $1.82 at filter level, base visitor untouched; WOOCS-model caveats documented)*

## 🛡️ Group C — Site Safety & Health

- [x] **C1 · Uptime + health monitor** 🔥🔥 — scheduled checks (broken pages, PHP errors, SSL expiry, disk) → webhook/email alert. *(live-verified 2026-07-04: 5 checks run — http/ssl(56d)/disk/php_errors/cron, transition-based alert de-spam)*
- [x] **C2 · Broken-link + redirect auto-fixer** 🔥🔥 — crawl, find 404s/broken links, suggest + apply redirects. *(live-verified: scan finds broken internal link, apply → shared SEO redirect store → front-end 301 honored)*
- [x] **C3 · Scheduled + off-site backups** 🔥🔥 — cron site-backup → push to S3/Drive/Dropbox; retention. *(live-verified: local scheduled backup created + retention + history + secret masking; SigV4/Dropbox push unit-tested against AWS vectors, needs real creds)*
- [x] **C4 · Firewall / WAF-lite** 🔥 — rate-limit, bad-bot block, country block, request rules. *(live-verified: 3 modes, dry-run verdicts, bad-bot block + Googlebot-safe, block-ip refuses loopback/caller, block-mode never locks out admin/loopback/wp-admin/MCP)*

## 📈 Group D — Content & SEO Reach

- [x] **D1 · Advanced schema generator** 🔥🔥 — Product/Recipe/Event/Review/FAQ/HowTo/JobPosting rich snippets. *(live-verified 2026-07-04: 7 types, Recipe ISO durations PT5M/PT10M/PT15M, auto-faq from h2/p, apply persists AND rich FAQPage JSON-LD renders in wp_head — fixed the head renderer to prefer rich builders)*
- [x] **D2 · Full-site autotranslate** 🔥🔥 — translate every post/page/product in one command. *(live-verified: graceful multilingual_unavailable without WPML/Polylang; reuses i18n duplicate+fill, token-protection round-trip for shortcodes/blocks/URLs; caller-translation always works, AI-auto needs a key)*
- [x] **D3 · Content freshness + auto-refresh** 🔥 — find stale/thin posts, suggest + apply updates. *(live-verified: old thin post scored stale=100/thin=100/priority=high with 5 detailed reasons, deterministic to-do suggestions, apply confirm-gated + never invents content)*
- [x] **D4 · Site-wide internal-link optimizer** 🔥🔥 — build a link graph, insert contextual links across the whole site. *(live-verified: 11-post graph, orphan/dead-end/hub/over-linked detection, keyword-overlap source→target suggestions; apply reuses safe seo-insert-link)*
- [x] **D5 · RSS / feed importer + auto-post** 🔥 — pull external feeds → draft posts. *(live-verified: add-feed + XXE-guarded parse (self-feed 3 items), import created 2 drafts; drafts-by-default, AI-rewrite opt-in)*
- [x] **D6 · Social media scheduler** 🔥🔥 — calendar + queue → auto-share to FB/IG/X/LinkedIn. *(live-verified: schedule + items[] batch auto-spaced by start+interval (3 queued across 3 days), day-grouped calendar, per-network char limits; webhook delivery model, no per-network OAuth in WP)*

## 🏢 Group E — Business Verticals (turn WP into an app)

- [x] **E1 · Booking / appointments** 🔥🔥🔥 — services, staff, availability, slots, reminders. *(live-verified 2026-07-04: availability 29 slots on a working day, booking created, second booking same slot → slot_taken (atomic no-double-book); slot math + reminder cron + .ics)*
- [x] **E2 · Membership / paywall** 🔥🔥🔥 — levels, drip content, restriction rules, member dashboard. *(live-verified: level + rule, non-member check → allowed=false reason=no_membership (fail-closed), assign; the_content paywall filter + admin/author bypass)*
- [x] **E3 · LMS / courses** 🔥🔥 — courses, lessons, quizzes, progress, certificates. *(live-verified: enroll → quiz wrong=fail/right=pass → progress complete=true → certificate issued; answer_index leak-guarded, sequential-lesson lock)*
- [x] **E4 · Events + ticketing** 🔥 — event CPT, RSVP, paid tickets, calendar. *(live-verified: capacity 2 → 2 registrations ok, 3rd → sold_out (atomic no-oversell); RSVP/Woo-order bridge, .ics)*
- [x] **E5 · Directory / listings** 🔥 — listing CPT, categories, map, front-end submit, monetize. *(live-verified: geo search near Dhaka → Dhaka Cafe first at 0.098 km (haversine), featured ranking + front-end submit moderation)*
- [x] **E6 · Donations / crowdfunding** 🔥 — campaigns, goals, recurring donations. *(live-verified: 3000+2000 completed → progress 50% / remaining 5000; honest no-card model — Woo/webhook bridge + recurring-schedule-only)*

## 🤖 Group F — AI-Native Moat (no competitor has these)

- [x] **F1 · AI chatbot / knowledge base** 🔥🔥🔥 — index site content → embeddable RAG chat widget answering from the site. *(live-verified 2026-07-04: build-index 10 chunks, keyword-RAG ranks the right passage, public /chat endpoint returns grounded sources, graceful no-key degradation; OpenAI-embedding path needs a key)*
- [x] **F2 · Agent mode / autonomous loop** 🔥🔥 — give a goal → AI plans → executes → verifies → retries. *(live-verified: structured steps + cross-step {steps.x.post_id} token resolves, ability_ok/contains checks, verify+retry loop bounded, goal-mode needs key, nested agent-run blocked)*
- [x] **F3 · Visual diff / regression** 🔥🔥 — before/after structural fingerprint (+client image hook) to catch breakage. *(live-verified: baseline → mutate → compare caught title/h1/text_hash/byte changes, severity major; rendered-PHP-error → critical; honest no-headless-browser scope)*
- [x] **F4 · Natural-language analytics** 🔥🔥 — "top sellers last month" → validated intent → SAFE parameterized query. *(live-verified: 8-report catalog, resolve-date last-month→2026-06-01, post_counts + NL answer, top_products graceful no-data; NEVER emits raw SQL; ask needs key or pre-mapped {report,params})*
- [x] **F5 · AI SEO auto-pilot** 🔥🔥 — scheduled: audit → fix meta → internal links → schema → track. *(live-verified: preview-post shows planned meta/links/schema, dry-run summary, confirm_required guard on live writes; reuses SEO driver; dry-run-first)*
- [x] **F6 · AI design-from-brief** 🔥 — describe a whole site → AI builds pages, menu, theme tokens. *(live-verified: structured plan → dry-build plan-of-record → real build created 2 live pages + nav menu (HTTP 200), block markup wp:heading/button, confirm-gated; brief-mode needs key)*

## 🌐 Group G — Ops & Compliance

- [x] **G1 · GDPR / cookie consent** 🔥🔥 — consent banner, data export/erase, privacy tools. *(live-verified 2026-07-04: banner renders on homepage with XSS-escaped content, privacy-audit 6 findings, export ran WP core exporters, erase confirm-gated; orchestrates WP privacy API)*
- [x] **G2 · Site migration (host → host)** 🔥🔥 — full export → import to another host. *(live-verified: readiness check flags php-downgrade blocker, dry-run import + URL-pair rewrite via serialized-safe search-replace, confirm-gated + blocker-refused; reuses backup + siteops engines)*
- [x] **G3 · User roles & capabilities editor** 🔥 — custom roles, granular caps. *(live-verified: create/get/add-cap, 7-group cap catalog; admin-lockout guards proven — stripping manage_options from admin → admin_guard, deleting core editor → protected)*
- [x] **G4 · Scheduled reports** 🔥 — weekly site/store/SEO report emailed to the owner. *(live-verified: dry-run built 4 sections real data (content/store/SEO/health), 5.3KB HTML preview; reuses count-posts/woo-reports/seo-audit/health-run, each graceful)*
- [x] **G5 · White-label / client mode** 🔥🔥 — rebrand the plugin, client-safe dashboards. *(live-verified: brand strings applied, javascript: logo URL stripped, client-mode restricts subscriber (would_hide_menus) but never admins; honest cosmetic-only scope, GPL untouched)*

---

**Recommended order:** finish `S2 → S3 → S4` (in flight) → then `E1, E2` (verticals) → `A1, F1` (engagement + AI wow) → `C1, C2, C3` (agency layer) → `D1, D2, G1`.

Tell me the numbers/groups — e.g. "Group E koro", "S2 S3 S4 + E1 E2", "all Group C".

*Generated 2026-07-03 after Roadmap 1 completed at v0.27.0.*
