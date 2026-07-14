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
	private $api_key_header = '';

	public function __construct() {
		$this->server_version = defined( 'MCPWP_VERSION' ) ? MCPWP_VERSION : '1.0.0';
		$this->server_name    = 'mcpwp';
		$this->registry       = new Mcpwp_MCP_Free_Tools();
	}

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/mcp',
			$this->route_definition( WP_REST_Server::CREATABLE, 'handle_mcp', 'check_mcp_permission', 'site', 'read', array( 'read', 'nested route decides' ), true )
		);
	}

	public function handle_mcp( $request ) {
		$this->api_key_header = (string) $request->get_header( 'X-API-Key' );
		if ( method_exists( $request, 'get_body' ) && strlen( (string) $request->get_body() ) > 15 * 1024 * 1024 ) { return $this->mcp_response( $this->jsonrpc_error( null, -32600, 'Request body is too large.', array( 'status' => 413 ) ), 413 ); }
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return $this->mcp_response( $this->jsonrpc_error( null, -32700, 'Parse error: invalid JSON-RPC request.' ) );
		}
		if ( empty( $body ) ) { return $this->mcp_response( $this->jsonrpc_error( null, -32600, 'Invalid Request: empty batch or object.' ) ); }
		if ( $this->is_list_array( $body ) ) {
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
			return empty( $output ) ? $this->mcp_response( null, 204 ) : $this->mcp_response( $output );
		}
		$response = $this->process_request( $body );
		return $this->mcp_response( $response, null === $response ? 204 : 200 );
	}

	private function process_request( $body ) {
		if ( ! is_array( $body ) || $this->is_list_array( $body ) ) {
			$rate = $this->charge_item( 'GET' );
			return is_wp_error( $rate ) ? $this->rate_limit_error( null, $rate ) : $this->jsonrpc_error( null, -32600, 'Invalid Request.' );
		}
		$has_id = array_key_exists( 'id', $body );
		$id = $has_id && ( is_int( $body['id'] ) || is_string( $body['id'] ) || null === $body['id'] ) ? $body['id'] : null;
		if ( array_diff( array_keys( $body ), array( 'jsonrpc', 'id', 'method', 'params' ) ) || ! isset( $body['jsonrpc'] ) || '2.0' !== $body['jsonrpc'] || ! isset( $body['method'] ) || ! is_string( $body['method'] ) || '' === $body['method'] || ( $has_id && null === $id && null !== $body['id'] ) ) {
			$rate = $this->charge_item( 'GET' );
			return is_wp_error( $rate ) ? $this->rate_limit_error( $id, $rate ) : $this->jsonrpc_error( $id, -32600, 'Invalid Request.' );
		}
		if ( isset( $body['params'] ) && ( ! is_array( $body['params'] ) || ( ! empty( $body['params'] ) && $this->is_list_array( $body['params'] ) ) ) ) {
			$rate = $this->charge_item( 'GET' );
			return is_wp_error( $rate ) ? $this->rate_limit_error( $id, $rate ) : $this->jsonrpc_error( $id, -32600, 'Invalid Request params.' );
		}
		$notification = ! $has_id;
		$method = $body['method'];
		$params = isset( $body['params'] ) ? $body['params'] : array();
		if ( 'tools/call' !== $method ) {
			$rate = $this->charge_item( 'GET' );
			if ( is_wp_error( $rate ) ) { return $notification ? null : $this->rate_limit_error( $id, $rate ); }
		}
		$response = null;
		switch ( $method ) {
			case 'initialize':
				if ( array_diff( array_keys( $params ), array( 'protocolVersion', 'capabilities', 'clientInfo' ) ) || ( isset( $params['protocolVersion'] ) && $this->protocol_version !== $params['protocolVersion'] ) ) { $response = $this->jsonrpc_error( $id, -32602, 'Unsupported initialize parameters.' ); break; }
				$response = $this->jsonrpc_result( $id, array_merge( $this->server_metadata(), array( 'instructions' => 'Use tools/list to discover the local WordPress tools. Create content as a draft unless publishing is explicitly requested.' ) ) ); break;
			case 'tools/list':
				if ( ! empty( $params ) ) { $response = $this->jsonrpc_error( $id, -32602, 'Unsupported tools/list parameters.' ); break; }
				$response = $this->handle_tools_list( $id ); break;
			case 'tools/call':
				if ( array_diff( array_keys( $params ), array( 'name', 'arguments' ) ) || ! isset( $params['name'] ) || ! is_string( $params['name'] ) || ( isset( $params['arguments'] ) && ( ! is_array( $params['arguments'] ) || ( ! empty( $params['arguments'] ) && $this->is_list_array( $params['arguments'] ) ) ) ) ) {
					$rate = $this->charge_item( 'GET' );
					$response = is_wp_error( $rate ) ? $this->rate_limit_error( $id, $rate ) : $this->jsonrpc_error( $id, -32602, 'Invalid tools/call parameters.' ); break;
				}
				$response = $this->handle_tools_call( $id, $params ); break;
			case 'ping':
				$response = empty( $params ) ? $this->jsonrpc_result( $id, array( 'pong' => true ) ) : $this->jsonrpc_error( $id, -32602, 'Ping accepts no parameters.' ); break;
			default:
				$response = $this->jsonrpc_error( $id, -32601, 'Method not found: ' . $method );
		}
		return $notification ? null : $response;
	}

	private function is_list_array( $value ) { return is_array( $value ) && ! empty( $value ) && array_keys( $value ) === range( 0, count( $value ) - 1 ); }

	private function server_metadata() {
		return array(
			'protocolVersion' => $this->protocol_version,
			'serverInfo'      => array( 'name' => $this->server_name, 'version' => $this->server_version ),
			'capabilities'    => array( 'tools' => (object) array() ),
		);
	}

	private function handle_tools_list( $id ) {
		$tools = $this->registry->get_tools();
		$tools = $this->filter_disabled_tools( $tools );
		$tools = $this->filter_tools_for_key( $tools );
		return $this->jsonrpc_result( $id, array( 'tools' => $tools ) );
	}

	private function handle_tools_call( $id, $params ) {
		$name      = $params['name'];
		$arguments = is_array( $params['arguments'] ?? null ) ? $params['arguments'] : array();
		$map       = $this->registry->get_tool_map();
		if ( ! isset( $map[ $name ] ) ) {
			$rate = $this->charge_item( 'GET' );
			return is_wp_error( $rate ) ? $this->rate_limit_error( $id, $rate ) : $this->jsonrpc_error( $id, -32602, 'Unknown tool: ' . $name );
		}
		$argument_error = $this->validate_tool_arguments( $name, $arguments );
		if ( null !== $argument_error ) {
			$rate = $this->charge_item( 'GET' );
			return is_wp_error( $rate ) ? $this->rate_limit_error( $id, $rate ) : $this->jsonrpc_error( $id, -32602, $argument_error );
		}
		$category = $this->registry->get_tool_category( $name );
		$disabled = get_option( 'mcpwp_disabled_tool_categories', array() );
		if ( is_array( $disabled ) && in_array( $category, $disabled, true ) ) {
			$rate = $this->charge_item( $map[ $name ]['method'] );
			if ( is_wp_error( $rate ) ) { return $this->rate_limit_error( $id, $rate ); }
			$this->log_tool_call( $name, 403, microtime( true ) );
			return $this->jsonrpc_error( $id, -32003, 'Tool category is disabled by the site administrator.' );
		}
		$record = $this->get_current_api_key_record();
		if ( is_array( $record ) && ! $this->key_allows_category( $record, $category ) ) {
			$rate = $this->charge_item( $map[ $name ]['method'] );
			if ( is_wp_error( $rate ) ) { return $this->rate_limit_error( $id, $rate ); }
			$this->log_tool_call( $name, 403, microtime( true ) );
			return $this->jsonrpc_error( $id, -32003, 'Tool category is not allowed for this API key.' );
		}
		$started_at = microtime( true );

		$route = $map[ $name ]['route'];
		foreach ( $arguments as $key => $value ) {
			$placeholder = '{' . $key . '}';
			if ( false !== strpos( $route, $placeholder ) ) {
				$route = str_replace( $placeholder, rawurlencode( (string) $value ), $route );
				unset( $arguments[ $key ] );
			}
		}
		$request = new WP_REST_Request( $map[ $name ]['method'], '/' . $this->namespace . $route );
		$request->set_header( 'X-API-Key', $this->api_key_header );
		if ( in_array( $map[ $name ]['method'], array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $arguments ) );
		} else {
			foreach ( $arguments as $key => $value ) { $request->set_param( $key, $value ); }
		}
		$response = rest_do_request( $request );
		if ( is_wp_error( $response ) ) {
			$status = (int) ( $response->get_error_data()['status'] ?? 500 );
			$this->log_tool_call( $name, $status, $started_at );
			return $this->jsonrpc_error( $id, $this->jsonrpc_code_for_status( $status ), $response->get_error_message(), array( 'status' => $status, 'code' => $response->get_error_code() ) );
		}
		$data = method_exists( $response, 'get_data' ) ? $response->get_data() : $response;
		$status = method_exists( $response, 'get_status' ) ? (int) $response->get_status() : 200;
		if ( $status >= 400 ) {
			$this->log_tool_call( $name, $status, $started_at );
			$message = is_array( $data ) && isset( $data['message'] ) ? (string) $data['message'] : 'WordPress REST request failed.';
			$code    = is_array( $data ) && isset( $data['code'] ) ? (string) $data['code'] : 'rest_error';
			return $this->jsonrpc_error( $id, $this->jsonrpc_code_for_status( $status ), $message, array( 'status' => $status, 'code' => $code ) );
		}
		$this->log_tool_call( $name, $status, $started_at );
		return $this->jsonrpc_result( $id, array( 'content' => array( array( 'type' => 'text', 'text' => wp_json_encode( $data ) ) ), 'structuredContent' => $data ) );
	}

	private function charge_item( $method ) {
		$record = $this->get_current_api_key_record();
		$identifier = 'key:' . sanitize_key( is_array( $record ) ? (string) ( $record['id'] ?? '' ) : '' );
		return $this->check_rate_limit( $identifier, strtoupper( (string) $method ), $record );
	}

	private function rate_limit_error( $id, $rate ) {
		return $this->jsonrpc_error( $id, -32029, $rate->get_error_message(), array( 'status' => 429, 'code' => $rate->get_error_code() ) );
	}

	private function jsonrpc_code_for_status( $status ) {
		$codes = array( 403 => -32003, 404 => -32004, 413 => -32013, 415 => -32015, 429 => -32029 );
		return isset( $codes[ $status ] ) ? $codes[ $status ] : -32000;
	}

	private function validate_tool_arguments( $name, $arguments ) {
		foreach ( $this->registry->get_tools() as $tool ) {
			if ( $name !== $tool['name'] ) { continue; }
			return $this->validate_schema_value( $arguments, $tool['inputSchema'], 1 ) ? 'Invalid tool arguments.' : null;
		}
		return 'Unknown tool.';
	}

	private function validate_schema_value( $value, $schema, $depth ) {
		if ( $depth > 8 || ! is_array( $schema ) ) { return true; }
		$type = isset( $schema['type'] ) ? $schema['type'] : null;
		if ( 'object' === $type ) {
			if ( ! is_array( $value ) || ( ! empty( $value ) && $this->is_list_array( $value ) ) || count( $value ) > 32 ) { return true; }
			$properties = isset( $schema['properties'] ) ? (array) $schema['properties'] : array();
			$required = isset( $schema['required'] ) && is_array( $schema['required'] ) ? $schema['required'] : array();
			foreach ( $required as $key ) { if ( ! array_key_exists( $key, $value ) ) { return true; } }
			if ( isset( $schema['minProperties'] ) && count( $value ) < (int) $schema['minProperties'] ) { return true; }
			if ( isset( $schema['additionalProperties'] ) && false === $schema['additionalProperties'] && array_diff( array_keys( $value ), array_keys( $properties ) ) ) { return true; }
			foreach ( $value as $key => $item ) { if ( ! isset( $properties[ $key ] ) || $this->validate_schema_value( $item, $properties[ $key ], $depth + 1 ) ) { return true; } }
		} elseif ( 'array' === $type ) {
			if ( ! is_array( $value ) || ( ! empty( $value ) && ! $this->is_list_array( $value ) ) ) { return true; }
			if ( isset( $schema['minItems'] ) && count( $value ) < (int) $schema['minItems'] ) { return true; }
			if ( isset( $schema['maxItems'] ) && count( $value ) > (int) $schema['maxItems'] ) { return true; }
			if ( ! empty( $schema['uniqueItems'] ) && count( array_unique( array_map( 'serialize', $value ) ) ) !== count( $value ) ) { return true; }
			if ( isset( $schema['items'] ) ) { foreach ( $value as $item ) { if ( $this->validate_schema_value( $item, $schema['items'], $depth + 1 ) ) { return true; } } }
		} elseif ( 'string' === $type ) {
			if ( ! is_string( $value ) || 1 !== preg_match( '//u', $value ) ) { return true; }
			$length = strlen( $value );
			if ( isset( $schema['minLength'] ) && $length < (int) $schema['minLength'] ) { return true; }
			if ( isset( $schema['maxLength'] ) && $length > (int) $schema['maxLength'] ) { return true; }
		} elseif ( 'integer' === $type ) {
			if ( ! is_int( $value ) || ( isset( $schema['minimum'] ) && $value < $schema['minimum'] ) || ( isset( $schema['maximum'] ) && $value > $schema['maximum'] ) ) { return true; }
		} elseif ( 'boolean' === $type && ! is_bool( $value ) ) {
			return true;
		}
		return isset( $schema['enum'] ) && ! in_array( $value, $schema['enum'], true );
	}

	private function log_tool_call( $name, $status_code, $started_at ) {
		$record = $this->get_current_api_key_record();
		$key_id = is_array( $record ) ? (string) ( $record['id'] ?? '' ) : '';
		$duration_ms = max( 0, (int) round( ( microtime( true ) - $started_at ) * 1000 ) );
		$this->log_activity( $name, $status_code, $duration_ms, $key_id );
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
