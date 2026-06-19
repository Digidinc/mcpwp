# Runtime & Ecosystem Landscape — June 2026 exploration

> 9-scout research sweep, 2026-06-11. Exploration only — actions captured as GH issues #555 (competitive), #556 (Growth Engine), #557 (distribution). Session memory: `reference_runtime_landscape_2026-06`. Full sourced reports in session transcript; this doc is the durable synthesis.

## 1. The strategic read (six movements)

1. **MCP won; client side commoditized.** Every runtime ships an MCP client (Cowork, Hermes Agent, OpenClaw, ChatGPT, Cursor, Codex, GHL AI Agent, M365 Copilot). Tool Search solves big catalogs generically. Value moved server-side: what tools touch + how safely.
2. **Distribution moved to connector directories.** ChatGPT Apps (`chatgpt.com/apps`, self-serve) + Claude Connector Directory (343 connectors, manual review, self-hosted-WP slot OPEN — WordPress.com listed Feb 2026 covers WP.com only) + 4 free registries. Young directories = land-grab window, months not years (Angie/Automattic close it).
3. **Model economics bifurcated.** Frontier up-market (Fable 5 $10/$50, 1M ctx); open-weight floor collapsed (Hermes 4 70B $0.13/$0.40; DeepSeek V4-Flash $0.10/$0.20). Two-speed agent design (cheap-continuous perception + expensive-periodic decision, STRATEGY §4b) is now the market-validated cost-optimal shape.
4. **Agent-native commerce live.** Apify x402/USDC + Skyfire: agents discover/call/pay tools, budget-capped. Marketplace pricing settled: Pay-Per-Event won, rental dead (Apify sunset Oct 2026), 80/20 split.
5. **Governance becoming mandatory.** Anthropic requires destructiveHint annotations; WP.com confirms-before-write; EU AI Act audits. OpenClaw's security record (36% of ClawHub skills carry prompt injection) is the market proof. We built gates first; late entrants retrofit.
6. **Platform squeeze.** WP Abilities API commoditizes transport below (12-24mo); Anthropic Managed Agents ($0.08/session-hr) platformizes orchestration above (mupot's lane). Moat = domain depth + directory presence + data aggregate.

## 2. Runtimes (funnel surfaces)

| Runtime | MCP client | Auth we satisfy | Note |
|---|---|---|---|
| Claude Cowork (GA Apr 2026, Pro+ Desktop) | remote-only (no stdio) | OAuth 2.1 ✅ | primary non-dev buyer persona surface |
| ChatGPT Business ($20/seat) | Business/Enterprise custom MCP; Apps for all paid | OAuth CIMD/DCR ✅ (CIMD doc to add) | App Directory = biggest reach |
| Codex cloud | HTTP MCP, Bearer/OAuth | ✅ | internet disabled during task — configure MCP pre-task |
| Cursor | SSE/streamable, Bearer or OAuth PKCE | ✅ | OAuth-discovery vs static-header conflict bug |
| Hermes Agent (Nous, MIT, v0.16, 163k★) | full: HTTP+bearer+OAuth PKCE+Tool Search | ✅ | #1 harness in our backlog; integrated (`integrations/hermes/`, `mupot/connectors/hermes/`) |
| OpenClaw (MIT, 374k★, 135k instances) | bearer-only; OAuth closed "not planned" (#61611) | API key ✅ | security disaster zone — integration docs must mandate scoped keys + approval-default |
| GHL AI Agent (Apr 2026) | any MCP server; Bearer/OAuth2/custom header | ✅ | see §5 |
| M365 Copilot / Copilot Studio | GA Jun-Jul 2026 | Azure AD OAuth | AppSource channel, enterprise |
| Gemini Spark | invite-only partners | — | watch summer 2026 |

Dead: OpenAI Operator (Aug 2025), AgentKit (sunset Nov 2026).

## 3. Model cost ladder (per MTok in/out, June 2026)

Fable/Mythos 5 $10/$50 (1M ctx) · Opus 4.8 $5/$25 · GPT-5.5 $5/$30 · Sonnet 4.6 $3/$15 · GPT-5.4 $2.50/$15 · Haiku 4.5 $1/$5 (cache read $0.10) · DeepSeek V4-Pro $0.44/$0.87 · GPT-5.4-nano $0.20/$1.25 · Hermes 4 70B $0.13/$0.40 · DeepSeek V4-Flash $0.10/$0.20.

- Opus 4.7+ tokenizer: +30-35% tokens/text — adjust old cost models up.
- Cheap-tier verdicts: **Hermes 4 70B stays leader** (purpose-built tool calling, clean license). DeepSeek V4-Pro = best open-weight tool caller (128 parallel calls, MCPAtlas 73.6) BUT China API routing = PIPEDA liability for Canadian SaaS; self-host needs 4×H200. V4-Flash degrades past 8-step chains — unfit for 258-tool loops. **Gemma 4 26B A4B** (Apache 2.0, ~15GB QAT on one 24GB card, good tool calling with Unsloth quants) = cleanest self-host pick at 24GB; Hermes 4.3 36B (512K ctx) the alternative. Qwen 3.7 Max leads BFCL-V4 (75.0%) — deserves own sweep.
- Self-host ladder: 24GB → Gemma 4 26B A4B or Hermes 4.3 36B · 48GB → Hermes 4 70B Q4 · 80GB+ → V4-Flash (experimental).

## 4. Data & creative platforms

- **Apify** = addon-marketplace reference model: 80/20 split, PPE canonical, monthly payouts, top creators $10k+ MRR, 50k MAU store; `mcp.apify.com` (OAuth, dynamic actor loading, 30 req/s); agentic payments live. Pairs with mupot `cap_micro_usd`.
- **Bright Data** = compliance-grade infra (ISO 27001 + SOC 2 + trust center). Pick for LinkedIn/Amazon unblocking, enterprise procurement. Not agent-native; no marketplace.
- **PostHog** — `mcp.posthog.com/mcp`: any funnel/trend/HogQL, LLM cost traces, warehouse joins (Stripe). Our `mcpwp_tool_called` wiring already feeds it. Perception loop = API key only, zero build. 100K free LLM events/mo.
- **Higgsfield** ($1.3B val): model aggregator (Veo 3.1/Kling 3.0/Seedance 2.0/Sora 2 + own Cinema Studio), one credit pool; Python SDK + CLI + `mcp.higgsfield.ai`. Ultra $99/mo ≈ 300-500 clips (~$0.73-0.87/clip). Click-to-Ad batch UI-only. HeyGen $0.17/10s wins talking-head. #513 stack: Higgsfield gen → Remotion composite → Telegram gate → post. Sora 2 API sunsets Sep 2026 (swappable inside Higgsfield).

## 5. GHL marketplace (strongest agency channel)

- 60k agencies; 1,500-1,800 apps; **0% rev share until Dec 31 2026, then 15%**; per-sub-account pricing dominant; Tipalti payouts; public listing = 10-day security review; private capped 5 agencies.
- GHL AI Agent calls arbitrary third-party MCP servers natively (since ~Apr 17 2026) — MCPWP callable from GHL workflows today; a marketplace app makes it discoverable/installable.
- **Gap confirmed empty:** zero WordPress-management apps; agencies request WP ops/analytics inside GHL on ideas board; LevelUp (45% of volume) has nothing.
- Official GHL MCP: `services.leadconnectorhq.com/mcp/` (36 tools, Private Integration Token). Closed — third parties serve their own MCP.
- Framing: "operate the WP sites your clients already run" — complementary to GHL's WP hosting.

## 6. MCPWP inside the GHL workflow (the assembly)

```
GHL Workflow (trigger: form fill / pipeline stage / cron)
  → AI Agent action (model + tools)
      tools: GHL native (CRM/email/SMS)
           + MCPWP MCP server (https://client-site.com/.../mcp, scoped key or proxy.mcpwp.net one-key)
  → agent acts on the WordPress site mid-workflow:
      landing page spin-up (Elementor), SEO fix, blog publish, menu/content update
  → destructive ops → MCPWP /approvals → stakeholder approves (Telegram/email)
  → workflow continues (GHL: notify, book, nurture)
```
One automation = CRM + site, both arms governed. This is the Growth Engine (#556) act-path made concrete: GHL = trigger + outbound arm, MCPWP = web-presence arm, approval gate between intent and irreversible action.

## 7. Growth Engine (concept, #556)

"An AI marketing department that asks before it acts." Category: agentic GrowthOps. Daily perception agents (Apify SERP, Bright Data competitor, PostHog funnels, GA4/GSC) → periodic decision agent → strategy proposal → stakeholder approval (Telegram/email/IM) → execute (MCPWP, GHL, capped ads, Higgsfield creatives) → measure → compound. All components exist; assembly not invention.

## 8. Issues filed from this exploration

- #555 — competitive landscape (Respira, elementor-mcp, Angie, IATO, mcp-adapter; positioning verdicts)
- #556 — Growth Engine concept + assembly map
- #557 — distribution land-grab (ChatGPT Apps, Claude Connectors, registries, GHL deadline Dec 31 2026)
