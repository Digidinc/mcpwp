---
name: dev-api
description: REST layer reference — load when working in mcpwp/includes/api/ on controllers, route registration, auth, or the /mcp JSON-RPC dispatch.
---

# MCPWP Dev — REST API Layer

> Load when working in `mcpwp/includes/api/`. Namespace `mcpwp/v1`. Naming `Mcpwp_*`/`mcpwp_*`/`MCPWP_*`.

## What this area is

Twenty-four REST controllers (~18 k lines) that expose every WordPress operation — content, Elementor, SEO, menus, approvals, webhooks, integrations — plus the native MCP JSON-RPC endpoint at `/mcp`. Every controller extends `Mcpwp_REST_API` and calls `register_rest_route` under the `mcpwp/v1` namespace. Routes are wired on `rest_api_init` from `Mcpwp_Loader::register_rest_routes()`.

## File map

| Controller file | REST surface | Lines |
|---|---|---|
| `class-mcpwp-rest-api.php` | Abstract base — auth, response helpers, shared graph utilities | 675 |
| `class-mcpwp-rest-mcp.php` | `POST /mcp` — JSON-RPC 2.0 (initialize / tools/list / tools/call) | 1556 |
| `class-mcpwp-rest-site.php` | `/site-info` `/onboard` `/introspect` `/settings` `/options` `/analytics` `/plugins` `/update` `/guides` `/workflows` `/agent-playbooks` `/rate-limit` `/api-keys` `/design-references` `/site-context` and more | 4531 |
| `class-mcpwp-rest-seo-audit.php` | `/seo/*` — audit, issues, autofix-plan, content-quality, structured-data, media SEO, search-performance, WooCommerce SEO | 1825 |
| `class-mcpwp-rest-content-graph.php` | `/content-graph` `/suggest-links` `/apply-link` `/validate-links` | 1261 |
| `class-mcpwp-rest-blocks.php` | `/blocks/*` — Gutenberg get/set, parse, serialize, validate, block-types, block-patterns, design-system | 1260 |
| `class-mcpwp-rest-elementor.php` | `/elementor/*` — full Elementor surface: get/set, section/widget edit, patch, bulk, kit-css, status | 1020 |
| `class-mcpwp-rest-integrations.php` | `/integrations/*` — provider keys, test, status, stock-photos, image-gen, alt-text, TTS | 928 |
| `class-mcpwp-rest-menus.php` | `/menus/*` — CRUD, locations, assign, setup, items, reorder | 1063 |
| `class-mcpwp-rest-pages.php` | `/pages/*` — CRUD, bulk, by-slug, template, clone | 636 |
| `class-mcpwp-rest-posts.php` | `/posts/*` — CRUD, drafts, bulk, featured-image | 517 |
| `class-mcpwp-rest-webhooks.php` | `/webhooks/*` `/events/*` — CRUD, test, logs, event schema | 487 |
| `class-mcpwp-rest-media.php` | `/media/*` — upload, from-url, from-base64, bulk | 483 |
| `class-mcpwp-rest-approvals.php` | `/approvals/*` — list, create, approve, reject, apply, rollback | 189 |
| `class-mcpwp-rest-batch.php` | `/batch` — fan-out up to N sub-requests in one call | 223 |
| `class-mcpwp-rest-content-quality.php` | `/seo/content-quality/{id}` | 338 |
| `class-mcpwp-rest-content.php` | `/content` — generic CPT list | 200 |
| `class-mcpwp-rest-figma.php` | `/figma/*` — file, node, status | 155 |
| `class-mcpwp-rest-screenshot.php` | `/screenshot` | 155 |
| `class-mcpwp-rest-feedback.php` | `/feedback` | 270 |
| `class-mcpwp-rest-action-log.php` | `/action-log` | 168 |
| `class-mcpwp-rest-signals.php` | `/signals` (Pro feature-flagged) | 146 |
| `class-mcpwp-rest-site-memory.php` | `/site-memory` (Pro feature-flagged) | 159 |
| `class-mcpwp-rest-site-blueprints.php` | `/site-blueprints` (Pro feature-flagged) | 162 |

## The base controller & route registration

`class-mcpwp-rest-api.php` — abstract class `Mcpwp_REST_API`:

