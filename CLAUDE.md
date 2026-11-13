# MCPWP - WordPress MCP Plugin

> 📍 **Start at [`TRUTH.md`](TRUTH.md)** for project topology, naming, and decisions (mumcp = product · mcpwp = media). This file = plugin internals.
>
> 🧭 **Running the project (plan, work queue, releases, rules)?** Start at [`docs/plan/README.md`](docs/plan/README.md).

## Local Development (Primary)

### Local WordPress

**Use `wp-rig-wp-dev`. It is the only container that mounts this plugin.**

```
URL:      http://localhost:8086
WP-CLI:   docker exec wp-rig-wp-dev wp <command> --allow-root
PHPUnit:  docker exec wp-rig-wp-dev bash -c 'cd /var/www/html/wp-content/plugins/mcpwp && vendor/bin/phpunit --configuration tests/phpunit.xml'
Mount:    wp-ai-operator/mcpwp -> /var/www/html/wp-content/plugins/mcpwp
```

Edits to `wp-ai-operator/mcpwp/` are live instantly there. No deploy needed.

**`wp-test-wordpress-1` (port 8080) does NOT mount this plugin, and it lies convincingly.** It
mounts `site-pilot-ai` and `site-pilot-ai-pro` — the pre-rename directories — and carries a stale,
unmounted copy of `mcpwp` under its own volume.

The trap: **it answers.** `http://localhost:8080/wp-json/` returns HTTP 200 and advertises the
`mcpwp/v1` namespace, so it looks like a working dev site. It is running old code. A file added to
`mcpwp/` today is simply absent there, and any test against it silently passes or fails on a
version nobody is editing. This doc previously named it as the live-mounted dev container and gave
port 8080, which cost two tasks real debugging time. Verify with:

```bash
docker inspect wp-rig-wp-dev --format '{{range .Mounts}}{{.Source}} -> {{.Destination}}{{"\n"}}{{end}}'
```

There is no PHP on the host — every PHP or WP-CLI command must run inside the container.

**Parallel work hazard:** `wp-rig-wp-dev`'s bind mount points at this shared checkout, so two
agents testing at once are editing the same files under the same web server. Isolated work stands
up its own throwaway container against its own clone rather than testing against the shared mount.

### Testing without the shared rig

PHPUnit needs a `vendor/` (composer-installed; `mcpwp/vendor/` is gitignored and not shipped), but
it does not need `wp-rig-wp-dev`. Point a throwaway container at any composer-installed vendor
directory — from a `composer install` in this worktree, another worktree, or a scratch clone — and
mount it read-only next to the plugin:

```bash
docker run --rm \
  -v <worktree>/mcpwp:/app \
  -v <any-composer-installed>/mcpwp/vendor:/app/vendor:ro \
  -w /app php:8.3-cli php vendor/bin/phpunit -c tests/phpunit.xml
```

This never touches the shared rig or its bind mount, so it is safe to run from an isolated worktree
while other work is live on `wp-rig-wp-dev`.

**Testing a Pro surface** needs the agency-license marker, `mcpwp/includes/agency-license.php`
returning `'MCPWP_AGENCY_LICENSE_V1'` (see `Mcpwp_License::agency_license_active()`). It is not
gitignored and is not in any shipped ZIP — create it only inside your own worktree, delete it when
done, and never commit it:

```bash
printf '<?php\nreturn '"'"'MCPWP_AGENCY_LICENSE_V1'"'"';\n' > mcpwp/includes/agency-license.php
```

(`MCPWP_TESTS` + CLI SAPI is the other Pro switch — that one is what the PHPUnit bootstrap uses,
and by design it cannot be reached by a web request.)

### Generate API Key
```bash
docker exec wp-rig-wp-dev bash -c 'php -r "
require_once \"/var/www/html/wp-load.php\";
\$key = \"mcpwp_\" . bin2hex(random_bytes(24));
update_option(\"mcpwp_api_key\", wp_hash_password(\$key));
echo \$key;
"'
```

### Quick Test
```bash
KEY="mcpwp_..."  # from above

# Site info
curl -s http://localhost:8086/wp-json/mcpwp/v1/site-info -H "X-API-Key: $KEY" | jq

# Create page + push Elementor data
PAGE=$(curl -s -X POST http://localhost:8086/wp-json/mcpwp/v1/pages \
  -H "X-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{"title":"Test","status":"draft"}' | jq -r .id)

curl -s -X POST "http://localhost:8086/wp-json/mcpwp/v1/elementor/$PAGE" \
  -H "X-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{"elementor_data":"[{\"id\":\"abc0001\",\"elType\":\"section\",\"settings\":{},\"elements\":[{\"id\":\"def0001\",\"elType\":\"column\",\"settings\":{\"_column_size\":100},\"elements\":[{\"id\":\"ghi0001\",\"elType\":\"widget\",\"widgetType\":\"heading\",\"settings\":{\"title\":\"Hello\"}}]}]}]"}' | jq
```

### WP-CLI Shortcuts
```bash
# Plugin management
docker exec wp-rig-wp-dev wp plugin list --allow-root
docker exec wp-rig-wp-dev wp plugin install elementor --activate --allow-root

# Database
docker exec wp-rig-wp-dev wp option get mcpwp_version --allow-root
docker exec wp-rig-wp-dev wp post list --post_type=page --allow-root

# Reset test environment
docker exec wp-rig-wp-dev wp post delete $(docker exec wp-rig-wp-dev wp post list --post_type=page --format=ids --allow-root) --force --allow-root
```

## Production Site

| Key | Value |
|-----|-------|
| URL | https://mcpwp.net |
| Stack | WordPress 6.9 + Elementor Pro |

### Key Page IDs (mcpwp.net)
Privacy & Terms: 118

## Deploy & Update System

**Canonical channel (2026-06-11+): `updates.mcpwp.net`** — Cloudflare Worker `mcpwp-updates` serving `version.json` + zips from R2 bucket `mumcp-updates`. New `mcpwp/v1` sites set `mcpwp_version_url` → `https://updates.mcpwp.net/version.json`.

**Legacy dual-serve:** `mumega.com/mcp-updates/*` and `mumega.com/spai-updates/*` stay live on the old `spai-updates` Worker (same R2) for un-migrated 2.8.56 `site-pilot-ai` sites. Retire `spai-updates` after every site repoints to `updates.mcpwp.net`.

| Component | URL |
|-----------|-----|
| Version check (canonical) | `https://updates.mcpwp.net/version.json` |
| Zip download (canonical) | `https://updates.mcpwp.net/mcpwp-latest.zip` |
| Version check (legacy) | `https://mumega.com/mcp-updates/version.json` |
| Worker (canonical) | `mcpwp-updates` · source `~/projects/sitepilotai/mcpwp-updates-worker/` |
| Worker (legacy) | `spai-updates` (deployed-only; serves mumega.com routes) |
| R2 bucket | `mumcp-updates` (manifest pinned 2.8.56 — two-person rule to bump) |

### Distribution Builds

Three variants, one command:

```bash
bash mcpwp/scripts/build-all.sh
```

| Zip | Pro? | License | Use for |
|-----|------|---------|---------|
| `mcpwp-X.Y.Z.zip` | No | — | Free direct-download / self-hosted install |
| `mcpwp-agency-X.Y.Z.zip` | Always on | None needed | Agency/client installs |
| `mcpwp-freemius-X.Y.Z.zip` | Via key | Freemius | Paid users |

