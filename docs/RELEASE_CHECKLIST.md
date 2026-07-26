# Release Checklist

Current release flow for MCPWP.

This project uses Freemius for paid licensing and can still publish self-hosted update artifacts when needed. The canonical self-hosted update path is:

- Static manifest: `https://mumega.com/mcp-updates/version.json`
- Download ZIP: `https://mumega.com/mcp-updates/mcpwp-latest.zip`

## Pre-Release

- [ ] All update-related changes committed to `main`
- [ ] Local tests passing
- [ ] Version number decided
- [ ] `mcpwp/mcpwp.php` updated
- [ ] `version.json` updated
- [ ] `readme.txt` updated if needed
- [ ] `mcpwp/CHANGELOG.md` updated if needed

## Version Bump

Update these locations together:

1. `mcpwp/mcpwp.php`
   - plugin header `Version:`
   - `MCPWP_VERSION` constant
2. `version.json`
   - `version`
   - prepend changelog HTML fragment
3. `mcpwp/readme.txt`
   - `Stable tag:`
   - Add entry under `== Changelog ==`

Quick check:

```bash
grep -E "(Version:|MCPWP_VERSION)" mcpwp/mcpwp.php
cat version.json
```

## Build

```bash
bash scripts/build-wporg.sh
```

Expected output:

- `scripts/mcpwp-X.Y.Z.zip`

## Publish

Publishing is **automatic**: when the release-please release PR is merged, the
`publish-canonical-channel` job in `release-please.yml` calls the publish
workflow, which uploads the artifacts to the R2 bucket `mumcp-updates` and fails
the release if the channel cannot actually serve the new version. Tagging is not
shipping — this is the step that ships.

### Manual / re-publish

To publish by hand (the release job failed, or you are pushing a build outside a
release), from a checkout of the tagged ref with `CLOUDFLARE_API_TOKEN` set:

```bash
bash scripts/publish_update_release.sh --build
```

This:

- verifies version consistency across plugin header, `MCPWP_VERSION`, `readme.txt`, and `version.json`
- builds the self-hosted and agency ZIPs
- refuses to publish a version the channel already serves, or a downgrade (pass `--allow-republish` to force an intentional same-version re-push)
- uploads `version.json`, `mcpwp-latest.zip`, and `mcpwp-agency-X.Y.Z.zip` to the R2 bucket `mumcp-updates`
- verifies both live channels (`updates.mcpwp.net` and `mumega.com/mcp-updates`) can deliver the new version, and fails if not

Both public channels are Cloudflare Workers backed by that one R2 bucket, so the
single upload updates both. No VPS filesystem access is involved.

### Commit and push repo changes

```bash
git add -A
git commit -m "Bump version to X.Y.Z"
git push origin main
```

## Canonical Update Rules

- The static `mumega.com` manifest and ZIP are the only canonical release artifacts.
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

- [ ] `https://mumega.com/mcp-updates/version.json` returns the new version
- [ ] `https://mumega.com/mcp-updates/mcpwp-latest.zip` exists
- [ ] ZIP and manifest versions match

### Site checks

On a target site:

- [ ] `mcpwp_version_url` points at `https://mumega.com/mcp-updates/version.json`
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

Let WordPress discover updates naturally from the static `mumega.com` manifest.

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
  --data '{"package_url":"https://mumega.com/mcp-updates/mcpwp-latest.zip"}'
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

1. Fix the issue locally
2. Bump to a newer version
3. Rebuild the ZIP
4. Replace the `mumega.com` ZIP
5. Verify static manifest + ZIP + site update checks again

Do not roll back by leaving mismatched manifests or stale `mcpwp_update_info` on sites.

## Historical Notes

- **2026-03-31:** mcpwp.net had stale `mcpwp_update_info` pinned to `1.7.6`; clearing it restored auto-update. `mcpwp_version_url` switched to `https://mumega.com/mcp-updates/version.json`.
- Bind-mounted plugin dirs are not valid auto-update test environments — WordPress cannot replace the mounted path during upgrade.

---

**Last Updated:** 2026-06-07