**Traits mixed in:**
- `Mcpwp_Api_Auth` (`trait-mcpwp-api-auth.php`) — `verify_api_key()`, `get_api_key_from_request()`, `find_scoped_api_key()`, `check_rate_limit()`, `key_has_scope()`. Auth accepts `X-API-Key` header or `Authorization: Bearer` header. Keys must start with `mcpwp_`. OAuth access tokens start with `mcpwp_at_`. Legacy single key stored in `mcpwp_api_key` wp_option is auto-migrated. Rate limiting is per key-id; bypass header `X-SPAI-Batch-Sub-Request` skips rate-limit for batch sub-requests.
- `Mcpwp_Sanitization` (`trait-mcpwp-sanitization.php`) — `sanitize_post_data()`, `sanitize_page_data()`, `sanitize_query_args()`, `sanitize_html_content()`.
- `Mcpwp_Logging` (`trait-mcpwp-logging.php`) — `log_activity($action, $request, $response, $status_code)` writes to `{prefix}mcpwp_activity_log`.

**Fixed namespace:** `protected $namespace = 'mcpwp/v1'` — set on the base class, never overridden.

**`check_permission($request)`** — every route's `permission_callback`. Delegates to `$this->verify_api_key($request)`. Returns `true` or `WP_Error`.

**`success_response($data, $status = 200)`** — wraps data in `WP_REST_Response`, then calls `add_rate_limit_headers()` which reads `Mcpwp_Rate_Limiter::get_instance()->get_headers()` and attaches them.

**`error_response($code, $message, $status = 400, $context = [])`** — returns `WP_Error` with `['status' => $status]`. Passes through `Mcpwp_Error_Hints::enhance_error()` to attach actionable hints.

**Shared graph utilities** — `build_content_graph_data()`, `calculate_graph_page_rank()`, `extract_internal_links_from_content()`, etc. are on the base so both `Mcpwp_REST_Content_Graph` and `Mcpwp_REST_Content_Quality` can call them without duplication.

**Route registration wiring:**
`Mcpwp_Loader` hooks `register_rest_routes()` to `rest_api_init`. That method instantiates every controller in order and calls `->register_routes()`. Third-party integrations and the `mcpwp_register_rest_routes` action fire last. Feature-flagged controllers (`Site_Memory`, `Signals`, `Site_Blueprints`) are guarded with `class_exists()`.

## The /mcp dispatch path

`class-mcpwp-rest-mcp.php` — class `Mcpwp_REST_MCP`:

