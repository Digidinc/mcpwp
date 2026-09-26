# Meta Muse connector platform — submission package

Issue: #904 mentions Muse for context; primary tracking is #960. Portal: **muse.ai/platform**.

Sources checked 2026-09-26: [Meta opens Muse to third-party developers with new connector API](https://cryptobriefing.com/meta-muse-developer-connector-api-access/),
[Muse Connector Platform: What Meta Opened to Developers](https://cellcog.ai/blog/muse-connector-platform/),
[Meta opened Muse to connectors. Here is what the form asks for, and what we filed.](https://stacktr.ee/blog/muse-connector-platform).
Muse opened connector submissions 2026-09-18. Meta has not published a formal technical
contract, review SLA, fees, or revenue share as of this pass — the field list below is
reconstructed from third-party accounts of the live form, not an official spec page, so
re-verify field names in the form itself before submitting.

## Status: READY — owner mints reviewer key and submits

`https://demo.mcpwp.net` is confirmed live (2026-09-26): its `/wp-json/` index carries the
`mcpwp/v1` namespace. The form itself doesn't gate hard on a public GitHub repo (good — MCPWP's
repo being private is not a blocker here), and now there's a real endpoint to give Meta's
reviewers for the "end to end testing" they run.

## Form field → answer (three sections: Overview, Technical specs, Review)

### Overview
| Field | Answer |
|---|---|
| Connector name | `MCPWP` |
| Company or developer | Mumega |
| Product website | `https://mcpwp.net` |
| Example prompts | "Build a landing page for my dental clinic with a hero, three services, and a contact form." / "Run an SEO audit on my WordPress site and show me the top three fixes." / "Add a new post to my blog and set a featured image from this URL." |
| Connector icon (512×512 PNG or SVG) | `docs/launch/gallery/src/icon.svg` exists but is 256×256 — re-export at 512×512 before upload. |
| Payments acceptance | No |
| Your name | OWNER TO PROVIDE |
| Work email | `support@mumega.com` (or a named individual's work email, owner's call) |
| Support email or URL | `support@mumega.com` / `https://mcpwp.net/contact/` |
| Privacy policy URL | `https://mcpwp.net/privacy/` |
| Terms of service URL | Not published separately from the privacy/terms page — `https://mcpwp.net/privacy/` doubles as this per the live page title ("MCPWP Privacy Policy and Terms"); confirm Muse accepts one combined URL for both fields. |
| "Anything else" (free text) | Optional — could note: "MCPWP is a WordPress plugin; each install is its own MCP endpoint. The URL and API key below are for our public reviewer demo site (demo.mcpwp.net), not a shared production server." |

### Technical specs
| Field | Answer |
|---|---|
| Connection type | **Existing MCP** |
| Hosted MCP endpoint URL | `https://demo.mcpwp.net/wp-json/mcpwp/v1/mcp` — live and confirmed serving the `mcpwp/v1` namespace (2026-09-26). Owner mints a reviewer key here before submitting (see Owner actions) — none has been created as part of this docs pass. |
| Documentation | `https://docs.mcpwp.net/` |
| Access requirements | "Requires a WordPress site with MCPWP installed and an API key generated in WP Admin → MCPWP → Setup, or OAuth via the mumcp.com broker. Free tier covers 180+ tools; a Pro license (Freemius, $59/yr+) unlocks 290+. The demo site above ships a reviewer key with write scope for testing." |
| Authentication methods | Check **API keys** (the `X-API-Key` header) and **OAuth with PKCE** (the mumcp.com broker path — RFC 8414/9728 discovery, PKCE S256 authorize + token exchange, per `readme.txt`). Leave "Other" unchecked. |

### Review
Read-back + three confirmation checkboxes (authorization to represent the company, acknowledgment
that Meta's approval is not guaranteed, agreement to Muse's connector terms). Nothing to
prepare beyond having the above answers correct — this section has no separate content.

## Required assets
| Asset | Source | Status |
|---|---|---|
| Icon, 512×512 PNG or SVG | `docs/launch/gallery/src/icon.svg` (256×256) | Needs re-export at 512×512 |

No screenshot carousel is documented for Muse in the sources checked — if the live form asks
for one, reuse `docs/launch/gallery/01-05*.png` (1270×760, from the Product Hunt kit).

## Owner actions
1. Mint a scoped (write, not admin) reviewer API key on `demo.mcpwp.net` — same demo site and
   likely the same key as the Claude directory package.
2. Provide your name (support/work email is already covered by `support@mumega.com`).
3. Re-export the icon at 512×512.
4. Sign in at muse.ai/platform (work email + 6-digit code; new accounts also need a date of
   birth) and re-verify the field list above against the live form — this package is built
   from third-party reporting on the form, not an official spec, since Meta hasn't published
   one.
5. Submit.

**Est. owner time: 15–20 min** — the demo site already exists.
