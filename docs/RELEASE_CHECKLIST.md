# Release Checklist

Current release flow for MCPWP.

MCPWP ships to **two independent update fleets**. Publishing one reaches none of the other.

| Fleet | Who | Served from |
|-------|-----|-------------|
| Self-hosted | Free and agency installs | `https://updates.mcpwp.net/version.json` (canonical) and the legacy `https://mumega.com/mcp-updates/version.json`, both from R2 bucket `mumcp-updates` |
| Freemius | Paying customers, premium build | Freemius product 23824 |

## How a release happens

Releases are cut by **release-please**. Do not bump versions by hand.

`release-please-config.json` sets `versioning: always-bump-patch`, so **every release is
`3.14.N`** — `feat:`, `fix:`, and breaking changes all bump the patch digit, until the owner
decides to move off the 3.14 line. Checkpoints on that line are tracked as GitHub milestones, not
version numbers: "3.14.1 — Solid ground", "3.14.5 — Agent-native", "3.14.9 — Self-improving +
go-to-market".

1. Merge reviewed, green PRs to `main` with conventional-commit titles (`feat:`, `fix:`).
2. release-please keeps a release PR open that bumps `mcpwp/mcpwp.php`, `MCPWP_VERSION`, `readme.txt`, `version.json` and `mcpwp/CHANGELOG.md` together.
3. If the release changes behaviour a site owner would notice, add an `### Upgrade Notes` section with plain-language bullets under that version in `mcpwp/CHANGELOG.md`. `scripts/sync-wporg-changelog.mjs` carries it to readme.txt's `== Upgrade Notice ==` and to the `version.json` update modal.
4. Wait for `main` CI to be green, then merge the release PR.
5. `release-please.yml` then runs, in order: tag and GitHub release, plugin ZIPs attached, WP.org changelog sync, `publish-freemius`, `publish-canonical-channel`.

A release is not shipped until both publish jobs are green. Each one fails the run if its channel cannot serve the new version.

### Which path to use (read first)

Since the move to `Digidinc/mcpwp` (2026-09-30), **GitHub Actions runs**. One thing is still blocked by the Digidinc enterprise policy: **Actions can't open pull requests**, so the Release PR must be opened locally (step 2 below). After that:

- **Actions working (the normal case now):** do steps 1–4 (check the channel, open/update the Release PR locally, edit the changelog, smoke-test), then merge the Release PR (the first command of step 5 only). **Stop there.** The `Release Please` workflow on the merge commit then tags, creates the GitHub release, builds and attaches the Free and Freemius ZIPs, syncs the wp.org changelog, and publishes BOTH channels (self-hosted R2 and Freemius), each with its own verifier. Watch that run (`env -u GITHUB_TOKEN gh run list -R Digidinc/mcpwp --workflow "Release Please" --limit 1`), check every job is green, then do step 9 (prove the live ZIP) and step 11 (public record). Running steps 5 (github-release) to 10 by hand as well double-publishes; the Freemius upload then fails with `duplicate_plugin_version`, which is harmless but noisy.
- **Actions not working:** follow every step below by hand.

### Local release procedure (use this while GitHub Actions can't run)

Actions billing has blocked every workflow since 3.13.1, so every 3.14.x release was cut this
way. Follow the steps in order. Don't skip the smoke test or the live check. If a script or workflow a step names is missing, that is "could not run", not "passed": stop, find out why (was the branch that adds it merged?), and never skip the step. `X` means the new
version (for example `3.14.9`). Run everything from a **fresh worktree on `origin/main`**, never
from the shared checkout:

```bash
cd /mnt/HC_Volume_104325311/projects/sitepilotai/wp-ai-operator && git fetch origin
git worktree add --detach ../wt-rel origin/main && cd ../wt-rel
T=$(env -u GITHUB_TOKEN gh auth token)
```

**1. Check what the channel serves** (the new version must be higher):
```bash
curl -s https://updates.mcpwp.net/version.json | jq -r .version
```

**2. Open or update the Release PR:**
```bash
npx -y release-please@16 release-pr --token="$T" --repo-url=Digidinc/mcpwp --target-branch=main \
  --config-file=release-please-config.json --manifest-file=.release-please-manifest.json
env -u GITHUB_TOKEN gh pr list --head release-please--branches--main--components--mcpwp
```

**3. Edit the Release PR's changelog if needed** (on branch
`release-please--branches--main--components--mcpwp`, file `mcpwp/CHANGELOG.md`):
- If the release changes what an existing API key may do, add a `### Changed for existing API keys`
  section with plain-language bullets (see 3.14.4 to 3.14.8 for examples).
