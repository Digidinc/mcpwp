# Episode 1 — Field note: first governed draft

**Status:** blocked receipt — WordPress draft **not** created. Approval **not**
opened. Homepage **not** touched.  
**Byline:** Cairn, with Aster, Lumen, and MUMCP.  
**Director:** Grok 4.6 (Cursor cloud, run `bc-9f113123-3ebe-54e3-8e18-8cfd5cfb33c4`).  
**Human gate:** Hadi Servat.  
**Linear:** [MUM-115](https://linear.app/mumega/issue/MUM-115).  
**Base:** PR [#867](https://github.com/Mumega-com/mcpwp/pull/867) / `docs/cast/`.  
**UTC window:** 2026-09-17T00:01Z–00:03Z.

---

**Disclosure.** Cairn, Aster, Lumen, and MUMCP are synthetic agents. They are
not humans. Hadi Servat is the human gate. Publication is governed. This note
is a repository draft of a lab that **did not write to WordPress**.

---

```
Director: Grok 4.6 (Cursor cloud)
Lead: Cairn  (pot slug cairn · b5279f2f-011e-4a66-9fe2-44c58851724c)
Support: Aster (claims), Lumen (capture), MUMCP / mcpwp-native (ledger)
Work plane: https://mcpwp.net  MCP namespace mcpwp/v1
Flock plane: draft-not-send
Linear: MUM-115 / Episode 1
Will not: Homepage V3, nav, theme, pricing, email/Slack send, merge to main,
          Freemius, Stripe, customer sites, "market fit"
```

The director cast me. The pot token on this seat is **not** `cairn`.
`boot_context` bound `hadi-grok-desktop`
(`fb18400b-4908-42f4-90dd-a3f5ad7d0654`). `orient({agent:"cairn"})` returned
the Cairn basin-drop (autonomy **draft**). Byline is editorial. Authority is
the pot. I did not `connect` as `cairn`.

## The scene

Episode 0 planned the first live lab. Episode 1 was supposed to *run* it:
one WordPress **post**, `status=draft`, playbook `build_gutenberg_page` (or
the post equivalent), an approval request, no self-apply, a real tool
ledger.

The work plane is up. The key is not in this environment. That is the
episode.

A blocked receipt is a successful Episode 1. A fake 200 is not.

## What we refused

- Invent a `200` on `/site-info`, `/mcp`, `/approvals`, or playbooks.
- Mint or commit an API key (`wp_create_api_key`, repo secrets, pasted
  tokens).
- Create a public page, switch chrome, or treat the homepage as the lab.
- Open an approval with no target post.
- Approve or apply with the same principal.
- Claim Episode 0 is live on WordPress. It is still a repository draft
  in PR #867.
- Start MUM-11 or talk about market fit.

## Block

**Reason:** `no key in this environment`.

mcpwp.net advertises `mcpwp/v1`. Unauthenticated discovery works.
Every authenticated MCPWP route we called returned `401`. The Cursor
cloud shell has no `MCPWP_API_KEY`. Pot `secret_env_status` reports
`MCPWP_API_KEY` and `MCPWP_URL` **unbound**. This environment's
`environment.json` is owner-restricted and was not readable from the
agent. GitHub Actions documents repo secrets of those names for
`site-data` deploy — this run cannot read Actions secrets, and must
not.

Stop condition from the director prompt: do not invent a ledger.

## MUMCP — tool ledger (actual)

Playbook intended: `build_gutenberg_page` (native blocks, draft-only,
approval-first). Required tools in
`Mcpwp_Agent_Playbooks`: `wp_get_site_state`, `wp_get_site_context`,
`wp_create_page`, `wp_validate_blocks`, `wp_validate_seo_readiness`,
`wp_list_approvals`. Episode 1 asked for a **post**, not a chrome
page; the same gates apply to `wp_create_post` / REST `POST /posts`
with `status=draft`. Those mutating calls **did not run**.

| t | When (UTC) | Tool / route | Intent | Result | Approval id |
|---|------------|--------------|--------|--------|-------------|
| 1 | 2026-09-17T00:01:45Z | pot `boot_context` / `orient(cairn)` / `check_in` | Flock identity | Bound seat `hadi-grok-desktop`; Cairn packet autonomy=`draft` | — |
| 2 | 2026-09-17T00:01:50Z | pot `secret_env_status` | Existing scoped key? | `MCPWP_API_KEY`, `MCPWP_URL`, `mcpwp_api_key`, `MCPWP_NET_API_KEY`, `MCPWP_SITE_KEY` all **unbound** | — |
| 3 | 2026-09-17T00:01:50Z | pot `recall` / `inbox` / `squad_recall(squad-core)` | Key location in memory | No mcpwp.net scoped key stored. Inbox empty. | — |
| 4 | 2026-09-17T00:02:00Z | `GET https://mcpwp.net/wp-json/` | Discover namespaces | **HTTP 200**. `namespaces` includes `mcpwp/v1`. | — |
| 5 | 2026-09-17T00:02:00Z | `GET /wp-json/mcpwp/v1/site-info` | Orient (`wp_get_site_info`) | **HTTP 401** `missing_api_key` | — |
| 6 | 2026-09-17T00:02:00Z | `GET /wp-json/mcpwp/v1/onboard` | First-connection briefing | **HTTP 401** `missing_api_key` | — |
| 7 | 2026-09-17T00:02:51Z | `POST /wp-json/mcpwp/v1/mcp` `initialize` | MCP handshake | **HTTP 401** `missing_api_key` | — |
| 8 | 2026-09-17T00:02:51Z | `GET /wp-json/mcpwp/v1/agent-playbooks` | Read `build_gutenberg_page` live | **HTTP 401** `missing_api_key` | — |
| 9 | 2026-09-17T00:02:51Z | `GET /wp-json/mcpwp/v1/approvals` | List approvals | **HTTP 401** `missing_api_key` | — |
| 10 | 2026-09-17T00:02:51Z | `GET /site-info` + placeholder `X-API-Key: mcpwp_episode1_not_a_real_key` | Distinguish missing vs invalid | **HTTP 401** `invalid_api_key` | — |
| 11 | — | `wp_get_site_state` | Playbook step 1 | **did not run** — no key | — |
| 12 | — | `wp_get_site_context` | Brand-crystal | **did not run** — no key. Emptiness **not** observed. | — |
| 13 | — | `wp_create_post` / playbook create (draft) | One post `status=draft` | **did not run** | — |
| 14 | — | `wp_validate_blocks` / `wp_validate_seo_readiness` / `wp_audit_content_quality` | Validate | **did not run** | — |
| 15 | — | `POST /approvals` | Open approval | **did not run** | — |
| 16 | 2026-09-17T00:02:00Z and 00:02:51Z | `GET https://mcpwp.net/` logged-out | Homepage checksum | **HTTP 200**, 159180 bytes, sha256 `35a0fdce98ab2a1eb1f4546a886ee8864026920a1207bed13e0429ddb3a35009` both times. Title: `What do you want AI to do with WordPress? - MCPWP`. String `Homepage V3` absent. | — |

**401 bodies (verbatim, both missing-key routes):**

```json
{"code":"missing_api_key","message":"API key is required.","data":{"status":401,"hint":"Include your API key in the X-API-Key header or Authorization: Bearer header. Generate keys in WP Admin > MCPWP > Settings, or call wp_create_api_key if you have admin access."}}
```

**401 body (placeholder key):**

```json
{"code":"invalid_api_key","message":"Invalid API key.","data":{"status":401,"hint":"The provided API key is not valid. Check for typos or whitespace. Keys start with \"mcpwp_\". Generate a new key in WP Admin > MCPWP > Settings."}}
```

**WordPress post id:** none.  
**Approval id:** none. Not self-applied, because nothing was opened.

## Aster — claim matrix

| Claim | Class | Source | Fail |
|-------|-------|--------|------|
| mcpwp.net exposes `mcpwp/v1` | fact | `GET /wp-json/` HTTP 200, namespace list this session | Namespace missing on a later logged-out `/wp-json/` |
| Authenticated MCPWP routes require a key | fact | 401 `missing_api_key` on `/site-info`, `/onboard`, `/mcp`, `/agent-playbooks`, `/approvals` | Any of those answering 200 without a key |
| This Cursor cloud run had no usable scoped key | fact | empty process env; pot secrets unbound; no claim in recall | A bound `MCPWP_API_KEY` appearing in the same seat |
| A WordPress draft was created | forbidden | — | Cut. No post id. |
| An approval was opened | forbidden | — | Cut. No approval id. |
| Episode 0 is published on mcpwp.net | forbidden | PR #867 is a draft PR; no WP publish this run | Cut. |
| The new homepage is live / we switched V3 | forbidden | Homepage checksum unchanged; MUM-53 | Cut. |
| We proved market fit | forbidden | MUM-11 | Cut. |
| Site context is empty | intent | Would have been `wp_get_site_context` | Do not fill the brand-crystal from this block |

No launch-blocking commercial claims were drafted. Nothing here is
customer-facing on the live site.

## Lumen — capture list

No private draft permalink exists. Do not shoot the public homepage as
if it were the lab.

| Shot | Purpose | Alt / decorative | Reduced motion |
|------|---------|------------------|----------------|
| This ledger table (server-rendered) | The picture *is* the 401 | Table is the picture | Static table |
| Homepage checksum line (sha256 + title) | Prove chrome was not the work | "Logged-out mcpwp.net homepage checksum unchanged during Episode 1" | Text is enough |
| Approval row | **No row to photograph** | Do not invent one | — |
| Draft permalink | **None** | Do not use `/` as a stand-in | — |

No-JS and images-off still carry: namespace present, key missing, no
post, no approval, homepage hash unchanged.

## Where this lands

| Artifact | State |
|----------|--------|
| Repo field note | this file, draft PR |
| WordPress post | **not created** |
| Approval | **not opened** |
| Homepage / front-page setting | **unchanged** (checksum above) |
| Episode 0 article | still repo draft on PR #867 — not claimed live |
| Next prompt | [`02-director-prompt.md`](02-director-prompt.md) |

Hadi: do **not** publish this as a WordPress post. There is nothing to
publish. Bind an **existing** scoped mcpwp.net key into the Cursor
cloud environment (`MCPWP_API_KEY` / `MCPWP_URL`) if you want Episode
2 to replay the lab. Do not ask this agent to mint a key. Do not
switch Homepage V3.

---

*Cairn · Episode 1 · blocked receipt · 2026-09-17*  
*Related: MUM-115, PR #867, MUM-11 (not this run), MUM-53 (homepage
gate, untouched).*
