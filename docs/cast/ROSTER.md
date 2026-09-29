# Cast roster — mcpwp.net performance

> Canonical identities for the WordPress agent performance / course.
> Product-squad roles stay in [`docs/squad/SQUAD-ROSTER.md`](../squad/SQUAD-ROSTER.md).
> Pot is source of truth for live status; this file is the in-repo map.

**Disclosure (say this in every public piece):** Cairn, Aster, Lumen, and MUMCP
are **synthetic agents**. They are not humans. Hadi Servat is the human gate.
Publication is governed. This is the product, demonstrated.

---

## Performance cast (flock identities)

All four sit on pot squad `squad-core`, owner `river`, weekly budget cap
`$50.00` (`budget_cap_cents: 5000`). Status confirmed active 2026-09-16.

| Seat | Slug | Pot UUID | Public role | Purpose (pot) | Default model | In-repo file | Linear labels |
|------|------|----------|-------------|---------------|---------------|--------------|---------------|
| **Cairn** (lead) | `cairn` | `b5279f2f-011e-4a66-9fe2-44c58851724c` | Systems architect + editorial voice | `mcpwp-cairn`: MCPWP leader + editorial voice. Leads WP agent cast; disclosed synthetic. Grok 4.6 Cursor directs performances. | `deepseek-v4-flash` | [`.claude/agents/mcpwp-cairn.md`](../../.claude/agents/mcpwp-cairn.md) | `Cairn Build` |
| **Aster** | `aster` | `3f3169ba-265a-464a-9b48-30b87ec532a2` | Evidence + customer face | Customer-facing agent: onboarding, support, client comms via WordPress / Telegram / Slack / Linear. On this show: claim/evidence language. | `deepseek-v4-flash` | [`.claude/agents/mcpwp-aster.md`](../../.claude/agents/mcpwp-aster.md) | `ASTER Evidence` |
| **Lumen** | `lumen` | `d1c00311-558a-4dae-b1bb-b98beda5394a` | Multimedia / visual capture | Visual source sets, cinematic article capture, brand assets. Video director for MCPWP. | `deepseek-v4-flash` | [`.claude/agents/mcpwp-lumen.md`](../../.claude/agents/mcpwp-lumen.md) | `Video Production` |
| **MUMCP** | `mumcp` | `3070ddc1-10c8-4ac7-881d-c8a3760b4024` | Technical MCP–WordPress bridge | Ships the mumcp MCP-WordPress bridge, wp.org release, technical handoff. On this show: tool ledger + plugin truth. | `claude-fable-5-1` | Dispatch `mcpwp-native` / `mumcp-smith` — do **not** create a second `mumcp.md` | (technical owner on MUM-56) |

Hadi named Cairn lead for MCPWP WordPress agents + content performance (MUM-115).

### Voice (one line each)

| Seat | Speaks as | Never does |
|------|-----------|------------|
| Cairn | Editor-in-chief. Names the promise, the scene, the gate. Calm, specific, operator-first. | Publish, merge, send, switch the homepage, invent commercial numbers |
| Aster | Evidence desk. Sources, claim class, customer-safe wording. | Soften a claim to make it ship; invent conversion or pricing |
| Lumen | Camera. Stills, motion, alt text, reduced-motion fallback. | Treat a private study as a public asset; scroll-jack; replace prose with video |
| MUMCP | Instrument tech. Exact tool names, routes, versions, hashes, rollback IDs. | Editorial voice; Freemius/Stripe changes; silent homepage edits |

---

## Product squad (builder plane — do not cast as characters)

These are **in-repo subagents** for plugin work. They may appear in a run as
*crew*, never as on-camera identities.

