# MCPWP plan: 3.14.x through launch

Written 2026-09-29 (3.14.8 is live). Owner of this document: the operating agent. Update the
**Status** column as work moves, and keep GitHub milestones in step. A work item isn't done
until its "Done when" line is true and verified.

## Where we are

- **3.14.10 is live** on `updates.mcpwp.net` and Freemius (2026-09-30: tool weight budget, #1064;
  3.14.9 the same day: approvals table, #1042). **3.14.11** is next: the Elementor collateral-strip
  fix (#829, PR #1083, in adversarial review). In three days, 3.14.1 to 3.14.8
  shipped: site graph, MCP protocol 2025-11-25, tool profiles and search, WebMCP in wp-admin,
  post-type and template gating for restricted keys, trustworthy approvals, and action-log
  filtering.
- **Freemius is in step:** every release since 3.14.8 publishes to both fleets from the Release PR
  merge. mcpwp.net runs 3.14.9 (owner updated it 2026-09-30; agents can't update it, #1070).
- **Almost nobody uses it yet.** mcpwp.net had 41 visitors in the 30-day baseline, with 84%
  bounce. The engineering is ahead of distribution.
- **Repo moved to `Digidinc/mcpwp`** (2026-09-29). Actions runs again. One gap: the enterprise policy stops Actions
  opening PRs, so the Release PR is opened locally (0.1b, `docs/RELEASE_CHECKLIST.md`).

## Principles for this plan

1. **Ship reach before features.** Code that no site runs is worth nothing.
2. **Freeze new security hardening** unless it's a P0, a real user report, or part of a work
   item below. 3.14.4 to 3.14.8 hardened restricted keys thoroughly; the next hour is worth more
   spent on users.
3. **Fewer, better tools.** About 270 tools is a weakness. Routing, consolidation and presets
   (Phase 2) make MCPWP easier for agents and owners.
4. **Every release is small, smoke-tested and verified live.** No batching for weeks.

---

## Phase 0: unblock shipping (now)

| # | Work | Issue | Who | Status | Done when |
|---|---|---|---|---|---|
| 0.1 | Fix GitHub Actions billing | none | Owner + agent | **done 2026-09-29:** repo moved to Digidinc (enterprise); workflows run | A workflow run starts |
| 0.1b | Enterprise policy: allow GitHub Actions to create pull requests (Digidinc enterprise → Policies → Actions → Workflow permissions) | none | **Owner** | waiting | release-please workflow opens the Release PR by itself |
| 0.2 | Publish 3.14.8 to Freemius via `publish-freemius.yml` | #1045 | Agent | **done 2026-09-29** (run 36636410466, verifier OK) | `verify_freemius_channel.sh --expect-version 3.14.8` passes |
| 0.3 | Update mcpwp.net through Freemius | #1045, #1070 | Owner clicks Update (agents can't: `wp_trigger_update` answers `not_applicable` on a Freemius build, #1070) | **done 2026-09-30:** mcpwp.net on 3.14.9; repeat per release until #1070 ships | mcpwp.net `wp_site_info` shows the live version |
| 0.4 | npm republish `@mcpwp.net/mcpwp` (npm has 3.0.1; source is 3.0.3) | #939 | **Owner** runs `npm publish` in `mcp-server/` (npm login is not on this server), or gives an automation token | waiting | `npm view @mcpwp.net/mcpwp version` = source version |
| 0.5 | Legal name on Privacy & Terms | #1050 | Agent | **done 2026-09-29:** "Digid Inc., a Canadian corporation" on page 118; footer "© 2026 Mumega · Digid Inc." (Mumega is a Digid product; keep the Mumega brand) | Page 118 live |

If 0.1 isn't fixed within a day, ask the owner for the other route: run the Freemius publish
once with their own token (`FREEMIUS_BEARER_TOKEN=… bash scripts/release_freemius.sh --version
3.14.8 --skip-bump`). Never read or bypass the gated secret yourself.

## Phase 1: finish reliability (3.14.x patch train)

Pick in this order (priority, not strict serialization; see OPERATING-RULES §1). Each item is one
PR, one builder. All of these are in GitHub milestone **3.14.9**.

| # | Work | Issue | Review | Status | Done when |
|---|---|---|---|---|---|
| 1.1 | Approvals store moves from one serialized option to its own table (migration, lock-free claims, keep history). **Brief ready: [briefs/1042-approvals-table.md](briefs/1042-approvals-table.md), Opus builder** | #1042 | **adversarial** (write path + auth) | **done: shipped in 3.14.9 (2026-09-30)** after 6 adversarial rounds; PR #1052; follow-ups #1053, #1067 | Migration runs on upgrade in the smoke test; approve/apply/rollback pass; no option writes remain |
| 1.2 | `wp_create_post` with `post_type=page` answers "Post not found" after creating | #1039 | normal | todo | Test plus rig: creates and returns the page |
| 1.3 | WooCommerce write tools (`wc_*`) not recorded in the action log | #1037 | normal | todo | Every `wc_*` write appears in `/action-log` |
| 1.4 | Woo `image_id` / `gallery_image_ids` gating and graph aggregate counts | #1034 | **adversarial** | todo | Gated key can't attach a hidden attachment; counts exclude hidden nodes |
| 1.5 | `/posts?post_type=unknown` returns 200 with a WP_Error body | #1005 | normal | todo | 400 with a clear code |
| 1.6 | Provider record overwrite on `POST /integrations/providers/{id}` | #1004 | normal | todo | Multi-field provider keeps other fields |
| 1.7 | html widget edit reports `render_verified:false` falsely | #999 | normal | todo | Correct flag on a real html widget edit |
| 1.8 | Verification: assert the DOM, not just meta | #989 | normal | todo | render_verified fails on a real broken render in a test |
| 1.9 | **Elementor writes strip script/style/svg from widgets nobody edited** (hit on mcpwp.net: PostHog script and logo SVG lost). One chokepoint (`Mcpwp_Elementor_Meta_Write`); unedited elements keep their stored content, anything introduced is sanitized by MCPWP itself | #829 | **adversarial** (content write path) | **in review:** PR #1083, rounds 1–2 found blockers, round-2 fixes in progress, round 3 next. Ships as 3.14.11. Until then: don't API-edit live Elementor documents that hold html widgets with script/style/svg | A round comes back clean; smoke test; live ZIP proves the fix |
| 1.10 | Test suite fails in random order; flaky `OptionAccessTest` | #1084 | normal | in progress (builder) | Default order plus 5 random seeds green |
| 1.11 | Updater on Freemius and agency sites: `wp_trigger_update` is `not_applicable`, `package_url` unsafe there | #1070 | **adversarial** (update channel) | todo | An agent updates mcpwp.net without wp-admin |
| 1.12 | Custom Code read fatals after a conditions re-save; action log `resource_id` 0 | #1082 | normal | todo (owner fixes snippet 682 by hand meanwhile) | Read works on the rig repro |
| 1.13 | `dry_run` publishes a template | #1078 | normal | todo | dry_run writes nothing |
| 1.14 | Follow-ups: approvals (#1053, #1067), tool budget round 3 (#1079), docs reference (#1073), proxies drop server instructions (#1074) | listed | normal | todo | Each closed |

**Released:** 3.14.9 (1.1), 3.14.10 (2.0a). **Next: 3.14.11** when 1.9 merges. Then release again
each time a user-visible fix merges.

## Phase 2: make tools easier (agent-native)

This is the answer to "270 tools is too many". Three layers, cheapest first.

| # | Work | Issue | Review | Status | Done when |
|---|---|---|---|---|---|
| 2.0a | **Tool weight budget** (owner priority 2026-09-30: "our MCP is heavy"). `core` is the default profile for sites that never chose one: about 23 tools, ~4,200 tokens on connect (was 194 tools, ~44k). Descriptions capped at 300 characters, examples moved to `mcpwp_find_tools`, `wp_introspect` answers a summary by default. `ToolListBudgetTest` enforces core ≤5k; `full` is pinned at measured +5% (Free 34,700, Pro 55,300) until 2.3 shrinks the catalog | #1064 | adversarial (3 rounds: meta tools, addon isolation, per-caller introspect) | **done: shipped in 3.14.10 (2026-09-30)**; PR #1071; follow-ups #1079 | Budget test green; before/after table in the PR |
| 2.0b | **Agent-ready docs content:** 10 concept pages, a generated reference (313 tools, errors), `llms.txt` + `llms-full.txt`; `scripts/gen-docs-reference.mjs` regenerates the reference each release | #1065 | normal | **content done** (PRs #1072, mcpwp-docs#1; docs eval 10/10). Not deployed: publishing moved to 2.0c | 10-question docs eval passes from `llms.txt` alone |
| 2.0c | **Stripe-like docs on mcpwp.net, built with Elementor Pro through MCPWP's own tools.** Owner decision 2026-09-30: MCPWP does NOT become a docs plugin; no plugin code. Pages under /docs/, a Theme Builder "Docs page" template (left nav, search, breadcrumbs, content, sticky table of contents), and a git→pages sync script. D1 design → D2 sync script (`scripts/docs-sync.mjs`) → D3 content as drafts, owner preview, publish, 301 docs.mcpwp.net | #1075 | owner approves the design before publish | **D1 done as drafts, waiting on the owner's design review** (pages 1273–1277, template 1288, menu 142; preview `https://mcpwp.net/?page_id=1273&preview=true`). D2/D3 don't start before that approval, and D3 waits for 1.9 | mcpwp.net/docs live; releases sync docs |
| 2.1 | **Job presets** for keys and the Tools page (Content writer, Designer, SEO, Store manager, Full) | #1048 | normal | todo | Presets selectable; each maps to the tested category set |
| 2.2 | **Semantic tool routing:** Jev re-ranks `mcpwp_find_tools`, keyword fallback; OpenAI Decisions API later as a second transport | #1046 | **adversarial** (outbound data + visibility) | todo | 10-intent before/after hit rate in the PR; no hidden-tool leak |
| 2.3 | **Tool catalog audit:** cluster duplicates with Jev offline, keep/merge/deprecate table, owner approves, ship deprecation flags | #1047 | normal (owner approves the list) | todo | 150 or fewer visible tools in `full` |
| 2.4 | `wp_look`: the agent sees its page (screenshot plus layout facts) | #908 | normal | todo | Tool returns an image plus facts on the rig |
| 2.5 | Generated per-site playbook and full schemas in `wp_introspect` | #812 | normal | todo | |
| 2.6 | Onboarding: connect your own agent (free) or MCPWP runs itself (paid) | #955 | normal | todo | |
| 2.7 | Opt-in approval path for Elementor and SEO writes | #930 | **adversarial** | todo | |

### About Jev and the OpenAI Decisions API (context for 2.2 and 2.3)

Both are "decision" models: you give them some state plus a finite set of named options, and
they return the chosen option with probabilities, in about 150ms. They don't write text.

- **Jev** (TypeSafe) is already in MCPWP (`Mcpwp_Jev`; TypeSafe or Cloudflare Workers AI
  transport; #935, #987). It ranks the approval queue today. It costs about $0.04 per million
  input tokens.
- **OpenAI Decisions API** was announced 2026-09-29 as a limited preview. Add it as a second
  transport behind the same `evaluate()` once it's generally available and documented. Don't
  build against guesses.

The rule for both: **they route, they never gate.** A decision model can reorder what an agent
or owner sees. It never approves, rejects, applies, or grants a tool the caller couldn't already
call.

## Phase 3: go to market (runs in parallel with Phases 1 and 2)

| # | Work | Issue | Who | Status | Done when |
|---|---|---|---|---|---|
| 3.1 | Video orders to the owner's video team, in Linear (see §Media) | Linear | Agent files, video team builds | todo | Tickets exist with scripts and assets |
| 3.2 | mcpwp.net launch refresh: what's new, WebMCP page, Digid legal, video embed, PHP warnings, funnel | #1050, #961, #959 | Agent (via MCP on mcpwp.net) | partly blocked (0.3, 0.5) | Each item live and fetched |
| 3.3 | Directory submissions: MCP registry, Smithery, Glama, mcp.so, Claude connector directory, Meta Muse | #960, #904 | Agent prepares copy; **owner** signs in where an account is needed | todo | Listed, or copy ready and waiting on owner sign-in |
| 3.4 | "Watch the agent work" live feed on mcpwp.net (Motion theme) | #1049 | Agent | todo | Live, adversarial review clean |
| 3.5 | Case studies: Viamar, Stemminds, DME, mcpwp.net's own numbers | #964 | Agent drafts; owner approves names | todo | Published |
| 3.6 | Chrome origin-trial token for WebMCP on mcpwp.net | none | **Owner** registers at developer.chrome.com/origintrials (Google sign-in); agent pastes it into Settings → WebMCP | waiting | `<meta http-equiv="origin-trial">` present in mcpwp.net wp-admin |
| 3.7 | North-star check: does an AI recommend MCPWP? (monthly) | #958 | Agent | monthly | Result recorded in `docs/growth/NORTH-STAR.md` |
| 3.8 | **ChatGPT plugin directory listing** (DevDay 2026: ChatGPT recommends plugins in conversation). Highest-leverage distribution item | #1058 | Agent prepares; **owner** creates the OpenAI developer account/org (Digid Inc.) | todo | Submitted |
| 3.9 | ChatGPT plugin extension: "Your site" sidebar, page preview panel, MCP Events automations | #1059 | Agent (design first; approve/reject slice gets **adversarial** review) | waiting on OpenAI docs | Design merged, events slice shipped |
| 3.10 | Sign in with ChatGPT: built-in AI on the owner's ChatGPT plan (no API key) | #1060 | Agent researches; **owner** applies for access | todo | Go/no-go recorded |
| 3.11 | **Multi-site hub:** one connection for many sites; per-project site pinning (`?site=alias` / `X-Mcpwp-Site`), session switching, every result stamped with its site, cross-site write guard, site switcher in the ChatGPT/Codex extension. Slices S1–S5 in the epic; S1 first | #1062 (with #549, #1059) | Agent (S1–S3 **adversarial**) | **backlog (possibility, not scheduled).** Owner handles Codex/project setup himself; don't touch Codex | 10 sites, one connection, each Codex project pinned, wrong-site write refused |

### Media: ordering video in Linear

The owner's video team takes work through **Linear**. The operating agent writes the order; the
team produces the video. Don't try to produce video yourself.

One Linear issue per video, containing:
- **Goal and audience** in one sentence ("site owners who use Claude; show that MCPWP makes edits
  safe").
- **Length and format:** 60s horizontal 1920×1080 plus a 30s vertical cut.
- **Script:** beat by beat, with on-screen text. Start from `docs/launch/DEMO-VIDEO-SCRIPT.md`
  and `docs/launch/REMOTION-SPEC.md`.
- **Assets:** existing video `docs/launch/video/mcpwp-demo-1080p.mp4`, poster, gallery images in
  `docs/launch/gallery/`, the brand kit `docs/BRAND_KIT.md`. Link them as GitHub URLs.
- **Where it lands:** mcpwp.net homepage, directory listings, social (`docs/launch/LAUNCH-SOCIAL.md`).
- **Deadline**, and who approves (the owner).

First three orders:
1. **"What's new in 3.14"** (60s): WebMCP in wp-admin, safer keys, approvals, site graph.
2. **"Watch the agent work"** (60s): Claude builds a page live, a risky change goes to approval,
   and the owner approves it in Control Room.
3. **"Connect in 2 minutes"** (30s vertical): install, copy key, paste into Claude, first command.

Linear access: the Linear MCP server needs a one-time sign-in by the owner (OAuth link from the
`plugin:engineering:linear` authenticate tool). If Linear isn't connected, write the three orders
into `docs/launch/video-orders/` and tell the owner they're ready to paste.

## Phase 4: the self-improving site (after Phases 1 and 2)

Order: #944 provider capability registry → #945 PostHog visitor signals → #946 think queries →
#947 Cloudflare signals → #948 Cloudflare cron → #949 edge A/B → #950 learn from outcomes →
#956 analytics adapters. SEO items (#915–#922) follow the graph. Design:
`docs/superpowers/specs/2026-09-27-graph-core-design.md`.

## Phase 5: 4.0 gates (not before launch traction)

- A: release engineering (#661), C: gateway architecture (#663). See [../ROADMAP-4.0.md](../ROADMAP-4.0.md).

---

## Owner-only list (keep this current, and mirror it in milestone 17's description)

| Item | Why only the owner |
|---|---|
| Enterprise policy: let Actions create PRs (0.1b) | Enterprise admin setting |
| Click Update on mcpwp.net's wp-admin Plugins page after each release (0.3, until #1070) | wp-admin login |
| Review the docs design drafts (2.0c) | Design approval before anything publishes |
| Re-enable PostHog: wp-admin → Elementor → Custom Code → 682 → Entire site → Publish | Reads of snippet 682 fatal through the API (#1082) |
| Decide: migrate the old Hello Biz page CSS (class-scoped rules only recommended) | Design decision |
| Decide: may agents use SSH to mcpwp.net's server (default: no) | Access decision |
| OpenAI developer account for Digid Inc. (3.8, 3.10) | Account creation |
| npm publish (0.4) | npm account; not logged in on this server |
| Freemius seller entity set to Digid | Freemius dashboard account |
| Chrome origin-trial token (3.6) | Google account sign-in |
| Directory accounts (3.3) | Third-party sign-ins |
| Linear sign-in for the agent | OAuth |
| Approving the tool keep/merge list (2.3) | Product decision |

Everything else, including releases, merges and publishing, belongs to the operating agent.
