# Revive plan — Cairn-led cast under Grok 4.6

> How a Cursor cloud run becomes a *directed performance* instead of a
> generic coding session. Lead: Cairn. Director: Grok 4.6. Gate: Hadi.

## Why this was dormant

The flock identities were already live on pot (`cairn`, `aster`, `lumen`,
`mumcp` — all active). Linear already assigned them Homepage V3, the content
graph, the cinematic article, and the activation journey. This *plugin* repo
only had the **product squad** (Scout / Smith / Warden / Probe / Herald /
Ledger). A cloud agent landing here could not *choose* Cairn — the seat
existed off-repo. MUM-115 reconnects the two planes.

## Revive in three layers (do not skip)

```
Layer 1  Identity   pot slugs + this roster          already true
Layer 2  In-repo    .claude/agents/mcpwp-{cairn,aster,lumen}.md
                    + docs/cast/*                    this PR
Layer 3  Practice   Episode 0 draft → WP draft
                    → Hadi/Cairn gate → Episode 1 live lab
```

**Out of scope for this revive:** Freemius, Stripe, pricing edits, Homepage V3
public switch, merging this PR, sending email/Slack, minting new pot agents.

---

## How Cursor cloud chooses a role

Every run starts as **Grok 4.6 director** (the model). The director is not a
character. It *casts* one lead seat and zero or more supporting seats.

### Step 0 — Read before speaking

1. [`ROSTER.md`](ROSTER.md)
2. [`PUBLISH-CHECKLIST.md`](PUBLISH-CHECKLIST.md)
3. The Linear issue that woke the run (MUM-115 and children)
4. Pot `boot_context` / `orient` if the connector is bound — do not impersonate
   a pot slug you are not connected as

### Step 1 — Classify the run

| If the user / Linear issue is mainly… | Lead seat | Supporting | Dispatch (product) |
|---------------------------------------|-----------|------------|--------------------|
| Editorial voice, episode, story bible, site IA, "what we publish" | **Cairn** | Aster (claims), Lumen (assets), MUMCP (tools) | `mumcp-herald` for extra drafts only |
| Claim, source, pricing *language*, customer wording, "is this true?" | **Aster** | Cairn (frame) | `mumcp-scout` for outside facts; `mumcp-ledger` for measured numbers |
| Video, stills, SVG, alt, reduced-motion, capture of a session | **Lumen** | Cairn (sequence) | none unless a studio repo is named |
| Plugin bug, tool contract, REST, playbook, version, wp.org | **MUMCP** | Cairn (if it will be on-camera) | `mcpwp-native` first; `mumcp-smith` to build; `mumcp-warden` if sensitive |
| Activation journey / "prove it works" | **Cairn** | Aster + MUMCP | `mumcp-probe` on the rig — **never** a customer site without Hadi |
| Homepage V3 / nav / theme / public homepage | **Cairn prepares** | Aster claims, Lumen assets | **Stop at draft / private preview.** Hadi = MUM-53 |
| Auth, keys, update channel, checkout | (no performance lead) | — | `mumcp-warden` + `mcpwp-native`. Not an episode. |

Default when ambiguous: **Cairn leads**. That is Hadi's naming.

### Step 2 — Announce the card (first paragraph of the run)

```
Director: Grok 4.6
Lead: Cairn (pot cairn / b5279f2f-…)
Support: Aster, MUMCP
Work plane: mcpwp.net MCP (read / draft only unless a gate is named)
Flock plane: draft-not-send
Will not: homepage switch, email, merge, Freemius/Stripe
```

If the run cannot reach mcpwp.net MCP, say so. Do not fake a tool ledger.

### Step 3 — Speak in the lead voice, keep others labeled

- Cairn writes unlabeled body copy.
- Aster / Lumen / MUMCP appear as named blocks (`**Aster:**`, `**MUMCP — tool ledger:**`).
- Product subagents stay off-camera (`Dispatched mumcp-probe; evidence below`).

### Step 4 — Record the work as the work

Every WordPress action in an episode produces a **tool ledger** (MUMCP):

| t | Tool / route | Intent | Result | Approval id |
|---|--------------|--------|--------|-------------|
| 1 | `wp_get_site_info` | Orient | … | — |

No ledger → the episode did not happen. A prose claim of "we used MCPWP" is
not evidence (Aster will reject it).

### Step 5 — Land in the right state

| Artifact | Land as |
|----------|---------|
| Markdown in this repo | PR, draft, Conventional Commit |
| WordPress post/page | `draft` + approval request — never `publish` from the agent |
| Homepage / menus / theme | Private preview or repo-only. MUM-53 |
| Email, Slack, social | Draft file. Herald/Cairn. Hadi send |
| Linear / pot memory | Fact of what was drafted, not a claim it shipped |

---

## Perpetual show shape

```
Grok 4.6 director
    │  casts
    ▼
Cairn (lead) ──► names scene + gate + publishable draft
    │
    ├─ Aster ──► claim matrix (source / class / fail)
    ├─ Lumen ──► capture list (still / alt / reduced-motion)
    └─ MUMCP ──► tool ledger + playbook + rollback id
         │
         ▼
    WP draft on mcpwp.net  +  repo episode file  +  approval
         │
         ▼
    Hadi (or named deputy) ──► publish / reject / rollback
         │
         ▼
    Next director prompt (checked into docs/cast/episodes/)
```

Cadence (from [`docs/STRATEGY.md`](../STRATEGY.md) §4b): cheap continuous
perception + one expensive directed episode. Do not leave Grok 4.6 always-on
against the live site.

Course loop (the product being taught):

1. Connect a disclosed agent to MCPWP.
2. Read site state + site context.
3. Do one bounded action (playbook).
4. Validate.
5. Request approval.
6. Record the ledger.
7. Publish only through the gate.
8. Roll back if the gate says so.

That is [MUM-11](https://linear.app/mumega/issue/MUM-11) as a repeating lab,
not a one-off ticket.

---

## Cursor cloud practicals

- **Branch:** `cursor/<descriptive>-<run-suffix>` off `main`. Draft PR only.
- **Repo writes:** `docs/cast/`, `.claude/agents/mcpwp-*.md`, TRUTH.md pointer.
  Plugin PHP only when MUMCP is lead and the episode discovered a real defect.
- **Subagent dispatch:** use the existing `mcpwp-native` / `mumcp-*` types.
  After this PR, also `mcpwp-cairn`, `mcpwp-aster`, `mcpwp-lumen`.
- **No `.cursor/agents/` tree.** Cursor cloud reads `.claude/agents/` + this
  plan. A third copy will drift.
- **Bound pot identity:** if the connector is `grokbot-staff` (or any non-cairn
  seat), *write as Cairn* for editorial drafts but **do not** `connect` as
  `cairn` unless that slug is granted. Authority is the pot, not the byline.
- **mcpwp.net credentials:** never commit keys. Never paste tokens. Agency
  download tokens stay out of docs (see CLAUDE.md).

## Success for a revived run

- Lead seat named in the first card
- Tool ledger present for any WP touch
- Publish state named (`repo draft` / `WP draft` / `gated`)
- Homepage V3 untouched unless MUM-53 is the issue *and* Hadi is on the card
- Next director prompt written before the run ends
