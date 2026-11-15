# ChatGPT Custom GPT Setup Guide

Connect MCPWP to ChatGPT as a Custom GPT using the OpenAPI schema.

## Prerequisites

- ChatGPT Plus or Team account (Custom GPTs require paid plan)
- MCPWP installed and active on your WordPress site
- API key generated (WP Admin → MCPWP → Setup)

## Step 1 — Get Your API Key

```
WP Admin → MCPWP → Setup → Generate API Key
```

Copy the key — it starts with `mcpwp_`. Store it somewhere safe; it's shown once.

## Step 2 — Create the Custom GPT

1. Go to https://chat.openai.com/gpts/create
2. Click **Configure** tab
3. Fill in:

| Field | Value |
|-------|-------|
| Name | MCPWP — WordPress Manager |
| Description | Manage my WordPress site through conversation |
| Instructions | Paste contents of `docs/chatgpt-gpt-instructions.md` |

## Step 3 — Add the Action (OpenAPI schema)

1. Scroll to **Actions** → click **Create new action**
2. Select **Import from URL** → paste:
   ```
   https://raw.githubusercontent.com/Digidinc/mcpwp/main/docs/openapi-chatgpt.yaml
   ```
   Or upload `docs/openapi-chatgpt.yaml` directly.

3. Under **Authentication**:
   - Type: **API Key**
   - Auth type: **Custom**
   - Header name: `X-API-Key`
   - Value: your `mcpwp_...` key

4. Under **Privacy policy URL**: `https://mcpwp.net/privacy`

5. Click **Save**

## Step 4 — Set the Server URL

In the imported schema, replace `{site}` with your WordPress domain:

```yaml
servers:
  - url: https://yourdomain.com/wp-json/mcpwp/v1
```

If you uploaded the YAML file, edit line 17 directly.

## Step 5 — Test the Connection

In the GPT preview panel, try:

```
What's on my site?
```

Expected: The GPT calls `GET /onboard` and returns a site briefing with page count, theme, plugins, and Elementor status.

If you get a 401: check the API key header is set correctly.
If you get a 404: confirm MCPWP is active and permalinks are set to "Post name" (Settings → Permalinks → Save).

## Step 6 — Publish

1. Choose visibility: **Only me** (private), **Anyone with link**, or **Everyone** (GPT Store)
2. Click **Update**

For GPT Store submission, you need:
- A verified builder profile
- Privacy policy URL filled in
- Logo image (optional but recommended — use `assets/icon-256x256.png`)

## Available Operations

The schema includes 49 operations. Key ones:

| Operation | What it does |
|-----------|-------------|
| `GET /onboard` | Full site briefing — always call first |
| `GET /site-info` | Site name, theme, plugins, Elementor mode |
| `GET /pages` | List all pages |
| `POST /pages` | Create a page |
| `PUT /pages/{id}` | Update page title/content/status |
| `GET /elementor/{id}` | Read Elementor layout JSON |
| `POST /elementor/{id}` | Write Elementor layout JSON |
| `PUT /elementor/{id}/edit-widget` | Edit one widget by ID |
| `GET /menus` | List navigation menus |
| `POST /menus/setup` | Create + populate menu in one call |
| `POST /approvals` | Create an approval request |
| `POST /approvals/{id}/approve` | Approve a pending change |
| `GET /seo/audit` | Site-level SEO audit |
| `POST /batch` | Run multiple operations in one call |

Full reference: `docs/openapi-chatgpt.yaml`

## Troubleshooting

**GPT says "I can't access your site"**
→ Check site URL in schema matches your actual WordPress domain exactly (no trailing slash).

**401 Unauthorized**
→ Confirm header name is `X-API-Key` (not `Authorization`). Regenerate key if needed.

**CORS errors in browser dev tools**
→ MCPWP adds CORS headers automatically. If blocked, check your hosting's firewall or CDN isn't stripping response headers.

**"operation not supported" for a tool**
→ Check if the tool is Pro-only. Pro tools require a paid Freemius license.

## Developer Mode (MCP Protocol)

For Claude Code / Claude Desktop, use the MCP endpoint directly instead of the OpenAPI schema:

```
Endpoint: https://yourdomain.com/wp-json/mcpwp/v1/mcp
Method:   POST
Auth:     X-API-Key: mcpwp_...
Protocol: JSON-RPC 2.0
```

See `integrations/clawhub/SKILL.md` for Claude Code setup.
See `CLAUDE_DESKTOP_SETUP.md` for Claude Desktop config.
