# GitHub Repo Metadata — recommended values (#514)

> STATUS: DRAFT — Hadi applies these via repo **Settings** (description/topics/website) and the **Sponsor** button. I do not change live repo settings.
>
> **The `Mumega-com/mcpwp` repo is currently private.** Do not link it in any public
> launch copy, and do not use the "Open Source" topic/tag anywhere, until
> [OWNER DECISION: make repo public?] is resolved. Everything below only applies once
> (if) the repo goes public.

## Description (the stale "239 tools" claim is the #514 item)
Live now: `⚡ 239 MCP tools for WordPress …` — **239 is inflated/unverified.** Counting the
Free and Pro registries directly on `origin/main` gives **172 core Free tools + 9 AI/stock
tools = 181 usable Free**, and **118 Pro tools + 4 Figma = 303 total with Pro**. Don't ship
a hard count that overstates.

**Recommended description (accurate, no inflated count):**
```
Turn any WordPress site into an MCP server — let Claude, Cursor, and any MCP client build
pages, edit Elementor, run WooCommerce, and manage media/SEO through natural language.
180+ tools free, 290+ with Pro. Governed, approval-gated, auditable.
```
(If you want a count, use "180+ tools free, 290+ with Pro" — counted from the registries
on `origin/main` — not 239 or 171. Recount via `tools/list` before any marketing copy
hard-codes a number; it drifts with every release.)

## Topics (current set is already good — minor adds)
Live: ai, claude, mcp, model-context-protocol, woocommerce, wordpress, wordpress-plugin, elementor.
**Add:** `ai-agents`, `cursor`, `seo`, `model-context-protocol-server`. (GitHub caps at 20; all fit.)
**Do not add** `open-source` — the repo is private.

## Website
Set repo **Website** to `https://mcpwp.net`.

## Sponsor button
`.github/FUNDING.yml` added (custom → mcpwp.net/pricing). Fill in GitHub Sponsors handle when the account exists, or remove.

## License
`LICENSE` was a truncated 18-line GPL (GitHub detected it as "Other"). Replaced with the canonical GPL-2.0 text so GitHub's license detector shows **GPL-2.0**, matching readme.txt's `License: GPLv2 or later`. No action needed from you — it's in the PR.
