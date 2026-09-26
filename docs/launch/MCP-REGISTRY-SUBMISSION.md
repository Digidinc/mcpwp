# Official MCP Registry Submission (mcp-publisher)

> STATUS: DRAFT — `registry-server.json` is prepared; **nothing published.** Submitting puts MCPWP in the Official MCP Registry, which downstream catalogs (PulseMCP, Glama, etc.) auto-ingest → passive discovery funnel.

## The nuance: MCPWP is a per-install server
Unlike a centrally-hosted MCP server, MCPWP turns *each WordPress site* into its own MCP endpoint (`https://<site>/wp-json/mcpwp/v1/mcp`). The registry listing therefore advertises the **pattern + the plugin**, not one shared URL. The `remotes[].url` uses a `YOUR-WORDPRESS-SITE.com` placeholder and documents the per-site `X-API-Key`. This is the honest representation; reviewers should understand the "server" is the plugin a user installs on their own site.

## Prereqs before publishing
- [ ] Repo is public + the `mcpwp.net` website live (both true / near).
- [ ] `name` namespace: `io.github.mumega-com/mcpwp` requires proving ownership of the GitHub org — `mcp-publisher` authenticates via GitHub OAuth against `Mumega-com`. Hadi (org owner) runs the auth.
- [ ] Validate `registry-server.json` against the current schema (the `$schema` URL pins the version; re-check it's current at submit time — the MCP registry schema evolves).
- [x] Decided (#931, 2026-09-26): MCPWP is not going on WordPress.org — #495 is not planned, so there is no MCPWP wp.org slug to reference. The listing's install path is the direct download (`https://updates.mcpwp.net/mcpwp-latest.zip`), not a `packages` entry pointing at wp.org.

## Publish steps (when gated)
```bash
# 1. Install the CLI
#    (Go: go install github.com/modelcontextprotocol/registry/cmd/mcp-publisher@latest
#     or the released binary)

# 2. Authenticate as the GitHub org owner (Hadi)
mcp-publisher login github     # OAuth → proves io.github.mumega-com namespace

# 3. From the repo root (server.json must be at root or pass --file)
cp docs/launch/registry-server.json ./server.json
mcp-publisher publish

# 4. Verify the listing appears, then remove the root server.json or keep it (convention is to keep server.json at repo root).
```

## After publishing
- PulseMCP / Glama auto-ingest within their refresh windows — no separate submit needed for those.
- Update the listing on each version bump (re-run `mcp-publisher publish` with the new `version`).

## Needs Hadi
- GitHub org-owner OAuth for `mcp-publisher login github`.
- Go decision (publishing is public; coordinate with PH launch timing so discovery channels light up together — WP.org is not part of this coordination, see #931).
