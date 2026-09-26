# Claude Connector Directory — submission package

Issue: #904 (part of #960). Portal: **claude.ai/directory/manage** → **Submit new** → **MCP connector**.

Sources checked 2026-09-26: [Submit a connector to the directory](https://claude.com/docs/connectors/building/submission),
[Connectors directory](https://claude.com/docs/connectors/directory),
[Anthropic Connectors Directory FAQ](https://support.claude.com/en/articles/11596036-anthropic-connectors-directory-faq)
(now redirects into the docs above). The portal opened for submissions 2026-09-25.

## Status: READY — owner mints reviewer key and submits

`https://demo.mcpwp.net` is live: confirmed 2026-09-26 via `/wp-json/` — the `mcpwp/v1`
namespace is present alongside the standard `wp/v2`, `wp-site-health/v1`, `wp-block-editor/v1`,
and `wp-abilities/v1` namespaces. The site identifies itself as "MCPWP Live Demo — Watch AI run
a real WordPress site — live, read-only demo." No plugin version string was visible in the
unauthenticated index; confirm 3.14.0 is what's actually running before pointing reviewers at
it. The "read-only demo" tagline is worth a direct check too — if it enforces read-only at the
WordPress level regardless of key scope, some write-tool calls a reviewer tries may correctly
return nothing changed. That's fine to explain to reviewers, but confirm it's a scope
restriction (a key the owner mints) and not a global lockout, since the Tools step below
expects both read and write tools to be callable.

Everything else below is ready to paste.

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
| Support contact | `support@mumega.com` (published at `https://mcpwp.net/contact/`) |
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
- **Primary contact**: `support@mumega.com` (owner may prefer to substitute a named person for
  review-update correspondence — the published address is the only contact on file)

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

### Test & launch step
Reviewer test account: **`https://demo.mcpwp.net`**. Instructions only — the owner mints the
actual key, nothing is minted as part of this docs pass.

1. Owner logs into `demo.mcpwp.net` WP Admin → MCPWP → Setup and generates a fresh, scoped API
   key labeled for Claude review (write scope, so reviewers can exercise both read and write
   tools — not admin, so a reviewer can't touch publish-only surfaces or site settings).
2. Owner pastes that key into the portal's Test & launch step, along with:
   - Connector URL: `https://demo.mcpwp.net/wp-json/mcpwp/v1/mcp`
   - Header: `X-API-Key: <the minted key>`
   - One line of context: "This is a public demo site — content changes are expected and
     safe to make; it resets/no personal data is present."
3. Confirm before submitting whether the demo's "read-only" framing blocks any write tool a
   reviewer might call — if it does, say so explicitly in this step's notes rather than letting
   a reviewer discover a silent no-op.

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
1. Confirm `demo.mcpwp.net` is running 3.14.0 and check whether "read-only" blocks write-tool
   calls regardless of key scope.
2. Mint a scoped (write, not admin) API key on `demo.mcpwp.net` for Claude reviewers.
3. Export `icon.svg` to the raster format/size the portal's upload widget requires.
4. Confirm the `mcpwp` slug is available.
5. Submit at claude.ai/directory/manage, using the answers above, and raise the
   URL-pattern-vs-custom-connection question with the review team rather than guessing.

**Est. owner time: 20–30 min** — the demo site already exists, so this is mostly minting a key
and running the portal steps.
