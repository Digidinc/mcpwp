---
name: dev-mcp-tools
description: MCP tool registries (free + pro + custom) — load when adding or modifying tools in mcpwp/includes/mcp/
---

# MCPWP Dev — MCP Tools

> Load when adding/modifying MCP tools in `mcpwp/includes/mcp/`. ~273 tools across free + pro.

## What this area is

The three registry classes declare every tool the MCP endpoint advertises. The REST controller
(`mcpwp/includes/api/class-mcpwp-rest-mcp.php`) merges them at runtime and dispatches JSON-RPC
`tools/call` requests to the matching WordPress REST route.

## File map

| File | Class | Role | Tools |
|------|-------|------|-------|
| `class-mcpwp-mcp-tool-registry.php` | `Mcpwp_MCP_Tool_Registry` (abstract) | Base: `define_tool()`, annotation helpers, category/destructive/open_world detection | — |
| `class-mcpwp-mcp-free-tools.php` | `Mcpwp_MCP_Free_Tools` | All tools available on the free tier | ~160 |
| `class-mcpwp-mcp-pro-tools.php` | `Mcpwp_MCP_Pro_Tools` | Pro-only tools (Elementor templates, WooCommerce, forms, SEO Pro, etc.) | ~113 |
| `class-mcpwp-custom-tool-registry.php` | `Mcpwp_Custom_Tool_Registry` | Collects third-party tools via `mcpwp_register_tools` filter | variable |
| `class-mcpwp-integration.php` (and `class-mcpwp-mcp-ai-integration.php`, `class-mcpwp-mcp-figma-integration.php`) | — | Integration-specific registries merged alongside free/pro | small |

## The tool registry base

```php
// Abstract — subclasses must implement both:
abstract public function get_tools(): array;       // tool definitions for tools/list
abstract public function get_tool_map(): array;    // tool_name => ['method'=>'GET','route'=>'/mcpwp/v1/...']

// Helper called inside get_tools():
protected function define_tool(
    string $name,        // tool name, verb-first, wp_ prefix, e.g. 'wp_list_posts'
    string $description, // one sentence, plain English — this is what agents search
    array  $input_props  // ['param_name' => ['type'=>'string','description'=>'…','required'=>true,'default'=>…]]
): array;
```

`define_tool()` builds the MCP `inputSchema` object and calls `get_tool_annotations()` which
attaches `readOnlyHint` (true for GET/HEAD/OPTIONS), `destructiveHint` (true for DELETE or
names listed in `get_destructive_tools()`), `openWorldHint` (names listed in
`get_open_world_tools()`), and `category` slug.

Subclasses override `get_tool_categories()`, `get_destructive_tools()`, `get_open_world_tools()`,
and `get_required_capabilities()` to declare per-tool metadata.

## Anatomy of a tool (real example)

```php
// From class-mcpwp-mcp-free-tools.php, get_tools():
$tools[] = $this->define_tool(
    'wp_get_design_reference',
    'Get one stored design reference, including its image, intent, style, reuse notes, and linked archetypes or parts.',
    array(
        'id' => array(
            'type'        => 'string',
            'description' => 'Design reference ID.',
            'required'    => true,
        ),
    )
);
```

And the matching route entry in `get_tool_map()`:
```php
'wp_get_design_reference' => array(
    'route'  => '/design-references/(?P<id>[\\w-]+)',
    'method' => 'GET',
),
```

Category declared in `get_tool_categories()`:
```php
'wp_get_design_reference' => 'site',
```

## Dispatch & analytics

`POST /mcpwp/v1/mcp` receives JSON-RPC 2.0. The controller method is
`Mcpwp_REST_MCP::handle_tools_call()`:

1. Merges all registries: free → (pro if licensed) → integration registries → custom.
2. Looks up `$tool_name` in the merged `get_all_tool_map()`.
3. Checks `mcpwp_disabled_tool_categories` option; rejects if category is disabled.
4. Checks `get_required_capabilities()` against active site capabilities; rejects if missing.
5. Checks API key scope (role/permissions).
6. Calls the resolved REST route internally via `rest_do_request()`.
7. Calls `fire_tool_called()` at **every** exit point (success and all failure branches).

`fire_tool_called()` emits:
```php
do_action( 'mcpwp_tool_called', $tool_name, $category, $duration_ms, $error_code );
```

**`$error_code` enum** (string):

| Value | Meaning |
|-------|---------|
| `''` | success |
| `tool_not_found` | name not in any registry, or name param missing |
| `category_disabled` | tool's category in `mcpwp_disabled_tool_categories` option |
| `scope_denied` | API key lacks permission for this tool or required capability absent |
| `execution_error` | internal REST call returned 4xx/5xx |

