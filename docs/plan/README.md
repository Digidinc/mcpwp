# MCPWP operator handbook: start here

Written 2026-09-29, the day after 3.14.8 shipped. It's for the agent that runs MCPWP day to day
(Claude Sonnet or Opus, working as coordinator). Read the whole file before doing anything. It
takes about ten minutes.

## What MCPWP is

MCPWP is a WordPress plugin that turns a site into an MCP server. An AI agent (Claude, ChatGPT,
Cursor, the browser's own agent) can then read and change the site: pages, Elementor, SEO,
WooCommerce, media and menus. Risky changes wait for the owner's approval.

- Repo: `Mumega-com/mcpwp`. Local clone: `/mnt/HC_Volume_104325311/projects/sitepilotai/wp-ai-operator`.
  The plugin is the `mcpwp/` subdirectory; the repo root is `wp-ai-operator/`.
- Public site: https://mcpwp.net (itself a WordPress site running MCPWP).
- Current version: see `curl -s https://updates.mcpwp.net/version.json | jq -r .version`.
- Seller: **Digid** (Canadian company). Owner: Hadi.

## Your job

You run the project continuously. The owner doesn't publish, release or merge. You do all of
that. The owner decides product direction, pays bills, holds accounts, and has a video team
that takes orders through Linear.

Every work cycle:

1. Read [PLAN.md](PLAN.md) and take the top unblocked item from the work queue.
2. Build it by dispatching a builder subagent. [OPERATING-RULES.md](OPERATING-RULES.md) has the
   brief template.
3. If it touches auth, write paths, approvals or external APIs, run an adversarial review in
   parallel. Repeat review rounds until one comes back clean.
4. Merge it (`--squash --admin`, conventional title).
5. Release when something user-visible has merged. Follow
   [../RELEASE_CHECKLIST.md](../RELEASE_CHECKLIST.md) exactly, including the smoke test and
   the live-ZIP check.
6. Update the mcpwp.net changelog page, the plan's status column and the milestone descriptions.
7. Clean up after yourself: worktrees, containers, scratch files.

## Where things are

| what | where |
|---|---|
| The plan and work queue | [PLAN.md](PLAN.md) |
| How to work: rules, briefs, reviews, cleanup | [OPERATING-RULES.md](OPERATING-RULES.md) |
| Ready-to-dispatch briefs for hard items | [briefs/](briefs/) |
| Release procedure, step by step | [../RELEASE_CHECKLIST.md](../RELEASE_CHECKLIST.md) |
| Release smoke test (one command) | `scripts/release_smoke.sh` |
| Readable roadmap | [../ROADMAP.md](../ROADMAP.md) |
| Task truth | GitHub issues and milestones on `Mumega-com/mcpwp`, org project board #2 |
| Video and media work | Linear (the owner's video team), see PLAN.md §Media |
| Plugin internals, endpoints, traps | `CLAUDE.md` at the repo root |
| Release history | `mcpwp/CHANGELOG.md` (release-please writes it; never edit it by hand) |

When a document and a GitHub milestone disagree, the milestone wins. Fix the document.

## Five things that have cost real time

1. **`gh` needs `env -u GITHUB_TOKEN`.** A stale token in the environment shadows the good
   login, and every call fails with 401. Always run `env -u GITHUB_TOKEN gh ...`.
2. **GitHub Actions can't run right now** because account billing failed. CI, release-please
   and the publish jobs never start. Run everything locally: the release checklist has the
   local path. Don't wait on CI; run the phpunit suite yourself.
3. **Never touch the shared rig** (`wp-rig-*` containers, `test-rig/`, the shared checkout's
   working tree). Other agents use them. Stand up your own throwaway containers, and remove
   them by exact name with `docker rm -f -v <name>`. **Never run any `docker ... prune`.**
4. **Every review round on auth code finds a new door** until the design becomes one shared
   check point. If two rounds in a row find new holes, stop patching and redesign (see
   OPERATING-RULES.md §Durability).
5. **Tagging isn't shipping.** A release is done only when the live ZIP on
   `updates.mcpwp.net` is downloaded and shown to contain the fix. Freemius is a separate fleet:
   publishing one doesn't reach the other.
