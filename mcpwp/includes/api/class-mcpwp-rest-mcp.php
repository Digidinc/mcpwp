<?php
/**
 * MCP (Model Context Protocol) REST Controller
 *
 * Implements a native MCP endpoint for direct Claude Desktop connection.
 * Receives JSON-RPC 2.0 requests and translates them to internal REST API calls.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MCP REST controller.
 *
 * Provides a streamable HTTP endpoint that follows the MCP specification.
 * Allows AI assistants like Claude to connect directly to WordPress without
 * needing external middleware or Cloudflare Workers.
 */
class Mcpwp_REST_MCP extends Mcpwp_REST_API {

	/**
	 * MCP spec revisions this server negotiates, oldest to newest (#906).
	 * Every one of these is echoed back verbatim when a client requests it
	 * in `initialize` or sends it as `MCP-Protocol-Version`. Anything else —
	 * a revision this server has never spoken, or none at all — falls back
	 * to LATEST_PROTOCOL_VERSION, per the spec's negotiation rule
	 * (basic/lifecycle#version-negotiation: "Otherwise, the server MUST
	 * respond with another protocol version it supports... SHOULD be the
	 * latest version supported by the server").
	 *
	 * @var string[]
	 */
	const SUPPORTED_PROTOCOL_VERSIONS = array( '2024-11-05', '2025-03-26', '2025-06-18', '2025-11-25' );

	/**
	 * Newest revision this server speaks — the negotiation fallback above,
	 * and the answer to a GET capability probe (handle_mcp_get()), which
	 * carries no client-requested version to negotiate against at all.
	 */
	const LATEST_PROTOCOL_VERSION = '2025-11-25';

	/**
	 * Version assumed for a non-initialize request with no
	 * MCP-Protocol-Version header — the spec's own backwards-compatibility
	 * default (basic/transports#protocol-version-header).
	 */
	const HEADERLESS_PROTOCOL_VERSION = '2025-03-26';

	/**
	 * Revisions whose Tool / CallToolResult shape defines a top-level
	 * `title`, `outputSchema`, and `structuredContent` for a tool's own
	 * declared output (introduced 2025-06-18; unchanged in 2025-11-25). A
	 * negotiated version outside this set gets the pre-2025-06-18 shape:
	 * `annotations.title` only — already unconditional since #934, for
	 * exactly this backward-compat reason — no `outputSchema`, no
	 * `structuredContent` for a tool's result.
	 *
	 * Deliberately unrelated to the unconditional `structuredContent.error`
	 * an isError result carries (#936, attach in tool_error_result()) — that
	 * is this plugin's own error-reporting convention, not a spec-gated
	 * field, and every version keeps it.
	 *
	 * @var string[]
	 */
	const STRUCTURED_OUTPUT_PROTOCOL_VERSIONS = array( '2025-06-18', '2025-11-25' );

	/**
	 * The protocol version negotiated for the HTTP request currently being
	 * answered — resolved once per request by
	 * resolve_protocol_version_for_request(), read by handle_initialize(),
	 * handle_tools_list() and handle_tools_call(). Defaults to the latest
	 * supported version so any code path that runs before resolution (none
	 * today) fails toward the richest shape rather than the oldest.
	 *
	 * @var string
	 */
	private $negotiated_protocol_version = self::LATEST_PROTOCOL_VERSION;

	/**
	 * Server name.
	 *
	 * @var string
	 */
	private $server_name;

	/**
	 * Server version.
	 *
	 * @var string
	 */
	private $server_version;

	/**
	 * Tool definitions cache.
	 *
	 * @var array|null
	 */
	private $tools_cache = null;

	/**
	 * Tool name => declared outputSchema, lazily indexed from the built-in
	 * registries by get_tool_output_schema() (#906).
	 *
	 * @var array|null
	 */
	private $tool_output_schemas = null;

	/**
	 * Tool names whose structuredContent failed its outputSchema in this
	 * request — logged once each (#906).
	 *
	 * @var array<string,bool>
	 */
	private $schema_mismatches_logged = array();

	/**
	 * Forgiving-params notes from the most recently validated tools/call
	 * (#911) — set by validate_tool_arguments() via handle_tools_call(),
	 * consumed and cleared by attach_result_meta() into
	 * `result._meta.param_fixes`. A side channel, not a return value,
	 * because attach_result_meta() decorates every JSON-RPC response
	 * uniformly (see its own docblock) and has no path to a per-call value
	 * threaded back from deep inside handle_tools_call()'s many early
	 * returns — the same reason current_rate_limit_budget() and
	 * Mcpwp_Spend_Budget::report() are read the same way for that method's
	 * other _meta keys.
	 *
	 * @var array[]
	 */
	private $last_param_fixes = array();

	/**
	 * Free tools registry.
	 *
	 * @var Mcpwp_MCP_Free_Tools
	 */
	private $free_registry;

	/**
	 * Pro tools registry.
	 *
	 * @var Mcpwp_MCP_Pro_Tools
	 */
	private $pro_registry;

	/**
	 * Custom tools registry (mcpwp_register_tools filter).
	 *
	 * @var Mcpwp_Custom_Tool_Registry
	 */
	private $custom_registry;

	/**
	 * Output buffer level that existed before this controller was created.
	 *
	 * @var int
	 */
	private $initial_ob_level = 0;

	/**
	 * Resolved capability flags (cached).
	 *
	 * @var array|null
	 */
	private $active_capabilities = null;

	/**
	 * Check whether a capability requirement is satisfied.
	 *
	 * Handles composite requirements: 'seo' is true if any SEO plugin is active,
	 * 'forms' is true if any forms plugin is active.
	 *
	 * @param string $requirement The capability key (e.g. 'elementor', 'seo', 'forms').
	 * @return bool True if the requirement is satisfied.
	 */
	private function is_capability_active( $requirement ) {
		if ( null === $this->active_capabilities ) {
			$core = class_exists( 'Mcpwp_Core' ) ? new Mcpwp_Core() : null;
			$caps = ( $core && method_exists( $core, 'get_capabilities' ) )
				? $core->get_capabilities()
				: array();

			$this->active_capabilities = $caps;

			// Derive composite flags.
			$this->active_capabilities['seo'] = ! empty( $caps['yoast'] )
				|| ! empty( $caps['rankmath'] )
				|| ! empty( $caps['aioseo'] )
				|| ! empty( $caps['seopress'] );

			$this->active_capabilities['forms'] = ! empty( $caps['cf7'] )
				|| ! empty( $caps['wpforms'] )
				|| ! empty( $caps['gravityforms'] )
				|| ! empty( $caps['ninjaforms'] );
		}

		return ! empty( $this->active_capabilities[ $requirement ] );
	}

	/**
	 * Collect all capability requirements from all registries.
	 *
	 * @return array Map of tool_name => capability_key.
	 */
	private function get_all_required_capabilities() {
		$reqs = $this->free_registry->get_required_capabilities();
		if ( $this->is_pro_active() ) {
			$reqs = array_merge( $reqs, $this->pro_registry->get_required_capabilities() );
		}
		foreach ( Mcpwp_Integration::resolve_all() as $integration ) {
			$reqs = array_merge( $reqs, $integration->get_required_capabilities() );
		}
		// Custom/registered tools (mcpwp_register_tools) may declare a required
		// site capability — gate them the same way as first-party tools.
		$reqs = array_merge( $reqs, $this->custom_registry->get_required_capabilities() );
		return $reqs;
	}

	/**
	 * Get merged tool definitions from all registries.
	 *
	 * Combines free, pro, and third-party integration tools.
	 * Filters out tools whose required plugins are not installed.
	 *
	 * @return array Tool definitions.
	 */
	private function get_all_tools() {
		$tools = $this->free_registry->get_tools();
		if ( $this->is_pro_active() ) {
			$tools = array_merge( $tools, $this->pro_registry->get_tools() );
		} else {
			// Staging discovery tools always appear in tools/list so agents can see they exist.
			// Execution is gated at call time — non-Pro calls return the upgrade path (line ~1128).
			$tools = array_merge( $tools, $this->pro_registry->get_staging_pro_tools() );
		}
		foreach ( Mcpwp_Integration::resolve_all() as $integration ) {
			$tools = array_merge( $tools, $integration->get_tools() );
		}
		$tools = array_merge( $tools, $this->custom_registry->get_tools() );

		// Filter out tools whose required plugins are not active.
		$reqs = $this->get_all_required_capabilities();
		if ( ! empty( $reqs ) ) {
			$tools = array_values(
				array_filter(
					$tools,
					function ( $tool ) use ( $reqs ) {
						$name = isset( $tool['name'] ) ? $tool['name'] : '';
						if ( ! isset( $reqs[ $name ] ) ) {
							return true; // No requirement, always show.
						}
						return $this->is_capability_active( $reqs[ $name ] );
					}
				)
			);
		}

		// Filter out tools in admin-disabled categories.
		$disabled_categories = get_option( 'mcpwp_disabled_tool_categories', array() );
		if ( ! empty( $disabled_categories ) && is_array( $disabled_categories ) ) {
			$all_categories = $this->get_all_tool_categories();
			$tools          = array_values(
				array_filter(
					$tools,
					function ( $tool ) use ( $disabled_categories, $all_categories ) {
						// Same rule as the handler: an uncategorised tool is not
						// hidden by a disabled category it does not belong to.
						$name     = isset( $tool['name'] ) ? $tool['name'] : '';
						$category = isset( $all_categories[ $name ] ) ? (string) $all_categories[ $name ] : '';
						return '' === $category || ! in_array( $category, $disabled_categories, true );
					}
				)
			);
		}

		return $tools;
	}

	/**
	 * Collect all tool category mappings from all registries.
	 *
	 * @return array Map of tool_name => category_slug.
	 */
	private function get_all_tool_categories() {
		$cats = $this->free_registry->get_tool_categories();
		if ( $this->is_pro_active() ) {
			$cats = array_merge( $cats, $this->pro_registry->get_tool_categories() );
		}
		foreach ( Mcpwp_Integration::resolve_all() as $integration ) {
			$cats = array_merge( $cats, $integration->get_tool_categories() );
		}
		$cats = array_merge( $cats, $this->custom_registry->get_tool_categories() );
		return $cats;
	}

	/**
	 * Get merged tool map from all registries.
	 *
	 * Combines free, pro, and third-party integration route mappings.
	 *
	 * @return array Tool name => route mappings.
	 */
	private function get_all_tool_map() {
		$tool_map = $this->free_registry->get_tool_map();
		if ( $this->is_pro_active() ) {
			$tool_map = array_merge( $tool_map, $this->pro_registry->get_tool_map() );
		}
		foreach ( Mcpwp_Integration::resolve_all() as $integration ) {
			$tool_map = array_merge( $tool_map, $integration->get_tool_map() );
		}
		$tool_map = array_merge( $tool_map, $this->custom_registry->get_tool_map() );
		return $tool_map;
	}

	/**
	 * Find the registry that owns a given tool.
	 *
	 * @param string $tool_name Tool name to look up.
	 * @return Mcpwp_MCP_Tool_Registry The owning registry.
	 */
	private function get_registry_for_tool( $tool_name ) {
		if ( isset( $this->free_registry->get_tool_map()[ $tool_name ] ) ) {
			return $this->free_registry;
		}
		if ( $this->is_pro_active() && isset( $this->pro_registry->get_tool_map()[ $tool_name ] ) ) {
			return $this->pro_registry;
		}
		foreach ( Mcpwp_Integration::resolve_all() as $integration ) {
			if ( isset( $integration->get_tool_map()[ $tool_name ] ) ) {
				return $integration;
			}
		}
		if ( isset( $this->custom_registry->get_tool_map()[ $tool_name ] ) ) {
			return $this->custom_registry;
		}
		return $this->free_registry; // fallback
	}

	/**
	 * Return introspection data for AI clients (MCP + REST).
	 *
	 * This is intentionally non-sensitive. It helps clients discover tools,
	 * capabilities, and auth requirements without guessing.
	 *
	 * @return array
	 */
	public function get_introspection_data() {
		$core = class_exists( 'Mcpwp_Core' ) ? new Mcpwp_Core() : null;
		// Figma is Pro (#564): on Free its tools are neither listed nor
		// callable, and a stored token (allowed on every tier) must not send an
		// agent to them (#845). The same entitlement check the Figma gate uses.
		$figma_entitled   = class_exists( 'Mcpwp_Figma' ) && Mcpwp_Figma::is_entitled();
		$figma_status     = $figma_entitled ? ( new Mcpwp_Figma() )->get_status() : array();
		$figma_configured = $figma_entitled && ! empty( $figma_status['configured'] );

		$site_info = is_object( $core ) && method_exists( $core, 'get_site_info' )
			? $core->get_site_info()
			: array(
				'plugin'       => array(
					'name'    => 'MCPWP',
					'version' => defined( 'MCPWP_VERSION' ) ? MCPWP_VERSION : null,
				),
				'capabilities' => array(),
			);

		// Build tools and tool map from all registries.
		$tools    = $this->get_all_tools();
		$tool_map = $this->get_all_tool_map();

		// Enrich each tool with route/method + annotations.
		foreach ( $tools as &$tool ) {
			$name = isset( $tool['name'] ) ? (string) $tool['name'] : '';
			if ( '' === $name ) {
				continue;
			}

			if ( isset( $tool_map[ $name ] ) && ! isset( $tool_map[ $name ]['callback'] ) ) {
				// x_spai kept for backward-compat; remove in a future major.
				$tool['x_spai']   = array(
					'method' => $tool_map[ $name ]['method'],
					'route'  => $tool_map[ $name ]['route'],
				);
				// x_mcpwp alias (new canonical name).
				$tool['x_mcpwp'] = $tool['x_spai'];
			} else {
				// x_spai kept for backward-compat; remove in a future major.
				$tool['x_spai']  = array();
				// x_mcpwp alias (new canonical name).
				$tool['x_mcpwp'] = array();
			}

			// Get annotations from the owning registry.
			$tool['annotations'] = $this->get_registry_for_tool( $name )->get_tool_annotations( $name );
		}
		unset( $tool );

		// Build contextual workflows
		$capabilities = $site_info['capabilities'] ?? array();
		$is_pro       = $this->is_pro_active();
		$workflows    = $this->build_contextual_workflows( $capabilities, $is_pro );

		return array(
			'plugin'                => $site_info['plugin'] ?? array(
				'name'    => 'MCPWP',
				'version' => defined( 'MCPWP_VERSION' ) ? MCPWP_VERSION : null,
			),
			'site'                  => array(
				'name'     => $site_info['name'] ?? null,
				'url'      => $site_info['url'] ?? null,
				'language' => $site_info['language'] ?? null,
				'timezone' => $site_info['timezone'] ?? null,
			),
			'license'               => $site_info['license'] ?? null,
			'capabilities'          => $capabilities,
			'detected_integrations' => $this->get_detected_integrations(),
			'auth'                  => array(
				'header' => 'X-API-Key',
				'note'   => 'Send your MCPWP API key in the X-API-Key header for REST + MCP requests.',
			),
			'endpoints'             => array(
				'rest_base' => rest_url( 'mcpwp/v1/' ),
				'mcp'       => rest_url( 'mcpwp/v1/mcp' ),
			),
			'mcp'                   => array(
				'transport' => 'JSON-RPC 2.0 over HTTP POST',
				'methods'   => array( 'initialize', 'tools/list', 'tools/call' ),
			),
			'recommended_guides'    => array_values(
				array_filter(
					array(
						'onboarding',
						'workflows',
						! empty( $capabilities['elementor'] ) ? 'elementor' : '',
						! empty( $capabilities['woocommerce'] ) ? 'woocommerce' : '',
					)
				)
			),
			'tools'                 => $tools,
			'workflows'             => $workflows,
			'operating_rules'       => array_values(
				array_filter(
					array(
						'Read site context before changing content structure.',
						$figma_configured ? 'If an approved Figma source is available, inspect it before translating the design into local structure.' : '',
						'Prefer archetypes and reusable parts over one-off page or product structures.',
						'For repeatable page classes, use page archetypes first.',
						'For repeatable product classes, use product archetypes first.',
						'If you create a reusable Elementor section, save it back into the parts library before finishing.',
						'Default new content to draft unless explicitly asked to publish.',
					)
				)
			),
			'quick_start'           => array_values(
				array_filter(
					array(
						'1. Call wp_introspect to discover capabilities.',
						'2. Call wp_get_site_context to read site-level rules.',
						'3. Call wp_get_guide(topic="onboarding") and wp_get_guide(topic="workflows").',
						$figma_configured ? '4. If the site uses Figma, call wp_figma_status and inspect the approved file or frame before building.' : '',
						'5. Use page/product archetypes and reusable parts before creating new structure.',
					)
				)
			),
		);
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->server_version = defined( 'MCPWP_VERSION' ) ? MCPWP_VERSION : '1.0.0';
		// Decoded for agents (#903) — see Mcpwp_Agent_Text.
		$site_name            = function_exists( 'get_bloginfo' ) ? Mcpwp_Agent_Text::decode( get_bloginfo( 'name' ) ) : '';
		$this->server_name    = 'mcpwp' . ( '' !== $site_name ? ':' . $site_name : '' );
		$this->initial_ob_level = ob_get_level();
		$this->free_registry    = new Mcpwp_MCP_Free_Tools();
		$this->pro_registry     = new Mcpwp_MCP_Pro_Tools();
		$this->custom_registry  = new Mcpwp_Custom_Tool_Registry();

		// Reserve every first-party tool name so a registered (mcpwp_register_tools)
		// tool can never shadow a built-in's route. Pro names are reserved too,
		// regardless of tier, so the guard holds on free sites as well.
		$reserved = array_merge(
			array_keys( $this->free_registry->get_tool_categories() ),
			array_keys( $this->pro_registry->get_tool_categories() )
		);
		foreach ( Mcpwp_Integration::resolve_all() as $integration ) {
			$reserved = array_merge( $reserved, array_keys( $integration->get_tool_categories() ) );
		}
		$this->custom_registry->set_reserved_names( $reserved );

		// Force JSON content type for MCP responses. During rapid sequential calls,
		// WordPress or PHP output buffering can send wrong Content-Type headers
		// (e.g. text/event-stream or text/html). This filter fires just before
		// WordPress serves the REST response, ensuring we override at the HTTP level.
		add_filter( 'rest_pre_serve_request', array( $this, 'force_json_content_type' ), 10, 4 );

		// A browser's CORS preflight is answered by WordPress core, not by
		// handle_options(), so the MCP request headers must also be on
		// core's allow-list for this route (#906). Core passes the request
		// to this filter only from WordPress 6.3; on older cores the second
		// argument is absent and allow_mcp_cors_headers() leaves the list
		// alone rather than widen it for every route.
		add_filter( 'rest_allowed_cors_headers', array( $this, 'allow_mcp_cors_headers' ), 10, 2 );
	}

	/**
	 * Add the MCP transport headers to core's CORS allow-list, on the /mcp
	 * route only.
	 *
	 * @param string[]             $headers Allowed headers.
	 * @param WP_REST_Request|null $request Request (WordPress 6.3+ passes it).
	 * @return string[]
	 */
	public function allow_mcp_cors_headers( $headers, $request = null ) {
		if ( ! is_array( $headers ) || ! is_object( $request ) || ! method_exists( $request, 'get_route' ) || false === strpos( (string) $request->get_route(), '/mcpwp/v1/mcp' ) ) {
			return $headers;
		}
		foreach ( array( 'X-API-Key', 'Mcp-Session-Id', 'MCP-Protocol-Version' ) as $header ) {
			if ( ! in_array( $header, $headers, true ) ) {
				$headers[] = $header;
			}
		}
		return $headers;
	}

	/**
	 * Force application/json Content-Type for MCP endpoint responses.
	 *
	 * WordPress REST API may send incorrect Content-Type headers during rapid
	 * sequential requests due to output buffering or cached headers from previous
	 * responses. This filter ensures the MCP endpoint always returns JSON.
	 *
	 * @param bool             $served  Whether the request has been served.
	 * @param WP_HTTP_Response $result  Response object.
	 * @param WP_REST_Request  $request Request object.
	 * @param WP_REST_Server   $server  REST server instance.
	 * @return bool Whether the request has been served.
	 */
	public function force_json_content_type( $served, $result, $request, $server ) {
		$route = $request->get_route();
		if ( false !== strpos( $route, '/mcpwp/v1/mcp' ) ) {
			$this->clean_mcp_output_buffers();

			// Remove any previously sent content-type headers.
			if ( ! headers_sent() ) {
				header_remove( 'Content-Type' );
				header( 'Content-Type: application/json; charset=UTF-8' );
			}
		}
		return $served;
	}

