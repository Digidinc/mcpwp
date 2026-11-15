# Official MCP Registry — submission package

Issue: #960. CLI: `mcp-publisher`. Live registry API: `https://registry.modelcontextprotocol.io`.
The prepared payload lives at `docs/launch/registry-server.json` (updated to `3.14.0` in this
same PR — see below). Background doc: `docs/launch/MCP-REGISTRY-SUBMISSION.md` (unchanged,
still accurate).

Sources checked 2026-09-26: live query of `registry.modelcontextprotocol.io` for the existing
`mcpwp` listing; [MCP registry GitHub](https://github.com/modelcontextprotocol/registry);
[schema at the pinned URL](https://static.modelcontextprotocol.io/schemas/2025-07-09/server.schema.json)
(still current — no newer dated schema found); registry issues on deprecation
([#1601](https://github.com/modelcontextprotocol/registry/issues/1601),
[#1662](https://github.com/modelcontextprotocol/registry/issues/1662)).

## Status: BLOCKED — needs an owner decision on the duplicate-listing question, then GitHub OAuth

## The duplicate-listing problem (read before publishing anything)

There is already a **live** entry in the registry:

```json
{
  "name": "io.github.mumega848/mcpwp",
  "version": "3.0.1",
  "description": "MCP for WordPress — connect Claude, Cursor, or Windsurf to any WordPress site.",
  "repository": { "url": "https://github.com/Mumega-com/mcpwp", "source": "github", "subfolder": "mcp-server" },
  "packages": [{
    "registryType": "npm",
    "identifier": "@mcpwp.net/mcpwp",
    "version": "3.0.1",
    "transport": { "type": "stdio" },
    "environmentVariables": [
      { "name": "WP_URL", "isRequired": true },
      { "name": "WP_API_KEY", "isRequired": true, "isSecret": true }
    ]
  }]
}
```

This is under the **personal** GitHub namespace `io.github.mumega848`, not the org
`io.github.digidinc` that `docs/launch/registry-server.json` targets. It points at the npm
package `@mcpwp.net/mcpwp` — the stdio proxy (source in `mcp-server/`, `mcp-server/server.json`
updated to `3.0.3` and `https://github.com/Digidinc/mcpwp`). Two things make this entry worth
updating rather than leaving stale on `3.0.1`:

1. **It's stale.** 3.0.1 predates the tool-annotation work (#934), the write/admin scope split
   (3.13), and everything else since. Anyone who finds MCPWP through this listing gets an old
   npm stdio bridge, not the current plugin.
2. **The npm package it points to has open security alerts.** #939 (open) tracks unpatched
   `hono`/`fast-uri` vulnerabilities in that exact package (`mcp-server`, published as
   `@mcpwp.net/mcpwp`). Directing registry traffic at it before #939 lands is directing traffic
   at a vulnerable dependency chain.

The prepared `registry-server.json` sidesteps both problems by using a `remotes` entry
(`streamable-http` to the plugin's own `/wp-json/mcpwp/v1/mcp`) instead of a `packages` entry —
no npm proxy in the loop at all, so #939 doesn't block this path.

**The registry has no self-service deprecate or delete.** Per the open registry issues linked
above, there is currently no `mcp-publisher deprecate`/`yank` command, and orphaned entries
(e.g., after a GitHub rename) have sat unresolved for months waiting on registry-admin
intervention. That means the decision below is close to irreversible in practice.

### Decision, pinned for the owner

**Check first: does Hadi still control the GitHub login `mumega848`?**

- **If yes** → first publish `@mcpwp.net/mcpwp` from `mcp-server/` with `npm publish --provenance --access public`, then `mcp-publisher login github` as `mumega848` and run `mcp-publisher publish` inside `mcp-server/` (using `mcp-server/server.json`, which targets `https://github.com/Digidinc/mcpwp` and schema `2025-12-11`). **This is the recommended path** —
  it updates the one listing in place, at the same name, with zero duplicate risk.
- **If no** (lost access, or moving to the org namespace) → publish
  `io.github.digidinc/mcpwp` using `docs/launch/registry-server.json`, authenticated under the `Digidinc` org. This is a **new, second name** in the
  registry. The old `io.github.mumega848/mcpwp` entry stays live and stale indefinitely — aggregators that auto-ingest by name (PulseMCP, Glama)
  will show **two MCPWP listings** with different data, and nothing in the current registry
  tooling can merge or remove the old one. Flag this explicitly to anyone doing the north-star
  measurement (`docs/growth/NORTH-STAR.md`) so a stale duplicate doesn't get mis-scored as a
  second citation.

Either way, do not publish under the org name *in addition to* keeping the personal one current
— pick one.

## What's already prepared

- `mcp-server/server.json` (in-place update for `io.github.mumega848/mcpwp`):
  - `$schema`: `2025-12-11`
  - `version` + `packages[0].version`: `3.0.3` (matches `mcp-server/package.json`)
  - `repository`: `https://github.com/Digidinc/mcpwp` (`subfolder: "mcp-server"`)
  - `websiteUrl`: `https://mcpwp.net`
- `docs/launch/registry-server.json` (org-namespace remote alternative `io.github.digidinc/mcpwp`):
  - `$schema`: `2025-12-11`, description ≤100 chars per schema constraint
  - `repository`: `https://github.com/Digidinc/mcpwp`
  - `remotes`: `streamable-http` `YOUR-WORDPRESS-SITE.com` placeholder with `X-API-Key` header

## Prereqs (per the existing MCP-REGISTRY-SUBMISSION.md)
- [ ] Duplicate-listing decision above, resolved
- [x] Repo visibility: `https://github.com/Digidinc/mcpwp` is public under GPL-2.0.
- [ ] `mcp-publisher` CLI installed, GitHub OAuth run by whichever account is chosen above

## Publish steps
```bash
# Option A (recommended — update existing io.github.mumega848/mcpwp in place):
cd mcp-server && npm publish --provenance --access public
mcp-publisher login github     # as mumega848
mcp-publisher publish

# Option B (new org-owned remote entry io.github.digidinc/mcpwp):
mcp-publisher login github     # as Digidinc org owner
cp docs/launch/registry-server.json ./server.json
mcp-publisher publish
```

## Owner actions
1. Resolve the duplicate-listing decision above.
2. Run the GitHub OAuth login as the chosen account.
3. Decide on repo visibility (affects this listing's usefulness, plus mcp.so and Glama below).
4. Run `mcp-publisher publish` from repo root with the updated `registry-server.json`.
5. No separate PulseMCP/Glama submission needed after this — both auto-ingest from the
   official registry.

**Est. owner time: 15–20 min** once the duplicate decision is made (the decision itself may
take longer if it requires recovering access to an old account).
