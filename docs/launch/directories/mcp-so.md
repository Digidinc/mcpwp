# mcp.so — submission package

Issue: #960. Submit at `mcp.so/submit?type=server`.

Sources checked 2026-09-26: live fetch of `https://mcp.so/submit?type=server`.

## Status: BLOCKED — the form requires a Repository URL, and the repo is private

The live form's only two required fields are **Repository URL** (marked required) and **Name**.
A submission type selector also lets you pick MCP Server / Remote Server / MCP Client / AI
Agent. "Repository URL" reads as GitHub-specific in context with the rest of the mcp.so
ecosystem (its listings are built from repo metadata) — with `Mumega-com/mcpwp` private, there
is nothing valid to put in that field today.

Two paid tiers exist ($0 free review vs. $39 one-time for immediate publish + verified badge +
featured placement + dofollow link) — a pricing decision for the owner, not something to
prepay on their behalf in this pass.

## Form field → answer (once the repo is public)

| Field | Answer |
|---|---|
| Submission type | MCP Server (or "Remote Server" — MCPWP is technically both: a plugin you install, which then serves a remote-HTTP endpoint per site; pick whichever the live form's copy fits better once you're looking at it) |
| Repository URL | `https://github.com/Mumega-com/mcpwp` |
| Name | `MCPWP` |
| Review tier | Owner's call — free (unspecified queue time) vs. $39 (immediate + verified badge + featured + dofollow) |

## Owner actions
1. Resolve repo visibility (blocks this and Glama — same root cause).
2. Once public, submit at `mcp.so/submit?type=server` with the fields above.
3. Decide free vs. paid tier.

**Est. owner time: 5 min** once the repo is public; the $39 tier is a one-click add during
submission if chosen.