	/**
	 * Register routes.
	 */
	public function register_routes() {
		// Main MCP endpoint
		register_rest_route(
			$this->namespace,
			'/mcp',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_mcp' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_mcp_get' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => 'OPTIONS',
					'callback'            => array( $this, 'handle_options' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * Handle GET request for MCP endpoint.
	 *
	 * Streamable HTTP MCP clients may issue a GET to open an SSE stream.
	 * WordPress REST API doesn't natively support SSE, so we return server
	 * metadata as a standard JSON response. This also serves as a health
	 * check / capability discovery endpoint.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function handle_mcp_get( $request ) {
		$response = new WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'result'  => array(
					// No client-requested version to negotiate against on a
					// bare capability probe — answer with the latest this
					// server speaks (#906), same fallback the negotiation
					// rule uses when nothing else matches.
					'protocolVersion' => self::LATEST_PROTOCOL_VERSION,
					'serverInfo'      => array(
						'name'    => $this->server_name,
						'version' => $this->server_version,
					),
					'capabilities'    => array(
						'tools'     => new \stdClass(),
						'resources' => new \stdClass(),
					),
				),
			),
			200
		);
		return $this->prepare_mcp_response( $response );
	}

	/**
	 * Handle OPTIONS request (CORS preflight).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function handle_options( $request ) {
		$response = new WP_REST_Response( null, 200 );
		// No version on the pre-auth path: OPTIONS is open, and an exact
		// version on an open route is a scanner's oracle.
		return $this->prepare_mcp_response( $response, false );
	}

	/**
	 * Handle MCP request.
	 *
	 * Processes JSON-RPC 2.0 requests and returns appropriate responses.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function handle_mcp( $request ) {
		// One spend budget for the whole fan-out tree of this HTTP request
		// (#752 F9): every tool call, nested batch operation and resource read
		// below draws from it. Opened here, before anything dispatches, and
		// closed in finally so it cannot outlive the request. When this runs
		// inside a tree that already holds a budget it joins that one.
		$spend_token = Mcpwp_Spend_Budget::open( $request );

		try {
			return $this->handle_mcp_messages( $request );
		} finally {
			Mcpwp_Spend_Budget::close( $spend_token );
		}
	}

	/**
	 * Answer the JSON-RPC message or batch of one /mcp request.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	private function handle_mcp_messages( $request ) {
		$body = $request->get_json_params();

		if ( empty( $body ) ) {
			return $this->jsonrpc_error_response(
				null,
				-32700,
				'Parse error: Invalid JSON. Ensure the request body is valid JSON-RPC 2.0 with Content-Type: application/json.'
			);
		}

		// One negotiated version governs every message this HTTP request
		// answers (#906) — resolved once, before any of them dispatch, so
		// handle_initialize()/handle_tools_list()/handle_tools_call() never
		// each guess differently within the same batch.
		$resolved = $this->resolve_protocol_version_for_request( $request, $body );
		if ( null === $resolved ) {
			// The header value is deliberately not echoed back.
			$rest_response = new WP_REST_Response(
				array(
					'jsonrpc' => '2.0',
					'id'      => ( is_array( $body ) && ! isset( $body[0] ) && isset( $body['id'] ) ) ? $body['id'] : null,
					'error'   => array(
						'code'    => -32600,
						'message' => 'Unsupported MCP-Protocol-Version header. Supported versions: ' . implode( ', ', self::SUPPORTED_PROTOCOL_VERSIONS ) . '.',
						'data'    => array(
							'supported' => self::SUPPORTED_PROTOCOL_VERSIONS,
							'hint'      => 'Send the protocolVersion the initialize response returned, or omit the header.',
						),
					),
				),
				400
			);
			return $this->prepare_mcp_response( $rest_response );
		}
		$this->negotiated_protocol_version = $resolved;
		$this->schema_mismatches_logged    = array();

		// Handle batch requests (array of requests) — limit batch size to prevent abuse.
		if ( isset( $body[0] ) ) {
			$max_batch = self::MAX_BATCH_REQUESTS;
			if ( count( $body ) > $max_batch ) {
				return $this->jsonrpc_error_response(
					null,
					-32600,
					sprintf( 'Batch too large: maximum %d requests per batch. Split into smaller batches.', $max_batch )
				);
			}

			// A JSON-RPC batch that cannot fit this request's spend budget is
			// refused whole before any of it runs (#752 F9 review r1): every
			// request in it is answered with the refusal, notifications are not.
			$price    = 0;
			$tool_map = $this->get_all_tool_map();
			foreach ( $body as $single_request ) {
				$price += $this->message_spend_price( $single_request, $tool_map );
			}
			$whole = Mcpwp_Spend_Budget::refuse_whole( $price );
			if ( is_wp_error( $whole ) ) {
				$refusals = array();
				foreach ( $body as $single_request ) {
					$single_id     = is_array( $single_request ) && isset( $single_request['id'] ) ? $single_request['id'] : null;
					$single_method = is_array( $single_request ) && isset( $single_request['method'] ) && is_string( $single_request['method'] ) ? $single_request['method'] : '';
					if ( null === $single_id && 0 === strpos( $single_method, 'notifications/' ) ) {
						continue;
					}
					$refusals[] = $this->spend_refusal( $single_id, $whole->get_error_message(), $whole->get_error_data(), $whole->get_error_code() );
				}
				if ( empty( $refusals ) ) {
					return $this->prepare_mcp_response( new WP_REST_Response( null, 204 ) );
				}
				return $this->prepare_mcp_response( new WP_REST_Response( $refusals, 200 ) );
			}

			$responses = array();
			foreach ( $body as $single_request ) {
				$response = $this->process_single_request( $single_request, $request );
				// Notifications return null, don't include in response
				if ( $response !== null ) {
					$responses[] = $this->attach_result_meta( $response );
				}
			}

			// A batch of nothing but notifications has no response body. An
			// empty 200 is the shape a client cannot tell from a server that
			// returned nothing (#802), so say 204 like the single-notification
			// path does.
			if ( empty( $responses ) ) {
				return $this->prepare_mcp_response( new WP_REST_Response( null, 204 ) );
			}

			$rest_response = new WP_REST_Response( array_values( $responses ), 200 );
			return $this->prepare_mcp_response( $rest_response );
		}

		// Single request
		$response = $this->process_single_request( $body, $request );

		if ( $response === null ) {
			// Notification - no response
			$rest_response = new WP_REST_Response( null, 204 );
			return $this->prepare_mcp_response( $rest_response );
		}

		$rest_response = new WP_REST_Response( $this->attach_result_meta( $response ), 200 );
		return $this->prepare_mcp_response( $rest_response );
	}

	/**
	 * JSON-RPC batch cap. A bulk job needs one round trip per hundred
	 * operations (#802); each operation is charged one rate-limit slot.
	 */
	const MAX_BATCH_REQUESTS = 100;

	/**
	 * A JSON-RPC batch costs one slot per request in it (#802).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return int
	 */
	protected function request_rate_weight( $request ) {
		$body = method_exists( $request, 'get_json_params' ) ? $request->get_json_params() : null;

		if ( is_array( $body ) && isset( $body[0] ) ) {
			return max( 1, count( $body ) );
		}

		return 1;
	}

	/**
	 * JSON-RPC methods that only ask about the session, never change the site.
	 *
	 * @var array
	 */
	private static $diagnostic_methods = array( 'initialize', 'ping' );

	/**
	 * Tools that only answer "may I continue, and when" (#802).
	 *
	 * @var array
	 */
	private static $diagnostic_tools = array( 'wp_rate_limit_status', 'wp_site_info' );

	/**
	 * @return array Tool names metered as diagnostics.
	 */
	public static function diagnostic_tools() {
		return self::$diagnostic_tools;
	}

	/**
	 * @return array JSON-RPC methods metered as diagnostics.
	 */
	public static function diagnostic_methods() {
		return self::$diagnostic_methods;
	}

	/**
	 * Whether every operation in this request is diagnostic (#802).
	 *
	 * The body is inspected, not trusted: a parse failure or an unknown method
	 * is not diagnostic, and one write anywhere in a batch makes the whole
	 * request a write. The decision only picks a rate-limit bucket — auth
	 * has already been checked by the time this runs.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	protected function is_diagnostic_request( $request ) {
		$body = method_exists( $request, 'get_json_params' ) ? $request->get_json_params() : null;

		if ( ! is_array( $body ) || empty( $body ) ) {
			return false;
		}

		$operations = isset( $body[0] ) ? $body : array( $body );

		foreach ( $operations as $operation ) {
			if ( ! is_array( $operation ) || ! $this->is_diagnostic_operation( $operation ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param array $operation One JSON-RPC request.
	 * @return bool
	 */
	private function is_diagnostic_operation( array $operation ) {
		$method = isset( $operation['method'] ) && is_string( $operation['method'] ) ? $operation['method'] : '';

		if ( '' === $method ) {
			return false;
		}

		if ( in_array( $method, self::$diagnostic_methods, true ) || 0 === strpos( $method, 'notifications/' ) ) {
			return true;
		}

		if ( 'tools/call' !== $method ) {
			return false;
		}

		$name = isset( $operation['params']['name'] ) ? $operation['params']['name'] : '';

		return is_string( $name ) && in_array( $name, self::$diagnostic_tools, true );
	}

	/**
	 * Price a JSON-RPC request as its underlying tools, not as the HTTP
	 * envelope that carries them (#891).
	 *
	 * Every call to this endpoint is an HTTP POST, so the base
	 * rate_limit_method() answer is always "POST" — a plain read like
	 * wp_get_elementor was priced exactly like a write (1x burst/minute
	 * ceiling on the caller's shared counter, not the 2x a GET gets),
	 * and reported as refused with `"bucket":"write"` even though nothing
	 * it did was destructive.
	 *
	 * Answers "GET" only when every operation in the request is a
	 * `tools/call` naming a tool this server's own map marks `method: GET`
	 * — the same map handle_tools_call_idempotent() already reads to decide
	 * a tool has nothing to protect. One write anywhere in a batch — an
	 * unknown tool, a non-GET tool, anything that is not `tools/call` at
	 * all — keeps the whole request priced at the real HTTP method, the
	 * same "every operation must qualify" rule is_diagnostic_request()
	 * applies just above. Never prices anything BELOW the real method:
	 * this can only raise a request to the read ceiling, never lower one
	 * to it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string
	 */
	protected function rate_limit_method( $request ) {
		$default = parent::rate_limit_method( $request );

		$body = method_exists( $request, 'get_json_params' ) ? $request->get_json_params() : null;

		if ( ! is_array( $body ) || empty( $body ) ) {
			return $default;
		}

		$operations = isset( $body[0] ) ? $body : array( $body );
		$tool_map   = null;

		foreach ( $operations as $operation ) {
			if ( ! is_array( $operation ) ) {
				return $default;
			}

			$method = isset( $operation['method'] ) && is_string( $operation['method'] ) ? $operation['method'] : '';

			if ( 'tools/call' !== $method ) {
				return $default;
			}

			$params    = isset( $operation['params'] ) && is_array( $operation['params'] ) ? $operation['params'] : array();
			$tool_name = isset( $params['name'] ) && is_string( $params['name'] ) ? $params['name'] : '';

			if ( '' === $tool_name ) {
				return $default;
			}

			if ( null === $tool_map ) {
				$tool_map = $this->get_all_tool_map();
			}

			if ( ! isset( $tool_map[ $tool_name ] ) ) {
				return $default;
			}

			$tool_method = isset( $tool_map[ $tool_name ]['method'] ) ? strtoupper( (string) $tool_map[ $tool_name ]['method'] ) : 'POST';

			if ( 'GET' !== $tool_method ) {
				return $default;
			}
		}

		return 'GET';
	}

	/**
	 * Put the server's own facts on every result (#802).
	 *
	 * - `plugin_version`: the plugin that produced THIS answer, so a plugin
	 *   replaced between two calls of a running job is visible without
	 *   re-running initialize. Written over whatever the result already holds,
	 *   so a replayed result names the plugin answering now, not the one that
	 *   stored it.
	 * - `rate_limit`: the caller's remaining budget, so a client can pace
	 *   itself before the wall instead of learning about it from a 429. Left
	 *   out when nothing was metered — absent, never reported as unlimited.
	 * - `spend_budget`: this request's spend budget (#752 F9) after this message.
	 *
	 * Errors are left alone: JSON-RPC error objects have no _meta, and a 429
	 * already carries its budget in its data. The X-MCPWP-Version header
	 * carries the version on every response, errors included.
	 *
	 * @param array $response JSON-RPC response.
	 * @return array
	 */
	private function attach_result_meta( $response ) {
		// Consumed exactly once per JSON-RPC message, success or error alike,
		// then cleared — a later message in the same batch (same controller
		// instance, see handle_mcp_messages()) must not inherit this one's
		// notes (#911).
		$param_fixes            = $this->last_param_fixes;
		$this->last_param_fixes = array();

		// An empty result is an object on the wire (stdClass); decorate it as a map.
		if ( is_array( $response ) && isset( $response['result'] ) && $response['result'] instanceof \stdClass ) {
			$response['result'] = get_object_vars( $response['result'] );
		}

		if ( ! is_array( $response ) || ! isset( $response['result'] ) || ! is_array( $response['result'] ) ) {
			return $response;
		}

		if ( ! isset( $response['result']['_meta'] ) || ! is_array( $response['result']['_meta'] ) ) {
			$response['result']['_meta'] = array();
		}

		$response['result']['_meta']['plugin_version'] = $this->server_version;

		// Forgiving-params notes (#911): near-miss argument keys this call
		// silently remapped onto the tool's real schema, so the caller learns
		// the canonical name instead of quietly relying on a guess forever.
		if ( ! empty( $param_fixes ) ) {
			$response['result']['_meta']['param_fixes'] = $param_fixes;
		}

		$budget = $this->current_rate_limit_budget();

		if ( null !== $budget ) {
			$response['result']['_meta']['rate_limit'] = $budget;
		}

		// What is left of this request's spend budget after this message
		// (#752 F9). Server-owned: anything a caller or a stored replay put
		// under this key is replaced.
		$spend = Mcpwp_Spend_Budget::report();

		if ( null !== $spend ) {
			$response['result']['_meta']['spend_budget'] = $spend;
		}

		return $response;
	}

	/**
	 * Idempotency record lifetime. Bulk jobs span hours; a retry a day later
	 * is a new job.
	 */
	const IDEMPOTENCY_TTL = DAY_IN_SECONDS;

	/**
	 * How long a claim on a request_id is honoured while the first attempt
	 * runs. PHP timeouts and fatals are the commonest way to lose a response,
	 * so a stuck claim must expire quickly — after this a retry may run.
	 */
	const IDEMPOTENCY_LEASE_SECONDS = 120;

	/**
	 * The lease length in force: the constant, filterable so a host with a
	 * shorter PHP time limit can lower it — and so the takeover branch is
	 * reachable under test rather than dead code.
	 *
	 * @return int At least 1.
	 */
	public static function lease_seconds() {
		$seconds = apply_filters( 'mcpwp_idempotency_lease_seconds', self::IDEMPOTENCY_LEASE_SECONDS );
		return (int) min( self::IDEMPOTENCY_TTL, max( 1, (int) $seconds ) );
	}

	/**
	 * Largest result that is pinned. Beyond this a replay would cost more than
	 * it protects, and wp_options is not a blob store.
	 */
	const IDEMPOTENCY_MAX_BYTES = 65536;

	/**
	 * Storage key for one (principal, request_id) pair.
	 *
	 * @param string $key_scope  Principal id (API key id, OAuth/broker subject).
	 * @param string $request_id Client-supplied id.
	 * @return string
	 */
	public static function idempotency_key( $key_scope, $request_id ) {
		return 'mcpwp_idem_' . hash( 'sha256', (string) $key_scope . '|' . (string) $request_id );
	}

	/**
	 * Option name of the in-flight claim for a store key.
	 *
	 * Claims live in wp_options directly rather than transients because
	 * add_option() is the one atomic primitive available on every host: the
	 * UNIQUE index on option_name makes the second of two concurrent claims
	 * fail. A transient cannot do that — get-then-set is the race.
	 *
	 * @param string $store_key From idempotency_key().
	 * @return string
	 */
	public static function idempotency_lease_name( $store_key ) {
		return $store_key . '_lease';
	}

	/**
	 * tools/call with an idempotency key (#802).
	 *
	 * `params._meta.request_id` is opt-in. Without it, behaviour is exactly
	 * handle_tools_call(). With it, the first COMPLETED result for
	 * (principal, request_id) is stored for IDEMPOTENCY_TTL and a re-send
	 * returns it without dispatching, marked `_meta.replayed`.
	 *
	 * Whether a tool is pinned is decided by the tool map's own HTTP method —
	 * anything that is not a GET has side effects — never by the scope table,
	 * which answers "how much privilege" and not "does it write". When a
	 * request_id arrives and cannot be honoured, `_meta.idempotency` says why
	 * rather than leaving the client to assume it was.
	 *
	 * @param mixed           $id      JSON-RPC id.
	 * @param array           $params  tools/call params.
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	private function handle_tools_call_idempotent( $id, $params, $request, $depth = 0 ) {
		$request_id = isset( $params['_meta']['request_id'] ) ? $params['_meta']['request_id'] : null;

		if ( null === $request_id ) {
			return $this->handle_tools_call( $id, $params, $request );
		}

		// Two re-entries at most: a store that answers differently on
		// consecutive reads must not turn into unbounded recursion. Past
		// that, the call runs unprotected and says so.
		if ( $depth >= 2 ) {
			return $this->with_idempotency_note( $this->handle_tools_call( $id, $params, $request ), 'not_stored', 'claim store inconsistent' );
		}

		if ( ! is_string( $request_id ) || '' === $request_id || strlen( $request_id ) > 128 || ! preg_match( '/^[A-Za-z0-9_\-:.]+$/', $request_id ) ) {
			return $this->jsonrpc_error(
				$id,
				-32602,
				'Invalid _meta.request_id',
				array( 'hint' => 'request_id is 1–128 characters from [A-Za-z0-9_-:.]. Make it unique per intended write and per agent — a UUID, or "<agent>:<job>:<step>" — since the namespace is shared by every client acting as the same principal.' )
			);
		}

		$tool_name = isset( $params['name'] ) && is_string( $params['name'] ) ? $params['name'] : '';
		$arguments = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();
		$tool_map  = $this->get_all_tool_map();

		// Only a tool with side effects is pinned. A GET has nothing to
		// protect, and a pinned read goes stale for a day. A name the merged
		// tool map (built-in, pro, integrations and hook-registered custom
		// tools alike) does not know cannot be classified, so it is not
		// pinned — and the client is told.
		if ( '' === $tool_name || ! isset( $tool_map[ $tool_name ] ) ) {
			return $this->with_idempotency_note( $this->handle_tools_call( $id, $params, $request ), 'not_applicable', 'tool not in the built-in map' );
		}

		$tool_method = isset( $tool_map[ $tool_name ]['method'] ) ? strtoupper( (string) $tool_map[ $tool_name ]['method'] ) : 'POST';

		if ( 'GET' === $tool_method ) {
			return $this->with_idempotency_note( $this->handle_tools_call( $id, $params, $request ), 'not_applicable', 'read tool' );
		}

		// A tool whose result cannot prove completion (a deploy that absorbs
		// failures into a success body) opts out in the map.
		if ( isset( $tool_map[ $tool_name ]['pin'] ) && false === $tool_map[ $tool_name ]['pin'] ) {
			return $this->with_idempotency_note( $this->handle_tools_call( $id, $params, $request ), 'not_applicable', 'result cannot prove completion' );
		}

		// No principal, no pinning. 'anon' would be a namespace shared by
		// every id-less legacy key and the admin chat path; an OAuth client
		// id without a user is shared by every user of that client.
		$key_record = $this->get_current_api_key_record();
		$scope      = self::principal_scope( $key_record );

		if ( '' === $scope ) {
			return $this->with_idempotency_note( $this->handle_tools_call( $id, $params, $request ), 'not_stored', 'no per-user principal id on this session' );
		}

		$store_key = self::idempotency_key( $scope, $request_id );
		// Hash the CANONICAL arguments (#831 alias-resolved, #911 near-miss
		// remapped) so postId:5 and post_id:5 under the same request_id hash
		// identically and are recognized as the same call (#911 review round
		// 1, WARN-2's NIT). Only this local hash changes — $params (still
		// carrying the caller's original spelling) is what actually reaches
		// handle_tools_call() below, which re-derives and re-validates its
		// own arguments independently, so this does not skip validation.
		$args_hash = hash( 'sha256', $tool_name . '|' . wp_json_encode( self::canonicalize( $this->canonical_tool_arguments( $tool_name, $tool_map[ $tool_name ], $arguments ) ) ) );
		$existing  = get_transient( $store_key );

		if ( is_array( $existing ) && isset( $existing['state'] ) && 'done' === $existing['state'] ) {
			// A done record without a readable response is corruption. Running
			// the write again on the strength of a broken record is the one
			// thing this feature must never do.
			if ( ! isset( $existing['args_hash'], $existing['response'] ) || ! is_array( $existing['response'] ) ) {
				return $this->tool_error_result(
					$id,
					'idempotency_corrupt',
					sprintf( 'The stored result for request_id "%s" is unreadable.', $request_id ),
					array( 'hint' => 'The first attempt completed but its record is corrupt, so it cannot be replayed and will not be re-run. Verify the site state and use a new request_id if the work is still needed.' )
				);
			}

			if ( $existing['args_hash'] !== $args_hash ) {
				return $this->tool_error_result(
					$id,
					'idempotency_conflict',
					sprintf( 'request_id "%s" was already used with different arguments.', $request_id ),
					array( 'hint' => 'A request_id identifies one intended write. Use a new id for a different call.' )
				);
			}

			// A replay skips the handler, so the handler's own gates are
			// re-checked here: a category disabled or a key downgraded since
			// the first call must not keep being served the old result.
			if ( ! $this->tool_currently_permitted( $tool_name, $key_record ) ) {
				return $this->handle_tools_call( $id, $params, $request );
			}

			if ( isset( $existing['response'] ) && is_array( $existing['response'] ) ) {
				$response       = $existing['response'];
				$response['id'] = $id;

				if ( isset( $response['result'] ) && is_array( $response['result'] ) ) {
					if ( ! isset( $response['result']['_meta'] ) || ! is_array( $response['result']['_meta'] ) ) {
						$response['result']['_meta'] = array();
					}
					$response['result']['_meta']['replayed']    = true;
					$response['result']['_meta']['replayed_at'] = isset( $existing['at'] ) ? (int) $existing['at'] : 0;

					// A pinned partial says so on every answer, not only the first
					// one — the client that lost the first answer is the one
					// replaying (review r2 G6).
					$note = $this->budget_refusal_note( $response['result'] );
					if ( null !== $note ) {
						$response = $this->with_idempotency_note( $response, 'stored', $note );
					}
				}

				// A replay is API activity even though it is not site activity —
				// and telemetry must be able to net it out, so it carries its
				// own code rather than posing as a 0 ms success.
				$this->log_mcp_activity( 'mcp_tool_replay', array( 'tool' => $tool_name, 'request_id' => $request_id ) );
				$this->fire_tool_called( $tool_name, microtime( true ), 'replayed' );

				return $response;
			}
		}

		// Atomic claim (Mcpwp_Idempotency_Claim): two concurrent identical
		// retries, exactly one runs. The lease is released only after the
		// done-record is stored — never before.
		$lease_name = self::idempotency_lease_name( $store_key );
		$now        = time();
		$claim      = Mcpwp_Idempotency_Claim::acquire( $lease_name, $now, $args_hash );

		if ( Mcpwp_Idempotency_Claim::UNAVAILABLE === $claim ) {
			// The store could not arbitrate — or the holder released between
			// our refused insert and our read, which on a localized driver is
			// indistinguishable. A completed record decides it before anything
			// runs; only then is the call run unprotected, and said so.
			$done = get_transient( $store_key );
			if ( is_array( $done ) && isset( $done['state'] ) && 'done' === $done['state'] ) {
				return $this->handle_tools_call_idempotent( $id, $params, $request, $depth + 1 );
			}

			return $this->with_idempotency_note( $this->handle_tools_call( $id, $params, $request ), 'not_stored', 'claim store unavailable' );
		}

		if ( Mcpwp_Idempotency_Claim::HELD === $claim ) {
			$lease = Mcpwp_Idempotency_Claim::read( $lease_name );

			// Released between our insert and this read: the first attempt
			// just finished. Its record is the answer, so look again.
			if ( null === $lease ) {
				$done = get_transient( $store_key );
				if ( is_array( $done ) && isset( $done['state'] ) && 'done' === $done['state'] ) {
					return $this->handle_tools_call_idempotent( $id, $params, $request, $depth + 1 );
				}
				$claim = Mcpwp_Idempotency_Claim::acquire( $lease_name, $now, $args_hash );
				if ( Mcpwp_Idempotency_Claim::ACQUIRED !== $claim ) {
					return $this->in_progress_error( $id, $request_id, 1 );
				}
			} else {
				$age   = $now - (int) $lease['at'];
				$lease_seconds = self::lease_seconds();

				// A lease from the future (the clock was corrected backwards)
				// is abandoned, not held forever (#802 round 4).
				if ( $age >= 0 && $age < $lease_seconds ) {
					if ( '' !== $lease['args_hash'] && $lease['args_hash'] !== $args_hash ) {
						return $this->tool_error_result(
							$id,
							'idempotency_conflict',
							sprintf( 'request_id "%s" was already used with different arguments.', $request_id ),
							array( 'hint' => 'A request_id identifies one intended write. Use a new id for a different call.' )
						);
					}

					return $this->in_progress_error( $id, $request_id, max( 1, $lease_seconds - $age ) );
				}

				// Abandoned by a timeout or fatal. Take it over with a
				// compare-and-swap so two retries racing for the same stale
				// lease cannot both win.
				if ( ! Mcpwp_Idempotency_Claim::take_over( $lease_name, $lease['raw'], $now, $args_hash ) ) {
					return $this->in_progress_error( $id, $request_id, 1 );
				}
			}
		}

		$response = $this->handle_tools_call( $id, $params, $request );

		try {
			$completed = is_array( $response )
				&& isset( $response['result'] )
				&& is_array( $response['result'] )
				&& empty( $response['result']['isError'] );

			if ( ! $completed ) {
				// Not pinned: the retry must be allowed to do the work.
				return $response;
			}

			// A 200 that carries its own failure list is not complete either —
			// pinning it would turn the reporter's duplicate into a silent drop.
			if ( $this->result_reports_partial_failure( $response['result'], $tool_map[ $tool_name ] ) ) {
				return $this->with_idempotency_note( $response, 'not_stored', 'partial failure: a retry is NOT protected and re-runs the operations that succeeded; retry only the failed ones, each under its own request_id' );
			}

			$encoded = wp_json_encode( $response );

			if ( false === $encoded || strlen( $encoded ) > self::IDEMPOTENCY_MAX_BYTES ) {
				return $this->with_idempotency_note( $response, 'not_stored', 'result too large to pin' );
			}

			$stored = set_transient(
				$store_key,
				array(
					'state'     => 'done',
					'args_hash' => $args_hash,
					'at'        => $now,
					'response'  => $response,
				),
				self::IDEMPOTENCY_TTL
			);

			if ( ! $stored ) {
				// Say so. A client that believes it is protected and is not will
				// retry into a duplicate.
				return $this->with_idempotency_note( $response, 'not_stored', 'storage write failed' );
			}

			return $this->with_idempotency_note( $response, 'stored', $this->budget_refusal_note( $response['result'] ) );
		} finally {
			// Only now. Releasing before the record exists is a duplicate window.
			Mcpwp_Idempotency_Claim::release( $lease_name );
		}
	}

	/**
	 * @param mixed  $id          JSON-RPC id.
	 * @param string $request_id  Client id.
	 * @param int    $retry_after Seconds.
	 * @return array
	 */
	private function in_progress_error( $id, $request_id, $retry_after ) {
		return $this->tool_error_result(
			$id,
			'idempotency_in_progress',
			sprintf( 'A call with request_id "%s" is still in progress.', $request_id ),
			array(
				'hint'        => sprintf( 'The first attempt has not finished. Wait and re-send with the same request_id; if it completes you get its result, not a second write. A claim older than %d seconds is treated as abandoned and the retry runs.', self::lease_seconds() ),
				'retry_after' => (int) $retry_after,
			)
		);
	}

	/**
	 * The idempotency namespace for a principal, or '' when there is no
	 * per-user principal to scope to.
	 *
	 * @param mixed $key_record Current key record.
	 * @return string
	 */
	public static function principal_scope( $key_record ) {
		if ( ! is_array( $key_record ) || empty( $key_record['id'] ) || ! is_string( $key_record['id'] ) ) {
			return '';
		}

		$id = $key_record['id'];

		// oauth-client:<client> is one namespace for every user of that
		// client — a cross-user replay. Only user-scoped subjects qualify.
		if ( 0 === strpos( $id, 'oauth-client:' ) || 'anon' === $id ) {
			return '';
		}

		return $id;
	}

	/**
	 * @param array       $response JSON-RPC response.
	 * @param string      $state    stored|not_stored|not_applicable.
	 * @param string|null $reason   Why, when not stored.
	 * @return array
	 */
	private function with_idempotency_note( $response, $state, $reason ) {
		if ( ! is_array( $response ) || ! isset( $response['result'] ) || ! is_array( $response['result'] ) ) {
			return $response;
		}

		if ( ! isset( $response['result']['_meta'] ) || ! is_array( $response['result']['_meta'] ) ) {
			$response['result']['_meta'] = array();
		}

		$response['result']['_meta']['idempotency'] = $state;

		if ( null !== $reason ) {
			$response['result']['_meta']['idempotency_reason'] = $reason;
		}

		return $response;
	}

	/**
	 * Whether the handler's own gates would let this tool run right now.
	 *
	 * @param string     $tool_name  Tool.
	 * @param array|null $key_record Current key record.
	 * @return bool
	 */
	private function tool_currently_permitted( $tool_name, $key_record ) {
		// The same resolver the handler uses. Inventing a category here that
		// the handler does not enforce sent a refused replay back INTO the
		// handler, which ran the write again (#802 round 4).
		$tool_category = $this->tool_category_for( $tool_name );

		$disabled = get_option( 'mcpwp_disabled_tool_categories', array() );
		if ( '' !== $tool_category && is_array( $disabled ) && in_array( $tool_category, $disabled, true ) ) {
			return false;
		}

		if ( $key_record ) {
			$allowed = $this->resolve_key_tool_categories( $key_record );
			if ( ! empty( $allowed ) && ( '' === $tool_category || ! in_array( $tool_category, $allowed, true ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The category a tool is enforced under, or '' when it has none. One
	 * function, so the handler's gate and the replay's gate cannot disagree.
	 *
	 * @param string $tool_name Tool.
	 * @return string
	 */
	private function tool_category_for( $tool_name ) {
		$all_categories = $this->get_all_tool_categories();
		return isset( $all_categories[ $tool_name ] ) ? (string) $all_categories[ $tool_name ] : '';
	}

	/**
	 * A tool result whose body carries a failure list is partial, not done.
	 *
	 * @param array $result JSON-RPC result.
	 * @return bool
	 */
	private function result_reports_partial_failure( array $result, array $mapping = array() ) {
		// A validation tool's job is to FIND errors; a non-empty errors[] is
		// its success, not a partial failure.
		if ( isset( $mapping['route'] ) && false !== strpos( (string) $mapping['route'], '/validate' ) ) {
			return false;
		}

		if ( empty( $result['content'] ) || ! is_array( $result['content'] ) ) {
			return false;
		}

		foreach ( $result['content'] as $block ) {
			if ( empty( $block['text'] ) || ! is_string( $block['text'] ) ) {
				continue;
			}

			$data = json_decode( $block['text'], true );

			if ( ! is_array( $data ) ) {
				continue;
			}

			foreach ( array( 'errors', 'unreadable', 'skipped', 'not_found' ) as $key ) {
				if ( ! empty( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
					return true;
				}
			}

			// Operations the spend budget refused did not run: the ones that ran
			// are done and the refused ones are named in the body, so a result
			// whose only failures are those refusals is complete and is pinned.
			// A replay returns it; the client resends the refused operations
			// under a new request_id (#752 F9 review r1).
			if ( isset( $data['failed'] ) && (int) $data['failed'] > 0 && (int) $data['failed'] !== self::budget_refusals_in( $data ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * How many operations of a batch body the spend budget refused.
	 *
	 * @param array $data Batch body.
	 * @return int
	 */
	private static function budget_refusals_in( array $data ) {
		if ( empty( $data['results'] ) || ! is_array( $data['results'] ) ) {
			return 0;
		}

		$refused = 0;
		foreach ( $data['results'] as $result ) {
			// The budget's own refusal only: status 413 AND its code. A 200 that
			// carries the code in its body ran, and counting it would pin a real
			// failure beside it (review r2 G2).
			if ( is_array( $result ) && isset( $result['status'], $result['data'] ) && Mcpwp_Spend_Budget::HTTP_STATUS === (int) $result['status'] && is_array( $result['data'] ) && isset( $result['data']['code'] ) && Mcpwp_Spend_Budget::ERROR_CODE === $result['data']['code'] ) {
				++$refused;
			}
		}

		return $refused;
	}

	/**
	 * The idempotency reason for a pinned result that carries budget refusals,
	 * or null when it carries none.
	 *
	 * @param array $result JSON-RPC result.
	 * @return string|null
	 */
	private function budget_refusal_note( array $result ) {
		$refused = $this->result_budget_refusals( $result );

		return $refused > 0
			? sprintf( 'stored with %d operation(s) refused by the spend budget: they did not run, and a replay of this request_id returns this result without running them. Resend the refused operations under a new request_id.', $refused )
			: null;
	}

	/**
	 * Operations refused by the spend budget in a tool result's batch body.
	 *
	 * @param array $result JSON-RPC result.
	 * @return int
	 */
	private function result_budget_refusals( array $result ) {
		if ( empty( $result['content'] ) || ! is_array( $result['content'] ) ) {
			return 0;
		}

		$refused = 0;
		foreach ( $result['content'] as $block ) {
			$data = is_array( $block ) && isset( $block['text'] ) && is_string( $block['text'] ) ? json_decode( $block['text'], true ) : null;
			if ( is_array( $data ) ) {
				$refused += self::budget_refusals_in( $data );
			}
		}

		return $refused;
	}

	/**
	 * Recursively key-sort so two SDKs' serialisations of the same arguments
	 * hash the same.
	 *
	 * @param mixed $value Arguments.
	 * @return mixed
	 */
	private static function canonicalize( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		foreach ( $value as $k => $v ) {
			$value[ $k ] = self::canonicalize( $v );
		}

		// Lists keep their order; maps are sorted.
		if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
			ksort( $value, SORT_STRING );
		}

		return $value;
	}

	/**
	 * @return array|null
	 */
	private function current_rate_limit_budget() {
		if ( ! class_exists( 'Mcpwp_Rate_Limiter' ) ) {
			return null;
		}

		return Mcpwp_Rate_Limiter::get_instance()->get_budget();
	}

	/**
	 * How many rate-limit slots a tool call costs: the items its list
	 * arguments carry, or the tool's fixed weight when it acts on many posts
	 * without carrying a list (wp_bulk_find_replace, wp_delete_all_drafts).
	 * One when neither applies. Mcpwp_Operation_Weights holds the lists and the
	 * per-tool map, so there is one place to price a tool.
	 *
	 * @param array  $arguments Tool arguments.
	 * @param string $tool_name Tool name ('' prices the lists alone).
	 * @return int
	 */
	private function nested_operation_count( $arguments, $tool_name = '' ) {
		return Mcpwp_Operation_Weights::tool_weight( $tool_name, $arguments );
	}

	/**
	 * Process a single JSON-RPC request.
	 *
	 * @param array           $body    JSON-RPC request body.
	 * @param WP_REST_Request $request Original REST request.
	 * @return array|null Response array or null for notifications.
	 */
	private function process_single_request( $body, $request ) {
		$method = isset( $body['method'] ) && is_string( $body['method'] ) ? $body['method'] : '';
		$id     = isset( $body['id'] ) ? $body['id'] : null;
		$params = isset( $body['params'] ) ? $body['params'] : array();

		// Notifications have no id - don't respond
		if ( $id === null && 0 === strpos( $method, 'notifications/' ) ) {
			return null;
		}

		// Route to appropriate handler
		switch ( $method ) {
			case 'initialize':
				return $this->handle_initialize( $id, $params );

			case 'notifications/initialized':
				// Without an id this is a notification and was answered with
				// nothing above. With an id it is a request, and JSON-RPC
				// answers every request: dropping it leaves the client waiting
				// on that id, and a lone one came back as a body-less 204 (#802).
				return array(
					'jsonrpc' => '2.0',
					'id'      => $id,
					'result'  => new \stdClass(),
				);

			case 'tools/list':
				return $this->handle_tools_list( $id, $params );

			case 'tools/call':
				return $this->conform_tool_call_result(
					$this->handle_tools_call_idempotent( $id, $params, $request ),
					is_array( $params ) && isset( $params['name'] ) && is_string( $params['name'] ) ? $params['name'] : '',
					is_array( $params ) && isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array()
				);

			case 'resources/list':
				return $this->handle_resources_list( $id );

			case 'resources/read':
				return $this->handle_resources_read( $id, $params, $request );

			case 'prompts/list':
				return $this->handle_prompts_list( $id );

			case 'prompts/get':
				return $this->handle_prompts_get( $id, $params );

			case 'ping':
				return $this->handle_ping( $id );

			default:
				return $this->jsonrpc_error(
					$id,
					-32601,
					'Method not found: ' . $method,
					array( 'hint' => 'Supported MCP methods: initialize, tools/list, tools/call, resources/list, resources/read, prompts/list, prompts/get, ping. Check the method name for typos.' )
				);
		}
	}

	/**
	 * Pick the protocol version to answer with, per the spec's negotiation
	 * rule (#906): echo back a version this server actually speaks;
	 * otherwise answer with the latest it speaks. Applies the same to a
	 * missing/non-string request (no `protocolVersion` in `initialize`, no
	 * usable `MCP-Protocol-Version` header) — nothing to match, so latest.
	 *
	 * @param mixed $requested Client-requested protocolVersion, if any.
	 * @return string
	 */
	private function negotiate_protocol_version( $requested ) {
		if ( is_string( $requested ) && in_array( $requested, self::SUPPORTED_PROTOCOL_VERSIONS, true ) ) {
			return $requested;
		}
		return self::LATEST_PROTOCOL_VERSION;
	}

	/**
	 * Resolve the protocol version governing this whole HTTP request.
	 *
	 * A JSON-RPC batch answers under one version. An `initialize` in it
	 * wins — that is the moment a version is negotiated, and the handshake
	 * never fails on version (negotiate_protocol_version()). Otherwise the
	 * transport-level signal decides: the `MCP-Protocol-Version` header the
	 * client MUST send on every request after initialize
	 * (basic/transports#protocol-version-header):
	 *
	 * - absent (or empty): assume 2025-03-26, as the spec directs for
	 *   backwards compatibility — so a client that predates the header gets
	 *   the shape it was written against;
	 * - a supported version: that version;
	 * - anything else: null — the caller answers 400 Bad Request, as the
	 *   spec requires for an invalid or unsupported header.
	 *
	 * @param WP_REST_Request $request Original request (read for the header).
	 * @param array            $body    Decoded JSON-RPC body — one message or a batch.
	 * @return string|null Null when the header names no supported version.
	 */
	private function resolve_protocol_version_for_request( $request, $body ) {
		$operations = ( is_array( $body ) && isset( $body[0] ) ) ? $body : array( $body );

		foreach ( $operations as $operation ) {
			if ( is_array( $operation ) && isset( $operation['method'] ) && 'initialize' === $operation['method'] ) {
				$params = isset( $operation['params'] ) && is_array( $operation['params'] ) ? $operation['params'] : array();
				return $this->negotiate_protocol_version( isset( $params['protocolVersion'] ) ? $params['protocolVersion'] : null );
			}
		}

		$header = ( $request && method_exists( $request, 'get_header' ) ) ? $request->get_header( 'MCP-Protocol-Version' ) : null;
		$header = is_string( $header ) ? trim( $header ) : '';
		if ( '' === $header ) {
			return self::HEADERLESS_PROTOCOL_VERSION;
		}

		// A header sent twice reaches WordPress comma-joined. The same
		// value repeated ("2025-06-18, 2025-06-18") is that value; two
		// different values are ambiguous and refused like any bad one.
		$values = array_values( array_unique( array_map( 'trim', explode( ',', $header ) ) ) );
		if ( 1 !== count( $values ) ) {
			return null;
		}

		return in_array( $values[0], self::SUPPORTED_PROTOCOL_VERSIONS, true ) ? $values[0] : null;
	}

	/**
	 * Whether the negotiated protocol version defines `title` (top-level),
	 * `outputSchema`, and `structuredContent` for a tool's own declared
	 * output (#906).
	 *
	 * @return bool
	 */
	private function protocol_version_has_structured_output() {
		return in_array( $this->negotiated_protocol_version, self::STRUCTURED_OUTPUT_PROTOCOL_VERSIONS, true );
	}

	/**
	 * Strip the >=2025-06-18 Tool fields from a tool definition for a client
	 * negotiated to an older protocol version (#906). `annotations.title` is
	 * left untouched — #934 put it there specifically so a pre-2025-06-18
	 * client still gets a display name.
	 *
	 * @param array $tool Tool definition.
	 * @return array
	 */
	private function strip_structured_output_fields( $tool ) {
		unset( $tool['title'], $tool['outputSchema'] );
		return $tool;
	}

	/**
	 * Look up a tool's declared outputSchema, if any (#906), with the error
	 * envelope admitted (Mcpwp_MCP_Tool_Registry::with_error_envelope()).
	 *
	 * Indexed once per controller instance — one per HTTP request — from
	 * the registries directly rather than get_all_tools(): that filters out
	 * tools whose category is disabled or whose plugin is inactive, but a
	 * client keeps the schema from an earlier tools/list, so a call to a
	 * since-disabled tool must still be shaped against it. The Pro registry
	 * is consulted only where Pro is active (a Free site cannot run a Pro
	 * tool, so building that list there was pure cost). Custom tools cannot
	 * declare an outputSchema (Mcpwp_Custom_Tool_Registry::get_tools()).
	 *
	 * @param string $tool_name Tool name.
	 * @return array|null
	 */
	private function get_tool_output_schema( $tool_name ) {
		if ( null === $this->tool_output_schemas ) {
			$registries = array( $this->free_registry );
			if ( $this->is_pro_active() ) {
				$registries[] = $this->pro_registry;
			}
			foreach ( Mcpwp_Integration::resolve_all() as $integration ) {
				$registries[] = $integration;
			}
			$this->tool_output_schemas = array();
			foreach ( $registries as $registry ) {
				if ( ! is_object( $registry ) || ! method_exists( $registry, 'get_tools' ) ) {
					continue;
				}
				foreach ( (array) $registry->get_tools() as $tool ) {
					if ( isset( $tool['name'], $tool['outputSchema'] ) && is_string( $tool['name'] ) && is_array( $tool['outputSchema'] ) ) {
						$this->tool_output_schemas[ $tool['name'] ] = Mcpwp_MCP_Tool_Registry::with_error_envelope( $tool['outputSchema'] );
					}
				}
			}
		}
		return isset( $this->tool_output_schemas[ $tool_name ] ) ? $this->tool_output_schemas[ $tool_name ] : null;
	}

	/**
	 * Make a tools/call result agree with the negotiated protocol version
	 * and the tool's declared outputSchema — the one place every tools/call
	 * answer passes through, whichever of handle_tools_call()'s many exits
	 * or the idempotent replay path produced it (#906).
	 *
	 * - Pre-2025-06-18: a successful result carries no structuredContent
	 *   (an isError result keeps #936's structuredContent.error).
	 * - 2025-06-18+, tool declares an outputSchema: a successful result's
	 *   structuredContent is built here from its text block (the same data
	 *   — it is how a replay, stored without one, gets it too). A top-level
	 *   JSON list is wrapped as {"items": [...]}, since structuredContent is
	 *   always an object. The candidate — or an isError result's error
	 *   envelope — is then VALIDATED against the schema server-side before
	 *   it is emitted, and a failure is logged once per tool per request.
	 *   The reference SDK client throws on a mismatching structuredContent
	 *   AND on a missing one ("has an output schema but did not return
	 *   structured content"), so for a successful result simply omitting it
	 *   would still end in a client exception. Instead the structured copy
	 *   is replaced by the error envelope every schema admits —
	 *   {error: {code: output_schema_mismatch, ...}} — while the text block
	 *   (the full data) and isError stay exactly as they were. An isError
	 *   result that fails (not reachable today) just loses structuredContent,
	 *   which that client allows. Never throws.
	 *
	 * @param array|null $response  JSON-RPC response from the tools/call handler.
	 * @param string     $tool_name Tool name as the client called it.
	 * @return array|null
	 */
	private function apply_output_schema( $response, $tool_name ) {
		if ( ! is_array( $response ) || ! isset( $response['result'] ) || ! is_array( $response['result'] ) ) {
			return $response;
		}

		$is_error = ! empty( $response['result']['isError'] );

		if ( ! $this->protocol_version_has_structured_output() ) {
			if ( ! $is_error ) {
				unset( $response['result']['structuredContent'] );
			}
			return $response;
		}

		$schema = '' === $tool_name ? null : $this->get_tool_output_schema( $tool_name );
		if ( null === $schema ) {
			return $response;
		}

		if ( $is_error ) {
			$candidate = isset( $response['result']['structuredContent'] ) ? json_decode( (string) wp_json_encode( $response['result']['structuredContent'] ) ) : null;
		} else {
			$text      = isset( $response['result']['content'][0]['text'] ) ? $response['result']['content'][0]['text'] : null;
			$candidate = is_string( $text ) ? json_decode( $text ) : null;
			if ( is_array( $candidate ) ) {
				$candidate = empty( $candidate ) ? new \stdClass() : (object) array( 'items' => $candidate );
			}
		}

		$errors = $candidate instanceof \stdClass
			? Mcpwp_MCP_Output_Schema::validate( $candidate, $schema )
			: array( '$: result is not a JSON object' );

		if ( array() === $errors ) {
			$response['result']['structuredContent'] = $candidate;
			return $response;
		}

		if ( $is_error ) {
			unset( $response['result']['structuredContent'] );
		} else {
			$response['result']['structuredContent'] = array(
				'error' => array(
					'code'    => 'output_schema_mismatch',
					'message' => 'The structured copy of this result did not match the tool\'s outputSchema and was withheld. The full result is in the text content.',
					'path'    => (string) strtok( $errors[0], ':' ),
				),
			);
		}
		if ( ! isset( $this->schema_mismatches_logged[ $tool_name ] ) ) {
			$this->schema_mismatches_logged[ $tool_name ] = true;
			error_log( sprintf( 'MCPWP: %s result does not match its outputSchema; structured copy withheld (%s)', $tool_name, implode( '; ', array_slice( $errors, 0, 3 ) ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
		return $response;
	}

	/**
	 * The one place every tools/call answer passes through, whichever of
	 * handle_tools_call()'s many exits or the idempotent replay path
	 * produced it: makes the result agree with the negotiated protocol
	 * version and the tool's outputSchema (#906, apply_output_schema()),
	 * then appends next-step hints for a successful result (#912,
	 * apply_next_step_hints()). Order matters: hints read the SAME text
	 * block apply_output_schema() validated structuredContent from, and are
	 * never written into structuredContent themselves.
	 *
	 * @param array|null $response  JSON-RPC response from the tools/call handler.
	 * @param string     $tool_name Tool name as the client called it.
	 * @param array      $arguments Arguments as the client sent them, for
	 *                              building a next-step hint's own call.
	 * @return array|null
	 */
	private function conform_tool_call_result( $response, $tool_name, $arguments = array() ) {
		$response = $this->apply_output_schema( $response, $tool_name );
		return $this->apply_next_step_hints( $response, $tool_name, is_array( $arguments ) ? $arguments : array() );
	}

	/**
	 * Append next-step hints to a successful tools/call result (#912).
	 *
	 * Advisory only: a short "Next: ..." line is appended to the text
	 * content block, and the same suggestions are given machine-readable
	 * under result._meta['mcpwp/next'] as [{tool, why, arguments}, ...].
	 * NEVER written into structuredContent — that is validated against the
	 * tool's own declared outputSchema (#906) and must not carry a
	 * suggestion no schema describes.
	 *
	 * Runs identically for a fresh dispatch and an idempotent replay (#802):
	 * both reach this from the same conform_tool_call_result() chokepoint,
	 * and a replay's stored text/arguments are read fresh here every time —
	 * nothing about a hint is itself stored, so re-running this on the same
	 * stored result never compounds.
	 *
	 * A hint naming a tool the caller cannot currently reach (category
	 * disabled by the site admin, or outside the API key's allowed
	 * categories) is dropped before it is shown — same gate the idempotent
	 * replay path already uses for its own permission recheck.
	 *
	 * @param array|null $response  JSON-RPC response, already schema-conformed.
	 * @param string     $tool_name Tool name as the client called it.
	 * @param array      $arguments Arguments as the client sent them.
	 * @return array|null
	 */
	private function apply_next_step_hints( $response, $tool_name, array $arguments ) {
		if ( ! is_array( $response ) || ! isset( $response['result'] ) || ! is_array( $response['result'] ) ) {
			return $response;
		}
		if ( '' === $tool_name || ! empty( $response['result']['isError'] ) ) {
			return $response;
		}
		if ( ! class_exists( 'Mcpwp_MCP_Next_Step_Hints' ) ) {
			return $response;
		}

		$text        = isset( $response['result']['content'][0]['text'] ) && is_string( $response['result']['content'][0]['text'] )
			? $response['result']['content'][0]['text']
			: null;
		$decoded     = null !== $text ? json_decode( $text, true ) : null;
		$result_data = is_array( $decoded ) ? $decoded : array();

		$hints = Mcpwp_MCP_Next_Step_Hints::for_tool( $tool_name, $arguments, $result_data );
		if ( empty( $hints ) ) {
			return $response;
		}

		$key_record = $this->get_current_api_key_record();
		$available  = array();
		foreach ( $hints as $hint ) {
			if ( isset( $hint['tool'] ) && $this->tool_currently_permitted( $hint['tool'], $key_record ) ) {
				$available[] = $hint;
			}
		}
		if ( empty( $available ) ) {
			return $response;
		}

		// An empty PHP array is a JSON array ([]) unless cast to an object —
		// a hint's `arguments` is always a JSON-RPC arguments OBJECT, even
		// when it has no keys (e.g. wp_get_site_context takes none), so a
		// no-arg hint must read as {} on the wire, not [].
		$as_json_object = function ( array $args ) {
			return empty( $args ) ? new \stdClass() : $args;
		};

		if ( null !== $text ) {
			$lines = array();
			foreach ( $available as $hint ) {
				$lines[] = sprintf(
					'Next: %s(%s) to %s.',
					$hint['tool'],
					(string) wp_json_encode( $as_json_object( $hint['arguments'] ) ),
					$hint['why']
				);
			}
			$response['result']['content'][0]['text'] = $text . "\n\n" . implode( "\n", $lines );
		}

		if ( ! isset( $response['result']['_meta'] ) || ! is_array( $response['result']['_meta'] ) ) {
			$response['result']['_meta'] = array();
		}
		$response['result']['_meta']['mcpwp/next'] = array_map(
			function ( $hint ) use ( $as_json_object ) {
				return array(
					'tool'      => $hint['tool'],
					'why'       => $hint['why'],
					'arguments' => $as_json_object( $hint['arguments'] ),
				);
			},
			$available
		);

		return $response;
	}

	/**
	 * Handle initialize method.
	 *
	 * @param mixed $id     Request ID.
	 * @param array $params Request parameters.
	 * @return array JSON-RPC response.
	 */
	private function handle_initialize( $id, $params ) {
		$client_info = isset( $params['clientInfo'] ) ? $params['clientInfo'] : array();

		$this->log_mcp_activity(
			'mcp_initialize',
			array(
				'client' => $client_info,
			)
		);

		// Include role info so AI clients know their access constraints upfront.
		$key_record         = $this->get_current_api_key_record();
		$role               = $key_record && isset( $key_record['role'] ) ? $key_record['role'] : 'admin';
		$allowed_categories = $key_record ? $this->resolve_key_tool_categories( $key_record ) : array();

		$server_info = array(
			'name'    => $this->server_name,
			'version' => $this->server_version,
		);

		if ( 'admin' !== $role ) {
			$server_info['role']            = $role;
			$server_info['tool_categories'] = $allowed_categories;
		}

		// What a bulk client needs before it starts (#802): the plugin version
		// so a swap under a running job is detectable, the budget so it can
		// pace itself, and how many lanes the host can be expected to carry.
		$server_info['plugin_version']   = $this->server_version;
		$server_info['concurrency_hint'] = max( 1, (int) apply_filters( 'mcpwp_concurrency_hint', 2 ) );
		$server_info['rate_limit']       = $this->describe_rate_limit( 'admin' === $role );

		// Site posture is for admin keys only, like role/tool_categories
		// above: a read-only key does not need the core version or the
		// origin hostname to pace itself.
		if ( 'admin' === $role ) {
			$server_info['wp_version'] = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : '';
			$server_info['site_url']   = function_exists( 'home_url' ) ? (string) home_url( '/' ) : '';
		}

		$result = array(
			// Resolved once for this whole HTTP request by
			// resolve_protocol_version_for_request() before dispatch (#906).
			'protocolVersion' => $this->negotiated_protocol_version,
			'serverInfo'      => $server_info,
			'capabilities'    => array(
				'tools'     => (object) array(),
				'resources' => array(
					'subscribe'   => false,
					'listChanged' => false,
				),
				'prompts'   => array(
					'listChanged' => false,
				),
			),
			'instructions'    => $this->build_server_instructions(),
		);

		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);
	}

	/**
	 * Handle ping method.
	 *
	 * @param mixed $id Request ID.
	 * @return array JSON-RPC response.
	 */
	private function handle_ping( $id ) {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => array(
				'pong' => true,
			),
		);
	}

	/**
	 * Handle tools/list method.
	 *
	 * Supports optional category filter via params.category.
	 * Filters tools based on the API key's role and allowed categories.
	 *
	 * @param mixed $id     Request ID.
	 * @param array $params Optional request parameters.
	 * @return array JSON-RPC response.
	 */
	private function handle_tools_list( $id, $params = array() ) {
		$this->log_mcp_activity( 'mcp_tools_list', array( 'params' => $params ) );

		// Build tools from all registries (with caching).
		if ( $this->tools_cache === null ) {
			$this->tools_cache = $this->get_all_tools();
		}

		$tools = $this->tools_cache;

		// Filter by API key role (tool category restrictions).
		$tools = $this->filter_tools_by_key_role( $tools );

		// Filter by category if requested.
		$category_filter = isset( $params['category'] ) ? sanitize_key( $params['category'] ) : '';
		if ( '' !== $category_filter ) {
			$tools = array_values(
				array_filter(
					$tools,
					function ( $tool ) use ( $category_filter ) {
						$cat = isset( $tool['annotations']['category'] ) ? $tool['annotations']['category'] : 'site';
						return $cat === $category_filter;
					}
				)
			);
		}

		// A client negotiated to an older protocol version does not get
		// fields that version's Tool shape never defined (#906) — the
		// input/annotations/route surface is unchanged either way.
		if ( ! $this->protocol_version_has_structured_output() ) {
			$tools = array_map( array( $this, 'strip_structured_output_fields' ), $tools );
		} else {
			// A third-party integration may hand over a raw outputSchema; it
			// gets the same error envelope a built-in schema gets from
			// define_tool(), or its every isError result would fail the
			// client's validation. Idempotent for schemas that have it.
			$tools = array_map(
				function ( $tool ) {
					if ( isset( $tool['outputSchema'] ) && is_array( $tool['outputSchema'] ) ) {
						$tool['outputSchema'] = Mcpwp_MCP_Tool_Registry::with_error_envelope( $tool['outputSchema'] );
					}
					return $tool;
				},
				$tools
			);
		}

		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => array(
				'tools' => $tools,
			),
		);
	}

	/**
	 * Filter tools list based on the current API key's role and allowed categories.
	 *
	 * @param array $tools Full tools list.
	 * @return array Filtered tools list.
	 */
	private function filter_tools_by_key_role( $tools ) {
		$key_record = $this->get_current_api_key_record();
		if ( ! $key_record ) {
			return $tools; // No key context (shouldn't happen), return all.
		}

		$allowed_categories = $this->resolve_key_tool_categories( $key_record );

		// Empty = unrestricted (admin role).
		if ( empty( $allowed_categories ) ) {
			return $tools;
		}

		$all_categories = $this->get_all_tool_categories();

		return array_values(
			array_filter(
				$tools,
				function ( $tool ) use ( $allowed_categories, $all_categories ) {
					// Same rule as the handler's per-key gate: an uncategorised
					// tool is denied to a category-restricted key, so it is not
					// listed to it either (#802 round 6).
					$name     = isset( $tool['name'] ) ? $tool['name'] : '';
					$category = isset( $all_categories[ $name ] ) ? (string) $all_categories[ $name ] : '';
					return '' !== $category && in_array( $category, $allowed_categories, true );
				}
			)
		);
	}

	/**
	 * Canonical resource definitions.
	 *
	 * The single source of truth for both resources/list (what gets
	 * advertised) and resources/read (what is resolvable) so the two can
	 * never drift — resources/read only ever proxies a route that
	 * resources/list actually advertised (#902).
	 *
	 * @return array<int, array{key:string, route:string, query:array, name:string, description:string, mimeType:string, text_field?:string}>
	 */
	private function mcp_resource_definitions() {
		return array(
			array(
				'key'         => 'site-info',
				'route'       => '/mcpwp/v1/site-info',
				'query'       => array(),
				'name'        => 'Site Info',
				'description' => 'WordPress site title, URL, active plugins, Elementor mode, and MCP capabilities. Read this before any content work.',
				'mimeType'    => 'application/json',
			),
			array(
				'key'         => 'site-context',
				'route'       => '/mcpwp/v1/site-context',
				'query'       => array(),
				'name'        => 'Site Context',
				'description' => 'Brand context and style guide set by the site owner. Read before writing or designing content.',
				'mimeType'    => 'text/plain',
				// get_site_context() returns a structured object, not a plain
				// string; extract the field the advertised text/plain
				// mimeType actually promises instead of dumping raw JSON.
				'text_field'  => 'effective_context',
			),
			array(
				'key'         => 'pages',
				'route'       => '/mcpwp/v1/pages',
				'query'       => array( 'per_page' => 50 ),
				'name'        => 'Pages',
				'description' => 'All published pages with IDs, slugs, and titles. Use to find page IDs before editing.',
				'mimeType'    => 'application/json',
			),
			array(
				'key'         => 'posts',
				'route'       => '/mcpwp/v1/posts',
				'query'       => array( 'per_page' => 50 ),
				'name'        => 'Recent Posts',
				'description' => 'Most recent 50 posts with IDs, slugs, titles, and categories.',
				'mimeType'    => 'application/json',
			),
			array(
				'key'         => 'design-references',
				'route'       => '/mcpwp/v1/design-references',
				'query'       => array(),
				'name'        => 'Design References',
				'description' => 'Stored design references (screenshots, approved designs) that should guide visual page creation.',
				'mimeType'    => 'application/json',
			),
			array(
				'key'         => 'elementor-status',
				'route'       => '/mcpwp/v1/elementor/status',
				'query'       => array(),
				'name'        => 'Elementor Status',
				'description' => 'Elementor version and layout mode (section or container/flexbox). Always read before writing Elementor data.',
				'mimeType'    => 'application/json',
			),
			array(
				'key'         => 'graph',
				'route'       => '/mcpwp/v1/graph',
				'query'       => array(),
				'name'        => 'Site Graph Summary',
				// #943 S2: read as mcpwp://graph. Counts, signal sources, top
				// orphans and freshness for first contact, hard-capped under a
				// 2k-token budget (Mcpwp_Graph_Reader::RESOURCE_MAX_BYTES) and
				// filtered by the caller's visibility like the graph tools.
				'description' => 'Site graph summary: node counts by kind, edge counts by type, signal sources with their latest period_end, the published orphan count plus the 10 most recently modified orphans (no inbound link from a published post), and graph_status freshness (coverage, built_at, backfill, dirty_count). Drill in with wp_graph_query, wp_graph_node and wp_graph_neighbors.',
				'mimeType'    => 'application/json',
			),
		);
	}

	/**
	 * Build the URI resources/list advertises for a resource definition.
	 *
	 * Uses rest_url() rather than hand-built "site_url() . '/wp-json/...'"
	 * string concatenation so the advertised URI is the same one WordPress
	 * itself would generate under either permalink structure (pretty
	 * "/wp-json/…" or plain "?rest_route=…").
	 *
	 * @param array $definition Resource definition.
	 * @return string
	 */
	private function mcp_resource_uri( $definition ) {
		$uri = rest_url( ltrim( $definition['route'], '/' ) );

		if ( ! empty( $definition['query'] ) ) {
			$uri = add_query_arg( $definition['query'], $uri );
		}

		return $uri;
	}

	/**
	 * Handle resources/list method.
	 *
	 * Exposes key site data as MCP resources so clients can read context without
	 * burning a tool-call slot.
	 *
	 * @param mixed $id Request ID.
	 * @return array JSON-RPC response.
	 */
	private function handle_resources_list( $id ) {
		$resources = array();

		foreach ( $this->mcp_resource_definitions() as $definition ) {
			$resources[] = array(
				'uri'         => $this->mcp_resource_uri( $definition ),
				'name'        => $definition['name'],
				'description' => $definition['description'],
				'mimeType'    => $definition['mimeType'],
			);
		}

		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => array(
				'resources' => $resources,
			),
		);
	}

	/**
	 * Resolve a client-supplied resource URI to one of the definitions in
	 * mcp_resource_definitions().
	 *
	 * Accepts two forms:
	 *  - the plugin's own `mcpwp://<key>` shorthand — independent of host,
	 *    scheme, and permalink structure;
	 *  - the https REST URI resources/list actually advertised, matched by
	 *    REST *route* (the part after "/wp-json/" or in "?rest_route=")
	 *    rather than by exact string equality against get_site_url(), so a
	 *    reverse proxy or a port-mapped dev host — where the scheme/host the
	 *    agent used to reach the site differs from what get_site_url()
	 *    returns inside the PHP process — does not 404 a URI the client
	 *    copied verbatim from resources/list.
	 *
	 * @param string $uri Client-supplied resource URI.
	 * @return array|null Matching resource definition, or null when nothing matches.
	 */
	private function resolve_mcp_resource( $uri ) {
		$definitions = $this->mcp_resource_definitions();

		if ( 0 === strncasecmp( $uri, 'mcpwp://', 8 ) ) {
			$key = strtok( substr( $uri, 8 ), '?' );
			$key = rtrim( (string) $key, '/' );

			foreach ( $definitions as $definition ) {
				if ( $definition['key'] === $key ) {
					return $definition;
				}
			}

			return null;
		}

		$parts = wp_parse_url( $uri );
		if ( empty( $parts['path'] ) ) {
			return null;
		}

		$route       = null;
		$wp_json_pos = strpos( $parts['path'], '/wp-json/' );
		if ( false !== $wp_json_pos ) {
			// Keep the leading slash: "/wp-json/mcpwp/v1/x" -> "/mcpwp/v1/x".
			$route = substr( $parts['path'], $wp_json_pos + strlen( '/wp-json' ) );
		} elseif ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $query_args );
			if ( ! empty( $query_args['rest_route'] ) ) {
				$route = $query_args['rest_route'];
			}
		}

		if ( null === $route ) {
			return null;
		}

		$route = '/' . trim( $route, '/' );

		foreach ( $definitions as $definition ) {
			if ( $definition['route'] === $route ) {
				return $definition;
			}
		}

		return null;
	}

	/**
	 * Handle resources/read method.
	 *
	 * Resolves the resource internally via rest_do_request() under the
	 * calling request's own authenticated context — no second HTTP round
	 * trip. Two bugs made every advertised resource unreadable (#902):
	 *
	 *  1. The internal sub-request's route was built as
	 *     "/wp-json/mcpwp/v1/…" — the "/wp-json" prefix is stripped by the
	 *     front-controller rewrite before a route ever reaches
	 *     WP_REST_Server::dispatch(); a request constructed in-process and
	 *     handed straight to rest_do_request() must use the bare route
	 *     ("/mcpwp/v1/…"), so the old code 404'd as rest_no_route on every
	 *     server, Apache included.
	 *  2. The API key was re-read from apache_request_headers() /
	 *     getallheaders(), which return nothing under php-fpm + nginx —
	 *     authentication silently vanished on non-Apache stacks even once
	 *     the route matched. get_api_key_from_request() reads
	 *     $request->get_header(), which WordPress populates from $_SERVER on
	 *     every server, and is the same helper tools/call already uses to
	 *     carry auth into its own internal sub-requests.
	 *
	 * Adversarial review round 2 found two more:
	 *
	 *  3. The credential was always re-sent as X-API-Key, even when the
	 *     caller authenticated with `Authorization: Bearer` (an OAuth access
	 *     token or a mumcp.com broker token) — the broker fallback only ever
	 *     runs for a genuine Bearer header with no X-API-Key present, so a
	 *     broker caller got "invalid_api_key" 401 on a request whose outer
	 *     /mcp call had already authenticated. Fixed by copying whichever
	 *     header the caller actually sent, never converting one into the
	 *     other.
	 *  4. The inner dispatch re-ran the full metered auth path, so one
	 *     resources/read call cost two rate-limit slots (outer /mcp + this
	 *     inner dispatch). Fixed by wrapping the dispatch in
	 *     Mcpwp_REST_Batch::$is_batch_sub_request, the same flag
	 *     handle_tools_call_idempotent() sets around its own internal
	 *     dispatch — it routes the inner permission_callback to
	 *     verify_api_key_auth_only(), which still runs full scope/category
	 *     checks (and, as of this round, the OAuth/broker branches too) and
	 *     only skips the rate-limit charge.
	 *
	 * @param mixed           $id      Request ID.
	 * @param array           $params  Request parameters.
	 * @param WP_REST_Request $request Original REST request (the outer /mcp call).
	 * @return array JSON-RPC response.
	 */
	private function handle_resources_read( $id, $params, $request ) {
		$uri = isset( $params['uri'] ) ? (string) $params['uri'] : '';

		if ( '' === $uri ) {
			return $this->jsonrpc_error( $id, -32602, 'Missing resource URI' );
		}

		$definition = $this->resolve_mcp_resource( $uri );

		if ( null === $definition ) {
			return $this->jsonrpc_error(
				$id,
				-32002,
				'Resource not found',
				array(
					'uri'  => $uri,
					'hint' => 'Only resources advertised by resources/list are readable — call resources/list to see available URIs, or use the mcpwp://<name> form.',
				)
			);
		}

		$sub_request = new WP_REST_Request( 'GET', $definition['route'] );

		foreach ( $definition['query'] as $key => $value ) {
			$sub_request->set_param( $key, $value );
		}

		// An explicit query string on the client-supplied URI overrides the
		// definition's own defaults (e.g. a different per_page on /pages).
		$parts = wp_parse_url( $uri );
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $query_args );
			unset( $query_args['rest_route'] );
			foreach ( $query_args as $key => $value ) {
				$sub_request->set_param( sanitize_key( $key ), sanitize_text_field( $value ) );
			}
		}

		// Carry the caller's own authenticated credential into the sub-request
		// exactly as presented — never convert one credential type into
		// another (#902 review round 2; shared with tools/call and /batch —
		// see forward_caller_credential()).
		$this->forward_caller_credential( $request, $sub_request );

		// A resource that is a heavy read (mcpwp://graph, weight 5) costs what
		// the same route costs over REST (#943 S2 review round 2, W6). The
		// outer /mcp request paid one slot; charge the rest before dispatch,
		// exactly as tools/call does for a multi-operation tool. The inner
		// dispatch below is unmetered, so nothing is charged twice.
		$resource_weight = class_exists( 'Mcpwp_Operation_Weights' )
			? Mcpwp_Operation_Weights::graph_weight( Mcpwp_Operation_Weights::normalize_route( $definition['route'] ), (array) $sub_request->get_params() )
			: null;
		if ( null !== $resource_weight && $resource_weight > 1 ) {
			$charge = $this->charge_additional_slots( $resource_weight - 1 );
			if ( is_wp_error( $charge ) ) {
				$data = $charge->get_error_data();
				return $this->jsonrpc_error(
					$id,
					-32000,
					$charge->get_error_message(),
					array(
						'code'        => 'rate_limited',
						'requested'   => $resource_weight,
						'retry_after' => isset( $data['retry_after'] ) ? (int) $data['retry_after'] : null,
						'hint'        => sprintf( 'Reading %s costs %d rate-limit slots. Nothing was read. Wait for the window or raise the key\'s limits.', $uri, $resource_weight ),
					)
				);
			}
		}

		// Mark as an internal sub-request so the inner dispatch's own
		// permission_callback runs the unmetered auth-only fast path
		// (verify_api_key_auth_only()) instead of full compute_api_key_verdict()
		// — full scope/category/broker/OAuth checks still run, only the
		// rate-limit charge is skipped, since the outer /mcp request already
		// metered this same caller once. Without this, one resources/read
		// call cost two rate-limit slots (outer /mcp + this inner dispatch).
		// Same save/restore pattern handle_tools_call_idempotent() uses, for
		// the same reason: this dispatch may itself be running inside another
		// internal dispatch, so a hard reset to false could clear an outer
		// caller's still-live flag.
		$batch_class_exists    = class_exists( 'Mcpwp_REST_Batch' );
		$previous_sub_request  = $batch_class_exists ? Mcpwp_REST_Batch::$is_batch_sub_request : null;
		if ( $batch_class_exists ) {
			Mcpwp_REST_Batch::$is_batch_sub_request = true;
		}
		try {
			$response = rest_do_request( $sub_request );
		} finally {
			if ( $batch_class_exists ) {
				Mcpwp_REST_Batch::$is_batch_sub_request = $previous_sub_request;
			}
		}
		$status = $response->get_status();
		$data   = $response->get_data();

		if ( $status >= 400 ) {
			$error_message = ( is_array( $data ) && isset( $data['message'] ) ) ? $data['message'] : 'Resource read failed';
			$error_code    = ( is_array( $data ) && isset( $data['code'] ) ) ? $data['code'] : 'resource_error';

			return $this->jsonrpc_error(
				$id,
				-32003,
				$error_message,
				array(
					'uri'    => $uri,
					'code'   => $error_code,
					'status' => $status,
				)
			);
		}

		$mime_type = $definition['mimeType'];
		$text      = wp_json_encode( $data );

		if ( 'text/plain' === $mime_type && is_array( $data ) && isset( $definition['text_field'] ) ) {
			$field = $definition['text_field'];
			if ( isset( $data[ $field ] ) && is_string( $data[ $field ] ) ) {
				$text = $data[ $field ];
			}
		} elseif ( 'text/plain' === $mime_type && is_string( $data ) ) {
			$text = $data;
		}

		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => array(
				'contents' => array(
					array(
						'uri'      => $uri,
						'mimeType' => $mime_type,
						'text'     => $text,
					),
				),
			),
		);
	}

	/**
	 * Handle prompts/list method.
	 *
	 * @param mixed $id Request ID.
	 * @return array JSON-RPC response.
	 */
	private function handle_prompts_list( $id ) {
		$prompts = array(
			array(
				'name'        => 'audit-seo',
				'description' => 'Run a full SEO audit of the site and return a prioritized list of issues with fix suggestions.',
				'arguments'   => array(),
			),
			array(
				'name'        => 'build-page',
				'description' => 'Build a new WordPress page from a brief. Provide a title and optional description of the page goal.',
				'arguments'   => array(
					array(
						'name'        => 'title',
						'description' => 'Page title',
						'required'    => true,
					),
					array(
						'name'        => 'brief',
						'description' => 'What the page should accomplish (e.g., "landing page for our email marketing service")',
						'required'    => false,
					),
					array(
						'name'        => 'style',
						'description' => 'Visual style (e.g., minimal, premium, editorial)',
						'required'    => false,
					),
				),
			),
			array(
				'name'        => 'refresh-content',
				'description' => 'Review the site for stale content — posts older than 12 months, missing meta descriptions, thin copy — and produce a prioritized refresh plan.',
				'arguments'   => array(),
			),
			array(
				'name'        => 'onboard',
				'description' => 'Get a complete orientation of this WordPress site — content inventory, active integrations, current signals, and recommended first actions.',
				'arguments'   => array(),
			),
			array(
				'name'        => 'design-match',
				'description' => 'Rebuild an existing page so it matches a design reference from the library. Provide the page ID and reference ID.',
				'arguments'   => array(
					array(
						'name'        => 'page_id',
						'description' => 'WordPress page ID to rebuild',
						'required'    => true,
					),
					array(
						'name'        => 'reference_id',
						'description' => 'Design reference ID from wp_list_design_references',
						'required'    => true,
					),
				),
			),
			array(
				'name'        => 'seo-loop',
				'description' => 'Run the SEO evidence loop: read wp_seo_flags, judge the six flags, fix before write through approvals/drafts, kill bad keywords, link best pages to stuck ones, measure, and record decisions with wp_remember.',
				'arguments'   => array(),
			),
		);

		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => array(
				'prompts' => $prompts,
			),
		);
	}

	/**
	 * Handle prompts/get method.
	 *
	 * Returns a filled-in prompt template for the given name and arguments.
	 *
	 * @param mixed $id     Request ID.
	 * @param array $params Request parameters.
	 * @return array JSON-RPC response.
	 */
	private function handle_prompts_get( $id, $params ) {
		$name      = isset( $params['name'] ) ? (string) $params['name'] : '';
		$arguments = isset( $params['arguments'] ) ? (array) $params['arguments'] : array();

		if ( '' === $name ) {
			return $this->jsonrpc_error( $id, -32602, 'Missing prompt name' );
		}

		// Decoded for agents (#903) — see Mcpwp_Agent_Text.
		$site_title = Mcpwp_Agent_Text::decode( get_bloginfo( 'name' ) );
		$site_url   = get_site_url();

		switch ( $name ) {
			case 'audit-seo':
				$text = "You are an SEO specialist working on the WordPress site \"{$site_title}\" ({$site_url}).\n\n"
					. "1. Call wp_seo_audit_site to run a full site-level SEO audit.\n"
					. "2. Call wp_get_seo_issues to retrieve any previously stored issues.\n"
					. "3. For the top 5 pages by traffic, call wp_validate_seo_readiness to check live <head> content.\n"
					. "4. Return a prioritized issue list: P0 (broken/missing), P1 (major improvements), P2 (nice-to-have).\n"
					. "5. For each P0 and P1 issue, include the specific tool call needed to fix it.";
				break;

			case 'build-page':
				$title = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : 'New Page';
				$brief = isset( $arguments['brief'] ) ? sanitize_textarea_field( $arguments['brief'] ) : '';
				$style = isset( $arguments['style'] ) ? sanitize_text_field( $arguments['style'] ) : '';

				$text = "You are a WordPress page builder working on \"{$site_title}\" ({$site_url}).\n\n"
					. "Goal: Build a page titled \"{$title}\"."
					. ( $brief ? " Brief: {$brief}" : '' )
					. ( $style ? " Desired style: {$style}." : '' ) . "\n\n"
					. "Steps:\n"
					. "1. Call wp_site_info to confirm the Elementor layout mode (section vs container).\n"
					. "2. Call wp_get_site_context to read the brand style guide.\n"
					. "3. Call wp_list_design_references to find any relevant design references.\n"
					. "4. Call wp_create_page with status 'draft' to create the page.\n"
					. "5. Build the Elementor data matching the brand context and design references.\n"
					. "6. Call wp_set_elementor to save the page layout.\n"
					. "7. Report the page ID and preview URL. Do not publish until reviewed.";
				break;

			case 'refresh-content':
				$text = "You are a content strategist working on \"{$site_title}\" ({$site_url}).\n\n"
					. "1. Call wp_get_signals to find stale content, thin posts, and missing featured images.\n"
					. "2. Call wp_list_posts with per_page=50 to get recent posts.\n"
					. "3. For posts older than 12 months, flag them for refresh.\n"
					. "4. Call wp_seo_audit_site to identify posts with missing meta descriptions or poor readability.\n"
					. "5. Return a refresh plan: which posts to update, what to add, in priority order.\n"
					. "6. Do not edit any content until the plan is confirmed.";
				break;

			case 'onboard':
				$text = "You are connecting to the WordPress site \"{$site_title}\" ({$site_url}) for the first time.\n\n"
					. "1. Call wp_onboard for a short site briefing (identity, builder mode, connected services, next actions); pass detail=full for the complete inventory.\n"
					. "2. Call wp_get_signals to check the current site health score.\n"
					. "3. Summarize: what's on the site, what integrations are active, and the top 3 actions you'd recommend.\n"
					. "4. Ask the site owner what they'd like to work on.";
				break;

			case 'design-match':
				$page_id   = isset( $arguments['page_id'] ) ? absint( $arguments['page_id'] ) : 0;
				$ref_id    = isset( $arguments['reference_id'] ) ? sanitize_key( $arguments['reference_id'] ) : '';

				if ( ! $page_id || ! $ref_id ) {
					return $this->jsonrpc_error( $id, -32602, 'design-match requires page_id and reference_id arguments' );
				}

				$text = "You are rebuilding a WordPress page to match a design reference on \"{$site_title}\".\n\n"
					. "Page ID: {$page_id}. Design reference: {$ref_id}.\n\n"
					. "1. Call wp_get_elementor({$page_id}) to read the current layout.\n"
					. "2. Call wp_get_design_reference('{$ref_id}') to read the reference image and notes.\n"
					. "3. Call wp_get_site_context to read the brand style guide.\n"
					. "4. Rebuild the Elementor data to match the reference's section structure, typography, and color palette.\n"
					. "5. Call wp_set_elementor to save. Include structural_changes in your report.\n"
					. "6. Do not publish — return the page ID for review.";
				break;

			case 'seo-loop':
				$text = "You are running the SEO evidence loop on \"{$site_title}\" ({$site_url}). Work the flags in order, one at a time, and do not skip the approval gate.\n\n"
					. "1. READ: Call wp_seo_flags to get the six act-worthy signals (almost_there, no_clicks, decaying, untargeted, wrong_intent, ai_mode). If it returns an empty result with a next_step, stop and ask the owner for a Search Console export, then call wp_import_search_performance and retry.\n"
					. "2. JUDGE: For each flag item, read its evidence and suggested_action. Decide which items are worth acting on now — prioritize decaying and almost_there first, they have the shortest path to more clicks.\n"
					. "3. FIX BEFORE WRITE: Never edit a live page directly. Use wp_create_page/wp_update_page or wp_create_post/wp_update_post to prepare the change, then route it through wp_list_approvals / wp_apply_approval so nothing publishes unreviewed.\n"
					. "4. KILL BAD KEYWORDS: For wrong_intent and ai_mode items that do not deserve a dedicated page, do not force-fit them — note them and move on rather than contorting an existing page's intent.\n"
					. "5. LINK BEST TO STUCK: For untargeted and almost_there items, call wp_suggest_internal_links and wp_apply_internal_link to link from your strongest existing pages to the page that should own the query.\n"
					. "6. MEASURE: After changes are approved and live, call wp_import_search_performance with the next export and call wp_seo_flags again to confirm clicks moved and the flag cleared.\n"
					. "7. RECORD: Call wp_remember to store what you changed and why (query, url, action, date) so the next loop doesn't repeat the same judgment call from scratch.";
				break;

			default:
				return $this->jsonrpc_error(
					$id,
					-32002,
					"Prompt \"{$name}\" not found",
					array( 'hint' => 'Call prompts/list to see available prompt names.' )
				);
		}

		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => array(
				'description' => ucfirst( str_replace( '-', ' ', $name ) ) . ' prompt for ' . $site_title,
				'messages'    => array(
					array(
						'role'    => 'user',
						'content' => array(
							'type' => 'text',
							'text' => $text,
						),
					),
				),
			),
		);
	}

	/**
	 * Handle tools/call method.
	 *
	 * Executes the requested tool by dispatching to internal REST API.
	 *
	 * @param mixed           $id      Request ID.
	 * @param array           $params  Tool parameters.
	 * @param WP_REST_Request $request Original REST request.
	 * @return array JSON-RPC response.
	 */
	private function handle_tools_call( $id, $params, $request ) {
		$start     = microtime( true );
		$tool_name = isset( $params['name'] ) ? $params['name'] : '';
		$arguments = isset( $params['arguments'] ) ? $params['arguments'] : array();

		if ( empty( $tool_name ) ) {
			$this->fire_tool_called( $tool_name, $start, 'tool_not_found' );
			return $this->jsonrpc_error(
				$id,
				-32602,
				'Missing tool name',
				array( 'hint' => 'The "name" field is required in tools/call params. Call tools/list to see available tool names.' )
			);
		}

		$this->log_mcp_activity(
			'mcp_tool_call',
			array(
				'tool' => $tool_name,
				'args' => $arguments,
			)
		);

		// Build tool map from all registries.
		$tool_map = $this->get_all_tool_map();

		if ( ! isset( $tool_map[ $tool_name ] ) ) {
			// Pro tool called on a Free install: answer with the upgrade path,
			// not "unknown tool" — the tool exists, the plan doesn't (#496).
			if ( ! $this->is_pro_active() && isset( $this->pro_registry->get_tool_map()[ $tool_name ] ) ) {
				$this->fire_tool_called( $tool_name, $start, 'pro_required' );
				return $this->tool_error_result(
					$id,
					'pro_required',
					sprintf( 'Tool "%s" is part of MCPWP Pro, which is not active on this site.', $tool_name ),
					array(
						'hint'          => sprintf(
							'"%s" requires an MCPWP Pro license (AI integrations, advanced SEO, WooCommerce and theme-builder tools). Plans start at $59/year — see https://mcpwp.net/pricing/ or upgrade in WP Admin > MCPWP. Free tools keep working without a license.',
							$tool_name
						),
						'plan_required' => 'pro',
						'upgrade_url'   => 'https://mcpwp.net/pricing/',
					)
				);
			}

			// Fuzzy-match for "did you mean?" suggestion.
			$available = array_keys( $tool_map );
			$best      = null;
			$best_pct  = 0;
			foreach ( $available as $candidate ) {
				$pct = 0;
				similar_text( $tool_name, $candidate, $pct );
				if ( $pct > $best_pct ) {
					$best_pct = $pct;
					$best     = $candidate;
				}
			}
			$suggestion = ( $best && $best_pct >= 50 ) ? sprintf( ' Did you mean "%s"?', $best ) : '';
			$this->fire_tool_called( $tool_name, $start, 'tool_not_found' );
			return $this->jsonrpc_error(
				$id,
				-32602,
				'Unknown tool: ' . $tool_name,
				array( 'hint' => 'Tool "' . $tool_name . '" does not exist.' . $suggestion . ' Call tools/list to see all available tools.' )
			);
		}

		$mapping = $tool_map[ $tool_name ];

		// Resolve deprecated parameter-name aliases to their canonical name
		// (#831) before any gate or the schema validator below ever sees
		// $arguments. This must run before validate_tool_arguments() — that
		// call checks required/unknown keys against the canonical schema, so
		// an un-resolved deprecated key would fail as "unknown parameter"
		// (or, for a required one, "missing parameter") even though the
		// caller supplied an equivalent value under its old name.
		$arguments = $this->resolve_param_aliases( $mapping, $arguments );

		// Check if the tool's category has been disabled by the site administrator.
		$disabled_categories = get_option( 'mcpwp_disabled_tool_categories', array() );
		if ( ! empty( $disabled_categories ) && is_array( $disabled_categories ) ) {
			$tool_category = $this->tool_category_for( $tool_name );
			if ( '' !== $tool_category && in_array( $tool_category, $disabled_categories, true ) ) {
				$this->fire_tool_called( $tool_name, $start, 'category_disabled' );
				return $this->tool_error_result(
					$id,
					'category_disabled',
					sprintf(
						'Tool "%s" is in the "%s" category, which has been disabled by the site administrator.',
						$tool_name,
						$tool_category
					),
					array( 'hint' => 'This category has been disabled in WP Admin > mcpwp > Tools. Contact the site administrator to re-enable it.' )
				);
			}
		}

		// Check if the API key's role allows this tool's category.
		$key_record = $this->get_current_api_key_record();
		if ( $key_record ) {
			$allowed_categories = $this->resolve_key_tool_categories( $key_record );
			if ( ! empty( $allowed_categories ) ) {
				// The same resolver as every other gate. A tool with no
				// category is not "site" — under a category-restricted key it
				// is denied, fail-closed, and the replay gate says the same
				// (#802 round 5).
				$tool_category = $this->tool_category_for( $tool_name );
				if ( '' === $tool_category || ! in_array( $tool_category, $allowed_categories, true ) ) {
					$role = isset( $key_record['role'] ) ? $key_record['role'] : 'unknown';
					$this->fire_tool_called( $tool_name, $start, 'scope_denied' );
					return $this->tool_error_result(
						$id,
						'scope_denied',
						sprintf(
							'This API key (role: %s) does not have access to %s tools. Allowed categories: %s.',
							$role,
							'' === $tool_category ? 'uncategorised' : $tool_category,
							implode( ', ', $allowed_categories )
						),
						array(
							'hint' => sprintf(
								'Tool "%s" is in the "%s" category, but this key only has access to: %s. Request an admin key or a key with the "%s" category enabled.',
								$tool_name,
								'' === $tool_category ? 'uncategorised' : $tool_category,
								implode( ', ', $allowed_categories ),
								'' === $tool_category ? 'uncategorised' : $tool_category
							),
						)
					);
				}
			}
		}

		// Check if the tool's required plugin is installed.
		$reqs = $this->get_all_required_capabilities();
		if ( isset( $reqs[ $tool_name ] ) && ! $this->is_capability_active( $reqs[ $tool_name ] ) ) {
			$plugin_names = array(
				'elementor' => 'Elementor',
				'gutenberg' => 'the Gutenberg block editor (disable Classic Editor plugin if installed)',
				'seo'       => 'an SEO plugin (Yoast, RankMath, AIOSEO, or SEOPress)',
				'forms'     => 'a forms plugin (Contact Form 7, WPForms, Gravity Forms, or Ninja Forms)',
			);
			$req          = $reqs[ $tool_name ];
			$human        = isset( $plugin_names[ $req ] ) ? $plugin_names[ $req ] : $req;
			$this->fire_tool_called( $tool_name, $start, 'scope_denied' );
			return $this->tool_error_result(
				$id,
				'scope_denied',
				sprintf( 'Tool "%s" requires %s to be installed and active on this WordPress site.', $tool_name, $human ),
				array(
					'hint' => sprintf(
						'The required plugin (%s) is not active. Use wp_detect_plugins to check what\'s installed. The site admin needs to install and activate it from WP Admin > Plugins.',
						$human
					),
				)
			);
		}

		// Validate arguments against tool schema — forgiving of near-miss keys
		// (#911): an unambiguous case/separator variant or small-table synonym
		// of exactly one known param is silently remapped (noted in
		// _meta.param_fixes via $this->last_param_fixes, see
		// attach_result_meta()); anything left unresolved (no candidate, a
		// tie, or a privileged/destructive target) is refused here, before
		// dispatch.
		$validation             = $this->validate_tool_arguments( $tool_name, $arguments );
		$arguments              = $validation['arguments'];
		$this->last_param_fixes = $validation['fixes'];
		if ( $validation['error'] ) {
			$this->fire_tool_called( $tool_name, $start, 'invalid_params' );
			return $this->tool_error_result(
				$id,
				'invalid_arguments',
				$validation['error'],
				array( 'hint' => "Call tools/list to see \"$tool_name\"'s required and optional parameters, then retry with the missing or corrected parameter." )
			);
		}

		// Once an earlier operation of this request was refused by the spend
		// budget, every later one is refused too (#752 F9) — here, before it is
		// charged rate-limit slots for work that will not run.
		$spend_state = Mcpwp_Spend_Budget::refuse_if_exhausted();
		if ( is_wp_error( $spend_state ) ) {
			$this->fire_tool_called( $tool_name, $start, Mcpwp_Spend_Budget::ERROR_CODE );
			return $this->spend_refusal_result( $id, $spend_state->get_error_message(), $spend_state->get_error_data() );
		}

		$dispatch = $this->resolve_tool_dispatch( $mapping, $arguments );

		// A batch over the controller's operation cap is refused by the
		// controller anyway; refuse it here, before it is charged a rate-limit
		// slot per operation for work that cannot run (#752 F9 review r1).
		$batch_cap = class_exists( 'Mcpwp_REST_Batch' ) ? Mcpwp_REST_Batch::MAX_OPERATIONS : 100;
		if ( $dispatch['is_batch'] && isset( $dispatch['arguments']['operations'] ) && is_array( $dispatch['arguments']['operations'] ) && count( $dispatch['arguments']['operations'] ) > $batch_cap ) {
			$this->fire_tool_called( $tool_name, $start, 'execution_error' );
			return $this->tool_error_result(
				$id,
				'batch_too_large',
				sprintf( 'Batch contains too many operations. Maximum %d allowed.', $batch_cap ),
				array(
					'hint' => sprintf( 'Split the operations into calls of at most %d. Nothing ran and no rate-limit slots were charged for the operations.', $batch_cap ),
				)
			);
		}

		// A call whose price as sent does not fit what is left of this
		// request's spend budget is refused whole, before it is charged
		// rate-limit slots and before anything runs.
		$spend_whole = Mcpwp_Spend_Budget::refuse_whole( $this->tool_call_spend_price( $mapping, $dispatch, $tool_name ) );
		if ( is_wp_error( $spend_whole ) ) {
			$this->fire_tool_called( $tool_name, $start, Mcpwp_Spend_Budget::EXCEEDED_CODE );
			return $this->spend_refusal_result( $id, $spend_whole->get_error_message(), $spend_whole->get_error_data(), $spend_whole->get_error_code() );
		}

		// A tool that carries a list of operations is a batch by another name
		// (#802). The HTTP request was charged one slot; the rest are charged
		// here, before dispatch, and refused whole when they do not fit —
		// otherwise wp_batch_update inside tools/call is a 100:1 bypass of
		// the weighted limiter.
		$nested = $this->nested_operation_count( $arguments, $tool_name );
		if ( $nested > 1 ) {
			$charge = $this->charge_additional_slots( $nested - 1 );
			if ( is_wp_error( $charge ) ) {
				$this->fire_tool_called( $tool_name, $start, 'rate_limited' );
				$data = $charge->get_error_data();
				return $this->tool_error_result(
					$id,
					'rate_limited',
					$charge->get_error_message(),
					array(
						'hint'        => sprintf( 'This call carries %d operations and each costs one rate-limit slot. Nothing ran. If it exceeds the ceiling (batch_exceeds_limit) waiting will not help: split it. Otherwise wait for the window or raise the key\'s limits.', $nested ),
						'retry_after' => isset( $data['retry_after'] ) ? (int) $data['retry_after'] : null,
						'remaining'   => isset( $data['remaining'] ) ? (int) $data['remaining'] : null,
						'limit'       => isset( $data['limit'] ) ? (int) $data['limit'] : null,
						'requested'   => $nested,
					)
				);
			}
		}

		$is_callback = isset( $mapping['callback'] ) && is_callable( $mapping['callback'] );
		$method      = $mapping['method'];

		// Remap tool param names and substitute path parameters
		// (resolve_tool_dispatch(), shared with the spend pricing so the price is
		// computed from the route that will dispatch).
		$arguments = $dispatch['arguments'];
		$route     = $dispatch['route'];

		if ( null !== $dispatch['bad_placeholder'] ) {
			$this->fire_tool_called( $tool_name, $start, 'invalid_path_parameter' );
			return $this->tool_error_result(
				$id,
				'invalid_path_parameter',
				sprintf( 'Path parameter "%s" may not contain "/".', $dispatch['bad_placeholder'] ),
				array(
					'hint' => 'A path placeholder fills one route segment. Pass the bare value (for example an ID or slug) without any path separators.',
				)
			);
		}

		// Capture before-state for audit log (no-op for non-write tools).
		$audit_all_cats = $this->get_all_tool_categories();
		$audit_category = isset( $audit_all_cats[ $tool_name ] ) ? $audit_all_cats[ $tool_name ] : 'site';
		$audit_key_id   = isset( $key_record['id'] ) ? (string) $key_record['id'] : '';
		$audit_log_id   = class_exists( 'Mcpwp_Action_Log' )
			? Mcpwp_Action_Log::begin( $tool_name, $arguments, $audit_key_id, $audit_category )
			: null;

		if ( $is_callback ) {
			// Callback tools run in-process and skip the rest_do_request()
			// re-dispatch that re-checks the underlying route's scope, so the only
			// gate is the tool-name-derived scope — which defaults to read unless
			// the name carries a write verb. Enforce a write floor: an extension
			// callback (arbitrary code) must not be reachable by a read-only key
			// just because its name lacks a write-verb prefix. Authors can still
			// raise the requirement to admin via mcpwp_required_scope_for_tool.
			// A null key record is the trusted admin-AJAX context and is exempt,
			// consistent with the other gates in this method.
			if ( $key_record && ! $this->key_has_scope( $key_record, 'write' ) ) {
				$this->fire_tool_called( $tool_name, $start, 'scope_denied' );
				return $this->tool_error_result(
					$id,
					'scope_denied',
					sprintf( 'Tool "%s" requires at least write scope.', $tool_name ),
					array(
						'hint'           => 'Custom callback tools mutate state and require a write- or admin-scoped API key.',
						'required_scope' => 'write',
					)
				);
			}

			// A callback runs in-process and never passes rest_dispatch_request,
			// where every routed operation is charged, so it is charged here.
			$spend = Mcpwp_Spend_Budget::charge( Mcpwp_Operation_Weights::spend_weight( 'POST', 'callback:' . $tool_name, $arguments ) );
			if ( is_wp_error( $spend ) ) {
				if ( class_exists( 'Mcpwp_Action_Log' ) ) {
					Mcpwp_Action_Log::complete( $audit_log_id, (int) round( ( microtime( true ) - $start ) * 1000 ), false, Mcpwp_Spend_Budget::ERROR_CODE );
				}
				$this->fire_tool_called( $tool_name, $start, Mcpwp_Spend_Budget::ERROR_CODE );
				return $this->spend_refusal_result( $id, $spend->get_error_message(), $spend->get_error_data() );
			}

			$full_route = 'callback:' . $tool_name;
			$response   = $this->normalize_custom_tool_callback_response(
				call_user_func( $mapping['callback'], $arguments )
			);
		} else {
			// Build internal REST request.
			// Custom tools (mcpwp_register_tools) store 'rest_path' as the full WP REST
			// route; built-in tools store a relative path that is prefixed with the
			// MCPWP namespace. Both cases read from $route — never from
			// $mapping['rest_path'] directly — because $route is the one variable the
			// placeholder-substitution loop above actually writes into. Reading the raw
			// mapping here would silently skip substitution for custom tools (#756).
			$full_route = isset( $mapping['rest_path'] )
				? $route
				: '/mcpwp/v1' . $route;
			$internal_request = new WP_REST_Request( $method, $full_route );

			// Set remaining arguments as params.
			// For write methods, also set body params so get_param() finds them
			// in the body bucket (higher priority than defaults).
			foreach ( $arguments as $key => $value ) {
				$internal_request->set_param( $key, $value );
			}
			if ( 'GET' !== $method ) {
				$internal_request->set_body_params( $arguments );
			}

			// Copy authentication from current request — exactly as the caller
			// presented it, never converted into a different credential type
			// (#978, same fix and shared helper as resources/read's #902).
			$this->forward_caller_credential( $request, $internal_request );

			// Mark as internal sub-request via a PHP static property so rate limiting
			// is not double-counted. Using a static flag (not an HTTP header) prevents
			// external callers from forging this bypass.
			//
			// Save and restore the previous value rather than hard-resetting to
			// false. This dispatch may itself already be running inside another
			// internal dispatch — a batch operation's route can map back into this
			// very tool dispatcher — so writing false unconditionally could clear
			// an outer caller's flag while it is still live. try/finally guarantees
			// the restore even if the sub-request throws, so one failing tool call
			// cannot leave rate limiting switched off for the rest of the PHP
			// process. (Not reachable today — nothing in this call path throws —
			// this removes the dependency on that fact rather than fixing a live
			// bug; see Mcpwp_REST_Batch::execute_operation() for the sibling this
			// mirrors.)
			$batch_class_exists    = class_exists( 'Mcpwp_REST_Batch' );
			$previous_sub_request  = $batch_class_exists ? Mcpwp_REST_Batch::$is_batch_sub_request : null;
			if ( $batch_class_exists ) {
				Mcpwp_REST_Batch::$is_batch_sub_request = true;
			}
			try {
				$response = rest_do_request( $internal_request );
			} finally {
				if ( $batch_class_exists ) {
					Mcpwp_REST_Batch::$is_batch_sub_request = $previous_sub_request;
				}
			}
		}
		$data   = $response->get_data();
		$status = $response->get_status();

		// A route that wraps a WP_Error in success_response() (e.g.
		// list_posts() for an unknown post_type) answers 200 with the error
		// object as its body. Treat it as the failure it is — reported as
		// a success it could never satisfy a declared outputSchema (#906),
		// and was a silent false success before that.
		if ( is_wp_error( $data ) ) {
			$wp_error_data = $data->get_error_data();
			$wp_error_data = is_array( $wp_error_data ) ? $wp_error_data : array();
			$status        = isset( $wp_error_data['status'] ) && (int) $wp_error_data['status'] >= 400 ? (int) $wp_error_data['status'] : 400;
			$data          = array(
				'code'    => $data->get_error_code(),
				'message' => $data->get_error_message(),
				'data'    => $wp_error_data,
			);
		}

		// Check for errors — detect all error shapes.
		$is_error = $status >= 400;

		if ( $is_error ) {
			// Extract error message from various response formats.
			$error_message = 'Tool execution failed';
			if ( isset( $data['message'] ) ) {
				$error_message = $data['message'];
			} elseif ( isset( $data['error'] ) && is_string( $data['error'] ) ) {
				$error_message = $data['error'];
			} elseif ( is_string( $data ) ) {
				$error_message = $data;
			}

			$error_code = isset( $data['code'] ) ? $data['code'] : 'tool_error';

			// Refused by the spend budget before the handler ran (#752 F9).
			if ( Mcpwp_Spend_Budget::is_refusal_code( $error_code ) ) {
				if ( class_exists( 'Mcpwp_Action_Log' ) ) {
					Mcpwp_Action_Log::complete( $audit_log_id, (int) round( ( microtime( true ) - $start ) * 1000 ), false, $error_code );
				}
				$this->fire_tool_called( $tool_name, $start, $error_code );
				return $this->spend_refusal_result( $id, $error_message, isset( $data['data'] ) ? $data['data'] : array(), $error_code );
			}

			// Build error data, preserving hint/guide from REST response.
			$error_data = array(
				'code'   => $error_code,
				'status' => $status,
			);

			// Carry forward hint and guide from the REST error response.
			if ( isset( $data['data']['hint'] ) ) {
				$error_data['hint'] = $data['data']['hint'];
			} elseif ( class_exists( 'Mcpwp_Error_Hints' ) ) {
				// Try to generate a hint from the error code.
				$hint_info = Mcpwp_Error_Hints::get_hint_for_code( $error_code );
				if ( ! empty( $hint_info['hint'] ) ) {
					$error_data['hint'] = $hint_info['hint'];
				}
			}

			if ( isset( $data['data']['guide'] ) ) {
				$error_data['guide'] = $data['data']['guide'];
			} elseif ( class_exists( 'Mcpwp_Error_Hints' ) ) {
				$hint_info = isset( $hint_info ) ? $hint_info : Mcpwp_Error_Hints::get_hint_for_code( $error_code );
				if ( ! empty( $hint_info['guide'] ) ) {
					$error_data['guide'] = $hint_info['guide'];
				}
			}

			if ( class_exists( 'Mcpwp_Action_Log' ) ) {
				Mcpwp_Action_Log::complete( $audit_log_id, (int) round( ( microtime( true ) - $start ) * 1000 ), false, 'execution_error' );
			}
			$this->fire_tool_called( $tool_name, $start, 'execution_error' );
			return $this->tool_error_result(
				$id,
				$error_code,
				$error_message . ' (route: ' . $method . ' ' . $full_route . ')',
				$error_data
			);
		}

		// Return successful result.
		if ( class_exists( 'Mcpwp_Action_Log' ) ) {
			Mcpwp_Action_Log::complete( $audit_log_id, (int) round( ( microtime( true ) - $start ) * 1000 ), true, '' );
		}
		$this->fire_tool_called( $tool_name, $start, '' );

		// A tool with a declared outputSchema, under a protocol version that
		// defines structuredContent (#906), gets it built and validated from
		// this text in conform_tool_call_result(). The text block stays
		// either way — the spec's backward-compat recommendation for a
		// client that only reads `content` — but is compact rather than
		// pretty-printed when it merely duplicates the structured copy.
		$structured = $this->protocol_version_has_structured_output()
			&& is_array( $data )
			&& null !== $this->get_tool_output_schema( $tool_name );

		$encoded = wp_json_encode( $data, $structured ? JSON_UNESCAPED_SLASHES : ( JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		if ( false === $encoded ) {
			// json_encode failed (non-UTF8 chars, circular refs, etc.) — return a safe error payload.
			$encoded = wp_json_encode(
				array(
					'error'   => 'Response could not be JSON-encoded.',
					'hint'    => 'The response contained data that failed json_encode (non-UTF8 characters or an extremely large structure). Try a smaller request.',
					'last_error' => json_last_error_msg(),
				)
			);
		}

		$result = array(
			'content' => array(
				array(
					'type' => 'text',
					'text' => $encoded,
				),
			),
			'isError' => false,
		);

		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);
	}

	/**
	 * Resolve deprecated MCP parameter-name aliases to their canonical name
	 * before validation and dispatch (#831).
	 *
	 * Historic and sibling-inconsistent parameter names — `page_id` where a
	 * tool's sibling now calls the same target `id`, `alt_text` where this
	 * plugin's own convention is `alt`, `base64` for `data`, `name` for
	 * `key`, `type` for `post_type`, `widget` for `widget_type`, and so on —
	 * are accepted silently, mapped onto the current canonical name, so
	 * existing agent integrations and stored playbooks keep working across
	 * the rename. A tool map entry opts in with:
	 *
	 *     'param_aliases' => array( 'old_name' => 'canonical_name', ... ),
	 *
	 * Deliberately NOT advertised in the tool's inputSchema — only the
	 * canonical name is. This is the actual fix for #831's framing: with
	 * Tool Search Tool, a client discovers ~272 tools by matching schemas
	 * against a query, and every extra property on a schema is one more
	 * thing that retrieval has to weigh per concept. Listing both names
	 * would reintroduce exactly the noise this issue exists to remove, for
	 * an alias that only a caller stuck on old documentation would ever
	 * choose deliberately. The alias is pure compatibility plumbing: it
	 * costs a real caller nothing to keep it undocumented, and it is proven
	 * here and in the tool's changelog entry, not in the schema a client
	 * reads to decide what to send.
	 *
	 * When both the alias and the canonical name are present in the same
	 * call, the canonical value wins and the alias is dropped — simplest
	 * predictable rule for a caller mid-migration between the two, and it
	 * matches how validate_tool_arguments() already treats unknown keys
	 * (refuse loudly) versus known ones (silently accepted): once resolved,
	 * an alias is indistinguishable from having sent the canonical name.
	 *
	 * @param array $mapping   Tool map entry (may carry 'param_aliases').
	 * @param array $arguments Raw arguments as received from the client.
	 * @return array Arguments with any alias keys renamed to their canonical name.
	 */
	private function resolve_param_aliases( $mapping, $arguments ) {
		$arguments = is_array( $arguments ) ? $arguments : array();
		$aliases   = isset( $mapping['param_aliases'] ) && is_array( $mapping['param_aliases'] ) ? $mapping['param_aliases'] : array();

		foreach ( $aliases as $alias => $canonical ) {
			if ( ! is_string( $alias ) || ! is_string( $canonical ) || ! array_key_exists( $alias, $arguments ) ) {
				continue;
			}
			if ( ! array_key_exists( $canonical, $arguments ) ) {
				$arguments[ $canonical ] = $arguments[ $alias ];
			}
			unset( $arguments[ $alias ] );
		}

		return $arguments;
	}

	/**
	 * A tool mapping applied to its arguments: param_remap, then path
	 * placeholders substituted one segment each.
	 *
	 * @param array $mapping   Tool map entry.
	 * @param array $arguments Tool arguments.
	 * @return array{route:string,arguments:array,bad_placeholder:?string,full_route:string,is_batch:bool}
	 */
	private function resolve_tool_dispatch( $mapping, $arguments ) {
		$arguments   = is_array( $arguments ) ? $arguments : array();
		$is_callback = isset( $mapping['callback'] ) && is_callable( $mapping['callback'] );
		$route       = $is_callback || ! isset( $mapping['route'] ) ? '' : (string) $mapping['route'];

		// Remap tool param names to REST endpoint param names where they differ.
		$param_remap = isset( $mapping['param_remap'] ) && is_array( $mapping['param_remap'] ) ? $mapping['param_remap'] : array();
		foreach ( $param_remap as $from => $to ) {
			if ( array_key_exists( $from, $arguments ) ) {
				$arguments[ $to ] = $arguments[ $from ];
				unset( $arguments[ $from ] );
			}
		}

		// Substitute path parameters (e.g., {id})
		//
		// A placeholder fills exactly one path SEGMENT. is_safe_rest_path() ran
		// at registration time against the template, while the braces were still
		// in it — so it never saw this value, and substitution is the one place
		// caller-supplied text enters an already-validated route.
		//
		// A value containing "/" would therefore add segments the allowlist never
		// approved. That is not exploitable today: WordPress matches REST routes
		// with an anchored pattern and does not resolve "..", so a smuggled path
		// simply fails to route (verified against WP 7.0.2 — every traversal form
		// tried matched NO ROUTE). But that is a negative property of someone
		// else's matcher, and this file already carries two fixes whose whole
		// point was removing exactly that kind of dependency (#752 F7/F11).
		// Refuse the segment instead of relying on the router to save us.
		$bad_placeholder = null;
		if ( ! $is_callback ) {
			foreach ( $arguments as $key => $value ) {
				$placeholder = '{' . $key . '}';
				if ( false !== strpos( $route, $placeholder ) ) {
					$value_str = is_scalar( $value ) ? (string) $value : '';

					if ( false !== strpos( $value_str, '/' ) ) {
						$bad_placeholder = (string) $key;
						break;
					}

					$route = str_replace( $placeholder, $value_str, $route );
					unset( $arguments[ $key ] );
				}
			}
		}

		// Custom tools (mcpwp_register_tools) store 'rest_path' as the full WP
		// REST route; built-in tools store a path relative to the namespace.
		$full_route = isset( $mapping['rest_path'] ) ? $route : '/mcpwp/v1' . $route;

		return array(
			'route'           => $route,
			'arguments'       => $arguments,
			'bad_placeholder' => $bad_placeholder,
			'full_route'      => $full_route,
			'is_batch'        => ! $is_callback && class_exists( 'Mcpwp_Operation_Weights' ) && '/batch' === Mcpwp_Operation_Weights::normalize_route( $full_route ),
		);
	}

	/**
	 * Spend units one tool call costs as sent (#752 F9 review r1): what its
	 * dispatch would be charged, before the pattern core matches is known —
	 * a lower bound. A batch tool costs its operations.
	 *
	 * @param array  $mapping   Tool map entry.
	 * @param array  $dispatch  resolve_tool_dispatch() result.
	 * @param string $tool_name Tool name.
	 * @return int
	 */
	private function tool_call_spend_price( $mapping, $dispatch, $tool_name ) {
		if ( ! class_exists( 'Mcpwp_Operation_Weights' ) || null !== $dispatch['bad_placeholder'] ) {
			return 0;
		}

		if ( isset( $mapping['callback'] ) && is_callable( $mapping['callback'] ) ) {
			return Mcpwp_Operation_Weights::spend_weight( 'POST', 'callback:' . $tool_name, $dispatch['arguments'] );
		}

		if ( $dispatch['is_batch'] ) {
			return class_exists( 'Mcpwp_REST_Batch' ) && isset( $dispatch['arguments']['operations'] )
				? Mcpwp_REST_Batch::price( $dispatch['arguments']['operations'] )
				: 0;
		}

		return Mcpwp_Operation_Weights::spend_weight( isset( $mapping['method'] ) ? $mapping['method'] : 'POST', $dispatch['full_route'], $dispatch['arguments'] );
	}

	/**
	 * Spend units one JSON-RPC message of a batch costs as sent. A tool call
	 * that will be answered from its stored result dispatches nothing and
	 * costs nothing; a resource read dispatches one route; everything else
	 * dispatches nothing.
	 *
	 * @param mixed $message  JSON-RPC message.
	 * @param array $tool_map Merged tool map, built once for the whole batch.
	 * @return int
	 */
	private function message_spend_price( $message, array $tool_map ) {
		if ( ! is_array( $message ) || ! isset( $message['method'] ) || ! is_string( $message['method'] ) ) {
			return 0;
		}

		if ( 'resources/read' === $message['method'] ) {
			return 1;
		}

		if ( 'tools/call' !== $message['method'] ) {
			return 0;
		}

		$params    = isset( $message['params'] ) && is_array( $message['params'] ) ? $message['params'] : array();
		$tool_name = isset( $params['name'] ) && is_string( $params['name'] ) ? $params['name'] : '';
		$arguments = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();

		if ( '' === $tool_name || ! isset( $tool_map[ $tool_name ] ) || $this->has_stored_result( $params, $tool_map ) ) {
			return 0;
		}

		// A batched call priced here never passed through handle_tools_call()'s
		// own alias resolution and forgiving-params remap, so both must run
		// here too (#831, #911 review round 1 WARN-2) — otherwise a
		// deprecated or near-miss parameter name inside a batch is priced (and
		// later dispatched, see handle_tools_call_idempotent()) as if neither
		// applied to it: a batch tool's operations count under the wrong key
		// name prices as if it carried none, and a path-placeholder route
		// left unsubstituted prices under the wrong route entirely — either
		// way undermining "the whole batch is refused before any of it runs"
		// (#752 F9 review r1).
		$mapping   = $tool_map[ $tool_name ];
		$arguments = $this->canonical_tool_arguments( $tool_name, $mapping, $arguments );

		return $this->tool_call_spend_price( $mapping, $this->resolve_tool_dispatch( $mapping, $arguments ), $tool_name );
	}

	/**
	 * Whether a tools/call carries a request_id whose completed result is
	 * stored for this principal with the same arguments — i.e. it will be
	 * replayed, not run.
	 *
	 * @param array $params   tools/call params.
	 * @param array $tool_map Merged tool map.
	 * @return bool
	 */
	private function has_stored_result( $params, $tool_map ) {
		$request_id = isset( $params['_meta']['request_id'] ) ? $params['_meta']['request_id'] : null;
		$tool_name  = isset( $params['name'] ) && is_string( $params['name'] ) ? $params['name'] : '';

		if ( ! is_string( $request_id ) || '' === $request_id || strlen( $request_id ) > 128 || ! preg_match( '/^[A-Za-z0-9_\-:.]+$/', $request_id ) || ! isset( $tool_map[ $tool_name ] ) ) {
			return false;
		}

		$scope = self::principal_scope( $this->get_current_api_key_record() );
		if ( '' === $scope ) {
			return false;
		}

		$arguments = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();
		// Canonicalized the same way handle_tools_call_idempotent() hashes
		// arguments to STORE a result, so a batched replay check agrees with
		// the stored hash regardless of which near-miss spelling either call
		// used (#911 review round 1, WARN-2's NIT).
		$arguments = $this->canonical_tool_arguments( $tool_name, $tool_map[ $tool_name ], $arguments );
		$existing  = get_transient( self::idempotency_key( $scope, $request_id ) );

		return is_array( $existing )
			&& isset( $existing['state'], $existing['args_hash'], $existing['response'] )
			&& 'done' === $existing['state']
			&& is_array( $existing['response'] )
			&& hash( 'sha256', $tool_name . '|' . wp_json_encode( self::canonicalize( $arguments ) ) ) === $existing['args_hash'];
	}

	/**
	 * A tool call the spend budget refused, as a JSON-RPC error (#752 F9).
	 *
	 * Its own code, so a client can tell "this request was too big — resend the
	 * rest in a new one" from a rate limit (-32029, wait) and from a failing
	 * handler (-32000). The operation did not run.
	 *
	 * @param mixed  $id      Request ID.
	 * @param string $message Refusal message.
	 * @param mixed  $data    Refusal data (weight, remaining, limit, hint).
	 * @param string $code    spend_budget_exhausted (this call, at dispatch) or
	 *                        spend_budget_exceeded (the whole call, before it ran).
	 * @return array
	 */
	private function spend_refusal( $id, $message, $data, $code = Mcpwp_Spend_Budget::ERROR_CODE ) {
		$data  = is_array( $data ) ? $data : array();
		$error = array(
			'code'   => Mcpwp_Spend_Budget::is_refusal_code( $code ) ? $code : Mcpwp_Spend_Budget::ERROR_CODE,
			'status' => Mcpwp_Spend_Budget::HTTP_STATUS,
		);

		foreach ( array( 'weight', 'remaining', 'limit', 'hint' ) as $field ) {
			if ( isset( $data[ $field ] ) ) {
				$error[ $field ] = $data[ $field ];
			}
		}

		return $this->jsonrpc_error( $id, Mcpwp_Spend_Budget::JSONRPC_CODE, (string) $message, $error );
	}

	/**
	 * A tool call the spend budget refused, as an isError tools/call RESULT.
	 *
	 * Same fields as spend_refusal() (weight/remaining/limit/hint/status/code)
	 * but for a refusal that happens while dispatching ONE already-resolved
	 * tool inside handle_tools_call() — the batch-level, pre-dispatch whole
	 * refusal in handle_mcp_messages() still uses spend_refusal() unchanged,
	 * because it can apply to non-tools/call methods too, for which isError
	 * has no meaning.
	 *
	 * @param mixed  $id      Request ID.
	 * @param string $message Refusal message.
	 * @param mixed  $data    Refusal data (weight, remaining, limit, hint).
	 * @param string $code    spend_budget_exhausted or spend_budget_exceeded.
	 * @return array JSON-RPC result response with isError:true.
	 */
	private function spend_refusal_result( $id, $message, $data, $code = Mcpwp_Spend_Budget::ERROR_CODE ) {
		$data     = is_array( $data ) ? $data : array();
		$resolved = Mcpwp_Spend_Budget::is_refusal_code( $code ) ? $code : Mcpwp_Spend_Budget::ERROR_CODE;
		$error    = array( 'status' => Mcpwp_Spend_Budget::HTTP_STATUS );

		foreach ( array( 'weight', 'remaining', 'limit', 'hint' ) as $field ) {
			if ( isset( $data[ $field ] ) ) {
				$error[ $field ] = $data[ $field ];
			}
		}

		return $this->tool_error_result( $id, $resolved, (string) $message, $error );
	}

	/**
	 * Normalize a trusted custom-tool callback result to a REST response.
	 *
	 * Callback registrations run only after the standard MCP gateway gates have
	 * passed. They receive the validated, remapped arguments and may return the
	 * same result shapes as a WordPress REST handler.
	 *
	 * @param mixed $result Callback return value.
	 * @return WP_REST_Response Normalized response for the shared MCP response flow.
	 */
	private function normalize_custom_tool_callback_response( $result ) {
		if ( $result instanceof WP_REST_Response ) {
			return $result;
		}

		if ( is_wp_error( $result ) ) {
			$error_data = $result->get_error_data();
			$error_data = is_array( $error_data ) ? $error_data : array();
			$status     = isset( $error_data['status'] ) ? (int) $error_data['status'] : 500;

			return new WP_REST_Response(
				array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
					'data'    => $error_data,
				),
				$status
			);
		}

		if ( is_array( $result ) ) {
			return new WP_REST_Response( $result, 200 );
		}

		return new WP_REST_Response(
			array(
				'code'    => 'invalid_callback_response',
				'message' => 'Custom tool callback must return a WP_REST_Response, WP_Error, or array.',
			),
			500
		);
	}

	/**
	 * Fire the mcpwp_tool_called action with timing and category data.
	 *
	 * @param string $tool_name  MCP tool name (may be empty for missing-name errors).
	 * @param float  $start      microtime(true) captured at entry of handle_tools_call.
	 * @param string $error_code Enum error code, '' on success.
	 */
	private function fire_tool_called( $tool_name, $start, $error_code ) {
		$all_cats    = $this->get_all_tool_categories();
		$category    = ( $tool_name && isset( $all_cats[ $tool_name ] ) ) ? $all_cats[ $tool_name ] : 'site';
		$duration_ms = (int) round( ( microtime( true ) - $start ) * 1000 );
		do_action( 'mcpwp_tool_called', $tool_name, $category, $duration_ms, $error_code );
	}

	/**
	 * Small, explicit synonym groups for #911's "known synonyms" case —
	 * concepts different tools spell differently. A near-miss key is mapped
	 * onto the schema's own member of its group only when the tool's schema
	 * carries EXACTLY one member of that group (see remap_near_miss_params());
	 * with more than one present, which one the caller meant is a guess this
	 * issue asks not to make, so it is refused instead (a tie, not a map).
	 *
	 * Empty today (adversarial review of PR #986, round 1, BLOCK-1). The
	 * `id`/`page_id`/`post_id` group this started with was removed entirely:
	 * across the 295-tool schema dump, `id` is the sole group member on ~105
	 * tools, and it means a completely different resource on each — a term,
	 * a menu, a webhook, a WooCommerce order, a course, a sidebar widget —
	 * never uniformly "a post or page". `wp_delete_term({post_id: 12})` would
	 * have silently mapped `post_id` onto `id` and deleted TERM 12, which is
	 * not what a caller typing "post_id" out of habit meant. #831's per-tool
	 * `param_aliases` (curated, one tool at a time) is the correct place for
	 * a genuine `page_id` -> `id` rename; this generic table is not, for any
	 * pairing where the target's meaning varies by tool.
	 *
	 * `content`/`content_html` was considered and also dropped: the schema
	 * shows `content` meaning a post/page body on some tools but a course,
	 * lesson, quiz or event DESCRIPTION, a translation, or raw Gutenberg
	 * block markup on others — not uniformly "post content" either, so it
	 * does not clear the bar a synonym entry here needs to clear. Kept as an
	 * empty table, not deleted outright, for a future pairing that a schema
	 * check actually clears — e.g. a synonym that means the exact same thing
	 * on every tool that has one of its members.
	 *
	 * @var string[][]
	 */
	const PARAM_SYNONYM_GROUPS = array();

	/**
	 * Substrings (case-insensitive) that mark a parameter name as
	 * privileged/destructive — never the target of an auto-mapped near-miss
	 * (#911), even when it is the caller's only candidate. These gate
	 * privilege, destructive intent, state-flipping toggles, or auth/
	 * permission state — a wrong guess here is a security regression, not a
	 * convenience. A call naming one of these unexpectedly is refused with
	 * the standard unknown-key message; the caller must spell it out on
	 * purpose, never have it inferred.
	 *
	 * Deliberately substrings, not exact names only (adversarial review of
	 * PR #986, round 1, WARN-1) — `force`/`status`/`role` as exact-only
	 * missed real near-misses of real schema params: `forceTypeChange`
	 * (wp_update_option), `postStatus`/`stockStatus` (wp_deploy_site_blueprint
	 * / wc_create_product), `allowCompounding`/`allowRestrictedBlocks`
	 * (wp_bulk_find_replace / wp_set_blocks), `purgeEverything`
	 * (wp_cloudflare_purge_cache), `overwrite` case variants (wp_setup_menu),
	 * `Enabled` (wp_update_rate_limit), `noIndex` (wp_set_noindex/wp_set_seo).
	 * Across all 295 tools this list protects 27 distinct real param names —
	 * see the PR #986 review-round comment for the full enumeration.
	 *
	 * @var string[]
	 */
	const PRIVILEGED_PARAM_MARKERS = array(
		'delete',
		'auth',
		'permission',
		'scope',
		'secret',
		'password',
		'token',
		'privilege',
		'admin',
		'role',
		'status',
		'force',
		'allow',
		'purge',
		'overwrite',
		'publish',
		'enabled',
		'noindex',
	);

	/**
	 * Whether a (candidate) parameter name is privileged/destructive and must
	 * never be the target of an auto-mapped near-miss (#911).
	 *
	 * @param string $name Candidate canonical parameter name.
	 * @return bool
	 */
	private function is_privileged_param_name( $name ) {
		$name = strtolower( (string) $name );
		if ( '' === $name ) {
			return false;
		}
		foreach ( self::PRIVILEGED_PARAM_MARKERS as $marker ) {
			if ( false !== strpos( $name, $marker ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Normalize a parameter key for case/separator-insensitive matching
	 * (#911's generic normalizer) — `postId`, `post-id`, `POST_ID` and
	 * `post_id` all reduce to `postid`. Word-level synonyms (`content_html`
	 * vs `content`) are NOT caught here on purpose — they normalize to
	 * different strings and are handled only by the explicit
	 * PARAM_SYNONYM_GROUPS table above, never guessed generically.
	 *
	 * @param string $key Raw parameter key.
	 * @return string Normalized form, lowercase alphanumeric only.
	 */
	private function normalize_param_key( $key ) {
		$key = (string) $key;
		// camelCase / PascalCase -> snake_case before the separator strip, so
		// `postId` and `post_id` normalize to the same string.
		$key = preg_replace( '/([a-z0-9])([A-Z])/', '$1_$2', $key );
		$key = strtolower( (string) $key );
		return (string) preg_replace( '/[^a-z0-9]/', '', (string) $key );
	}

	/**
	 * Coerce a raw argument value into the same comparable form a JSON-RPC
	 * caller's `"5"` and `5` (or `5.0`) already are to WordPress's own REST
	 * value handling — WP_REST_Request/rest_sanitize_* treat those as the
	 * same value, so a duplicate near-miss sent as a numeric string must not
	 * read as a conflict against the real key's numeric value (#911 review
	 * round 1, BLOCK-2).
	 *
	 * @param mixed $value Raw value.
	 * @return mixed Comparable form.
	 */
	private function coerce_param_value_for_compare( $value ) {
		if ( is_numeric( $value ) ) {
			// "5", 5, 5.0 and "5.0" all become the same string ("5"), and a
			// non-integer stays exact ("5.5").
			return (string) ( $value + 0 );
		}
		return $value;
	}

	/**
	 * Whether two argument values are the same, once coerced the way the
	 * REST layer already treats a JSON string vs a JSON number (#911 review
	 * round 1, BLOCK-2). Strict (`===`) after coercion — never loose `==`,
	 * which PHP still applies its own (safer, but still surprising) rules to
	 * for non-numeric strings.
	 *
	 * @param mixed $a First value.
	 * @param mixed $b Second value.
	 * @return bool
	 */
	private function param_values_equal( $a, $b ) {
		return $this->coerce_param_value_for_compare( $a ) === $this->coerce_param_value_for_compare( $b );
	}

	/**
	 * Remap unknown top-level argument keys onto the tool's real schema
	 * where the caller's key is an unambiguous near-miss (#911).
	 *
	 * For each key in $arguments that is not already a known param:
	 *  - a case/separator variant of exactly one known param, or a member of
	 *    a PARAM_SYNONYM_GROUPS group of which the schema carries exactly one
	 *    other member, is the key's sole candidate;
	 *  - a key with zero candidates is left alone (genuinely unknown — the
	 *    caller gets a fuzzy did-you-mean instead, from validate_tool_arguments());
	 *  - a key with more than one candidate is a tie and is never mapped —
	 *    which one the caller meant is exactly the guess this issue refuses
	 *    to make;
	 *  - a key whose sole candidate the caller ALSO supplied under its real
	 *    name is dropped silently ONLY when the two values are the same
	 *    (param_values_equal()) — matching resolve_param_aliases()'s existing
	 *    "canonical wins" rule for #831's deprecated-alias table. When they
	 *    differ, this is a genuine conflict (adversarial review of PR #986,
	 *    round 1, BLOCK-2): silently picking either value would be a guess
	 *    with the caller's own data as the stakes, so it is refused instead,
	 *    same as an unresolved near-miss. This also settles which of two
	 *    near-misses that target the SAME candidate wins: whichever this
	 *    loop resolves first is compared against every later one the same
	 *    way, so two near-misses with equal values collapse to one fix and
	 *    two with different values are refused as a conflict — neither is
	 *    ever silently preferred over the other;
	 *  - a key whose sole candidate is a privileged/destructive param
	 *    (is_privileged_param_name()) is never mapped — refused instead, same
	 *    as a true unknown key, so a caller cannot get `force` or `role` set
	 *    by a coincidental near-miss of an unrelated typo.
	 *
	 * @param string[] $known_params Property names from the tool's inputSchema.
	 * @param array    $arguments    Raw arguments (already alias-resolved by
	 *                                resolve_param_aliases()).
	 * @return array{arguments:array,fixes:array[],refused:array<string,array{candidate:string,reason:string}>}
	 */
	private function remap_near_miss_params( array $known_params, array $arguments ) {
		$fixes      = array();
		$refused    = array();
		$known_flip = array_flip( $known_params );

		// Snapshot the original keys — mutations below (mapping a key onto a
		// new one) must not make this loop revisit a key it just created.
		foreach ( array_keys( $arguments ) as $key ) {
			if ( ! is_string( $key ) || isset( $known_flip[ $key ] ) ) {
				continue;
			}

			$candidates = array();

			foreach ( self::PARAM_SYNONYM_GROUPS as $group ) {
				if ( ! in_array( $key, $group, true ) ) {
					continue;
				}
				foreach ( $group as $member ) {
					if ( $member !== $key && isset( $known_flip[ $member ] ) ) {
						$candidates[ $member ] = true;
					}
				}
			}

			$normalized_key = $this->normalize_param_key( $key );
			if ( '' !== $normalized_key ) {
				foreach ( $known_params as $known ) {
					if ( $this->normalize_param_key( $known ) === $normalized_key ) {
						$candidates[ $known ] = true;
					}
				}
			}

			$candidates = array_keys( $candidates );

			if ( 1 !== count( $candidates ) ) {
				if ( count( $candidates ) > 1 ) {
					$refused[ $key ] = array(
						'candidate' => implode( ', ', $candidates ),
						'reason'    => 'ambiguous',
					);
				}
				continue; // 0 candidates: genuinely unknown.
			}

			$candidate = $candidates[0];

			if ( array_key_exists( $candidate, $arguments ) ) {
				if ( $this->param_values_equal( $arguments[ $key ], $arguments[ $candidate ] ) ) {
					// Same value under both names — redundant, drop the
					// near-miss rather than overwrite the real key with an
					// identical value.
					unset( $arguments[ $key ] );
					$fixes[] = array(
						'from'   => $key,
						'to'     => $candidate,
						'reason' => 'duplicate_dropped',
					);
					continue;
				}

				// Different values under the near-miss and the real key —
				// never silently pick one (#911 review round 1, BLOCK-2).
				$refused[ $key ] = array(
					'candidate' => $candidate,
					'reason'    => 'conflict',
				);
				continue;
			}

			if ( $this->is_privileged_param_name( $candidate ) ) {
				$refused[ $key ] = array(
					'candidate' => $candidate,
					'reason'    => 'privileged',
				);
				continue;
			}

			$arguments[ $candidate ] = $arguments[ $key ];
			unset( $arguments[ $key ] );
			$fixes[] = array(
				'from'   => $key,
				'to'     => $candidate,
				'reason' => 'near_miss',
			);
		}

		return array(
			'arguments' => $arguments,
			'fixes'     => $fixes,
			'refused'   => $refused,
		);
	}

	/**
	 * Validate tool arguments against the tool's inputSchema, forgiving of
	 * near-miss keys (#911).
	 *
	 * Checks for missing required parameters and unknown parameters. Before
	 * either check, an unknown top-level key that is an unambiguous near-miss
	 * of exactly one real param is silently remapped by
	 * remap_near_miss_params() — everything that method leaves unresolved (no
	 * candidate, a tie, or a privileged/destructive target) is refused here,
	 * naming the unknown key, its closest valid key(s) when any exist, and
	 * the tool's required parameters, so a model can self-correct in one
	 * round trip instead of guessing again. Does NOT type-check values (MCP
	 * clients often send strings for all types).
	 *
	 * A schema that declares `additionalProperties: true` opts the whole
	 * tool out of both the remap and the unknown-key refusal — it intends to
	 * accept arbitrary extra top-level keys. No first-party or
	 * mcpwp_register_tools schema does this today; the guard exists for
	 * whichever tool is the first to.
	 *
	 * @param string $tool_name The tool name.
	 * @param array  $arguments The arguments to validate (already alias-resolved).
	 * @return array{arguments:array,fixes:array[],error:?string} Possibly-remapped
	 *         arguments, the fixes applied (for _meta.param_fixes), and an
	 *         error message, or null error if valid.
	 */
	private function validate_tool_arguments( $tool_name, $arguments ) {
		$arguments = is_array( $arguments ) ? $arguments : array();
		$result    = array(
			'arguments' => $arguments,
			'fixes'     => array(),
			'error'     => null,
		);

		$schema = $this->get_tool_input_schema( $tool_name );
		if ( ! $schema ) {
			return $result; // No schema to validate against.
		}

		// Get properties as an array (may be stdClass if empty).
		$properties = isset( $schema['properties'] ) ? (array) $schema['properties'] : array();
		$required   = isset( $schema['required'] ) ? $schema['required'] : array();
		$free_form  = isset( $schema['additionalProperties'] ) && true === $schema['additionalProperties'];
		$refused    = array();
		$known_params = array_keys( $properties );

		if ( ! $free_form && ! empty( $known_params ) ) {
			$remap = $this->remap_near_miss_params( $known_params, $arguments );
			$arguments           = $remap['arguments'];
			$result['arguments'] = $arguments;
			$result['fixes']     = $remap['fixes'];
			$refused             = $remap['refused'];
		}

		// Missing required params — collect every one, not just the first.
		$missing = array();
		foreach ( $required as $param ) {
			if ( ! array_key_exists( $param, $arguments ) ) {
				$missing[] = $param;
			}
		}

		// Unknown params that survived the remap above (no confident
		// candidate, a tie, a value conflict, or a candidate refused as
		// privileged/destructive) — the ORIGINAL key, string or not (a stray
		// integer/list-shaped key is exactly as unknown as a misspelled
		// string one and must refuse the same way it did before this fix).
		// Reports only the first for message brevity, matching this method's
		// existing single-message style.
		$unknown_param  = null;
		$unknown_detail = '';
		if ( ! $free_form && ! empty( $known_params ) ) {
			foreach ( array_keys( $arguments ) as $param ) {
				if ( in_array( $param, $known_params, true ) ) {
					continue;
				}

				$unknown_param = $param;

				if ( isset( $refused[ $param ] ) && 'privileged' === $refused[ $param ]['reason'] ) {
					$unknown_detail = sprintf(
						". '%s' looks like '%s', but that parameter is privileged/destructive and is never guessed — pass '%s' explicitly if that is what you meant.",
						$param,
						$refused[ $param ]['candidate'],
						$refused[ $param ]['candidate']
					);
				} elseif ( isset( $refused[ $param ] ) && 'ambiguous' === $refused[ $param ]['reason'] ) {
					$unknown_detail = sprintf( ". '%s' matches more than one parameter (%s) — ambiguous, pass the exact name.", $param, $refused[ $param ]['candidate'] );
				} elseif ( isset( $refused[ $param ] ) && 'conflict' === $refused[ $param ]['reason'] ) {
					$unknown_detail = sprintf( ". '%s' looks like '%s', which was already supplied with a different value — ambiguous, pass one explicit value.", $param, $refused[ $param ]['candidate'] );
				} else {
					// Fuzzy-match for "did you mean?" suggestion.
					$best_match = null;
					$best_score = 0;
					foreach ( $known_params as $known ) {
						$score = 0;
						similar_text( (string) $param, $known, $score );
						if ( $score > $best_score ) {
							$best_score = $score;
							$best_match = $known;
						}
					}
					if ( $best_match && $best_score >= 50 ) {
						$unknown_detail = '. Did you mean: ' . $best_match . '?';
					}
				}

				break;
			}
		}

		if ( ! empty( $missing ) || null !== $unknown_param ) {
			$parts = array();
			if ( ! empty( $missing ) ) {
				$parts[] = 'Missing required parameter' . ( count( $missing ) > 1 ? 's' : '' ) . ': ' . implode( ', ', $missing ) . '.';
			}
			if ( null !== $unknown_param ) {
				$parts[] = 'Unknown parameter: ' . $unknown_param . $unknown_detail;
			}
			$parts[] = 'Required: ' . ( empty( $required ) ? 'none' : implode( ', ', $required ) ) . '.';
			if ( null !== $unknown_param ) {
				$parts[] = 'Known parameters: ' . implode( ', ', $known_params ) . '.';
			}
			$result['error'] = implode( ' ', $parts );
		}

		return $result;
	}

	/**
	 * Look up one tool's inputSchema by name, populating (and reusing) the
	 * tools cache. Shared by validate_tool_arguments() and
	 * canonical_tool_arguments() so pricing/hashing and actual validation can
	 * never see a different schema for the same tool name.
	 *
	 * @param string $tool_name Tool name.
	 * @return array|null The tool's inputSchema, or null if the tool (or a
	 *                     schema for it) does not exist.
	 */
	private function get_tool_input_schema( $tool_name ) {
		if ( $this->tools_cache === null ) {
			$this->tools_cache = $this->get_all_tools();
		}

		foreach ( $this->tools_cache as $tool ) {
			if ( $tool['name'] === $tool_name ) {
				return isset( $tool['inputSchema'] ) ? $tool['inputSchema'] : null;
			}
		}

		return null;
	}

	/**
	 * The arguments a tools/call for $tool_name will actually dispatch with,
	 * once #831's deprecated-alias table and #911's forgiving-params remap
	 * both run — the identical transform handle_tools_call() applies before
	 * dispatch. Pricing (message_spend_price()) and idempotency hashing
	 * (handle_tools_call_idempotent(), has_stored_result()) call this so a
	 * caller who spells a parameter differently is priced, and identified for
	 * idempotency, exactly as if they had spelled it canonically — the same
	 * call must not be a different price or a different idempotency identity
	 * just because of which near-miss spelling reached the server (adversarial
	 * review of PR #986, round 1, WARN-2 and its NIT).
	 *
	 * Deliberately reuses validate_tool_arguments() itself, not a re-fetch of
	 * the schema plus a second call to remap_near_miss_params() — one
	 * function computes "what this call will actually run as", so pricing,
	 * hashing and dispatch cannot drift apart from each other over time.
	 *
	 * @param string $tool_name Tool name.
	 * @param array  $mapping   Tool map entry (for param_aliases).
	 * @param array  $arguments Raw arguments.
	 * @return array Canonical (alias-resolved, near-miss-remapped) arguments.
	 */
	private function canonical_tool_arguments( $tool_name, $mapping, $arguments ) {
		$arguments = is_array( $arguments ) ? $arguments : array();
		$arguments = $this->resolve_param_aliases( $mapping, $arguments );
		return $this->validate_tool_arguments( $tool_name, $arguments )['arguments'];
	}

	/**
	 * Get detected integrations for introspection.
	 *
	 * @return array Detected integrations with boolean flags.
	 */
	private function get_detected_integrations() {
		// Ensure plugin.php is loaded for is_plugin_active()
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Detect integrations using is_plugin_active() where possible
		$integrations = array(
			'elementor'   => is_plugin_active( 'elementor/elementor.php' )
				|| defined( 'ELEMENTOR_VERSION' ),
			'woocommerce' => is_plugin_active( 'woocommerce/woocommerce.php' )
				|| class_exists( 'WooCommerce' ),
			'rankmath'    => is_plugin_active( 'seo-by-rank-math/rank-math.php' )
				|| defined( 'RANK_MATH_VERSION' ),
			'yoast'       => is_plugin_active( 'wordpress-seo/wp-seo.php' )
				|| defined( 'WPSEO_VERSION' ),
			'pro_active'  => $this->is_pro_active(),
		);

		// Third-party integrations.
		foreach ( Mcpwp_Integration::resolve_all() as $integration ) {
			$info = $integration->get_info();
			if ( ! empty( $info['slug'] ) ) {
				$integrations[ $info['slug'] ] = true;
			}
		}

		return $integrations;
	}

	/**
	 * Build dynamic server instructions for the MCP initialize response.
	 *
	 * Returns a concise system prompt that helps AI models understand this
	 * WordPress site and use the available tools correctly. Content is
	 * dynamic based on detected plugins and capabilities.
	 *
	 * @return string Markdown-formatted instructions.
	 */
	private function build_server_instructions() {
		$core         = class_exists( 'Mcpwp_Core' ) ? new Mcpwp_Core() : null;
		$capabilities = $core ? $core->get_capabilities() : array();
		// Decoded for agents (#903) — see Mcpwp_Agent_Text.
		$site_name    = function_exists( 'get_bloginfo' ) ? Mcpwp_Agent_Text::decode( get_bloginfo( 'name' ) ) : 'this site';
		$site_url     = function_exists( 'home_url' ) ? home_url() : '';

		$lines = array();

		// --- Plugin overview ---
		$lines[] = '# MCPWP — MCP Server Instructions';
		$lines[] = '';
		$lines[] = 'You are connected to **' . $site_name . '**' . ( $site_url ? ' (' . $site_url . ')' : '' ) . ' via MCPWP, a WordPress management plugin that exposes content, design, SEO, and admin tools through MCP.';
		$lines[] = '';

		// --- Best practices ---
		$lines[] = '## Getting Started';
		$lines[] = '';
		$lines[] = '1. **Always call `wp_onboard` first** — it returns a short briefing: site identity, builder mode, connected services, content summary and the top next actions. Pass `detail=full` for the complete inventory; use tools/list for the tool catalog.';
		$lines[] = '2. Call `wp_get_site_context` to read the site style guide and design rules (if configured).';
		$lines[] = '3. Use `wp_detect_plugins` to confirm which integrations are available before using plugin-specific tools.';
		$lines[] = '';

		// --- WordPress primer ---
		$lines[] = '## WordPress Concepts';
		$lines[] = '';
		$lines[] = '- **Posts** — blog entries with categories, tags, and dates. Use `wp_list_posts`, `wp_create_post`, `wp_update_post`.';
		$lines[] = '- **Pages** — static content (About, Contact, etc.). Use `wp_list_pages`, `wp_create_page`, `wp_update_page`.';
		$lines[] = '- **Custom Post Types (CPTs)** — extended content types (products, events). Use `wp_list_content` with a `post_type` filter.';
		$lines[] = '- **Taxonomies** — categories and tags organize content. Use `wp_list_categories`, `wp_list_tags`, `wp_create_term`.';
		$lines[] = '- **Menus** — navigation menus assigned to theme locations. Use `wp_list_menus`, `wp_setup_menu`, `wp_add_menu_item`.';
		$lines[] = '- **Options** — site-wide settings (site title, front page, permalinks). Use `wp_get_options`, `wp_get_option`.';
		$lines[] = '- **Media** — images, files in the media library. Use `wp_upload_media_from_url`, `wp_list_media`.';
		$lines[] = '';

		// --- Elementor section (conditional) ---
		if ( ! empty( $capabilities['elementor'] ) ) {
			$layout_mode = ! empty( $capabilities['elementor_layout_mode'] ) ? $capabilities['elementor_layout_mode'] : 'section';

			$lines[] = '## Elementor Page Builder';
			$lines[] = '';
			$lines[] = 'This site uses Elementor (**' . $layout_mode . ' mode**) for visual page building.';
			$lines[] = '';

			if ( 'atomic' === $layout_mode ) {
				$lines[] = '- **Recommendation:** build with `container > container(s) > widget(s)` (classic widgets) — "atomic" mode means the Container experiment is also active, and that structure renders correctly today.';
				$lines[] = '- Genuine atomic elements (`e-div-block`, `e-heading`, ...) are accepted and saved, but Elementor\'s render pipeline currently drops them after save. Check `render_verified` in the `wp_set_elementor` response before trusting an atomic write. Details: `wp_get_guide(topic="elementor")`.';
			} elseif ( 'container' === $layout_mode ) {
				$lines[] = '- **Hierarchy:** `container > container(s) > widget(s)`. Containers can be nested.';
				$lines[] = '- Top-level elements use `"elType": "container"` with `"settings": {"content_width": "full"}` or boxed.';
			} else {
				$lines[] = '- **Hierarchy:** `section > column(s) > widget(s)`.';
				$lines[] = '- Columns need `"_column_size"` that sums to 100. Multi-column sections need a `"structure"` value (20=2col, 30=3col).';
			}

			$lines[] = '- Every element **must** have a unique `"id"` of exactly 7 alphanumeric characters (`a-z`, `A-Z`, `0-9`). Elementor Pro\'s AJAX widgets (Search live results, Taxonomy Filter on a Loop Grid) reject any other length and fail silently. The plugin auto-generates missing IDs, warns about non-conforming ones, and rewrites them when you pass `repair_ids=true` to `wp_patch_elementor` or `wp_set_elementor`. Details: `wp_get_guide(topic="elementor")`.';
			$lines[] = '- Use `wp_get_elementor` to read current data, `wp_set_elementor` to save. The response includes validation warnings.';
			$lines[] = '- Use `wp_get_widget_schema` to check correct widget settings keys before building.';
			$lines[] = '- After changing templates or global styles, call `wp_regenerate_elementor_css`.';
			$lines[] = '';
		}

		// --- Gutenberg section (conditional) ---
		if ( ! empty( $capabilities['gutenberg'] ) ) {
			$lines[] = '## Gutenberg Block Editor';
			$lines[] = '';
			$lines[] = '- Use `wp_get_blocks` to read parsed block data and `wp_set_blocks` to update blocks.';
			$lines[] = '- Use `wp_list_block_types` to discover available block types before building content.';
			$lines[] = '- Use `wp_list_block_patterns` to find pre-built layouts.';
			$lines[] = '';
		}

		// --- SEO section (conditional) ---
		$has_yoast    = ! empty( $capabilities['yoast'] );
		$has_rankmath = ! empty( $capabilities['rankmath'] );
		$has_aioseo   = ! empty( $capabilities['aioseo'] );
		$has_seopress = ! empty( $capabilities['seopress'] );
		$has_seo      = $has_yoast || $has_rankmath || $has_aioseo || $has_seopress;

		if ( $has_seo ) {
			$seo_plugin = $has_yoast ? 'Yoast SEO' : ( $has_rankmath ? 'RankMath' : ( $has_aioseo ? 'AIOSEO' : 'SEOPress' ) );

			$lines[] = '## SEO (' . $seo_plugin . ')';
			$lines[] = '';
			$lines[] = '- Use `wp_get_seo` and `wp_set_seo` to manage meta titles, descriptions, focus keywords, and social data.';

			if ( $has_yoast ) {
				$lines[] = '- Yoast keys: `_yoast_wpseo_title`, `_yoast_wpseo_metadesc`, `_yoast_wpseo_focuskw`.';
			} elseif ( $has_rankmath ) {
				$lines[] = '- RankMath keys: `rank_math_title`, `rank_math_description`, `rank_math_focus_keyword`.';
			}

			$lines[] = '- Use `wp_analyze_seo` to audit a page and `wp_bulk_seo` to update SEO data across multiple pages.';
			$lines[] = '';
		}

		// --- WooCommerce section (conditional) ---
		if ( ! empty( $capabilities['woocommerce'] ) ) {
			$lines[] = '## WooCommerce';
			$lines[] = '';
			$lines[] = '- Products are a custom post type. Use WooCommerce-specific tools when available.';
			$lines[] = '- Product pages may use Elementor templates or Gutenberg blocks depending on the theme.';
			$lines[] = '';
		}

		// --- Pro notice (when not licensed) ---
		if ( ! $this->is_pro_active() ) {
			$lines[] = '## Pro Features (Not Active)';
			$lines[] = '';
			$lines[] = 'This site runs MCPWP Free. The following tools appear in the tool list but will return an upgrade prompt when called:';
			$lines[] = '- **Staging** — `wp_get_staging_info`, `wp_push_to_staging`, `wp_push_to_live`, `wp_get_staging_status` (WP Engine / Kinsta / Pressable environment management)';
			$lines[] = '- **Advanced SEO** — `wp_get_seo`, `wp_set_seo`, `wp_analyze_seo`, `wp_bulk_seo`';
			$lines[] = '- **WooCommerce** — product/order/analytics tools';
			$lines[] = '- **Theme Builder** — `wp_get_theme_conditions`, `wp_set_theme_conditions`';
			$lines[] = '';
			$lines[] = 'Upgrade at https://mcpwp.net/pricing/ or via WP Admin > MCPWP.';
			$lines[] = '';
		}

		// --- Common mistakes ---
		$lines[] = '## Common Mistakes to Avoid';
		$lines[] = '';
		$lines[] = '- **Do not guess widget settings keys** — use `wp_get_widget_schema` to look up the correct keys for any Elementor widget.';
		$lines[] = '- **Do not create pages without checking existing content** — always call `wp_list_pages` or `wp_search` first.';
		$lines[] = '- **Do not skip reading site context** — the site may have specific design rules, color palettes, and layout guidelines.';
		$lines[] = '- **Do not hardcode URLs** — use `wp_site_info` to get the correct site URL and admin URL.';
		$lines[] = '- **Do not forget to set featured images** — use `wp_set_featured_image` after creating posts/pages with images.';
		$lines[] = '';

		return implode( "\n", $lines );
	}

	/**
	 * Build contextual workflows based on site capabilities.
	 *
	 * @param array $capabilities Detected site capabilities.
	 * @param bool  $is_pro      Whether Pro license is active.
	 * @return array Contextual workflows.
	 */
	private function build_contextual_workflows( $capabilities, $is_pro ) {
		$workflows = array();

		// Setup (always present)
		$workflows['Setup'] = array(
			'Call wp_introspect to discover capabilities and version.',
			'Call wp_get_site_context to read the site brief and design/content rules.',
			'Call wp_get_guide(topic="onboarding") and wp_get_guide(topic="workflows") before making structural changes.',
			'Use wp_create_api_key for scoped keys (admin only).',
		);

		// Content Management (always present)
		$workflows['Content Management'] = array(
			'Use wp_list_posts and wp_list_pages to browse existing content.',
			'Use wp_fetch to retrieve full content for specific posts or pages.',
			'Use wp_create_post, wp_update_post, wp_create_page, wp_update_page to publish changes.',
			'Use wp_list_drafts to review unpublished content.',
		);

		// Gutenberg (conditional)
		if ( ! empty( $capabilities['gutenberg'] ) ) {
			$workflows['Gutenberg Blocks'] = array(
				'Use wp_get_blocks to retrieve parsed block data for a post or page.',
				'Use wp_set_blocks to update block content (pass blocks array or raw content).',
				'Use wp_list_block_types to discover available block types before building.',
				'Use wp_list_block_patterns to find pre-built layouts to insert.',
			);
		}

		// Elementor (conditional)
		if ( ! empty( $capabilities['elementor'] ) ) {
			$workflows['Elementor'] = array(
				'Use wp_list_elementor_archetypes to find canonical page archetypes before building from scratch.',
				'Use wp_list_elementor_parts to reuse saved sections before creating new sections.',
				'Use wp_get_elementor to retrieve Elementor JSON data for a page.',
				'Use wp_set_elementor to update Elementor page builder content.',
				'Use wp_create_elementor_part_from_section to save strong reusable sections back into the library.',
				'Use wp_regenerate_elementor_css to rebuild CSS after template changes.',
			);
		}

		// eCommerce (conditional)
		if ( ! empty( $capabilities['woocommerce'] ) ) {
			$workflows['eCommerce'] = array(
				'Use wc_list_product_archetypes to find the correct product archetype before creating similar products.',
				'Use wc_apply_product_archetype to create or update products from a canonical structure.',
				'Monitor orders and customer data.',
				'Update product descriptions, pricing, and categories.',
			);
		}

		// SEO (conditional - any SEO plugin)
		$has_seo = ! empty( $capabilities['yoast'] )
			|| ! empty( $capabilities['rankmath'] )
			|| ! empty( $capabilities['aioseo'] )
			|| ! empty( $capabilities['seopress'] );

		if ( $has_seo ) {
			$workflows['SEO'] = array(
				'Use wp_get_seo and wp_set_seo to manage page metadata.',
				'Set focus keywords, meta descriptions, and social sharing data.',
				'Optimize content for search engines using detected SEO plugin.',
			);
		}

		// Licensed features (conditional).
		if ( $is_pro ) {
			$workflows['Licensed Features'] = array(
				'Use multilanguage tools for WPML/Polylang integrations.',
				'Manage Contact Form 7, WPForms, Gravity Forms, and Ninja Forms.',
				'Control widgets, sidebars, and theme customizations.',
			);
		}

		// Media (always present)
		$workflows['Media'] = array(
			'Use wp_upload_media to upload images from URLs or base64 data.',
			'Use wp_screenshot to capture page screenshots for review.',
		);

		// Administration (always present)
		$workflows['Administration'] = array(
			'Use wp_create_api_key and wp_list_api_keys to manage authentication.',
			'Use wp_create_webhook and wp_list_webhooks for event notifications.',
			'Monitor usage via wp_rate_limits and wp_activity_log.',
		);

		return $workflows;
	}

	/**
	 * Check if licensed features are active.
	 *
	 * @return bool True when a non-WP.org build has an active entitlement.
	 */
	private function is_pro_active() {
		if ( defined( 'MCPWP_WPORG_BUILD' ) ) {
			return false;
		}

		return class_exists( 'Mcpwp_License' ) && Mcpwp_License::get_instance()->is_pro();
	}

	/**
	 * Build a tools/call RESULT reporting an execution failure as data the
	 * model can read, instead of a JSON-RPC protocol error.
	 *
	 * Per the MCP spec ("Error Handling"), a protocol-level `error` is for
	 * parse failures, malformed requests, unknown methods/tools, and genuine
	 * server faults — not for a tool that resolved and then failed to run.
	 * Many clients drop `error.data` entirely, which is exactly where this
	 * plugin's `hint`/`guide` used to live, so a model could never see why a
	 * call failed or how to fix it. This returns a normal JSON-RPC `result`
	 * with `isError: true`: the teaching text goes in `content` (which every
	 * client shows the model), and every machine field a programmatic caller
	 * previously read from `error.data` is preserved verbatim under
	 * `result.structuredContent.error`.
	 *
	 * @param mixed  $id      JSON-RPC id.
	 * @param string $code    Machine error code (e.g. 'pro_required', 'scope_denied',
	 *                        'category_disabled', 'rate_limited', 'execution_error', or a
	 *                        granular REST error code carried through from the route).
	 * @param string $message One-line description of what failed.
	 * @param array  $data    Extra machine fields — hint, guide, status, retry_after,
	 *                        upgrade_url, required_scope, plan_required, weight,
	 *                        remaining, limit, etc. Merged as-is into structuredContent.error.
	 * @return array JSON-RPC result response with isError:true.
	 */
	private function tool_error_result( $id, $code, $message, array $data = array() ) {
		$error = array_merge( array( 'code' => $code, 'message' => $message ), $data );

		// Teaching text: one line of what failed, then "Fix:" from hint, then
		// "Next:" from guide. Short and plain — no JSON dump — because this is
		// what a model actually reads; the full machine detail is still in
		// structuredContent.error for anything that parses it.
		$lines = array( $message );
		if ( ! empty( $error['hint'] ) && is_string( $error['hint'] ) ) {
			$lines[] = 'Fix: ' . $error['hint'];
		}
		if ( ! empty( $error['guide'] ) && is_string( $error['guide'] ) ) {
			$lines[] = 'Next: ' . $error['guide'];
		}

		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => array(
				'content'           => array(
					array(
						'type' => 'text',
						'text' => implode( "\n", $lines ),
					),
				),
				'isError'           => true,
				'structuredContent' => array( 'error' => $error ),
			),
		);
	}

	/**
	 * Create a JSON-RPC error response.
	 *
	 * @param mixed  $id      Request ID.
	 * @param int    $code    Error code.
	 * @param string $message Error message.
	 * @param mixed  $data    Optional error data.
	 * @return array JSON-RPC error response.
	 */
	private function jsonrpc_error( $id, $code, $message, $data = null ) {
		$error = array(
			'code'    => $code,
			'message' => $message,
		);

		if ( $data !== null ) {
			$error['data'] = $data;
		}

		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => $error,
		);
	}

	/**
	 * Create a JSON-RPC error response (legacy method for backward compatibility).
	 *
	 * @param mixed  $id      Request ID.
	 * @param int    $code    Error code.
	 * @param string $message Error message.
	 * @return WP_REST_Response Error response.
	 */
	private function jsonrpc_error_response( $id, $code, $message ) {
		$response = new WP_REST_Response(
			$this->jsonrpc_error( $id, $code, $message ),
			200 // JSON-RPC errors return 200 with error in body
		);
		return $this->prepare_mcp_response( $response );
	}

	/**
	 * Add CORS headers to response.
	 *
	 * @param WP_REST_Response $response Response object.
	 */
	/**
	 * The configured limits plus what is left for this caller, for initialize.
	 *
	 * @return array
	 */
	private function describe_rate_limit( $include_config = true ) {
		if ( ! class_exists( 'Mcpwp_Rate_Limiter' ) ) {
			return array( 'enabled' => false );
		}

		$limiter  = Mcpwp_Rate_Limiter::get_instance();
		$settings = $limiter->get_settings();
		$key      = $this->get_current_api_key_record();
		$limits   = array(
			'requests_per_minute' => (int) $settings['requests_per_minute'],
			'requests_per_hour'   => (int) $settings['requests_per_hour'],
			'burst_limit'         => (int) $settings['burst_limit'],
		);

		// A key with its own ceilings reports its own ceilings.
		if ( is_array( $key ) && ! empty( $key['rate_limits'] ) && is_array( $key['rate_limits'] ) ) {
			$map = array(
				'per_minute' => 'requests_per_minute',
				'per_hour'   => 'requests_per_hour',
				'burst'      => 'burst_limit',
			);
			foreach ( $map as $from => $to ) {
				if ( isset( $key['rate_limits'][ $from ] ) && (int) $key['rate_limits'][ $from ] > 0 ) {
					$limits[ $to ] = (int) $key['rate_limits'][ $from ];
				}
			}
		}

		// Whether the throttle is on, and its tuning, is site posture: admin
		// keys only. Every caller gets its own remaining budget.
		$described = $include_config ? array_merge( array( 'enabled' => (bool) $limiter->is_enabled() ), $limits ) : array();
		$budget    = $limiter->get_budget();

		if ( null !== $budget ) {
			$described['limit']     = $budget['limit'];
			$described['remaining'] = $budget['remaining'];
			$described['reset_in']  = $budget['reset_in'];
			$described['window']    = $budget['window'];
			$described['bucket']    = $budget['bucket'];
		}

		$described['diagnostics'] = 'initialize, wp_rate_limit_status and wp_site_info are metered separately and stay reachable while the limit is exhausted. Honour Retry-After on 429; treat 502/503/504 like 429 with backoff; keep in-flight calls at or below concurrency_hint.';

		return $described;
	}

	/**
	 * Prepare MCP response with proper headers.
	 *
	 * Ensures correct Content-Type, no-cache headers, rate limit headers,
	 * and cleans any output buffering to prevent SSE/content-type interference.
	 *
	 * @param WP_REST_Response $response Response object.
	 * @return WP_REST_Response Prepared response.
	 */
	private function prepare_mcp_response( $response, $with_version = true ) {
		$this->clean_mcp_output_buffers();

		// Force correct content type for JSON-RPC responses.
		$response->header( 'Content-Type', 'application/json; charset=UTF-8' );

		// Every authenticated response names the plugin that produced it, so
		// a client can notice the plugin being replaced under a running job
		// (#802). Never on the open OPTIONS path.
		if ( $with_version ) {
			$response->header( 'X-MCPWP-Version', $this->server_version );
		}

		// Prevent caching of API responses.
		$response->header( 'Cache-Control', 'no-cache, no-store, must-revalidate' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'Expires', '0' );

		// Add rate limit headers.
		$this->add_rate_limit_headers( $response );

		// Add CORS headers.
		$this->add_cors_headers( $response );

		return $response;
	}

	/**
	 * Clean buffers opened during MCP handling without closing pre-existing buffers.
	 *
	 * PHPUnit and some WordPress hosts use an outer output buffer. Closing those
	 * buffers from an API handler causes test instability and can interfere with
	 * host-level output handling.
	 *
	 * @return void
	 */
	private function clean_mcp_output_buffers() {
		while ( ob_get_level() > $this->initial_ob_level ) {
			ob_end_clean();
		}
	}

	private function add_cors_headers( $response ) {
		// Use configured allowed origins; fall back to site URL only.
		$settings = get_option( 'mcpwp_settings', array() );
		$allowed  = ! empty( $settings['allowed_origins'] )
			? array_map( 'trim', explode( ',', $settings['allowed_origins'] ) )
			: array();

		$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';

		if ( ! empty( $origin ) && in_array( $origin, $allowed, true ) ) {
			$response->header( 'Access-Control-Allow-Origin', $origin );
			$response->header( 'Vary', 'Origin' );
		} elseif ( empty( $allowed ) ) {
			// No origins configured: allow all (MCP clients are non-browser).
			// Site owners can restrict via Settings > Allowed Origins.
			$response->header( 'Access-Control-Allow-Origin', '*' );
		}
		// If origins are configured but request origin doesn't match, no CORS header is sent.

		$response->header( 'Access-Control-Allow-Methods', 'POST, OPTIONS' );
		$response->header( 'Access-Control-Allow-Headers', 'Content-Type, X-API-Key, Mcp-Session-Id, MCP-Protocol-Version, Authorization' );
		$response->header( 'Access-Control-Max-Age', '86400' );
	}

	/**
	 * Log MCP activity.
	 *
	 * @param string $action  Action name.
	 * @param mixed  $context Context data.
	 */
	private function log_mcp_activity( $action, $context ) {
		$settings = get_option( 'mcpwp_settings', array() );
		if ( empty( $settings['enable_logging'] ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'mcpwp_activity_log';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- activity log insert; table name from $wpdb->prefix.
		$wpdb->insert(
			$table,
			array(
				'action'       => sanitize_key( $action ),
				'endpoint'     => '/mcpwp/v1/mcp',
				'method'       => 'POST',
				'status_code'  => 200,
				'ip_address'   => $this->get_client_ip_for_logging(),
				'user_agent'   => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
				'request_data' => wp_json_encode( $this->redact_log_data( $context, $this->get_log_redaction_keys() ) ),
				'created_at'   => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
	}
}
