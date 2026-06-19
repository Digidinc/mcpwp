# MCPWP Product Roadmap

Updated: 2026-06-19  
Current version: 3.3.3

## Strategic Direction

MCPWP is the most tool-complete WordPress MCP server (235+ tools vs best competitor 126). The next phase is not more tools — it is owning the **agency multi-site segment** (highest-value, zero competition at plugin layer) and adding the **compliance + intelligence** layer that will separate MCPWP from commodity MCP bridges.

### Competitive Position (June 2026)

| Capability | MCPWP | AI Engine | Royal MCP | Vibe AI | WP Official |
|------------|-------|-----------|-----------|---------|-------------|
| Tool count | 235+ | ~80 | ~126 | ~20 | 3 |
| Elementor depth | Deep | None | Basic (6) | None | None |
| Approval system | Yes | No | No | Partial | No |
| Multi-site proxy | No | No | No | No | No |
| Audit log + rollback | Partial | No | Activity log | No | No |
| Admin UI | Full (6 panels) | Plugin settings | None | None | None |
| White-label | No | No | No | No | No |
| Site memory | Static blob | No | No | No | No |

**Win condition**: Own the agency layer before anyone else does. Chat excellence is crowded; agency proxy is empty.

---

## Roadmap

### Immediate (v2.9 — now in progress)

These are in-flight or nearly done. Not the growth bets.

| Issue | What | Status |
|-------|------|--------|
| #359 | Server-side analytics (v2.8.45) | PR open, ready to merge |
| Beta operator loop | Validate real-site usage, Library polish | In progress |
| Release surface consistency | Changelog, version sync, RELEASE_CHECKLIST | Done |

---

### Phase 1 — Agency Foundation (v3.0)

**Goal: Own cross-site AI management before any competitor ships it.**  
No plugin offers a proxy MCP layer for agencies. This is the unoccupied white space.

| Issue | Title | Priority |
|-------|-------|----------|
| [#360](https://github.com/Mumega-com/mcpwp/issues/360) | Agency proxy MCP — one AI session controls N client sites | **P1 — ship first** |
| [#361](https://github.com/Mumega-com/mcpwp/issues/361) | AI action audit log + one-click rollback (EU AI Act) | **P2 — EU deadline Aug 2026** |
| [#366](https://github.com/Mumega-com/mcpwp/issues/366) | Agency dashboard — multi-site overview, health, key management | P7 — depends on #360 |
| [#367](https://github.com/Mumega-com/mcpwp/issues/367) | White-label — agency branding, custom domain, client widget | P8 — depends on #360 + #366 |

**Dependency order:** #360 → (#366 + #367 in parallel) → agency launch

**Revenue target:** 50 agencies on Starter (\$49/mo) = \$2,450/mo ARR from Phase 1

---

### Phase 2 — Intelligence Layer (v3.1)

**Goal: WordPress becomes an active intelligence layer, not a passive API.**  
Creates daily-active-use hooks and switching costs.

| Issue | Title | Priority |
|-------|-------|----------|
| [#362](https://github.com/Mumega-com/mcpwp/issues/362) | Dynamic site memory — structured context persists across AI sessions | P3 |
| [#363](https://github.com/Mumega-com/mcpwp/issues/363) | Proactive push signals — WordPress tells Claude when action needed | P4 |
| [#364](https://github.com/Mumega-com/mcpwp/issues/364) | Blueprint library — deploy multi-page site structures from agency templates | P5 |

**#362 + #363 + #364 are independent** — can be parallelized.

---

### Phase 3 — Chat Excellence (v3.2)

**Goal: Eliminate reasons to prefer AI Engine's chat.** Not a growth bet — hygiene.  
Build after P1/P2 are shipped.

| Issue | Title | Priority |
|-------|-------|----------|
| [#365](https://github.com/Mumega-com/mcpwp/issues/365) | Chat excellence — multi-model, streaming, history, confirmations | P6 |

**Competitors:** AI Engine (4.9★, 100K installs), Royal MCP (free). Chat is crowded; don't lead with it.

---

## Revenue Model

| Tier | Price | Unlocks |
|------|-------|---------|
| Free (WP.org) | \$0 | 120 tools, basic Elementor, API keys, activity log |
| Paid (Freemius) | TBD | 115 pro tools, WooCommerce, SEO intelligence, Elementor Pro |
| Agency Starter | \$49/mo | Proxy MCP (5 sites), agency dashboard, basic branding |
| Agency Pro | \$149/mo | 25 sites, white-label, custom domain, audit log export |
| Agency Scale | \$399/mo | Unlimited sites, API access, dedicated proxy, SLA |

**Revenue at 100 agencies (realistic 6-month post-launch):**
- 60 Starter: \$2,940/mo
- 30 Pro: \$4,470/mo
- 10 Scale: \$3,990/mo
- **Total: \$11,400/mo (~\$137K ARR)**

---

## What We Already Have (do not rebuild)

- [x] 235+ MCP tools (free + pro)
- [x] Elementor deep integration (get/set/patch/preview/summary)
- [x] Approval system with apply + rollback endpoints
- [x] Event store + webhook system
- [x] Design references + Library
- [x] Freemius paid tier integration
- [x] Self-update system (mumega.com static manifest)
- [x] Admin UI (6 panels: Setup, Control Room, Chat, Library, Integrations, Tools, Settings)
- [x] PostHog analytics (v2.8.44+)
- [x] Agent playbooks + guides system
- [x] Site context (static) — extends to dynamic memory in P3
- [x] Signals architecture started (SEO audit store, event store) — extends to proactive signals in P4

---

## Execution Order

```
NOW:     Merge #359 (analytics)
         Validate operator loop on real sites
         
NEXT:    #360 proxy worker (P1) ← sole focus until shipped
         #361 audit log (P2)   ← can run in parallel with #360

THEN:    #366 agency dashboard  ← depends on #360
         #367 white-label       ← depends on #360 + #366
         Agency tier launch

LATER:   #362 site memory       ← independent
         #363 push signals      ← independent  
         #364 blueprints        ← independent

LAST:    #365 chat excellence   ← polish track, not growth
```

---

## What the Research Found (sources)

- EU AI Act enforcement: August 2, 2026 — immutable AI action logs become required
- SiteGround pushed AI agent to 1M sites in May 2026 (1.1★ backlash) — proves intent D demand is real and large
- WP Umbrella (\$2/site/mo, 100K+ agencies) owns multi-site management via SaaS — plugin layer is open
- AI Engine (4.9★, 100K installs) leads on chat — chat alone will not differentiate MCPWP
- WordPress.com, SiteGround, 10Web all have multi-site but via hosting lock-in — plugin-embedded multi-site proxy is open
- Royal MCP (free) is the closest MCP competitor — no agency features, no UI, no approval system
