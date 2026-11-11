<?php
/**
 * One admission, context and receipt boundary for WooCommerce writes.
 *
 * @package MCPWP
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mcpwp_WooCommerce_Audit {
	const MAX_ROWS = 100;
	const MAX_ARGUMENT_BYTES = 65536;

	/** Exact request objects carry ownership; tool names never imply ownership. */
	private static $contexts;
	private static $auth_requests;
	private static $budgets;
	private static $active_contexts = array();

	/** WooCommerce-only persistence: the generic action log remains identical to main. */
	private static function persist( $context ) {
		global $wpdb;
		$row = array(
			'log_id' => 'act_' . str_replace( '-', '', wp_generate_uuid4() ),
			'timestamp' => current_time( 'mysql', true ),
			'tool_name' => $context->tool, 'category' => 'woocommerce',
			'api_key_id' => $context->api_key_id,
			'arguments' => wp_json_encode( self::redact_arguments( $context->arguments ) ),
			'before_snap' => null, 'after_snap' => null, 'resource_type' => 'other',
			'resource_id' => $context->resource_id, 'resource_post_type' => '', 'snapshot_skipped' => '',
			'duration_ms' => $context->duration_ms, 'success' => $context->success ? 1 : 0,
			'error_code' => $context->error_code, 'rollback_supported' => 0, 'rolled_back' => 0,
		);
		foreach ( array( 'log_id' => 44, 'tool_name' => 100, 'category' => 50, 'api_key_id' => 100, 'resource_type' => 50, 'resource_id' => 255, 'resource_post_type' => 40, 'snapshot_skipped' => 20, 'error_code' => 50 ) as $field => $limit ) {
			$row[ $field ] = self::bound( $row[ $field ], $limit );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$written = $wpdb->insert( $wpdb->prefix . Mcpwp_Action_Log::TABLE, $row, array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%d' ) );
		if ( false === $written || null === $written ) {
			update_option( 'mcpwp_action_log_last_failure', current_time( 'mysql', true ), false );
		} else {
			delete_option( 'mcpwp_action_log_last_failure' );
		}
		return $written;
	}

	public static function register_site_health( $tests ) {
		$tests['direct']['mcpwp_action_log'] = array( 'label' => __( 'MCPWP WooCommerce action log', 'mcpwp' ), 'test' => array( __CLASS__, 'site_health_test' ) );
		return $tests;
	}

	public static function site_health_test() {
		$failed = get_option( 'mcpwp_action_log_last_failure', false );
		$unknown = get_option( 'mcpwp_woocommerce_audit_unknown_actor', false );
		return array(
			'label' => $failed || $unknown ? __( 'MCPWP WooCommerce audit needs attention', 'mcpwp' ) : __( 'MCPWP WooCommerce audit is healthy', 'mcpwp' ),
			'status' => $failed || $unknown ? 'critical' : 'good',
			'badge' => array( 'label' => 'MCPWP', 'color' => 'blue' ),
			'description' => '<p>' . esc_html__( 'A failed insert leaves a missing receipt. An unknown actor means an executed write lacks verified attribution. Investigate and clear the unknown-actor diagnostic after resolving its cause; a successful insert clears only the insertion diagnostic.', 'mcpwp' ) . '</p>',
			'actions' => '', 'test' => 'mcpwp_action_log',
		);
	}

	/** Rate/admission refusals never fan out into persistent child receipts. */
	private static function admission_error( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}
		$data = $error->get_error_data();
		$status = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;
		return in_array( $status, array( 413, 429 ), true ) || in_array( $error->get_error_code(), array( 'batch_exceeds_limit', 'rate_limit_exceeded', 'batch_too_large', 'woocommerce_audit_limit' ), true );
	}

	/** Runs after permission/spend checks, with the route parameters core merged. */
	public static function capture_dispatch( $result, $request, $route, $handler ) {
		$context = self::context_for( $request );
		if ( $context && 'write' === $context->kind && null === $result ) {
			$context->arguments = $request->get_params();
			$context->executed = true;
			$permission = $handler['permission_callback'] ?? null;
			$controller = is_array( $permission ) ? $permission[0] : null;
			if ( is_object( $controller ) && method_exists( $controller, 'get_audit_key_id' ) && '' !== $controller->get_audit_key_id() ) {
				$context->api_key_id = $controller->get_audit_key_id();
			}
		}
		return $result;
	}

	/** The declared write surface, including REST-only archetype updates. */
	public static function catalog() {
		return array(
			'wc_create_product' => array( '/woocommerce/products', array( 'POST' ), 'id' ),
			'wc_update_product' => array( '/woocommerce/products/{id}', array( 'POST', 'PUT', 'PATCH' ), 'id' ),
			'wc_delete_product' => array( '/woocommerce/products/{id}', array( 'DELETE' ), 'id' ),
			'wc_create_product_category' => array( '/woocommerce/products/categories', array( 'POST' ), 'id' ),
			'wc_update_product_category' => array( '/woocommerce/products/categories/{id}', array( 'POST', 'PUT', 'PATCH' ), 'id' ),
			'wc_update_order' => array( '/woocommerce/orders/{id}', array( 'POST', 'PUT', 'PATCH' ), 'id' ),
			'wc_create_product_archetype' => array( '/woocommerce/archetypes', array( 'POST' ), 'id' ),
			'wc_update_product_archetype' => array( '/woocommerce/archetypes/{id}', array( 'POST', 'PUT', 'PATCH' ), 'id' ),
			'wc_apply_product_archetype' => array( '/woocommerce/archetypes/{id}/apply', array( 'POST' ), 'product' ),
		);
	}

	public static function tool_definition( $name ) {
		$catalog = self::catalog();
		if ( ! is_string( $name ) || ! isset( $catalog[ $name ] ) ) {
			return null;
		}
		$row = $catalog[ $name ];
		return array( 'tool' => $name, 'route' => $row[0], 'methods' => $row[1], 'result' => $row[2] );
	}

	public static function normalize_route( $route ) {
		$route = strtolower( rtrim( (string) $route, '/' ) );
		$route = preg_replace( '#^/(?:wp-json/)?mcpwp/v1(?=/|$)#', '', $route );
		return '/' . ltrim( $route, '/' );
	}

	public static function route_definition( $request ) {
		$route = self::normalize_route( $request->get_route() );
		foreach ( self::catalog() as $name => $row ) {
			$pattern = str_replace( '\\{id\\}', '[0-9]+', preg_quote( $row[0], '@' ) );
			if ( in_array( strtoupper( $request->get_method() ), $row[1], true ) && preg_match( '@^' . $pattern . '$@i', $route ) ) {
				return self::tool_definition( $name );
			}
		}
		return null;
	}

	/** Admission is bounded before raw denied envelopes are enumerated. */
	private static function admitted( $request ) {
		if ( ! $request instanceof WP_REST_Request ) {
			return true;
		}
		$route = self::normalize_route( $request->get_route() );
		if ( preg_match( '@^/mcp$@i', $route ) ) {
			$body = $request->get_json_params();
			return ! is_array( $body ) || ! isset( $body[0] ) || count( $body ) <= self::MAX_ROWS;
		}
		if ( preg_match( '@^/batch$@i', $route ) ) {
			$operations = $request->get_param( 'operations' );
			return is_array( $operations ) && count( $operations ) <= self::MAX_ROWS;
		}
		return true;
	}

	/** Root budgets are objects shared explicitly by the admitted request tree. */
	private static function request_budget( $request, $inherited = null ) {
		if ( null === self::$budgets ) {
			self::$budgets = new SplObjectStorage();
		}
		if ( $inherited ) {
			$budget = $inherited;
		} elseif ( is_object( $request ) && self::$budgets->contains( $request ) ) {
			return self::$budgets[ $request ];
		} else {
			// Independent writes from hooks inherit only the root quota, never
			// execution ownership or a principal from the currently running write.
			$parent = end( self::$active_contexts );
			if ( ! $parent && count( self::$budgets ) >= 256 ) {
				// Release completed request bodies in long-lived CLI workers.
				self::$budgets = new SplObjectStorage();
				self::$contexts = new SplObjectStorage();
				self::$auth_requests = new SplObjectStorage();
			}
			$budget = $parent ? $parent->budget : (object) array( 'count' => 0, 'limit' => self::MAX_ROWS );
		}
		if ( is_object( $request ) ) {
			self::$budgets[ $request ] = $budget;
		}
		return $budget;
	}

	public static function new_attempt( $tool, array $arguments, $actor, $request = null, $budget = null ) {
		$definition = self::tool_definition( $tool );
		if ( null === $definition || ! self::admitted( $request ) ) {
			return null;
		}
		$budget = self::request_budget( $request, $budget );
		if ( $budget->count >= $budget->limit ) {
			return new WP_Error( 'woocommerce_audit_limit', __( 'WooCommerce write admission limit reached. Split this request.', 'mcpwp' ), array( 'status' => 429 ) );
		}
		$budget->count++;
		return (object) array(
			'kind' => 'write', 'tool' => $tool, 'definition' => $definition, 'arguments' => $arguments,
			'api_key_id' => (string) $actor, 'route' => $definition['route'],
			'budget' => $budget, 'admitted' => true, 'running' => false, 'finished' => false,
			'executed' => false, 'admission_refused' => false, 'result' => array(), 'observed' => null, 'success' => false,
			'error_code' => 'execution_error', 'resource_id' => '', 'replayed' => false,
		);
	}

	public static function bind( $context, $request ) {
		if ( ! is_object( $context ) || is_wp_error( $context ) || ! $request instanceof WP_REST_Request ) {
			return;
		}
		if ( null === self::$contexts ) {
			self::$contexts = new SplObjectStorage();
		}
		self::$contexts[ $request ] = $context;
		self::request_budget( $request, $context->budget );
		$context->route = self::normalize_route( $request->get_route() );
	}

	private static function context_for( $request ) {
		return self::$contexts && self::$contexts->contains( $request ) ? self::$contexts[ $request ] : null;
	}

	private static function outcome( $response, $format ) {
		if ( is_wp_error( $response ) ) {
			return array( false, $response->get_error_code(), array(), self::admission_error( $response ) );
		}
		if ( 'mcp' === $format ) {
			$body = $response['result'] ?? array();
			$body = is_object( $body ) ? (array) $body : $body;
			$success = ! isset( $response['error'] ) && empty( $body['isError'] );
			$code = $success ? '' : ( $body['structuredContent']['error']['code'] ?? ( $response['error']['data']['code'] ?? 'tool_error' ) );
			if ( ! empty( $body['_meta']['replayed'] ) ) {
				$code = 'replayed';
			}
			$data = $body['structuredContent'] ?? json_decode( $body['content'][0]['text'] ?? '', true );
			if ( ! empty( $data['approval_required'] ) ) {
				$success = false;
				$code = 'deferred';
			}
			return array( $success, $code, is_array( $data ) ? $data : array(), in_array( $code, array( 'batch_exceeds_limit', 'rate_limit_exceeded', 'batch_too_large', 'woocommerce_audit_limit' ), true ) );
		}
		$response = rest_ensure_response( $response );
		$data = $response->get_data();
		$success = $response->get_status() < 400;
		if ( ! empty( $data['approval_required'] ) ) {
			return array( false, 'deferred', $data, false );
		}
		return array( $success, $success ? '' : ( $data['code'] ?? 'execution_error' ), is_array( $data ) ? $data : array(), in_array( $response->get_status(), array( 413, 429 ), true ) );
	}

	/** The sole WooCommerce completion/writing chokepoint. */
	public static function execute( $context, callable $callback, $format = 'mcp' ) {
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		if ( null === $context ) {
			return $callback();
		}
		if ( $context->running || $context->finished ) {
			return new WP_Error( 'woocommerce_audit_context_reused', __( 'This write attempt already ran.', 'mcpwp' ), array( 'status' => 409 ) );
		}
		$context->running = true;
		self::$active_contexts[] = $context;
		$start = microtime( true );
		try {
			$response = $callback();
			$outcome = self::outcome( $response, $format );
			list( $context->success, $context->error_code, $data ) = $outcome;
			$context->admission_refused = $context->admission_refused || ! empty( $outcome[3] );
			$context->result = $data;
			if ( is_array( $context->observed ) ) {
				$context->result = $context->observed[2];
				$context->admission_refused = $context->admission_refused || ! empty( $context->observed[3] );
				if ( ! $context->observed[0] ) {
					$context->success = false;
					$context->error_code = $context->observed[1];
				}
			}
			if ( $context->replayed ) {
				$context->error_code = 'replayed';
			}
			return $response;
		} finally {
			array_pop( self::$active_contexts );
			$context->running = false;
			$context->finished = true;
			$context->duration_ms = min( 2147483647, (int) round( ( microtime( true ) - $start ) * 1000 ) );
			$result = $context->result;
			if ( isset( $result['data'] ) && is_array( $result['data'] ) ) {
				$result = $result['data'];
			}
			if ( 'product' === $context->definition['result'] ) {
				$result = $result['product'] ?? array();
			}
			// A successful receipt names the object returned by the actual write.
			// Requested targets are retained only for denied/failed attempts.
			$id = isset( $result['id'] ) && is_scalar( $result['id'] ) ? (string) $result['id'] : '';
			if ( '' === $id && ! $context->success ) {
				$id = $context->arguments[ 'wc_apply_product_archetype' === $context->tool ? 'product_id' : 'id' ] ?? '';
			}
			// WooCommerce identifiers are numeric; invalid requested IDs must
			// not smuggle free text or credentials into public row metadata.
			$context->resource_id = is_scalar( $id ) && ctype_digit( (string) $id ) ? self::bound( $id, 255 ) : '';
			$ran = $context->executed || $context->success || 'deferred' === $context->error_code;
			if ( '' === $context->api_key_id && $ran ) {
				$context->api_key_id = 'unknown-actor';
				update_option( 'mcpwp_woocommerce_audit_unknown_actor', current_time( 'mysql', true ), false );
			}
			if ( '' !== $context->api_key_id && ( ! $context->admission_refused || $context->executed ) ) {
				self::persist( $context );
			} else {
				$context->budget->count--; // Pre-admission/unverified denials write no rows.
			}
		}
	}

	/** REST adapter: ownership is bound to this exact request, including errors. */
	public static function rest_dispatch( $result, $server, $request ) {
		$previous = self::context_for( $request );
		if ( $previous && ! $previous->finished ) {
			return $result; // Re-entry into the exact in-flight execution.
		}
		if ( $previous ) {
			self::$contexts->detach( $request );
			if ( empty( self::$active_contexts ) && self::$budgets && self::$budgets->contains( $request ) ) {
				self::$budgets->detach( $request );
			}
		}
		if ( self::$auth_requests && self::$auth_requests->contains( $request ) ) {
			self::$auth_requests->detach( $request );
		}
		if ( null === ( $definition = self::route_definition( $request ) ) ) {
			return $result;
		}
		$context = self::new_attempt( $definition['tool'], $request->get_params(), '', $request );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		self::bind( $context, $request );
		return self::execute( $context, static function () use ( $result, $request ) {
			return null === $result ? rest_do_request( $request ) : rest_ensure_response( $result );
		}, 'rest' );
	}

	/** Keep a root quota around admitted entry-point handlers, including callbacks. */
	public static function scope_dispatch( $result, $request, $route, $handler ) {
		$path = self::normalize_route( $request->get_route() );
		if ( null !== $result || ! is_callable( $handler['callback'] ?? null ) || ( ! preg_match( '@^/(?:mcp|batch|webmcp/call)$@i', $path ) && ! self::route_definition( $request ) ) ) {
			return $result;
		}
		$scope = (object) array( 'budget' => self::request_budget( $request ) );
		self::$active_contexts[] = $scope;
		try {
			return call_user_func( $handler['callback'], $request );
		} finally {
			array_pop( self::$active_contexts );
		}
	}

	/** Permission/handler adapter supplies verified actor and actual handler result. */
	public static function rest_result( $response, $handler, $request ) {
		$context = self::context_for( $request );
		if ( $context && ! $context->finished ) {
			$permission = $handler['permission_callback'] ?? null;
			$controller = is_array( $permission ) ? $permission[0] : null;
			if ( '' === $context->api_key_id && is_wp_error( $response ) && is_object( $controller ) && method_exists( $controller, 'get_audit_key_id' ) && is_callable( $permission ) ) {
				// Core skips permissions for invalid arguments. Verify identity
				// for this receipt only; preserve the error and never run the handler.
				call_user_func( $permission, $request );
			}
			if ( is_object( $controller ) && method_exists( $controller, 'get_audit_key_id' ) && '' !== $controller->get_audit_key_id() ) {
				$context->api_key_id = $controller->get_audit_key_id();
			}
			if ( 'write' === $context->kind && self::route_definition( $request ) ) {
				$context->arguments = $request->get_params();
				$context->observed = self::outcome( $response, 'rest' );
			}
		}
		return $response;
	}

	private static function target( array $params ) {
		return 'mcpwp_call' === ( $params['name'] ?? null ) && isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : $params;
	}

	private static function children( array $params ) {
		$params = self::target( $params );
		if ( ! in_array( $params['name'] ?? '', array( 'wp_batch', 'wp_batch_update' ), true ) ) {
			return array();
		}
		$operations = $params['arguments']['operations'] ?? null;
		if ( ! is_array( $operations ) || count( $operations ) > self::MAX_ROWS ) {
			return array();
		}
		$children = array();
		foreach ( $operations as $index => $operation ) {
			if ( ! is_array( $operation ) || ! is_string( $operation['path'] ?? null ) || ! is_string( $operation['method'] ?? null ) ) {
				continue;
			}
			$request = new WP_REST_Request( $operation['method'], $operation['path'] );
			$request->set_body_params( is_array( $operation['body'] ?? null ) ? $operation['body'] : array() );
			$definition = self::route_definition( $request );
			if ( $definition ) {
				$arguments = $request->get_params();
				if ( ! isset( $arguments['id'] ) && preg_match( '#/([0-9]+)(?:/apply)?$#', $request->get_route(), $match ) ) {
					$arguments['id'] = $match[1];
				}
				$children[ $index ] = array( $definition['tool'], $arguments );
			}
		}
		return $children;
	}

	/** MCP and batch aliases enter the same context/execute boundary. */
	public static function run_call( array $params, $request, $actor, callable $callback, $format = 'mcp' ) {
		$target = self::target( $params );
		$operations = $target['arguments']['operations'] ?? null;
		if ( ! self::admitted( $request ) || ( in_array( $target['name'] ?? '', array( 'wp_batch', 'wp_batch_update' ), true ) && is_array( $operations ) && count( $operations ) > self::MAX_ROWS ) ) {
			return new WP_Error( 'batch_too_large', 'Batch exceeds the admitted request limit.', array( 'status' => 400 ) );
		}
		$name = is_string( $target['name'] ?? null ) ? $target['name'] : '';
		$arguments = is_array( $target['arguments'] ?? null ) ? $target['arguments'] : array();
		if ( self::tool_definition( $name ) ) {
			$claimed = $request instanceof WP_REST_Request ? self::context_for( $request ) : null;
			if ( $claimed && 'write' === $claimed->kind && $claimed->tool === $name ) {
				return $claimed->running ? $callback( $claimed ) : new WP_Error( 'woocommerce_audit_context_reused', 'This write attempt already completed.' );
			}
			$context = self::new_attempt( $name, $arguments, $actor, $request );
			return self::execute( $context, static function () use ( $callback, $context ) { return $callback( $context ); }, $format );
		}
		$is_batch = in_array( $name, array( 'wp_batch', 'wp_batch_update' ), true );
		$batch = $is_batch && $request instanceof WP_REST_Request ? self::context_for( $request ) : null;
		if ( $is_batch && ( ! $batch || 'batch' !== $batch->kind ) ) {
			$batch = (object) array( 'kind' => 'batch', 'budget' => self::request_budget( $request ), 'api_key_id' => $actor, 'children' => array(), 'finished' => false );
		}
		$response = $callback( $batch );
		if ( ! $is_batch ) {
			return $response;
		}
		$outcome = self::outcome( $response, $format );
		list( $success, $code, $data ) = $outcome;
		if ( ! empty( $outcome[3] ) ) {
			$batch->finished = true;
			return $response;
		}
		if ( $is_batch ) {
			foreach ( self::children( $params ) as $index => $child ) {
				if ( $batch && isset( $batch->children[ $index ] ) ) {
					continue;
				}
				$context = self::new_attempt( $child[0], $child[1], $actor, $request );
				if ( is_wp_error( $context ) ) {
					break;
				}
				$batch->children[ $index ] = $context;
				$context->replayed = 'replayed' === $code;
				$cached = $data['results'][ $index ] ?? null;
				self::execute( $context, static function () use ( $cached, $code ) {
					return $cached ? new WP_REST_Response( $cached['data'] ?? array(), $cached['status'] ?? 200 ) : new WP_Error( $code ?: 'execution_error', 'Write was not executed.' );
				}, 'rest' );
			}
		}
		if ( $batch ) {
			$batch->finished = true;
		}
		return $response;
	}

	/** Batch adapters pass an explicit parent and one context per child index. */
	public static function begin_batch( $request, $actor ) {
		$context = self::context_for( $request );
		if ( ! $context ) {
			$context = (object) array( 'kind' => 'batch', 'budget' => self::request_budget( $request ), 'api_key_id' => $actor, 'children' => array(), 'finished' => false );
			self::bind( $context, $request );
		}
		return $context;
	}

	public static function dispatch_child( $parent, $index, $request, callable $callback ) {
		$definition = self::route_definition( $request );
		if ( ! $definition ) {
			return $callback();
		}
		$context = self::new_attempt( $definition['tool'], $request->get_params(), $parent->api_key_id, $request, $parent->budget );
		$parent->children[ $index ] = $context;
		self::bind( $context, $request );
		$response = self::execute( $context, $callback, 'rest' );
		if ( is_wp_error( $response ) ) {
			$data = $response->get_error_data();
			return new WP_REST_Response( array( 'code' => $response->get_error_code(), 'message' => $response->get_error_message(), 'data' => $data ), is_array( $data ) ? ( $data['status'] ?? 500 ) : 500 );
		}
		return $response;
	}

	/** Authentication only updates bound attempts; unbound denials share admission. */
	public static function auth_result( $request, $verdict, $actor ) {
		$context = self::context_for( $request );
		$refused = self::admission_error( $verdict );
		if ( $context ) {
			if ( $refused ) {
				$context->admission_refused = true;
			}
			if ( '' !== $actor ) {
				$context->api_key_id = $actor;
			}
			return;
		}
		if ( $refused || ! is_wp_error( $verdict ) || '' === $actor || ! self::admitted( $request ) ) {
			return;
		}
		if ( null === self::$auth_requests ) {
			self::$auth_requests = new SplObjectStorage();
		}
		if ( self::$auth_requests->contains( $request ) ) {
			return;
		}
		self::$auth_requests->attach( $request );
		$definition = self::route_definition( $request );
		if ( $definition ) {
			$context = self::new_attempt( $definition['tool'], $request->get_params(), $actor, $request );
			self::bind( $context, $request );
			self::execute( $context, static function () use ( $verdict ) { return $verdict; }, 'rest' );
			return;
		}
		$route = self::normalize_route( $request->get_route() );
		if ( preg_match( '@^/batch$@i', $route ) ) {
			$calls = array( array( 'params' => array( 'name' => 'wp_batch', 'arguments' => array( 'operations' => $request->get_param( 'operations' ) ) ) ) );
		} elseif ( preg_match( '@^/mcp$@i', $route ) ) {
			$body = $request->get_json_params();
			$calls = isset( $body['method'] ) ? array( $body ) : ( is_array( $body ) ? $body : array() );
		} else {
			return;
		}
		foreach ( $calls as $call ) {
			if ( is_array( $call['params'] ?? null ) ) {
				self::run_call( $call['params'], $request, $actor, static function () use ( $verdict ) { return $verdict; }, 'rest' );
			}
		}
	}

	/** UTF-8 character bounds match the schema, without splitting characters. */
	public static function bound( $value, $limit ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		return preg_match( '/^.{0,' . (int) $limit . '}/us', $value, $match ) ? $match[0] : '';
	}

	/** Decode wrappers, then permit only declared non-secret controls. */
	public static function redact_arguments( array $arguments ) {
		$safe = array();
		$names = array( 'id', 'product_id', 'order_id', 'category_id', 'parent_id', 'term_id', 'force', 'virtual', 'downloadable', 'note_customer', 'status', 'sku', 'name', 'note', 'description', 'short_description', 'regular_price', 'sale_price', 'type', 'categories', 'tags', 'attributes', 'images', 'stock_quantity', 'manage_stock', 'stock_status', 'meta_data', 'product_data', 'archetype_class', 'archetype_style' );
		$masked = class_exists( 'Mcpwp_Option_Access' ) ? Mcpwp_Option_Access::log_redaction_keys() : array();
		foreach ( $arguments as $key => $value ) {
			$name = is_string( $key ) && in_array( $key, $names, true ) ? $key : 'withheld_fields';
			if ( 'withheld_fields' === $name || in_array( strtolower( $name ), $masked, true ) ) {
				$safe[ $name ] = '[redacted]';
				continue;
			}
			for ( $depth = 0; is_string( $value ) && $depth < 6; $depth++ ) {
				$decoded = json_decode( $value, true );
				if ( JSON_ERROR_NONE === json_last_error() && $decoded !== $value ) {
					$value = $decoded;
					continue;
				}
				if ( preg_match( '/^(?:s|a|i|b|d|N):/', $value ) ) {
					$decoded = @unserialize( $value, array( 'allowed_classes' => false ) );
					if ( false !== $decoded || 'b:0;' === $value ) {
						$value = $decoded;
						continue;
					}
				}
				$decoded = rawurldecode( $value );
				if ( $decoded !== $value ) {
					$value = $decoded;
					continue;
				}
				$decoded = base64_decode( $value, true );
				if ( false !== $decoded && '' !== $decoded && preg_match( '//u', $decoded ) && rtrim( base64_encode( $decoded ), '=' ) === rtrim( $value, '=' ) ) {
					$value = $decoded;
					continue;
				}
				break;
			}
			if ( in_array( $name, array( 'id', 'product_id', 'order_id', 'category_id', 'parent_id', 'term_id' ), true ) && ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) ) {
				$safe[ $name ] = is_int( $value ) ? $value : self::bound( $value, 255 );
			} elseif ( in_array( $name, array( 'force', 'virtual', 'downloadable', 'note_customer' ), true ) && is_bool( $value ) ) {
				$safe[ $name ] = $value;
			} elseif ( 'status' === $name && is_string( $value ) && in_array( $value, array( 'draft', 'pending', 'private', 'publish', 'processing', 'completed', 'cancelled', 'refunded', 'on-hold', 'failed' ), true ) ) {
				$safe[ $name ] = $value;
			} else {
				$safe[ $name ] = '[redacted]';
			}
		}
		$json = wp_json_encode( $safe );
		return false === $json || strlen( $json ) > self::MAX_ARGUMENT_BYTES ? array( 'withheld' => 'too_large' ) : $safe;
	}
}
