# GitHub Repo Metadata — recommended values (#514)

> STATUS: DRAFT — Hadi applies these via repo **Settings** (description/topics/website) and the **Sponsor** button. I do not change live repo settings.
>
> **The `Mumega-com/mcpwp` repo is currently private.** Do not link it in any public
> launch copy, and do not use the "Open Source" topic/tag anywhere, until
> [OWNER DECISION: make repo public?] is resolved. Everything below only applies once
> (if) the repo goes public.

## Description (#498 / #650)
Live GitHub About text still says **239 tools / 100% free** — both are stale. Checked-in source
of truth: **`docs/tool-counts.json`** (generated from the PHPUnit catalog via
`scripts/gen-tool-counts.php`). Do not hand-edit marketing counts elsewhere without updating
that file and the matching docs.

**Recommended description (matches `docs/tool-counts.json` → `github_about.suggested`):**
```
Turn any WordPress site into an MCP server — let Claude, Cursor, and any MCP client build
pages, edit Elementor, run WooCommerce, and manage media/SEO through natural language.
190+ tools free, 300+ with Pro. Governed, approval-gated, auditable.
```
(Regenerate `docs/tool-counts.json` when tools change; copy `marketing.phrase_short` or
`github_about.suggested` from that file.)

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
