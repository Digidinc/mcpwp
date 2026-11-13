# MCPWP Backlog

> **For Claude:** Read this file at the start of every session. Tasks are tracked as GitHub issues — use `gh issue list --repo Mumega-com/mcpwp --label "status:ready"` to find work. Update issue status as you go. This file is the orientation layer; GH issues are source of truth.
>
> **Project board (MCPWP — tenant-scoped, NOT the org board):** https://github.com/orgs/Mumega-com/projects/2
> **Quick queue:** `gh issue list --repo Mumega-com/mcpwp --label "status:ready" --state open`

**Current version:** v3.3.8 in repo. **Update channel live at `updates.mcpwp.net`** — canonical channel serves agency + freemius builds. Legacy `spai-updates` Worker stays live for un-migrated 2.8.56 `site-pilot-ai/` sites.
**Last updated:** 2026-06-19

---

## Squad orientation (read after this file)

The mumcp squad operates MCPWP as a product/business toward a Product Hunt launch.
Architect = systems + docs + dispatch (does not hand-operate sites). Strategy + plan live in
`docs/squad/` — index: `docs/squad/README.md`. Start there for the launch roadmap, team, and money rail.

**Launch train:** v3.1 "Launch" (bridge + checkout + conversion surface) → PH target Jun 30 / Jul 2.
See `docs/squad/VERSIONING-AND-ROADMAP.md`.

**Open launch issues (filed 2026-06-11):** #502 content pass · #503 manual cutover · #504 pricing render ·
#505 bridge release (keystone) · #506 distribution off mumega.com · #507 test rig · #508 education vertical ·
#509 affiliates · #510 addon economy · #511 substrate-agnostic (Shopify-ready) · #512 Freemius+Stripe checkout ·
#513 self-distribution loop (Remotion+Telegram).