- If a site owner will notice a behaviour change, add `### Upgrade Notes`.
- If a merged PR's title wasn't conventional, add its line by hand (release-please drops it).

**4. Smoke-test the candidate** from the Release PR's head:
```bash
git fetch origin release-please--branches--main--components--mcpwp
git checkout --detach FETCH_HEAD
bash mcpwp/scripts/build-selfhosted.sh
bash scripts/release_smoke.sh --candidate mcpwp/scripts/mcpwp-selfhosted-X.zip
echo "exit=$?"     # 0 = pass. 1 = a check failed: stop and fix. 2 = could not run: NOT a pass.
```
Add release-specific checks for this release's changes in the script's `RELEASE_CHECKS`
section, or run them by hand with `--keep`. Examples: a refused request for a new permission
gate, or a new tool answering. Never merge the Release PR on a smoke result other than exit 0.

**5. Merge the Release PR and tag:**
```bash
env -u GITHUB_TOKEN gh pr merge <release PR> --squash --admin
git fetch origin && git checkout --detach origin/main
npx -y release-please@16 github-release --token="$T" --repo-url=Digidinc/mcpwp --target-branch=main \
  --config-file=release-please-config.json --manifest-file=.release-please-manifest.json
env -u GITHUB_TOKEN gh release edit vX --title "MCPWP vX"
```

**6. Sync the wp.org changelog, then verify version consistency:**
```bash
git checkout -b sync-X origin/main
node scripts/sync-wporg-changelog.mjs --version X
git add mcpwp/readme.txt version.json            # BOTH files, path-scoped, never -A
git commit -m "chore: sync WP.org changelog for vX"
git push origin HEAD:main
python3 scripts/verify_release_compatibility.py --manifest version.json --plugin mcpwp/mcpwp.php --readme mcpwp/readme.txt
```

