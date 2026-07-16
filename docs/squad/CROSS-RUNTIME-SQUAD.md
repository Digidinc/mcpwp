# mcpwp/mumcp — cross-runtime squad (2026-07-16)

> Layer above `SQUAD-ROSTER.md`. That doc is the mumcp architect's OWN internal roster
> (Scout/Smith/Warden/Probe/Herald/Ledger — subagents it dispatches). This doc is the layer
> above it: multiple AI RUNTIMES, on different hosts, working the same project as PEERS —
> each bringing capabilities the others don't have natively. Origin: Hadi, 2026-07-16 —
> "if you have hadi-codex-cli on your team on the mumcp folder in your squad you would have
> access to browser and more power."

## Why this is a real layer, not org tidiness

Different runtimes have genuinely different native tools:

| Runtime | Host | Native strength this squad needs |
|---|---|---|
| Claude Code (kasra) | this VPS | cross-repo build/security-gate discipline, dual-gate adversarial review |
| Claude Code (mumcp architect) | this VPS, Sonnet | WP/mcpwp product depth, own internal roster |
| Codex CLI (hadi-codex-cli) | — | **browser automation + scheduled/multi-day unattended tasks** — the thing neither Claude Code instance has natively |
| Hermes (proposed) | this VPS | cron scheduler, multi-platform delivery, closed learning loop, skill self-improvement |

Putting them on the SAME project's squad means the squad as a whole can do things no single
member can — this is capability pooling, not just access control.

## The naming convention — and a correction (2026-07-16, same night)

`<host>-<runtime>-<project>` — encodes where it runs, what it is, and which project it's
scoped to, in the identity itself (complements mupot's host-signal radar, which surfaces the
same "where" as telemetry rather than identity). Confirmed hosts: `vps` = this Hetzner box,
`mac` = Hadi's personal Mac (the model/inference literally runs there — not "hadi-" as a
prefix, which is a reserved anti-impersonation namespace on the SOS bus, see below).

- `vps-kasra` — this session, cross-repo/security lane.
- `vps-claude-mumcp` — the mumcp architect, this VPS.
- `mac-codex-mumcp` — the accurate name for the Codex CLI instance whose model runs on
  Hadi's Mac, scoped to this project (corrected from an earlier `hadi-codex-cli-mumcp` /
  `hadi-codex-mumcp` attempt — `hadi-` is a reserved bus prefix, see the mint-attempt note).
- `vps-hermes-mumcp` — a REAL Hermes profile, created 2026-07-16, idle (not yet activated),
  cheap-continuous-perception role per §4b. See "What's actually true" below — proposed
  scope (mumcp.com marketplace track vs mcpwp.net WP-plugin track) not yet resolved; this
  profile's `terminal.cwd` currently points at THIS repo (the WP-plugin track), so the name
  vs scope may need reconciling once the mumcp.com/mcpwp.net split is real.

### What's actually true, after attempting this live (correction)

Attempting to formally MINT `hadi-codex-mumcp` / `vps-codex-mumcp` as a new bus sub-identity
hit a real wall: the only agent-identity-minting surface on the SOS bus
(`sos/bus/tenant_agent_mint.py`, S028 C-1b) is the **customer-tenant custom-agent-mint**
API — built for paying customers (digid/viamar-style) minting their own scoped support
agent, protected by 9 named LOCK invariants (charter-injection-defense, RLS, QNFT signing).
Using `mumcp`/`mcpwp` as a `tenant_id` there is a category error — an internal project is
not a customer tenant. **No new bus sub-identity was minted, and none was needed:**
hadi-codex already has a working bus identity (`agent:hadi-codex`) and needs no separate
mint to coordinate on this project — it just orients via this doc + `BACKLOG.md` +
`STRATEGY.md` and reports back under its existing identity. The "each squad member gets
its own freshly-minted sub-identity" framing in the original version of this doc was
over-engineered; corrected here rather than left standing.

## The platform primitive this should eventually formalize onto

This squad pattern is being run informally, live, before the platform feature exists. It's
the exact shape of two things already designed on the mupot side tonight:

- **mupot Projects — repo-bound, squad-access-controlled** (mumega.com#409, mupot
  `docs/architecture/mupot-projects-squad-access.md`) — a project-scoped API key granting
  a squad access to a repo IS `project_squad_access`. This squad (kasra + mumcp + hadi-codex-
  cli + Hermes, scoped to `Mumega-com/mcpwp`) is the worked example that design was written
  against.
- **The CMS-adapter uniform port** (mupot #370, Slice A shipped tonight) — the actual
  mechanism the `inkwell ↔ MCPWP sync` MCP-to-MCP integration track (mcpwp
  `docs/adr/0001-extension-architecture.md`) called for: Inkwell (mumega.com's content
  framework, brand/CRM brain) and MCPWP (WordPress hands) as adapters behind one port. Two
  parallel sessions converged on the same mechanism the same night.

## Credential principle — same rule on both sides

Any squad-scoped API key that grants hadi-codex-cli (or a Hermes instance) access is a
token/identity-mint action: **scoped, least-privilege, human-gated, never a blanket key** —
Kasra's own standing rule (kasra/CLAUDE.md, SECURITY APPROVAL PROTOCOL), independently matched
by STRATEGY.md §11's Hermes-as-admin design ("scoped capability tokens per surface, not one
master credential"). Same principle, arrived at twice, from two different constraints
(security discipline vs. economy-design discipline). Mint only after the exact scope
(which surfaces, read vs write) is named — not a broad "team access" grant.

## Open / next

1. **Name hadi-codex-cli's exact scope** before any key is minted (which repo actions, read
   vs write, sensitive-surface reach).
2. **`vps-hermes-mumcp` / `vps-hermes-mcwp`** — blocked on the same thing STRATEGY.md §11's
   Hermes-as-admin sketch is blocked on: concrete access handed over (domain, CF zone,
   Freemius/Stripe IDs where relevant to each track).
3. **This doc + `mupot-projects-squad-access.md` should stay in sync** — whichever gets a
   real build first (mupot Projects feature, or this squad proving out informally) should
   feed the other.
