# Release Checklist

Current release flow for MCPWP.

MCPWP ships to **two independent update fleets**. Publishing one reaches none of the other.

| Fleet | Who | Served from |
|-------|-----|-------------|
| Self-hosted | Free and agency installs | `https://updates.mcpwp.net/version.json` (canonical) and the legacy `https://mumega.com/mcp-updates/version.json`, both from R2 bucket `mumcp-updates` |
| Freemius | Paying customers, premium build | Freemius product 23824 |

## How a release happens

Releases are cut by **release-please**. Do not bump versions by hand.

1. Merge reviewed, green PRs to `main` with conventional-commit titles (`feat:`, `fix:`).
2. release-please keeps a release PR open that bumps `mcpwp/mcpwp.php`, `MCPWP_VERSION`, `readme.txt`, `version.json` and `mcpwp/CHANGELOG.md` together.
3. If the release changes behaviour a site owner would notice, add an `### Upgrade Notes` section with plain-language bullets under that version in `mcpwp/CHANGELOG.md`. `scripts/sync-wporg-changelog.mjs` carries it to readme.txt's `== Upgrade Notice ==` and to the `version.json` update modal.
4. Wait for `main` CI to be green, then merge the release PR.
5. `release-please.yml` then runs, in order: tag and GitHub release, plugin ZIPs attached, WP.org changelog sync, `publish-freemius`, `publish-canonical-channel`.

A release is not shipped until both publish jobs are green. Each one fails the run if its channel cannot serve the new version.

## Pinning the version

release-please derives the next version from the commits since the last tag: any `feat:` means a minor bump. To ship a different version (for example a planned patch release that contains a small feature), land a commit on `main` whose message has a footer line, on its own line at the start:

```
Release-As: X.Y.Z
```

**Squash merges use the PR description as the commit body by default**, so a `Release-As` mentioned in the PR text is not a footer. Set the body explicitly when merging:

```bash
gh pr merge <N> --squash --body "Release-As: X.Y.Z"
```

Then confirm the release PR's title changed to the pinned version before merging it.

## Pre-merge checks for the release PR

- [ ] Suite green locally and on CI, in declaration and random order
- [ ] PHP 7.4 lint clean on production files
- [ ] Behaviour changes have Upgrade Notes
- [ ] Auth, credential and write-path changes in this release passed adversarial review

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

# Freemius, needs FREEMIUS_BEARER_TOKEN
bash scripts/release_freemius.sh --version X.Y.Z --skip-bump
```

`publish_update_release.sh`:

- verifies version consistency across plugin header, `MCPWP_VERSION`, `readme.txt`, and `version.json`
- builds the self-hosted and agency ZIPs
- refuses to publish a version the channel already serves, or a downgrade (pass `--allow-republish` to force an intentional same-version re-push)
- uploads `version.json` and `mcpwp-latest.zip`, plus `agency/mcpwp-agency-X.Y.Z.zip` (served only with an agency download token, #852), to the R2 bucket `mumcp-updates`
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

**Last Updated:** 2026-09-10
