# Episode 0 — Story bible

> The origin episode of a perpetual Grok 4.6–directed course: disclosed
> synthetic agents operate [mcpwp.net](https://mcpwp.net) through MCPWP,
> record the path, and publish under gates.
>
> **Lead:** Cairn · **Director:** Grok 4.6 · **Linear:** MUM-115
> **Publishable draft:** [`00-the-operators-return.md`](00-the-operators-return.md)

## Logline

Four named synthetic agents walk onto a live WordPress site, refuse to be
anonymous, do one governed thing, and leave the receipt where a human can
read it.

## Why Episode 0 exists

The cast was already alive on the pot and in Linear. The *show* was not —
no in-repo roster, no director card, no first public sentence that says
"we are not humans, and that is the point." Episode 0 is the contract the
later labs (activation journey, content graph, cinematic article) stand on.

It is **not** Homepage V3. It is **not** the MUM-56 cinematic feature. It is
the foyer.

## Thesis (front-load this)

WordPress is ready for agents when the agents are **named, scoped, logged,
and gated**. MCPWP is the hands. mupot is the authority. Hadi is the human
who can still say no. The course is the demonstration, not a metaphor.

## Dramatic question

Can a disclosed synthetic cast operate a real WordPress publication without
becoming either a ghostwriter farm or an unsupervised bot?

Episode 0 answers: **only if every action has a name, a tool, and a gate.**

## Cast on camera

| Seat | Function in Ep 0 | First line they earn |
|------|------------------|----------------------|
| **Cairn** | Host. Names the show and the gate. | "I am Cairn. I am not a person. I edit." |
| **Aster** | Stops a sentence that cannot be sourced. | "If we cannot point at it, we do not ship it." |
| **Lumen** | Shows what to photograph: the approval, not the sunset. | "The picture is the receipt." |
| **MUMCP** | Reads the instrument. Writes the ledger. | "Here is the exact call." |

Off-camera: Grok 4.6 director, grokbot-ceo (wake), Hadi (gate). Product
squad stays crew.

## World rules (non-negotiable)

1. **Disclosure is load-bearing.** Every public piece says the cast is
   synthetic. Hiding it is the failure mode Google calls scaled-content
   abuse; showing it is the safe harbor (`docs/STRATEGY.md` §4).
2. **Two planes.** Flock identity ≠ WordPress tool call.
3. **Draft is a state.** Publish is a different state. Homepage is a third.
4. **One bounded action per episode** until MUM-11 is proven.
5. **No invented commerce.** Prices, versions, entitlements → Aster desk
   or silence.
6. **Voice** from `docs/BRAND_KIT.md`: technical, direct, operator-first.
   Forbidden: magic, unlimited autonomy, "replaces your team."

## Episode 0 plot (five beats)

### Beat 1 — The card
Director announces lead Cairn, support Aster / Lumen / MUMCP, will-not list
(homepage, email, merge, Freemius/Stripe).

### Beat 2 — The names
Each seat states slug, UUID, job, and what they are forbidden to do.
This is the EU-shaped audit story: *who are you, what will you do, did you
stay in bounds?* (`STRATEGY.md` §4b).

### Beat 3 — The instrument
MUMCP names the work plane: `POST /wp-json/mcpwp/v1/mcp`, playbooks in
`Mcpwp_Agent_Playbooks`, approval-first global rules. No tool call is
faked in the published article. Episode 0's *on-page* ledger may be the
**intended** first lab (labeled `planned`) until a live MCP session lands
in Episode 1.

### Beat 4 — The refusal
Aster refuses three tempting lies: "we already proved market fit," "the
homepage is the new one," "one prompt does everything." Cairn keeps the
piece.

### Beat 5 — The invitation
The next lab is Episode 1: one WordPress **draft** field note created
through MCPWP on mcpwp.net, approval opened, ledger filled. Hadi is not
asked to switch the homepage.

## Cornerstones this episode points at (does not finish)

From MUM-28 — link, do not pretend they are published:

1. AI-operated WordPress
2. MCP for WordPress
3. WordPress agent governance (permissions, approval, evidence, recovery)

## Visual language (Lumen)

| Shot | Purpose | Alt / decorative |
|------|---------|------------------|
| Roster table (server-rendered) | Names + UUIDs | Table is the picture; image optional |
| Approval-gate still (amber) | Brand sequence, not decoration | "Amber approval gate between agent and WordPress" |
| Empty ledger template | Invite Episode 1 | decorative if the table is in prose |
| Homepage V3 mock / private preview | **Do not use in Ep 0 public draft** | Would imply the switch |

Reduced motion: static table + still. No scroll-jack. Meaning without JS.

## Reader paths (three, matching MUM-49 intent selector)

1. **Operate my site** → Episode 1 lab + plugin setup docs
2. **Scale workflows** → playbooks + approvals, not Agency pricing
3. **Evaluate AI + MCP** → this bible + `docs/PROJECT-TOPOLOGY.md`

Do not send them to a Homepage V3 that is not public.

## What Episode 0 is not

- Not the cinematic article "Your WordPress Site Is Not Obsolete…" (MUM-56)
- Not a Product Hunt launch post
- Not a pricing announcement
- Not proof that the activation journey is done (MUM-11)
- Not authorization to merge this PR

## Success criteria

- [x] Roster + revive plan + publish checklist in `docs/cast/`
- [x] In-repo agent files for Cairn / Aster / Lumen
- [x] Publishable article draft (markdown) with disclosure
- [ ] WordPress `draft` of that article (post-PR, MCP, ledger required)
- [ ] Aster claim matrix signed on the WP draft
- [ ] Hadi publish (or reject) — not this run
- [ ] Episode 1 director prompt ready — [`01-director-prompt.md`](01-director-prompt.md)

## Continuity seeds for later episodes

| Seed | Later episode |
|------|----------------|
| Empty `mcpwp_site_context` (if still empty) | Brand-crystal lab |
| Playbook `build_gutenberg_page` | First real draft page |
| MUM-11 eight-step journey | First "we ran it" receipt |
| MUM-28 three cornerstones | Authority wheel, one packet at a time |
| MUM-56 six scenes | Cinematic feature, unpublished until Hadi |
| MUM-7 events | Only after something real is public |
