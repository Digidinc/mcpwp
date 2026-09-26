# WordPress.org Plugin Name/Slug Exception Request (#495 / #521)

> **SUPERSEDED (2026-09-26, #931):** MCPWP is not going on WordPress.org — it is distributed
> directly (updates.mcpwp.net, Freemius, directories). The wp.org listing stays the separate
> "Mumega MCP" edition. Nothing here needs sending. Kept for reference only.
>
> STATUS: DRAFT for Hadi to send. Decision (2026-06-11): **pursue the exception** — keep the `MCPWP` name + `mcpwp` slug rather than rename.

## Context & realistic odds (read before sending)
WordPress.org restricts the standalone term **"wp"** in new plugin **names and slugs**. The Plugin Check tool flags `MCPWP` (name) and `mcpwp` (slug). Exceptions exist but the slug rule (it becomes the permalink `wordpress.org/plugins/mcpwp/`) is enforced more strictly than the display-name rule. Two realistic outcomes:
1. **Display name accepted, slug must change** — most likely. The reviewer may let the plugin *display* as "MCPWP" (or "MCPWP – MCP for WordPress") while requiring a compliant **slug** (the permalink). A slug like `mcp-connect`, `model-context-protocol`, or `mcp-server-connector` would satisfy them while the public name stays MCPWP.
2. **Full exception** — less likely for a brand-new submission, but worth requesting given the established product (the `mcpwp.net` domain, GitHub repo, and existing installs predate the submission).

**Fallback if rejected:** the rename is mechanical and ready to run on request (slug/name/prefix migration across the codebase). Choosing the exception path now costs nothing but a polite email; the rename remains available.

## Where to send it
When submitting at https://wordpress.org/plugins/developers/add/, the automated checker will flag the name. The review is handled by **plugins@wordpress.org** — reply to the review thread (or include this as the submission note) with the request below.

---

## Drafted request

> **Subject:** Plugin name/slug exception request — "MCPWP" (Model Context Protocol for WordPress)
>
> Hello WordPress Plugins Team,
>
> I'm submitting a plugin called **MCPWP** and I understand the automated check flags the term "wp" in the name and slug. I'd like to request an exception, and I'm happy to comply with whatever you decide.
>
> **What the name means:** "MCPWP" is **MCP + WP** — *Model Context Protocol for WordPress*. MCP (the Model Context Protocol) is an open standard for connecting AI assistants to external systems. The plugin turns a WordPress site into an MCP server so site owners can let AI clients (Claude, Cursor, etc.) manage their content through a governed, approval-gated, auditable interface. The "WP" is purely descriptive of what it connects to; there is no intent to imply official WordPress.org endorsement or affiliation.
>
> **Why I'm asking to keep it:** MCPWP is an established project with a dedicated domain (mcpwp.net), a public GitHub repository, and an existing user base that predates this WordPress.org submission. The name is how users already know it.
>
> **I'm flexible:** if the slug must be "wp"-free, I'm glad to use a compliant permalink (for example `mcp-connect`, `model-context-protocol`, or `mcp-server-connector`) while keeping the public display name as "MCPWP – Model Context Protocol for WordPress" if that's acceptable. Please let me know what works on your side and I'll adjust the headers accordingly.
>
> Thank you for your time and for maintaining the directory.
>
> Best regards,
> Hadi Servat
> Mumega Inc. — mcpwp.net

---

## If the reviewer requires a slug change (have these ready)
Preferred compliant slug candidates, in order:
1. `mcp-connect`
2. `mcp-for-sites`
3. `model-context-protocol`
4. `mcp-server-connector`

A slug-only change (keeping the MCPWP display name + the `mcpwp_` code prefix + the `mcpwp/v1` REST namespace) is **far** less invasive than a full rename — only the WordPress.org listing slug + the plugin folder name in the wporg build need to differ; the internal code can stay `mcpwp_`. Flag me and I'll prep whichever the reviewer accepts.

## After this
- PR #522 (WP.org compliance, 0 Plugin Check errors) keeps the `mcpwp` slug and stands as-is for the exception path.
- If the exception is granted → proceed to submit. If the slug must change → I run the minimal slug-only adjustment (not the full rename).
