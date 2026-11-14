<?php
/**
 * Round-three #1037 regression coverage: WordPress route parity, user
 * principals, admission, deferred operations and catalogue completeness.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-action-log.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-woocommerce-audit.php';
require_once dirname( __DIR__ ) . '/includes/mcp/class-mcpwp-mcp-pro-tools.php';
require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-woocommerce.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-api.php';

if ( ! function_exists( 'rest_ensure_response' ) ) {
	function rest_ensure_response( $response ) {
		return $response instanceof WP_REST_Response ? $response : new WP_REST_Response( $response );
	}
}

final class WooCommerceAuditRound3Test extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options'] = array();
		$GLOBALS['mcpwp_test_transients'] = array();
		$GLOBALS['mcpwp_test_filters'] = array();
		$GLOBALS['mcpwp_test_wpdb_rows'] = array();
		$GLOBALS['_mcpwp_test_registered_routes'] = array();
		foreach ( array( 'contexts', 'budgets', 'auth_requests', 'active_contexts' ) as $name ) {
			if ( ! property_exists( Mcpwp_WooCommerce_Audit::class, $name ) ) {
				continue;
			}
			$property = new ReflectionProperty( Mcpwp_WooCommerce_Audit::class, $name );
			$property->setAccessible( true );
			$property->setValue( null, 'active_contexts' === $name ? array() : null );
		}
	}

	protected function tearDown(): void {
		$_POST = array();
		$GLOBALS['mcpwp_test_is_admin'] = false;
		$GLOBALS['mcpwp_test_doing_ajax'] = false;
		$GLOBALS['mcpwp_test_ajax_referer_valid'] = false;
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_user_caps'] = array();
		if ( class_exists( Mcpwp_Destructive_Gate::class ) ) {
			$property = new ReflectionProperty( Mcpwp_Destructive_Gate::class, 'applying_as_admin' );
			$property->setAccessible( true );
			$property->setValue( null, 0 );
		}
		if ( class_exists( Mcpwp_REST_MCP::class ) ) {
			Mcpwp_REST_MCP::reset_verdict_memo();
		}
	}

	private function request( string $method, string $route, array $params = array() ): WP_REST_Request {
		$request = new WP_REST_Request( $method, $route );
		$request->set_body_params( $params );
		return $request;
	}

	private function rows(): array {
		return $GLOBALS['mcpwp_test_wpdb_rows']['wp_mcpwp_action_log'] ?? array();
	}

	/** WordPress's route matcher accepts a final control byte before its $. */
	public function test_catalogue_matches_wordpress_control_terminated_routes(): void {
		foreach ( array( "\n", "\r", "\t", "\0", ' ' ) as $suffix ) {
			$route = '/mcpwp/v1/woocommerce/products/10' . $suffix;
			$wordpress_matches = 1 === preg_match( '@^/mcpwp/v1/woocommerce/products/(?P<id>\d+)$@i', $route );
			$auditor_matches = null !== Mcpwp_WooCommerce_Audit::route_definition( $this->request( 'PUT', $route ) );
			$this->assertSame( $wordpress_matches, $auditor_matches, bin2hex( $suffix ) . ' must have identical route matching.' );
		}
	}

	public function test_catalogue_covers_every_registered_write_tool_and_route(): void {
		$catalogue = array_keys( Mcpwp_WooCommerce_Audit::catalog() );
		$tools = new Mcpwp_MCP_Pro_Tools();
		$tool_writes = 0;
		foreach ( $tools->get_tool_map() as $name => $definition ) {
			if ( 0 === strpos( $name, 'wc_' ) && ! $tools->get_tool_annotations( $name )['readOnlyHint'] ) {
				$tool_writes++;
				$this->assertContains( $name, $catalogue, $name . ' is a registered WooCommerce write tool.' );
			}
		}
		$this->assertGreaterThan( 0, $tool_writes, 'Test premise: the Pro registry declares WooCommerce writes.' );

		$saved = $GLOBALS['_mcpwp_test_registered_routes'];
		$GLOBALS['_mcpwp_test_registered_routes'] = array();
		try {
			( new Mcpwp_REST_WooCommerce( null ) )->register_routes();
			$route_writes = 0;
			foreach ( $GLOBALS['_mcpwp_test_registered_routes'] as $registered ) {
				if ( false === strpos( $registered['route'], '/woocommerce/' ) ) {
					continue;
				}
				$args = (array) $registered['args'];
				$handlers = isset( $args['methods'] ) ? array( $args ) : $args;
				foreach ( $handlers as $handler ) {
					$methods = preg_split( '/[|, ]+/', (string) ( $handler['methods'] ?? '' ) );
					foreach ( array_intersect( array( 'POST', 'PUT', 'PATCH', 'DELETE' ), $methods ) as $method ) {
						$route_writes++;
						$route = '/mcpwp/v1' . preg_replace( '/\(\?P<[^>]+>[^)]+\)/', '10', $registered['route'] );
						$this->assertNotNull(
							Mcpwp_WooCommerce_Audit::route_definition( $this->request( $method, $route ) ),
							$method . ' ' . $route . ' is a registered WooCommerce write route.'
						);
					}
				}
			}
			$this->assertGreaterThan( 0, $route_writes, 'Test premise: WooCommerce registered write routes.' );
		} finally {
			$GLOBALS['_mcpwp_test_registered_routes'] = $saved;
		}
	}

	public function test_user_principals_are_explicit_for_trusted_admin_and_approval_apply(): void {
		if ( ! defined( 'DOING_AJAX' ) ) {
			define( 'DOING_AJAX', true );
		}
		$GLOBALS['mcpwp_test_is_admin'] = true;
		$GLOBALS['mcpwp_test_doing_ajax'] = true;
		$GLOBALS['mcpwp_test_current_user'] = 7;
		$GLOBALS['mcpwp_test_user_caps'][7] = array( 'activate_plugins' => true, 'edit_products' => true );
		$GLOBALS['_mcpwp_test_posts'][23] = new WP_Post( array( 'ID' => 23, 'post_type' => 'product', 'post_status' => 'publish' ) );
		$GLOBALS['mcpwp_test_ajax_referer_valid'] = true;
		$_POST['action'] = 'mcpwp_chat_execute_tool';
		$controller = new class extends Mcpwp_REST_API { public function register_routes() {} };
		$this->assertTrue( $controller->verify_api_key( $this->request( 'POST', '/mcpwp/v1/mcp' ) ) );
		$this->assertSame( 'wp-user:7', $controller->get_audit_key_id() );

		// The replay leg must not accidentally take the earlier AJAX branch.
		$_POST = array();
		$GLOBALS['mcpwp_test_doing_ajax'] = false;
		$GLOBALS['mcpwp_test_is_admin'] = false;
		$GLOBALS['mcpwp_test_ajax_referer_valid'] = false;
		$property = new ReflectionProperty( Mcpwp_Destructive_Gate::class, 'applying_as_admin' );
		$property->setAccessible( true );
		$property->setValue( null, 7 );
		$replay = new class extends Mcpwp_REST_API { public function register_routes() {} };
		$this->assertTrue( $replay->verify_api_key( $this->request( 'DELETE', '/mcpwp/v1/woocommerce/products/23' ) ) );
		$this->assertSame( 'wp-user:7', $replay->get_audit_key_id() );
	}

	public function test_deferred_write_is_not_success_and_retains_target(): void {
		$request = $this->request( 'DELETE', '/mcpwp/v1/woocommerce/products/23', array( 'id' => 23 ) );
		$context = Mcpwp_WooCommerce_Audit::new_attempt( 'wc_delete_product', $request->get_params(), 'wp-user:7', $request );
		Mcpwp_WooCommerce_Audit::execute( $context, static function () {
		return new WP_REST_Response( array( 'approval_required' => true, 'approval_id' => 'apr_fixture', 'status' => 'pending' ), 202 );
		}, 'rest' );
		$this->assertCount( 1, $this->rows() );
		$this->assertSame( 0, $this->rows()[0]['success'] );
		$this->assertSame( 'deferred', $this->rows()[0]['error_code'] );
		$this->assertSame( '23', $this->rows()[0]['resource_id'] );
	}

	public function test_throwing_rest_write_uses_matched_params_for_failed_target(): void {
		$request = $this->request( 'PUT', '/mcpwp/v1/woocommerce/products/10' );
		$context = Mcpwp_WooCommerce_Audit::new_attempt( 'wc_update_product', array(), 'key-7', $request );
		Mcpwp_WooCommerce_Audit::bind( $context, $request );
		try {
			Mcpwp_WooCommerce_Audit::execute( $context, static function () use ( $request ) {
				$request->set_param( 'id', 10 );
				Mcpwp_WooCommerce_Audit::capture_dispatch( null, $request, '/mcpwp/v1/woocommerce/products/(?P<id>\\d+)', array() );
				throw new RuntimeException( 'write happened before exception' );
			}, 'rest' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( 'write happened before exception', $error->getMessage() );
		}
		$this->assertCount( 1, $this->rows() );
		$this->assertSame( '10', $this->rows()[0]['resource_id'] );
		$this->assertSame( 0, $this->rows()[0]['success'] );
	}

	public function test_admission_refusal_logs_at_most_one_root_receipt(): void {
		$body = array();
		for ( $index = 0; $index < 100; $index++ ) {
			$body[] = array( 'jsonrpc' => '2.0', 'id' => $index, 'method' => 'tools/call', 'params' => array( 'name' => 'wc_update_product', 'arguments' => array( 'id' => 10 ) ) );
		}
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp' );
		$request->set_json_params( $body );
		Mcpwp_WooCommerce_Audit::auth_result( $request, new WP_Error( 'batch_exceeds_limit', 'Refused', array( 'status' => 413 ) ), 'read-key' );
		$this->assertLessThanOrEqual( 1, count( $this->rows() ) );
		Mcpwp_WooCommerce_Audit::auth_result( $request, new WP_Error( 'rate_limit_exceeded', 'Throttled', array( 'status' => 429 ) ), 'read-key' );
		$this->assertLessThanOrEqual( 1, count( $this->rows()), 'A post-429 batch cannot expand into child receipts.' );
	}

	public function test_executed_unknown_actor_is_a_receipt_and_site_health_failure(): void {
		$request = $this->request( 'PUT', '/mcpwp/v1/woocommerce/products/23', array( 'id' => 23 ) );
		$context = Mcpwp_WooCommerce_Audit::new_attempt( 'wc_update_product', $request->get_params(), '', $request );
		Mcpwp_WooCommerce_Audit::execute( $context, static function () {
			return new WP_REST_Response( array( 'id' => 23 ), 200 );
		}, 'rest' );
		$this->assertCount( 1, $this->rows() );
		$this->assertSame( 'unknown-actor', $this->rows()[0]['api_key_id'] );
		$this->assertSame( 'critical', Mcpwp_WooCommerce_Audit::site_health_test()['status'] );
	}

	public function test_generic_action_log_stays_at_main_prefix_boundary(): void {
		$this->assertNull( Mcpwp_Action_Log::begin( 'wp_get_signals', array( 'query' => 'private secret' ) ) );
		$this->assertNull( Mcpwp_Action_Log::begin( str_repeat( 'x', 101 ), array( 'db_password' => 'short' ) ) );
		$this->assertNull( Mcpwp_Action_Log::begin( 'not_a_real_tool', array( 'clientSecret' => 'abc' ) ) );
	}
}
