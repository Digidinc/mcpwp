# MCPWP Connect Matrix — v3.1 Launch Readiness

**STATUS: PROTOCOL GREEN — same-machine clients verified. External-URL clients blocked on tunnel.**

Issue: #418 | Branch: docs/418-connect-matrix | Date: 2026-06-11

> **Tool count below (171) is stale — captured against plugin 3.0.1.** Current release is
> 3.13.0: 172 core Free tools + 9 AI/stock tools = 181 usable Free, 118 Pro tools + 4 Figma
> = 303 total with Pro (counted from the registries on `origin/main`). Public phrasing:
> "180+ tools free, 290+ with Pro." Re-run this handshake against a current build before
> quoting a number from this doc anywhere external.

## Handshake Evidence (rig: wp-dev, http://127.0.0.1:8086)

Plugin: mcpwp 3.0.1, active (captured at 3.0.1; current release is 3.13.0 — tool count is higher, see note above). Key source: `/mnt/HC_Volume_104325311/projects/sitepilotai/wp-rig/.mcpwp-dev-key`

### Step 1 — initialize

```
POST http://127.0.0.1:8086/wp-json/mcpwp/v1/mcp
X-API-Key: mcpwp_8324c2...
{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"probe","version":"1.0"}}}
```

Response (HTTP 200, 5ms server-side):
```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "result": {
    "protocolVersion": "2024-11-05",
    "serverInfo": { "name": "mcpwp:MCPWP Dev Rig", "version": "3.0.1" },
    "capabilities": {
      "tools": {},
      "resources": { "subscribe": false, "listChanged": false }
    },
    "instructions": "# MCPWP — MCP Server Instructions\n\nYou are connected to **MCPWP Dev Rig** ..."
  }
}
```

RESULT: PASS — valid protocolVersion `2024-11-05`, serverInfo and capabilities present, instructions block populated.

### Step 2 — tools/list

```
POST /mcp  {"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}
```

Response (HTTP 200, 1.6s server-side):

RESULT: PASS — **171 tools** returned. First three: `wp_site_info`, `wp_introspect`, `wp_onboard`.

### Step 3 — tools/call (wp_site_info)

```
POST /mcp  {"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"wp_site_info","arguments":{}}}
```

Response (HTTP 200, 1.4s server-side):
```
site_name: MCPWP Dev Rig
site_url:  http://127.0.0.1:8086
wp_version: 6.9.1
isError: false
```

RESULT: PASS — tool executed, structured result returned, no error flag.

### Timing — full sequence

| Step | Wall-clock |
|------|-----------|
| initialize | 1,599 ms |
| tools/list | 635 ms |
| tools/call (wp_site_info) | 850 ms |
| **Total sequence** | **3,089 ms** |

Protocol round-trip is ~3 seconds. The "<10 min first tool call" claim holds: the human time (install plugin, generate key, paste config) is the bottleneck, not the wire.

### Auth Negatives

| Test | HTTP status | Code | Message |
|------|-------------|------|---------|
| Bad key (`mcpwp_thisisafakebadkey123`) | **401** | `invalid_api_key` | "Invalid API key." with the hint "The key is not valid: it may be mistyped, revoked, or a retired first-activation key. Create a new key under MCPWP -> Setup." |
| No key (header absent) | **401** | `missing_api_key` | "API key is required. Include your API key in the X-API-Key header or Authorization: Bearer header." |

RESULT: PASS — both gate correctly at 401, error codes and hints are user-actionable.

---

## Client Connect Matrix

> **Placeholder for user config:** Replace `https://YOUR-SITE.com/wp-json/mcpwp/v1/mcp` and `mcpwp_YOUR_KEY` with your actual values.
> Key is generated in WP Admin > MCPWP > Setup.

| Client | Config verified (protocol)? | External end-to-end? | Notes |
|--------|-----------------------------|----------------------|-------|
| Claude Code | YES — same-machine protocol GREEN | YES (localhost) | Direct HTTP transport, no proxy |
| Claude Desktop | YES — config shape correct, protocol GREEN on rig | BLOCKED — needs public HTTPS | See note below |
| Cursor | YES — config shape correct, protocol GREEN on rig | BLOCKED — needs public HTTPS | mcp.json, same shape as Desktop |
| Windsurf | YES — config shape correct, protocol GREEN on rig | BLOCKED — needs public HTTPS | mcp_config.json, same shape |
| ChatGPT (Business/custom connector) | Config documented — NOT tested on rig | BLOCKED — needs public HTTPS + OAuth | REST not JSON-RPC; use openapi.yaml |
| Codex / Cowork | Config documented — NOT tested on rig | BLOCKED — needs public HTTPS + tunnel | Same tunnel dependency as ChatGPT |

---

## Per-Client Config Snippets

### Claude Code

Two equivalent methods:

**Method A — `claude mcp add` (command line):**
```bash
claude mcp add --transport http mcpwp \
  "https://YOUR-SITE.com/wp-json/mcpwp/v1/mcp" \
  --header "X-API-Key: mcpwp_YOUR_KEY"
```

**Method B — `.mcp.json` (project file):**
```json
{
  "mcpServers": {
    "mcpwp": {
      "type": "http",
      "url": "https://YOUR-SITE.com/wp-json/mcpwp/v1/mcp",
      "headers": {
        "X-API-Key": "mcpwp_YOUR_KEY"
      }
    }
  }
}
```

VERIFIED AGAINST RIG: protocol handshake identical to the raw curl tests above. HTTP transport, `X-API-Key` header, JSON-RPC 2.0.

---

### Claude Desktop

Claude Desktop supports two methods:

**Method A — Custom Connector (Recommended, no files):**

WP Admin > MCPWP > Setup copies the URL. Then in Claude Desktop > Settings > Connectors > Add custom connector:

