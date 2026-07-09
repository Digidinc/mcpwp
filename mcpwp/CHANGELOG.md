# Changelog

All notable changes to MCPWP will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [3.4.6](https://github.com/Mumega-com/mcpwp/compare/v3.4.5...v3.4.6) (2026-07-09)


### Bug Fixes

* **security:** restrict non-public content reads to write-scoped keys ([#601](https://github.com/Mumega-com/mcpwp/issues/601)) ([ea27690](https://github.com/Mumega-com/mcpwp/commit/ea2769065bb8c1430ac9f78de1490087abb737bd))

## [3.4.5](https://github.com/Mumega-com/mcpwp/compare/v3.4.4...v3.4.5) (2026-07-09)


### Bug Fixes

* **security:** require admin scope to bypass content-approval gate ([172fd28](https://github.com/Mumega-com/mcpwp/commit/172fd2803cfa0fa48c7907e4b8c6e2c08cb01f4b))
* **security:** require admin scope to bypass content-approval gate ([b27591b](https://github.com/Mumega-com/mcpwp/commit/b27591bb83de625eea5f916b834140dc7a34eb81))

## [3.4.4](https://github.com/Mumega-com/mcpwp/compare/v3.4.3...v3.4.4) (2026-07-08)


### Bug Fixes

* **seo:** stop /seo/audit fatal and per-post self-fetch storm ([9a60782](https://github.com/Mumega-com/mcpwp/commit/9a607829f60cfe0d73a30336b135754b4c0706be))
* **seo:** stop /seo/audit fatal and per-post self-fetch storm ([c4c4ec5](https://github.com/Mumega-com/mcpwp/commit/c4c4ec55d94fa8fcda75289ec1923faf0f6bc3da))

## [3.4.3](https://github.com/Mumega-com/mcpwp/compare/v3.4.2...v3.4.3) (2026-07-07)


### Bug Fixes

* **security:** harden REST/MCP scope matrix and self-approval gate ([3959a13](https://github.com/Mumega-com/mcpwp/commit/3959a13ec7304d8cba0003e87627ba66279f1549))

## [3.4.2](https://github.com/Mumega-com/mcpwp/compare/v3.4.1...v3.4.2) (2026-07-05)


### Bug Fixes

* **security:** block secret option stores from /option and admin-gate /plugin-settings ([094e472](https://github.com/Mumega-com/mcpwp/commit/094e472e3fe3f0bf7467869d4ad1e4b2d11d9178))
* **security:** block secret option stores from /option and admin-gate /plugin-settings ([3b30a37](https://github.com/Mumega-com/mcpwp/commit/3b30a37960769cb6c2ba31c94d215314758ea107))

## [Unreleased]

## [3.4.1]

### Security
- require admin scope for plugin self-update and update-control options; restrict update package URLs to HTTPS + trusted hosts (fixes write-scope→RCE chain)

## [3.4.0]

### Added
- GET /openapi.json — generates OpenAPI 3.0 schema from free-tools registry; enables ChatGPT GPT Actions + any OpenAPI consumer

## [3.3.9]

### Fixed
- staging tools (wp_get_staging_info etc.) now appear in tools/list on all plans — non-Pro calls return a clear upgrade prompt instead of silently being absent
- MCP server instructions include a Pro-features-not-active section when site is unlicensed, listing staging/SEO/WooCommerce as gated features with upgrade URL

## [3.3.8]

### Added
- Pro staging feature — WP Engine, Kinsta, Pressable adapters + Generic WP-CLI fallback
- REST endpoints GET /staging, POST /staging/push, GET /staging/status/{id}
- 4 MCP tools: wp_get_staging_info, wp_push_to_staging, wp_push_to_live, wp_get_staging_status
- push-to-live gates via approval request; actual push fires only after wp_apply_approval
- three integration providers added to Integration Manager (wpengine_staging, kinsta_staging, pressable_staging)

### Security
- SSRF mitigated — all host API calls use redirection=0; env IDs validated against adapter list_environments() before use; shell exec uses allowlisted binary path + escapeshellarg()

## [3.3.7]

### Added
- MCP resources/list returns 6 real site resources (site-info, site-context, pages, posts, design-references, elementor-status); resources/read proxies to internal mcpwp/v1 REST endpoints with API key forwarding
- MCP prompts/list returns 5 named prompt templates (audit-seo, build-page, refresh-content, onboard, design-match); prompts/get fills in arguments and returns messages[] array; both declared in initialize capabilities

## [3.3.6]

### Added
- wp_list_media gains search param (filter by title/filename); tool description updated to guide image-reuse workflow (check before uploading)

### Fixed
- wp_upload_media_b64 tool schema now exposes mime_type param (REST already accepted it; MCP tools/list was missing it)
- wp_update_menu_item tool description and error messages now include actionable hints — get menu_id from wp_list_menus, item_id from wp_list_menu_items; errors name the missing ID and the tool to call
- wp_upload_design_reference tool description and core missing_image error now include retry guidance — if image_url/image_base64 fails, upload separately then pass media_id

## [3.3.5]

### Added
- before/after change summary on wp_update_page and wp_update_post — response now includes changes[] (field, before, after) and unchanged[] so agents know exactly what shifted without a follow-up fetch
- structural_changes on wp_set_elementor — sections_before/after and widgets_before/after in every save response

### Security
- sslverify=false in Elementor CSS-prime loopback request fixed to respect WP_DEBUG

## [3.3.4]

### Security
- SSRF redirect bypass in wp_get_rendered_copy fetcher — redirection=0 was missing from the get_rendered_copy wp_remote_get call; redirect chains could bypass the same-host allowlist

## [3.3.3]

### Added
- mutation tool schema docs — wp_create_page/wp_update_page now document parent, template, menu_order; wp_create_post/wp_update_post now document categories and tags; all descriptions clarify Elementor vs Gutenberg usage (#569)

### Security
- SSRF redirect bypass — wp_get_rendered_copy, wp_get_rendered_html, and SEO validator fetchers now pass redirection=0 to prevent redirect chains from bypassing the same-host allowlist

## [3.3.2]

### Added
- wp_get_rendered_copy MCP tool — returns structured visible text (headings, paragraphs, button labels, list items, link text, image alts) from the live rendered page; ground-truth answer to "what does the page actually say?" (#569)

### Security
- sslverify=false in wp_get_rendered_html fixed — TLS enforced in production, relaxed only when WP_DEBUG is true

## [3.3.1]

### Added
- wp_flush_cache MCP tool flushes WordPress page cache after bulk edits; supports W3 Total Cache, WP Super Cache, LiteSpeed, WP Rocket, Cache Enabler, Breeze, and Cloudflare (#568)
- Elementor write tools (add_section, remove_section, replace_section, patch_elementor) now surface validation_warnings in response — matches existing set_elementor convention (#571)
- /onboard response includes suggested_first_tools context-aware list (Elementor vs Gutenberg path) and quick_start guidance string (#574)

### Security
- batch sub-request rate-limit bypass replaced — X-SPAI-Batch-Sub-Request HTTP header (externally spoofable) replaced with Mcpwp_REST_Batch::$is_batch_sub_request static property (#573)

## [3.3.0]

### Added
- render-truth across all validators — every MCPWP validator now reports validation_basis and validation_note so agents know whether issues were verified against rendered HTML or stored data (#569)
- validate_structured_data fetches live rendered page for published posts; renders JSON-LD injected by SEO plugins (Yoast/AIOSEO/@graph); suppresses false "no_structured_data" when plugin handles schema (#569)
- validate_internal_links now walks Elementor widget data (_elementor_data) alongside post_content — links in buttons, icon-boxes, and text-editor widgets are validated for broken targets, self-links, and non-canonical URLs; anchor-quality checks skip Elementor button links (#569)
- audit_seo_site exposes validation_basis per URL and a site-level validation_note counting how many URLs were render-verified (#569)
- validate_blocks response includes validation_basis: stored_block_data + note that dynamic/server-side blocks may render differently (#569)

### Fixed
- apply_internal_link duplicate-check now includes Elementor data — prevents false "duplicate link" skips on Elementor pages where post_content is empty (#569)

## [3.2.9]

### Added
- validate_seo_readiness now fetches the actual rendered <head> for published pages and reconciles issues against ground truth — false "missing meta description" and "noindex" issues caused by SEO plugin template rendering are suppressed (#569)
- rendered_head field in readiness response returns actual title, description, canonical, robots, og:title, og:description from the live page (#569)
- validation_basis field ("rendered_html" vs "stored_meta") and validation_note explain whether issues were verified against rendered output or stored meta (#569)
- rendered_noindex_detected issue fires when <meta name="robots"> in live <head> contains noindex even when stored meta doesn't (#569)

## [3.2.8]

### Fixed
- SEO readiness meta-description check now detects AIOSEO's excerpt fallback — pages with no stored per-post description no longer incorrectly warn "missing meta description" when AIOSEO renders one from the excerpt (#569)
- meta-description validation skips length check when value is an unrendered template string (Yoast/AIOSEO template tags); meta_description_source field added to readiness response (#569)
- apply_internal_link now checks Elementor widget data for existing links before inserting — prevents duplicate-link approval requests on Elementor pages where post_content is empty (#569)

## [3.2.7]

### Added
- wp_bulk_upload_media tool — import up to 20 images from URLs in one request; use instead of looping wp_upload_media_from_url for migrations (#566)

### Fixed
- wp_upload_media_from_url description now advises using bulk tool for 5+ images to stay under burst rate limit (#566)

## [3.2.6]

### Fixed
- REST Pro gate now enforces real license check — no more stub bypass (#564)
- remove dead hostname routing from agency proxy (latent Host-spoof surface) (#547)
- wp_rate_limit_status now shows real usage for API-key-authenticated requests (#533)

## [3.2.5]

### Fixed
- deduplicate Elementor element IDs on save to prevent CSS bleed across pages (#565)
- MCP internal tool dispatch no longer double-counts rate limit, fixing concurrency errors (#566)
- custom CSS set_custom_css warns on HTML-escaped operators, returns compact response, clarifies verification semantics (#567)

## [3.2.4]

### Security
- Encryption key is now stored in a dedicated WordPress option (mcpwp_encryption_key) instead of being derived from AUTH_SALT. AUTH_SALT rotation no longer silently corrupts stored provider credentials. A one-time non-destructive migration re-encrypts existing blobs on first load.
- Admin AJAX pro-gate bypass is now restricted to verified admin users with activate_plugins capability, confirmed nonce, and an explicit action allowlist.

## [3.2.3]

### Changed
- screenshots now save to the media library and return a URL by default — inline base64 (500KB+) overflowed AI-client contexts. Pass `inline=true` for raw base64.
- `wp_get_elementor` no longer duplicates the page data as a raw JSON string (`elementor_json`) — payload halved on large pages. Pass `include_raw=true` if you need the raw string.

## [3.2.2]

### Added
- Calling a Pro tool on a Free install now returns a clear upgrade-path error (JSON-RPC -32004 `pro_required` with plan and pricing link) instead of "unknown tool".

### Changed
- `/introspect` is compact by default (tool name + summary, ~80% smaller) so AI clients no longer overflow their context; pass `?full=true` for the legacy payload with full schemas.

### Fixed
- `/option` now refuses to overwrite an array/object option with a plain string (decodes valid JSON; `force_type_change=true` to override) — prevents accidental destruction of structured options such as Elementor theme-builder conditions.
- numeric values in API responses are returned as JSON numbers (analytics counts, Elementor `page_id`) for typed clients.

## [3.2.1]

### Changed
- Internal rebrand cleanup (spai→mcpwp) — cron label, code comments, and admin JS object renamed. Webhook deliveries now also emit `X-MCPWP-Signature`, `X-MCPWP-Event`, and `X-MCPWP-Webhook-ID` headers alongside the existing `X-SPAI-*` headers (both sets sent; `X-SPAI-*` retained for backward-compat). MCP tools/list response now also includes an `x_mcpwp` envelope key alongside `x_spai`. Bridge migration of legacy sites is unchanged.

## [3.2.0]

### Added
- Minimal OAuth 2.1 authorization server — MCP clients (Claude Desktop, ChatGPT) can now authenticate via browser sign-in instead of API keys. Adds RFC 9728 protected-resource metadata, RFC 8414 authorization-server metadata, PKCE S256 authorize endpoint, and token exchange (authorization_code + refresh_token). X-API-Key authentication is unchanged and unaffected. Requires `oauth_enabled` setting + allow-listed redirect URIs to activate.

### Fixed
- `wp_recall` tool PHP 8.x compatibility (#534).

## [3.1.0]

### Added
- Seamless migration bridge — sites upgrading from the earlier `site-pilot-ai` build carry over all content, API keys, OAuth access tokens, plugin settings, and licensing entitlement automatically. Existing API keys keep authenticating during and after the change; migration is idempotent and non-destructive (original data is left intact for rollback).
- Dual-prefix authentication — legacy keys and OAuth tokens continue to work alongside new `mcpwp_` credentials, with original scopes preserved.
- `Mcpwp_Migrate`: idempotent one-time option migration that copies all known `spai_*` options to their `mcpwp_*` equivalents on `plugins_loaded` and on activation. Non-destructive (originals kept), non-overwriting (skips existing `mcpwp_*` values), guarded by `mcpwp_migrated_from_spai` flag. Includes encryption sanity-check for `spai_integrations` blob with admin-visible warning if `AUTH_SALT` changed (provider keys must be re-entered).
- `find_legacy_spai_key()` in `Mcpwp_Api_Auth` trait: dual-prefix API key auth that accepts keys stored under `spai_api_keys` / `spai_api_key` so existing AI connections continue to work through the slug-rename cutover without re-keying. Uses the same `wp_check_password()` / `hash_equals()` path as the existing key lookup — no timing difference.
- `docs/BRIDGE-505-DESIGN.md`: slug-handover option analysis (Option A/B/C) with recommendation for architect + mumcp-warden review.
- `BridgeMigrateTest.php` / `BridgeAuthTest.php`: phpunit coverage for both.

### Changed
- WordPress.org Plugin Check clean (0 errors) — the WordPress.org build makes no third-party network calls and ships under GPL-2.0.
- per-site cutover runbook and verified MCP client connect matrix added.

## [3.0.1]

### Fixed
- signals fatal on every compute — `compute_seo_issues()` was calling the private `get_issues()` method and filtering on severity `critical` (which the store never writes). Now uses the public `list_issues(status=open, severity=error)`.
- `GET /signals` now lazily computes on first read — closes the permanently-empty feed on hosts where WP-Cron never fires (the mcpwp.net symptom). Response includes `last_computed`, `partial`, and `skipped_types`.
- `compute_pending_updates()` no longer calls `wp_update_plugins()` (remote requests to wordpress.org per plugin) on web requests — reads cached transient instead; network refresh is cron-only.
- Elementor broken-page checker fetches IDs only, then reads `_elementor_data` blobs one at a time — prevents large memory spikes.

### Changed
- `compute()` accepts a time budget; over-budget signal types are skipped with their stored values preserved and reported via `mcpwp_signals_meta` (`last_computed`, `skipped_types`, `partial`).
- Freemius is now the single source of truth for entitlement — removed legacy Lemon Squeezy store and self-started 14-day local trial that could independently grant Pro. New canonical `get_license_info()` accessor guarantees `plan` and `is_pro` never contradict.
- split four monolithic classes (REST Site 4531 lines, free/pro tool files, Admin 3491 lines, Elementor Basic 3299 lines) into focused per-surface controllers and trait groups — no behavior change.

### Security
- gateway tool-registration contract enforced — unknown tools rejected at `tools/call`, escalation vectors closed.

## [3.0.0]

### Changed
- Rebrand: the plugin is now **MCPWP** throughout — folder, main file, class names, function/option prefixes, constants, text domain, and REST namespace (`mcpwp/v1`). A clean reinstall is recommended; settings and API keys are re-created fresh.

## [2.8.31] - 2026-05-20

### Fixed
- Register Freemius update refresh hooks through WordPress directly so plugin and update-core screens clear stale update checks.
- Provide a CLI-safe host fallback before Freemius SDK initialization to avoid `HTTP_HOST` warnings during WP-CLI activation and update checks.

## [2.2.2] - 2026-04-01

### Changed
- Admin Library copy now makes the storage model explicit: SPAI archetypes and reusable parts are Elementor templates with SPAI metadata layered on top.

### Fixed
- Preserve existing archetype metadata on partial updates when only Elementor content changes.
- Preserve existing reusable-part metadata on partial updates when only Elementor content changes.
- Carry forward the `2.2.1` shared-host-safe Elementor mutation path and operator workflow improvements.

## [2.2.1] - 2026-04-01
### Added
- Shared-host-safe `elementor_data_base64` support for pro Elementor template, archetype, and part create/update routes.

### Changed
- API and guide docs now describe HostGator / ModSecurity-safe Elementor mutation patterns using form-encoded and base64 payloads.

### Fixed
- Reduce shared-host request-body incompatibilities that blocked archetype and part management on live sites protected by WAF rules.

## [2.2.0] - 2026-04-01

### Added
- Operator-focused admin polish with onboarding, update recovery, Library health, lineage counts, and drill-down asset views.
- Design-reference-driven draft generation from the admin Library, including automatic reusable-part creation and linkage.

### Changed
- README and plugin readme now position MCPWP as a reusable AI production system for WordPress operators.
- Library workflow guidance now better reflects site character, archetypes, parts, and design-reference usage.

### Fixed
- Carry forward the `2.1.0` feature set, including design references, structured archetypes, guided site character, Figma integration, updater hardening, and Elementor 4 save persistence verification.

## [2.1.0] - 2026-04-01

### Added
- Image-based design references so uploaded screenshots and mockups can be stored as reusable site assets.
- MCP and REST tools for storing, listing, reading, and updating design references.
- A structured `build_from_design_reference` workflow for turning uploaded design images into local pages, archetypes, and reusable parts.

### Changed
- Media and onboarding guidance now tells models to preserve approved screenshots as design references before building.

### Fixed
- Carry forward the `2.0.0` feature set, including archetypes, guided site character, Figma integration, updater hardening, and Elementor 4 save persistence verification.

## [2.0.0] - 2026-04-01

### Added
- Structured site-building primitives with reusable Elementor parts, page archetypes, and WooCommerce product archetypes.
- Guided site character authoring, public `llms.txt` output, and AI-facing context inheritance for page and product archetypes.
- Figma integration with personal token and OAuth configuration, plus REST and MCP design intake tools.
- Library and Integrations admin improvements for curating archetypes, parts, and design connections.

### Fixed
- Carry forward updater hardening and Elementor 4 save persistence verification from the `1.8.x` series.

## [1.8.6] - 2026-03-31

### Changed
- Test release to validate live auto-update from `1.8.5` with the filesystem-aware updater.

### Fixed
- Carry forward updater filesystem initialization, diagnostics, manifest hardening, and Elementor 4 save verification from `1.8.5`.

## [1.8.5] - 2026-03-31

### Fixed
- Initialize the WordPress filesystem before self-updates and surface actionable upgrader errors when plugin installs fail.
- Carry forward updater manifest selection hardening and Elementor 4 save persistence verification from `1.8.4`.

## [1.8.4] - 2026-03-31

### Changed
- Test release to validate end-to-end WordPress self-update from the canonical `mumega.com` manifest and ZIP.

### Fixed
- Carry forward updater hardening and Elementor 4 save persistence verification from `1.8.3`.

## [1.0.43] - 2026-02-07

### Fixed
- Pro MCP tools now unlock based on active license (single-plugin distribution), not a separate Pro add-on.

## [1.0.42] - 2026-02-07

### Added
- MCP tools for Elementor Theme Builder templates (get/create/update/delete).

## [1.0.41] - 2026-02-07

### Added
- MCP tools for Elementor Theme Builder templates (get/create/update/delete).

## [1.0.40] - 2026-02-07

### Added
- `wp_introspect` MCP tool and `/introspect` REST endpoint so AI clients can discover tools, auth, and capabilities automatically.

### Changed
- Use `activate_plugins` for WP Admin access checks so all administrators can access the plugin screens on managed hosts.

## [1.0.39] - 2026-02-07

### Changed
- Switch Freemius integration to single-plugin distribution (no premium zip required after checkout).
- Allow Freemius `Account`/`Pricing` pages for administrators even if `manage_options` is restricted by the host.

## [1.0.38] - 2026-02-07

### Fixed
- Ensure Freemius `Account` and `Pricing` admin pages are registered under the plugin menu to prevent "Sorry, you are not allowed to access this page." on managed hosts.

## [1.0.37] - 2026-02-07

### Added
- Include license status in the `/site-info` API response so clients can detect Pro state.

## [1.0.36] - 2026-02-07

### Fixed
- Treat Freemius plan names case-insensitively so Agency/Pro plans correctly mark the site as Pro.
- Hide upgrade/Go Pro CTAs in plugin action links when Pro is active (including Freemius-injected links).

## [1.0.35] - 2026-02-07

### Added
- Alerting via existing SPAI webhooks on error spikes (5xx, 401/403).
- Privacy controls for logging: disable storing `response_data`, configurable redaction keys.
- Dashboard widget showing recent API activity.

## [1.0.34] - 2026-02-07

### Added
- WP Admin `Activity Log` page for traceability (request actions, endpoints, status codes, redacted details).

## [1.0.33] - 2026-02-07

### Fixed
- Improve RankMath detection and report LearnPress capability.

### Added
- Admin-scoped MCP tools for reading options (homepage), menus, generic content listing, and CPT deletion.

## [1.0.32] - 2026-02-07

### Changed
- Release automation update.

## [1.0.31] - 2026-02-07

### Changed
- Release automation update.

## [1.0.30] - 2026-02-06

### Changed
- Switched to single-plugin distribution (no separate `mcpwp-premium` packaging). Pro stays license-gated.

## [1.0.29] - 2026-02-06

### Fixed
- Ensure Freemius updates include Pro module files by shipping `includes/pro/**` in both free + premium zips and gating Pro loading by license.

## [1.0.28] - 2026-02-06

### Fixed
- Correctly detect premium vs free mode in Freemius SDK (`is_premium`) so premium installs receive premium updates (and Pro modules ship).

## [1.0.27] - 2026-02-06

### Added
- Bundled all Pro endpoints into the premium package (SEO, Forms, Themes, Users, Widgets, WooCommerce, Multilang) with no separate Pro plugin install.

## [1.0.26] - 2026-02-06

### Added
- Bundled Elementor Pro endpoints into the premium package (no separate Pro plugin install).

## [1.0.25] - 2026-02-06

### Fixed
- Prevented premium activation fatal when both `mcpwp` and `mcpwp-premium` are installed at the same time.
- Restored Freemius premium-version configuration and detect premium/free mode from the plugin directory name.

## [1.0.24] - 2026-02-06

### Fixed
- Disabled Freemius premium-version auto swap to prevent checkout activation crashes on installs that use separate Pro plugin packaging.

## [1.0.23] - 2026-02-06

### Fixed
- Switched Freemius product configuration to premium-version mode (`has_premium_version: true`, `has_addons: false`) to match non-add-on distribution.

## [1.0.22] - 2026-02-06

### Fixed
- Prevented activation/deletion fatals when `mcpwp_fs()` exists but does not return a valid Freemius instance.
- Guarded Freemius SDK hook registration (`add_filter`/`add_action`) behind runtime instance checks.
- Hardened license helper methods to safely handle unavailable Freemius instance methods.

## [1.0.21] - 2026-02-06

### Fixed
- Prevented activation/deletion fatals when `mcpwp_fs()` exists but does not return a valid Freemius instance.
- Guarded Freemius SDK hook registration (`add_filter`/`add_action`) behind runtime instance checks.
- Hardened license helper methods to safely handle unavailable Freemius instance methods.

## [1.0.20] - 2026-02-06

### Added
- MCP tool annotations for safety hints: `readOnlyHint`, `openWorldHint`, `destructiveHint`
- New MCP tools: `wp_search` and `wp_fetch`
- New REST endpoints: `/search`, `/fetch`, and `/oauth/token` (client credentials grant)
- OAuth settings in admin: enable toggle, client ID, client secret (hashed), token TTL
- ChatGPT readiness documentation:
  - `docs/CHATGPT_CONFORMANCE.md`
  - `docs/CHATGPT_APP_SUBMISSION.md`
- ChatGPT/MCP conformance script: `tests/test-chatgpt-conformance.sh`

### Changed
- Cloudflare MCP transport adapter now supports configurable response mode (`auto`, `json`, `sse`)
- MCP notification handling in worker transport now returns `204` with empty body

### Security
- OAuth bearer tokens now enforce existing read/write/admin scope checks through API auth flow

## [1.0.18] - 2026-02-06

### Added
- Scoped API key lifecycle management: create, list, and revoke keys with metadata (`created_at`, `last_used_at`, `revoked_at`)
- API key scope enforcement (`read`, `write`, `admin`) across REST routes and MCP tool calls
- MCP tools for API key operations: `wp_list_api_keys`, `wp_create_api_key`, `wp_revoke_api_key`
- Development quality pipeline with CI checks for PHP syntax lint, coding standards (tests), and PHPUnit
- PHPUnit scaffolding and baseline tests for auth, rate limiter, and MCP endpoint behavior

### Security
- Legacy plaintext API keys are now force-hashed during migration into scoped key storage
- API key regeneration now revokes previous active scoped keys before issuing a new primary key

## [1.0.17] - 2026-02-06

### Security
- Identity-aware rate limiting: valid keys get `key:<hash>` bucket, invalid attempts get `invalid:<IP>` bucket
- Removed admin user fallback in `set_api_user_context()` — returns 500 if `mcpwp_api_agent` user missing instead of silently operating as admin
- Removed `?api_key=` query parameter authentication — prevents key leakage in URLs, server logs, and Referer headers
- SSRF protection added to media upload-by-URL endpoint via `Mcpwp_Security::validate_external_url()`
- Webhook URLs re-validated at send time to defend against DNS rebinding attacks
- Webhook delivery: timeout reduced to 15s, redirects disabled (`redirection => 0`), SSL enforced

### Fixed
- Rate limiter sliding window bug: `set_transient()` TTL now uses remaining window time instead of full 60/3600s (was extending window on every request)
- Rate limiter `remaining` count prevented from going negative with `max(0, ...)`
- Rate limiter window data properly validated and reset when expired or malformed

### Added
- `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset` headers on all SPAI REST responses
- `Retry-After` header on 429 rate-limit responses
- MCP `resources/list` handler (returns empty resources array)
- MCP `resources/read` handler (returns resource-not-found error)
- MCP `resources` capability advertised in `initialize` response
- `is_api_key_match()` extracted method for cleaner auth validation

## [1.0.16] - 2026-02-06

### Fixed
- Freemius premium activation fatal error — switched from `has_premium_version` to `has_addons` architecture
- Test Connection button now works reliably — bypasses internal REST dispatch which doesn't carry API key headers
- Pro plugin admin hook corrected from `tools_page_mcpwp` to `toplevel_page_mcpwp`

### Changed
- Tested up to WordPress 6.9.1

## [1.0.15] - 2026-02-06

### Security
- Removed `manage_options` and `edit_theme_options` from `mcpwp_api_agent` role (principle of least privilege)
- Added SSRF protection: `Mcpwp_Security::validate_external_url()` blocks private/reserved IPs on webhooks and media upload
- API keys now generated with `bin2hex(random_bytes(24))` — 192 bits of cryptographic randomness
- MCP batch requests capped at 10 per call (was unlimited)
- CORS now respects `allowed_origins` setting; wildcard only when unconfigured
- Webhook delivery: explicit `sslverify => true`, redirects disabled (`redirection => 0`)
- Elementor data validated for max size (5MB) and nesting depth (30 levels)
- Migration: existing installs auto-strip `manage_options` from API role on activation

### Added
- New `Mcpwp_Security` utility class for SSRF and payload validation
- Native MCP endpoint (`POST /wp-json/mcpwp/v1/mcp`) — direct Claude Desktop/Code connection
- Top-level admin menu with SVG icon (moved from buried Tools submenu)
- Tabbed admin interface: Setup, Connect AI, Settings, Advanced
- One-click Test Connection button with AJAX site-info check
- Copy-paste AI config guides: Claude Desktop, Claude Code, ChatGPT
- First-activation welcome banner with visible API key and copy button
- License/Upgrade card on Settings tab for Freemius Pro activation
- `do_action('mcpwp_admin_settings_cards')` hook for Pro extensions
- ChatGPT OpenAPI spec (`docs/openapi-chatgpt.yaml`, 17 endpoints)
- Cloudflare Worker MCP handler for remote MCP connections
- npm MCP server package with `--setup`, `--version`, `--test` CLI flags

### Fixed
- Fatal error: `log_activity()` access level conflict in MCP class (private vs protected inheritance)
- MCP activity logging: corrected column names to match `wp_mcpwp_activity_log` schema
- Freemius `first-path` aligned with new top-level menu (`admin.php?page=mcpwp`)
- MCP namespace consistency: all components use `mcpwp/v1`
- Admin page hook: `toplevel_page_mcpwp` (was `tools_page_...`)
- MCP tool count: 30 tools (removed 6 non-existent endpoint mappings)

## [1.0.14] - 2026-02-05

### Security
- API keys now hashed using `wp_hash_password()` instead of plain text storage
- New `mcpwp_api_agent` role with limited capabilities (not full admin)
- New `mcpwp_bot` service account for handling API requests
- API key shown only once after regeneration, then masked in UI
- Legacy plain-text keys auto-migrate to hashed on first API request
- Freemius SDK private method calls wrapped in try-catch

### Fixed
- Corrected `is_premium` flag to `false` for free version
- Improved backward compatibility for existing installations

## [1.0.13] - 2026-02-05

### Changed
- Switched plugin updates from GitHub to Freemius
- Removed custom GitHub updater class
- Removed `uninstall.php` (using Freemius `after_uninstall` hook instead)

### Added
- Transient and cron cleanup in Freemius uninstall hook

### Removed
- `class-mcpwp-updater.php`
- GitHub token settings from admin

## [1.0.12] - 2026-02-04

### Changed
- Updated Freemius SDK integration with correct configuration
- Renamed `mcpwp_fs()` to `mcpwp_fs()` per Freemius conventions
- Added multisite network support (`WP_FS__PRODUCT_23824_MULTISITE`)

### Added
- 14-day trial configuration with payment requirement
- Custom connect message for opt-in screen

## [1.0.11] - 2026-02-04

### Added
- Freemius SDK integration for licensing and updates
- License management abstraction layer (`Mcpwp_License` class)
- Upgrade banner in admin dashboard
- Support for Pro, Agency plans via Freemius

### Changed
- License checking now uses unified interface (supports Freemius or custom backend)

## [1.0.0] - 2024-01-01

### Added
- Initial release
- REST API with 14 endpoints
- Posts CRUD operations (create, read, update, delete)
- Pages CRUD operations
- Media upload (file and URL)
- Draft management (list and bulk delete)
- Basic Elementor support (get/set page data, create Elementor page)
- API key authentication
- Activity logging with configurable retention
- Admin settings page
- Plugin detection for Elementor, SEO plugins, form plugins, WooCommerce
- WordPress.org compliant readme.txt
- Internationalization support
- Clean uninstall

### Security
- CSRF protection with nonces
- Capability checks on all admin actions
- Input sanitization using WordPress functions
- Output escaping
- Secure API key generation
- Rate limiting ready architecture
