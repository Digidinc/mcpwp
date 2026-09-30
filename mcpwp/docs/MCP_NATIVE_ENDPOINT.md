# Native MCP Endpoint for MCPWP

## Overview

MCPWP now includes a native **Model Context Protocol (MCP)** endpoint that allows AI assistants like Claude Desktop to connect directly to your WordPress site without needing external middleware (npm package or Cloudflare Worker).

## Architecture

```
Claude Desktop → POST /wp-json/mcpwp/v1/mcp → WordPress Plugin → Internal REST API
```

The endpoint receives JSON-RPC 2.0 requests and translates them to internal WordPress REST API calls.

## Endpoint

```
POST https://yoursite.com/wp-json/mcpwp/v1/mcp
```

## Authentication

Use the same `X-API-Key` header as other MCPWP endpoints:

```bash
curl -X POST "https://yoursite.com/wp-json/mcpwp/v1/mcp" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: mcpwp_your_api_key_here" \
  -d '{"jsonrpc":"2.0","method":"ping","id":1}'
```

## MCP Methods

### 1. Initialize

```json
{
  "jsonrpc": "2.0",
  "method": "initialize",
  "id": 1,
  "params": {
    "clientInfo": {
      "name": "claude-desktop",
      "version": "1.0.0"
    }
  }
}
```

Response:
```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "result": {
    "protocolVersion": "2024-11-05",
    "serverInfo": {
      "name": "mcpwp",
      "version": "1.0.14"
    },
    "capabilities": {
      "tools": {}
    }
  }
}
```

### 2. List Tools

```json
{
  "jsonrpc": "2.0",
  "method": "tools/list",
  "id": 2
}
```

Response includes all available tools (17 free + PRO tools if active).

#### Tool profiles: `full` vs `core` (#907)

A full `tools/list` can run to hundreds of tools — more than some clients can
hold. Cursor caps a server at 40 tools, and every extra tool in the list is
tokens spent before the model reads a single word of the conversation. MCPWP
answers this with two profiles:

- **`full`** — every tool this site has. What every site got before #1064,
  and what a site keeps if it explicitly saved "full".
- **`core`** (default since #1064) — a fixed set of ~25 of the most-used tools (onboarding, pages,
  posts, search, Elementor, media, SEO, rendering/verification, menus,
  approvals) plus two meta tools:
  - **`mcpwp_find_tools`** — keyword-search the full catalog (`{"query": "...",
    "limit": 20, "category": "seo"}`) and get back only tools you are
    currently allowed to call.
  - **`mcpwp_call`** — dispatch any tool by name (`{"name": "wp_get_seo",
    "arguments": {...}}`), including one `core`'s tools/list does not
    advertise directly. It runs through the exact same gates as calling that
    tool directly — category, role, Pro, rate limit, idempotency — so nothing
    is bypassed by wrapping a call in it.

  In practice: search first, then call. `core` cuts a typical tools/list
  payload by roughly 80% with no loss of capability — every tool `full` has is
  still one `mcpwp_find_tools` + `mcpwp_call` away.

Select the profile with a query arg on the endpoint URL itself, so a client
can choose per-connection without changing anything site-wide:

```
POST https://yoursite.com/wp-json/mcpwp/v1/mcp?profile=core
```

