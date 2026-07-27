=== MCPWP ===
Contributors: mumega
Donate link: https://mcpwp.net/
Tags: ai, claude, mcp, elementor, api
Requires at least: 6.1
Tested up to: 7.0
Requires PHP: 7.4
<!-- x-release-please-start-version -->
Stable tag: 3.10.0
<!-- x-release-please-end -->
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect WordPress to Claude and other MCP clients for safe AI-assisted posts, pages, media, drafts, and Elementor basics.

== Description ==

MCPWP connects your WordPress site to AI assistants like Claude using the Model Context Protocol (MCP). It gives approved AI clients a scoped, auditable way to read site context and help manage core WordPress content through natural language.

The WordPress.org package is the free core: API keys, scoped access, activity logging, posts, pages, media, drafts, menus, site context, and basic Elementor operations when Elementor is installed.

= Key Features =

* **Secure MCP Access** - Connect MCP-capable clients with hashed API keys and scoped permissions
* **Content Management** - Create, edit, and delete posts and pages
* **Media Handling** - Upload files or import media from URLs
* **Draft Management** - List and clean up drafts
* **Menus and Site Context** - Read site structure and basic navigation data
* **Basic Elementor Support** - Read and update Elementor page data when Elementor is installed
* **Activity Log** - Audit API and MCP activity from wp-admin
* **Tool Controls** - Disable tool categories that should not be exposed to agents
* **MCP Compatible** - Works with Claude Code, Claude Desktop, and other MCP-compatible clients

= How It Works =

1. Install and activate the plugin
2. Copy your API key from MCPWP in the admin menu
3. Configure your MCP server with the API key
4. Review enabled tool categories and scopes
5. Start creating drafts and managing WordPress content with natural language

= Example Commands =

* "Create a blog post about summer recipes"
* "Create a draft landing page for our spring campaign"
* "List recent drafts and delete the unused ones"
* "Upload this image and set it as the featured image for post 123"
* "Read the site context before updating the About page"

== Installation ==

= From WordPress Admin =

1. Go to Plugins → Add New
2. Search for "MCPWP"
3. Click Install Now, then Activate
4. Go to MCPWP in the admin menu to get your API key

= Manual Installation =

1. Download the plugin ZIP file
2. Go to Plugins → Add New → Upload Plugin
3. Select the ZIP file and click Install Now
4. Activate the plugin
5. Go to MCPWP in the admin menu to get your API key

= MCP Server Setup =

**Claude Desktop** (Settings → Connectors → Add custom connector) — enable OAuth in the plugin settings and paste the plain MCP URL; Claude opens your WordPress login to authorize, so no key is stored in the client:

`https://yoursite.com/wp-json/mcpwp/v1/mcp`

Prefer an API key? Claude Desktop's claude_desktop_config.json only supports command-based servers, so route the key through the mcp-remote bridge, which sends it as a header:

`npx mcp-remote https://yoursite.com/wp-json/mcpwp/v1/mcp --header "X-API-Key:mcpwp_your_api_key_here"`

(A `?api_key=...` query string on the connector URL also works but writes the key into server/proxy access logs — prefer OAuth or the header method above.)

**Claude Code, Cursor, Windsurf** — add to the client's MCP config:

`{
  "mcpServers": {
    "mcpwp": {
      "type": "http",
      "url": "https://yoursite.com/wp-json/mcpwp/v1/mcp",
      "headers": {
        "X-API-Key": "mcpwp_your_api_key_here"
      }
    }
  }
}`

The Setup page in wp-admin shows these snippets pre-filled with your site URL and key, per client.

For ChatGPT GPT Actions, use the public OpenAPI schema at `https://yoursite.com/wp-json/mcpwp/v1/openapi.json`.

== Frequently Asked Questions ==

= What is MCP? =

Model Context Protocol (MCP) is an open protocol that enables AI assistants like Claude to interact with external tools and services. MCPWP exposes your WordPress site as an MCP-compatible tool.

= Is this secure? =

Yes. All requests require a unique API key. Keys are hashed using WordPress password hashing (not stored in plain text). A dedicated service account with limited capabilities handles API requests. Activity logging tracks all API usage for auditing.

= Does it work with any AI? =

MCPWP works with any MCP-compatible client: Claude Code, Claude Desktop, Cursor, Windsurf, and others. For ChatGPT, use the built-in OpenAPI schema (`/openapi.json`) to connect via GPT Actions.

= Do I need coding skills? =

No. Once configured, you control WordPress through natural language. The AI handles all the technical details.

= What about Elementor? =

Basic Elementor page read/write support is included when Elementor is installed. Advanced Elementor Pro and theme-builder workflows are not part of the WordPress.org free package.

= Who is this for? =

MCPWP is best for site owners, developers, agencies, and content teams that want AI assistants to help operate WordPress while keeping API keys, scopes, and activity logs under site-owner control.

= Does it support Elementor 4? =

Yes. MCPWP includes fallback handling for Elementor page data saves.

= Can I use this on multiple sites? =

Each site needs its own plugin installation and API key.

== Privacy ==

MCPWP can send anonymous usage data from your WordPress server to PostHog when the "Share anonymous tool-usage data" setting is enabled in WP Admin > MCPWP > Settings.

**What is collected:** Names of MCP tools called, whether each call succeeded or failed, execution duration, plugin version, WordPress version, and PHP major/minor version.

**What is NOT collected:** Post content, page content, user data, API keys, site URL, or any personally identifiable information.

**Identifier:** Each site is assigned a random site UUID (e.g. mcpwp-xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx). This UUID is stored in wp_options and cannot be traced back to a domain name or individual.

**Where data is sent:** PostHog, Inc. (posthog.com). Your server's outbound IP address is transmitted as part of the HTTP request to PostHog.

**Default state:** Disabled for free tier (requires opt-in). Enabled by default for paid subscribers (can be disabled at any time).

**How to opt out:** Uncheck "Share anonymous tool-usage data" under WP Admin > MCPWP > Settings.

== Screenshots ==

1. Control Room — supervised approvals, SEO findings, rollback-ready changes, and recommended next actions
2. Setup — MCP endpoint, client configuration, and role-scoped API key management
3. Tools — category controls for agent capability discovery and execution enforcement
4. Integrations and Chat — connected services plus safety-first agent workflow

== Changelog ==

