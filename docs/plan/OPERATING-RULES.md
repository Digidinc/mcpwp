# Operating rules

How the operating agent works on MCPWP. Each rule is here because breaking it cost real time.

## 1. You coordinate; subagents build

The operating agent picks work, writes briefs, reviews results, merges, releases and publishes.
Builders and reviewers are subagents with fresh context.

- **Builder:** one issue, one worktree, one PR. Use the Sonnet model for bounded work (one issue,
  clear acceptance, mechanical checks). Use Opus when the brief needs design judgment, or the
  previous round needed a redesign.
- **Adversarial reviewer:** a separate subagent that tries to break the PR. Required for anything
  touching auth, API keys, permissions, approvals, write paths, uploads, outbound HTTP, or a new
  public endpoint. Run it **in parallel** with your own correctness check, not after it.
- **Never merge a sensitive PR on one clean review from the builder itself.** The builder's
  self-review has missed P0s every time.
- Queue order is **priority for picking**, not strict serialization. Run up to about 4 builders
  at once, as long as their items don't edit the same files (for example, two items both
  touching `trait-mcpwp-api-auth.php` or `class-mcpwp-approvals.php` go one after the other).
  They share one machine, and more load shows up as flaky timing tests.

## 2. The builder brief (copy this, fill in the brackets)

Save it to a file and point the subagent at the file. Don't paste long history into the prompt.

```markdown
# Brief: #[N] [issue title]

Read GitHub issue #[N] fully first: `env -u GITHUB_TOKEN gh issue view [N] --repo Mumega-com/mcpwp --comments`.
If that fails with a "Projects (classic)" GraphQL error, use
`env -u GITHUB_TOKEN gh api repos/Mumega-com/mcpwp/issues/[N]` and `.../issues/[N]/comments`.

## Pins (literal, non-negotiable)
- Repo root: /mnt/HC_Volume_104325311/projects/sitepilotai/wp-ai-operator (git repo; GitHub
  Mumega-com/mcpwp). The plugin is its mcpwp/ subdirectory. mcpwp/ alone is NOT a repo.
- Base: `cd /mnt/HC_Volume_104325311/projects/sitepilotai/wp-ai-operator && git fetch origin &&
  git worktree add ../wt-[N] -b [fix|feat]/[N]-[slug] origin/main`. Work ONLY in that worktree.
- gh: always `env -u GITHUB_TOKEN gh ...`.
- Shared state: NEVER touch wp-rig-* containers, test-rig/, or the shared checkout's working tree.
- Rig: your OWN throwaway WordPress + MariaDB containers with unique names ([N]-wp / [N]-db) on a
  unique host port 9100–9199, with YOUR worktree's mcpwp/ bind-mounted as the plugin. Remove them
  when done with `docker rm -f -v <exact name>`. NEVER run any docker prune command.
  For Pro surfaces: create mcpwp/includes/agency-license.php containing
  `<?php return 'MCPWP_AGENCY_LICENSE_V1';` in YOUR worktree only. Never commit it.
- Unit tests (full suite, must be 0 failures):
  `docker run --rm -v <worktree>/mcpwp:/app -v /tmp/wp-ai-operator-clone/mcpwp/vendor:/app/vendor:ro -w /app php:8.3-cli php -d memory_limit=512M vendor/bin/phpunit -c tests/phpunit.xml`
  If /tmp/wp-ai-operator-clone/mcpwp/vendor is missing (it lives in /tmp and a reboot clears it),
  install one in your worktree instead:
  `docker run --rm -v <worktree>/mcpwp:/app -w /app composer:2 install --no-interaction --ignore-platform-reqs`
  and mount `<worktree>/mcpwp/vendor`. Never commit vendor/.
  Add tests. Show that a new test FAILS on the unpatched code before your fix.
- Don't bump versions or edit CHANGELOG.md, readme.txt, version.json or mcpwp/VERSION.
  release-please owns them.
- Commit and PR title: conventional (`fix(scope): …` / `feat(scope): …`). The PR body includes
  `Closes #[N]`. Open the PR against main. DO NOT MERGE.
- Fix at the chokepoint (the one shared function every path goes through), not per endpoint.
  Match the surrounding code style.
- If the issue text is wrong against current code, say so with evidence and fix what's true.

## The task
[What to build, the design decisions already made, and anything the issue leaves open that you
have decided. Be concrete: function names, files, the response shape.]

