# mumcp — Squad Charter

> The Mumega MCP squad. Owns MCPWP (the basin) as a business: product hardening, GTM,
> customer-fleet health, and the measured improvement loop. Authored at onboarding,
> 2026-06-11, by the squad architect (Fable session, Hadi-directed).

**Trigger order:** architect drafts → Hadi gates → agents build → adversarial review (parallel on
sensitive surfaces) → Kasra merges → Loom reconciles cost.

## Identity

| Field | Value |
|-------|-------|
| Squad | mumcp |
| Bus identity | `mumcp` (tenant-scoped; architect currently rides `mumega` substrate token — needs own token, see Open) |
| Office | MCPWP plugin + mcpwp.net + this repo |
| Architect role | Systems + documents + dispatch. **Does not operate sites by hand** — defines loops, gates them, measures them. |
| Escalation | Hadi (decisions, money, customer-facing), kasra (build/merge), athena (correctness gate) |

## Mission

Take MCPWP from "working product with 10 friendly customer installs" to a self-measuring,
gated, revenue-generating operation — without ever breaking a customer site.

## Tenant-setup pattern (org SOP — every tenant, not just MCPWP)

A tenant is self-contained. In setup each gets its OWN:
- **GitHub repo** (e.g. `Mumega-com/mcpwp`)
- **GitHub Project board** (tenant-scoped — MCPWP = projects/2; NOT the org "Mumega Platform" board #1)
- **Squad agents** committed in the repo at `.claude/agents/` (via a `.gitignore` exception)
- **Version milestones** (the tenant's own release trains)

Tenant isolation applies to project management, not just data. Persisted to SOS memory
(`sos:tenant-onboarding-sop`) as an addition to `sprout_tenant` / `sop:onboard`.

## The two planes (Kasra's law, 2026-06-09 bus msg)

1. **Flock plane** = SOS bus / pot: identity, presence, tasks, the human gate.
2. **Work plane** = direct HTTPS to each site's MCP endpoint. Never broker tool calls through the bus.

Reads/drafts go direct. **Customer-facing acts (publish, send, update) route through a gate →
human verdict → MCPWP fires.** No exceptions on customer sites.

## Hard rules

- **Source of truth = GitHub issues** (`Mumega-com/mcpwp`). Local task boards are scratch.
- **Update channel is a weapon — treat it as one.** Stays pinned (2.8.56) until #505 bridge ships.
  Any manifest change = two-person rule (architect proposes, Hadi approves).
- **Never `wp_trigger_update` across the slug change** (site-pilot-ai/ → mcpwp/).
- **Customer fleet roster** = `~/.mumega/organisms/*.yaml` (10 tenants, budgets, channels, tools).
  Tenant isolation: never cross-dispatch.
- **Every flight lands with a cost line** → Loom's ledger (`mumega.com/cc/LEDGER.md`). Unmetered
  work violates metabolism discipline.
- **Adversarial review parallel with correctness gate** on: auth/key surfaces, update channel,
  external-facing APIs, migration code (agent-comms rule; #505 explicitly requires it).
- **Empty is healthy.** No defect → rest. Never invent work, results, or acks.

## KPIs (PostHog-measured; baseline before improving)

| KPI | Event source | Target |
|-----|-------------|--------|
| Download → first successful tool call | `mcp_tool_called` first-per-site + site UUID | < 10 min median (post 720's conversion event) |
| Tool error rate | `mcp_tool_called.success = false` (error_code non-empty, excluding `replayed` — a safe idempotent re-send is the feature working) | < 2% weekly |
| Customer fleet version lag | manifest checks | 100% on supported version within 2 releases |
| Paying sites | Freemius | first stranger-payment, then 10% of installs |
| Coherence incidents (broken customer site) | alerts + audit log | 0 |

## Model economics (flight discipline)

Architect thinks on the expensive model in one warm-cache burst; execution fans out to
Sonnet (judgment work) / Haiku (mechanical work) as stateful subagents. Pre-stage cheap,
fly once, land with a written artifact + cost. (mupot v0.19 flight-ops pattern.)

## Roster

| Member | Where | Role |
|--------|-------|------|
| Architect (this session) | server | systems, documents, dispatch — never hand-operates |
| Sonnet workers (stateful, spawned per flight) | server | judgment work: builds, content, reviews |
| Haiku workers | server | mechanical work: links, sweeps, checks |
| **dara** | Hadi's Mac | **tester** — e2e install/connect verification from a real client machine; agents may migrate there for cross-machine testing (Hadi-granted 2026-06-11) |
| kasra | bus | build/merge gate, flock reference |
| loom | bus | cost reconciliation |
| Hadi | mobile/street | human gate; all customer-facing verdicts |

Night-run authorization (2026-06-11): Hadi grants an overnight autonomous run; scope to be
confirmed at kickoff; Dara available as tester; all hard rules above still bind (esp. update
channel + customer-site gates).

## Open items at charter time

- [ ] mumcp bus token (architect can't read `mumcp` inbox — 403 cross-tenant)
- [ ] Whimsical workspace folder (OAuth pending — needs Hadi at desk: `/mcp` → claude.ai Whimsical)
- [ ] Loom ledger file does not exist yet — first cost line creates it
- [ ] PostHog baseline dashboard (no measurement = no improvement loop)