- Name: `mcpwp-mysite` (or your site name)
- Remote MCP server URL: `https://YOUR-SITE.com/wp-json/mcpwp/v1/mcp?api_key=mcpwp_YOUR_KEY`
- Leave OAuth fields empty

Note: Custom Connector uses the `?api_key=` query param because the Desktop UI does not expose a custom headers field. The server accepts this equally (verified — see Authentication section in API.md).

**Method B — `claude_desktop_config.json` (stdio proxy via npm, for users who prefer file config):**
```json
{
  "mcpServers": {
    "mcpwp-mysite": {
      "command": "npx",
      "args": ["-y", "mcpwp"],
      "env": {
        "WP_URL": "https://YOUR-SITE.com",
        "WP_API_KEY": "mcpwp_YOUR_KEY",
        "WP_SITE_NAME": "mysite"
      }
    }
  }
}
```

EXTERNAL VERIFICATION: BLOCKED. Claude Desktop requires a publicly reachable HTTPS URL. The rig is localhost-only. End-to-end Desktop test requires the cloudflared tunnel. Config shape is consistent with API.md — no contradictions.

---

### Cursor

File: `~/.cursor/mcp.json` (global) or `.cursor/mcp.json` (project):
```json
{
  "mcpServers": {
    "mcpwp": {
      "url": "https://YOUR-SITE.com/wp-json/mcpwp/v1/mcp",
      "headers": {
        "X-API-Key": "mcpwp_YOUR_KEY"
      }
    }
  }
}
```

EXTERNAL VERIFICATION: BLOCKED — Cursor requires a public URL. Protocol shape is identical to Claude Code `.mcp.json`; the same wire format is confirmed GREEN on the rig.

---

### Windsurf

File: `~/.codeium/windsurf/mcp_config.json`:
```json
{
  "mcpServers": {
    "mcpwp": {
      "serverUrl": "https://YOUR-SITE.com/wp-json/mcpwp/v1/mcp",
      "headers": {
        "X-API-Key": "mcpwp_YOUR_KEY"
      }
    }
  }
}
```

Note: Windsurf uses `serverUrl` key (not `url`). Confirm against current Windsurf docs before publishing — the MCP config schema has evolved across versions.

EXTERNAL VERIFICATION: BLOCKED — same public URL requirement. Wire protocol identical to Cursor/Claude Code.

---

### ChatGPT (Business / Custom Connector)

ChatGPT custom connectors use the REST API + OpenAPI schema, not the JSON-RPC `/mcp` endpoint. Use:

- **Schema URL:** `https://YOUR-SITE.com/wp-json/mcpwp/v1/mcp` is NOT the right entry point for ChatGPT.
- **Correct approach:** Use the OpenAPI spec at `docs/openapi-chatgpt.yaml` (already present in repo) with ChatGPT's "Create a GPT" > Actions flow.
- **Authentication:** API key via custom header `X-API-Key`.

Config is documented in `docs/chatgpt-gpt-instructions.md`.

EXTERNAL VERIFICATION: BLOCKED — requires a public HTTPS URL and OAuth app registration or direct API key setup in ChatGPT Business. The rig cannot expose localhost to ChatGPT servers. Tunnel (cloudflared) needed for end-to-end test.

---

### Codex / Cowork

Config shape: same `mcpServers` JSON-RPC format as Claude Code `.mcp.json` (Codex uses the same MCP client library):
```json
{
  "mcpServers": {
    "mcpwp": {
      "type": "http",
      "url": "https://YOUR-SITE.com/wp-json/mcpwp/v1/mcp",
      "headers": {
        "X-API-Key": "mcpwp_YOUR_KEY"
      }
    }
  }
}
```

EXTERNAL VERIFICATION: BLOCKED — requires public HTTPS via cloudflared tunnel. The JSON-RPC handshake is the same wire protocol proven GREEN above.

---

## What Is and Is Not Tested

### Tested (GREEN)
- Full JSON-RPC 2.0 handshake: `initialize` -> `tools/list` -> `tools/call`
- Server returns correct `protocolVersion: "2024-11-05"`, `serverInfo`, `capabilities`
- 171 tools returned by `tools/list` (as of plugin 3.0.1 — stale, see note at top of doc)
- `wp_site_info` tool call returns correct structured result, `isError: false`
- Auth negative: bad key -> HTTP 401 `invalid_api_key`
- Auth negative: no key -> HTTP 401 `missing_api_key`
- Timing: full handshake sequence completes in ~3 seconds

### NOT Tested (requires external infrastructure)
- Claude Desktop: Custom Connector UI flow against a real public URL
- Cursor: MCP settings panel loading and tool discovery
- Windsurf: mcp_config.json loading and tool discovery
- ChatGPT Business: Actions setup and first tool call
- Codex/Cowork: actual client-side handshake
- Any scenario involving the cloudflared tunnel

### Reconciliation with existing docs
- `docs/API.md` line 3481: Claude Desktop Custom Connector documents `?api_key=` query param — CONSISTENT (server accepts `?api_key=` param per API.md §Authentication).
- `docs/API.md` line 3492: Claude Desktop npm package config — CONSISTENT with what is documented here.
- `docs/API.md` mentions "90+ tools" in the tools/list description — the rig returned **171 tools** at the time of this capture (plugin 3.0.1). Both figures are stale; current is 180+ free / 290+ Pro (see note at top of doc). Neither API.md's number nor this matrix's should be quoted externally without a fresh count.
- `docs/blog-launch-post.md` shows `"url"` + `"headers"` shape for Claude Desktop mcpServers — CONSISTENT.
- No existing `mumcp:connect` skill found in `~/.claude/skills/` — nothing to reconcile.
