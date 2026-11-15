# MCPWP — Connect Claude, Gemini, or GPT to Your WordPress Site in 2 Minutes

> **Historical draft (2026-03):** tool-count figures below predate `docs/tool-counts.json`. Current marketing: **190+ tools free, 300+ with Pro**.

WordPress powers 43% of the web. AI agents are transforming how sites are built and managed. But connecting the two has always required custom code, fragile REST wrappers, or expensive SaaS tools.

**MCPWP** closes that gap. One plugin, 250+ MCP tools, every major MCP client.

---

## What is it?

MCPWP is a WordPress plugin that turns your site into an MCP server. Once installed, any MCP-compatible AI — Claude, Gemini, GPT, Cursor, Windsurf, or your local Ollama — can manage your entire site through natural language.

```
You: "Build a landing page with a hero, 3 feature cards, and a CTA button"
AI:  → creates a full Elementor page with styled sections, flex grid, shadows, hover effects
```

No code. No Elementor JSON. No WordPress admin clicks.

---

## The numbers

- **250+ MCP tools** across 15 categories (content, Elementor, SEO, media, menus, approvals, WooCommerce, and more)
- **14 page blueprints** — hero, features, pricing, FAQ, testimonials, stats, and more
- **Elementor 4 support** with validation that auto-corrects widget key errors
- **Role-scoped API keys** — give your content bot 40 tools, your design bot 180
- **Human-in-the-loop approvals** — stage and approve changes before applying them
- **Site memory** — agents remember your brand voice, color palette, and decisions across sessions

---

## How it compares

| Feature | MCPWP | Royal MCP | Elementor Angie |
|---------|-------|-----------|-----------------|
| MCP tools | 250+ | 37 | Unknown |
| Elementor support | Full (build + edit + templates) | No | Yes (limited) |
| WooCommerce | 21 tools | No | No |
| Role-scoped keys | Yes (5 roles) | No | No |
| Approvals + rollback | Yes | No | No |
| Page blueprints | 14 types | No | No |
| Self-hosted | Yes | Yes | No (cloud) |

---

## Install in 2 minutes

**Step 1: Install the plugin**

Download from [mcpwp.net](https://mcpwp.net) and upload via WP Admin → Plugins → Add New.

Or via WP-CLI:
```bash
wp plugin install https://mumega.com/mcp-updates/mcpwp-latest.zip --activate
```

**Step 2: Generate an API key**

WP Admin → MCPWP → Setup → Generate API Key

**Step 3: Connect your AI**

For Claude Desktop or Claude Code, add to `claude_desktop_config.json`:
```json
{
  "mcpServers": {
    "wordpress": {
      "url": "https://your-site.com/wp-json/mcpwp/v1/mcp",
      "headers": {"X-API-Key": "mcpwp_your_key_here"}
    }
  }
}
```

That's it. Your AI can now read and manage your entire WordPress site.

---

## What agents can do

### Build Elementor pages from blueprints
```
Create a Services page with: hero (heading + CTA), 3-column features grid,
testimonials section, and contact form CTA.
```
The agent calls `wp_build_page()` with section blueprints — no JSON required.

### Edit one widget without touching the rest
```
Update the heading text on the homepage hero to "Grow Your Business Faster"
```
→ Calls `wp_edit_widget()` with the specific widget ID. Only that widget changes.

### Run a site-wide SEO audit
```
Find and fix all pages missing meta descriptions
```
→ `wp_seo_audit_site()` returns issues by severity. Agent creates an approval request
for each fix. You approve. Changes apply.

### Manage WooCommerce products
```
Create a T-Shirt product, $29.99, with 3 size variants
```
→ `wc_create_product()` + `wc_create_variation()` in one conversation.

### Remember brand rules across sessions
```
wp_remember(namespace: "brand", key: "tone", value: "Professional but approachable. No jargon.")
```
Next session: the agent loads `wp_recall(namespace: "brand", key: "tone")` automatically.

---

## For agencies: multi-site proxy

MCPWP Pro includes an agency proxy that routes one MCP token to all client sites:

```
wp_list_sites()
wp_get_page(_site: "client1.com", id: 5)
wp_set_elementor(_site: "client2.com", id: 10, elementor_data: [...])
```

One connection. Every client. Full audit trail.

---

## Why we built it

We're [Mumega](https://mumega.com) — an AI agency. We built MCPWP to solve the exact problem we hit every day: connecting AI workflows to WordPress without writing glue code.

MCPWP packages everything agencies need: site context memory, scoped API keys, Elementor workflows, approval queues, and 250+ tools behind one MCP endpoint.

---

**Get started:**
- Website: [mcpwp.net](https://mcpwp.net)
- GitHub: [Mumega-com/mcp-for-wp](https://github.com/Mumega-com/mcp-for-wp)
- Version: 2.8.49

---

*Built by [Mumega](https://mumega.com)*
