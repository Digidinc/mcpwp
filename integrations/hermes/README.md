# MCPWP + Hermes Agent

Connect [Hermes Agent](https://hermes-agent.nousresearch.com) (Nous Research) to any WordPress site running MCPWP.

Hermes discovers all 120+ MCPWP tools automatically. With Tool Search enabled, large tool catalogs get a 25–50% accuracy gain on tool selection (per Anthropic evals on Opus 4).

## Setup

**1. Install MCPWP** on your WordPress site and generate an API key:
> WP Admin → MCPWP → Setup → Generate API Key

**2. Set environment variables:**

```bash
export MCPWP_URL="https://your-site.com/wp-json/mcpwp/v1/mcp"
export MCPWP_API_KEY="mcpwp_xxxxxxxxxxxxxxxx"
```

**3. Add to `~/.hermes/config.yaml`:**

```yaml
mcp_servers:
  mcpwp:
    url: "${MCPWP_URL}"
    headers:
      X-API-Key: "${MCPWP_API_KEY}"
    timeout: 120
    connect_timeout: 60
    enabled: true
    supports_parallel_tool_calls: false
    tools:
      resources: false
      prompts: false

tools:
  tool_search:
    enabled: on          # recommended: 120+ tools always benefit
    threshold_pct: 5
    search_default_limit: 5
    max_search_limit: 20
```

**4. Launch Hermes:**

```bash
hermes chat
```

Or reload an active session without restarting:

```bash
/reload-mcp
```

**5. Verify tools loaded:**

```
/mcp status mcpwp
```

You should see 120+ tools listed.

---

## Multi-Site (Agency)

For agencies managing multiple WordPress sites, configure one entry per site:

```yaml
mcp_servers:
  client_acme:
    url: "${ACME_URL}"
    headers:
      X-API-Key: "${ACME_API_KEY}"
    timeout: 120
    enabled: true
    tools:
      resources: false
      prompts: false

  client_beta:
    url: "${BETA_URL}"
    headers:
      X-API-Key: "${BETA_API_KEY}"
    timeout: 120
    enabled: true
    tools:
      resources: false
      prompts: false
```

Tools are prefixed by server name: `mcp_client_acme_wp_list_pages`, `mcp_client_beta_wp_create_post`, etc.

---

## Tool Search

MCPWP has 120+ tools — above the threshold where Tool Search meaningfully helps. With `enabled: on`, Hermes replaces tool schema loading with three bridge tools:

- `tool_search(query)` — BM25 search over the catalog
- `tool_describe(name)` — load a specific tool's full schema
- `tool_call(name, arguments)` — invoke the tool

This reduces tool-definition tokens per turn by ~85% and improves selection accuracy.

---

## Tool Naming

Hermes prefixes MCP tool names: `mcp_<server_name>_<tool_name>`.

With server key `mcpwp`:

| MCPWP tool | Hermes name |
|-----------|-------------|
| `wp_list_pages` | `mcp_mcpwp_wp_list_pages` |
| `wp_create_post` | `mcp_mcpwp_wp_create_post` |
| `wp_set_elementor` | `mcp_mcpwp_wp_set_elementor` |
| `wp_onboard` | `mcp_mcpwp_wp_onboard` |

Use `include`/`exclude` filters with the original tool names (not the prefixed form):

```yaml
mcp_servers:
  mcpwp:
    tools:
      include: [wp_list_pages, wp_create_post, wp_get_elementor, wp_set_elementor]
```

---

## First Steps

Always call `wp_onboard` (or `mcp_mcpwp_wp_onboard`) first on a new site connection. It returns a full site briefing: content inventory, active plugins, Elementor mode, and recommended first actions.

```
Use mcp_mcpwp_wp_onboard to get a full site briefing
```

---

## Troubleshooting

**No tools showing after connect**
- Confirm `MCPWP_URL` and `MCPWP_API_KEY` are set in the shell before starting Hermes
- Run `/mcp probe mcpwp` to test the connection
- Check that MCPWP is active: WP Admin → Plugins

**401 Unauthorized**
- API key expired or wrong. Regenerate: WP Admin → MCPWP → Setup.
- Confirm the `X-API-Key` header is exactly as shown (case-sensitive).

**404 on MCP URL**
- MCPWP plugin not active, or WordPress permalink structure not set to "Post name"
- Go to WP Admin → Settings → Permalinks → save (no change needed, just save)

**SSE session errors**
- Hermes bug #20349 affects SSE session-ID propagation. Not relevant for MCPWP — the endpoint is stateless (each POST is independent, no `mcp-session-id` required).

**Tool not found**
- Tool category may be disabled: WP Admin → MCPWP → Tools
- API key scope may not include that category: WP Admin → MCPWP → Setup → edit key scopes

---

## Install as a Hermes Skill

A pre-built skill for the Hermes Skills Hub is available at [`SKILL.md`](SKILL.md).

Install via URL:

```bash
hermes skills install https://raw.githubusercontent.com/Digidinc/mcpwp/main/integrations/hermes/SKILL.md
```
