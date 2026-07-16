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

## The naming convention

`<host>-<runtime>-<project>` — encodes where it runs, what it is, and which project it's
scoped to, in the identity itself (complements mupot's host-signal radar, which surfaces the
same "where" as telemetry rather than identity).

- `vps-kasra` — this session, cross-repo/security lane.
- `vps-claude-mumcp` — the mumcp architect, this VPS.
- `hadi-codex-cli-mumcp` — hadi-codex-cli scoped to this project.
- `vps-hermes-mumcp` — proposed Hermes instance for the mumcp.com marketplace-brand track.
- `vps-hermes-mcwp` — proposed Hermes instance for the mcpwp.net WP-plugin track (per
  STRATEGY.md §11, mumcp.com and mcpwp.net are genuinely separate tracks, not a typo).

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
