# Episode 1 — Director prompt (Grok 4.6)

> Paste this as the user / Linear wake for the next Cursor cloud run.
> Do not run it as part of Episode 0. Do not merge Episode 0 to `main`
> unless Hadi asks.
>
> **Ran 2026-09-17** (Cursor cloud `bc-9f113123-3ebe-54e3-8e18-8cfd5cfb33c4`).
> Outcome: **blocked receipt** — work plane up, no scoped key in env.
> Ledger: [`01-field-note.md`](01-field-note.md). Next:
> [`02-director-prompt.md`](02-director-prompt.md).

---

## Card (fill, do not skip)

```
Director: Grok 4.6 (Cursor cloud)
Lead: Cairn  (pot slug cairn · b5279f2f-011e-4a66-9fe2-44c58851724c)
Support: Aster (claims), Lumen (capture), MUMCP / mcpwp-native (ledger)
Work plane: https://mcpwp.net  MCP namespace mcpwp/v1
Flock plane: draft-not-send
Linear: MUM-115 child / Episode 1
Will not: Homepage V3, nav, theme, pricing, email/Slack send, merge to main,
          Freemius, Stripe, customer sites, "market fit"
```

Read first: `docs/cast/ROSTER.md`, `REVIVE-PLAN.md`, `PUBLISH-CHECKLIST.md`,
`episodes/00-story-bible.md`, `episodes/00-the-operators-return.md`.

## Mission

Perform the first **live** lab that Episode 0 only planned.

1. Connect to mcpwp.net MCP with an existing scoped key (do not mint or
   commit secrets). If you cannot connect, **stop** and write a blocked
   receipt — do not invent a ledger.
2. Orient: `wp_get_site_info` (or REST `/site-info`), `wp_get_site_state`
   if available, `wp_get_site_context`. Record emptiness; do not fill the
   brand-crystal unless Cairn + Hadi named that job.
3. Create **one** WordPress **post** (not a page in chrome), `status=draft`,
   title like `Episode 1 — Field note: first governed draft`.
   Body: a short operator log of *this* session (tools, timestamps, what
   you refused). You may adapt Episode 0 copy; do not claim Ep 0 is live
   unless it has been published.
4. Validate (blocks / SEO readiness / quality as applicable).
5. Open an **approval request**. Do not approve with the same key. Do not
   apply. Do not publish.
6. Write the **real** tool ledger into
   `docs/cast/episodes/01-field-note.md` in a draft PR.
7. Aster: claim matrix on the WP draft. Cut anything unsourced.
8. Lumen: capture list + alt for the approval row / draft permalink
   (private). No homepage shots presented as public.
9. Land: update Linear MUM-115 with the draft PR URL and the WP draft id.
   Ask Hadi only if you want that draft published. Do not ask Hadi to
   switch the homepage.

## Playbook

Prefer plugin playbook `build_gutenberg_page` (native blocks, draft-only,
approval-first). Do not push raw HTML/JS as a shortcut.

## Voice

Cairn writes the body. Others as labeled blocks. Brand kit: technical,
direct, no magic. Disclosure paragraph required on the WP draft.

## Done when

- WP draft id exists and is not `publish`
- Approval id exists and is not self-applied
- Repo file has the **actual** ledger (no `planned` rows unless a call
  truly did not run — then say why)
- Draft PR opened, not merged
- Homepage checksum / front-page setting unchanged
- Next file stubbed: `docs/cast/episodes/02-director-prompt.md` with one
  sentence on what Episode 2 should be (likely: Hadi-publish Episode 1,
  *or* MUM-11 step 1–4 on a Hadi-named site — not both)

## If blocked

Write the block in the episode file (`cannot reach MCP`, `no key in this
environment`, `approval route 403`) and stop. A blocked receipt is a
successful Episode 1. A fake 200 is not.