## Proof required
[Specific real-HTTP requests on the rig that must succeed or be refused, with expected status
codes.]

## Reply (short)
PR URL, head SHA, full-suite line (tests / assertions / failures), rig evidence (abbreviated
requests and responses), anything deliberately left out and why, and a RECEIPT of everything
changed outside the worktree (containers created and removed, files) so it can be reverted.
```

## 3. The adversarial reviewer brief

```markdown
# Adversarial review: PR #[P] (#[N] [title])

You are trying to BREAK this change. Don't fix anything; report.
Check out the PR head in your own detached worktree:
`git -C /mnt/HC_Volume_104325311/projects/sitepilotai/wp-ai-operator worktree add --detach ../rv-[P] <head-sha>`.
Same shared-state pins as builders: own containers, remove them by exact name, never prune, and
never touch wp-rig-*.

Attack surface to cover, at minimum:
- Every door to the same data: REST route, /mcp tools/call, mcpwp_call wrapper, /batch,
  WebMCP /webmcp/call, OAuth/broker principal. A fix on one door and not the others is a BLOCK.
- Restricted keys: custom categories, read scope, empty or unknown role.
- Existence oracles: does a hidden object answer differently from a missing one (status, body,
  hint, timing of a whole different code path)?
- Failure versus absence: does an error ever produce the same output as "nothing found"?
- Concurrency and partial failure on write paths.
[Add anything specific to this change.]

Prove each finding on a real rig with real HTTP where possible.
Reply: a list of findings, each BLOCK / WARN / NIT, with a reproduction, plus "CLEAN" if you
found nothing that should block the merge.
```

Send **all** findings from a round to **one** fixer, then run a **new** reviewer (fresh context)
on the new head. Repeat until a round is CLEAN.

## 4. Durability over churn

- If two review rounds in a row find new holes of the same kind (another door, another param,
  another code path), **stop patching.** Redesign so there is one chokepoint, an allowlist or a
  declared map, and a test that enumerates every route. Example: #1024 took four rounds of
  patches, then one redesign (declared post-ref params plus one canonical not-found error plus a
  parity test over every route), and the redesign held.
- A caught error is reported, never swallowed. "Couldn't check" and "checked, nothing there"
  must never produce the same output.
- Test doubles must behave like real WordPress. #1023 (products permanently deleted while
  reporting "trash") hid behind a test stub that aliased trash to delete.

## 5. Before you merge

1. Fresh detached worktree at the PR head, then `git merge origin/main`.
2. Full phpunit suite green on that merge. Timing tests (`KsesRawTextStripTest`,
   `ContentGuardrailsTest`) can flake under host load. Re-run them alone; if they also fail on
   main, it isn't the PR.
3. Adversarial review CLEAN, if it's a sensitive surface.
4. PR title is conventional (release-please drops non-conventional titles from the changelog).
5. `env -u GITHUB_TOKEN gh pr merge <P> --squash --admin`. (The repo disallows merge commits, and
   CI can't run while billing is down, so `--admin` is expected.)
6. If the PR body lacked `Closes #N`, close the issue by hand with a comment linking the PR.

## 6. Releasing

Follow [../RELEASE_CHECKLIST.md](../RELEASE_CHECKLIST.md) exactly. The short version:
release-please (local), add the "Changed for existing API keys" section when permissions change,
**`scripts/release_smoke.sh` before merging the release PR**, merge, tag, sync the wp.org
changelog, build, attach the ZIPs, publish, download the live ZIP and grep for the fix, dispatch
the Freemius publish, and update the mcpwp.net changelog page.

## 7. Clean up after every item

- `git worktree remove ../wt-[N]` once its PR is merged (check `git status` is clean first).
- `docker ps -a` should show no containers you created. Remove yours by exact name only.
- Never commit `mcpwp/includes/agency-license.php`.
- Scratch files go in your session scratchpad, not in the repo.

## 8. Keep the record straight

- After each release, update: the PLAN.md status column, the milestone descriptions (owner list
  in milestone 17), the mcpwp.net changelog page, and `docs/ROADMAP.md` "Shipped".
- Say plainly what's verified and what isn't. "Tagged" isn't "shipped"; "tests pass" isn't
  "works on the rig".
- Never publish, message or post anything outward-facing that the plan doesn't cover without
  asking the owner. Releases to the update channels ARE covered: that's the standing approval.
