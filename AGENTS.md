# AGENTS.md — AI Contributor Guide

This file is for AI agents (Claude, OpenClaw, Hermes, ChatGPT) contributing to MCPWP. Human contributors: see CONTRIBUTING.md.

## Codebase Map

| File | Responsibility |
|------|----------------|
| `mcpwp/mcpwp.php` | Plugin bootstrap, version constants, hook registration |
| `mcpwp/includes/core/class-mcpwp-analytics.php` | PostHog server-side analytics |
| `mcpwp/includes/core/class-mcpwp-integration-manager.php` | Provider config (OpenAI, Gemini, PostHog…) |
| `mcpwp/includes/core/class-mcpwp-elementor-basic.php` | Elementor GET/SET + widget validation |
| `mcpwp/includes/api/class-mcpwp-rest-mcp.php` | MCP tool dispatch — `handle_tools_call()` routes all tool calls |
| `mcpwp/includes/mcp/class-mcpwp-mcp-free-tools.php` | Free tier MCP tools (~60 tools) |
| `mcpwp/includes/mcp/class-mcpwp-mcp-pro-tools.php` | Pro tier MCP tools (~60 tools) |
| `mcpwp/includes/mcp/class-mcpwp-mcp-tool-registry.php` | Tool registry base class |
| `mcpwp/includes/traits/trait-mcpwp-api-auth.php` | API key validation (used in all REST controllers) |
| `mcpwp/includes/class-mcpwp-loader.php` | Hook registration |
| `mcpwp/includes/class-mcpwp-rate-limiter.php` | Rate limiting |
| `mcpwp-agency-proxy/src/index.ts` | Cloudflare Worker — agency multi-site proxy |
| `docs/FREE_PRO_SPLIT.md` | Free vs Pro tool tier rules |
| `docs/openapi-chatgpt.yaml` | ChatGPT OpenAPI schema |

## How to Add a New MCP Tool

**4 steps. Do not skip any.**

### Step 1 — Decide: free or pro?
Read `docs/FREE_PRO_SPLIT.md`. Free tools go in `class-mcpwp-mcp-free-tools.php`, pro tools in `class-mcpwp-mcp-pro-tools.php`.

### Step 2 — Register the tool definition
In the correct file, add to the `get_tools()` array:
```php
[
    'name'        => 'wp_your_tool_name',
    'description' => 'One sentence, ≤120 chars, keyword-rich for BM25 search.',
    'inputSchema' => [
        'type'       => 'object',
        'properties' => [
            'param_name' => [ 'type' => 'string', 'description' => 'What this param does' ],
        ],
        'required' => [ 'param_name' ],
    ],
],
```

### Step 3 — Implement the handler
In the same file, add a `handle_your_tool_name( $params )` method:
```php
private function handle_your_tool_name( $params ) {
    $param = sanitize_text_field( $params['param_name'] ?? '' );
    if ( empty( $param ) ) {
        return [ 'error' => 'param_name is required' ];
    }
    // do the work
    return [ 'result' => $output ];
}
```

### Step 4 — Wire the dispatch
In `handle_tool( $tool_name, $params )` in the same file, add:
```php
case 'wp_your_tool_name':
    return $this->handle_your_tool_name( $params );
```

**That's it.** The tool registry picks it up automatically on next `tools/list` call.

## How to Add a New REST Endpoint

1. Create `mcpwp/includes/api/class-mcpwp-rest-yourfeature.php`
2. Extend `Mcpwp_Rest_Api` base class
3. Register routes in `register_routes()`
4. Add to `mcpwp/includes/class-mcpwp-loader.php` → `load_rest_controllers()`
5. Add to the endpoint table in `CLAUDE.md`

## Free vs Pro Split Rule

One sentence: tools that **read** WordPress data are free; tools that **write, delete, or require external APIs** (Elementor advanced, SEO autofix, media AI, analytics) are pro. When in doubt, check `docs/FREE_PRO_SPLIT.md`.

## How to Test a Change

```bash
# Start local WordPress
docker compose -f wp-test/docker-compose.yml up -d

# Generate API key
docker exec wp-test-wordpress-1 bash -c 'php -r "
require_once \"/var/www/html/wp-load.php\";
\$key = \"mcpwp_\" . bin2hex(random_bytes(24));
update_option(\"mcpwp_api_key\", wp_hash_password(\$key));
echo \$key;
"'

# Test your tool
KEY="mcpwp_..."
curl -s -X POST http://localhost:8080/wp-json/mcpwp/v1/mcp \
  -H "X-API-Key: $KEY" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"wp_your_tool_name","arguments":{"param_name":"test"}}}' | jq
```

## What NOT to Do

- Do NOT add `var_dump()`, `error_log()`, or `print_r()` debug statements
- Do NOT bump the version — release-please owns it (see [Releases](#releases))
- Do NOT add tools to the wrong tier file (check `docs/FREE_PRO_SPLIT.md`)
- Do NOT create new files for individual tools — add to the existing class
- Do NOT skip the `sanitize_text_field()` / `intval()` / `absint()` sanitization on inputs

## Releases

release-please owns versioning. Write Conventional Commits (`fix:` → patch, `feat:` → minor); it
opens a release PR that bumps all six version artefacts together and tags on merge. Never hand-edit
one of them — that desynchronises the set.

Three traps, each hit for real:

- **Tagging is not shipping.** Sites update from `updates.mcpwp.net/version.json`. A tag reaches
  nobody until `scripts/publish_update_release.sh` runs.
- **Never reuse a version already on the channel.** Check
  `curl -s https://updates.mcpwp.net/version.json | jq -r .version` first. The updater compares
  version strings, so re-publishing an installed version number strands those sites on old code.
- **`Release-As: X.Y.Z` must ride a commit that changes a real file.** `exclude-paths` makes
  release-please drop commits touching no files, so the documented `--allow-empty` trick is silently
  ignored here — the run still reports success. Verify the release PR title actually changed.

Release PRs get no CI (GitHub doesn't trigger workflows for `GITHUB_TOKEN`-created PRs). Expected.

## Key Constants

| Constant | Value | Location |
|----------|-------|----------|
| `MCPWP_VERSION` | current version | `mcpwp.php` |
| `MCPWP_PLUGIN_DIR` | absolute plugin path | `mcpwp.php` |
| `MCPWP_REST_NAMESPACE` | `'mcpwp/v1'` | `mcpwp.php` |

## Tool Dispatch Flow

```
POST /wp-json/mcpwp/v1/mcp
  → class-mcpwp-rest-mcp.php :: handle_tools_call()
    → checks category enabled (mcpwp_disabled_tool_categories option)
    → checks API key scope
    → Mcpwp_Mcp_Free_Tools::handle_tool($name, $params)
       OR Mcpwp_Mcp_Pro_Tools::handle_tool($name, $params)
    → do_action('mcpwp_tool_called', $tool, $category, $duration_ms, $error_code)
```
