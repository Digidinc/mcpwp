# Glama — submission package

Issue: #960. Submission method: point Glama at a **public GitHub repo**; it crawls and indexes
automatically. No form to fill in.

Sources checked 2026-09-26: [Glama MCP FAQ](https://glama.ai/mcp/faq),
[Glama MCP server registry](https://glama.ai/mcp/servers).

## Status: BLOCKED — the repo is private

Glama's stated model is "submit a GitHub repo and Glama indexes every tool, schema, and
annotation" — search, categories, and recommendations follow automatically, "no paywall, no
gatekeeping." That indexing is a crawl of the public repo (README, code, and — per the FAQ —
the live server via its in-browser inspector). `Mumega-com/mcpwp` is currently **private**
(confirmed 2026-09-26). There is nothing to submit here until that changes.

There is a second option, unrelated to the visibility blocker: Glama can also **host** a
server directly and front it with their Gateway (managed credentials, call logs, analytics).
That's a bigger commitment than a listing and isn't in scope for this package — flagging it
only so the owner knows it exists as a later option.

## What's ready once the repo is public

| Item | Answer |
|---|---|
| Repo to submit | `https://github.com/Mumega-com/mcpwp` |
| What Glama will index | README (readme.txt content should be mirrored or linked in a root README for best indexing — verify one exists at repo root), the 3.14.0 tool registries (title + all four annotation hints already present, from #934), and the live server via its inspector if a demo URL is also supplied |
| In-browser inspector target | `https://<demo-site>/wp-json/mcpwp/v1/mcp` with the demo API key — same demo site as the other packages, so Glama's visitors can exercise tools "no clone, no build, no local environment" |

## Owner actions
1. Decide on repo visibility (this is the actual blocker — same open item as
   `docs/launch/PH-LAUNCH-KIT.md`'s "Make the `Mumega-com/mcpwp` repo public?").
2. Once public: no form to submit — Glama's crawler picks it up. Optionally supply the demo
   site's MCP URL somewhere discoverable (repo README, mcpwp.net) so Glama's inspector has
   something live to point at.
3. Confirm a root-level `README.md` exists and accurately describes MCPWP (Glama indexes from
   it) — the repo root's actual README wasn't reviewed as part of this docs-only pass.

**Est. owner time: 0 min of form-filling** once the repo is public — this is the one directory
in this package with no submission form at all. All the time cost is in the repo-visibility
decision, which is already tracked elsewhere.
