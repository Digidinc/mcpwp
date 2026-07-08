# mumcp — WordPress.org Submission Guide

**Plugin:** mumcp (Model Context Protocol for WordPress, by Mumega)
**Slug:** `mumcp` · **Version:** 3.4.3 · **Branch:** `wporg` (cut from tag v3.4.3)
**Zip:** `mcpwp/scripts/mumcp-3.4.3.zip` (built by `bash mcpwp/scripts/build-wporg.sh`)

## Pre-upload verification (already done, re-run if zip is rebuilt)

```bash
bash mcpwp/scripts/build-wporg.sh
# Sanity checks must all print [OK]:
#   No Freemius SDK / No Pro modules / No legacy updater /
#   Main file mumcp/mumcp.php / Text domain matches slug / free-lock injected

# Plugin Check (0 errors as of 2026-07-08, plugin-check 2.0.0):
docker exec wp-rig-wp-dev wp plugin check mumcp --allow-root --format=csv | grep ',ERROR,'
```

Unit tests: 283 passing (1 pre-existing skip) via `vendor/bin/phpunit --configuration tests/phpunit.xml`.

## Upload steps (Hadi)

1. Log in at https://wordpress.org/plugins/developers/add/ with the wp.org account that will own the plugin (this account name shows as committer; `Contributors: mumega` in readme.txt should match or include it).
2. Upload `mumcp-3.4.3.zip`. Display name is read from the readme (`=== mumcp ===`) — the requested slug will be `mumcp`.
3. In the "notes to reviewer" box, paste the short note below.
4. Wait for the review email (typically days to ~2 weeks). Reply from the same account email; keep replies in one thread.
5. On approval you get SVN access at `https://plugins.svn.wordpress.org/mumcp/`:
   - `trunk/` ← contents of the zip's `mumcp/` folder
   - `tags/3.4.3/` ← copy of trunk at release
   - `assets/` ← from repo `mcpwp/assets/`: `icon-128x128.png`, `icon-256x256.png`, `banner-772x250.png`, `banner-1544x500.png`, `screenshot-1.png` … `screenshot-4.png` (captions come from readme.txt `== Screenshots ==`)

### Suggested note to reviewer

> mumcp connects WordPress to MCP (Model Context Protocol) clients such as Claude. All access requires an API key generated in wp-admin (keys are stored hashed). The plugin was previously distributed off-directory under an internal codename; internal option/hook prefixes (`mcpwp_*`) and the REST namespace (`mcpwp/v1`) are retained so existing installs are not broken — the public identity (name, slug, text domain `mumcp`) is new for WordPress.org. Telemetry (PostHog) is strictly opt-in and documented in the readme Privacy section. The build contains no self-update mechanism, no Freemius, and no paid code.

## Reviewer Q&A cheat sheet

**Q: Why do option names / classes / REST namespace use `mcpwp_` instead of the slug `mumcp`?**
A: Legacy-internal prefix. The plugin has an existing installed base (distributed off-directory); renaming stored options and the REST namespace would break every live install and require a risky data migration. The prefix is unique and collision-safe (that's the purpose of the prefix rule). Text domain, display name, and slug all match `mumcp`. Plugin Check reports 0 errors.

**Q: Does the plugin phone home?**
A: No unconsented traffic.
- PostHog analytics: disabled by default, explicit opt-in checkbox, fully disclosed in readme Privacy section (what is/isn't collected, random UUID, how to opt out).
- Feedback relay: only fires when a user/agent explicitly calls the feedback tool; disclosed in readme; kill-switch constant `MCPWP_DISABLE_FEEDBACK_RELAY`.
- Provider APIs (Pexels, screenshot worker, Google suggest for keyword research, etc.): only called when the site owner configures keys and invokes the matching tool; all listed in the readme "external services" disclosure.

**Q: Self-updates?**
A: The WP.org build contains no updater: `class-mcpwp-updater.php` is excluded from the zip, the `/update` REST route is not registered, and the `wp_check_update` / `wp_trigger_update` MCP tools are not defined when `MCPWP_WPORG_BUILD` is set (constant is injected into the main file at build time). Updates flow only through WordPress.org.

**Q: Paid/Pro upsell?**
A: The zip ships free code only (`includes/pro/` and Freemius are stripped; `MCPWP_WPORG_BUILD` hard-forces `is_pro() === false`). A few MCP tool names for Pro features remain listed in `tools/list` and return a clear "requires Pro" error with a link to mumcp.com/pricing — informational upsell, no crippled functionality, consistent with directory guidelines. The readme description links the pricing page (mumcp.com/pricing) once.

**Q: `wp_redirect()` warnings in the OAuth controller?**
A: Intentional. The OAuth 2.0 authorization endpoint must redirect back to the client's registered `redirect_uri` (external host by design). URIs are validated against the client registration (exact-match allowlist + PKCE) before redirecting, which is the OAuth-correct equivalent of `allowed_redirect_hosts`. `wp_safe_redirect()` would break every external MCP client.

**Q: Why does the plugin create a service account user?**
A: API requests execute as a dedicated role-limited bot user so AI clients never authenticate as a real admin; every request is scoped by the API key's permission set and logged in the Activity Log.

**Q: Remaining Plugin Check warnings?**
A: Only WARNINGs remain (variable-prefix conventions in admin partials, nonce sniffs on read-only display code, `the_content` filter invocation for rendering). No ERRORs. Happy to address specific ones on request.

## Post-approval follow-ups

- Add the wp.org SVN checkout + deploy step to `docs/RELEASE_CHECKLIST.md` (release-please flow on `main`; cherry-pick shared fixes main→wporg only).
- DONE 2026-07-08: public domain is mumcp.com (landing worker `mumcp-landing`); all marketing links moved off mcpwp.net.
- Consider a GitHub Action for SVN deploy (e.g. 10up/action-wordpress-plugin-deploy) once the slug is live.

## Branch discipline

`main` = Bardiya's (security/CI). `wporg` = long-lived wp.org variant (this branch). Shared fixes: cherry-pick main→wporg only. Never merge wporg→main.
