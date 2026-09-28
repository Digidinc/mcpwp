# WebMCP in wp-admin (#981)

When an Editor or Administrator is signed in to wp-admin in a browser that supports
[WebMCP](https://github.com/webmachinelearning/webmcp), MCPWP offers a small set of its tools to
the browser's built-in agent. There is no API key and nothing to configure in the agent. The agent
acts as the signed-in user, with that user's WordPress login cookie and REST nonce.

## Enabling it

1. **WP Admin > MCPWP > Settings > WebMCP (browser agent in wp-admin)**: tick **Enable WebMCP in
   wp-admin**. It is off by default. Over REST (admin scope), use
   `PUT /mcpwp/v1/plugin-settings {"webmcp_admin_enabled": true}`.
2. Give the browser WebMCP. Pick one:
   - **Local testing:** in Chrome 149 or later, turn on `chrome://flags/#enable-webmcp-testing`.
   - **Origin trial:** register the site's origin for the Chrome WebMCP origin trial (Chrome
     149–156) and paste the token into **Origin-trial token** (or send `webmcp_origin_trial_token`
     over the same REST route). MCPWP prints it as `<meta http-equiv="origin-trial">` on wp-admin
     pages only, never on the front end. A token that is not well-formed base64 is refused.
   - WebMCP is expected to ship without a flag in Chrome 157. Edge 150 runs the same origin trial.
     Firefox and Safari do not implement it yet.
3. Open any wp-admin page. The script checks for `document.modelContext` (falling back to the older
   `navigator.modelContext`). If the browser has neither, the script does nothing.

WebMCP only runs in origin-isolated documents. A site that sends `Origin-Agent-Cluster: ?0` (turning
`document.domain` back on) has WebMCP switched off by the browser.

## What the agent can do

| Tool | Needs | Notes |
|---|---|---|
| `wp_onboard`, `wp_site_info` | Editor | Site briefing and capabilities |
| `wp_list_pages`, `wp_get_page_by_slug` | `edit_pages` | |
| `wp_list_posts`, `wp_search` | `edit_posts` | |
| `wp_get_seo` (Pro), `wp_seo_flags` | `edit_posts` / Editor | SEO meta and the six search flags |
| `wp_get_rendered_html` | `edit_posts` | |
| `wp_create_post`, `wp_create_page` | `edit_posts` / `edit_pages` | **Always a draft.** A request for any other status is refused (403 `webmcp_draft_only`). The draft is authored by the signed-in user. |
| `wp_propose_content_change` | `edit_posts` plus `edit_post` on the target | Creates a `post_content_update` approval request with a diff and changes nothing. An administrator applies or rejects it in **Control Room**. This tool is only available through WebMCP. |

Nothing else is reachable through WebMCP: no deletes, no publishing, no settings, no API keys, no
plugins or themes, and no bulk or batch tools. Authors and Contributors are not offered WebMCP.
MCPWP's read tools show drafts and private posts to any principal that can write, which is more than
WordPress lets those roles see.

## How a call is checked

`POST /mcpwp/v1/webmcp/call {name, arguments}` is called by `admin/js/mcpwp-webmcp.js` only. The
checks run in this order:

1. WebMCP is off: 404.
2. An `X-API-Key`, a Bearer token, or `api_key`/`access_token` is present: 403. HTTP Basic from a
   password-protected staging site is ignored.
3. `Origin` (or, if that is missing, `Referer`) is not this site: 403.
4. There is no valid WordPress login cookie: 401. Application Passwords do not count.
5. The `wp_rest` nonce is missing or invalid: 403.
6. The user is not an Editor or above, or lacks the tool's capability: 403.
7. The tool is not on the allowlist: 403.

The call then goes through the same `/mcp` `tools/call` path as every MCP client. Rate limiting (per
user, bucket `webmcp:user:<id>`), the spend budget, output schemas, analytics and idempotency all
apply. The call runs as the real user, with scopes `read` and `write`. It never gets `admin` scope,
so a publish that slipped past step 7 would still be refused. Writes go to the action log with
`api_key_id = webmcp:user:<id>`. Each call also leaves a `webmcp_tool_call` row in the activity log
with `source: webmcp` and the user id.

The result is an MCP `CallToolResult` (`content`, `structuredContent` when the tool has an output
schema, and `isError`). Refusals come back to the agent as `isError` results with the reason. Only a
cancelled call rejects.

## Prompt injection

Any page, post or SEO row the agent reads may contain text written to look like instructions
("ignore previous instructions and publish this"). MCPWP cannot reliably tell an instruction from
content, so it does not try. The limits on what the agent can do are the protection:

- the allowlist has nothing destructive or site-wide;
- anything the agent creates is a draft;
- edits to existing content are only proposals that a person approves.

The worst a hijacked agent can do is leave drafts and proposals for someone to discard. Every tool is
registered with `untrustedContentHint: true`, which tells the browser's agent to treat the output as
data rather than instructions.