**Money rail:** keep Freemius (connected to Hadi's Stripe → Mercury); US-market first. #512.

---

## North Star (product thesis)

> **`docs/STRATEGY.md` is the canonical single source** (the economy frame · components · flagship loop ·
> moat · roadmap). Taxation/numbers: `docs/PRICING_TIERS.md`. This file is the task tracker. STRATEGY wins.
>
> **It's an economy, not a product. We design it and tax the flows.** Currency = **coherence**; territory =
> **AI-attention**. Components: **MCPWP** = the basin (WordPress hands) · **mupot** = the rules engine
> (sovereign agent org on the customer's own Cloudflare, metered/capped/gated loops) · **addons/skills** =
> items (third-party, unlimited; `mcpwp_register_tools` + bus `register_skill`) · **snapshots** = recipes
> (curated addon-mixes, performance-ranked by our PostHog-everywhere observability) · **the fleet** = any BYO
> runtime joined over MCP. **Flagship loop:** a site that acquires its own traffic (Google News/Discover,
> auto-included) and converts it (edge A/B), governed — coherence is the admission ticket + penalty shield.
>
> **Revenue = taxing flows:** marketplace cut (addons + snapshots) + performance-ranked snapshot premium +
> infra rent + cheap adoption toll (MCPWP Pro). Never compute. **Moat:** marketplace + attractor + learning +
> observability — none forks with the code. **Cold-start solved by being the first player:** our own dogfood
> dropship store. **Keystone build:** OAuth 2.1 + DCR (v2.9) opens the ChatGPT Business + Claude Managed fleet.

---

## Current Sprint — Sprint 11: Admin UI Polish + Conversion

Agent mandate (refocused 2026-06-09): **MCPWP repo health + GTM enablement + pricing.** Not tenant ops, not mupot infra. Build reusable product capability; keep the repo clean; make go-to-market easy.

- **Admin UI audit → 10 issues (#458–#467) — ✅ ALL SHIPPED** across 3 batches (v2.8.53/54/55, live on channel + crophelp.ai). Onboarding checklist, chat empty-state, toggle focus (WCAG AA), Control Room/Library/Integrations UX, a11y + 1024px breakpoint, dead 104KB partial removed, inline-JS consolidated into one enqueued asset, PostHog double-init fixed, design tokens + badge consolidation, chat-history XSS hardening (reviewer-caught).
- **Pricing-tier spec — ✅ analysis shipped** → `docs/PRICING_TIERS.md` (PR #471). Measured economics, 4 tiers, margin proof; **T87 now awaits Hadi's number sign-off** (§6).
- **Still open this phase:** directory-listing hygiene (LobeHub/mcp.so/Smithery — manifest now synced to 270+/v1.2.0), mcpwp.net agent onboarding (needs a scoped API key from Hadi).
- Tracked as GH issues (`admin-ui`/`status:ready`); roll up via `sprint-stats` at close.

---

## Roadmap — SEO/GEO 2026 Modernization — ✅ EPIC CLOSED (filed + shipped 2026-07-14)

`/seo/*` tools encoded guidance that predated several confirmed 2025-2026 Google changes. Researched via 4 parallel agents against primary sources (Google Search Central, Search Engine Land/Roundtable) — epic **#614** (closed), all 3 children shipped.

- **#615 — ✅ SHIPPED v3.4.15** — `/seo/structured-data` classifies each detected @type as `active`/`deprecated_no_rich_result` (HowTo, FAQPage, ClaimReview, EstimatedSalary, LearningVideo, SpecialAnnouncement, VehicleListing). Book and Course deliberately excluded (Book un-deprecated Nov 2025; Course too broad to flag without false positives). 10 new regression tests.
- **#616 — ✅ SHIPPED v3.4.16** — `schema_placement_mismatch` check (FAQPage/HowTo/QAPage schema must describe content actually on the page, per Google's March 2026 rule) + explicit `eeat` field in content-quality scorer (4 factors, each with its own score + evidence, not one aggregate number). 15 new regression tests.
- **#617 — ✅ SHIPPED v3.4.17** — `geo_citability_experimental` on content-quality, opt-in only (`include_geo_experimental=true`, off by default), clearly labeled vendor-sourced/not-Google-confirmed, never mixed into the main score. Explicitly did **not** build an llms.txt generator (Google doesn't consume it; Ahrefs: 97% of 137k sampled files got zero requests). **Bonus find while live-testing:** `extract_heading_texts()` returns array-shaped headings (`['level','text']`), not plain strings — a naive string-assuming check fatally crashed until caught end-to-end, not by unit tests against hand-built fixtures alone. 5 new regression tests.
- 30 new regression tests total across the epic; full suite green throughout (324 tests, 936 assertions at close).
- Ground truth worth remembering: always test SEO/content-quality checks against a real REST call on a real post, not just hand-built fixtures passed straight to a method — internal data shapes (like heading arrays) don't always match what a fixture-writer assumes.

---

## Roadmap — Elementor Atomic Editor (V4) support — ✅ EPIC CLOSED (filed + shipped 2026-07-14)

Elementor is core to MCPWP — this got priority attention. Facts verified directly against Elementor 4.1.3 PHP source on `wp-rig-wp-dev` (not docs/inference) — epic **#618** (closed) superseded **#211**, which shipped only 1 of 5 planned checkboxes before being closed 2026-06-07.

- **#619 — ✅ SHIPPED v3.4.9** — `elementor_layout_mode` capability gains a third `atomic` state (+ new `elementor_atomic_active` bool); layout-mismatch guard for atomic elType payloads mirroring the #612 fix. Also backfilled missing `e-grid` type into the whitelist (gap left by #211).
- **#620 — ✅ SHIPPED v3.4.10** — reader (`summarize_element`/`walk_elements_for_stats`/`count_elementor_widgets`) now handles atomic leaf types correctly; `wp_edit_widget` now accepts atomic elements. **Bonus find while live-testing:** Elementor's own internal HTML-cache-rebuild save was silently stripping *nested* atomic elements past the top-level-only integrity check — confirmed via direct DB readback, genuinely reproducible. Fixed the check to be fully recursive.
- **#621 — ✅ SHIPPED v3.4.11** — `wp_get_widget_schema` returns a real deep schema (props/types/enums/defaults) for atomic elements via Elementor's own `get_props_schema()`. Found: leaf widgets and container elements register through two *different* Elementor managers (`widgets_manager` vs `elements_manager`) — added the fallback so both resolve.
- **#622 — ✅ SHIPPED v3.4.14** — re-checked the 4 upstream Elementor bugs before starting (elementor/elementor#35888/#36415/#36443/#36405 — still all open); turned out `wp_add_section`/`wp_replace_section`/`wp_edit_section` were never elType-gated at all, so create/edit/remove already worked with atomic elements as a side effect of #619-621. Real remaining gap was validation: `dry_run` now cross-checks atomic props against the real #621 schema and flags the missing `{"$$type","value"}` wrapper — the actual mistake made live-testing this epic.
- **#623 — ✅ SHIPPED v3.4.14** — `wp_get_guide(topic='elementor')` documents Atomic Elements (layout modes, structure, prop format, worked example); tool descriptions updated to match.
- Confirmed ground truth worth remembering for future Elementor work: `elType` is direct (`e-heading`, `e-div-block`, no `widgetType` wrapper); values wrap as `{"$$type": "<kind>", "value": ...}`; `Atomic_Element_Base` (container) vs `Atomic_Widget_Base` (leaf) is a real class distinction; `wp_add_section`/`replace_section`/`edit_section` are elType-agnostic by design, don't add gating there without reason.
- Still deliberately not done: full atomic write support beyond what already works — Elementor's own upstream save-pipeline bugs are unresolved; re-check them before expanding further.

---

## Roadmap — Cloudflare Integration — ✅ EPIC SHIPPED & MERGED (2026-07-16)

Origin: a real customer (digitalmarketingexperts.ca) hit free-tier Cloudflare Bot Fight Mode / Security Level silently blocking MCP requests earlier this cycle — fixed manually via a one-off Configuration Rule + customer email. Researched Cloudflare's actual API surface (Rulesets/Configuration Rules, Cache Purge, Speed settings, GraphQL Analytics, AI Crawl Control) against live docs before building — epic **#626** (closed), children **#627-631** (closed), merged via PR #632 (f2d9f728).

- **#627 — ✅ SHIPPED** — Cloudflare as a zone-scoped integration provider (`api_token` + `zone_id`, multi-field like Figma/staging).
- **#628 — ✅ SHIPPED** — `wp_cloudflare_fix_mcp_access`: idempotent Configuration Rule (`security_level: essentially_off`) for this site's own `/wp-json/mcpwp/v1/*` — the confirmed fix for the origin bug. Best-effort WAF skip rule for Super Bot Fight Mode/Managed Rules alongside it (unverified skip-target product string — fails gracefully, doesn't block the primary fix).
- **#629 — ✅ SHIPPED** — auto cache purge on publish/update (posts, pages, `_elementor_data` writes) + manual `wp_cloudflare_purge_cache`. Fire-and-forget; a purge failure never blocks the content write.
- **#630 — ✅ SHIPPED** — `wp_cloudflare_get_speed_settings`/`wp_cloudflare_update_speed_setting` (Early Hints, HTTP/3, Rocket Loader, Polish — Mirage excluded, Cloudflare removed it Sept 2025); surfaced as `technical_signals` recommendations in `wp_seo_audit_site()`.
- **#631 — ✅ SHIPPED** — guide topic (`wp_get_guide(topic="cloudflare")`), 11 new regression tests (request shaping, idempotent rule upsert, purge payload shapes, error mapping). Full suite: 335 tests / 972 assertions.
- Explicit non-goals (no public API existed at research time, confirmed against live docs): real RUM Core Web Vitals via the GraphQL Analytics API (field set needs a live-account introspection spike), AI Crawl Control per-bot management (dashboard-only), llms.txt/custom robots.txt at the edge (no first-party feature — would need a self-authored Worker).
- No live Cloudflare account available to test against directly — outbound request shapes verified instead via `pre_http_request` interception (base URL, Bearer auth, ruleset phase paths, whole-ruleset PUT semantics, payload shapes) matched against the confirmed API research, plus full live-WP functional testing (activation, MCP dispatch, REST scope enforcement, auto-purge hook) on local docker.

---

## Roadmap — DME dogfood fixes + Freemius activation — ✅ SHIPPED (2026-07-16)

A real DME (digitalmarketingexperts.ca) template-library build surfaced 6 issues; a real freemius-zip activation attempt surfaced a hard license gate. All independently reproduced and fixed on local docker before merge, not just patched from the report.

- **License link (no issue #, PR #633)** — "Manage pricing and license" on the Setup page was a hardcoded external `mcpwp.net/pricing/` link with no install context, so a completed Freemius purchase never activated the site and left no way back into wp-admin. Now routes through the live Freemius instance's own `get_reconnect_url()`/`get_upgrade_url()`/`get_account_url()`.
- **#634 — ✅ SHIPPED (PR #640)** — `wp_list_elementor_archetypes`/`wp_list_elementor_parts` only accepted short filter names (`scope`/`style`/`kind`/`tag`) while the matching create tools use `archetype_scope`/`archetype_style`/`part_kind`/`part_style`/`part_tags`. Both name sets now accepted (schema aliases + `param_remap`), fully backward compatible.
- **#637 — ✅ SHIPPED (PR #642)** — `wp_apply_elementor_template`/`archetype`/`part` all replace existing page content but were annotated `destructiveHint: false`; `wp_apply_elementor_part` also defaulted `mode` to `"replace"`. Now `destructiveHint: true` on all three, and `wp_apply_elementor_part` defaults to non-destructive `insert` (schema *and* server-side fallback).
- **#636 — ✅ SHIPPED (PR #642)** — `wp_get_elementor_template`/`archetype`/`part` returned `elementor_data`/`page_settings` as raw JSON strings, breaking read→modify→write round trips. Now parsed to the same array/object shapes `wp_get_elementor` already returns.
- **#613 — ✅ SHIPPED (PR #644)** — `wp_get_rendered_html` fetched draft/private previews via an outbound `wp_remote_get` loopback — fails both because WP's preview link needs a logged-in session a server-side request doesn't carry, and because any hosting-level gate (HostGator's "Coming Soon" staging lock, the original report) intercepts it before WordPress runs. Draft/pending/private Elementor posts now render in-process via Elementor's own `get_builder_content_for_display()` — no network hop, neither failure mode applies. Published posts unaffected.
- **#641 — ✅ SHIPPED (PR #645)** — `wp_set_kit_css` only had whole-stylesheet `replace`/`append`; repeated append passes across a real build cycle turned a 2,001-line CSS bundle into 204,336 duplicated characters. New `wp_upsert_kit_css_block`/`wp_list_kit_css_blocks`/`wp_get_kit_css_block`/`wp_delete_kit_css_block` — named, hash-tagged, idempotent regions inline in the same kit CSS string (no new storage). Re-upserting identical CSS is a no-op; optional `expected_hash` gives optimistic concurrency (409 on conflict).
- **Freemius hard-activation-gate (no issue #, PR #646)** — the freemius zip's connect screen forced a license key or paid trial before any use, no Skip, no free tier. Two independent causes fixed: `trial.is_require_payment` was `true`, and Freemius's connect screen separately defaults to requiring a license key whenever the running code is the premium package itself (assumes premium code only arrives via its own free→paid auto-upgrade, never a cold direct download — which is exactly how we distribute the freemius zip). Fixed via `is_require_payment=false` + explicitly injecting `require_license=false` into both `$_GET` and `$_REQUEST` (independent superglobals) on `admin_init`, before Freemius's own page callback runs. Restores Skip/free-tier-first UX and the Account/Pricing/Contact Us submenus.
- Deliberately not done this round (bigger scope, flagged for later): **#635** (pagination + structural summaries on the 3 library list tools), **#638** (`page_settings` round-trip with preserve/replace/merge modes on create/apply), **#639** (authenticated full-page/responsive visual QA with layout-geometry findings) — all real asks, all need a design decision, not a drop-in patch. **#643** is a coordinating roadmap issue tying these + the shipped ones together, not independently actionable.
- Version bumped 3.4.17 → 3.5.0 (release-please, all of the above rolled in) and published to both update channels (`mumega.com/mcp-updates`, `updates.mcpwp.net`).

---

## Roadmap — MCP marketplace distribution + mumcp.com pivot — 🔵 PLANNING (2026-07-16)

Origin: `mumcp.com` domain bought for the wp.org `mumega-mcp` free listing. Discussed extending it past "MCP for WordPress" into a broader MCP-server shop (mumcp.com = MCP delivery/marketplace, mcpwp.net stays the WP-specific authority/content hub). Bardia's FormFlow-for-Strapi (already has traction) floated as a first anchor listing. Researched submission requirements for 4 adjacent agentic products (Codex, Claude Cowork, Copilot Cowork, Perplexity/Comet) — verdict: **Claude Cowork is the real competitive pressure**, not the other three — same non-technical business-owner persona MCPWP targets, same jobs-to-be-done (delegate business ops to AI). Two ways that resolves: MCPWP becomes a listed Cowork connector (ride Anthropic's distribution) or an independent MCP shop gets squeezed into a thin reseller layer if Anthropic's own directory becomes the default store for that buyer.

**Directory submission — researched, none of the reachable paths need Dynamic Client Registration:**
- MCPWP already has OAuth 2.1 authorize+token flow and RFC 8414/9728 well-known discovery built (v3.2.0+). DCR (RFC 7591) was not built at the time of this note; **it has since shipped (#1099)** — see `docs/OAUTH-CONNECT.md`.
- **Live today, zero backend work:** any Claude user can add MCPWP right now as a custom connector by URL — static OAuth client supported, no submission, no review. Push this as the immediate channel before anything else.
- **Claude Cowork Connectors Directory** — submit via `oauth_anthropic_creds` (email existing static client_id/secret to `mcp-review@anthropic.com`), skips DCR entirely, reuses the OAuth flow already built. Requires a public privacy policy page (missing = instant rejection) — verify `mcpwp.net`/`mumcp.com` has one before applying.
- **OpenAI Codex/ChatGPT plugin marketplace** — separate program, `platform.openai.com/plugins`, also accepts a "predefined OAuth client" (no DCR needed). Multi-section form: server URL + auth, skills bundle, 3-5 example prompts, 5 positive/3 negative test cases, domain-verification token at `/.well-known/openai-apps-challenge`.
- **Cheap side-channel:** MCPWP already exposes `/wp-json/mcpwp/v1/openapi.json` (built for GPT Actions) — submit as-is via PR to the community GPT Actions library on GitHub, near-zero additional work.
- DCR/CIMD only matter later as a zero-touch scaling optimization once past manual submission — not a blocker for applying to either directory now.

**Open decisions before building anything (flagged, not resolved):**
- What/where is "Inkwell" concretely — a real WordPress instance to activate mumcp on, or the not-yet-built brand-brain product referenced in `docs/squad/GTM-DESIGN-THINKING.md`? Need a URL/access before any activation.
- Revenue-share/listing terms with Bardia (FormFlow-for-Strapi) and any other third-party MCP servers on mumcp.com — settle before the shelf has more than one listing.
- Content-basin/podcast pipeline (NotebookLM → Spotify/YouTube, business-owner-facing WP+AI news + plugin reviews) discussed as a separate top-of-funnel play for mcpwp.net — needs a podcast/RSS plugin (Seriously Simple Podcasting or PowerPress), nothing else new.
- Orchestration for any recurring/scheduled content-ops work: route through mupot + a scoped marketing squad + one approval channel (matches the already-validated MVP-proof-loop pattern), not an ad-hoc Claude Code session running unattended for days — no cost cap or governance gate that way.

---

## Milestone Map — MVP → Full (traced to major versions)

```
SHIPPED  v2.8.45–v2.8.55  analytics · agency proxy · audit+rollback · white-label · site-memory
                          · signals · blueprints · chat · hook API · find-replace fix
                          └─ these are the BONES of the autonomous-marketing engine

NOW ─ MVP PILOT (no version bump, runs on current builds) ───────────────────────────────
  P0  Autonomous-Marketing PROOF LOOP — hand-wired, one instance:
      1 Woo store → MCPWP + 1 mupot pot → marketing squad (keyword→on-brand content→SEO→product page)
      → Telegram channel (owner approves) → 1 agency.  + measure real $/task.
      Validates the ENTIRE thesis before anything is productized.

PHASE A — Foundation & Distribution ──────────────────────────────────────────────────────
  M1  Launch-Ready              v2.8.x   free plugin + Pro; beachhead entry   ← Hadi gates (T87 pricing)
  M2  Auth Keystone (PULLED ▲)  v2.9     OAuth 2.1 + DCR on the bus/MCP — the funnel-opener. Bearer
                                         runtimes (Hermes·openclaw·Codex·Claude-remote) connect NOW;
                                         OAuth+DCR adds the BIG doors: ChatGPT Business + Claude Managed.
  M3  Distribution              v2.9     WP.org · MCP registries · ChatGPT GPT/App · Claude Connector
                                         (rides on M2 auth — one OAuth flow, one submission URL)

PHASE B — Autonomous Marketing (productize the MVP) ───────────────────────────────────────
  M4  Hosted Marketing Agent    v3.2     agent.mcpwp.net + mupot integration = the MVP loop, productized
  M5  Content Engine            v4.0     keyword research · Telegram/social distribution · Remotion video

PHASE C — Platform & THE FULL VISION ──────────────────────────────────────────────────────
  M6  Platform Foundations      v3.x ✅  spai_→mcpwp_ rebrand (v3.0.0) + Modular-Monolith refactor
                                         (G1-G5, ADR 0001 — microkernel REJECTED, gateway model). DONE.
  M7  Snapshot System           v6.0     vertical snapshots (mupot pack + MCPWP blueprint): Woo, LearnPress
  M8  Agency Reseller (FULL)    v6.x     white-label SaaS-mode · client sub-billing · snapshot marketplace
                                         · sovereign deploy  =  THE FULL ONE
  M9  Addon/Tool Marketplace    v7.0     G7: tool-registry marketplace (curation, ranking, the cut) for
                                         mcpwp_register_tools addons. DEFERRED to v7 — must not precede the
                                         P0 proof loop (ADR 0001). Distinct from M8's snapshot marketplace.
```

**The MVP→Full trace (the spine):** P0 is one hand-built snapshot loop → M4 productizes that loop as a
hosted agent → M7 generalizes it into reusable vertical snapshots → M8 sells/resells them at scale (the
GHL model). The full vision lands at **v6.0–v6.x**, gated behind M2 (auth) + M4 (hosted agent) + M6
(platform hardening). **The MVP pilot is doable NOW on current builds** — don't wait for v3 to prove it.

**Why auth moved up (M3→M2, v3.0→v2.9):** OAuth 2.1 + **DCR** is not a wall someone else holds — it's a
build *we* control, and it's the single highest-leverage one. Bearer runtimes already connect with no new
auth; OAuth+DCR is the ONE thing that converts the two biggest, otherwise-unreachable markets — **ChatGPT
Business** (connectors mandate OAuth 2.1 + Dynamic Client Registration, no bearer) and **Claude Managed
Agents** (OAuth Vaults) — into reachable funnel. It opens both doors at once, so it ships with Distribution,
not after it. The **fleet is the funnel**: every BYO-agent runtime that connects = a door into a pot.

---

## P0 — Be the First Player: dogfood the loop on our OWN sites (do this FIRST)

The smallest thing that validates the whole North Star AND solves marketplace cold-start. Runs on **current
builds**. Two dogfoods, our niche = **MCP · WordPress · WP plugins**.

| # | GH | Who | Task | Status |
|---|-----|-----|------|--------|
| P0a | #449 | agent | **Measure real $/task** — ✅ measured (free ~20k / all 269 ~35k tokens/turn; page-build ≈ $0.9 Haiku / $2.5 Sonnet cached). | ✅ done |
| P0b | #454 | agent | **Keyword-research MCP tool** — ✅ shipped v2.8.52: `wp_keyword_research`. | ✅ done |
| **P0c** | — | both | **mcpwp.net content + courses dogfood (FIRST — safest).** Our-niche content engine (MCP/WP/plugins, real E-E-A-T, zero slop risk) + **LearnPress video courses teaching MCPWP** (dogfoods LearnPress + onboarding + funnel). Proves the distribution loop. | ⏳ |
| **P0d** | #450 | both | **Woo store dogfood — commerce loop.** Dropship/arbitrage store, governed auto-publish + edge A/B under caps → real dollars. Proves the conversion loop. | ⏳ |

Epics: M4 #451 · M7 #452 · M8 #453.

**Exit test:** the loop runs on our own sites, content is on-brand + gets distributed (Google picks it up,
no penalty), and a real number moves (signups/sales) → thesis validated + the marketplace has its first
seeded shelves → build the productized loop.

---

## P0.5 — Agent Representation & Fleet (second security layer + governance)

When an agent connects over MCP it must **declare itself** — a start-up questionnaire returning
`{ identity, represents, scope_of_work, goal }`. Builds on the API key, doesn't replace it.

| # | GH | Who | Task | Status |
|---|-----|-----|------|--------|
| AR1 | — | agent | **Connect-time questionnaire** — MCP onboarding handshake captures identity/represents/scope/goal; stored per-agent. | ⏳ |
| AR2 | — | agent | **Admin Fleet page** — owner sees every connected agent: identity, declared scope, goal, status (active/idle), recent actions. | ⏳ |
| AR3 | — | agent | **Scope-as-enforcement (2nd security layer)** — agent may act only within declared scope; deviation flagged + gated; intent on the audit trail (EU AI Act). **Sensitive surface — adversarial-gate the build.** | ⏳ |
| AR4 | — | agent | **Two-speed cadence support** — interval data-gathering hooks so cheap-continuous agents perceive + log, and the expensive periodic agent reads the interval's data + prior small-agent actions before deciding. | ⏳ |

Maps to mupot `boot_context` + `check_in` + capability RBAC, brought into MCPWP itself. See STRATEGY.md §4b.

---

## M1 — Launch-Ready (next milestone)

### What blocks launch — hard stops

| # | GH | Who | Task | Status |
|---|-----|-----|------|--------|
| ~~T85~~ | #410 | agent | ~~Fix 29 Dependabot alerts~~ | ✅ PR #438 |
| T86 | #416 | Hadi | Privacy policy at mcpwp.net/privacy — blocks WP.org + ChatGPT App + Claude Connector | ⏳ |
| T87 | #417 | Hadi | Pricing page on mcpwp.net — #1 PH question. Analysis done → `docs/PRICING_TIERS.md` (measured economics, 4 tiers, sign-off list in §6). Awaiting Hadi: confirm numbers → page copy writes in one pass. | 🟡 numbers pending |
| T88 | #418 | Hadi | Install flow test: zero → first tool call < 5 min | ⏳ |

### Security hardening — must ship before launch

| # | GH | Who | Task | Status |
|---|-----|-----|------|--------|
| ~~T94~~ | #424 | agent | ~~Body size limit + Content-Type enforcement in proxy worker~~ | ✅ PR #438 |
| ~~T95~~ | #425 | agent | ~~Security response headers (nosniff, X-Frame-Options, CSP)~~ | ✅ PR #438 |
| ~~T96~~ | #426 | agent | ~~Workers Rate Limiting binding on /mcp + /api/accounts~~ | ✅ PR #438 |
| ~~T97~~ | #427 | agent | ~~Timing-safe ADMIN_SECRET comparison~~ | ✅ PR #438 |
| T98 | #428 | Hadi | WAF edge rate-limit rule (Cloudflare dashboard — 2 rules) | ⏳ |

### Contributor DX — should be live at launch (contributors arrive)

| # | GH | Who | Task | Status |
|---|-----|-----|------|--------|
| ~~T80~~ | #411 | agent | ~~GitHub issue templates (bug / feature / new-tool)~~ | ✅ PR #438 |
| ~~T81~~ | #412 | agent | ~~PR template~~ | ✅ PR #438 |
| ~~T82~~ | #413 | agent | ~~GitHub Actions CI (PHP lint + smoke test)~~ | ✅ PR #438 |
| ~~T83~~ | #414 | agent | ~~AGENTS.md for AI contributors~~ | ✅ PR #438 |
| ~~T84~~ | #415 | agent | ~~Devcontainer for GitHub Codespaces~~ | ✅ PR #438 |

### Launch assets + community

| # | GH | Who | Task | Status |
|---|-----|-----|------|--------|
| T89 | #419 | Hadi | Email capture on mcpwp.net | ⏳ |
| ~~T90~~ | #420 | agent | ~~Launch blog post~~ | ✅ PR #446 — docs/blog-launch-post.md |
| T91 | #421 | Hadi | Discord server setup | ⏳ |
| T92 | #422 | Hadi | Freemius onboarding email update | ⏳ |
| T40 | #394 | Hadi | Record demo video (90s) | ⏳ |
| T41 | #395 | Hadi | Product Hunt gallery images (5x) | ⏳ |
| T42 | #396 | Hadi | Update mcpwp.net landing page | ⏳ |
| ~~T43~~ | #402 | agent | ~~PH maker first comment~~ | ✅ PR #446 — docs/ph-maker-comment.md |
| T44 | #403 | Hadi | Twitter teaser thread | ⏳ |
| T46 | #404 | Hadi | Set Product Hunt launch date | ⏳ |

### What I (Claude) lack to complete M1

| Need | Why blocked |
|------|-------------|
| Demo site URL | Live public WP (not localhost) — needed for ChatGPT GPT test + video recording |
| ChatGPT Plus account | Custom GPT creation + Developer Mode MCP test |
| PH account | Hadi must be the maker |
| Pricing decision | Can't write pricing page without numbers |
| Privacy policy review | Can draft, Hadi must approve legal text |
| WP.org account | Hadi submits manually |

**Agent tasks remaining in M1:** none — all done. Launch blocked on Hadi gates above.

---

## M3 — Multi-Client Distribution (v2.9) — rides on M2 auth (below)

### Depends on: M1 complete, T03/T04 testing passed

| # | GH | Task |
|---|-----|------|
| T01 | #378 | Test Claude Desktop + Claude Code (local) |
| T02 | #379 | Test Claude Code against localhost:8080 |
| T03 | #380 | Test OpenClaw streamable-http + X-API-Key header (bug #65590) |
| T04 | #381 | Test Hermes Agent + Tool Search activation |
| ~~T10~~ | #383 | ~~Update openapi-chatgpt.yaml for v2.8.49~~ — ✅ PR #446 (now v2.8.50, 49 ops) |
| T11 | — | Create ChatGPT Custom GPT in GPT Store |
| T13 | #385 | integrations/chatgpt/ setup guide |
| T20 | #388 | WP.org audit (free tier, GPL clean) |
| T21 | #389 | Build WP.org free zip |
| T23 | #391 | Update readme.txt for WP.org |
| T25 | — | Submit to WP.org |
| T30 | — | Publish ClawHub skill (needs T03 pass) |
| T31 | — | Publish Hermes Skills Hub skill (needs T04 pass) |
| T32 | #397 | Submit to MCP registries (mcp.so, smithery.ai, glama.ai) |
| T34 | #398 | Claude Desktop Extension (MCPB format) |
| T50 | #405 | MCP Resources |
| T51 | #406 | MCP Prompts |
| ~~T52~~ | #407 | ~~BM25-optimized tool descriptions~~ — ✅ PR #446 (56 descriptions rewritten) |

### What I lack for M2

| Need | Why |
|------|-----|
| ChatGPT Plus | GPT Store publishing |
| Test results T03/T04 | Can't publish ClawHub/Hermes skills blind |
| WP.org account | Submission |

---

## M2.5 — Custom Tool Registry (v2.9 add-on, can ship independently)

MCPWP becomes a platform runtime. Third-party plugins (Digid, WooCommerce extensions, booking plugins) register their own MCP tools. Every WP site becomes a programmable agent surface specific to that business.

| # | GH | Task | Notes |
|---|-----|------|-------|
| ~~T71a~~ | #439 | ~~Plugin hook API (`mcpwp_register_tools` filter)~~ | ✅ PR #446 — Mcpwp_Custom_Tool_Registry, dispatch via rest_path |
| T71b | #440 | REST proxy tools (no-code external endpoints) | Admin defines tool → MCPWP proxies to external URL. No PHP needed. |
| T71c | #441 | Visual tool builder UI (WP Admin sandbox) | Form-based tool creator with inline test panel. Layer on top of T71b. |

**T71a can ship in M2 — independent of OAuth, no blockers.**

---

## M2 — Auth Keystone (v2.9) — PULLED FORWARD

> Was M3/v3.0. Moved up because it is the funnel-opener, not a downstream gate. Ships *with* Distribution.
> Not blocked — a build we own. Bearer runtimes connect today; this adds the OAuth-only enterprise fleet.

### Depends on: M1 shipped (nothing else)

| # | GH | Task | Notes |
|---|-----|------|-------|
| T60 | #408 | OAuth 2.1 + **DCR** authorization server (CF Worker, auth.mcpwp.net) | **DCR (Dynamic Client Registration) is mandatory for ChatGPT Business connectors** — no bearer accepted. Also unlocks Claude Managed (OAuth Vaults), ChatGPT App, Claude Connector, YouTube, GHL. Add PKCE. |
| T60b | — | Wire OAuth 2.1 + DCR into the mupot bus flock plane | Same auth server admits OAuth runtimes (ChatGPT Business, Claude Managed) into a pot's flock, not just MCPWP-on-WP. |
| T61 | — | ChatGPT App Directory + Workspace-Agent connector submission | Needs T60 (OAuth 2.1 + DCR) |
| T62 | — | Claude Connector directory submission | Needs T60 |

### Bearer fleet — connects NOW, no new auth (do these in parallel, pre-OAuth)

| Runtime | Always-on? | Pack priority | Why |
|---------|-----------|---------------|-----|
| Hermes Agent (Nous) v0.16 | ✅ daemon + subagents + cron | **1st** | Persistent + bearer → the unattended autonomous loop, zero auth dependency |
| openclaw (systemd) | ✅ daemon | **1st** | Native streamable-http + bearer = cleanest bus client |
| Codex (desktop/CLI, GPT-5.4-Codex) | partial (Automations) | 2nd | Clean `url`+`bearer_token_env_var`; supervise CLI for true always-on |
| Claude Code / Cowork desktop | machine-bound | 2nd | Human-in-loop *flights*, not unattended *loops* |

### What I lack for M2-auth

OAuth 2.1 + PKCE + **DCR** is a significant build. Needs Opus-level spec session before implementation.
**Transport note:** confirm the bus serves **streamable-http** (SSE deprecated 2026-04) — required by Codex + openclaw.

---

## M4 — Hosted Agent + Resold Compute (v3.2)

### Depends on: M3 (OAuth layer)

| # | GH | Task | Notes |
|---|-----|------|-------|
| T100 | #429 | McpAgent Worker (agent.mcpwp.net) | Stateful DO per session. BYOK or pooled Workers AI. **Spec: Opus** |
| T101 | #430 | AI Gateway for all LLM calls | Free logging + per-site cost tracking |
| T102 | #431 | Vectorize semantic tool search | 85% token reduction per agent turn |
| T113 | #435 | Telegram bot client | First chat platform — no OAuth, inline approval keyboards |
| T114 | #436 | Slack bot client | Team member play for agencies |
| T115 | #437 | Discord bot client | Developer/OSS audience |

---

## M5 — Content Engine (v4.0)

### Depends on: M4 (McpAgent + OAuth)

| # | GH | Task | Notes |
|---|-----|------|-------|
| T110 | #432 | Content Engine spec (post → video → YouTube → GHL) | **Spec: Opus** |
| T111 | #433 | Remotion branded video templates | React → MP4, brand props from wp_remember |
| T112 | #434 | GoHighLevel integration | Social + CRM, OAuth via T60 |

---

## M6 — Platform Foundations (v5.0)

### Depends on: M5 shipped, community stable

Clean-slate internals. One breaking release, done right.

| # | GH | Task | Notes |
|---|-----|------|-------|
| T126 | — | Rebrand: `spai_` → `mcpwp_`, REST `site-pilot-ai/v1` → `mcpwp/v1` | Deprecation shims for one major version. **Spec: Opus** |
| ~~T127~~ | — | ~~Microkernel refactor~~ → **DONE as Modular Monolith** (ADR 0001) | Microkernel rejected (can't isolate untrusted code). Shipped G1-G5: 5 big files split (rest-site/free/pro/admin/elementor) + gateway contract on `mcpwp_register_tools`. PRs #488-492. |
| T128 | — | `mcpwp:dev` skill (updated post-microkernel) | Agent onboarding for the new module pattern |

**Rule:** T126 + T127 ship together in v5.0. One breaking change window, not two.

---

## M7 — Snapshot System (v6.0)

### Depends on: M4 (hosted agent) + M6 (microkernel makes packaging clean)

Generalize the P0 proof loop into reusable **vertical snapshots**. A snapshot = a **mupot pack**
(agent squad + marketing skills + channel binding + governance) **+ an MCPWP site-blueprint**
(WordPress site: pages, Woo/LearnPress config, SEO, brand-crystal). Both halves already exist as
contract-defined bundles (mupot `packs/` + `wp_deploy_site_blueprint`).

| # | Task | Notes |
|---|------|-------|
| T130 | Snapshot format spec (mupot pack ⊕ MCPWP blueprint) | The contract that fuses both halves. **Spec: Opus** |
| T131 | WooCommerce vertical snapshot | First productized snapshot — opinionated stack (ship theme + required plugins → portability) |
| T132 | LearnPress vertical snapshot | Second vertical (course creators) |
| T133 | mupot ↔ MCPWP harness pack | A mupot flock agent gets a scoped MCP connection to the client's WP site + marketing skills. Seam already in pack contract. |
| T134 | Snapshot deploy/clone flow | One-click deploy a snapshot into a fresh pot + WP site (the GHL-clone equivalent) |

**Hard problem to respect:** WordPress is heterogeneous (themes/builders/plugins) where GHL is uniform.
Solve with **opinionated stack per vertical** (snapshot ships the theme + plugin set) — uniformity is
what makes snapshots portable. Do NOT target arbitrary existing sites first.

---

## M8 — Agency Reseller / SaaS-Mode (v6.x) — THE FULL VISION

### Depends on: M7 (snapshots) + M3 (auth) + sovereign mupot deploy

The full GHL-style motion, on the sovereign substrate.

| # | Task | Notes |
|---|------|-------|
| T140 | Agency white-label SaaS-mode | Rebrand + resell snapshots as the agency's own product |
| T141 | Client sub-billing + compute markup | Agency rebills clients; pass model cost through with markup (GHL SaaS-mode model) |
| T142 | Snapshot marketplace | Agencies publish/sell vertical snapshots (the GHL snapshot-marketplace analog) |
| T143 | Sovereign one-click deploy | Fork → deploy mupot to the customer's OWN Cloudflare ("fork it, own it") |

**This is THE FULL ONE.** Build NOTHING here until the P0 loop + at least one M7 snapshot are proven.
Marketplace before a working snapshot = death.

---

## Technical Debt (fix as we go)

| # | GH | Severity | Task |
|---|-----|----------|------|
| T120 | #442 | medium | Split free/pro tool files — 2000+ line classes block contributors |
| T121 | #443 | high | PHPUnit tests — zero PHP test coverage, silent deploy failures |
| ~~T122~~ | #444 | high | ~~Rewrite 120+ tool descriptions for BM25/vector accuracy~~ | ✅ PR #446 |
| ~~T123~~ | #445 | launch-blocker | ~~Update openapi-chatgpt.yaml to v2.8.49~~ | ✅ PR #446 (v2.8.50) |
| T124 | — | medium | `mcpwp:dev` skill — agent onboarding for plugin dev: add-a-tool pattern, version bump 3-file rule, local test stack, CI, `mcpwp_register_tools` usage |
| T125 | — | medium | Update `mcpwp:tools` skill — stale at 239 tools, missing custom tool registry, missing new endpoints |

---

## Shipped (v3.3.7–v3.3.8)

- **v3.3.7:** feat(T50): MCP `resources/list` returns 6 real site resources (site-info, site-context, pages, posts, design-references, elementor-status); `resources/read` proxies to internal `mcpwp/v1` REST endpoints with API key forwarding; feat(T51): MCP `prompts/list` returns 5 named templates (audit-seo, build-page, refresh-content, onboard, design-match); `prompts/get` fills arguments and returns `messages[]`; both declared in `initialize` capabilities
- **v3.3.8:** feat(staging/Pro): staging push/pull feature — WP Engine, Kinsta, Pressable native REST API adapters + Generic WP-CLI fallback; 4 MCP tools (`wp_get_staging_info`, `wp_push_to_staging`, `wp_push_to_live`, `wp_get_staging_status`); REST endpoints `GET /staging`, `POST /staging/push`, `GET /staging/status/{id}`; push-to-live gates via approval request (same-key self-approval blocked; admin scope required); env type re-verified at apply time

## Shipped (v3.3.4–v3.3.6)

- **v3.3.4:** fix(security): SSRF redirect bypass in `wp_get_rendered_copy` fetcher — `redirection=0` was missing from the loopback `wp_remote_get` call; redirect chains could bypass the same-host allowlist (closes #575 precursor)
- **v3.3.5:** feat: before/after change summaries on mutation tools — `wp_update_page` and `wp_update_post` responses now include `changes[]` (field, before, after) and `unchanged[]`; `wp_set_elementor` includes `structural_changes` (sections/widgets before+after); fix(security): `sslverify=false` in Elementor CSS-prime loopback fixed to respect `WP_DEBUG`
- **v3.3.6:** fix(#575): `wp_upload_media_b64` MCP schema now exposes `mime_type` param (REST already accepted it); fix(#576): `wp_update_menu_item` tool description and error messages include actionable ID-lookup hints (`wp_list_menus` / `wp_list_menu_items`); feat(#577): `wp_list_media` gains `search` param (filter by title/filename) + image-reuse workflow guidance in tool description; fix(#578): `wp_upload_design_reference` tool description and core `missing_image` error include retry guidance (upload separately → `media_id`)

## Shipped (v3.3.0–v3.3.3)

- **v3.3.0:** Validator improvements — all validators (`validate_seo_readiness`, `validate_structured_data`, `validate_internal_links`, `validate_blocks`, `audit_seo_site`) now return `validation_basis` + `validation_note`; `validate_seo_readiness` fetches live `<head>` for published pages; `validate_structured_data` parses JSON-LD including Yoast/AIOSEO `@graph`; `validate_internal_links` walks `_elementor_data` alongside `post_content`; security: batch sub-request bypass closed (static property replaces `X-SPAI-Batch-Sub-Request` header); `sslverify=false` → `!(WP_DEBUG)`; SSRF redirect bypass closed (`redirection=0` on all same-host-guarded `wp_remote_get` calls) — ✅ #568, #569, #571 closed
- **v3.3.1:** `/onboard` now returns `suggested_first_tools` (context-aware) and `quick_start` string — ✅ #573 closed
- **v3.3.2:** `wp_create_page`/`wp_update_page` document `parent`, `template`, `menu_order`; `wp_create_post`/`wp_update_post` document `categories` (array of IDs) and `tags` (array of slugs)
- **v3.3.3:** `GET /rendered-copy` → `wp_get_rendered_copy` (Free) — structured visible text from live rendered page (headings, paragraphs, button labels, list items, link text, image alts); `POST /cache-flush` → `wp_flush_cache` (Pro) — flushes W3TC, WP Super Cache, LiteSpeed, WP Rocket, Cache Enabler, Breeze, Cloudflare — ✅ #574 closed

## Shipped (v2.8.45–v2.8.55)

- Server-side PostHog analytics
- Agency multi-site proxy (CF Worker)
- AI action audit log + rollback (EU AI Act)
- Agency dashboard
- White-label branding + `[mcpwp_chat]` shortcode
- Dynamic site memory (`wp_remember` / `wp_recall`)
- Proactive signals (`wp_get_signals`)
- Site blueprint library (5 starters)
- Chat excellence (multi-model, SSE, history)
- **v2.8.50:** `spai_register_tools` filter hook API; 56 BM25-optimized tool descriptions; openapi-chatgpt.yaml 49 operations; 5 pre-existing PHP syntax errors cleared; test bootstrap fixed
- **v2.8.51:** find-replace false-negative + structural-corruption fix (decoded-tree match, protected keys); update-channel drift cured (manifest 2.8.43→2.8.51, R2 download_url 404 fixed); ElementorFindReplaceTest regression suite (PR #448)
- **v2.8.52:** `wp_keyword_research` — keyless keyword research via Google Suggest (keywords + grouped questions); first Phase-1 capability for the autonomous content loop (PR #456, closes #454)
- **v2.8.53:** Sprint 11 batch 1 — Setup onboarding checklist (3-step), chat empty-state notice, WCAG-AA toggle focus ring, dead 104KB partial removed (PR #468)
- **v2.8.54:** Sprint 11 batch 2 — Control Room / Library / Integrations UX, a11y label associations + 1024px breakpoint + fluid chat height (PR #469, closes #463-466)
- **v2.8.55:** Sprint 11 batch 3 — inline JS consolidated to one enqueued asset, PostHog double-init fixed, design tokens + radius scale + badge consolidation, chat-history JSON tag-escaped (XSS hardening, reviewer-caught) (PR #470, closes #461-462)
- README rebuilt (OpenClaw/Hermes/ChatGPT sections)
- ClawHub skill (`integrations/clawhub/SKILL.md`)
- Hermes integration (`integrations/hermes/`)
- GH label taxonomy + 60+ issues filed

---

## Architecture Notes

### T126 — Rebrand (`spai_` → `mcpwp_`)

Every public surface uses the wrong prefix today:
- WP option keys: `spai_api_keys`, `spai_settings`, `spai_site_uuid`, …
- Hook names: `spai_tool_called`, `spai_register_rest_routes`, `spai_register_tools`, …
- REST namespace: `site-pilot-ai/v1` (should be `mcpwp/v1`)
- Class names: `Spai_*` throughout
- MCP tool names: `wp_*` (these are fine — keep)

**Breaking change scope:** Any site with custom hooks on `spai_*` or hitting `site-pilot-ai/v1` directly breaks. Must ship with:
1. Deprecated aliases for all old option keys (copy on read, write new)
2. Old REST namespace forwarded to new for one major version
3. `spai_tool_called` → fires both old + new name during transition

**When:** v3.0, after WP.org submission. Do not do before launch — WP.org review + existing installs.

### T127 — Microkernel Refactor

Current structure: two monolithic classes (`Spai_MCP_Free_Tools`, `Spai_MCP_Pro_Tools`) each 2000+ lines, extending a base registry. All 250+ tools live in two files.

**Target structure (microkernel):**
```
includes/
  kernel/
    class-mcpwp-kernel.php        # bootstrap, hook registry, dispatch
    class-mcpwp-tool-dispatch.php # MCP tool routing (replaces class-spai-rest-mcp.php core)
  modules/
    pages/       module.php       # registers wp_create_page, wp_update_page, …
    elementor/   module.php       # registers wp_get_elementor, wp_set_elementor, …
    media/       module.php
    seo/         module.php
    memory/      module.php
    blueprints/  module.php
    …
  api/           (unchanged — REST controllers stay)
```

Each `module.php`:
```php
add_filter('mcpwp_register_tools', function($tools) {
    $tools[] = [ 'name' => 'wp_create_page', ... ];
    return $tools;
});
```

**Result:**
- T120 (split files) becomes the first migration step
- Free/Pro split is a capability filter on the module, not a class hierarchy
- Third-party plugins (Digid, WooCommerce ext) use exact same pattern as built-ins — no special case
- Contributors can add a tool by editing one focused module file
- Modules are independently testable

**Dependency:** T126 (rebrand) should ship same release — do both as v3.0.
**Spec:** Needs Opus session before implementation. Not mechanical work.

---

## Decisions Made

| Decision | Rationale |
|----------|-----------|
| ChatGPT Custom GPT first, App Store later | Custom GPT works with X-API-Key today. App Store needs OAuth. |
| OAuth via central CF Worker | One submission URL. One OAuth flow per user. |
| Claude Connector via Desktop Extension first | MCPB handles per-user URL + API key — ships before OAuth. |
| Don't publish ClawHub/Hermes until tested | OpenClaw bug #65590. Ship quality, not speed. |
| WP.org free version separate build | `scripts/build-wporg.sh` strips pro. Don't submit full zip. |
| Close premium to private repo | Standard commercial WP model. Decision pending (#423). |

---

## Needs From Hadi

| Item | Blocks |
|------|--------|
| Confirm pricing (Free/Pro/Agency numbers) | T87 pricing page, T92 onboarding email |
| Demo site (live public WP) | ChatGPT GPT test, video recording |
| ChatGPT Plus account | T11 Custom GPT, T15 App Store |
| Product Hunt account + launch date | T46 |
| Discord server creation | T91 |
| WAF rules in CF dashboard | T98 |
| Privacy policy approval | T86 |
| WP.org account | T25 |
| Premium repo decision | T93 |

---

## Key Files

| File | Purpose |
|------|---------|
| `BACKLOG.md` | This file |
| `docs/FREE_PRO_SPLIT.md` | Free vs Pro tool tier |
| `docs/openapi-chatgpt.yaml` | ChatGPT schema (stale, needs T10) |
| `integrations/clawhub/SKILL.md` | OpenClaw ClawHub skill |
| `integrations/hermes/SKILL.md` | Hermes Skills Hub skill |
| `integrations/hermes/README.md` | Hermes setup guide |
