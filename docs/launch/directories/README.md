# Directory submission packages

Part of #904, part of #960. Each file in this folder is a ready-to-paste submission package
for one directory — form fields with answer text, required assets, and a status line. **Docs
only: nothing here has been submitted, no accounts were created, nothing was published to npm
or the MCP registry.** Final submissions need the owner's own accounts (Claude, Meta, GitHub
OAuth, Smithery, etc.) and, for most of them, a demo WordPress site the owner provisions.

Canonical claims used throughout (checked against `docs/launch/PH-LAUNCH-KIT.md`,
`mcpwp/readme.txt`, and live URLs on 2026-09-26): 180+ tools free / 290+ with Pro; approval
gate is opt-in; publishing needs an admin-scoped key as of 3.13; verified AI clients are Claude
Desktop, Claude Code, and Cursor; no EU AI Act or competitor-numbers claims are made anywhere in
this set; the `Mumega-com/mcpwp` repo is private, so no GitHub links appear in any listing copy
except where a package is explicitly blocked on that changing.

## The one recurring blocker: a demo site

Four of the seven packages (Claude directory, Muse, Smithery, and — indirectly — Glama's
inspector) need a live WordPress install with MCPWP active and a working test API key, because
each reviewer/scanner needs to actually call tools against something real. **Standing up one
demo site covers all of them.** Do this once, then reuse the same URL and key across the
Claude, Muse, and Smithery submissions.

## Checklist

| Directory | Method | Owner action | Est. time |
|---|---|---|---|
| [Claude connector directory](claude-connector-directory.md) | Developer portal (claude.ai/directory/manage) | Provision demo site + key; provide support contact; export icon PNG; submit | 45–90 min |
| [Meta Muse](meta-muse.md) | Developer portal (muse.ai/platform) | Same demo site; provide name/email; re-export icon at 512×512; submit | 20–30 min |
| [Official MCP registry](mcp-registry.md) | CLI (`mcp-publisher`) | **Decide first**: update the stale `io.github.mumega848/mcpwp` in place, or accept a permanent duplicate under `io.github.mumega-com/mcpwp` — see the file for why this is close to irreversible; then run GitHub OAuth + `mcp-publisher publish` | 15–20 min (+ decision time) |
| [Smithery](smithery.md) | Web form / CLI (`smithery.ai/new`) | Same demo site; publish by URL (no public repo needed) | 15 min |
| [Glama](glama.md) | Auto-crawl of public GitHub repo | **Blocked on repo visibility** — no form once public | 0 min (after repo decision) |
| [mcp.so](mcp-so.md) | Web form (`mcp.so/submit`) | **Blocked on repo visibility**; pick free vs. $39 tier once public | 5 min (after repo decision) |
| [mcpservers.org](mcpservers-org.md) | Web form (`mcpservers.org/submit`) | Provide contact email; submit — no repo or demo site needed | 10 min |
| [PulseMCP](pulsemcp.md) | None — auto-ingests from the official registry | Nothing beyond the registry decision above; verify listing after it publishes | 0 min |

## Suggested submission order

1. **Claude connector directory and Meta Muse first** — both need the same demo site, both are
   the highest-traffic surfaces for the "AI agent recommends MCPWP" north star
   (`docs/growth/NORTH-STAR.md`), and doing them together amortizes the demo-site setup cost.
2. **mcpservers.org** — no blockers, no shared dependency, can happen any time, may as well be
   early.
3. **Official MCP registry** — do this only after the duplicate-listing decision is made;
   PulseMCP follows automatically once it's done.
4. **Smithery** — same demo site as step 1, so it's nearly free to do right after.
5. **Glama and mcp.so** — whenever the repo-visibility decision lands; both are then near-zero
   effort.

## Known gaps across multiple packages
- **No published support contact** (email or form) was found anywhere in the repo or on
  mcpwp.net during this research pass. Three packages (Claude directory, Muse, mcpservers.org)
  need one before submitting.
- **No demo/test WordPress site exists yet.** Every package that needs one says so explicitly.
- **Repo is private.** Blocks Glama and mcp.so outright; softly affects the MCP registry
  listing's usefulness (the repo link is dead to visitors even if OAuth ownership still
  verifies); tracked separately in `docs/launch/PH-LAUNCH-KIT.md`'s pre-launch checklist.
