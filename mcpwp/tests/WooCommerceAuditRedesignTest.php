<?php
/**
 * Regression coverage for #1037 review round 2.
 *
 * This suite exercises the explicit WooCommerce audit catalogue rather than
 * trusting an HTTP verb, a prefix, or a nested ambient attempt.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-action-log.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-option-access.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-woocommerce-audit.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-batch.php';
require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-woocommerce.php';

if ( ! function_exists( 'rest_ensure_response' ) ) {
	function rest_ensure_response( $response ) {
		return $response instanceof WP_REST_Response ? $response : new WP_REST_Response( $response );
	}
}

final class WooCommerceAuditRedesignTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options'] = array();
		$GLOBALS['mcpwp_test_transients'] = array();
		$GLOBALS['mcpwp_test_filters'] = array();
		$GLOBALS['mcpwp_test_wpdb_rows'] = array();
		$GLOBALS['mcpwp_test_wpdb_insert_fails'] = false;
		$GLOBALS['_mcpwp_test_posts'] = array(
			10 => new WP_Post( array( 'ID' => 10, 'post_type' => 'product', 'post_status' => 'publish' ) ),
		);

		$this->set_static( 'contexts', null );
		$this->set_static( 'budgets', null );
		$this->set_static( 'active_contexts', array() );
	}

	private function set_static( string $name, $value ): void {
		$property = new ReflectionProperty( Mcpwp_WooCommerce_Audit::class, $name );
		$property->setAccessible( true );
		$property->setValue( null, $value );
	}

	private function rows(): array {
		return $GLOBALS['mcpwp_test_wpdb_rows']['wp_mcpwp_action_log'] ?? array();
	}

	private function request( string $method, string $route, array $body = array(), array $query = array() ): WP_REST_Request {
		$request = new WP_REST_Request( $method, $route );
		$request->set_body_params( $body );
		$request->set_query_params( $query );
		return $request;
	}

	/** @dataProvider writable_routes */
	public function test_explicit_catalogue_normalizes_every_accepted_write_door( string $method, string $route, string $tool ): void {
		$definition = Mcpwp_WooCommerce_Audit::route_definition( $this->request( $method, $route ) );
		$this->assertNotNull( $definition, $method . ' ' . $route . ' must be an explicitly declared WooCommerce write route.' );
		$this->assertSame( $tool, $definition['tool'] );
	}

	public static function writable_routes(): array {
		return array(
			'product create' => array( 'POST', '/mcpwp/v1/woocommerce/products', 'wc_create_product' ),
			'product post' => array( 'POST', '/mcpwp/v1/woocommerce/products/10', 'wc_update_product' ),
			'product patch and case' => array( 'PATCH', '/mcpwp/v1/WooCommerce/PRODUCTS/10/', 'wc_update_product' ),
			'product delete' => array( 'DELETE', '/mcpwp/v1/woocommerce/products/10', 'wc_delete_product' ),
			'category create' => array( 'POST', '/mcpwp/v1/woocommerce/products/categories', 'wc_create_product_category' ),
			'category post' => array( 'POST', '/mcpwp/v1/woocommerce/products/categories/10', 'wc_update_product_category' ),
			'order patch' => array( 'PATCH', '/mcpwp/v1/woocommerce/orders/10', 'wc_update_order' ),
			'archetype create' => array( 'POST', '/mcpwp/v1/woocommerce/archetypes', 'wc_create_product_archetype' ),
			'archetype direct update' => array( 'PUT', '/mcpwp/v1/woocommerce/archetypes/10', 'wc_update_product_archetype' ),
			'archetype apply' => array( 'POST', '/mcpwp/v1/woocommerce/archetypes/10/apply', 'wc_apply_product_archetype' ),
		);
	}

	public function test_catalogue_declares_the_complete_woocommerce_write_inventory(): void {
		$this->assertSame(
			array(
				'wc_create_product', 'wc_update_product', 'wc_delete_product',
				'wc_create_product_category', 'wc_update_product_category', 'wc_update_order',
				'wc_create_product_archetype', 'wc_update_product_archetype', 'wc_apply_product_archetype',
			),
			array_keys( Mcpwp_WooCommerce_Audit::catalog() )
		);
	}

	public function test_response_id_wins_over_route_body_and_query_ids(): void {
		$request = $this->request( 'PUT', '/mcpwp/v1/woocommerce/products/10', array( 'id' => 23, 'name' => 'Changed' ), array( 'id' => 24 ) );
		$context = Mcpwp_WooCommerce_Audit::new_attempt( 'wc_update_product', $request->get_params(), 'key-1', $request );
		Mcpwp_WooCommerce_Audit::bind( $context, $request );
		Mcpwp_WooCommerce_Audit::execute( $context, static function () {
			return new WP_REST_Response( array( 'id' => 23, 'name' => 'Changed' ), 200 );
		}, 'rest' );

		$this->assertCount( 1, $this->rows() );
		$this->assertSame( '23', $this->rows()[0]['resource_id'] );
	}

	public function test_exact_request_context_avoids_duplicate_failure_and_keeps_nested_write(): void {
		$outer = $this->request( 'PUT', '/mcpwp/v1/woocommerce/products/10', array( 'id' => 10 ) );
		$outer_context = Mcpwp_WooCommerce_Audit::new_attempt( 'wc_update_product', $outer->get_params(), 'outer', $outer );
		Mcpwp_WooCommerce_Audit::bind( $outer_context, $outer );
		Mcpwp_WooCommerce_Audit::execute( $outer_context, static function () {
			return new WP_REST_Response( array( 'code' => 'not_found' ), 404 );
		}, 'rest' );
		// Permission/result adapters revisiting THIS request must finalize no second row.
		Mcpwp_WooCommerce_Audit::auth_result( $outer, new WP_Error( 'not_found', 'Missing', array( 'status' => 404 ) ), 'outer' );

		$nested = $this->request( 'PUT', '/mcpwp/v1/woocommerce/products/23', array( 'id' => 23 ) );
		$nested_context = Mcpwp_WooCommerce_Audit::new_attempt( 'wc_update_product', $nested->get_params(), 'nested', $nested );
		Mcpwp_WooCommerce_Audit::bind( $nested_context, $nested );
		Mcpwp_WooCommerce_Audit::execute( $nested_context, static function () {
			return new WP_REST_Response( array( 'id' => 23 ), 200 );
		}, 'rest' );

		$this->assertCount( 2, $this->rows() );
		$this->assertSame( array( '10', '23' ), array_column( $this->rows(), 'resource_id' ) );
		$this->assertSame( array( 0, 1 ), array_column( $this->rows(), 'success' ) );
	}

	public function test_actor_is_copied_to_a_scoped_batch_child_context(): void {
		$controller = new Mcpwp_REST_MCP();
		$key = $controller->create_scoped_api_key( 'batch writer', array( 'write' ) );
		$request = new WP_REST_Request( 'PUT', '/mcpwp/v1/woocommerce/products/10', array( 'id' => 10 ), array( 'X-API-Key' => $key['key'] ) );
		$previous = Mcpwp_REST_Batch::$is_batch_sub_request;
		Mcpwp_REST_Batch::$is_batch_sub_request = true;
		try {
			$this->assertTrue( $controller->verify_api_key( $request ) );
		} finally {
			Mcpwp_REST_Batch::$is_batch_sub_request = $previous;
		}
		$this->assertSame( $key['id'], $controller->get_audit_key_id(), 'The batch fast path must preserve the verified scoped-key identity.' );
		$context = Mcpwp_WooCommerce_Audit::new_attempt( 'wc_update_product', $request->get_params(), $controller->get_audit_key_id(), $request );
		Mcpwp_WooCommerce_Audit::bind( $context, $request );
		Mcpwp_WooCommerce_Audit::execute( $context, static function () {
			return new WP_REST_Response( array( 'id' => 10 ), 200 );
		}, 'rest' );
		$this->assertSame( $key['id'], $this->rows()[0]['api_key_id'] );
	}

	public function test_redaction_decodes_scalar_wrappers_and_masks_free_text(): void {
		$arguments = array(
			'sku' => 'password=Hunter2!! / jane@example.test',
			'payload' => array( 'cvv' => '123', 'clientSecret' => 'xyz', 'apiKey' => 'abc' ),
			'blob_json' => '"sk-proj-FAKE0123456789abcdefghijklmnop"',
			'blob_serialized' => 's:38:"sk-proj-FAKE0123456789abcdefghijklmnop";',
			'url_encoded' => rawurlencode( '{"password":"short"}' ),
		);
		$redacted = Mcpwp_WooCommerce_Audit::redact_arguments( $arguments );
		$encoded = wp_json_encode( $redacted );
		foreach ( array( 'Hunter2', 'jane@example.test', '"123"', '"xyz"', '"abc"', 'sk-proj-', 'short' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $encoded );
		}
		$this->assertSame( '[redacted]', $redacted['sku'] );
	}

	public function test_admission_precedes_receipts_and_caps_an_admitted_root_at_100(): void {
		$operations = array();
		for ( $index = 0; $index < 101; $index++ ) {
			$operations[] = array( 'method' => 'PUT', 'path' => '/woocommerce/products/' . $index, 'body' => array( 'id' => $index ) );
		}
		$called = false;
		$result = Mcpwp_WooCommerce_Audit::run_call(
			array( 'name' => 'wp_batch_update', 'arguments' => array( 'operations' => $operations ) ),
			$this->request( 'POST', '/mcpwp/v1/mcp' ),
			'',
			static function () use ( &$called ) {
				$called = true;
				return array( 'result' => array( 'isError' => true, 'structuredContent' => array( 'error' => array( 'code' => 'batch_too_large' ) ) ) );
			}
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertFalse( $called );
		$this->assertSame( array(), $this->rows(), 'A JSON-RPC batch rejected before admission must not write anonymous audit rows.' );

		$root_a = $this->request( 'POST', '/mcpwp/v1/mcp' );
		for ( $index = 0; $index < 100; $index++ ) {
			$request = $this->request( 'PUT', '/mcpwp/v1/woocommerce/products/' . $index, array( 'id' => $index ) );
			$context = Mcpwp_WooCommerce_Audit::new_attempt( 'wc_update_product', $request->get_params(), 'read-key', $root_a );
			$this->assertNotInstanceOf( WP_Error::class, $context );
			Mcpwp_WooCommerce_Audit::execute( $context, static function () {
				return new WP_REST_Response( array( 'code' => 'insufficient_scope' ), 403 );
			}, 'rest' );
		}
		$this->assertCount( 100, $this->rows() );
		$overflow = Mcpwp_WooCommerce_Audit::new_attempt( 'wc_update_product', array( 'id' => 101 ), 'read-key', $root_a );
		$this->assertInstanceOf( WP_Error::class, $overflow );
		$this->assertCount( 100, $this->rows() );

		$unauthenticated_root = $this->request( 'POST', '/mcpwp/v1/mcp' );
		$untrusted = Mcpwp_WooCommerce_Audit::new_attempt( 'wc_update_product', array( 'id' => 12 ), '', $unauthenticated_root );
		Mcpwp_WooCommerce_Audit::execute( $untrusted, static function () {
			return new WP_REST_Response( array( 'code' => 'insufficient_scope' ), 403 );
		}, 'rest' );
		$this->assertCount( 100, $this->rows(), 'An unauthenticated root never persists a receipt or consumes another root budget.' );

		$root_b = $this->request( 'POST', '/mcpwp/v1/mcp' );
		$fresh = Mcpwp_WooCommerce_Audit::new_attempt( 'wc_update_product', array( 'id' => 13 ), 'fresh-key', $root_b );
		$this->assertNotInstanceOf( WP_Error::class, $fresh, 'A long-lived process must not carry root A capacity into root B.' );
		Mcpwp_WooCommerce_Audit::execute( $fresh, static function () {
			return new WP_REST_Response( array( 'id' => 13 ), 200 );
		}, 'rest' );
		$this->assertCount( 101, $this->rows() );
	}

	public function test_validation_failure_resolves_verified_actor_without_running_the_handler(): void {
		$controller = new Mcpwp_REST_WooCommerce( null );
		$key = $controller->create_scoped_api_key( 'validation writer', array( 'write' ) );
		$request = new WP_REST_Request( 'PUT', '/mcpwp/v1/woocommerce/products/10', array( 'id' => 10 ), array( 'X-API-Key' => $key['key'] ) );
		$context = Mcpwp_WooCommerce_Audit::new_attempt( 'wc_update_product', $request->get_params(), '', $request );
		Mcpwp_WooCommerce_Audit::bind( $context, $request );
		$handler_ran = false;
		Mcpwp_WooCommerce_Audit::execute( $context, static function () use ( $request, $controller, &$handler_ran ) {
			$error = new WP_Error( 'rest_invalid_param', 'Invalid field.', array( 'status' => 400 ) );
			Mcpwp_WooCommerce_Audit::rest_result( $error, array( 'permission_callback' => array( $controller, 'check_permission' ) ), $request );
			$handler_ran = false;
			return $error;
		}, 'rest' );
		$this->assertFalse( $handler_ran );
		$this->assertCount( 1, $this->rows() );
		$this->assertSame( $key['id'], $this->rows()[0]['api_key_id'] );
		$this->assertSame( 'rest_invalid_param', $this->rows()[0]['error_code'] );
	}

	public function test_persisted_columns_are_bounded_and_denial_still_receives_one_row(): void {
		$too_long = str_repeat( '7', 256 );
		$request = $this->request( 'PUT', '/mcpwp/v1/woocommerce/products/' . $too_long, array( 'id' => $too_long, 'sku' => str_repeat( 'x', 70000 ) ) );
		$context = Mcpwp_WooCommerce_Audit::new_attempt( 'wc_update_product', $request->get_params(), str_repeat( 'a', 101 ), $request );
		Mcpwp_WooCommerce_Audit::execute( $context, static function () {
			return new WP_REST_Response( array( 'code' => 'insufficient_scope' ), 403 );
		}, 'rest' );
		$this->assertCount( 1, $this->rows() );
		$row = $this->rows()[0];
		$this->assertLessThanOrEqual( 100, strlen( $row['tool_name'] ) );
		$this->assertLessThanOrEqual( 100, strlen( $row['api_key_id'] ) );
		$this->assertLessThanOrEqual( 255, strlen( $row['resource_id'] ) );
		$this->assertLessThanOrEqual( 65536, strlen( $row['arguments'] ) );
		$this->assertSame( 0, $row['success'] );
	}

	public function test_denied_resource_id_cannot_store_credential_or_pii_prose(): void {
		$unsafe_id = 'sk-proj-FAKE0123456789abcdefghijklmnop / jane@example.test';
		$request = $this->request( 'PUT', '/mcpwp/v1/woocommerce/products/10', array( 'id' => $unsafe_id, 'sku' => 'password=Hunter2!!' ) );
		$context = Mcpwp_WooCommerce_Audit::new_attempt( 'wc_update_product', $request->get_params(), 'read-key', $request );
		Mcpwp_WooCommerce_Audit::execute( $context, static function () {
			return new WP_Error( 'insufficient_scope', 'Denied', array( 'status' => 403 ) );
		}, 'rest' );
		$this->assertCount( 1, $this->rows() );
		$this->assertSame( '', $this->rows()[0]['resource_id'], 'WooCommerce resource IDs are integer identifiers; invalid denied metadata must not be retained.' );
		$row = wp_json_encode( $this->rows()[0] );
		$this->assertStringNotContainsString( 'sk-proj-', $row );
		$this->assertStringNotContainsString( 'jane@example.test', $row );
		$this->assertStringNotContainsString( 'Hunter2', $row );
	}

	public function test_unknown_argument_key_cannot_become_stored_audit_metadata(): void {
		$untrusted_key = 'sk_proj_FAKE0123456789abcdefghijklmnop';
		$redacted = Mcpwp_WooCommerce_Audit::redact_arguments(
			array(
				'id' => 10,
				$untrusted_key => 'innocuous-value',
			)
		);
		$encoded = wp_json_encode( $redacted );
		$this->assertStringNotContainsString( $untrusted_key, $encoded );
		$this->assertStringNotContainsString( 'innocuous-value', $encoded );
		$this->assertSame( 10, $redacted['id'] );
	}

	public function test_batch_alias_and_webmcp_denials_expand_admitted_children_once(): void {
		$children = array(
			array( 'method' => 'PUT', 'path' => '/woocommerce/products/10', 'body' => array( 'id' => 10 ) ),
			array( 'method' => 'PATCH', 'path' => '/woocommerce/orders/11', 'body' => array( 'id' => 11 ) ),
		);
		foreach ( $children as $child ) {
			$params = array( 'name' => 'wp_batch_update', 'arguments' => array( 'operations' => array( $child ) ) );
			Mcpwp_WooCommerce_Audit::run_call( $params, $this->request( 'POST', '/mcpwp/v1/webmcp/call' ), 'webmcp:user:7', static function () {
				return array( 'result' => array( 'isError' => true, 'structuredContent' => array( 'error' => array( 'code' => 'webmcp_tool_not_allowed' ) ) ) );
			} );
		}
		$this->assertCount( 2, $this->rows() );
		$this->assertSame( array( 'wc_update_product', 'wc_update_order' ), array_column( $this->rows(), 'tool_name' ) );
		$this->assertSame( array( 'webmcp:user:7', 'webmcp:user:7' ), array_column( $this->rows(), 'api_key_id' ) );
	}

	public function test_replayed_batch_expands_one_replayed_receipt_per_woocommerce_child(): void {
		$params = array(
			'name' => 'wp_batch',
			'arguments' => array( 'operations' => array(
				array( 'method' => 'PUT', 'path' => '/woocommerce/products/10', 'body' => array( 'id' => 10 ) ),
				array( 'method' => 'PATCH', 'path' => '/woocommerce/orders/11', 'body' => array( 'id' => 11 ) ),
			) ),
		);
		Mcpwp_WooCommerce_Audit::run_call( $params, $this->request( 'POST', '/mcpwp/v1/mcp' ), 'replay-key', static function () {
			return array(
				'result' => array(
					'isError' => false,
					'_meta' => array( 'replayed' => true ),
					'structuredContent' => array(
						'results' => array(
							array( 'status' => 200, 'data' => array( 'id' => 10 ) ),
							array( 'status' => 200, 'data' => array( 'id' => 11 ) ),
						),
					),
				),
			);
		} );
		$this->assertCount( 2, $this->rows() );
		$this->assertSame( array( 'replayed', 'replayed' ), array_column( $this->rows(), 'error_code' ) );
		$this->assertSame( array( '10', '11' ), array_column( $this->rows(), 'resource_id' ) );
	}

	public function test_actual_webmcp_denial_receives_one_woocommerce_receipt(): void {
		$GLOBALS['mcpwp_test_options'] = array( 'mcpwp_settings' => array( 'webmcp_admin_enabled' => true ) );
		$GLOBALS['mcpwp_test_current_user'] = 7;
		$GLOBALS['wp_rest_auth_cookie'] = true;
		$GLOBALS['mcpwp_test_user_caps'] = array( 7 => array( 'edit_posts' => true, 'edit_pages' => true, 'edit_others_posts' => true ) );
		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/webmcp/call',
			array( 'name' => 'wc_update_product', 'arguments' => array( 'id' => 10, 'name' => 'Denied' ) ),
			array( 'Origin' => 'https://example.com', 'X-WP-Nonce' => 'test-nonce' ),
			array( 'name' => 'wc_update_product', 'arguments' => array( 'id' => 10, 'name' => 'Denied' ) )
		);
		$result = ( new Mcpwp_REST_WebMCP() )->handle_call( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'webmcp_tool_not_allowed', $result->get_error_code() );
		$this->assertCount( 1, $this->rows() );
		$this->assertSame( 'wc_update_product', $this->rows()[0]['tool_name'] );
		$this->assertSame( Mcpwp_WebMCP::principal_record( 7 )['id'], $this->rows()[0]['api_key_id'] );
		$this->assertSame( 0, $this->rows()[0]['success'] );
	}
	public function test_reusing_a_rest_request_for_a_second_dispatch_creates_a_new_attempt(): void {
		$request = $this->request( 'PUT', '/mcpwp/v1/woocommerce/products/10' );
		add_filter( 'rest_dispatch_request', static function ( $result, $inner ) {
			Mcpwp_WooCommerce_Audit::auth_result( $inner, true, 'verified-key' );
			return new WP_REST_Response( array( 'id' => 10 ), 200 );
		}, 10, 2 );
		Mcpwp_WooCommerce_Audit::rest_dispatch( null, null, $request );
		Mcpwp_WooCommerce_Audit::rest_dispatch( null, null, $request );
		$this->assertCount( 2, $this->rows() );
		Mcpwp_WooCommerce_Audit::auth_result( $request, new WP_Error( 'not_found', 'Denied' ), 'verified-key' );
		$this->assertCount( 2, $this->rows(), 'A permission recheck is not a third dispatch.' );
	}

}
