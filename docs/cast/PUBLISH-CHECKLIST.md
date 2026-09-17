# WordPress publish checklist — mcpwp.net

> What an episode may write automatically vs what waits for a named human.
> Homepage V3 is **not** an auto-publish surface. Linear gate: [MUM-53](https://linear.app/mumega/issue/MUM-53).

Autonomy for Cursor / Cairn / Aster / Lumen / MUMCP on this show: **draft**.
Draft is not publish. A green PR is not a live homepage.

---

## State machine (use these words; do not skip)

```
repo draft → WP draft → approval requested → Hadi/Cairn verdict
    → applied / published → verified logged-out → (optional) rollback
```

Private preview, staging, deployed theme, and public homepage are **different
states** (MUM-56 / MUM-51 / MUM-53). Never report one as another.

---

## Auto-allowed (agent may do without a new Hadi ping)

Still log the tool ledger. Still stay on mcpwp.net or the local rig — not a
customer site.

| Action | How | Notes |
|--------|-----|-------|
| Read site info, plugins, health, menus, signals | MCP GET / free tools | Always first |
| Read `mcpwp_site_context` | `wp_get_site_context` | Empty context is a finding, not a license to invent brand |
| List posts/pages/media; fetch a draft by id | MCP GET | Do not leak private URLs in public copy |
| Create or update a **draft** post/page | MCP POST/PUT `status=draft` | Title must say Episode / Field note / Draft |
| Create an **approval request** for that draft | `/approvals` | Required before any publish-facing mutation |
| Validate blocks / SEO readiness / content quality | playbook gates | Fail closed; do not "just publish anyway" |
| Write episode markdown in `docs/cast/episodes/` | git | This repo |
| Draft social / newsletter **files** | `docs/` or Herald | Not sent |
| Capture a **local** screenshot of a draft preview | Lumen | Not a public asset until reviewed |
| Record PostHog event *names* you will need | MUM-7 list | Emitting fake events is a lie — don't |

If unsure, it is **not** auto-allowed.

---

## Cairn editorial gate (Hadi need not be in the room)

Cairn may approve *inside the episode* only when all of these are true:

- Surface is a **blog post or field note**, not a page in the chrome (home,
  pricing, nav, footer, theme)
- Aster signed the claim matrix (no unresolved `launch-blocking` claims)
- Tool ledger is attached
- Status remains `draft` until a human publish click or an approval `apply`
  that Hadi has pre-authorized for *this* content class
- No commercial number, version, or install-path change (those are Aster + Hadi)

This is the "governed WP work" the course teaches. It is still disclosed
synthetic + approval-logged.

---

## Hadi gate (explicit approve at action time)

A PR, a passing test, a private preview, or "Cairn said it's ready" **cannot**
complete these.

| Surface | Why | Linear |
|---------|-----|--------|
| **Homepage V3 public switch** | Public-state gate. Reload logged-out after. Rollback if nav/a11y/truth/measurement fails | MUM-5, MUM-50, MUM-51, **MUM-53** |
| One-promise homepage copy / AI truth block | Positioning freeze | MUM-49 |
| Header / footer / menu / theme / front-page setting | Chrome is the product face | MUM-51, dogfood spec |
| Pricing page, tiers, entitlements, "Free vs Pro vs Agency vs Mupot" public wording | Commercial truth | MUM-6, MUM-48, MUM-52 |
| Any **publish** of a post that makes product or pricing claims | Same | Aster desk |
| Email, Slack, Telegram, social **send** | Draft-not-send (MUM-115 + charter) | Herald → Hadi |
| Customer site or "controlled participant" for MUM-11 | Hadi selects the site | MUM-11 |
| Merge to `main` / deploy theme package / update-channel publish | Irreversible | kasra + Hadi as already ruled |
| Freemius / Stripe / checkout / money | **Out of this show.** Separate track. Do not invent changes | — |
| Concept 02 Gemini upload / paid generation | Lumen private study | MUM-43 + explicit Hadi |
| Treating a private animation study as the public logo | Evidence boundary | MUM-45, MUM-47 |

### Homepage V3 launch matrix (must all be named)

From MUM-53 — copy this into any homepage-adjacent episode:

- [ ] Production brand pack + final SVG reviewed (not Concept 02 study)
- [ ] Theme/package SHA recorded; verification passed
- [ ] Logged-out private preview, desktop + mobile
- [ ] a11y, no-JS, reduced-motion, links, structured data, performance
- [ ] PostHog baseline + critical events (MUM-7)
- [ ] Aster claim matrix: zero launch-blocking opens
- [ ] Lumen final assets separated from private study
- [ ] Current homepage/theme backup + tested rollback
- [ ] States labeled: implemented / locally verified / preview verified / deployed / **published** / customer-proven
- [ ] Hadi explicit approve **at switch time**
- [ ] Public URL reloaded logged-out; publication time recorded separately from git

---

## Never

- Publish from the agent because the draft "looks done"
- Put Homepage V3 live from this plugin repo
- Commit API keys, agency download tokens, Freemius bearer, or Stripe secrets
- Claim a journey is "customer proof" or "market fit" (MUM-11 forbids it)
- Collapse Mumega MCP (wp.org) / MCPWP Pro / Agency / Mupot into one product
- Invent versions, prices, or install counts (Aster owns the register)
- Auto-trace the logo PNG (MUM-46 — deterministic SVG only)
- Use chroma sources as production images (MUM-56)

---

## Episode 0 publish path (this PR)

| Artifact | Allowed now | Next human step |
|----------|-------------|-----------------|
| `docs/cast/**` in a **draft PR** | Yes | Review, do not merge until asked |
| WordPress draft of Episode 0 article | Yes, after PR review, via MCP `status=draft` | Cairn + Aster pass, then Hadi publish |
| Homepage / nav / theme | **No** | MUM-53 |
| Social/email send announcing the cast | **No** | Draft file only |
| "The operators are live on mcpwp.net" | **No** until the WP post is actually published | — |
