# MCPWP - WordPress MCP Plugin

> 📍 **Start at [`TRUTH.md`](TRUTH.md)** for project topology, naming, and decisions (mumcp = product · mcpwp = media). This file = plugin internals.

## Local Development (Primary)

### Local WordPress

**Use `wp-rig-wp-dev`. It is the only container that mounts this plugin.**

```
WP-CLI:   docker exec wp-rig-wp-dev wp <command> --allow-root
PHPUnit:  docker exec wp-rig-wp-dev bash -c 'cd /var/www/html/wp-content/plugins/mcpwp && vendor/bin/phpunit --configuration tests/phpunit.xml'
Mount:    wp-ai-operator/mcpwp -> /var/www/html/wp-content/plugins/mcpwp
```

Edits to `wp-ai-operator/mcpwp/` are live instantly there. No deploy needed.

**`wp-test-wordpress-1` does NOT mount this plugin.** It mounts `site-pilot-ai` and
`site-pilot-ai-pro` — the pre-rename directories — and carries a stale, unmounted copy of `mcpwp`
under its own volume. Commands run against it silently exercise old code: a file added to
`mcpwp/` today is simply absent there. This doc previously named it as the live-mounted dev
container, which cost a task real debugging time. Verify with:

```bash
docker inspect wp-rig-wp-dev --format '{{range .Mounts}}{{.Source}} -> {{.Destination}}{{"\n"}}{{end}}'
```

There is no PHP on the host — every PHP or WP-CLI command must run inside the container.

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
curl -s http://localhost:8080/wp-json/mcpwp/v1/site-info -H "X-API-Key: $KEY" | jq

# Create page + push Elementor data
PAGE=$(curl -s -X POST http://localhost:8080/wp-json/mcpwp/v1/pages \
  -H "X-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{"title":"Test","status":"draft"}' | jq -r .id)

curl -s -X POST "http://localhost:8080/wp-json/mcpwp/v1/elementor/$PAGE" \
  -H "X-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{"elementor_data":"[{\"id\":\"abc\",\"elType\":\"section\",\"settings\":{},\"elements\":[{\"id\":\"def\",\"elType\":\"column\",\"settings\":{\"_column_size\":100},\"elements\":[{\"id\":\"ghi\",\"elType\":\"widget\",\"widgetType\":\"heading\",\"settings\":{\"title\":\"Hello\"}}]}]}]"}' | jq
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
| `mcpwp-X.Y.Z.zip` | No | — | WordPress.org submission |
| `mcpwp-agency-X.Y.Z.zip` | Always on | None needed | Agency/client installs |
| `mcpwp-freemius-X.Y.Z.zip` | Via key | Freemius | Paid users |

Agency build injects `MCPWP_PRO=true` at build time — no wp-config.php changes, no license prompt. Includes self-update channel (`updates.mcpwp.net`).

Download latest agency build: `https://updates.mcpwp.net/mcpwp-agency-3.3.3.zip`

### Release a New Version

**release-please owns versioning — never hand-bump.** Land Conventional Commits on `main`
(`fix:` → patch, `feat:` → minor); release-please opens/updates a release PR that bumps all six
version artefacts, writes the changelog, tags, and creates the GitHub Release when a human merges it.

Merge the release PR (`chore(main): release X.Y.Z`) → tags `vX.Y.Z`. release-please then publishes
automatically (`publish-canonical-channel` job): it uploads `version.json` + the self-hosted ZIP +
the agency ZIP (`mcpwp-agency-X.Y.Z.zip`) to the `mumcp-updates` R2 bucket — which backs both
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
2. Falls back to `mcpwp_version_url` option or hardcoded mumega.com manifest URL
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

- Canonical release sources are the `mumega.com` static manifest and ZIP
- A stale `mcpwp_update_info` can block newer releases — leave it empty unless intentionally overriding
- `mcpwp_version_url` on all sites should point to `https://mumega.com/mcp-updates/version.json`
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
├── version.json                               # Self-update manifest (mumega.com serves this)
└── scripts/
    ├── build-wporg.sh                         # Build WordPress.org free package
    ├── build-freemius.sh                      # Build Freemius paid package
    ├── publish_update_release.sh              # Build + publish to mumega.com static + R2
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

## Elementor Data Format

See `docs/ELEMENTOR_WIDGET_REFERENCE.md` for the complete widget key reference.

### Structure
```
section → column(s) → widget(s)       # Classic layout
container → container(s) → widget(s)  # Flexbox layout (check elementor_layout_mode)
```

### Critical Rules
1. **Every element MUST have an `id`** (8 char alphanumeric) — plugin auto-generates if missing
2. **Use correct widget control keys** — wrong keys silently break rendering (plugin warns now)
3. **Sections need `_column_size`** on columns (must sum to 100)
4. **Multi-column sections** need `"structure": "20"` (2-col), `"30"` (3-col), `"40"` (4-col)

### Minimal Page Example
```json
[{
  "id": "sec1", "elType": "section",
  "settings": {"background_background": "classic", "background_color": "#FFFFFF"},
  "elements": [{
    "id": "col1", "elType": "column",
    "settings": {"_column_size": 100},
    "elements": [{
      "id": "w1", "elType": "widget", "widgetType": "heading",
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

Just write Conventional Commits (`fix:` → patch, `feat:` → minor) and merge the release PR.
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
- **Error codes (enum):** `''` success, `tool_not_found`, `category_disabled`, `scope_denied`, `execution_error`, `pro_required` (v3.2.2+: Pro tool called on Free install — upsell telemetry)

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

Config stored per-provider in `mcpwp_integrations` wp_option (encrypted). Manage via WP Admin > Integrations or `/integrations/configure` endpoint.

## WP Options Keys

| Key | Purpose |
|-----|---------|
| `mcpwp_api_keys` | Stored API key hashes |
| `mcpwp_api_key` | Single key (legacy) |
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
| `mcpwp_version_url` | Update check URL (→ mumega.com manifest) |
| `mcpwp_site_uuid` | Anonymous site UUID for analytics (`mcpwp-<uuid4>`) |
| `mcpwp_first_activation` | Initial activation timestamp |
| `mcpwp_approvals_identity_migrated` | One-time flag: on upgrade, purge identity-less pending/approved approvals so a reconnected OAuth principal can't self-approve a pre-fix request |

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
