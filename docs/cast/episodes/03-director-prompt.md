# Episode 3 — Director prompt (Grok 4.6)

> Paste this as the user / Linear wake for the next Cursor cloud run.
> Do not merge Episode 2 (or 1, or 0) to `main` unless Hadi asks.
>
> Episode 2 ran 2026-09-17 and wrote another **blocked receipt**. The
> Cloud environment injects secrets, but not `MCPWP_API_KEY`. Same lab.

---

## Card (fill, do not skip)

```
Director: Grok 4.6 (Cursor cloud)
Lead: Cairn  (pot slug cairn · b5279f2f-011e-4a66-9fe2-44c58851724c)
Support: Aster (claims), Lumen (capture), MUMCP / mcpwp-native (ledger)
Work plane: https://mcpwp.net  MCP namespace mcpwp/v1
Flock plane: draft-not-send
Linear: MUM-115 / Episode 3
Will not: Homepage V3, nav, theme, pricing, email/Slack send, merge to main,
          Freemius, Stripe, customer sites, "market fit", minting a new API key
```

Read first: `docs/cast/ROSTER.md`, `REVIVE-PLAN.md`, `PUBLISH-CHECKLIST.md`,
`episodes/02-field-note.md` (blocked receipt), `episodes/01-field-note.md`.

## One sentence

Replay the live lab — one WordPress **post** `status=draft`, playbook
`build_gutenberg_page` (or post equivalent), approval opened and not
self-applied, real ledger — **only after** this pod lists
`MCPWP_API_KEY` in `CLOUD_AGENT_INJECTED_SECRET_NAMES` **and**
`GET /site-info` with that key returns **200**. Do not publish Episode
1 or 2 (there is no WP draft). Do not start MUM-11.

## Preconditions (stop if any fail)

1. `echo "$CLOUD_AGENT_INJECTED_SECRET_NAMES"` contains `MCPWP_API_KEY`
   (names only; do not print the value). Process env `MCPWP_API_KEY` is
   non-empty. Do not mint. Do not read GitHub Actions secrets into the
   repo. Do not commit the key. Do not use `DATAFORSEO_*` as a
   stand-in.
2. `GET /wp-json/mcpwp/v1/site-info` with `X-API-Key` returns **200**.
   If it still 401s, write another blocked receipt — do not invent a
   200. "Hadi said it was bound" is not a 200.
3. Record the logged-out homepage checksum before any write. Episode 2:
   159180 bytes, sha256
   `84c5ac92b7dd41415a08dd193d64dd2874dae49aef788b655a8becd3704523c5`,
   title `What do you want AI to do with WordPress? - MCPWP`. A moving
   digest at constant size can be cache-bust `ver=` — still do not
   change chrome.

## Mission (same lab as Episode 1 and 2)

1. Orient: `wp_get_site_info`, `wp_get_site_state` if available,
   `wp_get_site_context`. Record emptiness; do not fill the brand-crystal.
2. Create **one** WordPress **post**, `status=draft`, title like
   `Episode 3 — Field note: first governed draft`. Body: operator log of
   *this* session. Disclosure paragraph required. Prefer playbook
   `build_gutenberg_page` / native blocks. Do not publish.
3. Validate (blocks / SEO readiness / quality as applicable).
4. Open an **approval request**. Do not approve with the same key. Do not
   apply.
5. Write the **real** ledger into `docs/cast/episodes/03-field-note.md`
   in a draft PR. Aster claim matrix. Lumen capture of the approval row
   and **private** draft permalink — not the public homepage.
6. Update Linear MUM-115 with the draft PR URL and the WP draft id.
   Ask Hadi only if you want that draft published.

## Done when

- WP draft id exists and is not `publish`, **or** a third honest blocked
  receipt exists
- Approval id exists and is not self-applied — or the block explains why
- Homepage / front-page setting not written
- Draft PR opened, not merged
- Next file stubbed only if the lab actually landed a draft; otherwise
  stop. Empty board after a landed draft is healthy.

## If blocked again

Write the block (`cannot reach MCP`, `no key in this environment`,
`approval route 403`, `injected-secret list still lacks MCPWP_API_KEY`)
and stop. Still a successful receipt.
