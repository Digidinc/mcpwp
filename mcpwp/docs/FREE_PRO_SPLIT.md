# Free vs Pro Split

## Positioning

MCPWP should not compete with WordPress native MCP plumbing. WordPress MCP and the WordPress Abilities API are the platform layer. MCPWP is the operator layer that makes WordPress safe, repeatable, and production-oriented for AI agents.

- Free: make self-hosted WordPress AI-ready through MCP with safe core site operations.
- Pro: turn WordPress into a reusable AI site production system with commerce, design memory, advanced integrations, and agency workflows.

The human customer buys, configures, approves, and audits. The day-to-day tool user can be an agent such as Claude Code, Codex CLI/Desktop, OpenClaw, Hermes, Claude Desktop, or another MCP-capable client.

## Free Capabilities

Free must be useful without Freemius, without custom update code, and without external paid services.

- MCP connection/setup for self-hosted WordPress.
- API key creation, scoped access, and rate limits.
- Site information and site context.
- Posts, pages, media, drafts, menus, taxonomies, and reusable basic content operations.
- Basic Elementor read/write support when Elementor is installed.
- Activity log and security settings.
- Tool category toggles.
- Human approval gates for publish, delete, commerce, menu, and theme-builder mutations.
- Compatibility/fallback behavior for sites without official WordPress MCP support.

## Pro Capabilities

Pro is for teams that use AI to operate a site repeatedly, not just connect to it.

- WooCommerce product, order, customer, category, analytics, and product archetype workflows.
- SEO integrations: Yoast, Rank Math, AIOSEO, SEOPress.
- Form integrations: Contact Form 7, WPForms, Gravity Forms, Ninja Forms.
- Elementor Pro, theme builder, template, widget, and global style workflows.
- Reusable Elementor parts.
- Page and product archetypes.
- Design references from screenshots, mockups, and approved source material.
- Agent workflows that turn design references and archetypes into draft pages, product pages, sections, and reusable parts.
- Figma OAuth and Figma design intake (REST `/figma/*` answers 403 `pro_required` on Free; the Figma MCP tools are listed only with Pro).
- SEO market data from DataForSEO: visibility snapshots, refresh, and opportunities (REST `/seo/visibility`, `/seo/visibility/refresh`, `/seo/opportunities` answer 403 `pro_required` on Free).
- Google Indexing.
- AI provider integrations: OpenAI, Gemini, ElevenLabs, Pexels, screenshot worker.
- Agency, multisite, dashboard, monitoring, and centralized key management workflows.

## Boundary Rules

- WP.org builds must not include the Freemius SDK.
- WP.org builds must not include a custom updater.
- Freemius builds include Freemius and exclude the legacy self-hosted updater.
- The text domain remains `mcpwp` in every build.
- The public WP.org package root is `mcpwp/`.
- The Freemius package root remains `mcpwp/` unless Freemius configuration changes.
- REST routes, MCP tools, admin UI, and docs must read from the same capability source.
- Pro-only capabilities should return deterministic "Pro required" errors when called without entitlement.
- Free must not feel like crippleware. It must fulfill the core promise: connect AI to WordPress safely through MCP.

## Implementation Direction

Implement a central capability registry before removing or hiding more tools. The registry should define:

- capability key
- tier: `free` or `pro`
- required API scope
- REST routes
- MCP tools or compact-router actions
- admin UI surfaces
- external services involved
- build availability

This avoids divergent free/pro behavior across REST, MCP, and admin screens.
