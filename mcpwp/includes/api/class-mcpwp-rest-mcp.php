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
	 * MCP protocol version.
	 *
	 * @var string
	 */
	private $protocol_version = '2024-11-05';

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
		$figma_status = class_exists( 'Mcpwp_Figma' ) ? ( new Mcpwp_Figma() )->get_status() : array();
		$figma_configured = ! empty( $figma_status['configured'] );

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
		$site_name            = function_exists( 'get_bloginfo' ) ? get_bloginfo( 'name' ) : '';
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
					'protocolVersion' => $this->protocol_version,
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
		if ( ! is_array( $response ) || ! isset( $response['result'] ) || ! is_array( $response['result'] ) ) {
			return $response;
		}

		if ( ! isset( $response['result']['_meta'] ) || ! is_array( $response['result']['_meta'] ) ) {
			$response['result']['_meta'] = array();
		}

		$response['result']['_meta']['plugin_version'] = $this->server_version;

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
		$args_hash = hash( 'sha256', $tool_name . '|' . wp_json_encode( self::canonicalize( $arguments ) ) );
		$existing  = get_transient( $store_key );

		if ( is_array( $existing ) && isset( $existing['state'] ) && 'done' === $existing['state'] ) {
			// A done record without a readable response is corruption. Running
			// the write again on the strength of a broken record is the one
			// thing this feature must never do.
			if ( ! isset( $existing['args_hash'], $existing['response'] ) || ! is_array( $existing['response'] ) ) {
				return $this->jsonrpc_error(
					$id,
					-32010,
					sprintf( 'The stored result for request_id "%s" is unreadable.', $request_id ),
					array( 'hint' => 'The first attempt completed but its record is corrupt, so it cannot be replayed and will not be re-run. Verify the site state and use a new request_id if the work is still needed.' )
				);
			}

			if ( $existing['args_hash'] !== $args_hash ) {
				return $this->jsonrpc_error(
					$id,
					-32602,
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
						return $this->jsonrpc_error(
							$id,
							-32602,
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

			return $this->with_idempotency_note( $response, 'stored', null );
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
		return $this->jsonrpc_error(
			$id,
			-32009,
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

			if ( isset( $data['failed'] ) && (int) $data['failed'] > 0 ) {
				return true;
			}
		}

		return false;
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
					'result'  => array(),
				);

			case 'tools/list':
				return $this->handle_tools_list( $id, $params );

			case 'tools/call':
				return $this->handle_tools_call_idempotent( $id, $params, $request );

			case 'resources/list':
				return $this->handle_resources_list( $id );

			case 'resources/read':
				return $this->handle_resources_read( $id, $params );

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
			'protocolVersion' => $this->protocol_version,
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
	 * Handle resources/list method.
	 *
	 * Exposes key site data as MCP resources so clients can read context without
	 * burning a tool-call slot.
	 *
	 * @param mixed $id Request ID.
	 * @return array JSON-RPC response.
	 */
	private function handle_resources_list( $id ) {
		$site_url = get_site_url();

		$resources = array(
			array(
				'uri'         => $site_url . '/wp-json/mcpwp/v1/site-info',
				'name'        => 'Site Info',
				'description' => 'WordPress site title, URL, active plugins, Elementor mode, and MCP capabilities. Read this before any content work.',
				'mimeType'    => 'application/json',
			),
			array(
				'uri'         => $site_url . '/wp-json/mcpwp/v1/site-context',
				'name'        => 'Site Context',
				'description' => 'Brand context and style guide set by the site owner. Read before writing or designing content.',
				'mimeType'    => 'text/plain',
			),
			array(
				'uri'         => $site_url . '/wp-json/mcpwp/v1/pages?per_page=50',
				'name'        => 'Pages',
				'description' => 'All published pages with IDs, slugs, and titles. Use to find page IDs before editing.',
				'mimeType'    => 'application/json',
			),
			array(
				'uri'         => $site_url . '/wp-json/mcpwp/v1/posts?per_page=50',
				'name'        => 'Recent Posts',
				'description' => 'Most recent 50 posts with IDs, slugs, titles, and categories.',
				'mimeType'    => 'application/json',
			),
			array(
				'uri'         => $site_url . '/wp-json/mcpwp/v1/design-references',
				'name'        => 'Design References',
				'description' => 'Stored design references (screenshots, approved designs) that should guide visual page creation.',
				'mimeType'    => 'application/json',
			),
			array(
				'uri'         => $site_url . '/wp-json/mcpwp/v1/elementor/status',
				'name'        => 'Elementor Status',
				'description' => 'Elementor version and layout mode (section or container/flexbox). Always read before writing Elementor data.',
				'mimeType'    => 'application/json',
			),
		);

		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => array(
				'resources' => $resources,
			),
		);
	}

	/**
	 * Handle resources/read method.
	 *
	 * Proxies to the internal REST API and returns the response as a text blob.
	 *
	 * @param mixed $id     Request ID.
	 * @param array $params Request parameters.
	 * @return array JSON-RPC response.
	 */
	private function handle_resources_read( $id, $params ) {
		$uri = isset( $params['uri'] ) ? (string) $params['uri'] : '';

		if ( '' === $uri ) {
			return $this->jsonrpc_error( $id, -32602, 'Missing resource URI' );
		}

		$site_url = get_site_url();
		$rest_base = $site_url . '/wp-json/mcpwp/v1/';

		// Only proxy internal mcpwp/v1 REST endpoints.
		if ( 0 !== strpos( $uri, $rest_base ) ) {
			return $this->jsonrpc_error(
				$id,
				-32002,
				'Resource not found',
				array(
					'uri'  => $uri,
					'hint' => 'Only mcpwp/v1 resources are readable. Call resources/list to see available URIs.',
				)
			);
		}

		$internal_path = '/' . ltrim( str_replace( $site_url, '', $uri ), '/' );

		$sub_request = new WP_REST_Request( 'GET', $internal_path );

		// Carry the API key auth from the outer request.
		$current_request_headers = function_exists( 'apache_request_headers' ) ? apache_request_headers() : array();
		$api_key = '';
		foreach ( $current_request_headers as $header => $value ) {
			if ( 'x-api-key' === strtolower( $header ) ) {
				$api_key = $value;
				break;
			}
		}
		if ( $api_key ) {
			$sub_request->add_header( 'X-API-Key', $api_key );
		}

		// Parse query string from URI.
		$query_string = parse_url( $uri, PHP_URL_QUERY );
		if ( $query_string ) {
			parse_str( $query_string, $query_params );
			foreach ( $query_params as $key => $value ) {
				$sub_request->set_param( sanitize_key( $key ), sanitize_text_field( $value ) );
			}
			$sub_request->set_route( strtok( $internal_path, '?' ) );
		}

		$response = rest_do_request( $sub_request );
		$data     = rest_get_server()->response_to_data( $response, false );

		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => array(
				'contents' => array(
					array(
						'uri'      => $uri,
						'mimeType' => 'application/json',
						'text'     => wp_json_encode( $data ),
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

		$site_title = get_bloginfo( 'name' );
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
					. "1. Call wp_onboard to get a complete site briefing (content inventory, integrations, suggested tools).\n"
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
				return $this->jsonrpc_error(
					$id,
					-32004,
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

		// Check if the tool's category has been disabled by the site administrator.
		$disabled_categories = get_option( 'mcpwp_disabled_tool_categories', array() );
		if ( ! empty( $disabled_categories ) && is_array( $disabled_categories ) ) {
			$tool_category = $this->tool_category_for( $tool_name );
			if ( '' !== $tool_category && in_array( $tool_category, $disabled_categories, true ) ) {
				$this->fire_tool_called( $tool_name, $start, 'category_disabled' );
				return $this->jsonrpc_error(
					$id,
					-32003,
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
					return $this->jsonrpc_error(
						$id,
						-32003,
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
			return $this->jsonrpc_error(
				$id,
				-32003,
				sprintf( 'Tool "%s" requires %s to be installed and active on this WordPress site.', $tool_name, $human ),
				array(
					'hint' => sprintf(
						'The required plugin (%s) is not active. Use wp_detect_plugins to check what\'s installed. The site admin needs to install and activate it from WP Admin > Plugins.',
						$human
					),
				)
			);
		}

		// Validate arguments against tool schema.
		$validation_error = $this->validate_tool_arguments( $tool_name, $arguments );
		if ( $validation_error ) {
			$this->fire_tool_called( $tool_name, $start, 'execution_error' );
			return $this->jsonrpc_error( $id, -32602, $validation_error );
		}

		$mapping     = $tool_map[ $tool_name ];

		// Once an earlier operation of this request was refused by the spend
		// budget, every later one is refused too (#752 F9) — here, before it is
		// charged rate-limit slots for work that will not run.
		$spend_state = Mcpwp_Spend_Budget::refuse_if_exhausted();
		if ( is_wp_error( $spend_state ) ) {
			$this->fire_tool_called( $tool_name, $start, Mcpwp_Spend_Budget::ERROR_CODE );
			return $this->spend_refusal( $id, $spend_state->get_error_message(), $spend_state->get_error_data() );
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
				return $this->jsonrpc_error(
					$id,
					-32029,
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
		$route       = $is_callback ? '' : $mapping['route'];
		$method      = $mapping['method'];

		// Remap tool param names to REST endpoint param names where they differ.
		$param_remap = isset( $mapping['param_remap'] ) ? $mapping['param_remap'] : array();
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
		if ( ! $is_callback ) {
			foreach ( $arguments as $key => $value ) {
				$placeholder = '{' . $key . '}';
				if ( false !== strpos( $route, $placeholder ) ) {
					$value_str = (string) $value;

					if ( false !== strpos( $value_str, '/' ) ) {
						$this->fire_tool_called( $tool_name, $start, 'invalid_path_parameter' );
						return $this->jsonrpc_error(
							$id,
							-32602,
							sprintf( 'Path parameter "%s" may not contain "/".', $key ),
							array(
								'hint' => 'A path placeholder fills one route segment. Pass the bare value (for example an ID or slug) without any path separators.',
							)
						);
					}

					$route = str_replace( $placeholder, $value_str, $route );
					unset( $arguments[ $key ] );
				}
			}
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
				return $this->jsonrpc_error(
					$id,
					-32003,
					sprintf( 'Tool "%s" requires at least write scope.', $tool_name ),
					array( 'hint' => 'Custom callback tools mutate state and require a write- or admin-scoped API key.' )
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
				return $this->spend_refusal( $id, $spend->get_error_message(), $spend->get_error_data() );
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

			// Copy authentication from current request.
			$api_key = $this->get_api_key_from_request( $request );
			if ( $api_key ) {
				$internal_request->set_header( 'X-API-Key', $api_key );
			}

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
			if ( Mcpwp_Spend_Budget::ERROR_CODE === $error_code ) {
				if ( class_exists( 'Mcpwp_Action_Log' ) ) {
					Mcpwp_Action_Log::complete( $audit_log_id, (int) round( ( microtime( true ) - $start ) * 1000 ), false, Mcpwp_Spend_Budget::ERROR_CODE );
				}
				$this->fire_tool_called( $tool_name, $start, Mcpwp_Spend_Budget::ERROR_CODE );
				return $this->spend_refusal( $id, $error_message, isset( $data['data'] ) ? $data['data'] : array() );
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
			return $this->jsonrpc_error(
				$id,
				-32000,
				$error_message . ' (route: ' . $method . ' ' . $full_route . ')',
				$error_data
			);
		}

		// Return successful result.
		if ( class_exists( 'Mcpwp_Action_Log' ) ) {
			Mcpwp_Action_Log::complete( $audit_log_id, (int) round( ( microtime( true ) - $start ) * 1000 ), true, '' );
		}
		$this->fire_tool_called( $tool_name, $start, '' );
		$encoded = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
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
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => array(
				'content' => array(
					array(
						'type' => 'text',
						'text' => $encoded,
					),
				),
				'isError' => false,
			),
		);
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
	 * @return array
	 */
	private function spend_refusal( $id, $message, $data ) {
		$data  = is_array( $data ) ? $data : array();
		$error = array(
			'code'   => Mcpwp_Spend_Budget::ERROR_CODE,
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
	 * Validate tool arguments against the tool's inputSchema.
	 *
	 * Checks for missing required parameters and unknown parameters.
	 * Does NOT type-check values (MCP clients often send strings for all types).
	 *
	 * @param string $tool_name The tool name.
	 * @param array  $arguments The arguments to validate.
	 * @return string|null Error message, or null if valid.
	 */
	private function validate_tool_arguments( $tool_name, $arguments ) {
		// Ensure tools cache is populated.
		if ( $this->tools_cache === null ) {
			$this->tools_cache = $this->get_all_tools();
		}

		// Find the tool schema.
		$schema = null;
		foreach ( $this->tools_cache as $tool ) {
			if ( $tool['name'] === $tool_name ) {
				$schema = isset( $tool['inputSchema'] ) ? $tool['inputSchema'] : null;
				break;
			}
		}

		if ( ! $schema ) {
			return null; // No schema to validate against.
		}

		// Get properties as an array (may be stdClass if empty).
		$properties = isset( $schema['properties'] ) ? (array) $schema['properties'] : array();
		$required   = isset( $schema['required'] ) ? $schema['required'] : array();

		// Check required parameters.
		foreach ( $required as $param ) {
			if ( ! array_key_exists( $param, $arguments ) ) {
				return 'Missing required parameter: ' . $param;
			}
		}

		// Check for unknown parameters.
		if ( ! empty( $properties ) ) {
			$known_params = array_keys( $properties );
			foreach ( array_keys( $arguments ) as $param ) {
				if ( ! in_array( $param, $known_params, true ) ) {
					// Fuzzy-match for "did you mean?" suggestion.
					$best_match = null;
					$best_score = 0;
					foreach ( $known_params as $known ) {
						$score = 0;
						similar_text( $param, $known, $score );
						if ( $score > $best_score ) {
							$best_score = $score;
							$best_match = $known;
						}
					}

					$msg = 'Unknown parameter: ' . $param;
					if ( $best_match && $best_score >= 50 ) {
						$msg .= '. Did you mean: ' . $best_match . '?';
					}
					return $msg;
				}
			}
		}

		return null;
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
		$site_name    = function_exists( 'get_bloginfo' ) ? get_bloginfo( 'name' ) : 'this site';
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
		$lines[] = '1. **Always call `wp_onboard` first** — it returns a complete site briefing with content inventory, active integrations, available tools, and recommended first actions.';
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

			if ( 'container' === $layout_mode ) {
				$lines[] = '- **Hierarchy:** `container > container(s) > widget(s)`. Containers can be nested.';
				$lines[] = '- Top-level elements use `"elType": "container"` with `"settings": {"content_width": "full"}` or boxed.';
			} else {
				$lines[] = '- **Hierarchy:** `section > column(s) > widget(s)`.';
				$lines[] = '- Columns need `"_column_size"` that sums to 100. Multi-column sections need a `"structure"` value (20=2col, 30=3col).';
			}

			$lines[] = '- Every element **must** have a unique `"id"` (8 alphanumeric chars). The plugin auto-generates missing IDs.';
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
		$response->header( 'Access-Control-Allow-Headers', 'Content-Type, X-API-Key, Mcp-Session-Id, Authorization' );
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
