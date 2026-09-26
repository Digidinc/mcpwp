# Meta Muse connector platform — submission package

Issue: #904 mentions Muse for context; primary tracking is #960. Portal: **muse.ai/platform**.

Sources checked 2026-09-26: [Meta opens Muse to third-party developers with new connector API](https://cryptobriefing.com/meta-muse-developer-connector-api-access/),
[Muse Connector Platform: What Meta Opened to Developers](https://cellcog.ai/blog/muse-connector-platform/),
[Meta opened Muse to connectors. Here is what the form asks for, and what we filed.](https://stacktr.ee/blog/muse-connector-platform).
Muse opened connector submissions 2026-09-18. Meta has not published a formal technical
contract, review SLA, fees, or revenue share as of this pass — the field list below is
reconstructed from third-party accounts of the live form, not an official spec page, so
re-verify field names in the form itself before submitting.

## Status: BLOCKED — same reason as the Claude directory: needs a demo site + test account

The form itself doesn't gate hard on a public GitHub repo (good — MCPWP's repo being private is
not a blocker here), but it does ask for a real, connectable endpoint and — per the review
description ("end to end testing") — a way for Meta's reviewers to actually exercise it.

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
| Work email | OWNER TO PROVIDE |
| Support email or URL | OWNER TO PROVIDE — same gap as the Claude directory package |
| Privacy policy URL | `https://mcpwp.net/privacy/` |
| Terms of service URL | Not published separately from the privacy/terms page — `https://mcpwp.net/privacy/` doubles as this per the live page title ("MCPWP Privacy Policy and Terms"); confirm Muse accepts one combined URL for both fields. |
| "Anything else" (free text) | Optional — could note: "MCPWP is a WordPress plugin; each install is its own MCP endpoint. The URL and API key below are for our reviewer demo site, not a shared production server." |

### Technical specs
| Field | Answer |
|---|---|
| Connection type | **Existing MCP** |
| Hosted MCP endpoint URL | `https://<demo-site>/wp-json/mcpwp/v1/mcp` — needs the owner's demo site (see below); do not submit the bare pattern, Muse's form wants a real URL to test against |
| Documentation | `https://docs.mcpwp.net/` |
| Access requirements | "Requires a WordPress site with MCPWP installed and an API key generated in WP Admin → MCPWP → Setup, or OAuth via the mumcp.com broker. Free tier covers 180+ tools; a Pro license (Freemius, $59/yr+) unlocks 290+." |
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
1. Same demo/test WordPress site as the Claude directory package — one demo site can serve
   both submissions.
2. Provide name, work email, support contact.
3. Re-export the icon at 512×512.
4. Sign in at muse.ai/platform (work email + 6-digit code; new accounts also need a date of
   birth) and re-verify the field list above against the live form — this package is built
   from third-party reporting on the form, not an official spec, since Meta hasn't published
   one.
5. Submit.

**Est. owner time: 20–30 min**, assuming the Claude-directory demo site already exists —
otherwise add the demo-site setup time from that package.
