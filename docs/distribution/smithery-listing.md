# Smithery Listing — MCPWP

## Name
MCPWP — WordPress MCP Server

## Tagline
Turn any WordPress site into an MCP server for AI agents

## Short Description (≤160 chars)
Connect Claude, Cursor, or any MCP client to WordPress. Manage content, Elementor layouts, media, SEO, and menus through natural language.

## Long Description
MCPWP is a WordPress plugin that exposes your entire site through the Model Context Protocol (MCP). Install it once, generate an API key, and your AI assistant can manage every aspect of your WordPress site — no custom code, no webhooks, no glue scripts.

**What AI agents can do:**
- Create and update pages, posts, and custom post types
- Read and write Elementor page layouts (sections, columns, widgets) with validation
- Upload media from URLs or base64, generate AI alt text
- Run SEO audits, fix issues, check structured data
- Manage navigation menus and taxonomies
- Gate every change behind an approval queue with one-click rollback
- Receive real-time webhooks on content events

**Security model:**
- Role-scoped API keys (admin, editor, designer, author)
- Every destructive action requires explicit approval
- Rate limiting and audit log built in

**Works with:** Claude (Desktop, Code, claude.ai Connectors), Cursor, Windsurf, Cline, ChatGPT (via /openapi.json GPT Actions), any MCP-compatible client.

**Free plan** includes all content, Elementor, media, menu, and approval tools. **Pro** adds SEO, staging push, and WooCommerce.

## Categories
- Content Management
- Productivity
- Development Tools

## Tags
wordpress, cms, content-management, elementor, seo, page-builder, mcp-server

## MCP Config Example
```json
{
  "mcpServers": {
    "mcpwp": {
      "url": "https://yoursite.com/wp-json/mcpwp/v1/mcp",
      "headers": {
        "X-API-Key": "mcpwp_your_api_key_here"
      }
    }
  }
}
```

## First Tool to Call
`wp_onboard` — returns complete site briefing, available tools, and recommended first actions.

## Links
- Homepage: https://mcpwp.net
- Docs: https://docs.mcpwp.net
- Install: https://updates.mcpwp.net/mcpwp-agency-latest.zip
- GitHub: https://github.com/Mumega-com/mcpwp