**7. Build and attach ZIPs** (free and freemius only, never the agency ZIP, #852):
```bash
bash mcpwp/scripts/build-all.sh
env -u GITHUB_TOKEN gh release upload vX mcpwp/scripts/mcpwp-X.zip mcpwp/scripts/mcpwp-freemius-X.zip
```
(Check the exact output paths that `build-all.sh` prints.)

**8. Publish the self-hosted channel** (fails by itself if the channel can't serve X):
```bash
bash scripts/publish_update_release.sh --build
```

**9. Prove the live ZIP carries the fix.** Tagging and publishing aren't shipping:
```bash
curl -sL https://updates.mcpwp.net/mcpwp-latest.zip -o /tmp/live-X.zip
unzip -p /tmp/live-X.zip mcpwp/mcpwp.php | grep -E "Version:|MCPWP_VERSION"
unzip -l /tmp/live-X.zip | grep -c agency-license.php     # must print 0
unzip -p /tmp/live-X.zip 'mcpwp/includes/*' | grep -c '<a symbol your fix added>'   # must be > 0
```

**10. Publish to Freemius (the paid fleet).** The token is a repo secret. Dispatch the workflow;
never read the secret:
```bash
env -u GITHUB_TOKEN gh workflow run publish-freemius.yml -f version=X
env -u GITHUB_TOKEN gh run list --workflow publish-freemius.yml --limit 1
```
If Actions still can't run (billing), record "Freemius X pending: Actions billing" in milestone
17's owner-blocked list, and tell the owner in your report.

**11. Update the public record:**
- mcpwp.net changelog page (page **117**, HTML widget **`cl_timeline_widget`**). Read the current
  HTML with `wp_get_elementor` (id 117, element_id `cl_timeline_widget`; it's an Elementor html widget, not a sidebar widget, so `wp_get_widget` 404s), add a card for X at the top
  of the timeline, keep the same markup style, and write it back with `wp_edit_widget` using
  `settings.html`. The MCP endpoint and key for mcpwp.net are in
  `/mnt/HC_Volume_104325311/projects/sitepilotai/.mcp.json`. Fetch https://mcpwp.net/changelog/
  afterwards and confirm `>X<` appears.
- `docs/plan/PLAN.md` status column, the milestone descriptions, and "Shipped" in `docs/ROADMAP.md`.
- **Regenerate the docs reference** (docs.mcpwp.net tool, endpoint, error and changelog pages,
  #1065). From the release worktree, with the docs repo (`Mumega-com/mcpwp-docs`, branch
  `master`) checked out in its own worktree next to it:
  ```bash
  git -C ../mcpwp-docs fetch origin && git -C ../mcpwp-docs worktree add ../wt-docs-X -b docs/reference-X origin/master
  bash mcpwp/scripts/build-selfhosted.sh
  bash scripts/gen-docs-reference.sh --candidate mcpwp/scripts/mcpwp-selfhosted-X.zip --out ../wt-docs-X/src/content/docs
  cd ../wt-docs-X && npm ci && npm run build   # also regenerates public/llms.txt, dist/llms-full.txt and the .md copies
  ```
  The script stands up its own throwaway site (containers `docsref-*`, port 9196) and removes it.
  It reads the catalog with `wp_introspect {"full": true}` (`--source introspect`, the default),
  which carries the full descriptions and schemas. Once #1064 trims `tools/list`, do not use
  `--source tools-list`; if #1064 also moves the long descriptions out of `wp_introspect`, use
  `--source find-tools`. Commit the regenerated pages plus `public/llms.txt`, open a PR on
  `Mumega-com/mcpwp-docs`, and ask the owner to deploy it (docs.mcpwp.net is a Cloudflare Pages
  direct upload, `npx wrangler pages deploy dist --project-name mcpwp-docs`; see #864). Then run
  the eval in `docs/plan/docs-eval.md` against the deployed `llms.txt`.

**12. Clean up:** `cd .. && git -C wp-ai-operator worktree remove wt-rel`, and remove any smoke
containers left over from `--keep`.

## Pinning the version

With `versioning: always-bump-patch`, release-please bumps the patch digit regardless of commit
type — there is no automatic minor/major bump to override on this line. `Release-As` is still how
you move off the 3.14 line intentionally: land a commit on `main` whose message has a footer line, on its own line at the start:

```
Release-As: X.Y.Z
```

**Squash merges use the PR description as the commit body by default**, so a `Release-As` mentioned in the PR text is not a footer. Set the body explicitly when merging:

```bash
gh pr merge <N> --squash --body "Release-As: X.Y.Z"
```

Then confirm the release PR's title changed to the pinned version before merging it.

## Pre-merge checks for the release PR

- [ ] Suite green locally (CI too when Actions runs), in declaration and random order
- [ ] `scripts/release_smoke.sh` exit 0 on the Release PR head
- [ ] PHP 7.4 lint clean on production files
- [ ] Behaviour changes have Upgrade Notes
- [ ] Auth, credential and write-path changes in this release passed adversarial review
- [ ] `bash mcpwp/tests/rig/oauth-connect-e2e.sh` exits 0 (OAuth connect flow against a real WordPress, ~2 min, throwaway `e2e-oauth-*` stack). Add `--keep` for a human joint test with a real connector; `--down` removes it

## Post-release verification

```bash
bash scripts/verify_update_channel.sh
FREEMIUS_BEARER_TOKEN=... bash scripts/verify_freemius_channel.sh --expect-version X.Y.Z
```

`verify_freemius_channel.sh` checks the release flag and downloads the premium ZIP (following Freemius's redirect to storage without the bearer header), failing unless it is a valid archive whose `mcpwp-premium/mcpwp.php` carries the expected `Version:` header and `MCPWP_VERSION` constant and which ships `mcpwp-premium/freemius/start.php` (an older expected tag verifies the latest build too). `--strict-storage` exits 2 when storage refused a request before delivering; the contract test is `scripts/tests/verify_freemius_channel_test.sh`. When a customer reports a failed premium update, see *Freemius* in `docs/KNOWN_ISSUES.md`.

## Manual / re-publish

To publish by hand (a publish job failed, or you are pushing a build outside a release), from a checkout of the tagged ref:

```bash
# Self-hosted channel, needs CLOUDFLARE_API_TOKEN
bash scripts/publish_update_release.sh --build

# Freemius: dispatch the workflow (token stays in repo secrets)
env -u GITHUB_TOKEN gh workflow run publish-freemius.yml -f version=X.Y.Z
```

`publish_update_release.sh`:

- verifies version consistency across plugin header, `MCPWP_VERSION`, `readme.txt`, and `version.json`
- builds the self-hosted and agency ZIPs
- refuses to publish a version the channel already serves, or a downgrade (pass `--allow-republish` to force an intentional same-version re-push)
- uploads `version.json` and `mcpwp-latest.zip` to the R2 bucket `mumcp-updates`, and `mcpwp-agency-X.Y.Z.zip` to the private bucket `mcpwp-agency-builds` (served only with an agency download token in the `Authorization` header, #852)
- fails the release if an agency build answers 200 without a token on either host
- verifies both live channels can deliver the new version, and fails if not

Both public self-hosted channels are Cloudflare Workers backed by that one R2 bucket, so the single upload updates both.

Commit any repo changes with path-scoped `git add <paths>`, never `git add -A`.

## Canonical Update Rules

- The `updates.mcpwp.net` manifest and ZIP are the canonical release artifacts; `mumega.com/mcp-updates` serves the same R2 objects for older sites.
- `mcpwp_update_info` is an optional site-level override, not the source of truth.
- If `mcpwp_update_info` is used during deploys, it must match the static manifest exactly.
- If no override is required, leave `mcpwp_update_info` empty.

### Critical Warning

A stale `mcpwp_update_info` option can block future updates even when `mcpwp_version_url` is correct.

The updater currently checks:

1. `mcpwp_update_info`
2. `mcpwp_version_url`
3. built-in worker URL fallback

That means stale override data can hide newer static-manifest releases.

## Post-Release Verification

### Artifact checks

- [ ] `https://updates.mcpwp.net/version.json` returns the new version
- [ ] `https://updates.mcpwp.net/mcpwp-latest.zip` exists
- [ ] ZIP and manifest versions match

### Site checks

On a target site:

- [ ] `mcpwp_version_url` points at `https://updates.mcpwp.net/version.json`
- [ ] `mcpwp_update_info` is empty or matches the manifest exactly
- [ ] `/wp-json/mcpwp/v1/update` reports the expected result
- [ ] If analytics enabled: PostHog Live Events shows `mcp_tool_called` after a tool call

Example checks:

```bash
curl -fsSL "https://SITE/wp-json/mcpwp/v1/option?key=mcpwp_version_url" -H "X-API-Key: ..."
curl -fsSL "https://SITE/wp-json/mcpwp/v1/option?key=mcpwp_update_info" -H "X-API-Key: ..."
curl -fsSL "https://SITE/wp-json/mcpwp/v1/update" -H "X-API-Key: ..."
```

### If a site is already on the new version

Expected:

```json
{
  "current_version": "X.Y.Z",
  "update_available": false,
  "new_version": null,
  "has_package": false
}
```

## Updating Target Sites

### Preferred

Let WordPress discover updates naturally from the `updates.mcpwp.net` manifest. Premium sites update through Freemius instead.

Requirements:

- target site plugin version is recent enough to use the self-update flow
- `mcpwp_version_url` is correct
- `mcpwp_update_info` is empty or current
- `wp-content/upgrade` is writable by the actual web/PHP user on the host

### Forced

If needed, trigger a direct package install via the REST update route:

```bash
curl -fsSL -X POST "https://SITE/wp-json/mcpwp/v1/update" \
  -H "X-API-Key: ..." \
  -H "Content-Type: application/json" \
  --data '{"package_url":"https://updates.mcpwp.net/mcpwp-latest.zip"}'
```

## Failure Mode: Update Not Appearing

Check these in order:

1. `mcpwp_update_info` is stale
2. static manifest version is wrong
3. ZIP URL is wrong or unreachable
4. `wp-content/upgrade` or the plugin directory is not writable by the runtime PHP user
5. site is still on an older plugin build with weaker self-update behavior
6. `update_plugins` / `mcpwp_update_check` caches need clearing

Useful reset:

```bash
curl -fsSL "https://SITE/wp-json/mcpwp/v1/update" -H "X-API-Key: ..."
```

That route clears update caches before checking.

## Rollback

If a release is bad:

1. Fix forward on `main` with a `fix:` commit
2. Merge the next release-please PR
3. Confirm both publish jobs are green and both verify scripts pass

Do not roll back by leaving mismatched manifests or stale `mcpwp_update_info` on sites.

## Historical Notes

- **2026-03-31:** mcpwp.net had stale `mcpwp_update_info` pinned to `1.7.6`; clearing it restored auto-update. `mcpwp_version_url` switched to `https://mumega.com/mcp-updates/version.json`.
- **2026-09-10:** a premium site failed with `Download failed. Forbidden` on 3.11.0 while the release was healthy; a fresh update check fixed it. See `docs/KNOWN_ISSUES.md`.
- Bind-mounted plugin dirs are not valid auto-update test environments — WordPress cannot replace the mounted path during upgrade.

---

**Last Updated:** 2026-09-29
