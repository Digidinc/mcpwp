=== Mumega MCP ===
Contributors: mumega
Donate link: https://mumcp.com/
Tags: ai, claude, mcp, elementor, api
Requires at least: 6.1
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 3.4.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Model Context Protocol (MCP) for WordPress, by Mumega. Safe AI-assisted posts, pages, media, menus, and Elementor basics.

== Description ==

**Mumega MCP — Model Context Protocol for WordPress, by Mumega.**

Mumega MCP connects your WordPress site to AI assistants like Claude using the Model Context Protocol (MCP). It gives approved AI clients a scoped, auditable way to read site context and help manage core WordPress content through natural language.

The WordPress.org package is the free core: API keys, scoped access, activity logging, posts, pages, media, drafts, menus, site context, and basic Elementor operations when Elementor is installed. Advanced features (staging workflows, SEO automation, WooCommerce tooling, AI provider integrations) are available separately — see [mcpwp.net/pricing](https://mumcp.com/pricing).

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
2. Copy your API key from Mumega MCP in the admin menu
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
2. Search for "Mumega MCP"
3. Click Install Now, then Activate
4. Go to Mumega MCP in the admin menu to get your API key

= Manual Installation =

1. Download the plugin ZIP file
2. Go to Plugins → Add New → Upload Plugin
3. Select the ZIP file and click Install Now
4. Activate the plugin
5. Go to Mumega MCP in the admin menu to get your API key

= MCP Server Setup =

Add to your MCP client config (Claude Desktop, Cursor, Windsurf):

`{
  "mcpServers": {
    "mcpwp": {
      "url": "https://yoursite.com/wp-json/mcpwp/v1/mcp",
      "headers": {
        "X-API-Key": "mcpwp_your_api_key_here"
      }
    }
  }
}`

For ChatGPT GPT Actions, use the public OpenAPI schema at `https://yoursite.com/wp-json/mcpwp/v1/openapi.json`.

== Frequently Asked Questions ==

= What is MCP? =

Model Context Protocol (MCP) is an open protocol that enables AI assistants like Claude to interact with external tools and services. Mumega MCP exposes your WordPress site as an MCP-compatible tool.

= Is this secure? =

Yes. All requests require a unique API key. Keys are hashed using WordPress password hashing (not stored in plain text). A dedicated service account with limited capabilities handles API requests. Activity logging tracks all API usage for auditing.

= Does it work with any AI? =

Mumega MCP works with any MCP-compatible client: Claude Code, Claude Desktop, Cursor, Windsurf, and others. For ChatGPT, use the built-in OpenAPI schema (`/openapi.json`) to connect via GPT Actions.

= Do I need coding skills? =

No. Once configured, you control WordPress through natural language. The AI handles all the technical details.

= What about Elementor? =

Basic Elementor page read/write support is included when Elementor is installed. Advanced Elementor Pro and theme-builder workflows are not part of the WordPress.org free package.

= Who is this for? =

Mumega MCP is best for site owners, developers, agencies, and content teams that want AI assistants to help operate WordPress while keeping API keys, scopes, and activity logs under site-owner control.

= Does it support Elementor 4? =

Yes. Mumega MCP includes fallback handling for Elementor page data saves.

= Can I use this on multiple sites? =

Each site needs its own plugin installation and API key.

== Privacy ==

Mumega MCP can send anonymous usage data from your WordPress server to PostHog when the "Share anonymous tool-usage data" setting is enabled in WP Admin > Mumega MCP > Settings.

**What is collected:** Names of MCP tools called, whether each call succeeded or failed, execution duration, plugin version, WordPress version, and PHP major/minor version.

**What is NOT collected:** Post content, page content, user data, API keys, site URL, or any personally identifiable information.

**Identifier:** Each site is assigned a random site UUID (e.g. mcpwp-xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx). This UUID is stored in wp_options and cannot be traced back to a domain name or individual.

**Where data is sent:** PostHog, Inc. (posthog.com). Your server's outbound IP address is transmitted as part of the HTTP request to PostHog.

**Default state:** Disabled. Analytics only runs if you explicitly enable the setting (opt-in).

**How to opt out:** Uncheck "Share anonymous tool-usage data" under WP Admin > Mumega MCP > Settings.

== Screenshots ==

1. Control Room — supervised approvals, SEO findings, rollback-ready changes, and recommended next actions
2. Setup — MCP endpoint, client configuration, and role-scoped API key management
3. Tools — category controls for agent capability discovery and execution enforcement
4. Integrations and Chat — connected services plus safety-first agent workflow

== Changelog ==

= 3.4.3 =
* security: harden REST/MCP scope matrix and self-approval gate

= 3.4.2 =
* security: block secret option stores from /option endpoint; admin-gate /plugin-settings

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

Older releases are documented at https://github.com/Mumega-com/mcpwp/blob/main/CHANGELOG.md

== Upgrade Notice ==

= 3.4.3 =
Security hardening: REST/MCP scope matrix tightened and self-approval gate enforced.

= 3.4.1 =
Security fix: plugin self-update and update-control options now require admin scope; update package URLs are restricted to HTTPS + trusted hosts (closes a write-scope-to-RCE chain).

= 3.4.0 =
Adds OpenAPI 3.0 schema endpoint (`/openapi.json`) for ChatGPT GPT Actions and any OpenAPI consumer. No key required to read the schema.

= 3.3.9 =
Staging tools now appear in tools/list on all plans with a clear upgrade prompt. MCP server instructions now surface Pro-gated features when unlicensed.

= 3.3.8 =
Adds staging environment management for WP Engine, Kinsta, and Pressable. Push-to-live always gates through the approval system.


== Disclaimer ==

This plugin is provided "as is" without warranty of any kind, express or implied. Use at your own risk. The authors are not liable for any damages, data loss, or issues arising from the use of this plugin. This plugin modifies WordPress content (posts, pages, Elementor data, media, settings) through its MCP and REST API endpoints — always maintain backups before making changes via AI assistants. By using this plugin you accept full responsibility for changes made through its API.

== Privacy Policy ==

Mumega MCP stores API keys, settings, and activity logs locally in WordPress. The plugin does not transmit site content to external services unless you configure an optional integration or ask an AI client connected to your site to send that data.

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
* Privacy Policy: https://mumcp.com/privacy

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

* Documentation: [Mumega MCP docs](https://mumcp.com/docs/)
* Support Forum: [wordpress.org/support/plugin/mumega-mcp](https://wordpress.org/support/plugin/mumega-mcp)
* GitHub: [github.com/Mumega-com/mcp-for-wp](https://github.com/Mumega-com/mcp-for-wp)
