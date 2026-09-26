# Claude Connector Directory — submission package

Issue: #904 (part of #960). Portal: **claude.ai/directory/manage** → **Submit new** → **MCP connector**.

Sources checked 2026-09-26: [Submit a connector to the directory](https://claude.com/docs/connectors/building/submission),
[Connectors directory](https://claude.com/docs/connectors/directory),
[Anthropic Connectors Directory FAQ](https://support.claude.com/en/articles/11596036-anthropic-connectors-directory-faq)
(now redirects into the docs above). The portal opened for submissions 2026-09-25.

## Status: BLOCKED — owner must provision a demo/test WordPress site before submitting

Everything else below is ready to paste. The blocker is the **Test & launch** step, which needs
live, reviewer-usable credentials — we cannot generate those without a running site. See
"Owner actions" at the bottom.

## Form field → answer

### Connection step
- **Server URL**: MCPWP is a per-install plugin, not one shared server — every WordPress site
  that installs it exposes its own endpoint. Choose **"Users connect to different URLs"** →
  **"URL pattern"**: `https://*/wp-json/mcpwp/v1/mcp`
  (Note: the portal says a **custom connection** — where the user supplies their own URL/key at
  connect time — can't be combined with **URL pattern**. Since MCPWP's connection model *is*
  "user supplies their own URL and key," this is a real tension to resolve with Anthropic during
  review, not a paperwork gap. See "Open question" below.)

### Tools step
Syncs automatically from a connected server. Already satisfied: every tool in 3.14.0 carries a
`title` and the correct `readOnlyHint` / `destructiveHint` / `openWorldHint` / `idempotentHint`
(shipped in #934, merged). Nothing to fix here before submitting.

### Listing step
| Field | Answer |
|---|---|
| Server name (≤100 chars) | `MCPWP` |
| One-liner (≤200 chars) | `Turn any WordPress site into an MCP server. Build pages, edit Elementor, run SEO audits, and manage media through 180+ governed, approval-gated tools.` (156 chars) |
| Description (≤2000 chars) | See below |
| Categories (1–5) | Portal's exact taxonomy wasn't enumerated in the docs pulled for this package — pick the closest live options at submit time. Best-fit guesses: Website/CMS or Developer Tools, plus Productivity. |
| Documentation URL | `https://docs.mcpwp.net/` |
| Privacy policy URL | `https://mcpwp.net/privacy/` (confirmed live 2026-09-26, "MCPWP Privacy Policy and Terms") |
| Support contact | **OWNER TO PROVIDE** — no published support email or contact form was found in the repo or on mcpwp.net during this pass. Needs a real inbox before submitting. |
| Icon | `docs/launch/gallery/src/icon.svg` exists (256×256 vector, "lightning route" mark). No PNG export exists yet — export at whatever raster size the portal's upload widget asks for. |
| URL slug | `mcpwp` (permanent once published — confirm it's free) |

**Description text (paste-ready, 1,010 chars):**

> MCPWP turns your WordPress site into an MCP server. Install the plugin, generate an API key
> (or turn on OAuth), and Claude can build Elementor pages, publish posts, run SEO audits,
> manage menus and media, and more — through natural language.
>
> 180+ tools free, 290+ with Pro, across 21 categories: content, Elementor read/write, media,
> SEO, menus, Gutenberg blocks, taxonomy, webhooks, admin, and AI/stock-photo tools that use
> your own OpenAI, Gemini, ElevenLabs, or Pexels key. Pro adds advanced Elementor builders,
> theme builder, WooCommerce, SEO Pro, LMS, translation, staging pushes, and Figma.
>
> Any write can be staged as an approval request, reviewed, applied, and rolled back — you
> choose what goes through the gate. Publishing itself needs an admin-scoped API key as of
> 3.13: a write-scoped key can create and edit drafts but cannot ship anything without you.
>
> Site memory persists brand voice and design decisions across every session. Proactive
> signals surface broken Elementor data, stale content, and pending updates without being
> asked.
>
> Self-hosted — your site, your data. Verified with Claude Desktop, Claude Code, and Cursor.
> Free is a real download, not a demo: the approval gate, audit log, rollback, and site
> memory are all included, not Pro-gated.

### Use cases step
- **Primary use cases**: building/editing Elementor pages by prompt, running SEO audits and
  applying fixes through the approval gate, bulk content operations, WooCommerce management
  (Pro), menu and media management.
- **What users need before connecting**: a WordPress site with MCPWP installed and an API key
  generated in WP Admin → MCPWP → Setup (or OAuth turned on).
- **Reads or writes data**: both — reads (site info, content, SEO signals) and writes (posts,
  pages, Elementor data, media, settings), gated by key scope and, optionally, the approval
  queue.

### Company step
- **Company name**: Mumega
- **Website**: `https://mcpwp.net`
- **Primary contact**: OWNER TO PROVIDE

### Authentication step
Two real options exist on the product today — present both, since the portal's own guidance
says "URL pattern" rules out Anthropic-held OAuth credentials:
1. **X-API-Key header** — user pastes their own per-site key at connect time (fits "custom
   connection: users supply their own URL or credentials").
2. **OAuth 2.0 via the mumcp.com broker** — the plugin's `oauth_enabled` setting turns this on;
   Claude opens the site's own WordPress login to authorize (PKCE, RFC 8414/9728 discovery, no
   key stored in the client). This is the path the portal's "Authentication works for Claude's
   client... OAuth 2.0" language is written for.

**Open question for review (flag to Anthropic, don't silently pick one):** MCPWP is a
distributed pattern, not a single hosted server — one plugin, many independent WordPress
installs, each with its own URL and its own OAuth broker session against mumcp.com. The portal
is built around "one server, one auth config." Whichever of URL-pattern/custom-connection the
review team says fits best, use that; don't guess and submit both.

### Data handling step
- **Underlying API**: your own — MCPWP is a first-party WordPress REST API surface the plugin
  registers on the customer's own site. Nothing is proxied through a third party MCPWP doesn't
  control (the mumcp.com broker only handles the OAuth handshake and token introspection).
- **Personal health data / sponsored content**: no.

### Test & launch step — BLOCKED
Needs: a live WordPress install with MCPWP active, a generated API key (or OAuth enrolled),
and step-by-step connect instructions a reviewer can follow with no prior context. Nothing here
until the owner provisions a demo site (see below).

### Compliance step
Seven acknowledgments (directory guidelines, first-party API usage, financial transactions, AI
media generation, prompt injection, conversation data collection, public documentation) — all
answerable "as expected" for MCPWP: it's a first-party API, no financial transactions or AI
media generation inside the connector itself (image-gen tools call the *user's own* configured
provider key, not MCPWP's), and documentation is public at docs.mcpwp.net.

## Required assets
| Asset | Source | Status |
|---|---|---|
| Icon | `docs/launch/gallery/src/icon.svg` (256×256 SVG) | Exists — export to PNG at the portal's requested size before upload |
| Gallery/carousel screenshots (only if listed as an MCP App — MCPWP is a plain connector, not an App, so these likely aren't required) | `docs/launch/gallery/01-05*.png` (1270×760 each) | Exist, in case the reviewer asks for visuals |

## Owner actions
1. Stand up a demo WordPress site (or reuse an existing rig), install MCPWP 3.14.0, generate a
   test API key (and optionally enroll OAuth via mumcp.com), and write the reviewer-facing
   connect steps — this is the actual blocker.
2. Provide a support contact (email or form) and a company primary contact.
3. Export `icon.svg` to the raster format/size the portal's upload widget requires.
4. Confirm the `mcpwp` slug is available.
5. Submit at claude.ai/directory/manage, using the answers above, and raise the
   URL-pattern-vs-custom-connection question with the review team rather than guessing.

**Est. owner time: 45–90 min**, most of it standing up the demo site and writing its connect
instructions.
