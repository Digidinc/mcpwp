<?php
/**
 * Free-only MCP JSON-RPC controller.
 *
 * @package mumcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mcpwp_REST_MCP extends Mcpwp_REST_API {
	private $protocol_version = '2024-11-05';
	private $server_version;
	private $server_name;
	private $registry;

	public function __construct() {
		$this->server_version = defined( 'MCPWP_VERSION' ) ? MCPWP_VERSION : '1.0.0';
		$this->server_name    = 'mcpwp';
		$this->registry       = new Mcpwp_MCP_Free_Tools();
	}

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/mcp',
			array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'handle_mcp' ), 'permission_callback' => array( $this, 'check_permission' ) )
		);
	}

	public function handle_mcp( $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || empty( $body ) ) {
			return $this->mcp_response( $this->jsonrpc_error( null, -32700, 'Parse error: invalid JSON-RPC request.' ) );
		}
		if ( isset( $body[0] ) ) {
			if ( count( $body ) > 10 ) {
				return $this->mcp_response( $this->jsonrpc_error( null, -32600, 'Batch too large.' ) );
			}
			$output = array();
			foreach ( $body as $item ) {
				$response = $this->process_request( $item );
				if ( null !== $response ) {
					$output[] = $response;
				}
			}
			return $this->mcp_response( $output );
		}
		$response = $this->process_request( $body );
		return $this->mcp_response( $response, null === $response ? 204 : 200 );
	}

	private function process_request( $body ) {
		$method = (string) ( $body['method'] ?? '' );
		$id     = $body['id'] ?? null;
		$params = is_array( $body['params'] ?? null ) ? $body['params'] : array();
		if ( null === $id && 0 === strpos( $method, 'notifications/' ) ) {
			return null;
		}
		switch ( $method ) {
			case 'initialize':
				return $this->jsonrpc_result( $id, array_merge( $this->server_metadata(), array( 'instructions' => 'Use tools/list to discover the local WordPress tools. Create content as a draft unless publishing is explicitly requested.' ) ) );
			case 'tools/list':
				return $this->handle_tools_list( $id, $params );
			case 'tools/call':
				return $this->handle_tools_call( $id, $params );
			case 'resources/list':
				return $this->jsonrpc_result( $id, array( 'resources' => $this->resources() ) );
			case 'resources/read':
				return $this->handle_resource_read( $id, $params );
			case 'prompts/list':
				return $this->jsonrpc_result( $id, array( 'prompts' => array() ) );
			case 'ping':
				return $this->jsonrpc_result( $id, array( 'pong' => true ) );
			default:
				return $this->jsonrpc_error( $id, -32601, 'Method not found: ' . $method );
		}
	}

	private function server_metadata() {
		return array(
			'protocolVersion' => $this->protocol_version,
			'serverInfo'      => array( 'name' => $this->server_name, 'version' => $this->server_version ),
			'capabilities'    => array( 'tools' => (object) array(), 'resources' => array( 'subscribe' => false, 'listChanged' => false ), 'prompts' => array( 'listChanged' => false ) ),
		);
	}

	private function handle_tools_list( $id, $params ) {
		$tools = $this->registry->get_tools();
		$tools = $this->filter_disabled_tools( $tools );
		$tools = $this->filter_tools_for_key( $tools );
		$filter = sanitize_key( (string) ( $params['category'] ?? '' ) );
		if ( '' !== $filter ) {
			$tools = array_values( array_filter( $tools, static function ( $tool ) use ( $filter ) { return ( $tool['annotations']['category'] ?? 'site' ) === $filter; } ) );
		}
		return $this->jsonrpc_result( $id, array( 'tools' => $tools ) );
	}

	private function handle_tools_call( $id, $params ) {
		$name      = sanitize_key( (string) ( $params['name'] ?? '' ) );
		$arguments = is_array( $params['arguments'] ?? null ) ? $params['arguments'] : array();
		$map       = $this->registry->get_tool_map();
		if ( ! isset( $map[ $name ] ) ) {
			return $this->jsonrpc_error( $id, -32602, 'Unknown tool: ' . $name );
		}
		$category = $this->registry->get_tool_category( $name );
		$disabled = get_option( 'mcpwp_disabled_tool_categories', array() );
		if ( is_array( $disabled ) && in_array( $category, $disabled, true ) ) {
			return $this->jsonrpc_error( $id, -32003, 'Tool category is disabled by the site administrator.' );
		}
		$record = $this->get_current_api_key_record();
		if ( is_array( $record ) && ! $this->key_allows_category( $record, $category ) ) {
			return $this->jsonrpc_error( $id, -32003, 'Tool category is not allowed for this API key.' );
		}

		$route = $map[ $name ]['route'];
		foreach ( $arguments as $key => $value ) {
			$placeholder = '{' . $key . '}';
			if ( false !== strpos( $route, $placeholder ) ) {
				$route = str_replace( $placeholder, rawurlencode( (string) $value ), $route );
				unset( $arguments[ $key ] );
			}
		}
		$request = new WP_REST_Request( $map[ $name ]['method'], '/' . $this->namespace . $route );
		foreach ( $arguments as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$response = rest_do_request( $request );
		if ( is_wp_error( $response ) ) {
			return $this->jsonrpc_error( $id, -32000, $response->get_error_message() );
		}
		$data = method_exists( $response, 'get_data' ) ? $response->get_data() : $response;
		return $this->jsonrpc_result( $id, array( 'content' => array( array( 'type' => 'text', 'text' => wp_json_encode( $data ) ) ), 'structuredContent' => $data ) );
	}

	private function filter_disabled_tools( $tools ) {
		$disabled = get_option( 'mcpwp_disabled_tool_categories', array() );
		if ( ! is_array( $disabled ) || empty( $disabled ) ) {
			return $tools;
		}
		return array_values( array_filter( $tools, function ( $tool ) use ( $disabled ) { return ! in_array( $tool['annotations']['category'] ?? 'site', $disabled, true ); } ) );
	}

	private function filter_tools_for_key( $tools ) {
		$record = $this->get_current_api_key_record();
		if ( ! is_array( $record ) ) {
			return $tools;
		}
		return array_values( array_filter( $tools, function ( $tool ) use ( $record ) { return $this->key_allows_category( $record, $tool['annotations']['category'] ?? 'site' ); } ) );
	}

	private function resources() {
		return array(
			array( 'uri' => 'mcpwp://site-info', 'name' => 'Site Info', 'description' => 'Local WordPress site information.', 'mimeType' => 'application/json' ),
			array( 'uri' => 'mcpwp://posts', 'name' => 'Posts', 'description' => 'Local WordPress posts.', 'mimeType' => 'application/json' ),
		);
	}

	private function handle_resource_read( $id, $params ) {
		$routes = array( 'mcpwp://site-info' => '/site-info', 'mcpwp://posts' => '/posts' );
		$uri    = (string) ( $params['uri'] ?? '' );
		if ( ! isset( $routes[ $uri ] ) ) {
			return $this->jsonrpc_error( $id, -32602, 'Unknown resource: ' . $uri );
		}
		$response = rest_do_request( new WP_REST_Request( 'GET', '/' . $this->namespace . $routes[ $uri ] ) );
		$data     = method_exists( $response, 'get_data' ) ? $response->get_data() : $response;
		return $this->jsonrpc_result( $id, array( 'contents' => array( array( 'uri' => $uri, 'mimeType' => 'application/json', 'text' => wp_json_encode( $data ) ) ) ) );
	}

	public function get_introspection_data() {
		$tools = $this->registry->get_tools();
		$map   = $this->registry->get_tool_map();
		foreach ( $tools as &$tool ) {
			$tool['x_spai']  = $map[ $tool['name'] ];
			$tool['x_mcpwp'] = $map[ $tool['name'] ];
		}
		unset( $tool );
		return array( 'plugin' => array( 'name' => 'mumcp', 'version' => $this->server_version ), 'auth' => array( 'header' => 'X-API-Key' ), 'tools' => $tools );
	}

	private function jsonrpc_result( $id, $result ) {
		return array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result );
	}

	private function jsonrpc_error( $id, $code, $message, $data = array() ) {
		$error = array( 'code' => $code, 'message' => $message );
		if ( ! empty( $data ) ) {
			$error['data'] = $data;
		}
		return array( 'jsonrpc' => '2.0', 'id' => $id, 'error' => $error );
	}

	private function mcp_response( $data, $status = 200 ) {
		$response = new WP_REST_Response( $data, $status );
		$response->header( 'Content-Type', 'application/json; charset=UTF-8' );
		return $response;
	}
}