`Mcpwp_Analytics` (analytics class) subscribes to this hook and forwards to PostHog when enabled.

## Free vs Pro gating

```php
// In Mcpwp_REST_MCP::get_all_tools():
$tools = $this->free_registry->get_tools();
if ( $this->is_pro_active() ) {
    $tools = array_merge( $tools, $this->pro_registry->get_tools() );
}
```

`is_pro_active()` returns `true` when `Mcpwp_License::get_instance()->is_pro()` is true
(class `Mcpwp_License` must exist — provided by Freemius integration).

**Category toggles**: site admin can disable entire categories via WP Admin > Tools.
Stored as an array in option `mcpwp_disabled_tool_categories`. Disabled-category tools
are hidden from `tools/list` and rejected on `tools/call` with `category_disabled`.

**WP.org build guard**: `class-mcpwp-mcp-free-tools.php` gates out the four custom-CSS
tools (`wp_get_custom_css`, `wp_set_custom_css`, `wp_delete_custom_css`, `wp_get_css_length`)
when `MCPWP_WPORG_BUILD` constant is defined.

## Third-party tools — the mcpwp_register_tools hook

External plugins register tools without subclassing PHP:

```php
add_filter( 'mcpwp_register_tools', function( array $tools ): array {
    $tools[] = [
        'name'        => 'digid_list_listings',       // required; use plugin_prefix_verb format
        'description' => 'List active real estate listings.', // required
        'rest_path'   => '/digid/v1/listings',         // required; full WP REST path
        'method'      => 'GET',                        // default GET
        'category'    => 'listings',                   // default 'custom'
        'input_props' => [
            'per_page' => [ 'type' => 'integer', 'description' => 'Items per page.' ],
            'status'   => [ 'type' => 'string',  'description' => 'Filter by status.' ],
        ],
        'destructive' => false,                        // optional hint
        'open_world'  => false,                        // optional hint
        'param_remap' => [],                           // map MCP param names to REST param names
    ];
    return $tools;
} );
```

`Mcpwp_Custom_Tool_Registry` resolves the filter on first access, validates each entry
(`name`, `description`, `rest_path` all required), and exposes the result via the standard
`get_tools()` / `get_tool_map()` interface. The `rest_path` is used verbatim — no namespace
prepend — so use the full route including `/mcpwp/v1` if targeting this plugin's own routes.

The `destructive` registration key sets the MCP `annotations.destructiveHint` metadata. That
hint describes behavior to MCP clients; it does not grant or raise an authorization scope.

### Elevating a custom tool's authorization scope

Destructive extensions should feature-detect scope-filter support before registering tools
that require elevation. This complete extension example registers two tools and raises their
inferred scopes to `admin` by exact name:

```php
add_action(
	'plugins_loaded',
	static function (): void {
		if (
			! defined( 'MCPWP_SUPPORTS_CUSTOM_TOOL_SCOPE_FILTER' )
			|| ! MCPWP_SUPPORTS_CUSTOM_TOOL_SCOPE_FILTER
		) {
			return;
		}

		add_filter(
			'mcpwp_register_tools',
			static function ( array $tools ): array {
				$tools[] = array(
					'name'        => 'wp_update_mumega_motion',
					'description' => 'Update the configured Mumega motion settings.',
					'rest_path'   => '/mumega-motion/v1/settings',
					'method'      => 'POST',
					'category'    => 'mumega-motion',
					'destructive' => true,
				);
				$tools[] = array(
					'name'        => 'wp_rollback_mumega_motion',
					'description' => 'Roll back Mumega motion settings to the previous revision.',
					'rest_path'   => '/mumega-motion/v1/rollback',
					'method'      => 'POST',
					'category'    => 'mumega-motion',
					'destructive' => true,
				);

				return $tools;
			}
		);

		add_filter(
			'mcpwp_required_scope_for_tool',
			static function ( string $scope, string $tool_name ): string {
				$admin_tools = array(
					'wp_update_mumega_motion',
					'wp_rollback_mumega_motion',
				);

				return in_array( $tool_name, $admin_tools, true ) ? 'admin' : $scope;
			},
			10,
			2
		);
	}
);
```

The scope filter is raise-only:

- It can raise `read` to `write` or `admin`, and `write` to `admin`.
- It cannot lower a core or inferred requirement.
- Invalid scope values are ignored.
- Use exact tool names instead of broad prefixes or patterns.
- The tool must still enforce an appropriate WordPress capability in its callback or REST
  `permission_callback`; the MCP scope filter is not a substitute for WordPress authorization.

## How to add a tool