= 3.10.0 =
* feat: **seo:** market data via DataForSEO — visibility, opportunities, and an agent loop ([#730](https://github.com/Mumega-com/mcpwp/issues/730))

= 3.9.3 =
* fix: **security:** granular tool-category gate on all auth paths, fail-closed OAuth scopes ([#700](https://github.com/Mumega-com/mcpwp/issues/700))

= 3.9.2 =
* fix: **release:** publish and watch the Freemius channel like the self-hosted one ([#726](https://github.com/Mumega-com/mcpwp/issues/726))

= 3.9.1 =
* fix: **update:** guard Mcpwp_Updater use on builds that prune it ([#724](https://github.com/Mumega-com/mcpwp/issues/724))

= 3.9.0 =
* feat: **mupot:** read-first WordPress connector — status + task board in Control Room ([#715](https://github.com/Mumega-com/mcpwp/issues/715)) ([8a06c11](https://github.com/Mumega-com/mcpwp/commit/8a06c11593f62c7de1b873d4d79ed09f014ed71c)), closes [#689](https://github.com/Mumega-com/mcpwp/issues/689)

= 3.8.0 =
* feat: **updater:** make update-channel failures loud at every layer ([#719](https://github.com/Mumega-com/mcpwp/issues/719))
* fix: tool schema item types ([#707](https://github.com/Mumega-com/mcpwp/issues/707)) and staging environment detection ([#710](https://github.com/Mumega-com/mcpwp/issues/710)) ([#713](https://github.com/Mumega-com/mcpwp/issues/713))
* fix: **updater:** resolve updates from the plugin manifest, not the WP.org transient ([#718](https://github.com/Mumega-com/mcpwp/issues/718))

= 3.7.2 =
* fix: **security:** fold route case before every authorization decision ([#711](https://github.com/Mumega-com/mcpwp/issues/711))

= 3.7.1 =
* feat: **admin:** redesign admin UI to Claude Design Control Room mockup (3.6.5)
* feat: allow extensions to raise custom MCP tool scope ([#654](https://github.com/Mumega-com/mcpwp/issues/654))
* feat: **broker-plugin:** cached introspection validator (fail-closed, audience-checked, kill-switch)
* feat: **broker-plugin:** claude.ai/ChatGPT OAuth sign-in via mumcp.com broker (v3.7.0)
* feat: **broker-plugin:** enrollment callback + confirm endpoints (site-control proof)
* feat: **broker-plugin:** enrollment lifecycle — connect/state/confirm/store/disconnect + Connections UI
* feat: **broker-plugin:** point .well-known/oauth-protected-resource at mumcp.com when enrolled
* feat: **broker-plugin:** validate broker Bearer tokens in verify_api_key() (read|write, never admin) + 401 discovery header
* feat: **cloudflare:** cloudflare integration — bot-fix, cache purge, speed/CWV signals ([#632](https://github.com/Mumega-com/mcpwp/issues/632))
* feat: **elementor:** idempotent, named Kit CSS blocks ([#645](https://github.com/Mumega-com/mcpwp/issues/645))
* feat: **elementor:** validate Atomic Elements props against real schema + document the feature
* feat: **integrations:** self-service Cloudflare Bot Fight Mode fix in wp-admin
* feat: **seo:** classify deprecated schema.org rich-result types ([#615](https://github.com/Mumega-com/mcpwp/issues/615))
* feat: **seo:** experimental opt-in GEO/AI-citability score ([#617](https://github.com/Mumega-com/mcpwp/issues/617))
* feat: **seo:** schema-placement check + explicit E-E-A-T rubric ([#616](https://github.com/Mumega-com/mcpwp/issues/616))
* feat: support trusted custom tool callbacks ([#657](https://github.com/Mumega-com/mcpwp/issues/657))
* fix: **broker-plugin:** host-lock enroll-store issuer/introspection_endpoint to mumcp.com (prevent credential-exfil poisoning)
* fix: **broker-plugin:** introspect only as fallback after local key + rate-limit anonymous bearer attempts (no key egress, no DoS)
* fix: **broker-plugin:** public state-gated enroll-callback + pin audience to home_url + pin introspection URL + no public log-amplification
* fix: **ci:** scope proxy-worker audit to runtime deps + bump hono ([#692](https://github.com/Mumega-com/mcpwp/issues/692))
* fix: **elementor:** accept create-side param names on archetype/part list tools ([#640](https://github.com/Mumega-com/mcpwp/issues/640))
* fix: **elementor:** correct destructive annotations and elementor_data round-trip typing ([#642](https://github.com/Mumega-com/mcpwp/issues/642))
* fix: **elementor:** stop silent data loss on section/container/atomic layout mismatches
* fix: **freemius:** soft-gate first activation instead of hard license wall ([#646](https://github.com/Mumega-com/mcpwp/issues/646))
* fix: **license:** close reconnect-link render-time gap + notify on blocked updates
* fix: **license:** detect wrong-package downgrade + warn loudly ([#656](https://github.com/Mumega-com/mcpwp/issues/656))
* fix: **license:** reconnect flow no longer forces skip-mode (found the actual bug)
* fix: **license:** reconnect Freemius when a site is stuck anonymous/pending ([#633](https://github.com/Mumega-com/mcpwp/issues/633))
* fix: **license:** update vendored Freemius SDK 2.13.0 -&gt; 2.13.4
* fix: **license:** use Freemius's install-linked checkout URL, not marketing site
* fix: **mcp:** draft rendered-copy 404/502 + expose unlicensed-reason code
* fix: menu taxonomy contract, AIOSEO persistence, draft rendered-copy (3.6.2) ([#682](https://github.com/Mumega-com/mcpwp/issues/682))
* fix: **oauth:** discovery docs broken on subdirectory installs + widen CF fix
* fix: **oauth:** serve consent screen off the REST API so browser sign-in works ([#695](https://github.com/Mumega-com/mcpwp/issues/695))
* fix: **pages:** support excerpt on wp_create_page/wp_update_page ([#697](https://github.com/Mumega-com/mcpwp/issues/697))
* fix: **release:** release_freemius.sh never defined MCPWP_FREEMIUS_BUILD
* fix: **rendered-html:** render draft/private Elementor content in-process, not via loopback ([#644](https://github.com/Mumega-com/mcpwp/issues/644))
* fix: **security:** block secret option stores from /option and admin-gate /plugin-settings
* fix: **security:** block secret option stores from /option and admin-gate /plugin-settings
* fix: **security:** close draft/private content disclosure to read-scoped API keys ([#683](https://github.com/Mumega-com/mcpwp/issues/683))
* fix: **security:** harden feedback-relay rate limiting and Figma OAuth callback ([#649](https://github.com/Mumega-com/mcpwp/issues/649))
* fix: **security:** harden REST/MCP scope matrix and self-approval gate
* fix: **security:** harden REST/MCP scope matrix and self-approval gate
* fix: **security:** require admin scope to bypass content-approval gate
* fix: **security:** require admin scope to bypass content-approval gate
* fix: **security:** restrict Elementor globals writes to an allowlist and admin-gate kit CSS ([#603](https://github.com/Mumega-com/mcpwp/issues/603))
* fix: **security:** restrict non-public content reads to write-scoped keys ([#601](https://github.com/Mumega-com/mcpwp/issues/601))
* fix: **seo:** 3.6.4 — readiness false-positives, screenshot save_to_media, setup-page connect clarity ([#685](https://github.com/Mumega-com/mcpwp/issues/685))
* fix: **seo:** stop /seo/audit fatal and per-post self-fetch storm
* fix: **seo:** stop /seo/audit fatal and per-post self-fetch storm

= 3.7.0 =
* new: **connect:** claude.ai and ChatGPT can now sign into your site over OAuth via the mumcp.com broker — WP Admin → MCPWP → Setup → "Connect to mumcp.com". No per-site OAuth server: the sign-in, Dynamic Client Registration, and consent are hosted once on mumcp.com; your site validates the resulting tokens by cached introspection.
* new: **connect:** Connections UI with one-click Connect / Disconnect. Disconnect is a local kill-switch — it rejects all broker tokens instantly with no network call.
* security: broker tokens are capped at read/write scope — **admin capability is never grantable over OAuth** and stays on the deliberate `X-API-Key` path. Token validation is fail-closed, audience-bound (a token minted for another site is refused), and the per-site introspection credential is stored encrypted and never leaves the site in a browser response.
* note: Claude Desktop / Cursor / Windsurf are unchanged — they keep connecting via `X-API-Key`.

= 3.6.5 =
* new: **admin:** redesigned admin UI — warm cream/antique-gold palette, Newsreader/Instrument Sans/IBM Plex Mono typography, and refreshed cards, stat tiles, badges, and buttons across Setup, Control Room, Chat, Library, Integrations, Tools, and Settings. Visual only — no endpoints, data, or JS behavior changed.
* new: **control-room:** pending approvals now show a "View before / after" disclosure with the change's existing before/after excerpt, so a reviewer can see what an approval actually changes without leaving the queue.

= 3.6.4 =
* fix: **seo:** `wp_validate_seo_readiness` no longer reports a sitewide robots.txt block when the site only disallows sub-paths (e.g. `/wp-admin/`, WooCommerce cart URLs). It now parses robots.txt by user-agent group and flags a root block only on an exact `Disallow: /` ([#680](https://github.com/Mumega-com/mcpwp/issues/680))
* fix: **seo:** readiness checks (H1, word count, headings, image alts) now source from the live rendered page for server-rendered template pages whose stored content is empty, instead of falsely reporting `missing_h1` / `thin_content` ([#681](https://github.com/Mumega-com/mcpwp/issues/681))
* fix: **media:** `wp_screenshot_url` now honors an explicit `save_to_media=false` instead of always creating a media item when `inline` is off ([#677](https://github.com/Mumega-com/mcpwp/issues/677))
* new: **setup:** the Setup page now separates the two Claude Desktop connect methods — "API key · works today" (with a treat-this-link-as-a-secret warning) and "OAuth sign-in · more secure · coming soon via mumcp.com" — so the working configuration is unmistakable

= 3.6.3 =
* fix: **security:** close a content-disclosure gap where a read-scoped API key could read or enumerate non-public (draft, pending, private) content — titles, URLs, bodies, excerpts, and SEO/approval metadata — across the Elementor, blocks, pages, posts, SEO, content-graph, approvals, and signals read surfaces. Non-public content now requires write scope, enforced at a shared model chokepoint so no read endpoint can reopen the gap ([#683](https://github.com/Mumega-com/mcpwp/issues/683))

= 3.6.2 =
* fix: **menus:** `wp_add_menu_item` / `wp_create_menu` now honor the documented `taxonomy` and `post_type` item types (with `object` + `object_id`) instead of silently storing a blank custom link; `wp_create_menu` reports per-item failures instead of returning a partially-broken menu ([#676](https://github.com/Mumega-com/mcpwp/issues/676))
* fix: **seo:** `wp_set_seo` (AIOSEO) now persists the meta description and robots flags reliably — sets `robots_default` so per-post noindex/nofollow take effect, reads the description from AIOSEO's table in readiness checks, and returns an explicit error instead of a false success when a field does not persist ([#672](https://github.com/Mumega-com/mcpwp/issues/672))
* fix: **content:** `wp_get_rendered_copy` renders unpublished drafts in-process (Elementor or classic/Gutenberg) instead of failing with a loopback `fetch_error`, so agents can audit copy before publishing ([#675](https://github.com/Mumega-com/mcpwp/issues/675))
* test: lock a tool-name-stability invariant so no registration path can leak a hash-suffixed tool name ([#673](https://github.com/Mumega-com/mcpwp/issues/673))

= 3.6.1 =
* fix: **oauth:** discovery documents (`/.well-known/oauth-authorization-server`, `/.well-known/oauth-protected-resource`) only worked for a WordPress install at the domain root — a subdirectory install (e.g. `/staging/5707`) never matched, so OAuth-based clients (Claude, ChatGPT) trying to connect via browser sign-in could never discover the auth server. Now matches both the RFC 8414 §3.1 insertion form and a plain suffix form, and `issuer`/`resource` correctly include the site's own path
* new: **integrations:** Cloudflare's "Fix MCP Access" rule now also covers the `.well-known/oauth-*` discovery paths, not just the REST API prefix — confirmed live that Cloudflare's challenge page was blocking OAuth discovery on a real customer site even after the REST-only rule was applied

= 3.6.0 =
* feat: allow extensions to raise custom MCP tool scope ([#654](https://github.com/Mumega-com/mcpwp/issues/654))
* feat: **cloudflare:** cloudflare integration — bot-fix, cache purge, speed/CWV signals ([#632](https://github.com/Mumega-com/mcpwp/issues/632))
* feat: **integrations:** self-service Cloudflare Bot Fight Mode fix in wp-admin
* feat: support trusted custom tool callbacks ([#657](https://github.com/Mumega-com/mcpwp/issues/657))
* fix: **freemius:** soft-gate first activation instead of hard license wall ([#646](https://github.com/Mumega-com/mcpwp/issues/646))
* fix: **license:** close reconnect-link render-time gap + notify on blocked updates
* fix: **license:** detect wrong-package downgrade + warn loudly ([#656](https://github.com/Mumega-com/mcpwp/issues/656))
* fix: **license:** reconnect flow no longer forces skip-mode (found the actual bug)
* fix: **license:** update vendored Freemius SDK 2.13.0 -&gt; 2.13.4
* fix: **license:** use Freemius's install-linked checkout URL, not marketing site
* fix: **mcp:** draft rendered-copy 404/502 + expose unlicensed-reason code
* fix: **release:** release_freemius.sh never defined MCPWP_FREEMIUS_BUILD
* fix: **security:** harden feedback-relay rate limiting and Figma OAuth callback ([#649](https://github.com/Mumega-com/mcpwp/issues/649))

= 3.5.8 =
* fix: **build:** the actual root cause of every "unlicensed"/"no_freemius_sdk" report this cycle — scripts/release_freemius.sh (the script used to upload every real release to Freemius) built its zip with the freemius/ SDK folder physically present but never defined the MCPWP_FREEMIUS_BUILD constant that mcpwp.php's own bootstrap requires to actually load it. Every zip Freemius served to real customers since 3.5.1 had the SDK on disk but dead code — license would never activate no matter what was reinstalled. Fixed to inject the constant identically to build-freemius.sh; verified by unzipping the corrected build and confirming the define is present.

= 3.5.7 =
* new: **integrations:** "Fix MCP Access (Bot Fight Mode)" button on the Cloudflare integration card — self-service Cloudflare WAF/Bot Fight Mode fix for AI-client requests, previously only reachable via the `wp_cloudflare_fix_mcp_access` MCP tool or a raw REST call. Any customer with the Cloudflare integration configured can now fix this themselves in wp-admin, no per-site manual dashboard rule-writing needed

= 3.5.6 =
* fix: **license:** closed a gap in the 3.5.5 reconnect fix — the displayed "Manage pricing and license" link itself still got `require_license=false` baked into its href at render time (a separate moment from the two already covered), silently reintroducing the same stuck loop. Fixed and live-verified end-to-end from a clean state.
* new: **license:** admin notice when a newer version exists but Freemius can't push it to this site because it's anonymous/pending/unlicensed — read-only version check against the canonical manifest, never offers an install action (that would require the self-hosted zip, which has no Freemius SDK)

= 3.5.5 =
* fix: **license:** "Manage pricing and license" (reconnect after Skip) actually lets you enter a license again — the soft-gate default from 3.4.x forced `require_license=false` onto Freemius's own reconnect redirect too, so clicking it always landed back on the skip-oriented connect screen with no visible way to enter a license. Live-verified: connect screen now renders with `requireLicenseKey: true` and a real license-key field after reconnecting

= 3.5.4 =
* fix: **license:** detect the specific case behind #656 — a site that shows residual Freemius account data (`fs_accounts`) for this product but has no Freemius SDK currently loaded reports `reason: freemius_removed_had_license` (not just `no_freemius_sdk`), plus a loud admin notice pointing at the fix: reinstall the Pro package from your Freemius account

= 3.5.3 =
* fix: **rendered-copy:** `wp_get_rendered_copy` (`GET /rendered-copy`) now renders private/draft/pending Elementor content in-process, same as `wp_get_rendered_html` — was fetching the public/preview URL and getting a host 404/coming-soon page on unpublished content ([#655](https://github.com/Mumega-com/mcpwp/issues/655))
* fix: **license:** expose a safe `reason` code (`wporg_build`, `no_freemius_sdk`, `anonymous`, `pending_activation`, `no_active_license`) when a site reports unlicensed, without exposing the key — a previously-Pro site silently losing entitlement had no visible cause ([#656](https://github.com/Mumega-com/mcpwp/issues/656))

= 3.5.2 =
* fix: **license:** update vendored Freemius SDK 2.13.0 &rarr; 2.13.4 &mdash; includes an upstream checkout-context fix where raw request params could override computed checkout params (a plausible cause of "pay with coupon does nothing")

= 3.5.1 =
* fix: **license:** Upgrade/pricing links now use Freemius's own install-linked checkout URL instead of a plain marketing-site link — a purchase made on the marketing site had no install context, so it never synced back to the plugin (license appeared stuck unactivated even after payment)

= 3.5.0 =
* feat: **elementor:** idempotent, named Kit CSS blocks ([#645](https://github.com/Mumega-com/mcpwp/issues/645))
* feat: **elementor:** validate Atomic Elements props against real schema + document the feature
* feat: **seo:** classify deprecated schema.org rich-result types ([#615](https://github.com/Mumega-com/mcpwp/issues/615))
* feat: **seo:** experimental opt-in GEO/AI-citability score ([#617](https://github.com/Mumega-com/mcpwp/issues/617))
* feat: **seo:** schema-placement check + explicit E-E-A-T rubric ([#616](https://github.com/Mumega-com/mcpwp/issues/616))
* fix: **elementor:** accept create-side param names on archetype/part list tools ([#640](https://github.com/Mumega-com/mcpwp/issues/640))
* fix: **elementor:** correct destructive annotations and elementor_data round-trip typing ([#642](https://github.com/Mumega-com/mcpwp/issues/642))
* fix: **elementor:** stop silent data loss on section/container/atomic layout mismatches
* fix: **license:** reconnect Freemius when a site is stuck anonymous/pending ([#633](https://github.com/Mumega-com/mcpwp/issues/633))
* fix: **rendered-html:** render draft/private Elementor content in-process, not via loopback ([#644](https://github.com/Mumega-com/mcpwp/issues/644))
* fix: **security:** block secret option stores from /option and admin-gate /plugin-settings
* fix: **security:** block secret option stores from /option and admin-gate /plugin-settings
* fix: **security:** harden REST/MCP scope matrix and self-approval gate
* fix: **security:** harden REST/MCP scope matrix and self-approval gate
* fix: **security:** require admin scope to bypass content-approval gate
* fix: **security:** require admin scope to bypass content-approval gate
* fix: **security:** restrict non-public content reads to write-scoped keys ([#601](https://github.com/Mumega-com/mcpwp/issues/601))
* fix: **seo:** stop /seo/audit fatal and per-post self-fetch storm
* fix: **seo:** stop /seo/audit fatal and per-post self-fetch storm

= 3.4.17 =
* new: **seo:** /seo/content-quality gains an optional geo_citability_experimental score (include_geo_experimental=true, off by default) — self-contained answer blocks, question-phrased headings, and structured-data presence, explicitly labeled as vendor-observed correlation, not a Google-confirmed signal, and never mixed into the main score. Deliberately does not score total page word count (vendor data found only ~0.04 correlation with citation). Not building an llms.txt generator — Google doesn't consume it. (#614, #617)

= 3.4.16 =
* new: **seo:** /seo/structured-data flags schema_placement_mismatch when FAQPage/HowTo/QAPage schema describes content that can't be found on the page itself — Google's March 2026 eligibility rule requires this schema to describe primary content, not a supplementary/off-topic section. Coarse presence check against the page's own visible text, not a DOM layout analyzer.
* new: **seo:** /seo/content-quality returns an explicit eeat field with Google's 4-factor E-E-A-T rubric (Experience, Expertise, Authoritativeness, Trustworthiness — Search Quality Rater Guidelines, updated Sept 2025), each with its own 0-100 sub-score and the specific evidence found — not one aggregate number. Authoritativeness explicitly discloses it can't check off-site signals from a single page. 15 new regression tests across both checks; full suite green (319 tests, 922 assertions). (#614, #616)

= 3.4.15 =
* new: **seo:** /seo/structured-data now classifies each detected schema @type as active or deprecated_no_rich_result (new rich_result_status field), catching HowTo (retired 2023), FAQPage (retired May 2026), and 5 more types retired June 2025 (ClaimReview, EstimatedSalary, LearningVideo, SpecialAnnouncement, VehicleListing) — confirmed via Google Search Central, not vendor blogs. The markup stays valid; this just tells you it earns no SERP snippet anymore, without recommending deletion. Book/Course deliberately excluded (Book was un-deprecated Nov 2025; Course is too broad a type to flag without false positives). (#614, #615)

= 3.4.14 =
* new: **elementor:** dry_run/wp_set_elementor now validates Atomic Elements props against their real schema (via wp_get_widget_schema) instead of skipping validation entirely — flags unknown prop keys and the #1 real mistake (a plain scalar instead of the required {"$$type","value"} wrapper) before writing. wp_add_section/wp_replace_section/wp_edit_section/wp_edit_widget were already elType-agnostic and work with Atomic Elements as-is.
* new: **docs:** wp_get_guide(topic="elementor") documents Atomic Elements (Editor V4) — layout modes, structure, prop wrapper format, and a worked example — so AI agents know the surface exists and how to use it correctly. Tool descriptions for wp_get_elementor/wp_get_elementor_summary/wp_edit_widget/wp_set_elementor updated to match. (#618, #622, #623)

= 3.4.13 =
* new: **elementor:** wp_get_widget_schema now returns a real, deep schema for Elementor v4 Atomic Elements (props, types, enums, defaults — e.g. a heading's "tag" prop correctly lists h1-h6) instead of an empty tabs list. Built from Elementor's own get_props_schema()/define_props_schema() methods, not guesswork. Covers both atomic leaf widgets (e-heading, e-button, ...) and atomic container elements (e-div-block, e-flexbox, ...), which register through two different Elementor managers — both now resolve correctly instead of "unknown widget" for the container types.

= 3.4.12 =
* fix: **elementor:** wp_get_elementor_summary and the /preview stats endpoint were blind to Elementor v4 Atomic content elements (e-heading, e-button, etc.) — they're self-typed via elType with no widgetType wrapper, so both silently reported zero widgets on atomic pages. Now correctly counted and summarized, including unwrapping the {"$$type","value"} prop format for display.
* fix: **elementor:** wp_edit_widget could not edit any Atomic content element at all (hard-checked elType === 'widget', which atomic leaf elements never are) — now accepts them.
* fix: **elementor:** found and fixed a real silent-data-loss bug live-testing the above — Elementor's own internal HTML-cache-rebuild re-save (part of every wp_set_elementor write) was silently stripping atomic elements nested inside a container while leaving the top-level element count unchanged. The existing post-write integrity check only compared top-level count and missed this; it now does a full recursive comparison and restores the verified data if Elementor's internal save corrupts anything at any depth. (#618, #620)

= 3.4.11 =
* fix: **elementor:** wp_site_info now detects Elementor's Atomic Elements (Editor V4) experiment — elementor_layout_mode reports "atomic" and a new elementor_atomic_active flag is exposed, instead of silently reporting stale section/container state on Atomic-enabled sites.
* fix: **elementor:** wp_set_elementor now rejects atomic element payloads (e-div-block, e-heading, etc.) up front with a clear error when the site's Atomic Elements experiment is inactive, instead of a false success followed by the page silently coming back empty — same failure class as the container/section fix below, closed before it shipped to production. (#618, #619)
* fix: **elementor:** added missing "e-grid" atomic element type to the validator/reader whitelist (was shipped by Elementor after the original #211 atomic support landed).

= 3.4.10 =
* fix: **elementor:** wp_set_elementor now rejects "container" elements up front with a clear error when the site's Elementor Container experiment is inactive (classic "section" layout mode), instead of reporting a false success followed by the page silently coming back empty. Root cause: on section-mode sites, a later internal re-save used to rebuild post_content/HTML cache could silently drop container elements Elementor's own registry doesn't recognize in that mode — after the plugin's own success check had already passed. Also added a defense-in-depth re-check that restores the verified data if that internal re-save ever corrupts it for any other reason. (#612)

= 3.4.9 =
* diagnostic: **elementor:** every write/delete of _elementor_data now logs post ID, element count, and a full call-stack backtrace via error_log() — visible in wp-content/debug.log or the host's PHP error log, no SSH/DB access needed. Added to track down #612 (wp_set_elementor reports success but readback is empty on at least one Elementor Pro + shared-hosting site).

= 3.4.8 =
* fix: **admin ui:** sidebar menu icon was invisible — SVG background square nearly matched WP admin's dark theme color; icon is now transparent-background so it's actually visible.
* fix: **admin ui:** Permissions checkboxes on the Create API Key form could overflow off-screen on narrow admin widths; now wraps properly.
* hardening: **elementor:** wp_set_elementor's post-write verification now reads the raw DB row directly (bypassing any persistent object cache) instead of get_post_meta(), and surfaces the real MySQL error if the write itself silently failed. Addresses reports of wp_set_elementor returning success/meta_verified:true while immediate readback shows an empty page on certain shared-hosting setups (#612).

= 3.4.7 =
* fix: **auth:** Create API Key form was missing scope checkboxes — every scoped key (including Admin role) silently saved as read-only, so no key could write. Form now defaults to read+write (+admin for the Admin role) and lets you uncheck Write for a real read-only key.

= 3.4.6 =
* fix: **security:** restrict non-public content reads to write-scoped keys ([#601](https://github.com/Mumega-com/mcpwp/issues/601))

= 3.4.5 =
* fix: **security:** require admin scope to bypass content-approval gate

= 3.4.4 =
* fix: **seo:** stop /seo/audit fatal and per-post self-fetch storm
* fix: **seo:** stop /seo/audit fatal and per-post self-fetch storm

= 3.4.1 =
* security: require admin scope for plugin self-update and update-control options; restrict update package URLs to HTTPS + trusted hosts (fixes write-scope→RCE chain)

= 3.4.0 =
* feat: GET /openapi.json — generates OpenAPI 3.0 schema from free-tools registry; enables ChatGPT GPT Actions + any OpenAPI consumer

= 3.3.9 =
* fix(ux): staging tools (wp_get_staging_info etc.) now appear in tools/list on all plans — non-Pro calls return a clear upgrade prompt instead of silently being absent
* fix(ux): MCP server instructions include a Pro-features-not-active section when site is unlicensed, listing staging/SEO/WooCommerce as gated features with upgrade URL

= 3.3.8 =
* feat(staging): Pro staging feature — WP Engine, Kinsta, Pressable adapters + Generic WP-CLI fallback
* feat(staging): REST endpoints GET /staging, POST /staging/push, GET /staging/status/{id}
* feat(staging): 4 MCP tools: wp_get_staging_info, wp_push_to_staging, wp_push_to_live, wp_get_staging_status
* feat(staging): push-to-live gates via approval request; actual push fires only after wp_apply_approval
* feat(staging): three integration providers added to Integration Manager (wpengine_staging, kinsta_staging, pressable_staging)
* security: SSRF mitigated — all host API calls use redirection=0; env IDs validated against adapter list_environments() before use; shell exec uses allowlisted binary path + escapeshellarg()

= 3.3.7 =
* feat(T50): MCP resources/list returns 6 real site resources (site-info, site-context, pages, posts, design-references, elementor-status); resources/read proxies to internal mcpwp/v1 REST endpoints with API key forwarding
* feat(T51): MCP prompts/list returns 5 named prompt templates (audit-seo, build-page, refresh-content, onboard, design-match); prompts/get fills in arguments and returns messages[] array; both declared in initialize capabilities

= 3.3.6 =
* fix(#575): wp_upload_media_b64 tool schema now exposes mime_type param (REST already accepted it; MCP tools/list was missing it)
* fix(#576): wp_update_menu_item tool description and error messages now include actionable hints — get menu_id from wp_list_menus, item_id from wp_list_menu_items; errors name the missing ID and the tool to call
* feat(#577): wp_list_media gains search param (filter by title/filename); tool description updated to guide image-reuse workflow (check before uploading)
* fix(#578): wp_upload_design_reference tool description and core missing_image error now include retry guidance — if image_url/image_base64 fails, upload separately then pass media_id

= 3.3.5 =
* feat: before/after change summary on wp_update_page and wp_update_post — response now includes changes[] (field, before, after) and unchanged[] so agents know exactly what shifted without a follow-up fetch
* feat: structural_changes on wp_set_elementor — sections_before/after and widgets_before/after in every save response
* fix(security): sslverify=false in Elementor CSS-prime loopback request fixed to respect WP_DEBUG

= 3.3.4 =
* fix(security): SSRF redirect bypass in wp_get_rendered_copy fetcher — redirection=0 was missing from the get_rendered_copy wp_remote_get call; redirect chains could bypass the same-host allowlist

= 3.3.3 =
* feat: mutation tool schema docs — wp_create_page/wp_update_page now document parent, template, menu_order; wp_create_post/wp_update_post now document categories and tags; all descriptions clarify Elementor vs Gutenberg usage (#569)
* fix(security): SSRF redirect bypass — wp_get_rendered_copy, wp_get_rendered_html, and SEO validator fetchers now pass redirection=0 to prevent redirect chains from bypassing the same-host allowlist

= 3.3.2 =
* feat: wp_get_rendered_copy MCP tool — returns structured visible text (headings, paragraphs, button labels, list items, link text, image alts) from the live rendered page; ground-truth answer to "what does the page actually say?" (#569)
* fix(security): sslverify=false in wp_get_rendered_html fixed — TLS enforced in production, relaxed only when WP_DEBUG is true

= 3.3.1 =
* feat: wp_flush_cache MCP tool flushes WordPress page cache after bulk edits; supports W3 Total Cache, WP Super Cache, LiteSpeed, WP Rocket, Cache Enabler, Breeze, and Cloudflare (#568)
* feat: Elementor write tools (add_section, remove_section, replace_section, patch_elementor) now surface validation_warnings in response — matches existing set_elementor convention (#571)
* fix(security): batch sub-request rate-limit bypass replaced — X-SPAI-Batch-Sub-Request HTTP header (externally spoofable) replaced with Mcpwp_REST_Batch::$is_batch_sub_request static property (#573)
* feat: /onboard response includes suggested_first_tools context-aware list (Elementor vs Gutenberg path) and quick_start guidance string (#574)

= 3.3.0 =
* feat: render-truth across all validators — every MCPWP validator now reports validation_basis and validation_note so agents know whether issues were verified against rendered HTML or stored data (#569)
* feat: validate_structured_data fetches live rendered page for published posts; renders JSON-LD injected by SEO plugins (Yoast/AIOSEO/@graph); suppresses false "no_structured_data" when plugin handles schema (#569)
* feat: validate_internal_links now walks Elementor widget data (_elementor_data) alongside post_content — links in buttons, icon-boxes, and text-editor widgets are validated for broken targets, self-links, and non-canonical URLs; anchor-quality checks skip Elementor button links (#569)
* fix: apply_internal_link duplicate-check now includes Elementor data — prevents false "duplicate link" skips on Elementor pages where post_content is empty (#569)
* feat: audit_seo_site exposes validation_basis per URL and a site-level validation_note counting how many URLs were render-verified (#569)
* feat: validate_blocks response includes validation_basis: stored_block_data + note that dynamic/server-side blocks may render differently (#569)

= 3.2.9 =
* new: validate_seo_readiness now fetches the actual rendered <head> for published pages and reconciles issues against ground truth — false "missing meta description" and "noindex" issues caused by SEO plugin template rendering are suppressed (#569)
* new: rendered_head field in readiness response returns actual title, description, canonical, robots, og:title, og:description from the live page (#569)
* new: validation_basis field ("rendered_html" vs "stored_meta") and validation_note explain whether issues were verified against rendered output or stored meta (#569)
* new: rendered_noindex_detected issue fires when <meta name="robots"> in live <head> contains noindex even when stored meta doesn't (#569)

= 3.2.8 =
* fix: SEO readiness meta-description check now detects AIOSEO's excerpt fallback — pages with no stored per-post description no longer incorrectly warn "missing meta description" when AIOSEO renders one from the excerpt (#569)
* fix: meta-description validation skips length check when value is an unrendered template string (Yoast/AIOSEO template tags); meta_description_source field added to readiness response (#569)
* fix: apply_internal_link now checks Elementor widget data for existing links before inserting — prevents duplicate-link approval requests on Elementor pages where post_content is empty (#569)

= 3.2.7 =
* new: wp_bulk_upload_media tool — import up to 20 images from URLs in one request; use instead of looping wp_upload_media_from_url for migrations (#566)
* fix: wp_upload_media_from_url description now advises using bulk tool for 5+ images to stay under burst rate limit (#566)

= 3.2.6 =
* fix: REST Pro gate now enforces real license check — no more stub bypass (#564)
* fix: remove dead hostname routing from agency proxy (latent Host-spoof surface) (#547)
* fix: wp_rate_limit_status now shows real usage for API-key-authenticated requests (#533)

= 3.2.5 =
* fix: deduplicate Elementor element IDs on save to prevent CSS bleed across pages (#565)
* fix: MCP internal tool dispatch no longer double-counts rate limit, fixing concurrency errors (#566)
* fix: custom CSS set_custom_css warns on HTML-escaped operators, returns compact response, clarifies verification semantics (#567)

= 3.2.4 =
* Security: Encryption key is now stored in a dedicated WordPress option (mcpwp_encryption_key) instead of being derived from AUTH_SALT. AUTH_SALT rotation no longer silently corrupts stored provider credentials. A one-time non-destructive migration re-encrypts existing blobs on first load.
* Security: Admin AJAX pro-gate bypass is now restricted to verified admin users with activate_plugins capability, confirmed nonce, and an explicit action allowlist.

= 3.2.3 =
* Improved: screenshots now save to the media library and return a URL by default — inline base64 (500KB+) overflowed AI-client contexts. Pass `inline=true` for raw base64.
* Improved: `wp_get_elementor` no longer duplicates the page data as a raw JSON string (`elementor_json`) — payload halved on large pages. Pass `include_raw=true` if you need the raw string.

= 3.2.2 =
* New: Calling a Pro tool on a Free install now returns a clear upgrade-path error (JSON-RPC -32004 `pro_required` with plan and pricing link) instead of "unknown tool".
* Improved: `/introspect` is compact by default (tool name + summary, ~80% smaller) so AI clients no longer overflow their context; pass `?full=true` for the legacy payload with full schemas.
* Fixed: `/option` now refuses to overwrite an array/object option with a plain string (decodes valid JSON; `force_type_change=true` to override) — prevents accidental destruction of structured options such as Elementor theme-builder conditions.
* Fixed: numeric values in API responses are returned as JSON numbers (analytics counts, Elementor `page_id`) for typed clients.

= 3.2.1 =
* Maintenance: Internal rebrand cleanup (spai→mcpwp) — cron label, code comments, and admin JS object renamed. Webhook deliveries now also emit `X-MCPWP-Signature`, `X-MCPWP-Event`, and `X-MCPWP-Webhook-ID` headers alongside the existing `X-SPAI-*` headers (both sets sent; `X-SPAI-*` retained for backward-compat). MCP tools/list response now also includes an `x_mcpwp` envelope key alongside `x_spai`. Bridge migration of legacy sites is unchanged.

= 3.2.0 =
* New: Minimal OAuth 2.1 authorization server — MCP clients (Claude Desktop, ChatGPT) can now authenticate via browser sign-in instead of API keys. Adds RFC 9728 protected-resource metadata, RFC 8414 authorization-server metadata, PKCE S256 authorize endpoint, and token exchange (authorization_code + refresh_token). X-API-Key authentication is unchanged and unaffected. Requires `oauth_enabled` setting + allow-listed redirect URIs to activate.
* Fix: `wp_recall` tool PHP 8.x compatibility (#534).

= 3.1.0 =
* New: Seamless migration bridge — sites upgrading from the earlier `site-pilot-ai` build carry over all content, API keys, OAuth access tokens, plugin settings, and licensing entitlement automatically. Existing API keys keep authenticating during and after the change; migration is idempotent and non-destructive (original data is left intact for rollback).
* New: Dual-prefix authentication — legacy keys and OAuth tokens continue to work alongside new `mcpwp_` credentials, with original scopes preserved.
* Compliance: WordPress.org Plugin Check clean (0 errors) — the WordPress.org build makes no third-party network calls and ships under GPL-2.0.
* Docs: per-site cutover runbook and verified MCP client connect matrix added.

= 3.0.1 =
* Fix: signals fatal on every compute — `compute_seo_issues()` was calling the private `get_issues()` method and filtering on severity `critical` (which the store never writes). Now uses the public `list_issues(status=open, severity=error)`.
* Fix: `GET /signals` now lazily computes on first read — closes the permanently-empty feed on hosts where WP-Cron never fires (the mcpwp.net symptom). Response includes `last_computed`, `partial`, and `skipped_types`.
* Fix: `compute_pending_updates()` no longer calls `wp_update_plugins()` (remote requests to wordpress.org per plugin) on web requests — reads cached transient instead; network refresh is cron-only.
* Fix: Elementor broken-page checker fetches IDs only, then reads `_elementor_data` blobs one at a time — prevents large memory spikes.
* Improvement: `compute()` accepts a time budget; over-budget signal types are skipped with their stored values preserved and reported via `mcpwp_signals_meta` (`last_computed`, `skipped_types`, `partial`).
* Licensing: Freemius is now the single source of truth for entitlement — removed legacy Lemon Squeezy store and self-started 14-day local trial that could independently grant Pro. New canonical `get_license_info()` accessor guarantees `plan` and `is_pro` never contradict.
* Refactor (G1–G4): split four monolithic classes (REST Site 4531 lines, free/pro tool files, Admin 3491 lines, Elementor Basic 3299 lines) into focused per-surface controllers and trait groups — no behavior change.
* Hardening (G5): gateway tool-registration contract enforced — unknown tools rejected at `tools/call`, escalation vectors closed.

= 3.0.0 =
* Rebrand: the plugin is now **MCPWP** throughout — folder, main file, class names, function/option prefixes, constants, text domain, and REST namespace (`mcpwp/v1`). A clean reinstall is recommended; settings and API keys are re-created fresh.

= 2.8.56 =
* Fix: front-end fatal error ("Class Mcpwp_Elementor_Chat_Widget not found") when Elementor initialized widgets before the chat-widget class was defined. The Elementor chat widget is defined conditionally on Elementor being loaded; under some plugin load orders the file loaded first, leaving the class undefined while registration still ran. Registration is now guarded and skips safely instead of crashing the site.
* Fix: capability detection now refreshes immediately when a plugin is activated or deactivated. Previously the capabilities response (woocommerce, learnpress, etc.) was cached for up to an hour, so a newly-enabled integration could report as unavailable — making connected AI agents blind to tools that were actually ready to use. The cache is now cleared on plugin activate/deactivate.

= 2.8.55 =
* Improvement: Admin JavaScript consolidated into a single enqueued, cacheable asset — inline scripts removed from Setup, Tools, Chat, and Integrations pages; data now passed via wp_localize_script.
* Fix: PostHog analytics now initializes exactly once (was double-initialized on the Tools and Integrations pages).
* Fix: admin script now loads on every MCPWP page (Control Room and Chat previously missing it).
* Security: chat history JSON data element is now tag-escaped, preventing a stored message containing markup from breaking out of the script element.
* Design system: added surface/border/text and status color tokens plus a radius scale; consolidated the two badge styles into one; replaced hardcoded color/background values with tokens and status classes.

= 2.8.54 =
* Improvement: Control Room — empty-state guidance, in-page section navigation, change summaries on pending approvals, and a confirm step before applying changes.
* Improvement: Library — design-reference form split into Required/Optional groups, consistent cards, clearer empty states with calls to action.
* Improvement: Integrations — upgrade link always shown on Pro cards with per-provider unlock descriptions; version pill added; status messages translatable.
* Accessibility: input label associations, a tablet (1024px) responsive breakpoint, and fluid chat height.

= 2.8.53 =
* New: Setup onboarding checklist — a 3-step guide (generate key → connect → first tool call) so new users always know the next step.
* Improvement: Chat shows a "connect OpenAI or Gemini" notice when no AI provider is configured, instead of silently degrading.
* Accessibility: tool toggles now show a visible keyboard focus ring (WCAG 2.1 AA).
* Polish: clearer "Update channel" labeling on Setup; removed a large stale admin template; design-system font-weight and Action Log style cleanups.

= 2.8.52 =
* New: wp_keyword_research — keyless keyword research via Google Suggest. Expands a seed phrase into related keywords and grouped questions (long-tail terms, content ideas, SEO topic discovery). No API key required.

= 2.8.51 =
* Fix: wp_bulk_find_replace no longer returns "not found" for searches containing HTML tags or URL slashes. The pre-check ran on raw JSON (where < is stored as < and / as \/); it now matches on the decoded element tree.
* Fix: wp_bulk_find_replace no longer corrupts page structure when a search term overlaps Elementor vocabulary. Structural keys (elType, widgetType, id, isInner, structure) are excluded from replacement.
* Hardening: find-replace search term now requires a minimum length of 1.
* Tests: added Elementor find/replace regression suite.

= 2.8.50 =
* New: mcpwp_register_tools filter — third-party plugins register MCP tools with a simple array, no PHP class required.
* Improvement: 56 tool descriptions rewritten for BM25 accuracy — more specific verbs, synonyms, and use-case context.
* Docs: openapi-chatgpt.yaml updated to v2.8.50 (was stale at v1.0.60) — adds /onboard, menus, approvals, full Elementor edit suite, /batch, /settings, and 24 other missing endpoints. Now 49 operations.

= 2.8.49 =
* New: Blueprint Library (wp_list_site_blueprints / wp_get_site_blueprint / wp_deploy_site_blueprint / wp_extract_site_blueprint) — create multi-page site structures from 5 starter blueprints (law firm, restaurant, SaaS, real estate, portfolio) or save custom ones from your existing content.
* New: Chat Excellence — multi-model routing (OpenAI GPT-4o mini, Google Gemini 2.5 Flash, Workers AI fallback), SSE streaming for token-by-token responses, persistent conversation history per user, Clear button, model indicator in header.
* New: Destructive tool confirmation — delete and rollback tools require explicit confirmation in chat before executing.
* New: Chat model picker in Settings — choose preferred model or let Auto detect the best available key.
* New: Site Blueprints panel in Library showing all custom and starter blueprints with deploy hints.

= 2.8.48 =
* New: Dynamic site memory (wp_remember / wp_recall / wp_forget / wp_list_memories) — AI decisions, brand rules, and preferences persist across sessions in structured namespaces (identity, constraints, history, preferences, contacts).
* New: Proactive site signals (wp_get_signals) — WordPress surfaces actionable issues without being asked: stale content, broken Elementor data, missing featured images, draft accumulation, plugin updates, SEO issues.
* New: Site Signals panel in Control Room with severity indicators and direct edit links.
* New: Memory count shown in Control Room alongside signal feed.
* Fix: Legacy mcpwp_site_context auto-migrated into identity namespace on first wp_recall call.

= 2.8.47 =
* New: White-label mode — set agency name, logo, primary color, and chat greeting in WP Admin > Settings.
* New: [mcpwp_chat] shortcode renders a branded floating chat button on any page or post.
* New: Elementor chat widget wrapping the [mcpwp_chat] shortcode (available in Elementor widget panel).
* New: Agency custom hostname routing in proxy worker (CNAME ai.agencyname.com → proxy.mcpwp.net via KV).

= 2.8.46 =
* New: AI action audit log — every write-tool call logged to DB with before/after state snapshots (EU AI Act compliance).
* New: One-click rollback for post/page updates and Elementor writes from Control Room.
* New: CSV export of action log for compliance reporting.
* New: Configurable log retention (default 90 days, daily auto-prune).

= 2.8.45 =
* New: Server-side MCP tool analytics — when enabled, sends anonymous tool call data (tool name, success/failure, duration) to PostHog. Opt-in for free tier, opt-out for paid. No site content or PII is ever collected.
* New: Site support UUID displayed in WP Admin > Settings with a Copy button. Share this ID when contacting MCPWP support.
* New: PostHog configurable via WP Admin > Integrations — token and host are no longer hardcoded.
* Docs: Added == Privacy == section to readme.txt per WP.org plugin guidelines.

= 2.8.44 =
* New: PostHog analytics integration — configure token via WP Admin > Integrations. Tracks 10 key admin actions: API key copy, connection test, upgrade click, scoped key create/revoke, integration save/remove, tool category toggle, AI client tab switch.
* Fix: PostHog token no longer hardcoded — removed default public token, must be configured per-site.

= 2.8.43 =
* Fix: wp_set_template_conditions now accepts both object form ({type, name, sub_name, sub_id}) and positional array form (["include", "singular", "product"]) — positional arrays were previously flattened to include>general due to wrong key lookup (#210).
* Fix: wp_get_elementor_globals now returns both system_typography and custom_typography under fonts, and system_colors + custom_colors under colors — previously only custom_typography was returned, hiding Elementor's 4 reserved defaults (#253).
* Fix: wp_upload_media_from_url tool definition now exposes filename, title, and alt parameters — the underlying PHP already supported them but they were missing from tools/list (#252).
* Fix: Elementor v4 atomic elements (e-heading, e-paragraph, e-button, etc.) now skip settings key validation — they use props/styles, not classic settings, so the validator was incorrectly flagging them (#211).
* Fix: Form widget static schema now includes button styling keys (button_color, button_background_color, button_border_color, button_hover_color, button_background_hover_color) — these are valid Elementor Pro form button controls that were missing from the validator allow-list, generating false-positive warnings (#250, #255).
* Fix: elementor_data_base64 JSON parse error now includes decoded_length in the response to help diagnose LLM-side truncation vs transport issues (#254).
* Docs: Added docs/FREE_PRO_SPLIT.md with full free/pro tool split, WP.org build constraints, and gating pattern (#258).

= 2.8.42 =
* Fix: Theme handler ($supported_themes) — removed corrupted duplicate 'flavor'/'flavflavor' entries and 'oceanwp' entry with wrong option_key. Only Astra, GeneratePress, and Kadence remain with accurate settings_type. Applied to both plugin copies.
* Fix: Integrations admin page — save/remove/test connection buttons now have JS handlers (were previously unimplemented). Uses correct spaiIntegrations nonce and AJAX action names.
* Fix: version.json download_url was pointing to a 404 URL; corrected to /mcpwp-updates/mcpwpwp-latest.zip.
* Polish: Removed inline styles from integrations display and chat display; extracted to CSS classes (mcpwp-info-panel, mcpwp-action-row, mcpwp-field-row, is-hidden, etc.).

= 2.8.41 =
* Fix: Scoped API key scope enforcement — submitted scopes are now respected with a role-based ceiling. Non-admin roles are capped at read+write and cannot claim admin scope regardless of input (issue #333).
* Fix: SEO MCP tool contract drift — wp_set_seo now accepts normalized title/description/canonical_url fields alongside seo_title/seo_description aliases; wp_bulk_seo accepts items as alias for updates; Google Indexing tools now correctly registered in the Pro tool registry (issue #350).
* New: wp_create_elementor_custom_code, wp_get_elementor_custom_code, wp_update_elementor_custom_code — first-class MCP tools for Elementor Pro custom code snippets with injection location (head/body_start/body_end), conditions, raw code preservation, and dry_run support (issue #346).
* Tests: wp_list_pages pagination contract locked with RestPagesTest; extended PHPUnit bootstrap with WP_Query stub, wp_insert_post, and post_type_exists helpers (issue #353).

= 2.8.40 =
* Fix: wp_validate_seo_readiness transport deserialize error on large sites — reduced content graph query from 500 to 100 posts, eliminating memory/timeout failures that produced malformed JSON-RPC responses (issue #337).
* Fix: MCP tools/call response now handles json_encode failure gracefully instead of sending null in the text field, which caused client-side deserialize errors on non-UTF8 content.
* Polish: MCPWP admin shell — introduced CSS design token system (--mcpwp-* variables), dark product headers (.mcpwp-header), shared card/badge/toggle components, and MCPWP-styled admin menu icon. Setup, Control Room, Tools, Integrations, and Chat now share one visual system. Admin assets load by page slug so submenu screens reliably receive shared CSS (issue #342, PR #344).
* Assets: Refreshed WordPress.org banner, icon, and screenshots to MCPWP branding — removed all Site Pilot AI references from marketplace assets (issue #341).

= 2.8.39 =
* Fix: Freemius "free" plan slug no longer causes plan:free/pro_active:true contradiction when pro is active via Lemon Squeezy or developer constant (issue #319).
* Fix: Disabled tool categories now enforced at raw REST endpoint level — callers can no longer bypass category toggles by hitting /mcpwp/v1/* routes directly (issue #328).

= 2.8.38 =
* New: wp_update_media — update alt text, title, caption, or description on an existing media attachment without re-uploading (issue #338).

= 2.8.37 =
* Fix: wp_seo_audit_site and wp_validate_seo_readiness PHP fatal — extract_internal_links_from_content() now defined in base REST class, accessible to all subclasses including the SEO audit controller (issue #336, #337).
* Fix: Disabled tool categories now enforced at execution time, not just discovery — calling a tool in a disabled category returns a clear error instead of executing silently (issue #328).
* Fix: Elementor custom-code tools (wp_list_elementor_custom_code, etc.) now require Elementor Pro and are hidden from tools/list when Elementor Pro is not installed, preventing ghost-route 404 errors (issue #335).

= 2.8.36 =
* Fix: wp_bulk_find_replace now operates on decoded element tree instead of raw JSON string — prevents JSON corruption when replacing URL substrings or text that appears inside serialized values.
* Fix: wp_setup_menu with overwrite=true now clears existing items before repopulating — prevents duplicate accumulation on repeat calls.
* Fix: wp_update_page now returns slug_warning when WordPress silently rewrites a requested slug due to collision with auto-drafts or trashed posts.
* Fix: SVG upload error message now explains the XSS risk and suggests PNG/WebP alternatives.
* Fix: wp_set_custom_css now returns structured alternatives array when CSS fails loopback verification, including Elementor Custom Code instructions.
* New: wp_get_kit_css and wp_set_kit_css — free-tier tools to read/write Elementor kit global CSS. More reliable than wp_set_custom_css on child-theme sites. Works without Elementor Pro.

= 2.8.35 =
* Fix: Admin role now automatically grants full scopes (read+write+admin) — no longer requires scope checkboxes to be manually set.
* UX: Scopes section hidden for preset roles; custom role shows scope controls.

= 2.8.34 =
* Security: Block SVG uploads via b64 endpoint — SVGs can carry stored XSS via script tags; use WP media library with sanitizer plugin instead.

= 2.8.33 =
* Security: Restore least-privilege default for API key scopes — omitting scopes now defaults to read-only, not full access.
* Security: MIME type validation for base64 uploads now verifies caller-supplied mime_type matches detected content type; mismatches are rejected.

= 2.8.32 =
* Fix: API key creation no longer falls back to read-only scope when scopes are omitted — defaults to full access (read, write, admin).
* Fix: wp_upload_media_b64 now accepts mime_type parameter; caller-supplied mime type takes priority over filename detection.
* Fix: REST schema for wp_create_api_key scopes param now documents default (all scopes) and enum values.

= 2.8.31 =
* Fix: Harden Freemius update checks so admin update pages reliably refresh the update cache.
* Fix: Avoid Freemius SDK host warnings during WP-CLI activation and update checks.

= 2.8.30 =
* New: Add read-only WooCommerce SEO intelligence report for product content, commerce evidence, and search performance.
* New: Add REST and MCP product SEO report with approval-safe next steps.

= 2.8.29 =
* New: Add provider-neutral Search Console, Bing, and manual search performance import storage.
* New: Add REST and MCP search trend report with top queries, top URLs, daily aggregates, and import history.

= 2.8.28 =
* New: Add approval-safe SEO autofix planner for stored audit issues.
* New: Add REST and MCP plan output that maps issues to safe tools, playbooks, approval gates, and manual review boundaries.

= 2.8.27 =
* New: Add content coherence score across context, graph, content, SEO, approvals, and events.
* New: Add REST and MCP report with prioritized recommendations mapped to deterministic playbooks.

= 2.8.26 =
* New: Add deterministic agent playbook contracts for Gutenberg, SEO, internal links, and rollback workflows.
* New: Add REST and MCP access to playbook gates, stop conditions, and rollback paths.

= 2.8.25 =
* New: Add Control Room event inbox with event type and risk filters.
* New: Add escalation rules for high-risk, failing SEO, and approval lifecycle events.

= 2.8.24 =
* New: Add compact site-state snapshot for deterministic agent starts across content, graph, SEO, approvals, events, and capabilities.
* New: Add MCP and REST access for agents to read recommended next actions before mutating WordPress content.

= 2.8.23 =
* New: Add AI-first event store and WordPress hooks for approval lifecycle and stored SEO audit events.
* New: Add event schema and recent event listing endpoints for agents and webhook subscribers.

= 2.8.22 =
* New: Add Control Room actions for approving, rejecting, applying, and rolling back supervised agent changes.
* New: Add a one-click stored SEO audit action and status, severity, and category filters for stored SEO issues.

= 2.8.21 =
* New: Add state-driven Control Room visuals for healthy, warning, critical, empty, and rollback-ready dashboard states.

= 2.8.20 =
* New: Add a WordPress admin Control Room for approvals, stored SEO issues, rollback-ready changes, recommendations, and recent agent activity.
* New: Document Search Console, Bing, Apify, keyword inventory, and WooCommerce SEO intelligence backlog direction.

= 2.8.19 =
* New: Add optional stored SEO audit runs and normalized issue records.
* New: Add stored SEO issue listing with status, severity, category, post, and run filters.

= 2.8.18 =
* New: Add read-only content quality and AI-search citation readiness audit for posts and pages.
* New: Check answer depth, summaries, FAQ/question coverage, entity-like names, freshness, trust signals, and reference hints.

= 2.8.17 =
* New: Add read-only SEO site audit summary for posts and pages.
* New: Aggregate readiness, structured data, and media SEO issues into prioritized URL-level recommendations.

= 2.8.16 =
* New: Add read-only media SEO audit for posts and pages.
* New: Check featured images, content images, alt text, filenames, dimensions, file size, lazy-loading hints, and duplicate image use.

= 2.8.15 =
* New: Add read-only structured data inventory and validation for posts and pages.
* New: Detect JSON-LD, microdata, schema.org hints, schema parse errors, missing schema shape, and page-appropriate schema recommendations.

= 2.8.14 =
* New: Add read-only SEO pre-publish readiness validation for WordPress content.
* New: Check title, slug, H1, heading order, meta description, image alt text, internal links, indexability, canonical, robots, sitemap, and schema hints.

= 2.8.13 =
* New: Add weighted content graph signals for SEO and internal linking workflows.
* New: Content graph nodes now include menu depth, freshness score, hub score, orphan severity, and PageRank-style rank score.
* New: Content graph now includes taxonomy edges for shared topic relationships.

= 2.8.12 =
* New: Add read-only internal link validation for SEO and agent publishing workflows.
* New: Detect self-links, duplicate internal targets, weak anchors, missing targets, unpublished targets, and non-canonical internal URLs.

= 2.8.11 =
* New: Add approval-first internal link application from content graph targets.
* New: Internal link application builds native Gutenberg link paragraphs and refuses invented or duplicate target links.

= 2.8.10 =
* New: Add internal link suggestions from the content graph for SEO-aware agent workflows.
* New: Suggestions use existing site URLs only and return approval-ready link diffs without mutating content.

= 2.8.9 =
* New: Add section-level Gutenberg patching by block path, anchor, or heading text.
* New: Section patches create approval requests by default so agents do not rewrite full pages directly.

= 2.8.8 =
* New: Add approval, diff, apply, and rollback workflow for agent mutations.
* New: Allow `wp_set_blocks` to create a pending approval request instead of saving immediately.
* New: Add MCP tools to list, inspect, approve, reject, apply, and roll back approval requests.

= 2.8.7 =
* New: Add Gutenberg block safety validation for agent-generated content.
* New: Add a read-only internal content graph for internal link and orphan-page workflows.
* Fix: Reject unsafe block saves by default unless restricted output is explicitly approved.
* Fix: Stabilize MCP endpoint buffering and endpoint test coverage.

= 2.8.6 =
* Fix: Rename public display brand to MCPWP for WordPress.org packaging.
* Docs: Keep technical identifiers documented as stable while updating user-facing naming.

= 2.8.5 =
* Fix: Align text domain and WordPress.org package slug with the assigned mcpwp slug.
* Fix: Build the WordPress.org ZIP as the free package.
* Fix: Exclude Freemius, the legacy updater, and Pro modules from the WordPress.org package.
* Fix: Disable Pro MCP exposure in WordPress.org builds.
* Docs: Update readme scope for the free WordPress.org package.

= 2.8.3 =
* Fix: Resolve Plugin Check errors for WordPress.org submission.

= 2.8.2 =
* Fix: API key creation form now defaults all scopes checked.

= 2.8.1 =
* Fix: Guard custom CSS, custom JavaScript, and Elementor custom-code endpoints for WordPress.org builds.
* Fix: Replace deprecated utf8_encode usage.
* Docs: Add external service disclosures required for WordPress.org review.

= 2.8.0 =
* New: Chat tab in wp-admin for authenticated site operations.
* Fix: Elementor HTML rendering cache flush after saves.

= 2.5.0 =
* New: Admin navigation for setup, integrations, tools, settings, and activity log.
* New: API key setup flow for MCP clients.

= 2.4.1 =
* Fix: Avoid fatal error when updater file is excluded from WordPress.org build.

= 2.4.0 =
* Rename plugin package for WordPress.org slug generation.
* Remove self-updater from WordPress.org build.
* Fix SQL escaping in activity log for WordPress.org scanner.

== Upgrade Notice ==

= 3.9.3 =
AI image and alt-text generation is now governed by the **AI** tool toggle rather than **Admin**. If you disabled Admin specifically to block `/integrations/*` generation, it becomes reachable again on upgrade — AI is on by default. Disable the AI toggle to restore that block. This aligns the REST routes with the tool taxonomy the MCP layer has always used. Tool-category toggles now hold on the REST entry point as well as the MCP one. A toggle you previously set expecting MCP-only enforcement — WooCommerce, LearnPress, Multisite, Staging, Forms, Elementor Theme — will now also block the matching REST routes.

= 3.4.1 =
Security fix: plugin self-update and update-control options now require admin scope; update package URLs are restricted to HTTPS + trusted hosts (closes a write-scope-to-RCE chain).

= 3.4.0 =
Adds OpenAPI 3.0 schema endpoint (`/openapi.json`) for ChatGPT GPT Actions and any OpenAPI consumer. No key required to read the schema.

= 3.3.9 =
Staging tools now appear in tools/list on all plans with a clear upgrade prompt. MCP server instructions now surface Pro-gated features when unlicensed.

= 3.3.8 =
Adds staging environment management for WP Engine, Kinsta, and Pressable. Push-to-live always gates through the approval system.

= 2.8.31 =
Improves Freemius update checks and removes WP-CLI host warnings during activation and update refreshes.

= 2.8.30 =
Adds a read-only WooCommerce SEO report for product content quality, commerce signals, and search evidence.

= 2.8.29 =
Adds read-only search performance imports and trend reporting for Search Console, Bing, and manual exports.

= 2.8.28 =
Adds a read-only SEO autofix plan so agents can prepare safe fixes without silently mutating content or metadata.

= 2.8.27 =
Adds a read-only content coherence score so agents and humans can prioritize focused site improvements.

= 2.8.26 =
Adds deterministic playbook contracts so agents can follow safe tool order, validation gates, approval gates, and rollback paths.

= 2.8.25 =
Adds an event inbox in the Control Room so humans can see and filter recent agent-relevant events.

= 2.8.24 =
Adds a coherent site-state snapshot so agents can read the whole WordPress system before choosing a playbook or proposing changes.

= 2.8.23 =
Adds normalized event hooks and recent event history so external agents and automations can react to supervised WordPress state changes.

= 2.8.22 =
Adds actionable Control Room workflows for approvals, rollback, stored SEO audits, and filtered SEO issue review.

= 2.8.21 =
Adds state-aware Control Room visuals so dashboard cards and issue rows adapt to current approval and SEO status.

= 2.8.20 =
Adds the first human Control Room screen for supervising agent work and SEO issue follow-up.

= 2.8.19 =
Adds stored SEO audit issues so agents can track open and resolved findings over time.

= 2.8.18 =
Adds content quality and citation-readiness checks for AI/search-oriented publishing.

= 2.8.17 =
Adds a site-level SEO audit summary so agents can prioritize URLs and fixes.

= 2.8.16 =
Adds media SEO checks so agents can catch image issues before publishing.

= 2.8.15 =
Adds structured data validation so agents can inspect schema safely before publishing.

= 2.8.14 =
Adds SEO pre-publish readiness checks for safer agent publishing workflows.

= 2.8.13 =
Adds weighted content graph signals for better internal linking and SEO workflows.

= 2.8.12 =
Adds internal link validation for safer SEO and publishing workflows.

= 2.8.11 =
Adds approval-first internal link application using existing content graph targets.

= 2.8.10 =
Adds graph-based internal link suggestions for safer SEO workflows.

= 2.8.9 =
Adds safer section-level Gutenberg patching with approval-first behavior.

= 2.8.8 =
Adds approval requests with apply and rollback support for safer AI-assisted WordPress editing.

= 2.8.7 =
Adds Gutenberg safety checks and a read-only content graph for safer AI-assisted WordPress editing.

= 2.8.6 =
Public display brand is now MCPWP. Slug, text domain, and REST namespace remain stable.

== Disclaimer ==

This plugin is provided "as is" without warranty of any kind, express or implied. Use at your own risk. The authors are not liable for any damages, data loss, or issues arising from the use of this plugin. This plugin modifies WordPress content (posts, pages, Elementor data, media, settings) through its MCP and REST API endpoints — always maintain backups before making changes via AI assistants. By using this plugin you accept full responsibility for changes made through its API.

== Privacy Policy ==

MCPWP stores API keys, settings, and activity logs locally in WordPress. The plugin does not transmit site content to external services unless you configure an optional integration or ask an AI client connected to your site to send that data.

== Third Party Services ==

This plugin may connect to the following external services depending on your configuration:

= WordPress.com mShots =
Used as a fallback for generating website screenshot thumbnails when no Cloudflare Browser Rendering worker is configured.
* Data sent: The URL of the page to screenshot
* When: When the wp_screenshot_url MCP tool is called and no custom screenshot worker is configured
* Service: https://s0.wp.com/mshots/v1/
* Privacy Policy: https://automattic.com/privacy/

= Feedback Relay (optional) =
When a user submits feedback via the wp_submit_feedback tool, the feedback is relayed to a central endpoint so plugin developers receive bug reports. This can be disabled by defining `MCPWP_DISABLE_FEEDBACK_RELAY` as true.
* Data sent: Feedback text, site URL, site name, plugin version
* When: Only when the wp_submit_feedback MCP tool is explicitly called
* Endpoint: https://mcpwp.net/wp-json/mcpwp/v1/feedback/relay
* Privacy Policy: https://mcpwp.net/privacy

= GitHub API (optional) =
If configured in settings, feedback can be automatically posted as GitHub issues.
* Data sent: Feedback text, site name, plugin version
* When: Only when GitHub integration is configured and feedback is submitted
* Service: https://api.github.com/
* Privacy Policy: https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement

= OpenAI API (optional) =
Used for AI image generation (wp_generate_image, wp_generate_featured_image) and alt text generation when configured by the user.
* Data sent: Text prompts or image URLs for processing
* When: Only when the user has configured an OpenAI API key and explicitly calls AI generation tools
* Service: https://api.openai.com/
* Terms of Service: https://openai.com/policies/terms-of-use
* Privacy Policy: https://openai.com/policies/privacy-policy

= Google Gemini API (optional) =
Used as an alternative AI provider for image description and text generation when configured by the user.
* Data sent: Text prompts or image URLs for processing
* When: Only when the user has configured a Gemini API key and explicitly calls AI generation tools
* Service: https://generativelanguage.googleapis.com/
* Terms of Service: https://ai.google.dev/gemini-api/terms
* Privacy Policy: https://policies.google.com/privacy

= ElevenLabs API (optional) =
Used for text-to-speech generation when configured by the user.
* Data sent: Text content to convert to speech
* When: Only when the user has configured an ElevenLabs API key and calls the wp_text_to_speech tool
* Service: https://api.elevenlabs.io/
* Terms of Service: https://elevenlabs.io/terms-of-use
* Privacy Policy: https://elevenlabs.io/privacy-policy

= Pexels API (optional) =
Used for stock photo search and download when configured by the user.
* Data sent: Search queries for stock photos
* When: Only when the user has configured a Pexels API key and calls wp_search_stock_photos or wp_download_stock_photo
* Service: https://api.pexels.com/
* Terms of Service: https://www.pexels.com/api/documentation/#guidelines
* Privacy Policy: https://www.pexels.com/privacy-policy/

= Figma API (optional) =
Used to import design files, extract colors, typography, and component data from Figma when configured by the user. Supports both personal access tokens and OAuth 2.0.
* Data sent: Figma file keys and node IDs to retrieve design data; no content from your WordPress site is sent to Figma
* When: Only when the user has configured a Figma access token and calls wp_get_figma_file, wp_get_figma_node, or uses the Figma OAuth integration
* OAuth authorization: https://www.figma.com/oauth
* OAuth token exchange: https://api.figma.com/v1/oauth/token
* API service: https://api.figma.com/v1
* Terms of Service: https://www.figma.com/tos/
* Privacy Policy: https://www.figma.com/privacy/

= LottieFiles (optional) =
Used when the user sets an external Lottie animation URL on a Lottie widget. The browser (not the server) fetches the animation JSON file directly from the URL provided by the user.
* Data sent: HTTP request from the visitor's browser to the Lottie JSON URL
* When: Only when a Lottie widget is configured with an external source URL (e.g. https://assets.lottiefiles.com/...)
* Service: https://lottiefiles.com/
* Privacy Policy: https://lottiefiles.com/page/privacy-policy

= Google Indexing API (optional) =
Used to submit URLs to Google for indexing when configured by the user with a Google service account.
* Data sent: Page URLs from your WordPress site for indexing notification
* When: Only when the user has configured a Google service account JSON and calls wp_submit_to_google_index or wp_google_index_status
* Service: https://indexing.googleapis.com/v3/urlNotifications
* Required OAuth scope: https://www.googleapis.com/auth/indexing
* Terms of Service: https://developers.google.com/terms/
* Privacy Policy: https://policies.google.com/privacy

= Cloudflare Workers AI (optional) =
Powers the built-in Chat assistant when no OpenAI key is configured.
* Data sent: Chat messages, site context (name, URL, page titles)
* When: Only when the Chat tab is used and no OpenAI key is available
* Service: https://api.cloudflare.com/client/v4/accounts/{id}/ai/
* Terms of Service: https://www.cloudflare.com/terms/
* Privacy Policy: https://www.cloudflare.com/privacypolicy/

== Support ==

* Documentation: [MCPWP docs](https://mcpwp.net/docs/)
* Support Forum: [wordpress.org/support/plugin/mcpwp](https://wordpress.org/support/plugin/mcpwp)
* GitHub: [github.com/Mumega-com/mcp-for-wp](https://github.com/Mumega-com/mcp-for-wp)
