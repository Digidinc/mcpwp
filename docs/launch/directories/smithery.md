# Smithery — submission package

Issue: #960. Submit at `smithery.ai/new`, or via CLI: `smithery mcp publish <url> -n <org/server>`.

Sources checked 2026-09-26: [Smithery docs](https://smithery.ai/docs),
[Smithery publish docs](https://smithery.ai/docs/build/publish).

## Status: BLOCKED — needs a real, reachable demo endpoint (repo can stay private)

Good news relative to the other directories: Smithery does **not** require a public GitHub
repo. It supports publishing a remote HTTP server by URL directly — "bring your own hosting,"
where the Smithery Gateway proxies to the upstream server — and reads a static server card at
`/.well-known/mcp/server-card.json` rather than scanning source. That fits MCPWP's per-install
model better than a GitHub-crawl-based directory would.

The blocker is the same as Claude/Muse: Smithery's publish flow scans the live server (to build
its tool listing and let visitors call tools in-browser), so it needs one specific, working
`https://<site>/wp-json/mcpwp/v1/mcp` URL with a working key — the per-site placeholder pattern
used in `registry-server.json` won't scan.

## Form field / CLI → answer

| Field | Answer |
|---|---|
| Server URL | `https://<demo-site>/wp-json/mcpwp/v1/mcp` — needs the owner's demo site (same one provisioned for Claude/Muse) |
| Publish name | `@mumega/mcpwp` or `mumega/mcpwp` (org-style identifier; confirm the exact slash convention in the live `smithery.ai/new` flow) |
| Auth for the scan | The demo site's `X-API-Key` — Smithery's scan will need this to enumerate tools; check whether the publish flow has a field for a bearer/custom header or expects an unauthenticated probe first (unclear from docs pulled) |
| Server card (optional, `/.well-known/mcp/server-card.json`) | Not present on the plugin today. Not required to publish (Smithery's auto-scan covers the baseline), but adding one later would let Smithery's listing carry richer metadata (name, description, icon) without depending entirely on the live scan. Not a blocker for this submission. |

## Required assets
None documented as mandatory beyond the working URL. If the live flow requests an icon, reuse
`docs/launch/gallery/src/icon.svg`.

## Owner actions
1. Same demo site as Claude/Muse.
2. Go to `smithery.ai/new`, paste the demo site's MCP URL, and complete the flow (or run
   `smithery mcp publish "https://<demo-site>/wp-json/mcpwp/v1/mcp" -n mumega/mcpwp` via the CLI).
3. Confirm how the publish flow authenticates its scan against a key-gated endpoint — the docs
   pulled for this package didn't show a header/credential field, so this needs a live look at
   the form.
4. Optional, later: pursue Smithery's vendor verification under Settings → Verification once
   published.

**Est. owner time: 15 min**, once the demo site exists.