**Editions, not to be confused:** MCPWP (this repo) is the professional plugin, distributed directly
— `updates.mcpwp.net`, Freemius, and the directories in `docs/launch/directories/`. It is not on
WordPress.org. The wp.org listing (slug `mumega-mcp`, display name "Mumega MCP") is a separate,
reduced edition built from a different tree and is not an MCPWP distribution channel (owner
decision, 2026-09-26, #931 closed not planned).

Agency build writes a build-time marker file (`includes/agency-license.php`) at build time — no wp-config.php changes, no license prompt. A wp-config.php `define('MCPWP_PRO', true)` is NOT honoured by any build, Free or Freemius included (issue #863) — the marker file is the only thing that unlocks Pro without Freemius, and only `scripts/build-agency.sh` writes it. Includes self-update channel (`updates.mcpwp.net`).

Agency builds are **not public** (#852): anyone holding one has every Pro feature. They are stored in the private R2 bucket `mcpwp-agency-builds` (never in `mumcp-updates`, which the legacy `mumega.com` Worker reads without auth) and served only by `updates.mcpwp.net/agency/mcpwp-agency-X.Y.Z.zip` to a request with `Authorization: Bearer <token>` — tokens are never accepted in a URL. Tokens are the `mcpwp-updates` Worker secret `AGENCY_DOWNLOAD_TOKENS` (one per agency, comma-separated, 32+ chars). Never upload an agency ZIP to a public bucket or paste a token into docs, a `package_url` or a script. The release fails if an agency build answers 200 without a token on either host. Note: an agency site's built-in updater follows the public manifest to `mcpwp-latest.zip`, which is the Free build.

### Release a New Version

**release-please owns versioning — never hand-bump.** Since `versioning: always-bump-patch`
(`release-please-config.json`), **every release is `3.14.N`** — `fix:`, `feat:`, and breaking
changes all bump the patch digit, not the minor or major — until the owner decides to move off the
3.14 line. Land Conventional Commits on `main`; release-please opens/updates a release PR that bumps
all six version artefacts, writes the changelog, tags, and creates the GitHub Release when a human
merges it. Checkpoints on the 3.14 line are tracked as GitHub milestones, not version numbers:
"3.14.1 — Solid ground", "3.14.5 — Agent-native", "3.14.9 — Self-improving + go-to-market".

**Fallback when GitHub Actions can't run a release** (billing blocked it twice — used for 3.13.1
and 3.14.0): run release-please locally instead of waiting on CI.

```bash
T=$(env -u GITHUB_TOKEN gh auth token)

# 1. Open/update the release PR
npx -y release-please@16 release-pr \
  --token="$T" --repo-url=Digidinc/mcpwp --target-branch=main \
  --config-file=release-please-config.json --manifest-file=.release-please-manifest.json

# 2. Merge the Release PR, then tag + create the GitHub Release
npx -y release-please@16 github-release \
  --token="$T" --repo-url=Digidinc/mcpwp --target-branch=main \
  --config-file=release-please-config.json --manifest-file=.release-please-manifest.json
gh release edit vX --title "MCPWP vX"

# 3. Carry the changelog to the wp.org-format readme.txt, verify version consistency, build, publish
node scripts/sync-wporg-changelog.mjs --version X && git add mcpwp/readme.txt version.json && git commit -m "chore: sync WP.org changelog for vX" && git push
python3 scripts/verify_release_compatibility.py --manifest version.json --plugin mcpwp/mcpwp.php --readme mcpwp/readme.txt
bash mcpwp/scripts/build-all.sh
# Attach only the free + freemius ZIPs to the GitHub release — never the agency ZIP (#852)
bash scripts/publish_update_release.sh --build

# 4. Verify the live ZIP actually shipped
curl -sI https://updates.mcpwp.net/mcpwp-latest.zip | grep -i version
curl -s https://updates.mcpwp.net/mcpwp-latest.zip -o /tmp/mcpwp-latest.zip && unzip -p /tmp/mcpwp-latest.zip mcpwp/mcpwp.php | grep -i "Version:\|MCPWP_VERSION"
```

Then publish to Freemius (the paid fleet) by dispatching `publish-freemius.yml`. The token stays
in repo secrets; never read it: `env -u GITHUB_TOKEN gh workflow run publish-freemius.yml -f version=X`.
The full step-by-step, including the release smoke test (`scripts/release_smoke.sh`), is in
`docs/RELEASE_CHECKLIST.md`.

Merge the release PR (`chore(main): release X.Y.Z`) → tags `vX.Y.Z`. release-please then publishes
automatically (`publish-canonical-channel` job): it uploads `version.json` + the self-hosted ZIP +
the public files to the `mumcp-updates` R2 bucket and the agency ZIP to the private `mcpwp-agency-builds` bucket — which backs both
`updates.mcpwp.net` and `mumega.com/mcp-updates` — and FAILS the release if the channel cannot
actually serve the new version. No manual `wrangler` upload and no VPS access is needed.

Re-publish by hand if that job failed, or to push a build outside a release:

```bash
bash scripts/publish_update_release.sh --build
```

**Tagging is not shipping.** Sites update from whatever `updates.mcpwp.net/version.json` serves.
Publishing used to be a remembered manual step; it is now part of the release, and the publish
script exits non-zero unless the live channel actually serves the new version. Watch the
`publish-canonical-channel` job — green there means sites can receive the release.

**Before cutting a release, check what the channel already serves:**
`curl -s https://updates.mcpwp.net/version.json | jq -r .version`. If that version is >= the one
about to be tagged, pick a higher one — the updater compares version strings, so re-publishing a
version number that is already installed leaves those sites permanently on the old code. (This bit
us: a 3.7.0 build was live on the channel while the repo was about to tag a *different* 3.7.0, which
would have stranded two security fixes. Released as 3.7.1 instead.) `publish_update_release.sh` now
enforces this automatically — it refuses to publish a version the channel already serves or a
downgrade (`--allow-republish` overrides an intentional same-version re-push) — but picking a higher
version up front is still the right fix.

**To force a version:** put `Release-As: X.Y.Z` in a commit footer — but it **must ride a commit that
changes a real file**. `exclude-paths` in `release-please-config.json` makes release-please filter out
commits touching no files, so the documented `--allow-empty` trick is silently dropped here. The run
still reports success; verify by checking the release PR title actually changed.

Release PRs get **no CI** — GitHub does not trigger workflows for `GITHUB_TOKEN`-created PRs. Expected.

### How the Updater Works
1. Checks `mcpwp_update_info` WP option first
2. Falls back to `mcpwp_version_url` option, or `https://updates.mcpwp.net/version.json` if that
   option was never saved (raised from the legacy mumega.com manifest in #888/3.13.1 — a site that
   already saved a value, including the legacy manifest, keeps working: both read the same R2
   bucket, see "Deploy & Update System" above)
3. Compares remote version to installed version
4. Downloads zip from `download_url` and installs via `Plugin_Upgrader`
5. `wp_trigger_update` also accepts `package_url` param for forced installs

**The manifest is authoritative — never WordPress's `update_plugins` transient.**
`Mcpwp_Updater::get_update_state()` is the single source of truth for "is there an update", and both
`/update` endpoints read it. Core only populates that transient when *its own* request to
api.wordpress.org succeeds; when that fails it still fires
`pre_set_site_transient_update_plugins` but with no `checked` property at all. Inferring the answer
from the transient therefore stranded sites on outdated releases — including a security patch (#716).

A declined update now returns a `reason` (`already_current`, `manifest_fetch_failed`,
`version_url_not_https`, `manifest_invalid`, `package_host_not_trusted`) instead of a bare
"No update available", which is otherwise indistinguishable from a broken channel.

Transient entries this plugin owns carry `Mcpwp_Updater::FORCED_PACKAGE_FLAG`; `check_for_update()`
leaves those alone so a `package_url` install is not reverted to the manifest version.

`Mcpwp_Updater::describe_reason()` owns the reason → sentence mapping. REST responses, the Site
Health test and the admin notice all call it, so they cannot disagree about what a reason means.

### Update Channel Durability

Every layer of the delivery path now fails loudly instead of silently. #716 was invisible because
each of them was quiet:

| Guard | Where | Catches |
|-------|-------|---------|
| Release publishes itself | `release-please.yml` → `publish-release.yml` (`workflow_call`) | A tagged release nobody remembered to publish |
| Publish is fatal on a bad channel | `scripts/publish_update_release.sh` | Manifest bumped but ZIP stale/missing/404 — these were `Note:` lines that exited 0 |
| End-to-end channel check | `scripts/verify_update_channel.sh` | Manifest advertising a version the served ZIP does not contain; untrusted `download_url` host; package shipped without the updater |
| Daily canary | `update-channel-canary.yml` | A channel that rots after publish; files/reopens one issue per channel |
| On-site visibility | `Mcpwp_Update_Channel_Health` | A site whose channel is unreachable — Site Health "critical", admin notice, `update_channel` in `/site-health` |
| Freemius publishes itself | `release-please.yml` → `publish-freemius` | A release that reached the self-hosted channel but never the paid fleet |
| Freemius channel check | `scripts/verify_freemius_channel.sh` | Freemius serving an older version than the latest tag; a build left in `pending`/`beta` rather than `released` |
| Pruned-class guard | `scripts/verify_distignore_refs.sh` (MCPWP CI) | Shipped code calling a class that `.distignore` removes from a build — a fatal only that build sees (#723) |

**There are two fleets, not one.** `updates.mcpwp.net` serves self-hosted and agency installs; the
premium build updates through **Freemius** and ignores that manifest entirely. Publishing one ships
nothing to the other. Freemius sat on 3.7.0 from 2026-07-21 to 2026-07-27 while the self-hosted
channel moved through 3.7.1, 3.7.2, 3.8.0 and 3.9.0 — four releases, including the #716 updater fix,
that no paying customer could install. Both are now published by the release and watched daily.

Freemius needs `FREEMIUS_BEARER_TOKEN` (repo secret, and in `~/.env.secrets` locally). The verifier
treats a missing token as a **failure**, not a skip — "could not check the paid channel" and "the
paid channel is fine" must never print the same thing.

```bash
bash scripts/verify_freemius_channel.sh --expect-version 3.9.1
```

**The premium build's plugin directory is `mcpwp-premium/`, but every published ZIP unpacks to
`mcpwp/`.** So `Plugin_Upgrader::upgrade('mcpwp-premium/mcpwp.php')` fails by construction, and a
`package_url` forced install cannot rescue a premium site. Update those through Freemius — pull the
signed package with `mcpwp_fs()->get_update( false, true )`, inject it into the `update_plugins`
transient under the real basename, then run `Plugin_Upgrader`. Freemius' own updater does not hook
that transient under WP-CLI, so `wp plugin update` reports "no update" on a site that has one.

Check any channel by hand, no build required:

```bash
bash scripts/verify_update_channel.sh --manifest-url https://updates.mcpwp.net/version.json
```

A site that cannot reach its channel can still be updated directly: `wp_trigger_update` with an
explicit `package_url` bypasses the manifest and the transient entirely.

### Update Notes

- Canonical release source is `updates.mcpwp.net` (the `mcpwp-updates` Worker, R2 bucket
  `mumcp-updates`); `mumega.com/mcp-updates` is a legacy mirror serving the same R2 objects for
  un-migrated sites, not a separate source
- A stale `mcpwp_update_info` can block newer releases — leave it empty unless intentionally overriding
- New sites default `mcpwp_version_url` to `https://updates.mcpwp.net/version.json` (since #888,
  3.13.1); sites that already saved the legacy `mumega.com` manifest URL keep working and do not
  need to be repointed
- See `docs/RELEASE_CHECKLIST.md` for full release procedure

## Plugin Architecture

```
wp-ai-operator/mcpwp/
├── mcpwp.php              # Bootstrap, version constants, hook registration
├── includes/
│   ├── core/
│   │   ├── class-mcpwp-analytics.php           # PostHog server-side analytics
│   │   ├── class-mcpwp-integration-manager.php # Provider config: OpenAI, Gemini, ElevenLabs, Pexels, Figma, PostHog…
│   │   ├── class-mcpwp-elementor-basic.php     # Elementor GET/SET + widget validation
│   │   ├── class-mcpwp-elementor-widgets.php   # Widget schema registry
│   │   ├── class-mcpwp-core.php                # Site info, capabilities
│   │   ├── class-mcpwp-posts.php               # Posts CRUD
│   │   ├── class-mcpwp-pages.php               # Pages CRUD
│   │   ├── class-mcpwp-drafts.php              # Drafts management
│   │   ├── class-mcpwp-media.php               # Media upload/management
│   │   ├── class-mcpwp-approvals.php           # Approval request lifecycle
│   │   ├── class-mcpwp-event-store.php         # Event persistence + webhook dispatch
│   │   ├── class-mcpwp-design-references.php   # Design reference library
│   │   ├── class-mcpwp-content-coherence.php   # Content coherence scoring
│   │   ├── class-mcpwp-seo-audit-store.php     # Stored SEO issues
│   │   ├── class-mcpwp-seo-autofix.php         # Approval-safe SEO fix planner
│   │   ├── class-mcpwp-signals.php             # Site health signals (lazy compute, time-budgeted)
│   │   ├── class-mcpwp-site-state.php          # Coherent site-state snapshot
│   │   ├── class-mcpwp-site-blueprints.php     # Site blueprint export/deploy
│   │   ├── class-mcpwp-site-memory.php         # Persistent AI memory store
│   │   ├── class-mcpwp-agent-playbooks.php     # Playbook contracts for agents
│   │   ├── class-mcpwp-guides.php              # Guide indexing
│   │   ├── class-mcpwp-workflows.php           # Workflow definitions
│   │   ├── class-mcpwp-figma.php               # Figma API wrapper
│   │   ├── class-mcpwp-screenshot.php          # Page screenshot
│   │   ├── class-mcpwp-search-performance.php  # Search Console import storage
│   │   ├── class-mcpwp-graph-reader.php        # Site graph read surface: visibility chokepoint, filters, cursors
│   │   ├── class-mcpwp-woocommerce-seo.php     # WooCommerce SEO report
│   │   ├── class-mcpwp-keyword-research.php    # Keyword research
│   │   ├── class-mcpwp-white-label.php         # White-label config
│   │   ├── class-mcpwp-action-log.php          # Action log persistence
│   │   ├── class-mcpwp-feedback.php            # Feedback store
│   │   ├── class-mcpwp-provider-openai.php     # OpenAI API wrapper
│   │   ├── class-mcpwp-provider-gemini.php     # Google Gemini wrapper
│   │   ├── class-mcpwp-provider-elevenlabs.php # ElevenLabs TTS wrapper
│   │   ├── class-mcpwp-provider-pexels.php     # Pexels stock photos
│   │   └── class-mcpwp-encryption.php          # Encryption utility for stored keys
│   ├── api/
│   │   ├── class-mcpwp-rest-api.php            # Base REST controller (auth trait, logging)
│   │   ├── class-mcpwp-rest-site.php           # /onboard, /site-info, /update, /settings, /introspect
│   │   ├── class-mcpwp-rest-elementor.php      # /elementor/* — full Elementor surface
│   │   ├── class-mcpwp-rest-mcp.php            # /mcp — all MCP tool dispatch, fires mcpwp_tool_called
│   │   ├── class-mcpwp-rest-posts.php          # /posts, /drafts
│   │   ├── class-mcpwp-rest-pages.php          # /pages, /pages/by-slug
│   │   ├── class-mcpwp-rest-media.php          # /media, /media/from-url, /media/from-base64
│   │   ├── class-mcpwp-rest-menus.php          # /menus — full menu surface
│   │   ├── class-mcpwp-rest-blocks.php         # /blocks — Gutenberg surface
│   │   ├── class-mcpwp-rest-seo-audit.php      # /seo/* — audit, issues, autofix
│   │   ├── class-mcpwp-rest-signals.php        # /signals — site health signals
│   │   ├── class-mcpwp-rest-content-graph.php  # /content-graph, /suggest-links
│   │   ├── class-mcpwp-rest-graph.php          # /graph, /graph/node|neighbors|query, /graph/rebuild (#943)
│   │   ├── class-mcpwp-rest-content-quality.php# /seo/content-quality
│   │   ├── class-mcpwp-rest-approvals.php      # /approvals — full approval lifecycle
│   │   ├── class-mcpwp-rest-webhooks.php       # /webhooks, /events
│   │   ├── class-mcpwp-rest-integrations.php   # /integrations — provider management
│   │   ├── class-mcpwp-rest-figma.php          # /figma — Figma file access
│   │   ├── class-mcpwp-rest-screenshot.php     # /screenshot
│   │   ├── class-mcpwp-rest-batch.php          # /batch — multi-request handler
│   │   ├── class-mcpwp-rest-content.php        # /content — generic CPT endpoint
│   │   ├── class-mcpwp-rest-feedback.php       # /feedback
│   │   ├── class-mcpwp-rest-site-blueprints.php# /site-blueprints
│   │   ├── class-mcpwp-rest-site-memory.php    # /memory — AI memory CRUD
│   │   └── class-mcpwp-rest-action-log.php     # /action-log
│   ├── mcp/
│   │   ├── class-mcpwp-mcp-free-tools.php      # Free MCP tools
│   │   ├── class-mcpwp-mcp-pro-tools.php       # Pro MCP tools
│   │   ├── class-mcpwp-mcp-tool-registry.php   # Tool registry base
│   │   ├── class-mcpwp-custom-tool-registry.php# Custom tool registration
│   │   ├── class-mcpwp-integration.php         # Integration base class
│   │   ├── class-mcpwp-mcp-ai-integration.php  # AI provider integration
│   │   └── class-mcpwp-mcp-figma-integration.php # Figma MCP integration
│   ├── admin/
│   │   ├── class-mcpwp-admin.php               # Admin menu, page routing
│   │   ├── class-mcpwp-settings.php            # Settings registration + UI
│   │   ├── class-mcpwp-integrations-admin.php  # Integrations admin page
│   │   ├── class-mcpwp-tools-admin.php         # Tool category toggles UI
│   │   └── class-mcpwp-activity-log.php        # Activity log admin page
│   ├── traits/
│   │   ├── trait-mcpwp-api-auth.php            # API key validation
│   │   ├── trait-mcpwp-sanitization.php        # Input sanitization
│   │   └── trait-mcpwp-logging.php             # Activity logging
│   ├── class-mcpwp-loader.php                  # Hook registration
│   ├── class-mcpwp-rate-limiter.php            # Rate limiting
│   ├── class-mcpwp-updater.php                 # Self-update system
│   ├── class-mcpwp-license.php                 # License management (Freemius)
│   ├── class-mcpwp-alerts.php                  # Error spike alerts
│   ├── class-mcpwp-ai-presence.php             # AI client detection
│   ├── class-mcpwp-security.php               # Security utilities
│   ├── class-mcpwp-webhooks.php               # Webhook firing
│   ├── class-mcpwp-activator.php              # Plugin activation
│   ├── class-mcpwp-deactivator.php            # Plugin deactivation
│   ├── class-mcpwp-error-hints.php            # Error hint enrichment
│   ├── class-mcpwp-i18n.php                   # Internationalization
│   └── freemius-init.php                      # Freemius SDK bootstrap
├── admin/
│   ├── css/mcpwp-admin.css                    # MCPWP design system (CSS tokens, dark headers)
│   └── partials/                              # Admin page templates
├── readme.txt                                 # WordPress.org changelog + Privacy section
├── version.json                               # Self-update manifest (updates.mcpwp.net serves this; mumega.com mirrors it)
└── scripts/
    ├── build-wporg.sh                         # Build the free, no-Pro zip (self-hosted distribution, not a WordPress.org submission)
    ├── build-freemius.sh                      # Build Freemius paid package
    ├── publish_update_release.sh              # Build + publish to updates.mcpwp.net R2 (mumega.com mirrors the same bucket)
    └── release_freemius.sh                   # Deploy to Freemius
```

## REST API Endpoints (MCPWP)

All endpoints require `X-API-Key` header. Namespace: `mcpwp/v1`.

### Discovery & Site
| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/site-info` | GET | Site info + capabilities + `elementor_layout_mode` |
| `/onboard` | GET | Complete first-connection briefing (use this first) |
| `/introspect` | GET | Machine-readable API description |
| `/settings` | GET/PUT | Plugin settings |
| `/options` | GET | WordPress reading options |
| `/plugins` | GET | Active plugins + capabilities |
| `/fetch` | GET | Fetch arbitrary external URL |
| `/rate-limit` | GET | Rate limit status |
| `/rate-limit/reset` | POST | Reset rate limit |
| `/analytics` | GET | Usage analytics |
| `/rendered-copy` | GET | Structured visible text from live rendered page (headings, paragraphs, buttons, alts) |
| `/screenshot` | POST | Generate page screenshot |
| `/batch` | POST | Batch multiple requests in one call |
| `/cache-flush` | POST | Flush WP page cache (W3TC, WP Super Cache, LiteSpeed, WP Rocket, Cloudflare, etc.) — Pro |
| `/feedback` | POST | Submit feedback |
| `/update` | GET/POST | Check/trigger plugin self-update |

### Content
| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/pages` | GET/POST | List/create pages |
| `/pages/{id}` | GET/PUT/DELETE | Individual page CRUD |
| `/pages/{id}/clone` | POST | Duplicate page |
| `/pages/by-slug/{slug}` | GET | Fetch page by slug |
| `/pages/{id}/template` | GET/PUT | Page template assignment |
| `/templates/page` | GET | Available page templates |
| `/posts` | GET/POST | List/create posts |
| `/posts/{id}` | GET/PUT/DELETE | Individual post CRUD |
| `/posts/{id}/featured-image` | POST | Set featured image |
| `/drafts` | GET | List all drafts |
| `/drafts/delete-all` | POST | Bulk delete drafts |
| `/search` | GET | Search posts/pages |
| `/content` | GET | Generic content list (any post type) |
| `/categories` | GET | All post categories |
| `/tags` | GET | All post tags |

On `POST /post-meta/{id}`, when `key` is a known id-meta (`Mcpwp_REST_API::get_post_ref_meta_value_keys()`:
`_thumbnail_id`, `_product_image_gallery`, `_upsell_ids`, `_crosssell_ids`, `_children`), a user-run
principal must be able to read every post `value` names (`read_post`, #1121). The key is lowercased
before the lookup (`meta_key` collates case-insensitively) and the value is read through
`Mcpwp_REST_API::decode_post_meta_value()`, the same decoder the handler stores with. Negative, signed, out-of-range
and nested values (anything that does not round-trip as a positive integer) are `400 invalid_post_ref`; more than 500 ids is `400 too_many_ids`; bot keys are
unchanged. A new id-meta key must be classified in `PostMetaIdRefs1121Test::CLASSIFIED`.

### Media
| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/media` | GET | List media library |
| `/media` | POST | Upload file |
| `/media/{id}` | GET/PUT/DELETE | Individual media CRUD |
| `/media/from-url` | POST | Import from external URL |
| `/media/from-base64` | POST | Base64-encoded upload |
| `/media/bulk` | POST | Batch upload |

### Elementor
| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/elementor/{id}` | GET/POST | Get/set full Elementor data |
| `/elementor/{id}/summary` | GET | Quick preview (no full data) |
| `/elementor/{id}/preview` | GET | Rendered HTML preview |
| `/elementor/{id}/edit-section` | PUT | Edit one section |
| `/elementor/{id}/add-section` | POST | Add new section |
| `/elementor/{id}/remove-section` | DELETE | Delete section |
| `/elementor/{id}/replace-section` | PUT | Replace section |
| `/elementor/{id}/patch` | PATCH | Patch page data |
| `/elementor/{id}/edit-widget` | PUT | Edit one widget |
| `/elementor/{id}/find-replace` | POST | Find/replace in page |
| `/elementor/bulk` | GET | Get multiple pages at once |
| `/elementor/status` | GET | Elementor version/mode |
| `/elementor/widgets` | GET | Available widgets |
| `/elementor/widgets/{type}` | GET | Widget schema |
| `/elementor/widget-help/{type}` | GET | Widget help |
| `/elementor/kit-css` | GET/POST | Global kit CSS (replace/append) |
| `/elementor/kit-css/blocks` | GET/POST | List / upsert named, hash-tagged, idempotent kit CSS blocks |
| `/elementor/kit-css/blocks/{name}` | GET/DELETE | Get / delete one named kit CSS block |
| `/elementor/regenerate-css` | POST | Regenerate CSS cache |

### Gutenberg
| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/blocks/{id}` | GET/PUT | Get/set Gutenberg blocks |
| `/block-types` | GET | Available block types |
| `/block-patterns` | GET | Block patterns |
| `/blocks/parse` | POST | Parse HTML to blocks |
| `/blocks/serialize` | POST | Serialize blocks to HTML |
| `/blocks/validate` | POST | Validate block structure |
| `/blocks/design-system` | GET | Design system tokens |

### Menus
| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/menus` | GET/POST | List/create menus |
| `/menus/{id}` | GET/PUT/DELETE | Menu CRUD |
| `/menus/locations` | GET | Available theme locations |
| `/menus/assign-location` | POST | Assign menu to location |
| `/menus/setup` | POST | Create + populate menu in one call |
| `/menus/{id}/items` | GET/POST | List/add menu items |
| `/menus/{id}/items/{item_id}` | GET/PUT/DELETE | Item CRUD |
| `/menus/{id}/items/reorder` | POST | Reorder items |

### SEO & Content Intelligence
| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/seo/readiness/{id}` | GET | Pre-publish SEO validation |
| `/seo/audit` | GET | Site-level SEO audit |
| `/seo/issues` | GET | Stored SEO issues |
| `/seo/autofix-plan` | GET | Approval-safe fix plan |
| `/seo/search-performance` | GET | Search trend report |
| `/seo/search-performance/import` | POST | Import search data |
| `/seo/woocommerce` | GET | WooCommerce product SEO report |
| `/seo/media/{id}` | GET | Media SEO check |
| `/seo/structured-data/{id}` | GET | Schema.org validation |
| `/content-graph` | GET | Weighted internal link graph |
| `/content-graph/suggestions` | POST | Suggest internal links |
| `/content-graph/apply-link` | POST | Apply accepted link |
| `/content-graph/validate-links` | POST | Validate all internal links |
| `/seo/content-quality/{id}` | GET | Content quality score |
| `/seo/visibility` | GET | Stored search visibility snapshot (DataForSEO) |
| `/seo/visibility/refresh` | POST | Fetch a fresh visibility snapshot — debounced 5 min, spends DataForSEO credit |
| `/seo/opportunities` | GET | Ranked SEO opportunity list, maturity-aware |
| `/seo/flags` | GET | The six act-worthy search signals (`almost_there`, `no_clicks`, `decaying`, `untargeted`, `wrong_intent`, `ai_mode`) computed from imported search performance (#933) |

MCP prompt `seo-loop` walks an agent through `wp_seo_flags` end to end: read the six flags, judge
them, fix before write through approvals/drafts, kill bad keywords, link best pages to stuck ones,
measure with `wp_import_search_performance`, and record decisions with `wp_remember`.

### Site Graph (#943)
| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/graph` | GET | Summary, also the `mcpwp://graph` MCP resource: node counts by kind, edge counts by type, signal sources, orphan total plus the top 10, and `graph_status`. Hard-capped under 2k tokens |
| `/graph/node` | GET | `wp_graph_node`: one node (`node_key` or `post_id`), edge counts by type, latest signals, and a 28-day trend |
| `/graph/neighbors` | GET | `wp_graph_neighbors`: edges and neighbor nodes, `direction` out/in/both, `depth` 1-2 (capped at 200 nodes), `limit` ≤100, `cursor` |
| `/graph/query` | GET | `wp_graph_query`: allowlisted filters (`orphan`, `min_inbound`/`max_inbound`, `has_metric`, `metric{name,source,op,value,window_days}`, `no_edge_type`, `modified_before`, `word_count_lt`), `order_by` label/modified/inbound/metric, `limit` ≤200, `cursor` |
| `/graph/rebuild` | POST | Restart the backfill from the first post (admin scope) |

All three tools are Free, read scope, and in the `content` category (REST `/graph*` too, so a
designer key or a disabled `content` category is refused everywhere). `Mcpwp_Graph_Reader` is the
single visibility chokepoint, and it fails closed by kind. A read-scope key sees published posts
only, judged from the **live** `wp_posts` row (label, url and status are live too). It sees terms
of viewable taxonomies only, and media only for attachments whose parent is public. Any other kind
is visible only with an inbound edge from a public post. Edges touching a hidden node are omitted
rather than redacted. Password-protected posts expose no word count or content links. An invalid
filter value is a 400 `invalid_filter`, never a silently dropped filter. Cursors are encrypted
(sodium secretbox) and bound to their query. Graph reads weigh 5 (query, summary, depth-2
neighbors) or 1 (node, depth-1 neighbors).
Every response carries `graph_status` (ready, coverage, built_at, backfill, dirty_count).
Real-MySQL checks live in `mcpwp/tests/rig/` (see `graph-read-surface-rig.php` and
`graph-read-surface-http.sh`).

### AI Presence & Crawlers
| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/ai-crawlers/policy` | GET/PUT | AI crawler allow/block policy (admin scope to write) |
| `/indexnow/status` | GET | IndexNow key, enabled state, last submission result |
| `/indexnow/submit` | POST | Manually submit URLs to IndexNow |

IndexNow is opt-in (off by default) and always respects `blog_public` (WordPress's "discourage
search engines" setting) and defers to Rank Math's own IndexNow module when that is active. Serving
`/llms.txt` defers whenever Yoast's `enable_llms_txt` option, Rank Math's `llms-txt` module, or a
physical `llms.txt` file at the site root is present — MCPWP only serves its own when none of those
apply (or when forced via settings). See `mcpwp/includes/core/class-mcpwp-indexnow.php` and
`mcpwp/includes/class-mcpwp-ai-presence.php`.

### Approvals & Safety
| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/approvals` | GET/POST | List/create approval requests |
| `/approvals/{id}` | GET | Get approval |
| `/approvals/{id}/approve` | POST | Approve change |
| `/approvals/{id}/reject` | POST | Reject change |
| `/approvals/{id}/apply` | POST | Apply approved change |
| `/approvals/{id}/rollback` | POST | Rollback applied change |

### Webhooks & Events
| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/webhooks` | GET/POST | List/create webhooks |
| `/webhooks/{id}` | GET/PUT/DELETE | Webhook CRUD |
| `/webhooks/{id}/test` | POST | Send test event |
| `/webhooks/{id}/logs` | GET | Webhook delivery logs |
| `/webhooks/events` | GET | Available event types |
| `/events` | GET | Recent MCP events (event store) |
| `/events/schema` | GET | Event payload schemas |

### Design & References
| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/design-references` | GET/POST | List/create design references |
| `/design-references/{id}` | GET/PUT | Design reference CRUD |

### Figma
| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/figma/status` | GET | Connection status, auth mode, default file key |
| `/figma/file` | GET | File summary + outline |
| `/figma/node` | GET | Specific node/frame summary |
| `/figma/design-tokens` | GET | Cached colors + typography from last sync (`mcpwp_figma_design_tokens` option) |
| `/figma/design-tokens/sync` | POST | Pull colors/typography from the file's shared styles and refresh the cache — admin scope required |

### Integrations
| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/integrations/status` | GET | All provider connection status |
| `/integrations/configure` | POST | Save provider API key |
| `/integrations/test` | POST | Test provider connection |
| `/integrations/remove` | POST | Remove provider key |
| `/integrations/stock-photos` | GET | Search stock photos (Pexels) |
| `/integrations/generate-image` | POST | Generate image (OpenAI/Gemini) |
| `/integrations/generate-alt-text` | POST | AI alt text generation |
| `/integrations/text-to-speech` | POST | Text to audio (ElevenLabs) |

### API Keys
| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api-keys` | GET | List all API keys |
| `/api-keys/{id}` | GET/PUT/DELETE | Key CRUD |

### Guides & Playbooks
| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/guides` | GET | Indexed guides |
| `/guides/{topic}` | GET | Topic-specific guide |
| `/workflows/{name}` | GET | Workflow definition |
| `/agent-playbooks` | GET | Agent playbook contracts |

### Staging (Pro)
| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/staging` | GET | Detect host, list staging environments |
| `/staging/push` | POST | Push between environments — destination=live creates approval request (admin scope required) |
| `/staging/status/{id}` | GET | Check async push operation status (WP Engine / Kinsta) |

Supported hosts: WP Engine (REST API), Kinsta (REST API), Pressable (REST API), Generic (WP-CLI fallback).
Configure provider keys under WP Admin > Integrations: `wpengine_staging`, `kinsta_staging`, `pressable_staging`.
Push to live always gates through the approval system — same key cannot approve its own request.

### MCP
| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/mcp` | POST | MCP tool dispatch (all tools via JSON-RPC) — methods: `initialize`, `tools/list`, `tools/call`, `resources/list`, `resources/read`, `prompts/list`, `prompts/get`, `ping` |

A tool execution failure now returns as an `isError: true` `tools/call` result (#936) instead of a
bare JSON-RPC error, so a model reading `content` can see and react to what went wrong. A
programmatic client should read the structured reason from `result.structuredContent.error`, not
parse the human-readable `content` text.

Every tool also carries a top-level `title` and `readOnlyHint`/`destructiveHint`/`openWorldHint`/
`idempotentHint` annotations (#934), with per-tool overrides in
`mcpwp/includes/mcp/class-mcpwp-mcp-tool-annotations.php` and the generated audit table at
`mcpwp/docs/mcp-tool-annotations.md`.

`tools/list` answers under one of two profiles (#907): `core` (the default since #1064) or `full`
(every tool). `core` is ~25 most-used tools, from `Mcpwp_REST_MCP::CORE_TOOL_PROFILE_TOOLS`, plus the meta tools
`mcpwp_find_tools` — keyword search the full catalog, filtered to what the caller can currently
call — and `mcpwp_call` — dispatch any tool by name through the exact same gates as calling it
directly). For a client with a small tool-count budget (Cursor's 40-tool cap; a fixed token
budget). Precedence: `?profile=core|full` query arg on the `/mcp` URL > site setting
(`mcpwp_settings.mcp_tool_profile`, WP Admin > mcpwp > Settings, stored only when chosen) > `core`
(`Mcpwp_REST_MCP::DEFAULT_TOOL_PROFILE`); an unrecognised value at either level falls through rather
than failing the handshake. See `mcpwp/docs/MCP_NATIVE_ENDPOINT.md` for the client-facing writeup.

Tool weight budget (#1064): `tools/list` is slimmed at emission in one place,
`Mcpwp_MCP_Tool_List_Diet` (called from `handle_tools_list()`): descriptions ≤300 chars, property
descriptions ≤120, no #912 `examples`, no duplicate `annotations.title` under 2025-06-18+, plus
hand-written list texts for `core` and the heaviest tools. Registries keep the full definitions —
`mcpwp_find_tools` returns them (with examples), `invalid_arguments` errors carry the first example,
and validation/forgiving params read them. `ToolListBudgetTest` enforces the budgets (core ≤5,000
tokens, any tool ≤450, default `wp_introspect` summary ≤2,000; Free/Pro `full` pinned at measured +5%
until the #1047 consolidation). A new or longer tool that breaks a budget belongs in the diet's maps
or in #1047, not in a raised budget.

Every key may use `mcpwp_find_tools` and `mcpwp_call` BY NAME
(`Mcpwp_Api_Auth::get_key_exempt_tool_names()`, applied in `key_role_permits_tool()` — the one per-key
decision tools/list, tools/call, `mcpwp_call`'s inner call, replay and introspect all use): they only
reach tools the key can already call, and without them `core` stranded pre-#907 custom keys. No key is
ever granted the `meta` CATEGORY: the slug is reserved (`reserved_tool_category_slugs()`), stripped from
stored key categories, absent from roles and the key-setup UI, and an addon tool that claims it
(`mcpwp_register_tools` or an integration) is remapped to `custom` and logged once — otherwise it would
ride along to every key (#1064 review round 2). A site that disables `meta` site-wide gets the `full`
profile (`effective_tool_profile()`), and every "use mcpwp_find_tools" hint is emitted only when both
meta tools are visible to the caller; a category-restricted key's `initialize` instructions name only
tools it can call. `wp_introspect`/`/introspect` (summary, `detail=full`, `tool=`)
and `/onboard?detail=full` list exactly the caller's pre-profile tools/list
(`get_introspection_data_for_caller()`); a hidden `tool=` is the same 404 as a nonexistent one.

Protocol version is negotiated per the spec (#906): `initialize` echoes a supported
`protocolVersion` (`2024-11-05`, `2025-03-26`, `2025-06-18`, `2025-11-25`) and answers the latest for
anything else — the handshake never fails on version. Every later request follows its
`MCP-Protocol-Version` header: none (or empty) means `2025-03-26`, per the spec; an unsupported or
malformed value is an HTTP 400 JSON-RPC error listing the supported versions (the value is not echoed).
An `initialize` in the same JSON-RPC batch wins over the header. The npm proxy (`mcp-server/`) runs its
own `initialize` against WordPress with the version its host negotiated and forwards the answer as the
header on every request after that.

Under `2025-06-18`+ tools carry top-level `title`, and tools with a stable result shape
(`wp_site_info`, `wp_list_pages`, `wp_list_posts`, `wp_get_page_by_slug`) declare an `outputSchema`
and return `structuredContent` beside a compact-JSON text block. Older versions get neither
(`annotations.title` stays). Declare a schema via `define_tool()`'s 4th argument; it is wrapped by
`Mcpwp_MCP_Tool_Registry::with_error_envelope()` (success branch forbids an `error` key; the #936
`structuredContent.error` branch still validates) — a third-party integration's raw `outputSchema`
gets the same wrapping at `tools/list`. Before emitting, `conform_tool_call_result()` validates the
structured copy server-side (`Mcpwp_MCP_Output_Schema`, strict like the client's Ajv — not core's
coercing validator). A result that fails keeps its text and `isError`, is logged once per request,
and carries `structuredContent: {error: {code: "output_schema_mismatch", path}}` instead: the
reference SDK client throws on a missing structuredContent as well as a mismatching one. A top-level
list result is sent as `{"items": [...]}`. `McpProtocolVersionTest` fails until a new schema is
validated against a real result.

## SEO Market Data (DataForSEO)

Two data classes, deliberately in separate stores:

- **Visibility** (`Mcpwp_SEO_Visibility`) — where the site ranks in the market. Position, volume,
  difficulty. From DataForSEO.
- **Performance** (`Mcpwp_Search_Performance`) — traffic actually received. Clicks, impressions,
  CTR. Search Console only; still import-only until the broker OAuth work lands.

They are not interchangeable. DataForSEO cannot supply clicks or impressions, and `position` means
an impression-weighted average in Search Console but a scraped SERP rank in DataForSEO. Merging
them produces a number nobody can interpret.

`Mcpwp_SEO_Opportunities::rank()` adapts its signal to site maturity — `greenfield` (no rankings),
`emerging` (ranks, but deep), `mature` (page-2 positions). Striking distance is a score multiplier,
never an entry condition: a site ranking only at positions 64-91 must still get a ranked list.

Credentials are BYO via WP Admin > Integrations > DataForSEO (login + password, HTTP Basic).
Nothing runs on a timer — every lookup is an explicit user or agent action.

## Elementor Data Format

See `docs/ELEMENTOR_WIDGET_REFERENCE.md` for the complete widget key reference.

### Structure
```
section → column(s) → widget(s)       # Classic layout
container → container(s) → widget(s)  # Flexbox layout (check elementor_layout_mode)
```

### Critical Rules
1. **Every element MUST have an `id`** of exactly 7 alphanumeric characters — Elementor Pro's Search and Taxonomy Filter/Loop Grid AJAX reject any other length (400 `widget_id`, silent on the front end). The plugin auto-generates missing ids, warns about non-conforming ones, and rewrites them only with `repair_ids=true` on `wp_patch_elementor` / `wp_set_elementor`
2. **Use correct widget control keys** — wrong keys silently break rendering (plugin warns now)
3. **Sections need `_column_size`** on columns (must sum to 100)
4. **Multi-column sections** need `"structure": "20"` (2-col), `"30"` (3-col), `"40"` (4-col)

### Minimal Page Example
```json
[{
  "id": "sec0001", "elType": "section",
  "settings": {"background_background": "classic", "background_color": "#FFFFFF"},
  "elements": [{
    "id": "col0001", "elType": "column",
    "settings": {"_column_size": 100},
    "elements": [{
      "id": "wid0001", "elType": "widget", "widgetType": "heading",
      "settings": {"title": "Hello World", "header_size": "h1", "align": "center"}
    }]
  }]
}]
```

### Save Response (v1.0.57+)
```json
{
  "success": true,
  "warnings": [
    "section[0].column[0].widget[0]: unknown widget 'headng' (did you mean 'heading'?)",
    "section[0]: auto-generated missing ID",
    "container[1].column[0]: 'column' should not be nested inside 'container'"
  ],
  "debug": {
    "validation_fixes": ["..."],
    "validation_warnings": ["..."],
    "meta_verified": true
  }
}
```

## Version Bumping

**Do not bump versions by hand.** release-please owns all six artefacts and moves them together:
`.release-please-manifest.json`, `mcpwp/VERSION`, `mcpwp/mcpwp.php` (header + `MCPWP_VERSION`),
`mcpwp/readme.txt` (`Stable tag`), `version.json`, `mcpwp/CHANGELOG.md`. Hand-editing any one of them
desynchronises the set — that is how the repo ended up with a manifest on 3.6.0 while every other file
said 3.7.0.

Just write Conventional Commits and merge the release PR — with `versioning: always-bump-patch`,
every commit type bumps the patch digit (`3.14.N`), not just `fix:`.
See [Release a New Version](#release-a-new-version) for the publish steps and the traps.

## Analytics (v2.8.45+)

Server-side MCP tool usage tracked via PostHog when enabled.

- **Hook:** `do_action('mcpwp_tool_called', $tool, $category, $duration_ms, $error_code)` fired at every return point in `handle_tools_call`
- **Class:** `Mcpwp_Analytics` — `on_tool_called()`, `capture()`, `get_site_uuid()`, `is_enabled()`
- **Config:** PostHog token/host via WP Admin > Integrations > PostHog
- **Toggle:** WP Admin > Settings > "Share anonymous tool-usage data"
- **Free tier:** off by default (opt-in). **Paid tier:** on by default (opt-out)
- **UUID:** `mcpwp_site_uuid` in wp_options — random, never reveals site domain
- **Event:** `mcp_tool_called` with `tool`, `category`, `success`, `duration_ms`, `error_code`, plugin/WP/PHP version
- **Error codes (enum):** `''` success, `tool_not_found`, `category_disabled`, `scope_denied`, `execution_error`, `pro_required` (v3.2.2+: Pro tool called on Free install — upsell telemetry), `rate_limited` (3.11: refused over budget, nothing ran), `replayed` (3.11: an idempotent re-send served from the stored result — counted as `success: true`, it is the feature's happy path), `spend_budget_exceeded` (3.11.1: the call was priced over this request's spend budget and refused whole; nothing ran), `spend_budget_exhausted` (3.11.1: an operation priced at dispatch no longer fit; it and every later operation in the request did not run), `invalid_params` (#911: a missing required param, or an unknown top-level argument key with no confident/safe near-miss mapping, refused before dispatch — nothing ran; #907 reuses this for mcpwp_call's own "missing name"/stray-argument-key refusals and mcpwp_find_tools' missing-query refusal, rather than adding a near-duplicate code), `recursion_denied` (#907: mcpwp_call refused to wrap itself or mcpwp_find_tools). A tool whose route answers with a REST error reports that error's `code`; `tool_error` when it carries none.

## Admin Pages

| Page Slug | Title | Purpose |
|-----------|-------|---------|
| `mcpwp` | Setup | MCP endpoint config, API key generation, role-scoped permissions |
| `mcpwp-control-room` | Control Room | Approval requests, SEO issues inbox, event log, rollback-ready changes |
| `mcpwp-chat` | Chat | AI assistant interface with authenticated site operations |
| `mcpwp-library` | Library | Design references browser, reusable templates/parts |
| `mcpwp-integrations` | Integrations | Provider key management, connection testing, service status |
| `mcpwp-tools` | Tools | Tool category toggles, capability discovery, enforcement state |
| `mcpwp-settings` | Settings | Activity logging, rate limiting, analytics toggle, log retention |
| `mcpwp-activity-log` | Activity Log | API request history, MCP tool execution audit trail |

## Integration Providers

| Provider | Tier | Fields | Capabilities |
|----------|------|--------|--------------|
| OpenAI | Pro | `api_key` | image generation, vision, text |
| Google Gemini | Pro | `api_key` | vision, text |
| ElevenLabs | Pro | `api_key` | text-to-speech |
| Pexels | Free | `api_key` | stock photos |
| Screenshot Worker | Free | `url`, `token` | page screenshots (Cloudflare Browser Rendering) |
| Google Indexing API | Pro | `service_account_json` | submit URLs to Google index |
| Figma | Pro | `personal_access_token`, `oauth_client_id`, `oauth_client_secret`, `default_file_key` | design files, colors, typography |
| PostHog | Free/Pro | `token`, `host` | server-side MCP tool analytics |
| Jev (TypeSafe) | Free | `api_key`, `endpoint` (advanced override) | optional approval-queue triage ranking (bring-your-own key, billed to the site owner directly); routes only — never approves, rejects, applies, or blocks anything |

Config stored per-provider in `mcpwp_integrations` wp_option (encrypted). Manage via WP Admin > Integrations or `/integrations/configure` endpoint.

## WP Options Keys

| Key | Purpose |
|-----|---------|
| `mcpwp_api_keys` | Stored API key hashes |
| `mcpwp_api_key` | Single key (legacy) |
| `mcpwp_api_keys_last_used` | Last-used timestamp per scoped key id (#1090). NOT autoloaded, on purpose (#1096): core's `update_option()` on an autoloaded option force-loads and then sets the `alloptions` cache entry, which also holds the key store, so a stalled touch could write a pre-revoke key store back over a revoke on a persistent cache. Written at most once a minute per key; pruned (best effort) when a key is revoked or rotated. Never written into the key store itself |
| `mcpwp_api_keys_lock` | Transient lock row (insert-claim, 10 s TTL) taken by `Mcpwp_Key_Store::mutate()`, the write path for `mcpwp_api_keys` used by create, revoke, rotate, the legacy-key migration, the spai key import and the deny-all categories migration (#1096). The store is read from the database row and written by compare-and-swap, so a stalled holder whose lock was stolen cannot overwrite a newer write; a write that cannot be made fails closed. It also refuses a value the `option_value` column cannot hold (a latin1 table would silently truncate the row at the first unencodable character), rolls back a write that does not read back whole, and refuses to build on a corrupt store, which also stops the legacy/spai fallbacks from authenticating; `Mcpwp_Key_Store_Health` reports that state (Site Health "critical" plus an admin notice on every admin screen). The activator's first-key mint and legacy migration (`ensure_api_keys()`, which runs at every plugin update) skip an unreadable store and otherwise write through `mutate()` |
| `mcpwp_version` | Installed plugin version |
| `mcpwp_db_version` | DB schema version |
| `mcpwp_settings` | Plugin settings array (analytics_enabled, logging, etc.) |
| `mcpwp_rate_limit_settings` | Rate limit config |
| `mcpwp_site_context` | Site brief markdown (set by AI or admin) |
| `mcpwp_site_context_updated` | Last context update timestamp |
| `mcpwp_design_references` | Stored design reference library |
| `mcpwp_integrations` | Provider API keys (encrypted) |
| `mcpwp_disabled_tool_categories` | Tool category toggles |
| `mcpwp_figma_design_tokens` | Cached Figma colors/typography tokens (synced hourly via cron + on-demand via `/figma/design-tokens/sync`); read directly by companion theme projects |
| `mcpwp_update_info` | Update manifest override (leave empty unless overriding) |
| `mcpwp_version_url` | Update check URL (default `updates.mcpwp.net`; legacy sites may still hold the mumega.com manifest URL — both serve the same R2 bucket) |
| `mcpwp_site_uuid` | Anonymous site UUID for analytics (`mcpwp-<uuid4>`) |
| `mcpwp_first_activation` | Initial activation timestamp |
| `mcpwp_approvals_identity_migrated` | One-time flag: on upgrade, purge identity-less pending/approved approvals so a reconnected OAuth principal can't self-approve a pre-fix request |
| `mcpwp_approvals_table_migrated` | One-time flag (#1042): the approvals queue was copied from the old `mcpwp_approval_requests` option into the `{prefix}mcpwp_approvals` table |
| `mcpwp_approval_requests_pre_3149` | The old approvals option, renamed by that migration and kept (autoload off) for recovery by hand; a later release removes it |

## WP Hooks

### Actions (do_action)
| Hook | Args | Purpose |
|------|------|---------|
| `mcpwp_tool_called` | `$tool, $category, $duration_ms, $error_code` | Fired at every MCP tool exit point — analytics subscriber |
| `mcpwp_register_rest_routes` | — | Fired after plugin routes registered — add custom routes |
| `mcpwp_event_emitted` | `$event_type, $payload` | Fired when approval/SEO/webhook event is stored |
| `mcpwp_fs_loaded` | — | Freemius SDK loaded |

### Filters (apply_filters)
| Hook | Args | Purpose |
|------|------|---------|
| `mcpwp_bypass_api_key_check` | `false, $request` | Return `true` to skip API key validation for a request |
| `mcpwp_event_payload` | `$payload, $event_type` | Modify event data before storage |
| `mcpwp_integrations` | `$providers` | Register custom integration providers |
| `mcpwp_site_capabilities` | `$caps` | Add custom site capabilities to site-info response |

## Plugin Namespaces

| Plugin | REST Namespace |
|--------|---------------|
| MCPWP | `mcpwp/v1` |
| DigID Marketing Manager | `digid/v1` |

## Operations & growth docs

- [`docs/INTAKE.md`](docs/INTAKE.md) — intake process (#940)
- [`docs/growth/NORTH-STAR.md`](docs/growth/NORTH-STAR.md) — growth north star (#958)
- [`docs/launch/directories/`](docs/launch/directories/) — per-directory submission notes (#968)

**Task truth is GitHub issues + the org project board**
(https://github.com/orgs/Mumega-com/projects/2), fields `Version`, `Priority`, `Track`, `Size`.
Linear is not used for MCPWP engineering. The one exception: video and media orders to the
owner's video team go into Linear (see `docs/plan/PLAN.md` §Media).
