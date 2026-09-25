# WordPress.org Submission Checklist (#495)

> STATUS: DRAFT / audit — NOT submitted. WP.org review is a one-shot first impression; clear every box before submitting the free build.
>
> Note: a separate, reduced edition is already live on WordPress.org under the slug
> `mumega-mcp` ("Mumega MCP", v3.4.14). This checklist is for submitting the full MCPWP
> free build under its own slug — it is a distinct submission, not an update to the
> existing listing. Do not describe the existing `mumega-mcp` listing as "MCPWP on
> WordPress.org" in public copy.

## readme.txt header — COMPLIANT (audited mcpwp/readme.txt)
- [x] Contributors: mumega
- [x] Tags: ai, claude, mcp, elementor, api  *(WP.org allows max 5 — currently exactly 5 ✓)*
- [x] Requires at least: 6.1
- [x] Tested up to: 7.0
- [x] Requires PHP: 7.4
- [x] Stable tag: 3.13.0 (current) — the tag in this checklist must be re-verified against `mcpwp/readme.txt` at actual submit time; release-please owns this value and it moves on every release
- [x] License: GPLv2 or later + License URI
- [x] Short description present (<150 chars)
- [x] Sections: Description, (verify Installation, FAQ, Changelog, Screenshots below)

## Pre-submit gaps to verify
- [ ] **Installation** section present + accurate (activate → generate key → connect MCP client → first tool call).
- [ ] **FAQ** section covers: what is MCP, is my data safe, which AI clients, does it need Elementor, free vs Pro.
- [ ] **Screenshots** section lists each screenshot with a caption; the actual files (`assets/screenshot-N.png`) exist.
- [ ] **Changelog** in readme.txt matches the version.json changelog.
- [ ] **Privacy** section (already added per project notes) — confirm it covers the PostHog opt-in analytics accurately.

## WP.org asset requirements (the `/assets` SVN dir, not in the plugin zip)
- [ ] Plugin **icon**: 128×128 + 256×256 (`icon-128x128.png`, `icon-256x256.png`).
- [ ] Plugin **banner**: 772×250 + 1544×500 (`banner-772x250.png`, `banner-1544x500.png`).
- [ ] **Screenshots**: `screenshot-1.png` … matching the readme Screenshots section.
- Project notes say MCPWP-branded banner/icon/screenshots were refreshed (v2.8.40, #341) — **confirm they exist and are current** before submit.

## Guideline compliance (the things that get plugins rejected)
- [ ] **Free build is genuinely functional** — the WP.org package must not be a teaser; the free tier (posts/pages/media/drafts/menus/site-context/basic Elementor) works without Pro.
- [ ] **No premium nag/lock that breaks WP.org "no crippling" rules** — Pro upsell is allowed; disabling core WP.org features behind a paywall is not.
- [ ] **No external loading of executable code** — the self-update mechanism (mumega.com manifest) must be **disabled in the WP.org build** (`MCPWP_WPORG_BUILD`); WP.org plugins update only through WP.org. Verify the wporg build flag suppresses the custom updater.
- [ ] **No tracking without consent** — PostHog analytics is opt-in on free tier ✓ (confirm default-off in the wporg build).
- [ ] **Sanitization/escaping/nonces** on all admin forms (WP.org Plugin Check plugin will flag these).
- [ ] Run the official **Plugin Check (PCP)** plugin locally on the rig and clear all errors before submit.

## Submit process (when gated)
1. Build the free package: `bash scripts/build-wporg.sh`.
2. Run Plugin Check on the rig → zero errors.
3. Submit the zip at https://wordpress.org/plugins/developers/add/ (one-time review, days–weeks).
4. On approval: SVN `trunk` + `assets/` + tag the version.

## Needs Hadi
- The WP.org account / plugin-slug reservation.
- Final confirmation the free build's update channel is disabled + analytics default-off.
- Go decision (the review is one-shot; submit only when the free build is clean).