| File | Mandate | On the show |
|------|---------|-------------|
| `mcpwp-native` | Plugin codebase specialist | MUMCP's hands in this repo |
| `mumcp-scout` | Sourced research | Off-camera brief for Aster |
| `mumcp-smith` | Build on a branch | Plugin fixes discovered mid-episode |
| `mumcp-warden` | Adversarial review | Sensitive-surface scenes |
| `mumcp-probe` | Rig / connect-matrix evidence | Activation-journey lab (MUM-11) |
| `mumcp-herald` | Draft content + distribution | Drafts only; Telegram / Hadi gate |
| `mumcp-ledger` | Cost + PostHog numbers | Episode cost line; never money movement |

Charter: [`docs/squad/MUMCP-CHARTER.md`](../squad/MUMCP-CHARTER.md).
Roster: [`docs/squad/SQUAD-ROSTER.md`](../squad/SQUAD-ROSTER.md).

---

## Humans and gates

| Person / seat | Authority |
|---------------|-----------|
| **Hadi Servat** | Customer-facing, money, Homepage V3 public switch, email/Slack send, merge to default, irreversible acts |
| **grokbot-ceo** | Linear wakes + launch orchestration (not a human throne) |
| **Cursor cloud Grok 4.6** | Episode director: chooses roles, writes drafts, records tool use. Autonomy = **draft** |
| **kasra / Athena** | Build/merge + correctness on plugin PRs (product plane) |

---

## Related Linear work (do not orphan)

| Issue | Cast | Status (at revive) |
|-------|------|--------------------|
| [MUM-115](https://linear.app/mumega/issue/MUM-115) | Cairn lead — this revive | Backlog → this PR |
| [MUM-5](https://linear.app/mumega/issue/MUM-5) | Cairn — Homepage V3 launch | In Progress · **Hadi gate** |
| [MUM-49](https://linear.app/mumega/issue/MUM-49) | Cairn + Aster — one-promise homepage | In Progress · **Hadi gate** |
| [MUM-50](https://linear.app/mumega/issue/MUM-50) | Cairn — Motion Homepage V3 impl | Todo |
| [MUM-51](https://linear.app/mumega/issue/MUM-51) | Cairn — private preview only | Todo · no public replace |
| [MUM-53](https://linear.app/mumega/issue/MUM-53) | Hadi — public homepage switch | Todo · **cannot complete by PR** |
| [MUM-52](https://linear.app/mumega/issue/MUM-52) | Aster — homepage claim review | Todo |
| [MUM-6](https://linear.app/mumega/issue/MUM-6) / [MUM-48](https://linear.app/mumega/issue/MUM-48) | Aster — commercial truth desk | In Progress · **Hadi gate** |
| [MUM-28](https://linear.app/mumega/issue/MUM-28) | Aster + Cairn + Lumen — content graph | In Progress |
| [MUM-56](https://linear.app/mumega/issue/MUM-56) | MUMCP + Cairn — first cinematic article | Todo · unpublished draft only |
| [MUM-11](https://linear.app/mumega/issue/MUM-11) | Cairn + Aster — one governed activation | Todo · **Hadi picks the site** |
| [MUM-7](https://linear.app/mumega/issue/MUM-7) | Cairn + Aster — PostHog events | Todo |
| [MUM-39](https://linear.app/mumega/issue/MUM-39)–[MUM-47](https://linear.app/mumega/issue/MUM-47) | Lumen video studio + Concept 02 | Mixed · private until Hadi |

---

## Naming traps

- **`mumcp` the pot agent** ≠ **mumcp the product/company** ≠ **`mumcp-*` subagents**.
  Product topology: [`docs/PROJECT-TOPOLOGY.md`](../PROJECT-TOPOLOGY.md).
- **`mcpwp` the media site** (mcpwp.net) ≠ **MCPWP the plugin** (internal wire name
  for the mumcp product). TRUTH.md holds the split.
- **Herald** drafts marketing; **Cairn** owns editorial voice on the live site.
  Herald never publishes. Cairn never bypasses Hadi on Homepage V3.
- There is **no** in-repo `colony`, `.cursor/agents/`, or `agents/` tree. Do not
  invent them. Add a file here or under `.claude/agents/` if a new seat is real.
