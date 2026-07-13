# Mumega MCP — WordPress.org Submission Guide

**Plugin:** Mumega MCP (Model Context Protocol for WordPress, by Mumega)
**Slug:** `mumega-mcp` · **Version:** 3.4.3 · **Branch:** `wporg` (cut from tag v3.4.3)
**Zip:** `mcpwp/scripts/mumega-mcp-3.4.3.zip` (built by `bash mcpwp/scripts/build-wporg.sh`)
**Installed folder:** `wp-content/plugins/mumega-mcp/` · **Main file:** `mumega-mcp/mumega-mcp.php`
**Support:** `https://wordpress.org/support/plugin/mumega-mcp/`

## Pre-upload verification

```bash
bash mcpwp/scripts/build-wporg.sh
bash mcpwp/scripts/verify-wporg-artifact.sh mcpwp/scripts/mumega-mcp-3.4.3.zip
# The artifact verifier must print [OK]. Security and physically minimal-surface
# gates are separate review steps and must also pass before upload.

# Plugin Check (run after installing the candidate under its final slug):
docker exec wp-rig-wp-dev wp plugin check mumega-mcp --allow-root --format=csv
```

## Upload steps (Hadi)

1. Log in at https://wordpress.org/plugins/developers/add/ with the wp.org account that will own the plugin (this account name shows as committer; `Contributors: mumega` in readme.txt should match or include it).
2. Upload `mumega-mcp-3.4.3.zip`. Display name is read from the readme (`=== Mumega MCP ===`) — the requested slug will be `mumega-mcp`.
3. In the "notes to reviewer" box, paste the short note below.
4. Wait for the review email (typically days to ~2 weeks). Reply from the same account email; keep replies in one thread.
5. On approval you get SVN access at `https://plugins.svn.wordpress.org/mumega-mcp/`:
   - `trunk/` ← contents of the zip's `mumega-mcp/` folder
   - `tags/3.4.3/` ← copy of trunk at release
   - `assets/` ← from repo `mcpwp/assets/`: `icon-128x128.png`, `icon-256x256.png`, `banner-772x250.png`, `banner-1544x500.png`, `screenshot-1.png` … `screenshot-4.png` (captions come from readme.txt `== Screenshots ==`)

### Suggested note to reviewer

> Mumega MCP connects WordPress to MCP (Model Context Protocol) clients such as Claude. The plugin was previously distributed off-directory under an internal codename; internal option/hook prefixes (`mcpwp_*`) and the REST namespace (`mcpwp/v1`) are retained so existing installs are not broken. Its WordPress.org public identity is consistently `Mumega MCP` / `mumega-mcp`. Final security and package-surface verification will be completed before upload.

## Reviewer Q&A cheat sheet

**Q: Why do option names / classes / REST namespace use `mcpwp_` instead of the slug `mumega-mcp`?**
A: Legacy-internal prefix. The plugin has an existing installed base (distributed off-directory); renaming stored options and the REST namespace would break every live install and require a risky data migration. The prefix is unique and collision-safe (that's the purpose of the prefix rule). Text domain, display name, and slug use the public `mumega-mcp` identity.

**Q: Does the plugin phone home?**
A: No unconsented traffic.
- PostHog analytics: disabled by default, explicit opt-in checkbox, fully disclosed in readme Privacy section (what is/isn't collected, random UUID, how to opt out).
- Feedback relay: only fires when a user/agent explicitly calls the feedback tool; disclosed in readme; kill-switch constant `MCPWP_DISABLE_FEEDBACK_RELAY`.
- Provider APIs (Pexels, screenshot worker, Google suggest for keyword research, etc.): only called when the site owner configures keys and invokes the matching tool; all listed in the readme "external services" disclosure.

**Q: Self-updates?**
A: The WordPress.org branch defines `MCPWP_WPORG_BUILD` in source, and the artifact verifier rejects the legacy updater implementation. The final route/tool security gate must pass before upload.

**Q: Paid/Pro upsell?**
A: The artifact contract rejects `includes/pro/` and Freemius implementation files. Remaining product surfaces are subject to the later physically minimal-surface gate before upload.

**Q: `wp_redirect()` warnings in the OAuth controller?**
A: Intentional. The OAuth 2.0 authorization endpoint must redirect back to the client's registered `redirect_uri` (external host by design). URIs are validated against the client registration (exact-match allowlist + PKCE) before redirecting, which is the OAuth-correct equivalent of `allowed_redirect_hosts`. `wp_safe_redirect()` would break every external MCP client.

**Q: Why does the plugin create a service account user?**
A: API requests execute as a dedicated role-limited bot user so AI clients never authenticate as a real admin; every request is scoped by the API key's permission set and logged in the Activity Log.

**Q: Remaining Plugin Check warnings?**
A: Use the current candidate's Plugin Check output; do not rely on results from an older slug or artifact.

## Post-approval follow-ups

- Add the wp.org SVN checkout + deploy step to `docs/RELEASE_CHECKLIST.md` (release-please flow on `main`; cherry-pick shared fixes main→wporg only).
- DONE 2026-07-08: public domain is mumcp.com (landing worker `mumcp-landing`); all marketing links moved off mcpwp.net.
- Consider a GitHub Action for SVN deploy (e.g. 10up/action-wordpress-plugin-deploy) once the slug is live.

## Branch discipline

`main` = Bardiya's (security/CI). `wporg` = long-lived wp.org variant (this branch). Shared fixes: cherry-pick main→wporg only. Never merge wporg→main.
