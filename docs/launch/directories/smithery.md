# Smithery — submission package

Issue: #960. Submit at `smithery.ai/new`, or via CLI: `smithery mcp publish <url> -n <org/server>`.

Sources checked 2026-09-26: [Smithery docs](https://smithery.ai/docs),
[Smithery publish docs](https://smithery.ai/docs/build/publish).

## Status: READY — owner mints reviewer key and submits

Good news relative to the other directories: Smithery does **not** require a public GitHub
repo. It supports publishing a remote HTTP server by URL directly — "bring your own hosting,"
where the Smithery Gateway proxies to the upstream server — and reads a static server card at
`/.well-known/mcp/server-card.json` rather than scanning source. That fits MCPWP's per-install
model better than a GitHub-crawl-based directory would.

`https://demo.mcpwp.net` is confirmed live (2026-09-26): its `/wp-json/` index carries the
`mcpwp/v1` namespace, so there's a real, working URL for Smithery's publish-time scan (it
enumerates tools by calling the live server, so it needs a working key, not just a reachable
host).

## Form field / CLI → answer

| Field | Answer |
|---|---|
| Server URL | `https://demo.mcpwp.net/wp-json/mcpwp/v1/mcp` |
| Publish name | `@mumega/mcpwp` or `mumega/mcpwp` (org-style identifier; confirm the exact slash convention in the live `smithery.ai/new` flow) |
| Auth for the scan | The reviewer key the owner mints on `demo.mcpwp.net` (see Owner actions — instructions only, nothing minted as part of this docs pass). Check whether the publish flow has a field for a custom header (`X-API-Key`) or expects an unauthenticated probe first — unclear from the docs pulled, verify live. |
| Server card (optional, `/.well-known/mcp/server-card.json`) | Not present on the plugin today. Not required to publish (Smithery's auto-scan covers the baseline), but adding one later would let Smithery's listing carry richer metadata (name, description, icon) without depending entirely on the live scan. Not a blocker for this submission. |

## Required assets
None documented as mandatory beyond the working URL. If the live flow requests an icon, reuse
`docs/launch/gallery/src/icon.svg`.

## Owner actions
1. Mint a scoped (write, not admin) reviewer API key on `demo.mcpwp.net` — reuse the same key
   minted for the Claude directory/Muse packages if convenient.
2. Go to `smithery.ai/new`, paste `https://demo.mcpwp.net/wp-json/mcpwp/v1/mcp`, supply the key
   where the form asks for auth, and complete the flow (or run
   `smithery mcp publish "https://demo.mcpwp.net/wp-json/mcpwp/v1/mcp" -n mumega/mcpwp` via the
   CLI).
3. Confirm how the publish flow authenticates its scan against a key-gated endpoint — the docs
   pulled for this package didn't show a header/credential field, so this needs a live look at
   the form.
4. Optional, later: pursue Smithery's vendor verification under Settings → Verification once
   published.

**Est. owner time: 15 min** — the demo site already exists.
