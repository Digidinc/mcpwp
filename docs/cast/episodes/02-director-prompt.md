# Episode 2 — Director prompt (Grok 4.6)

> Paste this as the user / Linear wake for the next Cursor cloud run.
> Do not merge Episode 1 (or Episode 0) to `main` unless Hadi asks.
>
> **Ran 2026-09-17** (Cursor cloud `bc-45a55b1d-0308-53fe-8ed3-041cc3090d41`).
> Outcome: **blocked receipt** — work plane up; Cloud secrets inject
> (`DATAFORSEO_*` names present) but `MCPWP_API_KEY` is not in
> `CLOUD_AGENT_INJECTED_SECRET_NAMES`. Authenticated MCPWP routes still
> 401. Ledger: [`02-field-note.md`](02-field-note.md). Next:
> [`03-director-prompt.md`](03-director-prompt.md).

---

## Card (fill, do not skip)

```
Director: Grok 4.6 (Cursor cloud)
Lead: Cairn  (pot slug cairn · b5279f2f-011e-4a66-9fe2-44c58851724c)
Support: Aster (claims), Lumen (capture), MUMCP / mcpwp-native (ledger)
Work plane: https://mcpwp.net  MCP namespace mcpwp/v1
Flock plane: draft-not-send
Linear: MUM-115 / Episode 2
Will not: Homepage V3, nav, theme, pricing, email/Slack send, merge to main,
          Freemius, Stripe, customer sites, "market fit", minting a new API key
```

Read first: `docs/cast/ROSTER.md`, `REVIVE-PLAN.md`, `PUBLISH-CHECKLIST.md`,
`episodes/01-field-note.md` (blocked receipt), `episodes/01-director-prompt.md`.

## One sentence

Replay Episode 1's live lab — one WordPress **post** `status=draft`,
playbook `build_gutenberg_page` (or post equivalent), approval opened and
not self-applied, real ledger — **only after** Hadi binds an existing
scoped `MCPWP_API_KEY` into this Cursor cloud environment; do not publish
Episode 1 (there is no WP draft) and do not start MUM-11.

## Preconditions (stop if any fail)

1. Process env or pot-bound secret `MCPWP_API_KEY` is present. Do not mint.
   Do not read GitHub Actions secrets into the repo. Do not commit the key.
2. `GET /wp-json/mcpwp/v1/site-info` with that key returns **200**. If it
   still 401s, write another blocked receipt — do not invent a 200.
3. Homepage checksum from Episode 1
   (`35a0fdce98ab2a1eb1f4546a886ee8864026920a1207bed13e0429ddb3a35009`)
   is recorded again before any write. Do not change chrome.

## Mission (same lab as Episode 1)

1. Orient: `wp_get_site_info`, `wp_get_site_state` if available,
   `wp_get_site_context`. Record emptiness; do not fill the brand-crystal.
2. Create **one** WordPress **post**, `status=draft`, title like
   `Episode 1 — Field note: first governed draft` (or Episode 2 if you
   keep the numbering honest). Body: operator log of *this* session.
   Disclosure paragraph required. Prefer playbook `build_gutenberg_page`
   / native blocks. Do not publish.
3. Validate (blocks / SEO readiness / quality as applicable).
4. Open an **approval request**. Do not approve with the same key. Do not
   apply.
5. Write the **real** ledger into `docs/cast/episodes/02-field-note.md`
   in a draft PR. Aster claim matrix. Lumen capture of the approval row
   and **private** draft permalink — not the public homepage.
6. Update Linear MUM-115 with the draft PR URL and the WP draft id.
   Ask Hadi only if you want that draft published.

## Done when

- WP draft id exists and is not `publish`, **or** a second honest blocked
  receipt exists
- Approval id exists and is not self-applied — or the block explains why
- Homepage checksum / front-page setting unchanged
- Draft PR opened, not merged
- Next file stubbed only if the lab actually landed a draft; otherwise
  stop. Empty board after a landed draft is healthy.

## If blocked again

Write the block (`cannot reach MCP`, `no key in this environment`,
`approval route 403`) and stop. Still a successful receipt.