1. **Pick the right file**: free tier feature → `class-mcpwp-mcp-free-tools.php`; pro-gated → `class-mcpwp-mcp-pro-tools.php`.
2. **Add `define_tool()`** call inside `get_tools()` in alphabetical / category order. Write a BM25-searchable description: concrete nouns, what it returns, when to use it.
3. **Add to `get_tool_map()`** in the same file: `'wp_your_tool' => ['route' => '/your-endpoint', 'method' => 'POST']`.
4. **Add to `get_tool_categories()`**: choose the closest category slug from the table in `get_tool_categories()` (site, content, media, elementor, seo, admin, webhooks, taxonomy, gutenberg, forms, etc.).
5. **If destructive or open-world**: add to `get_destructive_tools()` or `get_open_world_tools()`.
6. **If capability-gated** (e.g. requires Elementor): add to `get_required_capabilities()`.
7. **Implement the REST route** in the appropriate `mcpwp/includes/api/class-mcpwp-rest-*.php` controller and register it in `class-mcpwp-loader.php`.
8. **Analytics fires automatically** — `mcpwp_tool_called` is emitted by the dispatcher; nothing needed in the tool handler itself.
9. **Bump version in 3 files**:
   - `mcpwp/mcpwp.php` — `Version:` header + `MCPWP_VERSION` constant
   - `mcpwp/readme.txt` — `Stable tag:` + changelog entry under `== Changelog ==`
   - `mcpwp/version.json` — `version` field + prepend HTML changelog fragment
10. **Test**: `php -l` on changed files, then `composer test` (PHPUnit), then manual `tools/list` + `tools/call` against local WordPress.

## Gotchas

- **Description quality matters**: `tools/list` is how agents discover tools. Vague descriptions cause missed calls. Use concrete verbs and nouns: "Returns the 20 most-recent media items" beats "Get media".
- **Token cost of tools/list**: ~272 tools × avg ~60 tokens each ≈ 16 k tokens. Do not add redundant or near-duplicate tools.
- **Name convention**: always `wp_verb_noun` (verb first). Pro tools also follow this; third-party tools should use their own prefix (`acf_`, `wc_`, etc.).
- **`param_remap`** (custom tools only): use when the MCP param name must differ from the REST query param name (e.g. `id` → `post_id`).
- **`param_aliases`** (built-in tool_map entries in `class-mcpwp-mcp-free-tools.php` / `class-mcpwp-mcp-pro-tools.php` only, not the custom-tool-registry contract): `'param_aliases' => [ 'old_name' => 'canonical_name' ]`, resolved by `Mcpwp_REST_MCP::resolve_param_aliases()` before validation and before `param_remap`. Use this — never a second schema property — when renaming a parameter for cross-tool naming consistency (#831): the schema keeps only the canonical name (so `tools/list` / Tool Search Tool retrieval sees one property per concept), while a caller still sending the old name keeps working silently. Composes with `param_remap`: alias resolves old→canonical first, then `param_remap` (if present) renames canonical→REST-param-name same as always. Do not add the deprecated name to `input_props` — that is the #634-style pattern (`wp_set_seo`'s `seo_title`/`title`), reserved for two names that are both meant to stay valid going forward, not for retiring one.
- **`define_tool()` ignores extra keys** in `input_props` beyond `type`, `description`, `required`, `default`. Do not add `enum` or `format` — they are stripped silently.
- **WP.org build**: do not add tools that call external APIs or store external keys inside the `MCPWP_WPORG_BUILD` code path; gate them like the custom CSS tools.
- **Integration registries** (`class-mcpwp-mcp-ai-integration.php`, `class-mcpwp-mcp-figma-integration.php`) are merged before custom tools. Tool names must be globally unique across all registries.

## Testing

```bash
# Lint changed PHP
php -l mcpwp/includes/mcp/class-mcpwp-mcp-free-tools.php

# Run PHPUnit suite (from plugin root)
composer test
# or directly:
vendor/bin/phpunit --configuration mcpwp/tests/phpunit.xml

# MCP endpoint smoke test (requires local WP running on :8080)
bash mcpwp/tests/test-mcp-endpoint.sh

# Key test files
mcpwp/tests/McpEndpointTest.php     # tools/list + tools/call dispatch
mcpwp/tests/McpProToolsTest.php     # pro registry and gating
mcpwp/tests/ApiAuthTest.php         # scope/permission enforcement
```

## Related skills

- [[dev-api]] — `class-mcpwp-rest-mcp.php` controller that owns dispatch, routing, and analytics emit
- [[dev-core]] — service classes (`Mcpwp_Analytics`, `Mcpwp_Rate_Limiter`, etc.) that tool handlers call into
- [[dev-architecture]] — top-level plugin map and hook reference
