<?php
/**
 * Batch REST Controller
 *
 * Allows multiple REST API operations in a single request for efficiency.
 *
 * @package mumcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Batch REST controller.
 *
 * Processes multiple REST API operations in a single HTTP request.
 * Each operation is executed sequentially with the same authentication.
 */
class Mcpwp_REST_Batch extends Mcpwp_REST_API {

	use Mcpwp_Api_Auth;
	use Mcpwp_Sanitization;
	use Mcpwp_Logging;

	/**
	 * Maximum number of operations allowed per batch.
	 *
	 * @var int
	 */
	const MAX_OPERATIONS = 25;

	/**
	 * PHP-internal flag set while a batch sub-request is being dispatched.
	 *
	 * The auth/rate-limit layer reads this property instead of checking an
	 * HTTP header, which any external caller could forge.  It is reset to
	 * false immediately after each sub-request returns.
	 *
	 * @var bool
	 */
	public static $is_batch_sub_request = false;

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/batch',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_batch' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'operations' => array(
							'description' => __( 'Array of operations to execute.', 'mumega-mcp' ),
							'type'        => 'array',
							'required'    => true,
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'method' => array(
										'type'     => 'string',
										'enum'     => array( 'GET', 'POST', 'PUT', 'DELETE' ),
										'required' => true,
									),
									'path'   => array(
										'type'     => 'string',
										'required' => true,
									),
									'body'   => array(
										'type' => 'object',
									),
								),
							),
						),
					),
				),
			)
		);
	}

	/**
	 * Handle batch request.
	 *
	 * Processes multiple operations sequentially and returns results.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function handle_batch( $request ) {
		$this->log_activity( 'batch_request', $request );

		$operations = $request->get_param( 'operations' );

		// Validate operations array
		if ( ! is_array( $operations ) || empty( $operations ) ) {
			return $this->error_response(
				'invalid_operations',
				__( 'Operations must be a non-empty array.', 'mumega-mcp' ),
				400
			);
		}

		// Check batch size limit
		if ( count( $operations ) > self::MAX_OPERATIONS ) {
			return $this->error_response(
				'batch_too_large',
				sprintf(
					/* translators: %d: maximum number of operations */
					__( 'Batch contains too many operations. Maximum %d allowed.', 'mumega-mcp' ),
					self::MAX_OPERATIONS
				),
				400
			);
		}

		// Get API key from current request for forwarding
		$api_key = $this->get_api_key_from_request( $request );

		$results = array();

		// Execute each operation sequentially
		foreach ( $operations as $index => $operation ) {
			$result    = $this->execute_operation( $operation, $index, $api_key );
			$results[] = $result;
		}

		return $this->success_response(
			array(
				'results' => $results,
				'total'   => count( $results ),
			)
		);
	}

	/**
	 * Execute a single operation.
	 *
	 * @param array  $operation Operation details.
	 * @param int    $index     Operation index.
	 * @param string $api_key   API key for authentication.
	 * @return array Operation result.
	 */
	private function execute_operation( $operation, $index, $api_key ) {
		// Validate operation structure
		if ( ! is_array( $operation ) ) {
			return array(
				'index'  => $index,
				'status' => 400,
				'data'   => array(
					'code'    => 'invalid_operation',
					'message' => __( 'Operation must be an object.', 'mumega-mcp' ),
				),
			);
		}

		$method = isset( $operation['method'] ) ? strtoupper( $operation['method'] ) : '';
		$path   = isset( $operation['path'] ) ? $operation['path'] : '';
		$body   = isset( $operation['body'] ) ? $operation['body'] : array();

		// Validate method
		if ( ! in_array( $method, array( 'GET', 'POST', 'PUT', 'DELETE' ), true ) ) {
			return array(
				'index'  => $index,
				'status' => 400,
				'data'   => array(
					'code'    => 'invalid_method',
					'message' => __( 'Method must be GET, POST, PUT, or DELETE.', 'mumega-mcp' ),
				),
			);
		}

		// Validate path
		if ( empty( $path ) || ! is_string( $path ) ) {
			return array(
				'index'  => $index,
				'status' => 400,
				'data'   => array(
					'code'    => 'invalid_path',
					'message' => __( 'Path must be a non-empty string.', 'mumega-mcp' ),
				),
			);
		}

		// Ensure path starts with /
		if ( '/' !== substr( $path, 0, 1 ) ) {
			$path = '/' . $path;
		}

		// Build full route
		$route = '/mcpwp/v1' . $path;

		// Create internal REST request
		$internal_request = new WP_REST_Request( $method, $route );

		// Set body parameters for POST/PUT
		if ( in_array( $method, array( 'POST', 'PUT' ), true ) && is_array( $body ) ) {
			foreach ( $body as $key => $value ) {
				$internal_request->set_param( $key, $value );
			}
			$internal_request->set_body_params( $body );
		}

		// Set query parameters for GET/DELETE
		if ( in_array( $method, array( 'GET', 'DELETE' ), true ) && is_array( $body ) ) {
			foreach ( $body as $key => $value ) {
				$internal_request->set_param( $key, $value );
			}
			$internal_request->set_query_params( $body );
		}

		// Copy authentication.
		if ( $api_key ) {
			$internal_request->set_header( 'X-API-Key', $api_key );
		}

		// Mark as batch sub-request via a PHP static property so that the
		// auth/rate-limit layer can skip rate counting for sub-requests.
		// A static property cannot be forged by an external HTTP caller,
		// unlike an HTTP header.
		self::$is_batch_sub_request = true;
		$response                   = rest_do_request( $internal_request );
		self::$is_batch_sub_request = false;

		// Extract response data
		$status = $response->get_status();
		$data   = $response->get_data();

		return array(
			'index'  => $index,
			'status' => $status,
			'data'   => $data,
		);
	}
}
