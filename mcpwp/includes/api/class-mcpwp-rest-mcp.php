<?php
/**
 * Free-only MCP JSON-RPC controller.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serve the JSON-RPC MCP endpoint and dispatch retained tools.
 */
class Mcpwp_REST_MCP extends Mcpwp_REST_API {
	/**
	 * Supported MCP protocol version.
	 *
	 * @var string
	 */
	private $protocol_version = '2024-11-05';

	/**
	 * Plugin version advertised to MCP clients.
	 *
	 * @var string
	 */
	private $server_version;

	/**
	 * Stable MCP server name.
	 *
	 * @var string
	 */
	private $server_name;

	/**
	 * Retained MCP tool registry.
	 *
	 * @var Mcpwp_MCP_Free_Tools
	 */
	private $registry;

	/**
	 * API-key header retained for request logging.
	 *
	 * @var string
	 */
	private $api_key_header = '';

	/**
	 * Initialize retained MCP server metadata and the free tool registry.
	 */
	public function __construct() {
		$this->server_version = defined( 'MCPWP_VERSION' ) ? MCPWP_VERSION : '1.0.0';
		$this->server_name    = 'mcpwp';
		$this->registry       = new Mcpwp_MCP_Free_Tools();
	}

	/**
	 * Register the retained MCP JSON-RPC endpoint.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/mcp',
			array_merge(
				$this->route_definition( WP_REST_Server::CREATABLE, 'handle_mcp', 'check_mcp_permission', 'site', 'read', array( 'read', 'nested route decides' ), true ),
				array( 'permission_callback' => array( $this, 'check_mcp_permission' ) )
			)
		);
	}

	/**
	 * Parse and dispatch a single or batched MCP JSON-RPC request.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response MCP response.
	 */
	public function handle_mcp( $request ) {
		$this->api_key_header = (string) $request->get_header( 'X-API-Key' );
		$raw                  = method_exists( $request, 'get_body' ) ? (string) $request->get_body() : '';
		if ( strlen( $raw ) > 15 * 1024 * 1024 ) {
			return $this->mcp_response( $this->charged_top_level_error( $this->jsonrpc_error( null, -32013, 'Request body is too large.', array( 'status' => 413 ) ) ) );
		}
		$body           = json_decode( $raw );
		$upload_request = JSON_ERROR_NONE === json_last_error() && $this->contains_upload_tool( $body );
		if ( strlen( $raw ) > 1024 * 1024 && ! $upload_request ) {
			return $this->mcp_response( $this->charged_top_level_error( $this->jsonrpc_error( null, -32013, 'Request body is too large.', array( 'status' => 413 ) ) ) );
		}
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return $this->mcp_response( $this->charged_top_level_error( $this->jsonrpc_error( null, -32700, 'Parse error: invalid JSON-RPC request.' ) ) );
		}
		if ( is_array( $body ) ) {
			if ( empty( $body ) ) {
				return $this->mcp_response( $this->charged_top_level_error( $this->jsonrpc_error( null, -32600, 'Invalid Request: empty batch.' ) ) ); }
			if ( count( $body ) > 10 ) {
				return $this->mcp_response( $this->charged_top_level_error( $this->jsonrpc_error( null, -32600, 'Batch too large.' ) ) );
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
		if ( ! is_object( $body ) ) {
			return $this->mcp_response( $this->charged_top_level_error( $this->jsonrpc_error( null, -32600, 'Invalid Request.' ) ) ); }
		$response = $this->process_request( $body );
		return $this->mcp_response( $response, null === $response ? 204 : 200 );
	}

	/**
	 * Validate and dispatch one decoded JSON-RPC request object.
	 *
	 * @param mixed $body Decoded JSON value.
	 * @return array<string,mixed>|null JSON-RPC response, or null for a notification.
	 */
	private function process_request( $body ) {
		if ( ! is_object( $body ) ) {
			$rate = $this->charge_item( 'GET' );
			return is_wp_error( $rate ) ? $this->rate_limit_error( null, $rate ) : $this->jsonrpc_error( null, -32600, 'Invalid Request.' );
		}
		$body   = get_object_vars( $body );
		$has_id = array_key_exists( 'id', $body );
		$id     = $has_id && ( is_int( $body['id'] ) || is_string( $body['id'] ) || null === $body['id'] ) ? $body['id'] : null;
		if ( array_diff( array_keys( $body ), array( 'jsonrpc', 'id', 'method', 'params' ) ) || ! isset( $body['jsonrpc'] ) || '2.0' !== $body['jsonrpc'] || ! isset( $body['method'] ) || ! is_string( $body['method'] ) || '' === $body['method'] || ( $has_id && null === $id && null !== $body['id'] ) ) {
			$rate = $this->charge_item( 'GET' );
			return is_wp_error( $rate ) ? $this->rate_limit_error( $id, $rate ) : $this->jsonrpc_error( $id, -32600, 'Invalid Request.' );
		}
		if ( isset( $body['params'] ) && ! is_object( $body['params'] ) ) {
			$rate = $this->charge_item( 'GET' );
			return is_wp_error( $rate ) ? $this->rate_limit_error( $id, $rate ) : $this->jsonrpc_error( $id, -32600, 'Invalid Request params.' );
		}
		$notification = ! $has_id;
		$method       = $body['method'];
		$params       = isset( $body['params'] ) ? get_object_vars( $body['params'] ) : array();
		if ( 'tools/call' !== $method ) {
			$rate = $this->charge_item( 'GET' );
			if ( is_wp_error( $rate ) ) {
				return $notification ? null : $this->rate_limit_error( $id, $rate ); }
		}
		$response = null;
		switch ( $method ) {
			case 'initialize':
				if ( ! $this->valid_initialize_params( $params ) ) {
					$response = $this->jsonrpc_error( $id, -32602, 'Unsupported initialize parameters.' );
					break; }
				$response = $this->jsonrpc_result( $id, array_merge( $this->server_metadata(), array( 'instructions' => 'Use tools/list to discover the local WordPress tools. Create content as a draft unless publishing is explicitly requested.' ) ) );
				break;
			case 'tools/list':
				if ( ! empty( $params ) ) {
					$response = $this->jsonrpc_error( $id, -32602, 'Unsupported tools/list parameters.' );
					break; }
				$response = $this->handle_tools_list( $id );
				break;
			case 'tools/call':
				if ( array_diff( array_keys( $params ), array( 'name', 'arguments' ) ) || ! isset( $params['name'] ) || ! is_string( $params['name'] ) || ( isset( $params['arguments'] ) && ! is_object( $params['arguments'] ) ) ) {
					$rate     = $this->charge_item( 'GET' );
					$response = is_wp_error( $rate ) ? $this->rate_limit_error( $id, $rate ) : $this->jsonrpc_error( $id, -32602, 'Invalid tools/call parameters.' );
					break;
				}
				$response = $this->handle_tools_call( $id, $params );
				break;
			case 'ping':
				$response = empty( $params ) ? $this->jsonrpc_result( $id, array( 'pong' => true ) ) : $this->jsonrpc_error( $id, -32602, 'Ping accepts no parameters.' );
				break;
			default:
				$response = $this->jsonrpc_error( $id, -32601, 'Method not found: ' . $method );
		}
		return $notification ? null : $response;
	}

	/**
	 * Determine whether an array uses contiguous zero-based integer keys.
	 *
	 * @param mixed $value Candidate value.
	 * @return bool Whether the value is a list array.
	 */
	private function is_list_array( $value ) {
		return is_array( $value ) && array_keys( $value ) === range( 0, count( $value ) - 1 ); }

	/**
	 * Determine whether a decoded request contains the base64 upload tool.
	 *
	 * @param mixed $body Decoded single or batch request.
	 * @return bool Whether an upload tool call is present.
	 */
	private function contains_upload_tool( $body ) {
		$items = is_array( $body ) ? $body : array( $body );
		foreach ( $items as $item ) {
			if ( ! is_object( $item ) ) {
				continue; }
			$values = get_object_vars( $item );
			$params = isset( $values['params'] ) && is_object( $values['params'] ) ? get_object_vars( $values['params'] ) : array();
			if ( 'tools/call' === ( $values['method'] ?? '' ) && 'wp_upload_media_b64' === ( $params['name'] ?? '' ) ) {
				return true; }
		}
		return false;
	}

	/**
	 * Charge and return a top-level JSON-RPC parse or shape error.
	 *
	 * @param array<string,mixed> $error JSON-RPC error envelope.
	 * @return array<string,mixed> Rate-limit error or original error envelope.
	 */
	private function charged_top_level_error( $error ) {
		$rate = $this->charge_item( 'GET' );
		return is_wp_error( $rate ) ? $this->rate_limit_error( null, $rate ) : $error;
	}

	/**
	 * Validate the bounded subset of MCP initialize parameters.
	 *
	 * @param array<string,mixed> $params Initialize parameters.
	 * @return bool Whether the parameters are valid.
	 */
	private function valid_initialize_params( $params ) {
		if ( array_diff( array_keys( $params ), array( 'protocolVersion', 'capabilities', 'clientInfo' ) ) ) {
			return false; }
		if ( isset( $params['protocolVersion'] ) && ( ! is_string( $params['protocolVersion'] ) || $this->protocol_version !== $params['protocolVersion'] ) ) {
			return false; }
		if ( isset( $params['clientInfo'] ) ) {
			if ( ! is_object( $params['clientInfo'] ) ) {
				return false; }
			$client = get_object_vars( $params['clientInfo'] );
			if ( 2 !== count( $client ) || array_diff( array_keys( $client ), array( 'name', 'version' ) ) || ! isset( $client['name'], $client['version'] ) || ! is_string( $client['name'] ) || ! is_string( $client['version'] ) || '' === $client['name'] || '' === $client['version'] || strlen( $client['name'] ) > 100 || strlen( $client['version'] ) > 100 ) {
				return false; }
		}
		if ( isset( $params['capabilities'] ) ) {
			if ( ! is_object( $params['capabilities'] ) || strlen( wp_json_encode( $params['capabilities'] ) ) > 4096 ) {
				return false; }
			$capabilities = get_object_vars( $params['capabilities'] );
			if ( array_diff( array_keys( $capabilities ), array( 'roots', 'sampling', 'experimental' ) ) ) {
				return false; }
			foreach ( $capabilities as $capability ) {
				if ( ! is_object( $capability ) || ! $this->bounded_capability_value( $capability, 1 ) ) {
					return false; }
			}
		}
		return true;
	}

	/**
	 * Validate a bounded client capability value recursively.
	 *
	 * @param mixed $value Capability value.
	 * @param int   $depth Current traversal depth.
	 * @return bool Whether the value is within retained depth and size limits.
	 */
	private function bounded_capability_value( $value, $depth ) {
		if ( $depth > 4 ) {
			return false; }
		if ( is_object( $value ) ) {
			$properties = get_object_vars( $value );
			if ( count( $properties ) > 16 ) {
				return false; }
			foreach ( $properties as $key => $item ) {
				if ( strlen( (string) $key ) > 64 || ! $this->bounded_capability_value( $item, $depth + 1 ) ) {
					return false; }
			}
			return true;
		}
		if ( is_array( $value ) ) {
			if ( count( $value ) > 16 ) {
				return false; }
			foreach ( $value as $item ) {
				if ( ! $this->bounded_capability_value( $item, $depth + 1 ) ) {
					return false; }
			}
			return true;
		}
		return is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value || ( is_string( $value ) && strlen( $value ) <= 1000 );
	}

	/**
	 * Return MCP protocol, server, and capability metadata.
	 *
	 * @return array<string,mixed> Server metadata.
	 */
	private function server_metadata() {
		return array(
			'protocolVersion' => $this->protocol_version,
			'serverInfo'      => array(
				'name'    => $this->server_name,
				'version' => $this->server_version,
			),
			'capabilities'    => array( 'tools' => (object) array() ),
		);
	}

	/**
	 * Build a tools/list result filtered by site and key policy.
	 *
	 * @param int|string|null $id JSON-RPC request ID.
	 * @return array<string,mixed> JSON-RPC result envelope.
	 */
	private function handle_tools_list( $id ) {
		$tools = $this->registry->get_tools();
		$tools = $this->filter_disabled_tools( $tools );
		$tools = $this->filter_tools_for_key( $tools );
		return $this->jsonrpc_result( $id, array( 'tools' => $tools ) );
	}

	/**
	 * Validate, authorize, dispatch, and wrap an MCP tool call.
	 *
	 * @param int|string|null     $id     JSON-RPC request ID.
	 * @param array<string,mixed> $params Validated tools/call parameters.
	 * @return array<string,mixed> JSON-RPC result or error envelope.
	 */
	private function handle_tools_call( $id, $params ) {
		$name      = $params['name'];
		$arguments = isset( $params['arguments'] ) && is_object( $params['arguments'] ) ? get_object_vars( $params['arguments'] ) : array();
		$map       = $this->registry->get_tool_map();
		if ( ! isset( $map[ $name ] ) ) {
			$rate = $this->charge_item( 'GET' );
			return is_wp_error( $rate ) ? $this->rate_limit_error( $id, $rate ) : $this->jsonrpc_error( $id, -32602, 'Unknown tool: ' . $name );
		}
		$argument_error = $this->validate_tool_arguments( $name, $arguments );
		if ( null !== $argument_error ) {
			$rate = $this->charge_item( $map[ $name ]['method'] );
			return is_wp_error( $rate ) ? $this->rate_limit_error( $id, $rate ) : $this->jsonrpc_error( $id, -32602, $argument_error );
		}
		$category = $this->registry->get_tool_category( $name );
		$disabled = get_option( 'mcpwp_disabled_tool_categories', array() );
		if ( is_array( $disabled ) && in_array( $category, $disabled, true ) ) {
			$rate = $this->charge_item( $map[ $name ]['method'] );
			if ( is_wp_error( $rate ) ) {
				return $this->rate_limit_error( $id, $rate ); }
			$this->log_tool_call( $name, 403, microtime( true ) );
			return $this->jsonrpc_error( $id, -32003, 'Tool category is disabled by the site administrator.', array( 'status' => 403 ) );
		}
		$record = $this->get_current_api_key_record();
		if ( is_array( $record ) && ! $this->key_allows_category( $record, $category ) ) {
			$rate = $this->charge_item( $map[ $name ]['method'] );
			if ( is_wp_error( $rate ) ) {
				return $this->rate_limit_error( $id, $rate ); }
			$this->log_tool_call( $name, 403, microtime( true ) );
			return $this->jsonrpc_error( $id, -32003, 'Tool category is not allowed for this API key.', array( 'status' => 403 ) );
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
			foreach ( $arguments as $key => $value ) {
				$request->set_param( $key, $value ); }
		}
		$response = rest_do_request( $request );
		if ( is_wp_error( $response ) ) {
			$status = (int) ( $response->get_error_data()['status'] ?? 500 );
			$this->log_tool_call( $name, $status, $started_at );
			return $this->jsonrpc_error(
				$id,
				$this->jsonrpc_code_for_status( $status ),
				$response->get_error_message(),
				array(
					'status' => $status,
					'code'   => $response->get_error_code(),
				)
			);
		}
		$data   = method_exists( $response, 'get_data' ) ? $response->get_data() : $response;
		$status = method_exists( $response, 'get_status' ) ? (int) $response->get_status() : 200;
		if ( $status >= 400 ) {
			$this->log_tool_call( $name, $status, $started_at );
			$message = is_array( $data ) && isset( $data['message'] ) ? (string) $data['message'] : 'WordPress REST request failed.';
			$code    = is_array( $data ) && isset( $data['code'] ) ? (string) $data['code'] : 'rest_error';
			return $this->jsonrpc_error(
				$id,
				$this->jsonrpc_code_for_status( $status ),
				$message,
				array(
					'status' => $status,
					'code'   => $code,
				)
			);
		}
		$this->log_tool_call( $name, $status, $started_at );
		return $this->jsonrpc_result(
			$id,
			array(
				'content'           => array(
					array(
						'type' => 'text',
						'text' => wp_json_encode( $data ),
					),
				),
				'structuredContent' => $data,
			)
		);
	}

	/**
	 * Charge the authenticated key for one MCP request item.
	 *
	 * @param string $method HTTP or logical method used for rate-limit policy.
	 * @return true|WP_Error True when allowed, otherwise a rate-limit error.
	 */
	private function charge_item( $method ) {
		$record     = $this->get_current_api_key_record();
		$identifier = 'key:' . sanitize_key( is_array( $record ) ? (string) ( $record['id'] ?? '' ) : '' );
		return $this->check_rate_limit( $identifier, strtoupper( (string) $method ) );
	}

	/**
	 * Convert a rate-limit error to a JSON-RPC error envelope.
	 *
	 * @param int|string|null $id   JSON-RPC request ID.
	 * @param WP_Error        $rate Rate-limit error.
	 * @return array<string,mixed> JSON-RPC rate-limit error envelope.
	 */
	private function rate_limit_error( $id, $rate ) {
		return $this->jsonrpc_error(
			$id,
			-32029,
			$rate->get_error_message(),
			array(
				'status' => 429,
				'code'   => $rate->get_error_code(),
			)
		);
	}

	/**
	 * Map an HTTP error status to the retained JSON-RPC server code.
	 *
	 * @param int $status HTTP status code.
	 * @return int JSON-RPC error code.
	 */
	private function jsonrpc_code_for_status( $status ) {
		$codes = array(
			403 => -32003,
			404 => -32004,
			413 => -32013,
			415 => -32015,
			429 => -32029,
		);
		return isset( $codes[ $status ] ) ? $codes[ $status ] : -32000;
	}

	/**
	 * Validate tool arguments against the registered input schema.
	 *
	 * @param string $name      Tool name.
	 * @param array  $arguments Tool arguments.
	 * @return string|null Validation message, or null when valid.
	 */
	private function validate_tool_arguments( $name, $arguments ) {
		foreach ( $this->registry->get_tools() as $tool ) {
			if ( $name !== $tool['name'] ) {
				continue; }
			return $this->validate_schema_value( $arguments, $tool['inputSchema'], 1 ) ? 'Invalid tool arguments.' : null;
		}
		return 'Unknown tool.';
	}

	/**
	 * Recursively test whether a value violates a retained tool schema.
	 *
	 * @param mixed $value  Candidate value.
	 * @param array $schema Tool schema.
	 * @param int   $depth  Current traversal depth.
	 * @return bool Whether the value violates the schema.
	 */
	private function validate_schema_value( $value, $schema, $depth ) {
		if ( $depth > 8 || ! is_array( $schema ) ) {
			return true; }
		$type = isset( $schema['type'] ) ? $schema['type'] : null;
		if ( 'object' === $type ) {
			if ( ! is_array( $value ) || ( ! empty( $value ) && $this->is_list_array( $value ) ) || count( $value ) > 32 ) {
				return true; }
			$properties = isset( $schema['properties'] ) ? (array) $schema['properties'] : array();
			$required   = isset( $schema['required'] ) && is_array( $schema['required'] ) ? $schema['required'] : array();
			foreach ( $required as $key ) {
				if ( ! array_key_exists( $key, $value ) ) {
					return true; }
			}
			if ( isset( $schema['minProperties'] ) && count( $value ) < (int) $schema['minProperties'] ) {
				return true; }
			if ( isset( $schema['additionalProperties'] ) && false === $schema['additionalProperties'] && array_diff( array_keys( $value ), array_keys( $properties ) ) ) {
				return true; }
			foreach ( $value as $key => $item ) {
				if ( ! isset( $properties[ $key ] ) || $this->validate_schema_value( $item, $properties[ $key ], $depth + 1 ) ) {
					return true; }
			}
		} elseif ( 'array' === $type ) {
			if ( ! is_array( $value ) || ( ! empty( $value ) && ! $this->is_list_array( $value ) ) ) {
				return true; }
			if ( isset( $schema['minItems'] ) && count( $value ) < (int) $schema['minItems'] ) {
				return true; }
			if ( isset( $schema['maxItems'] ) && count( $value ) > (int) $schema['maxItems'] ) {
				return true; }
			if ( ! empty( $schema['uniqueItems'] ) && count( array_unique( array_map( 'serialize', $value ) ) ) !== count( $value ) ) {
				return true; }
			if ( isset( $schema['items'] ) ) {
				foreach ( $value as $item ) {
					if ( $this->validate_schema_value( $item, $schema['items'], $depth + 1 ) ) {
						return true; }
				}
			}
		} elseif ( 'string' === $type ) {
			if ( ! is_string( $value ) || 1 !== preg_match( '//u', $value ) ) {
				return true; }
			$length = strlen( $value );
			if ( isset( $schema['minLength'] ) && $length < (int) $schema['minLength'] ) {
				return true; }
			if ( isset( $schema['maxLength'] ) && $length > (int) $schema['maxLength'] ) {
				return true; }
		} elseif ( 'integer' === $type ) {
			if ( ! is_int( $value ) || ( isset( $schema['minimum'] ) && $value < $schema['minimum'] ) || ( isset( $schema['maximum'] ) && $value > $schema['maximum'] ) ) {
				return true; }
		} elseif ( 'boolean' === $type && ! is_bool( $value ) ) {
			return true;
		}
		return isset( $schema['enum'] ) && ! in_array( $value, $schema['enum'], true );
	}

	/**
	 * Record the outcome and duration of a dispatched MCP tool call.
	 *
	 * @param string $name        Tool name.
	 * @param int    $status_code HTTP status code.
	 * @param float  $started_at  Start time from microtime.
	 * @return void
	 */
	private function log_tool_call( $name, $status_code, $started_at ) {
		$record      = $this->get_current_api_key_record();
		$key_id      = is_array( $record ) ? (string) ( $record['id'] ?? '' ) : '';
		$duration_ms = max( 0, (int) round( ( microtime( true ) - $started_at ) * 1000 ) );
		$this->log_activity( $name, $status_code, $duration_ms, $key_id );
	}

	/**
	 * Remove tools whose categories are disabled by site policy.
	 *
	 * @param array<int,array<string,mixed>> $tools Registered tools.
	 * @return array<int,array<string,mixed>> Enabled tools.
	 */
	private function filter_disabled_tools( $tools ) {
		$disabled = get_option( 'mcpwp_disabled_tool_categories', array() );
		if ( ! is_array( $disabled ) || empty( $disabled ) ) {
			return $tools;
		}
		return array_values(
			array_filter(
				$tools,
				function ( $tool ) use ( $disabled ) {
					return ! in_array( $tool['annotations']['category'] ?? 'site', $disabled, true );
				}
			)
		);
	}

	/**
	 * Remove tools outside the authenticated key's category grants.
	 *
	 * @param array<int,array<string,mixed>> $tools Site-enabled tools.
	 * @return array<int,array<string,mixed>> Tools allowed for the key.
	 */
	private function filter_tools_for_key( $tools ) {
		$record = $this->get_current_api_key_record();
		if ( ! is_array( $record ) ) {
			return $tools;
		}
		return array_values(
			array_filter(
				$tools,
				function ( $tool ) use ( $record ) {
					return $this->key_allows_category( $record, $tool['annotations']['category'] ?? 'site' );
				}
			)
		);
	}

	/**
	 * Build a JSON-RPC success envelope.
	 *
	 * @param int|string|null $id     JSON-RPC request ID.
	 * @param mixed           $result Result payload.
	 * @return array<string,mixed> JSON-RPC result envelope.
	 */
	private function jsonrpc_result( $id, $result ) {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);
	}

	/**
	 * Build a JSON-RPC error envelope.
	 *
	 * @param int|string|null $id      JSON-RPC request ID.
	 * @param int             $code    JSON-RPC error code.
	 * @param string          $message Human-readable error message.
	 * @param array           $data    Optional structured error data.
	 * @return array<string,mixed> JSON-RPC error envelope.
	 */
	private function jsonrpc_error( $id, $code, $message, $data = array() ) {
		$error = array(
			'code'    => $code,
			'message' => $message,
		);
		if ( ! empty( $data ) ) {
			$error['data'] = $data;
		}
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => $error,
		);
	}

	/**
	 * Build a JSON REST response for an MCP JSON-RPC payload.
	 *
	 * @param mixed $data   JSON-RPC response payload.
	 * @param int   $status HTTP status code.
	 * @return WP_REST_Response MCP response.
	 */
	private function mcp_response( $data, $status = 200 ) {
		$response = new WP_REST_Response( $data, $status );
		$response->header( 'Content-Type', 'application/json; charset=UTF-8' );
		return $response;
	}
}
