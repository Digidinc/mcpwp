# MCPWP v4.0 Roadmap

Status: **DRAFT v1** — written 2026-07-18, before the 3.4.13 wp.org security/stability
audit results. Section 7 will be revised once the audit workflow completes.

Owner: Hadi. Baseline: MCPWP 3.6.1 (Freemius + self-hosted), mumega-mcp 3.4.13
(wp.org, in review round 4), ADR 0001 accepted (Gateway + Modular Monolith, PR #487).

---

## Why 4.0

Three forces justify a major version:

1. **Architecture** — ADR 0001's Gateway + Modular Monolith refactor (G1–G7) changes
   internal structure and the extension contract. That is a major-version event.
2. **Connection model** — moving from "API key pasted into a client" to OAuth-first,
   zero-touch connect. Changes the default onboarding path for every new user.
3. **Channel maturity** — four distribution channels (Freemius Pro, self-hosted,
   wp.org free, agency) now exist but are hand-assembled. 4.0 makes them one build
   matrix with CI-verified artifacts.

The 3.5.x–3.6.x cycle proved the product logic is solid (362 tests green, tools
verified live) and the packaging/ops layer is the weak organ: dead-SDK Freemius zips,
duplicate defines on main, double-loaded plugin on production, stale update channels.
4.0's center of gravity is **reliability of delivery**, not new tool count.

---

## Workstream A — Release Engineering (the pipeline)

Goal: a packaging bug of the class we shipped in 3.5.x becomes structurally impossible.

- **A1. Single build matrix.** One `build.sh --channel {freemius|selfhosted|wporg|agency}`
  replacing the four divergent scripts. Channel differences declared in one manifest
  (files stripped, constants injected, slug/text-domain rewrites), not duplicated
  bash. `release_freemius.sh` becomes a thin wrapper over it.
- **A2. Artifact verification gate.** Post-build, pre-upload checks that run on the
  actual zip: constant-injection asserts (`MCPWP_FREEMIUS_BUILD`, `MCPWP_WPORG_BUILD`),
  duplicate-`define()` lint, `php -l` every file, activation smoke test in a clean
  Docker WP with `WP_DEBUG=1` (fails on any warning — would have caught both the
  dead-SDK bug and the headers-already-sent cascade).
- **A3. CI-built releases only.** No zip uploaded to Freemius/wp.org/R2 that wasn't
  built and verified in CI. release-please cuts versions; humans approve, never
  hand-assemble.
- **A4. wp.org branch sync.** Automated cherry-pick candidate list from `main` →
  wp.org branch (files in the reduced tree only), so the free build stops drifting.
  Kills the current hand-maintained `fix/wporg-approval-minimal` divergence.
- **A5. Update-channel health monitor.** Daily probe: version.json on both channels,
  Freemius latest tag, R2 zip hash — alert on drift between channels.

Exit criteria: one command releases all channels; a deliberately-injected duplicate
define or missing constant fails the gate.

## Workstream B — Zero-Touch Connect

Goal: a non-technical WordPress owner connects claude.ai (or any MCP client) in
under 2 minutes with no Cloudflare surgery and no pasted keys.

> **Status update (2026-07-21): B1 DELIVERED — via a hosted broker, not per-site OAuth.**
> The original B1 plan (a full OAuth 2.1 authorization server *inside each plugin*)
> was rejected (PR #598) — it puts a browser-driven, security-critical AS on every
> customer site (high blast radius, an inherent `/wp-json` login-loop, per-site
> maintenance). Instead we built the **mumcp.com OAuth broker** (Cloudflare Worker,
> repo `Mumega-com/mumcp-broker`), which hosts DCR + consent + token issuance **once**.
> The plugin shrinks to a token *validator* (cached RFC 7662 introspection) + a
> one-time enrollment client. Broker is **live on mumcp.com**; the plugin side ships
> in **MCPWP 3.7.0** (PR #686, adversarially gated, 580 tests). Admin capability is
> deliberately NOT grantable over OAuth (read/write only). Remaining: wire a real
> email provider for broker magic-link accounts; full browser-enrollment E2E needs a
> public site URL (broker SSRF-guards localhost). See
> `docs/superpowers/specs/2026-07-20-mumcp-oauth-broker-design.md`.

- **B1. OAuth 2.1 complete.** ✅ Delivered as above — Dynamic Client Registration
  (RFC 7591), consent, PKCE-S256, and token issuance are hosted on the mumcp.com
  broker (the maintained `@cloudflare/workers-oauth-provider` library), so claude.ai
  connectors register with zero manual client IDs. The plugin validates the
  resulting opaque tokens by broker introspection. Well-known discovery (fixed for
  subdirectory installs in 3.6.1) now points at the broker when a site is enrolled.
- **B2. Connection Doctor.** One endpoint + admin page that self-tests the full
  chain (REST reachable from outside, well-known docs served, Cloudflare challenge
  detection, headers-already-sent detection, double-load detection) and returns a
  human-readable verdict list. Surfaced in admin AND as an MCP tool so support can
  run it remotely.
- **B3. Cloudflare auto-remediation v2.** Extend the 3.5.7 fix button: detect
  challenge responses automatically (from the Doctor), offer one-click rule creation,
  verify after applying. Cover other WAFs (Wordfence, Sucuri) with guided steps.
- **B4. Output hygiene guard.** Bootstrap-level protection: MCP/REST responses
  buffer and discard stray PHP warnings/notices before JSON emission, with the
  discarded output logged to the activity log. A warning on a shared host must never
  corrupt the JSON-RPC body again (root cause of this week's connector registration
  failures).

Exit criteria: fresh site behind Cloudflare → claude.ai connected via OAuth with
zero manual config; Doctor green.

## Workstream C — Gateway Architecture (ADR 0001, G1–G7)

Goal: execute the accepted Gateway + Modular Monolith refactor.

- **C1. G1–G3:** module boundaries inside the monolith (core/content/elementor/
  media/seo/integrations), each with an explicit public interface; gateway routes
  MCP tool calls to modules; kill cross-module reach-ins.
- **C2. G4–G5:** untrusted addon isolation — custom tools from third-party code run
  through the gateway contract only (`mcpwp_register_tools` + callback transport
  from PR #657 is the seed of this); capability/scope enforcement centralized at
  the gateway, not per-module.
- **C3. G6–G7:** agency proxy (#360) — one gateway fronting N sites, key vaulting,
  per-site scoping; append-only audit log (#361) with export.
- **C4. Extension SDK.** Document + freeze the extension contract (feature-detect
  constants, callback transport, scope filter) as the public v4 API. mumega-motion
  theme integration (mumega-motion-theme#4) is the reference implementation.

Exit criteria: all 272 tools dispatch through the gateway; agency proxy manages ≥3
real sites; motion theme updates itself via the documented contract.

## Workstream D — wp.org Channel

Goal: approved listing, then keep it alive cheaply.

- **D1. Land current review round:** remove dead `media.php` requires; either inline
  `permission_callback` per route (so static analysis sees it) or reply with the
  `route_definition()` explanation — decision after audit. Address every audit
  finding in the same upload; aim for round 5 = approval.
- **D2. Post-approval cadence:** free build releases monthly from the build matrix
  (A1/A4), changelog synced (`sync-wporg-changelog.mjs`), assets maintained.
- **D3. Free→Pro funnel:** `pro_required` telemetry (3.2.2+) wired into upgrade
  prompts; wp.org readme + admin upsell → mcpwp.net checkout with install-linked
  Freemius URLs (fixed in 3.5.x).

## Workstream E — Product Depth (differentiators, not breadth)

No new tool sprawl in 4.0. Deepen what wins deals:

- **E1. Elementor v4 atomic first-class** — follow Elementor's atomic widget rollout;
  keep validation/auto-fix ahead of competitors. Elementor stays the *compatibility*
  surface (installed base), not the strategic bet — see Workstream T.
- **E2. Site blueprints + agent playbooks** as the "agency in a box" story: export a
  finished site's structure, deploy to a new install in one MCP call. Blueprints
  target both Elementor (existing sites) and Motion (new sites, cleaner deploys —
  no widget-schema validation layer needed).
- **E3. Approvals/rollback maturity** — approval-gated writes as the default posture
  for agency tier; rollback coverage for every mutating tool class.
- **E4. Site memory + signals** — persistent AI context per site; this is the moat
  against generic WP MCP servers.

## Workstream T — Mumega Motion (first-party theme)

Own theme = strategic pillar, not an integration example. Motion (React streaming,
~34KB gzipped, Figma token pipeline via MCPWP sync backend) gives us the only full
stack in the market: plugin + theme + blueprints = complete AI-managed site.

- **T1. Self-update contract** (mumega-motion-theme#4): register
  `wp_update_mumega_motion` / `wp_rollback_mumega_motion` via the callback transport
  (PR #657). First consumer of the extension SDK — hardens C4 with real usage.
- **T2. MCP-native editing surface.** Motion sections/tokens editable directly via
  MCP tools — no page-builder JSON contract we don't control. Target: AI builds a
  full Motion page with zero validation warnings, faster than the Elementor path.
- **T3. Figma → tokens → live theme** pipeline productized: design-token sync as a
  demoable feature (nothing comparable exists in WP). Ships with a guided flow in
  the Integrations admin.
- **T4. Blueprint-first onboarding:** new-site flow offers Motion + blueprint deploy
  as the default "AI site in minutes" path; Elementor remains the brownfield path.
- **T5. Distribution decision (flag to Hadi):** wp.org theme directory submission vs
  bundled-with-Pro vs standalone sale. Affects review burden and update channel.

Strategic effect: reduces existential dependency on Elementor's roadmap. Elementor
= serve the installed base (E1); Motion = own the greenfield (T2/T4).

## Workstream F — GTM (parallel, not blocked by code)

- **F1.** wp.org approval → launch content wave (the listing is the discovery channel).
- **F2.** Woo beachhead per GTM memory: WooCommerce SEO + product tooling demos.
- **F3.** Pricing: free (wp.org) / Pro (Freemius) / Agency (proxy + audit log) —
  agency tier priced on sites-under-management.
- **F4.** Case studies: DME as first documented agency deployment once stable.

---

## Sequencing

| Phase | Target | Contents |
|-------|--------|----------|
| 4.0-alpha | +6 weeks | A1–A3 (build matrix + gates), B4 (output guard), D1 (wp.org round 5) |
| 4.0-beta | +12 weeks | B1–B3 (OAuth DCR, Doctor, CF v2), C1–C2 (G1–G5), A4–A5, T1 (theme self-update) |
| 4.0 GA | +18 weeks | C3–C4 (agency proxy, audit log, extension SDK), E1–E2, T2–T3 (Motion MCP surface + Figma pipeline) |
| 4.1 | GA+8 weeks | E3–E4, T4–T5 (blueprint-first onboarding, theme distribution), F-driven iterations |

Rationale: pipeline first (everything else ships through it), connect second
(top-of-funnel), architecture third (needs the safety net of A2's smoke gates),
product depth last (already ahead of market).

## Risks

- **Refactor under live churn** — G1–G7 while shipping 3.6.x fixes; mitigate with
  A2 gates landing first and module-by-module migration.
- **wp.org review stall** — rounds are ~weekly; keep D1 changes minimal and surgical,
  don't bundle refactors into review uploads.
- **Solo-maintainer bus factor** — build matrix + CI (A) is also the mitigation:
  releases stop depending on tribal knowledge.
- **Freemius dependency** — single licensing vendor; keep self-hosted channel
  first-class as hedge.

## Section 7 — Audit deltas (2026-07-18 audit complete)

Full findings + change plan: `docs/wporg-audit-2026-07-18.md`. 27-agent adversarial
audit of the mumega-mcp 3.4.13 wp.org zip: 17 confirmed (1×P1, 2×P2, 14×P3),
5 refuted. No P0. Roadmap impact:

**D1 sharpened.** wp.org round 5 = one upload (3.4.14) carrying both reviewer
findings + 15 self-found fixes. Key correction: the reviewer's own suggested fix
(delete the media.php require) is WRONG — that require is transitively load-bearing
for audio/video metadata (`wp_read_video_metadata`/`wp_read_audio_metadata` live in
media.php); right fix is delegating to `media_handle_upload()`/`media_handle_sideload()`
so media.php loads-and-immediately-uses (their documented exception). The 11-route
`permission_callback` flag is a confirmed static-analysis false positive — remedy is
literal-key-per-call-site, not an auth change.

**One real security class → backport to main (Workstreams B/C):**
- **Pre-auth enumeration oracle** (P2, findings #3/#16): permission callbacks run
  `get_post()` existence/type/status checks *before* API-key verification → 404-vs-401
  lets an anonymous caller enumerate valid IDs, distinguish post/page/attachment, and
  detect drafts. Present on `main` too. Fix rule "authenticate first, resource-check
  second" becomes a standing gateway auth rule (C2) + Connection Doctor / Plugin Check
  assertion (B2).

**New A2 gate items (this audit's bug classes → CI checks):**
- Pre-auth response-shape differential (missing-resource vs unauthorized must be
  indistinguishable to an unauthenticated caller).
- Config-default consistency lint (findings #14/#15: limiter default 30 vs
  activator/UI 10 — enforced value must equal displayed value, one source of truth).
- Dead-hook / dead-code detector (findings #8, #12: registered hooks that never fire,
  routers no route reaches).
- Spoofable-client-identifier check (finding #9: forwarded IP headers honored without
  a trusted-proxy gate).

**Least-privilege review (C2):** finding #4 — the low-privilege `mcpwp_api_agent`
role carries `mcpwp_manage_settings`, collapsing admin-route capability gates to
scope-only. Role/capability matrix gets an explicit audit as enforcement centralizes
at the gateway.

Net: the audit added no scope to 4.0 — it *validated* Workstream A (a smoke/gate
pipeline would have caught the media-fatal risk, the default mismatch, the dead
hooks) and B/C (auth-ordering + least-privilege belong in the gateway, not scattered
per-callback). Immediate action: D1 (ship 3.4.14) + a targeted `main` patch for the
P2 oracle.
