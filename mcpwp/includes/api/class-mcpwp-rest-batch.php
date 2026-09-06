<?php
/**
 * Batch REST Controller
 *
 * Allows multiple REST API operations in a single request for efficiency.
 *
 * @package MCPWP
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
	 * Routes a batch operation may never target.
	 *
	 * Both the batch endpoint and the MCP dispatch endpoint mark their own
	 * internal sub-requests so the auth layer skips rate counting for them.
	 * Letting one of them appear as an operation inside the other means a
	 * single counted request fans out into a tree of uncounted ones, with the
	 * per-call operation ceiling re-applying fresh at every level instead of
	 * being shared. Version-agnostic and case-insensitive, because WordPress
	 * matches REST routes case-insensitively.
	 *
	 * The route name must END here — anything that is not a route-name
	 * character terminates it. Stated as a negative lookahead rather than a set
	 * of terminators on purpose (#752 F12): an enumerated set has to be right
	 * about every character an attacker might append, and the previous one
	 * ("/", "?", "#") was not. It left ";p=1", a NUL byte, a backslash and
	 * trailing whitespace uncovered — all inert today only because WordPress'
	 * route matcher is anchored, which is exactly the downstream accident the
	 * paragraph below says this constant must not lean on. Either the principle
	 * covers all of them or "?" and "#" never needed special-casing.
	 *
	 * The lookahead inverts the burden: a hyphenated or longer sibling
	 * ("/batch-status", "/batchelor", "/mcp-server") continues the name and is
	 * allowed, and everything else is a terminator without having to be listed.
	 * Where the two differ, this one denies — the safe direction for a denial
	 * list, and no shipped route is affected because the only routes under this
	 * prefix are exactly "/batch" and "/mcp".
	 *
	 * Version-agnostic and case-insensitive, because WordPress matches REST
	 * routes case-insensitively.
	 *
	 * Delimited with "~" rather than the house "#" so the pattern stays legible
	 * next to its history: "#" used to appear inside it as a terminator, where
	 * it would have closed the pattern early even inside a character class, and
	 * the resulting compile failure would make preg_match() return false —
	 * failing open on a denial check.
	 *
	 * @var string
	 */
	const DENIED_OPERATION_ROUTE_PATTERN = '~^/mcpwp/v[0-9]+/(?:batch|mcp)(?![A-Za-z0-9_-])~i';

	/**
	 * PHP-internal flag set while a batch sub-request is being dispatched.
	 *
	 * The auth/rate-limit layer reads this property instead of checking an
	 * HTTP header, which any external caller could forge.
	 *
	 * Each dispatch saves the flag's current value, sets it true, and restores
	 * that saved value in a finally block — it is NOT reset to false. The MCP
	 * tool dispatcher sets this same flag around every tools/call, and one of
	 * its tools maps to this endpoint, so a dispatch here can legitimately
	 * begin with the flag already true; writing false unconditionally would
	 * clear an outer caller's state while it is still live. Restoring rather
	 * than resetting also means a throwing sub-request cannot leave rate
	 * limiting switched off for the remainder of the PHP process.
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
							'description' => __( 'Array of operations to execute.', 'mcpwp' ),
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
				__( 'Operations must be a non-empty array.', 'mcpwp' ),
				400
			);
		}

		// Check batch size limit
		if ( count( $operations ) > self::MAX_OPERATIONS ) {
			return $this->error_response(
				'batch_too_large',
				sprintf(
					/* translators: %d: maximum number of operations */
					__( 'Batch contains too many operations. Maximum %d allowed.', 'mcpwp' ),
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
	 * Finish normalising a route from the result of the slash-collapsing
	 * preg_replace() call in execute_operation().
	 *
	 * Split out so the null-from-preg_replace guard is independently testable
	 * via reflection, since '#/+#' cannot itself be made to fail from a test —
	 * it is anchored to no backtracking and always compiles.
	 *
	 * @param string|null $collapsed_route Result of preg_replace( '#/+#', '/', $route ).
	 * @return string|null Normalised route, or null if it could not be normalised.
	 */
	private static function finish_normalizing_route( $collapsed_route ) {
		if ( ! is_string( $collapsed_route ) ) {
			return null;
		}
		return '/' . trim( $collapsed_route, '/' );
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
					'message' => __( 'Operation must be an object.', 'mcpwp' ),
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
					'message' => __( 'Method must be GET, POST, PUT, or DELETE.', 'mcpwp' ),
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
					'message' => __( 'Path must be a non-empty string.', 'mcpwp' ),
				),
			);
		}

		// Strip surrounding whitespace and control bytes (trim() covers NUL,
		// tab, CR/LF and vertical tab) before anything reads this path. A
		// leading space turned "/batch" into a route the denial pattern could
		// not recognise and WordPress would not route either — inert, but inert
		// for the downstream reason this file has twice decided not to rely on
		// (#752 F12). Normalising here means the denial check and the dispatch
		// below see the same string.
		$path = trim( $path );

		// Ensure path starts with /
		if ( '/' !== substr( $path, 0, 1 ) ) {
			$path = '/' . $path;
		}

		// Build full route
		$route = '/mcpwp/v1' . $path;

		// Collapse repeated slashes and drop a trailing one before matching:
		// "//batch" and "/batch/" reach the same handler as "/batch", so the
		// check has to see the same string WordPress will.
		$collapsed_route  = preg_replace( '#/+#', '/', $route );
		$normalized_route = self::finish_normalizing_route( $collapsed_route );

		// preg_replace() returns null (not false) on a PCRE engine failure —
		// backtrack limit, recursion limit, or another internal error — rather
		// than throwing. Passing that null through trim() would coerce it to
		// '', $normalized_route would become '/', the denial check below would
		// not fire, and the ORIGINAL, un-normalised $route (built below) would
		// still be the one dispatched. That is the classic parser-differential
		// bug this file's own DENIED_OPERATION_ROUTE_PATTERN docblock warns
		// about one line up: check the normalised value, dispatch the raw one.
		// Refuse to dispatch a route this code could not itself normalise,
		// rather than fail open into dispatching it unchecked.
		//
		// Not reachable today: '#/+#' is anchored to no backtracking and
		// always compiles, so preg_replace() cannot fail against it. This
		// guard removes the dependency on that fact rather than fixing a live
		// bug.
		if ( null === $normalized_route ) {
			return array(
				'index'  => $index,
				'status' => 400,
				'data'   => array(
					'code'    => 'forbidden_path',
					'message' => __( 'Operation path could not be safely normalised.', 'mcpwp' ),
				),
			);
		}

		// Refuse to dispatch back into the batch or MCP entry points. Rejecting
		// only this operation, not the whole batch, matches how every other
		// per-operation validation failure behaves here.
		if ( preg_match( self::DENIED_OPERATION_ROUTE_PATTERN, $normalized_route ) ) {
			return array(
				'index'  => $index,
				'status' => 400,
				'data'   => array(
					'code'    => 'forbidden_path',
					'message' => __( 'Operations may not target the batch or MCP dispatch endpoints.', 'mcpwp' ),
				),
			);
		}

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
		//
		// Save and restore the previous value rather than hard-resetting to
		// false. This dispatch may itself already be running inside another
		// internal dispatch — the MCP tool dispatcher sets the same flag around
		// every tools/call, and one of its tools maps to this very endpoint —
		// so writing false unconditionally would clear a caller's state that is
		// still live. try/finally guarantees the restore even if the
		// sub-request throws, so one failing operation cannot leave rate
		// limiting switched off for the rest of the PHP process.
		$previous_sub_request       = self::$is_batch_sub_request;
		self::$is_batch_sub_request = true;

		try {
			$response = rest_do_request( $internal_request );
		} finally {
			self::$is_batch_sub_request = $previous_sub_request;
		}

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
