# Directory submission packages

Part of #904, part of #960. Each file in this folder is a ready-to-paste submission package
for one directory — form fields with answer text, required assets, and a status line. **Docs
only: nothing here has been submitted, no accounts were created, nothing was published to npm
or the MCP registry, and no API key has been minted.** Final submissions need the owner's own
accounts (Claude, Meta, GitHub OAuth, Smithery, etc.) and, for the demo-site packages, a
reviewer API key the owner mints on `demo.mcpwp.net`.

Canonical claims used throughout (checked against `docs/launch/PH-LAUNCH-KIT.md`,
`mcpwp/readme.txt`, and live URLs on 2026-09-26): 180+ tools free / 290+ with Pro; approval
gate is opt-in; publishing needs an admin-scoped key as of 3.13; verified AI clients are Claude
Desktop, Claude Code, and Cursor; no EU AI Act or competitor-numbers claims are made anywhere in
this set; the `Mumega-com/mcpwp` repo is private, so no GitHub links appear in any listing copy
except where a package is explicitly blocked on that changing. Support contact is
`support@mumega.com`, published at `https://mcpwp.net/contact/`; the demo/test site is
`https://demo.mcpwp.net`, confirmed live on 2026-09-26 (`/wp-json/` returns the `mcpwp/v1`
namespace).

## The one recurring step: mint a reviewer key on the demo site

Three of the eight packages (Claude directory, Muse, Smithery) need a working API key against
`https://demo.mcpwp.net/wp-json/mcpwp/v1/mcp`, because each reviewer/scanner needs to actually
call tools against something real. **Mint one scoped (write, not admin) key on demo.mcpwp.net
and reuse it across all three** — that's the only manual step left before those submissions can
go. The demo site describes itself as "read-only" in its own tagline; confirm before minting
whether that's a WordPress-level lock (in which case say so plainly in each submission's test
instructions) or just framing for the public-facing demo, since two of the three packages expect
both read and write tools to be callable by reviewers.

## Checklist

| Directory | Method | Owner action | Est. time |
|---|---|---|---|
| [Claude connector directory](claude-connector-directory.md) | Developer portal (claude.ai/directory/manage) | Mint reviewer key on demo.mcpwp.net; export icon PNG; submit | 20–30 min |
| [Meta Muse](meta-muse.md) | Developer portal (muse.ai/platform) | Same reviewer key; provide name; re-export icon at 512×512; submit | 15–20 min |
| [Official MCP registry](mcp-registry.md) | CLI (`mcp-publisher`) | **Decide first**: update the stale `io.github.mumega848/mcpwp` in place, or accept a permanent duplicate under `io.github.mumega-com/mcpwp` — see the file for why this is close to irreversible; then run GitHub OAuth + `mcp-publisher publish` | 15–20 min (+ decision time) |
| [Smithery](smithery.md) | Web form / CLI (`smithery.ai/new`) | Same reviewer key; publish by URL (no public repo needed) | 15 min |
| [Glama](glama.md) | Auto-crawl of public GitHub repo | **Owner decision: repo visibility** — no form once public | 0 min (after decision) |
| [mcp.so](mcp-so.md) | Web form (`mcp.so/submit`) | **Owner decision: repo visibility**; pick free vs. $39 tier once public | 5 min (after decision) |
| [mcpservers.org](mcpservers-org.md) | Web form (`mcpservers.org/submit`) | Submit — no repo or demo site needed | 10 min |
| [PulseMCP](pulsemcp.md) | None — auto-ingests from the official registry | Nothing beyond the registry decision above; verify listing after it publishes | 0 min |

## Suggested submission order

1. **Claude connector directory and Meta Muse first** — both need the same reviewer key, both
   are the highest-traffic surfaces for the "AI agent recommends MCPWP" north star
   (`docs/growth/NORTH-STAR.md`), and doing them together amortizes the key-minting step.
2. **mcpservers.org** — no blockers, no shared dependency, can happen any time, may as well be
   early.
3. **Official MCP registry** — do this only after the duplicate-listing decision is made;
   PulseMCP follows automatically once it's done.
4. **Smithery** — same reviewer key as step 1, so it's nearly free to do right after.
5. **Glama and mcp.so** — whenever the repo-visibility decision lands; both are then near-zero
   effort.

## Remaining owner decisions (not resolved by this pass)
- **MCP registry duplicate-listing question** — `mcp-registry.md` lays out the two branches
  (update `io.github.mumega848/mcpwp` in place vs. publish a second name under
  `io.github.mumega-com/mcpwp`) and why the registry has no self-service way to undo the wrong
  choice.
- **Repo visibility** — still private as of 2026-09-26; blocks Glama and mcp.so outright, and
  softly affects the MCP registry listing's usefulness (the repo link is dead to visitors even
  if OAuth ownership still verifies). Tracked separately in `docs/launch/PH-LAUNCH-KIT.md`'s
  pre-launch checklist.
- **Icon assets** — `docs/launch/gallery/src/icon.svg` (256×256) exists but needs raster export
  at whatever size each portal asks for (Muse specifically wants 512×512).
