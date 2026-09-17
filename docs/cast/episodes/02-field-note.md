# Episode 2 — Field note: live lab, key still not injected

**Status:** blocked receipt — WordPress draft **not** created. Approval **not**
opened. Homepage **not** touched.  
**Byline:** Cairn, with Aster, Lumen, and MUMCP.  
**Director:** Grok 4.6 (Cursor cloud, run `bc-45a55b1d-0308-53fe-8ed3-041cc3090d41`).  
**Human gate:** Hadi Servat.  
**Linear:** [MUM-115](https://linear.app/mumega/issue/MUM-115).  
**Base:** PR [#868](https://github.com/Mumega-com/mcpwp/pull/868) / Episode 1
blocked receipt (stacked on [#867](https://github.com/Mumega-com/mcpwp/pull/867)).  
**This PR:** pending (draft, stacked on #868).  
**UTC window:** 2026-09-17T14:27Z–14:29Z.

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
Linear: MUM-115 / Episode 2
Will not: Homepage V3, nav, theme, pricing, email/Slack send, merge to main,
          Freemius, Stripe, customer sites, "market fit", minting a new API key
```

The director cast me. The pot token on this seat is **not** `cairn`.
`boot_context` bound `hadi-grok-desktop`
(`fb18400b-4908-42f4-90dd-a3f5ad7d0654`). `orient({agent:"cairn"})` returned
the Cairn basin-drop (autonomy **draft**). `check_in` labeled
`episode-2-blocked-receipt` on the same bound seat. Byline is editorial.
Authority is the pot. I did not `connect` as `cairn`.

## The scene

Episode 1 proved the work plane and the missing key. Hadi said
`MCPWP_API_KEY` is now in the Cloud environment. Episode 2 was the replay:
one WordPress **post**, `status=draft`, playbook `build_gutenberg_page` (or
the post equivalent), an approval request, no self-apply, a real tool
ledger.

The work plane is still up. Secret injection works — this pod received
other Cloud secrets. `MCPWP_API_KEY` is not among them. That is the
episode.

A blocked receipt is a successful Episode 2. A fake 200 is not.

## What we refused

- Invent a `200` on `/site-info`, `/mcp`, `/approvals`, or playbooks.
- Treat "Hadi says the key is in the Cloud environment" as a 200.
- Mint or commit an API key (`wp_create_api_key`, repo secrets, pasted
  tokens).
- Read or print any injected secret value.
- Create a public page, switch chrome, or treat the homepage as the lab.
- Open an approval with no target post.
- Approve or apply with the same principal.
- Claim Episode 0 or Episode 1 is live on WordPress.
- Start MUM-11 or talk about market fit.

## Block

**Reason:** `no MCPWP_API_KEY in this environment` — more precise than
Episode 1.

mcpwp.net still advertises `mcpwp/v1`. Unauthenticated discovery still
works. Every authenticated MCPWP route we called still returned `401`.

This Cursor cloud pod **does** inject secrets. The inventory is:

| Cursor env var | Value (names only) |
|----------------|--------------------|
| `CLOUD_AGENT_ALL_SECRET_NAMES` | `DATAFORSEO_LOGIN,DATAFORSEO_PASSWORD` |
| `CLOUD_AGENT_INJECTED_SECRET_NAMES` | `DATAFORSEO_LOGIN,DATAFORSEO_PASSWORD` |
| `MCPWP_API_KEY` | **unset** (not in either list) |
| `MCPWP_URL` | **unset** |

`DATAFORSEO_*` values were present in process env. They were not used.
They are the wrong plane (SEO market data, not mcpwp.net MCP). Their
values are not printed here.

Pot `secret_env_status` still reports `MCPWP_API_KEY`, `MCPWP_URL`,
`mcpwp_api_key`, `MCPWP_NET_API_KEY`, `MCPWP_SITE_KEY`, `MCPWP_BASE_URL`
all **unbound**. `recall` / `inbox` / `squad_recall(squad-core)` still
hold no scoped mcpwp.net key. This environment's `environment.json` is
owner-restricted (`environmentJson: null`, source Personal,
`RUNTIME_FORWARD_FILL`). GitHub Actions secrets remain unreadable from
this run, and must.

Stop condition from the director prompt: if `/site-info` still 401s,
write another blocked receipt — do not invent a 200.

## MUMCP — tool ledger (actual)

Playbook intended: `build_gutenberg_page` (native blocks, draft-only,
approval-first). Required tools in
`Mcpwp_Agent_Playbooks`: `wp_get_site_state`, `wp_get_site_context`,
`wp_create_page`, `wp_validate_blocks`, `wp_validate_seo_readiness`,
`wp_list_approvals`. Episode 2 asked for a **post**, not a chrome
page; the same gates apply to `wp_create_post` / REST `POST /posts`
with `status=draft`. Those mutating calls **did not run**.

| t | When (UTC) | Tool / route | Intent | Result | Approval id |
|---|------------|--------------|--------|--------|-------------|
| 1 | 2026-09-17T14:27:29Z | process env + `CLOUD_AGENT_*_SECRET_NAMES` | Existing scoped key? | `MCPWP_API_KEY` **unset**. Injected names: `DATAFORSEO_LOGIN`, `DATAFORSEO_PASSWORD` only. | — |
| 2 | 2026-09-17T14:28:00Z | pot `boot_context` / `orient(cairn)` / `check_in` | Flock identity | Bound seat `hadi-grok-desktop`; Cairn packet autonomy=`draft`; check-in label `episode-2-blocked-receipt` | — |
| 3 | 2026-09-17T14:28:00Z | pot `secret_env_status` | Existing scoped key? | `MCPWP_API_KEY`, `MCPWP_URL`, `mcpwp_api_key`, `MCPWP_NET_API_KEY`, `MCPWP_SITE_KEY`, `MCPWP_BASE_URL` all **unbound** | — |
| 4 | 2026-09-17T14:28:10Z | pot `recall` / `inbox` / `squad_recall(squad-core)` | Key location in memory | Episode 1 blocked-receipt memory only. Inbox empty. No mcpwp.net scoped key stored. | — |
| 5 | 2026-09-17T14:27:29Z | `GET https://mcpwp.net/wp-json/` | Discover namespaces | **HTTP 200**. `namespaces` includes `mcpwp/v1`. Site name `MCPWP`. | — |
| 6 | 2026-09-17T14:27:29Z | `GET /wp-json/mcpwp/v1/site-info` | Orient (`wp_get_site_info`) | **HTTP 401** `missing_api_key` | — |
| 7 | 2026-09-17T14:28:23Z | `GET /wp-json/mcpwp/v1/onboard` | First-connection briefing | **HTTP 401** `missing_api_key` | — |
| 8 | 2026-09-17T14:28:26Z | `POST /wp-json/mcpwp/v1/mcp` `initialize` | MCP handshake | **HTTP 401** `missing_api_key` | — |
| 9 | 2026-09-17T14:28:24Z | `GET /wp-json/mcpwp/v1/agent-playbooks` | Read `build_gutenberg_page` live | **HTTP 401** `missing_api_key` | — |
| 10 | 2026-09-17T14:28:25Z | `GET /wp-json/mcpwp/v1/approvals` | List approvals | **HTTP 401** `missing_api_key` | — |
| 11 | 2026-09-17T14:28:28Z | `GET /site-info` + placeholder `X-API-Key: mcpwp_episode2_not_a_real_key` | Distinguish missing vs invalid | **HTTP 401** `invalid_api_key` | — |
| 12 | — | `wp_get_site_state` | Playbook step 1 | **did not run** — no key | — |
| 13 | — | `wp_get_site_context` | Brand-crystal | **did not run** — no key. Emptiness **not** observed. | — |
| 14 | — | `wp_create_post` / playbook create (draft) | One post `status=draft` | **did not run** | — |
| 15 | — | `wp_validate_blocks` / `wp_validate_seo_readiness` / `wp_audit_content_quality` | Validate | **did not run** | — |
| 16 | — | `POST /approvals` | Open approval | **did not run** | — |
| 17 | 2026-09-17T14:27:29Z and 14:28:23Z | `GET https://mcpwp.net/` logged-out | Homepage checksum | **HTTP 200**, 159180 bytes, sha256 `84c5ac92b7dd41415a08dd193d64dd2874dae49aef788b655a8becd3704523c5` three times this session. Title: `What do you want AI to do with WordPress? - MCPWP`. Canonical `https://mcpwp.net/`. String `Homepage V3` absent. | — |

**401 bodies (verbatim, missing-key routes):**

```json
{"code":"missing_api_key","message":"API key is required.","data":{"status":401,"hint":"Include your API key in the X-API-Key header or Authorization: Bearer header. Generate keys in WP Admin > MCPWP > Settings, or call wp_create_api_key if you have admin access."}}
```

**401 body (placeholder key):**

```json
{"code":"invalid_api_key","message":"Invalid API key.","data":{"status":401,"hint":"The provided API key is not valid. Check for typos or whitespace. Keys start with \"mcpwp_\". Generate a new key in WP Admin > MCPWP > Settings."}}
```

**WordPress post id:** none.  
**Approval id:** none. Not self-applied, because nothing was opened.

### Homepage hash vs Episode 1

Episode 1 recorded sha256
`35a0fdce98ab2a1eb1f4546a886ee8864026920a1207bed13e0429ddb3a35009`
(159180 bytes, same title). This session's bytes and title match; the
digest does not. We did **not** POST/PUT chrome, menus, theme, or the
front-page setting. Asset URLs on the logged-out homepage carry
WordPress `ver=` cache-busters, including unix timestamps (one this
session: `1789654457`). A moving digest at constant size is consistent
with that, and is **not** a Homepage V3 switch. String `Homepage V3`
is still absent.

## Aster — claim matrix

| Claim | Class | Source | Fail |
|-------|-------|--------|------|
| mcpwp.net exposes `mcpwp/v1` | fact | `GET /wp-json/` HTTP 200, namespace list this session | Namespace missing on a later logged-out `/wp-json/` |
| Authenticated MCPWP routes require a key | fact | 401 `missing_api_key` on `/site-info`, `/onboard`, `/mcp`, `/agent-playbooks`, `/approvals` | Any of those answering 200 without a key |
| This Cursor cloud run had no usable scoped MCPWP key | fact | `MCPWP_API_KEY` unset; name absent from `CLOUD_AGENT_ALL_SECRET_NAMES`; pot secrets unbound | `MCPWP_API_KEY` appearing in `CLOUD_AGENT_INJECTED_SECRET_NAMES` **and** `/site-info` 200 |
| Cloud secret injection is working | fact | `CLOUD_AGENT_INJECTED_SECRET_NAMES` lists `DATAFORSEO_LOGIN`, `DATAFORSEO_PASSWORD`; those process-env vars are present (values not printed) | Those names listed but vars unset |
| Hadi bound `MCPWP_API_KEY` into *this* pod | forbidden | The name is not in the injected-secret list | Cut. Do not equate a dashboard intent with this process. |
| A WordPress draft was created | forbidden | — | Cut. No post id. |
| An approval was opened | forbidden | — | Cut. No approval id. |
| Episode 0 or 1 is published on mcpwp.net | forbidden | PRs #867 / #868 are draft PRs; no WP publish this run | Cut. |
| The new homepage is live / we switched V3 | forbidden | Title + canonical unchanged; `Homepage V3` absent; MUM-53 | Cut. |
| We proved market fit | forbidden | MUM-11 | Cut. |
| Site context is empty | intent | Would have been `wp_get_site_context` | Do not fill the brand-crystal from this block |

No launch-blocking commercial claims were drafted. Nothing here is
customer-facing on the live site.

## Lumen — capture list

No private draft permalink exists. Do not shoot the public homepage as
if it were the lab.

| Shot | Purpose | Alt / decorative | Reduced motion |
|------|---------|------------------|----------------|
| This ledger table (server-rendered) | The picture *is* the 401 plus the injected-secret name list | Table is the picture | Static table |
| Secret-name inventory (names only) | Prove injection works and `MCPWP_API_KEY` is the missing name | "Cloud agent injected DataForSEO secrets; MCPWP_API_KEY not in the name list" | Text is enough |
| Homepage checksum line (sha256 + title + bytes) | Prove chrome was not the work; record digest vs Episode 1 | "Logged-out mcpwp.net homepage 159180 bytes, title unchanged, Homepage V3 absent" | Text is enough |
| Approval row | **No row to photograph** | Do not invent one | — |
| Draft permalink | **None** | Do not use `/` as a stand-in | — |

No-JS and images-off still carry: namespace present, MCPWP key missing,
other Cloud secrets present by name, no post, no approval.

## Where this lands

| Artifact | State |
|----------|--------|
| Repo field note | this file, draft PR pending |
| WordPress post | **not created** |
| Approval | **not opened** |
| Homepage / front-page setting | **not written** (checksum note above) |
| Episode 0 article | still repo draft on PR #867 — not claimed live |
| Episode 1 field note | still blocked receipt on PR #868 |
| Next prompt | [`03-director-prompt.md`](03-director-prompt.md) |

Hadi: do **not** publish this as a WordPress post. There is nothing to
publish. The next bind has to be visible *inside the pod*:
`CLOUD_AGENT_INJECTED_SECRET_NAMES` must include `MCPWP_API_KEY`, and
`GET /wp-json/mcpwp/v1/site-info` with that header must return **200**.
Adding the name to a different environment, to GitHub Actions, or to pot
memory does not reach this process. Do not ask this agent to mint a key.
Do not switch Homepage V3.

---

*Cairn · Episode 2 · blocked receipt · 2026-09-17*  
*Related: MUM-115, PR #867, PR #868, MUM-11 (not this run), MUM-53
(homepage gate, untouched).*
