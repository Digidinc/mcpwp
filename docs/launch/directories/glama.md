# Glama — submission package

Issue: #960. Submission method: point Glama at the public GitHub repository
`https://github.com/Digidinc/mcpwp`; Glama crawls and indexes automatically, and root `glama.json`
declares maintainer GitHub accounts (`hrahimi270`, `mumega848`) for organization ownership claims.

Sources checked: [Glama MCP FAQ](https://glama.ai/mcp/faq),
[Glama MCP server registry](https://glama.ai/mcp/servers),
[Glama `glama.json` schema](https://glama.ai/mcp/schemas/server.json).

## Status: READY — `Digidinc/mcpwp` is public and `glama.json` is at the repository root

`https://github.com/Digidinc/mcpwp` is public under `GPL-2.0`, root `README.md` is live, and root
`glama.json` is committed against `https://glama.ai/mcp/schemas/server.json` with maintainer logins
so Glama's organization ownership claim succeeds cleanly.

## Listing details

| Item | Answer |
|---|---|
| Repo to submit | `https://github.com/Digidinc/mcpwp` |
| Ownership manifest | `/glama.json` (`$schema: https://glama.ai/mcp/schemas/server.json`, `maintainers: ["hrahimi270", "mumega848"]`) |
| What Glama indexes | Root `README.md`, `mcp-server/` (`@mcpwp.net/mcpwp`), the PHP MCP tool registries (title + all four annotation hints from #934), and the live server via its inspector if a demo URL is supplied |
| In-browser inspector target | `https://demo.mcpwp.net/wp-json/mcpwp/v1/mcp` with the scoped reviewer key minted on `demo.mcpwp.net` |

## Owner actions
1. Visit `https://glama.ai/mcp/servers`, add `https://github.com/Digidinc/mcpwp` (or open the auto-crawled listing), and run **Claim ownership** signed into GitHub as `hrahimi270` or `mumega848`.
2. Optionally supply `https://demo.mcpwp.net/wp-json/mcpwp/v1/mcp` for Glama's live inspector.

**Est. owner time: 2 min.**
