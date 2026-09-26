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
`io.github.mumega-com` that `docs/launch/registry-server.json` targets. It points at the npm
package `@mcpwp.net/mcpwp` — the stdio proxy, confirmed live on npm at v3.0.1 (published
2026-05-30) — **not** the plugin's own remote HTTP endpoint. Two things make this entry worth
fixing rather than leaving alone:

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

- **If yes** → `mcp-publisher login github` as `mumega848` and republish an updated
  `io.github.mumega848/mcpwp` (bump to 3.14.0, swap the npm `packages` entry for the `remotes`
  entry in `registry-server.json`, keep the existing name). **This is the recommended path** —
  it updates the one listing in place, at the same name, with zero duplicate risk.
- **If no** (lost access, or it was a placeholder/contractor account) → publish
  `io.github.mumega-com/mcpwp` as prepared, under the org. This is a **new, second name** in the
  registry. The old `io.github.mumega848/mcpwp` entry stays live, stale, and pointing at a
  vulnerable npm package indefinitely — aggregators that auto-ingest by name (PulseMCP, Glama)
  will show **two MCPWP listings** with different data, and nothing in the current registry
  tooling can merge or remove the old one. Flag this explicitly to anyone doing the north-star
  measurement (`docs/growth/NORTH-STAR.md`) so a stale duplicate doesn't get mis-scored as a
  second citation.

Either way, do not publish under the org name *in addition to* keeping the personal one current
— pick one.

## What's already prepared

`docs/launch/registry-server.json`, updated in this PR:
- `version`: `3.0.1` → `3.14.0` (matches `mcpwp/VERSION` and `readme.txt`)
- `$schema`: unchanged — `2025-07-09` is still the schema the live registry serves as of this
  check, so no migration needed
- `name`, `description`, `repository`, `remotes` (the `YOUR-WORDPRESS-SITE.com` per-site
  placeholder with the documented `X-API-Key` header): unchanged, still accurate to the
  per-install architecture

## Prereqs (per the existing MCP-REGISTRY-SUBMISSION.md, still true)
- [ ] Duplicate-listing decision above, resolved
- [ ] Repo visibility: **currently private** (confirmed 2026-09-26). The registry's
  `source: "github"` validation authenticates ownership via OAuth, which can succeed against a
  private repo — but a `repository.url` link that visitors can't open is a dead end for anyone
  who clicks through from PulseMCP/Glama. Treat "repo public" as a soft prereq for this listing
  to be *useful*, even if not a hard technical one.
- [ ] `mcp-publisher` CLI installed, GitHub OAuth run by whichever account is chosen above

## Publish steps (unchanged from MCP-REGISTRY-SUBMISSION.md)
```bash
mcp-publisher login github     # as mumega848 (update in place) or as the Mumega-com org owner (new name)
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
