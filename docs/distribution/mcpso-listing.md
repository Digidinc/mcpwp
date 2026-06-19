# mcp.so Listing — MCPWP

## Server Name
mcpwp

## Display Name
MCPWP — WordPress MCP Server

## One-liner
Full WordPress control via MCP — content, Elementor, SEO, media, menus, approvals.

## Description
MCPWP turns any WordPress site into a fully-featured MCP server. AI agents connect with a single URL and API key, then manage the entire site through 200+ tools spanning content, Elementor page building, media, SEO, navigation menus, webhooks, and an approval queue for safe human-in-the-loop operation.

**Highlights:**
- Elementor layout editing with widget schema validation and auto-fix
- Approval queue: every change can be gated, reviewed, and rolled back
- Role-scoped keys limit what each agent can touch
- ChatGPT GPT Actions support via `/openapi.json`
- Free tier covers all content, page building, and media tools

## Tool Count
200+

## Auth Type
API Key (header: `X-API-Key`)

## Transport
HTTP (Streamable HTTP / SSE)

## Endpoint Pattern
`https://{wordpress-site}/wp-json/mcpwp/v1/mcp`

## Categories
- CMS
- Content
- Productivity

## Config
```json
{
  "mcpServers": {
    "mcpwp": {
      "url": "https://yoursite.com/wp-json/mcpwp/v1/mcp",
      "headers": { "X-API-Key": "mcpwp_your_key" }
    }
  }
}
```

## Repository
https://github.com/Mumega-com/mcpwp

## Homepage
https://mcpwp.net

## Install
Plugin zip: https://updates.mcpwp.net/mcpwp-agency-latest.zip
Docs: https://docs.mcpwp.net
