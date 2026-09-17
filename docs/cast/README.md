# MCPWP WordPress agent cast

> Canonical home for the **mcpwp.net performance cast** — disclosed synthetic
> agents who operate WordPress via MCPWP, record the work, and publish under
> gates. Linear: [MUM-115](https://linear.app/mumega/issue/MUM-115/cairn-lead-revive-mcpwp-agent-cast-grok-46-directed-content).
>
> **Lead:** Cairn (pot slug `cairn`, UUID `b5279f2f-011e-4a66-9fe2-44c58851724c`).
> **Director:** Cursor cloud Grok 4.6. Orchestration: grokbot-ceo Linear wakes.

This is **not** the mumcp product squad (Scout / Smith / Warden / Probe / Herald /
Ledger). Those roles build the plugin. This cast *uses* the plugin on
[mcpwp.net](https://mcpwp.net) as a perpetual live course.

## Read in this order

1. [`ROSTER.md`](ROSTER.md) — identities, planes, who speaks
2. [`REVIVE-PLAN.md`](REVIVE-PLAN.md) — how Cursor cloud picks a role per run
3. [`PUBLISH-CHECKLIST.md`](PUBLISH-CHECKLIST.md) — what may auto-draft vs Hadi gate
4. [`episodes/00-story-bible.md`](episodes/00-story-bible.md) — Episode 0 contract
5. [`episodes/00-the-operators-return.md`](episodes/00-the-operators-return.md) — first publishable draft
6. [`episodes/01-director-prompt.md`](episodes/01-director-prompt.md) — Episode 1 prompt (ran 2026-09-17)
7. [`episodes/01-field-note.md`](episodes/01-field-note.md) — Episode 1 blocked receipt (no WP draft)
8. [`episodes/02-director-prompt.md`](episodes/02-director-prompt.md) — Episode 2 prompt (ran 2026-09-17)
9. [`episodes/02-field-note.md`](episodes/02-field-note.md) — Episode 2 blocked receipt (key still not injected)
10. [`episodes/03-director-prompt.md`](episodes/03-director-prompt.md) — next Grok 4.6 prompt

## Two planes (do not collapse)

From [`docs/squad/MUMCP-CHARTER.md`](../squad/MUMCP-CHARTER.md):

| Plane | Where | What it is |
|-------|-------|------------|
| **Flock** | mupot (`mupot.mumega.com/mcp`) | identity, presence, tasks, human gate |
| **Work** | HTTPS to the site MCP (`mcpwp/v1`) | actual WordPress reads and gated writes |

Never broker WordPress tool calls through the bus. Reads and drafts go direct.
Publish, send, homepage, money, and irreversible acts go through a named gate.

## Inventory snapshot (2026-09-16)

Searched this repo (`AGENTS.md`, `.claude/agents/`, `.cursor/agents/`, `agents/`,
`docs/squad/`, colony, cairn, aster, lumen, mumcp) plus pot `resolve_agent` /
`get_agent_profile` and Linear project `mcpwp.net`.

| Surface | What exists | What was missing |
|---------|-------------|------------------|
| `.claude/agents/` | Product squad: `mcpwp-native`, `mumcp-scout`, `mumcp-smith`, `mumcp-warden`, `mumcp-probe`, `mumcp-herald`, `mumcp-ledger` | Editorial cast files (now added: `mcpwp-cairn`, `mcpwp-aster`, `mcpwp-lumen`) |
| `.cursor/agents/` | **Absent** | Cursor has no first-class agent dir in this repo. Role choice is this folder + `.claude/agents/` + the director prompt |
| `agents/` | **Absent** | Do not invent a third tree |
| `colony/` | **Absent** | Not a repo concept here |
| `AGENTS.md` | How to add an MCP *tool*, not a character roster | Leave it; point here for people |
| `docs/squad/` | mumcp product charter + Scout/Smith roster + cross-runtime peers | Links to this folder; do not merge the two rosters |
| Plugin playbooks | `Mcpwp_Agent_Playbooks` (Gutenberg, SEO, links, rollback) | Mechanical contracts the cast must follow |
| Pot `squad-core` | `cairn`, `aster`, `lumen`, `mumcp` — all **active** | In-repo definitions were the gap MUM-115 names |
| Linear `mcpwp.net` | MUM-5 / 6 / 7 / 11 / 28 / 39–56 + MUM-115 | Cast work already exists; this revive reconnects it |

Full table: [`ROSTER.md`](ROSTER.md).