Or set a site-wide default in WP Admin > mcpwp > Settings ("MCP Tool
Profile"), or via `PUT /wp-json/mcpwp/v1/plugin-settings` with
`{"mcp_tool_profile": "core"}`. Precedence: the `?profile=` query arg wins,
then the site setting (when one was saved), then `core`. Values are trimmed and
lowercased; an unrecognised value at either level falls back rather than failing
the connection. If the site has disabled the "meta" category (so
`mcpwp_find_tools`/`mcpwp_call` are not available), `core` is never applied: the
connection gets `full`. Every API key can use the two meta tools by name,
regardless of its role's categories — they only find and run tools that key may
already call. The `meta` category itself is reserved: no key is granted it, and a
third-party tool registered in it is treated as `custom`.

#### Tool weight budget (#1064)

Every tool in `tools/list` is trimmed at emission, in one place
(`Mcpwp_MCP_Tool_List_Diet`), so a connection pays less before it does
anything:

- Tool descriptions are at most 300 characters, cut at a sentence (or word)
  boundary. A handful of the heaviest and the `core` tools carry a
  hand-written list summary instead.
- Parameter descriptions are at most 120 characters; ones that only restate
  the parameter name are dropped, as are `default: ""`/`default: false`.
- The #912 worked `examples` are not in `tools/list`. `mcpwp_find_tools`
  returns each match's full description, full input schema and examples, and
  an `invalid_arguments` error carries the tool's first example
  (`structuredContent.error.example`, and in the text).
- `annotations.title` is dropped when the top-level `title` (2025-06-18+)
  carries the same text; `idempotentHint` is sent only when it is `true` on a
  write tool. `readOnlyHint`, `destructiveHint` and `openWorldHint` are always
  sent.

Nothing a tool accepts changes: types, `required`, `enum`, `items` and bounds
are emitted exactly as registered, and argument validation reads the full
definitions. `outputSchema` is untouched. `ToolListBudgetTest` holds `core`
under 5,000 tokens, any single tool under 450, and the default `wp_introspect`
(now a summary; `detail="full"` for the tool list) under 2,000.

### 3. Call Tool

```json
{
  "jsonrpc": "2.0",
  "method": "tools/call",
  "id": 3,
  "params": {
    "name": "wp_site_info",
    "arguments": {}
  }
}
```

Response:
```json
{
  "jsonrpc": "2.0",
  "id": 3,
  "result": {
    "content": [
      {
        "type": "text",
        "text": "{\"name\":\"My Site\",\"url\":\"https://mysite.com\",...}"
      }
    ],
    "isError": false
  }
}
```

### 4. Ping

```json
{
  "jsonrpc": "2.0",
  "method": "ping",
  "id": 4
}
```

Response:
```json
{
  "jsonrpc": "2.0",
  "id": 4,
  "result": {
    "pong": true
  }
}
```

### 5. Notifications

Notifications (methods starting with `notifications/`) have no `id` and receive no response:

```json
{
  "jsonrpc": "2.0",
  "method": "notifications/initialized"
}
```

## Available Tools

### Free Tools (17 total)

| Tool | Description |
|------|-------------|
| `wp_site_info` | Get site information |
| `wp_analytics` | Get site analytics |
| `wp_detect_plugins` | Detect active plugins |
| `wp_list_posts` | List blog posts |
| `wp_create_post` | Create a blog post |
| `wp_update_post` | Update a blog post |
| `wp_delete_post` | Delete a blog post |
| `wp_list_pages` | List pages |
| `wp_create_page` | Create a page |
| `wp_update_page` | Update a page |
| `wp_upload_media` | Upload media file |
| `wp_upload_media_from_url` | Upload media from URL |
| `wp_list_drafts` | List all drafts |
| `wp_delete_all_drafts` | Delete all drafts |
| `wp_get_elementor` | Get Elementor page data |
| `wp_set_elementor` | Set Elementor page data |
| `wp_elementor_status` | Check Elementor status |

### PRO Tools (13 additional)

Conditionally available when MCPWP PRO is active:

**SEO (5 tools):**
- `wp_get_seo` - Get SEO metadata
- `wp_set_seo` - Set SEO metadata
- `wp_analyze_seo` - Analyze SEO
- `wp_bulk_seo` - Bulk update SEO
- `wp_seo_status` - Get SEO plugin status

**Forms (4 tools):**
- `wp_list_forms` - List all forms
- `wp_get_form` - Get form details
- `wp_get_form_entries` - Get form submissions
- `wp_forms_status` - Get forms plugin status

**Elementor Pro (4 tools):**
- `wp_list_elementor_templates` - List Elementor templates
- `wp_apply_elementor_template` - Apply template to page
- `wp_create_landing_page` - Create landing page
- `wp_clone_elementor_page` - Clone Elementor page
- `wp_get_elementor_globals` - Get global settings
- `wp_get_elementor_widgets` - Get available widgets

## Tool Examples

### Create a Blog Post

```json
{
  "jsonrpc": "2.0",
  "method": "tools/call",
  "id": 5,
  "params": {
    "name": "wp_create_post",
    "arguments": {
      "title": "My New Post",
      "content": "<p>This is the post content</p>",
      "status": "draft"
    }
  }
}
```

### List Published Posts

```json
{
  "jsonrpc": "2.0",
  "method": "tools/call",
  "id": 6,
  "params": {
    "name": "wp_list_posts",
    "arguments": {
      "status": "publish",
      "per_page": 5,
      "page": 1
    }
  }
}
```

### Update Page SEO (PRO)

```json
{
  "jsonrpc": "2.0",
  "method": "tools/call",
  "id": 7,
  "params": {
    "name": "wp_set_seo",
    "arguments": {
      "id": 42,
      "title": "My Awesome Page | Site Name",
      "description": "This is the best page ever",
      "focus_keyword": "awesome page"
    }
  }
}
```

### Get Elementor Page Data

```json
{
  "jsonrpc": "2.0",
  "method": "tools/call",
  "id": 8,
  "params": {
    "name": "wp_get_elementor",
    "arguments": {
      "id": 123
    }
  }
}
```

## Batch Requests

You can send multiple requests in a single HTTP call:

```json
[
  {
    "jsonrpc": "2.0",
    "method": "tools/call",
    "id": 1,
    "params": {
      "name": "wp_site_info",
      "arguments": {}
    }
  },
  {
    "jsonrpc": "2.0",
    "method": "tools/call",
    "id": 2,
    "params": {
      "name": "wp_analytics",
      "arguments": {"days": 7}
    }
  }
]
```

Response will be an array of responses (excluding notifications).

## Error Handling

Errors follow JSON-RPC 2.0 error format:

```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "error": {
    "code": -32602,
    "message": "Unknown tool: wp_nonexistent_tool"
  }
}
```

### Error Codes

- `-32700` - Parse error (invalid JSON)
- `-32601` - Method not found
- `-32602` - Invalid params
- `-32000` - Tool execution failed (WordPress REST error)

## CORS Support

The endpoint includes proper CORS headers for Claude Desktop:

```
Access-Control-Allow-Origin: *
Access-Control-Allow-Methods: POST, OPTIONS
Access-Control-Allow-Headers: Content-Type, X-API-Key, Mcp-Session-Id, Authorization
```

## Claude Desktop Configuration

Add this to your Claude Desktop MCP config:

```json
{
  "mcpServers": {
    "wordpress-musical-unicorn": {
      "transport": {
        "type": "http",
        "url": "https://musicalunicornfarm.com/wp-json/mcpwp/v1/mcp",
        "headers": {
          "X-API-Key": "mcpwp_your_api_key_here"
        }
      }
    }
  }
}
```

## Advantages Over Cloudflare Worker

1. **Direct Connection** - No middleware needed
2. **Simpler Setup** - Just install the plugin
3. **Unified Auth** - Same API key as other endpoints
4. **Better Performance** - One less hop
5. **Automatic Updates** - Plugin updates include MCP improvements
6. **Integrated Logging** - Uses MCPWP's built-in logging

## Security

- Uses the same authentication as other MCPWP endpoints
- Rate limiting is enforced (if enabled)
- Activity is logged (if logging is enabled)
- All WordPress capabilities are respected
- CORS is configured for browser-based clients

## Troubleshooting

### 401 Unauthorized

Check that:
- API key is correct
- API key is set in MCPWP settings
- Header name is `X-API-Key` (case-sensitive)

### 404 Not Found

Ensure:
- Plugin is active
- WordPress permalinks are enabled
- REST API is accessible

### Tool Not Found

Verify:
- Tool name is correct (case-sensitive)
- PRO tools require PRO version to be active
- Check available tools with `tools/list`

### Rate Limited

If you see rate limit errors:
- Check rate limit status with `/rate-limit` endpoint
- Adjust rate limits in MCPWP settings
- Disable rate limiting if needed

## Implementation Details

### File Structure

```
mcpwp/
├── includes/
│   ├── api/
│   │   └── class-mcpwp-rest-mcp.php  (NEW)
│   └── class-mcpwp-loader.php        (MODIFIED)
└── mcpwp.php                (MODIFIED)
```

### Internal Flow

1. **Request arrives** at `/wp-json/mcpwp/v1/mcp`
2. **Auth check** using `Mcpwp_Api_Auth` trait
3. **JSON-RPC parsing** determines method
4. **Method routing** to appropriate handler
5. **Tool execution** builds internal `WP_REST_Request`
6. **Internal dispatch** via `rest_do_request()`
7. **Response formatting** as JSON-RPC result
8. **CORS headers** added to response

### Code Quality

- Follows WordPress coding standards
- Uses existing auth trait (`Mcpwp_Api_Auth`)
- Extends base `Mcpwp_REST_API` class
- Proper docblocks and comments
- Type hints where applicable
- Error handling at every step

## Testing

### Test Ping

```bash
curl -X POST "https://yoursite.com/wp-json/mcpwp/v1/mcp" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: mcpwp_xxx" \
  -d '{"jsonrpc":"2.0","method":"ping","id":1}' | jq
```

Expected:
```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "result": {
    "pong": true
  }
}
```

### Test Initialize

```bash
curl -X POST "https://yoursite.com/wp-json/mcpwp/v1/mcp" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: mcpwp_xxx" \
  -d '{"jsonrpc":"2.0","method":"initialize","id":1,"params":{"clientInfo":{"name":"test"}}}' | jq
```

### Test Tools List

```bash
curl -X POST "https://yoursite.com/wp-json/mcpwp/v1/mcp" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: mcpwp_xxx" \
  -d '{"jsonrpc":"2.0","method":"tools/list","id":1}' | jq
```

### Test Site Info Tool

```bash
curl -X POST "https://yoursite.com/wp-json/mcpwp/v1/mcp" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: mcpwp_xxx" \
  -d '{"jsonrpc":"2.0","method":"tools/call","id":1,"params":{"name":"wp_site_info","arguments":{}}}' | jq
```

## Changelog

### v1.0.14 (2024-02-06)

- **NEW:** Native MCP endpoint at `/mcp`
- **NEW:** 17 free tools exposed via MCP
- **NEW:** 13 PRO tools (conditionally available)
- **NEW:** JSON-RPC 2.0 protocol support
- **NEW:** Batch request support
- **NEW:** CORS headers for browser clients
- **NEW:** Internal REST API dispatch

## License

GPL v2 or later (same as MCPWP plugin)

## Support

For issues or questions:
- GitHub: https://github.com/Mumega-com/mcp-for-wp
- Email: support@mumega.com