**Route:** `POST mcpwp/v1/mcp` → `handle_mcp()`. Also handles `GET`/`HEAD` (`handle_mcp_get()` → authenticated 405 + `Allow: POST`, no SSE stream — #1092; auth refusal still wins first) and `OPTIONS` (CORS preflight, `permission_callback: '__return_true'`).

**Protocol:** JSON-RPC 2.0. Supported methods: `initialize`, `notifications/initialized`, `tools/list`, `tools/call`, `resources/list`, `resources/read`, `ping`. Batch requests (array body) limited to 10 per call.

**Registries merged on every dispatch:**
1. `Mcpwp_MCP_Free_Tools` — always active
2. `Mcpwp_MCP_Pro_Tools` — included when `is_pro_active()` returns true
3. `Mcpwp_Integration::resolve_all()` — third-party integration tools
4. `Mcpwp_Custom_Tool_Registry` — via `mcpwp_register_tools` filter

**`tools/list` (`handle_tools_list`):** returns merged definitions after filtering by: (a) required plugin capability (`mcpwp_disabled_tool_categories` option), (b) API key role scopes.

**`tools/call` (`handle_tools_call`):** resolves `params.name` in merged tool map → validates arguments against `inputSchema` → checks category-disable option → checks key scope (`resolve_key_tool_categories()`) → checks required plugin capability → dispatches to the owning registry's handler → calls `fire_tool_called()` at every exit point.

**Analytics hook — `fire_tool_called($tool_name, $start, $error_code)`:**
```php
do_action( 'mcpwp_tool_called', $tool_name, $category, $duration_ms, $error_code );
```
`$error_code` enum: `''` (success), `'tool_not_found'`, `'category_disabled'`, `'scope_denied'`, `'execution_error'`.
`Mcpwp_Analytics::on_tool_called()` subscribes to this hook and ships the event to PostHog when enabled.

**Content-Type guard:** a `rest_pre_serve_request` filter forces `Content-Type: application/json` on every `/mcpwp/v1/mcp` response to prevent buffering artefacts from leaking `text/event-stream`.

## Conventions

**`permission_callback`:** always `array( $this, 'check_permission' )` (delegates to `verify_api_key`). Public endpoints use `'__return_true'` (only OPTIONS on `/mcp`).

**Param schema:** declared inline in `register_rest_route`'s `args` array. Use `sanitize_callback` for individual params (e.g. `'sanitize_callback' => 'sanitize_text_field'`). Bulk sanitization via trait methods happens inside the handler.

**Pagination:** use `$this->get_pagination_args()` in `args` — provides `per_page` (1–100, default 10) and `page`.

**Common post args:** `$this->get_post_args()` — `title`, `content`, `status` (enum), `excerpt`.

**Logging:** every handler calls `$this->log_activity('action_name', $request)` as its first line.

**Delegation:** controllers are thin. They parse the request, call a service class (e.g. `Mcpwp_Pages`, `Mcpwp_Core`, `Mcpwp_Elementor_Basic`), then return `success_response()` or `error_response()`.

## Gotchas

- **Hard namespace cut.** There is no alias from `site-pilot-ai/v1`. Any consumer (curl, MCP client config, test scripts) must use `mcpwp/v1`. Old `spai_*` option keys and hook names do not exist in v3.
- **Key prefix.** API keys must start with `mcpwp_`. OAuth tokens start with `mcpwp_at_`. The auth trait rejects anything else.
- **`class-mcpwp-rest-site.php` is 4 531 lines.** It owns: site-info, onboard, introspect, settings, options, analytics, plugins, update/trigger-update, guides, workflows, agent-playbooks, rate-limit, api-keys CRUD, design-references, site-context, site-state, and more. When adding a new endpoint that is conceptually "site-level" resist the urge to pile into this file — create a new controller instead.
- **Batch sub-requests.** `Mcpwp_REST_Batch` sets `X-SPAI-Batch-Sub-Request: 1` on each internal sub-request so `verify_api_key` skips rate-limiting (already counted on the outer call).
- **Feature-flagged controllers** (`Site_Memory`, `Signals`, `Site_Blueprints`) are registered with `class_exists()` guards — they are in the Pro package only.
- **Analytics hook fires on every exit path.** When adding a new error return in `handle_tools_call`, always call `$this->fire_tool_called($tool_name, $start, 'execution_error')` before returning.

## How to add a new endpoint

1. Create `mcpwp/includes/api/class-mcpwp-rest-{area}.php`. Declare `class Mcpwp_REST_{Area} extends Mcpwp_REST_API`.
2. Implement `register_routes()`. Use `$this->namespace` (`mcpwp/v1`), `$this->check_permission` as `permission_callback`, `$this->get_pagination_args()` for list endpoints.
3. In each handler: call `$this->log_activity('action_name', $request)`, delegate to a service class, return `$this->success_response($data)` or `$this->error_response('code', 'message', 4xx)`.
4. Register the controller in `Mcpwp_Loader::register_rest_routes()` (`mcpwp/includes/class-mcpwp-loader.php`) alongside the existing list. Feature-gated controllers wrap with `if ( class_exists('Mcpwp_REST_YourController') )`.
5. If the endpoint also needs an MCP tool, add the tool definition + route map entry in `Mcpwp_MCP_Free_Tools` or `Mcpwp_MCP_Pro_Tools`.

## Testing

Test files live in `mcpwp/tests/` alongside `phpunit.xml` (bootstrap: `bootstrap.php`).

Relevant test files:
- `McpEndpointTest.php` — JSON-RPC dispatch, tools/list, tools/call
- `McpProToolsTest.php` — Pro registry tool coverage
- `ApiAuthTest.php` — key validation, scope checks, rate-limit bypass
- `RestPagesTest.php` — pages controller CRUD
- `RateLimiterTest.php` — rate-limit mechanics

Run: `composer test` from `mcpwp/`, or directly `./vendor/bin/phpunit --configuration tests/phpunit.xml`.

Syntax check a single file before committing: `php -l mcpwp/includes/api/class-mcpwp-rest-{area}.php`.

Shell smoke tests: `mcpwp/tests/test-mcp-endpoint.sh` and `test-chatgpt-conformance.sh` for end-to-end JSON-RPC checks against a running WordPress instance.

## Related skills

- [[dev-mcp-tools]] — the tool registries (`Mcpwp_MCP_Free_Tools`, `Mcpwp_MCP_Pro_Tools`) that `/mcp` dispatches into
- [[dev-core]] — service classes the controllers delegate to (`Mcpwp_Core`, `Mcpwp_Pages`, `Mcpwp_Posts`, `Mcpwp_Analytics`, etc.)
- [[dev-architecture]] — top-level map of all packages and how the loader ties them together
