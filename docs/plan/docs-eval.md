# Docs eval (#1065)

Ten questions an agent must be able to answer from **docs.mcpwp.net alone**, starting at
`https://docs.mcpwp.net/llms.txt` (and `llms-full.txt`). Each answer is taken from the plugin
code at 3.14.9, with the source named, so the docs can be checked against it.

How to run it: give a fresh agent only the two files (or the two URLs once deployed), ask the ten
questions, and mark each answer right or wrong against the key below. All ten must be right. A
wrong answer is a docs bug: fix the page, rebuild, and run the eval again.

## Questions and answers

**1. Which API key role can edit Elementor templates?**
`admin`, `editor` and `designer`, or a `custom` key granted the `elementor-templates` category.
The template tools (`wp_create_elementor_template`, `wp_update_elementor_template`, …) are in
`elementor-templates`, are Pro, and writes need `write` scope. Theme Builder templates are
`elementor-theme`, open to the same three roles.
Source: `Mcpwp_API_Auth_Trait::get_role_definitions()`; route map `'/elementor/templates' =>
'elementor-templates'` in `get_route_category_map()`.

**2. How do I roll back an applied approval?**
Call `wp_rollback_approval` with the approval `id` (REST `POST /approvals/{id}/rollback`), or press
Rollback in Control Room. It needs `admin` scope (so an `admin`-role key) and must not come from the
key that requested the change. Only `applied` or `apply_failed` requests roll back; deletes
(`destructive_operation`) and staging pushes cannot; the before-state is kept 7 days after apply
(`rollback_available` on the record says whether it still can).
Source: `get_required_scope_for_request()` (approvals transitions → admin),
`Mcpwp_Approvals::rollback_request()`, `rollback_available()`, `CLOSED_RETENTION = 604800`,
`check_self_approval()`.

**3. What does the error `approval_queue_full` mean?**
The site already holds too many open approval requests from capped principals, so nothing was
queued (HTTP 429): 100 pending content proposals site-wide, 200 open requests in total, or 100
pending destructive requests across API principals. Fix: have the queue reviewed, or use an
admin-scope key (exempt).
Source: `Mcpwp_Approvals::MAX_PENDING_CONTENT_TOTAL`, `MAX_ITEMS`,
`Mcpwp_Destructive_Gate::MAX_PENDING_TOTAL`; the three `approval_queue_full` errors in
`class-mcpwp-approvals.php`.

**4. How does an agent reach a tool that isn't in the `core` profile?**
Search with `mcpwp_find_tools` (`query`, optional `limit` 1–20, `category`), then dispatch it with
`mcpwp_call` (`name`, `arguments`). The call passes the same category, scope, Pro, rate-limit and
spend checks as calling the tool directly.
Source: `Mcpwp_REST_MCP::CORE_TOOL_PROFILE_TOOLS`, `META_TOOL_NAMES`, `handle_find_tools()`.

**5. Which approvals (requesters) skip the approval limits?**
Only a person in wp-admin with `manage_options` and an API key with `admin` scope. Write-scope keys
of any role (even the `admin` role), OAuth sign-ins and WebMCP users are capped. Approving,
rejecting, applying and rolling back are never refused by a limit.
Source: `Mcpwp_Approvals::requester_is_exempt_from_caps()`; CHANGELOG 3.14.9.

**6. Can a write-scope key publish a post?**
No. Setting status `publish`, `private` or `future` needs `admin` scope; a write-scope key gets 403
`insufficient_scope`. Only an `admin`-role key can hold `admin` scope.
Source: `$publish_gated_routes` and `request_targets_publish_status()` in
`get_required_scope_for_request()`; `$allowed_ceiling` in `create_scoped_api_key()`.

**7. What are the default rate limits, and what happens when a key exceeds them?**
Burst 30 per 10 seconds, 60 per minute, 1000 per hour; reads (GET/HEAD) get double the burst and
per-minute limits. Over it: 429 `rate_limit_exceeded` with a `Retry-After` header (`rate_limited`
over MCP); nothing ran.
Source: `Mcpwp_Rate_Limiter` defaults, `get_burst_window()`, `check_limit()`.

**8. What does a `custom`-role key with no valid categories get?**
Deny-all: it authenticates and can call only the discovery tools (`wp_site_info`, `wp_onboard`,
`wp_introspect`, read `wp_get_site_context`). It is not unrestricted (since 3.14.8).
Source: `resolve_key_tool_categories()`, `deny_all_category_marker()`, `get_discovery_allowlist()`.

**9. How is WebMCP turned on, and can the browser agent publish?**
Enable it in WP Admin → MCPWP → Settings (**Enable WebMCP in wp-admin**, off by default), then use
Chrome 149+ with the `enable-webmcp-testing` flag or paste an origin-trial token. It cannot publish:
creates are always drafts (`webmcp_draft_only`), edits are approval proposals, and it never holds
`admin` scope.
Source: `mcpwp/docs/WEBMCP.md`, `Mcpwp_WebMCP` allowlist, `authenticate_webmcp_principal()`.

**10. How many posts can MCPWP publish per day, and what happens past that?**
25 new published posts and pages per site per UTC day by default (Content Guardrails setting).
Past it, the write still happens but is saved as a draft with a message; drafts are never capped.
Source: `Mcpwp_Content_Guardrails::default_settings()` (`daily_publish_cap` 25),
`on_insert_post_data()`.

## Result log

| Date | Build | Score | Notes |
|------|-------|-------|-------|
| 2026-09-30 | mcpwp-docs `docs/1065-agent-ready`, local build (MCPWP 3.14.9) | **10/10** | Answered from `dist/llms.txt` (14,979 B) + `dist/llms-full.txt` (657,228 B) only. Q1 concepts/access + tools/elementor-templates; Q2, Q3, Q5 concepts/approvals; Q4 concepts/tool-profiles; Q6, Q8 concepts/access; Q7 concepts/limits; Q9 concepts/webmcp; Q10 concepts/guardrails. |
