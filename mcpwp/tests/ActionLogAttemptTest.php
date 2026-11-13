<?php
/**
 * #1037 review: every door and every outcome has a bounded, redacted receipt.
 *
 * @package MCPWP
 */
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-action-log.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-option-access.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-license.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-batch.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-encryption.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-broker-auth.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-oauth-well-known.php';

final class ActionLogAttemptTest extends TestCase {
	protected function setUp(): void {
		foreach ( array( 'options', 'transients', 'filters', 'wpdb_rows', 'wpdb_options', 'dispatched_routes' ) as $name ) {
			$GLOBALS[ 'mcpwp_test_' . $name ] = array();
		}
		$GLOBALS['mcpwp_test_current_user'] = 2;
		$GLOBALS['mcpwp_test_wpdb_insert_fails'] = false;
		$GLOBALS['mcpwp_test_fs'] = new Mcpwp_Test_Fs_Stub( true, true, false, 'pro' );
		$license = new ReflectionProperty( Mcpwp_License::class, 'instance' );
		$license->setAccessible( true );
		$license->setValue( null, null );
		Mcpwp_REST_MCP::reset_verdict_memo();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['mcpwp_test_rest_response_override'], $GLOBALS['mcpwp_test_wpdb_insert_fail'] );
		$GLOBALS['mcpwp_test_filters'] = array();
		$GLOBALS['mcpwp_test_fs'] = null;
		$license = new ReflectionProperty( Mcpwp_License::class, 'instance' );
		$license->setAccessible( true );
		$license->setValue( null, null );
	}

	private function rows(): array {
		return $GLOBALS['mcpwp_test_wpdb_rows']['wp_mcpwp_action_log'] ?? array();
	}

	private function call( string $name, array $arguments, array $scopes = array( 'admin' ), array $meta = array() ): array {
		$controller = new Mcpwp_REST_MCP();
		$caps = new ReflectionProperty( Mcpwp_REST_MCP::class, 'active_capabilities' );
		$caps->setAccessible( true );
		$caps->setValue( $controller, array( 'woocommerce' => true ) );
		$key = $controller->create_scoped_api_key( 'attempt', $scopes );
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp', array(), array( 'X-API-Key' => $key['key'] ), array(
			'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
			'params' => array( 'name' => $name, 'arguments' => $arguments, '_meta' => $meta ),
		) );
		$verdict = $controller->verify_api_key( $request );
		if ( is_wp_error( $verdict ) ) {
			return array( 'result' => array( 'isError' => true ) );
		}
		$this->assertTrue( $verdict );
		return $controller->handle_mcp( $request )->get_data();
	}

	public function test_direct_rest_and_batch_subrequests_share_the_route_auditor(): void {
		add_filter( 'rest_dispatch_request', static function ( $pre, $request ) {
			Mcpwp_WooCommerce_Audit::auth_result( $request, true, 'verified-key' );
			return $pre;
		}, 10, 2 );
		$registry = new Mcpwp_MCP_Pro_Tools();
		$seen = array();
		foreach ( $registry->get_tool_map() as $name => $mapping ) {
			if ( 0 !== strpos( $name, 'wc_' ) || $registry->get_tool_annotations( $name )['readOnlyHint'] ) {
				continue;
			}
			foreach ( array( false, true ) as $batch ) {
				$GLOBALS['mcpwp_test_wpdb_rows'] = array();
				$GLOBALS['mcpwp_test_rest_response_override'] = array( 'id' => 91, 'product' => array( 'id' => 92 ) );
				$request = new WP_REST_Request( $mapping['method'], '/mcpwp/v1' . str_replace( '{id}', '41', $mapping['route'] ), array( 'name' => 'Changed' ) );
				$previous = Mcpwp_REST_Batch::$is_batch_sub_request;
				Mcpwp_REST_Batch::$is_batch_sub_request = $batch;
				try {
					$response = Mcpwp_WooCommerce_Audit::rest_dispatch( null, null, $request );
				} finally {
					Mcpwp_REST_Batch::$is_batch_sub_request = $previous;
				}
				$this->assertSame( 200, $response->get_status() );
				$rows = $this->rows();
				$this->assertCount( 1, $rows, $name . ( $batch ? ' batch' : ' direct' ) );
				$this->assertSame( $name, $rows[0]['tool_name'] );
				$this->assertSame( 'woocommerce', $rows[0]['category'] );
				$this->assertNotSame( '', $rows[0]['resource_id'] );
			}
			$seen[] = $name;
		}
		$this->assertCount( 8, $seen );
	}

	public function test_free_text_credentials_and_pii_are_withheld(): void {
		$this->call( 'wc_update_order', array( 'id' => 11, 'note' => 'Customer jane@example.test; password=Hunter2!!' ) );
		$args = $this->rows()[0]['arguments'];
		$this->assertStringNotContainsString( 'jane@example.test', $args );
		$this->assertStringNotContainsString( 'Hunter2', $args );
		$this->assertSame( '[redacted]', json_decode( $args, true )['note'] );
	}

	public function test_non_woocommerce_callback_keeps_main_logging_behavior(): void {
		add_filter( 'mcpwp_register_tools', static function ( $tools ) {
			$tools[] = array( 'name' => 'digid_throw', 'description' => 'Write then throw.', 'method' => 'POST', 'callback' => static function () {
				update_option( 'audit_exception_mutation', 1 );
				throw new RuntimeException( 'Do not store exception payloads' );
			} );
			return $tools;
		} );
		try {
			$this->call( 'digid_throw', array() );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'Do not store exception payloads', $e->getMessage() );
		}
		$this->assertSame( 1, get_option( 'audit_exception_mutation' ) );
		$rows = $this->rows();
		$this->assertCount( 0, $rows );
	}

	/** @dataProvider refusal_provider */
	public function test_refusals_are_logged_without_snapshots( string $mode ): void {
		$args = array( 'id' => 41, 'name' => 'Changed' );
		$scopes = array( 'admin' );
		if ( 'missing' === $mode ) { $args = array(); }
		if ( 'read' === $mode ) { $scopes = array( 'read' ); }
		if ( 'disabled' === $mode ) { update_option( 'mcpwp_disabled_tool_categories', array( 'woocommerce' ) ); }
		$response = $this->call( 'wc_update_product', $args, $scopes );
		$this->assertTrue( $response['result']['isError'] );
		$rows = $this->rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 0, $rows[0]['success'] );
		$this->assertNotSame( '', $rows[0]['api_key_id'] );
		$this->assertNotSame( '', $rows[0]['error_code'] );
		$this->assertNull( $rows[0]['before_snap'] );
		$this->assertSame( 0, $rows[0]['rollback_supported'] );
	}
	public static function refusal_provider(): array { return array( array( 'missing' ), array( 'read' ), array( 'disabled' ) ); }

	public function test_a_scope_refused_mcpwp_call_is_audited_as_the_inner_tool(): void {
		// mcpwp_call is rewritten onto the tool it names before the audit wrapper
		// opens its attempt, and the per-call scope refusal then runs inside it.
		$this->call( 'mcpwp_call', array(), array( 'read' ) );
		$this->assertSame( 0, count( $this->rows() ), 'A malformed wrapper names no tool: nothing to audit.' );
		$GLOBALS['mcpwp_test_wpdb_rows']['wp_mcpwp_action_log'] = array();
		$controller = new Mcpwp_REST_MCP();
		$caps = new ReflectionProperty( Mcpwp_REST_MCP::class, 'active_capabilities' );
		$caps->setAccessible( true );
		$caps->setValue( $controller, array( 'woocommerce' => true ) );
		$key = $controller->create_scoped_api_key( 'wrapped', array( 'read' ) );
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp', array(), array( 'X-API-Key' => $key['key'] ), array(
			'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
			'params' => array( 'name' => 'mcpwp_call', 'arguments' => array( 'name' => 'wc_update_product', 'arguments' => array( 'id' => 41, 'name' => 'Changed' ) ) ),
		) );
		$this->assertTrue( $controller->verify_api_key( $request ) );
		$data = $controller->handle_mcp( $request )->get_data();
		$this->assertTrue( $data['result']['isError'] );
		$rows = $this->rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'wc_update_product', $rows[0]['tool_name'] );
		$this->assertSame( 0, $rows[0]['success'] );
	}

	public function test_a_replay_gets_a_receipt_without_a_second_mutation(): void {
		// Same principal must be reused for the request_id cache.
		$controller = new Mcpwp_REST_MCP();
		$key = $controller->create_scoped_api_key( 'replay', array( 'admin' ) );
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp', array(), array( 'X-API-Key' => $key['key'] ), array(
			'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => array( 'name' => 'wp_create_page', 'arguments' => array( 'title' => 'Test' ), '_meta' => array( 'request_id' => 'audit-replay' ) ),
		) );
		$this->assertTrue( $controller->verify_api_key( $request ) );
		$controller->handle_mcp( $request );
		$second = $controller->handle_mcp( $request )->get_data();
		$this->assertTrue( $second['result']['_meta']['replayed'] );
		$this->assertCount( 1, $GLOBALS['mcpwp_test_dispatched_routes'] );
		$rows = $this->rows();
		$this->assertCount( 1, $rows, 'The generic log retains main behavior: replay adds no write.' );
	}

	/** @dataProvider uncertain_provider */
	public function test_non_woocommerce_custom_tools_keep_main_logging_boundary( string $mode ): void {
		add_filter( 'mcpwp_register_tools', static function ( $tools ) use ( $mode ) {
			$tool = array( 'name' => 'digid_uncertain', 'description' => 'Uncertain side effects.', 'destructive' => true );
			if ( 'callback' === $mode ) { $tool['callback'] = static function () { return array( 'id' => 88 ); }; }
			else { $tool['rest_path'] = '/digid/v1/uncertain'; if ( 'destructive-rest' === $mode ) { $tool['method'] = 'GET'; } else { $tool['destructive'] = false; } }
			$tools[] = $tool;
			return $tools;
		} );
		$this->call( 'digid_uncertain', array() );
		$this->assertCount( 0, $this->rows() );
	}
	public static function uncertain_provider(): array { return array( array( 'callback' ), array( 'destructive-rest' ), array( 'implicit-rest' ) ); }

	public function test_repeated_permission_checks_do_not_duplicate_a_refusal(): void {
		$request = new WP_REST_Request( 'PUT', '/mcpwp/v1/woocommerce/products/41' );
		$error = new WP_Error( 'insufficient_scope', 'Denied', array( 'status' => 403 ) );
		$context = Mcpwp_WooCommerce_Audit::new_attempt( 'wc_update_product', array( 'id' => 41 ), '', $request );
		Mcpwp_WooCommerce_Audit::bind( $context, $request );
		Mcpwp_WooCommerce_Audit::execute( $context, static function () use ( $request, $error ) {
			Mcpwp_WooCommerce_Audit::auth_result( $request, $error, 'verified-key' );
			return $error;
		}, 'rest' );
		Mcpwp_WooCommerce_Audit::auth_result( $request, $error, 'verified-key' );
		$this->assertCount( 1, $this->rows() );
		$this->assertSame( 'verified-key', $this->rows()[0]['api_key_id'] );
		$this->assertNull( $this->rows()[0]['before_snap'] );
	}

	public function test_internal_rest_dispatch_does_not_duplicate_the_mcp_receipt(): void {
		$GLOBALS['mcpwp_test_rest_response_override'] = array( 'id' => 41 );
		add_filter( 'rest_dispatch_request', static function ( $pre, $request ) {
			return Mcpwp_WooCommerce_Audit::rest_dispatch( $pre, null, $request );
		}, 10, 2 );
		add_filter( 'rest_dispatch_request', array( 'Mcpwp_WooCommerce_Audit', 'capture_dispatch' ), 20, 4 );
		$this->call( 'wc_update_product', array( 'id' => 41, 'name' => 'Changed' ) );
		$this->assertCount( 1, $this->rows() );
		$this->assertSame( '41', $this->rows()[0]['resource_id'] );
	}

	public function test_batch_auth_refusal_records_each_admitted_write_child_once(): void {
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/batch', array( 'operations' => array(
			array( 'method' => 'PUT', 'path' => '/woocommerce/products/41', 'body' => array( 'name' => 'Denied' ) ),
			array( 'method' => 'PUT', 'path' => '/woocommerce/orders/42', 'body' => array( 'status' => 'completed' ) ),
		) ) );
		$error = new WP_Error( 'insufficient_scope', 'Denied', array( 'status' => 403 ) );
		Mcpwp_WooCommerce_Audit::auth_result( $request, $error, 'verified-key' );
		Mcpwp_WooCommerce_Audit::auth_result( $request, $error, 'verified-key' );
		$rows = $this->rows();
		$this->assertCount( 2, $rows );
		$this->assertSame( array( '41', '42' ), array_column( $rows, 'resource_id' ) );
		$this->assertSame( array( 0, 0 ), array_column( $rows, 'success' ) );
		$this->assertSame( array( null, null ), array_column( $rows, 'before_snap' ) );
	}

	/** @dataProvider bearer_provider */
	public function test_verified_bearer_scope_refusals_keep_their_principal( string $kind ): void {
		$controller = new Mcpwp_REST_MCP();
		if ( 'oauth' === $kind ) {
			update_option( 'mcpwp_settings', array( 'oauth_enabled' => true ) );
			$token = $controller->issue_oauth_access_token( array( 'read' ), 3600 )['access_token'];
			$expected_id = 'oauth:' . substr( hash( 'sha256', $token ), 0, 16 );
		} else {
			update_option( 'mcpwp_broker_enrollment', array( 'site_id' => 'site-123', 'audience' => 'https://example.test', 'site_credential' => 'unused' ) );
			$token = 'cached-read-broker';
			set_transient( 'mcpwp_bt_' . hash( 'sha256', $token . '|site-123|https://example.test' ), array( 'scope' => array( 'read' ), 'sub' => 'cache-read', 'site_id' => 'site-123', 'aud' => 'https://example.test', 'exp' => time() + 60 ), 60 );
			$expected_id = 'broker:cache-read';
		}
		$request = new WP_REST_Request( 'PUT', '/mcpwp/v1/woocommerce/products/41', array( 'id' => 41 ), array( 'Authorization' => 'Bearer ' . $token ) );
		$error = $controller->verify_api_key( $request );
		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'insufficient_scope', $error->get_error_code() );
		$this->assertCount( 1, $this->rows() );
		$this->assertSame( $expected_id, $this->rows()[0]['api_key_id'] );
		// The cached verdict must carry audit identity to a different controller.
		$other = new Mcpwp_REST_MCP();
		$this->assertInstanceOf( WP_Error::class, $other->verify_api_key( $request ) );
		$this->assertSame( $expected_id, $other->get_audit_key_id() );
		$this->assertCount( 1, $this->rows() );
	}
	public static function bearer_provider(): array { return array( array( 'oauth' ), array( 'broker' ) ); }

	public function test_invalid_after_valid_does_not_inherit_an_audit_principal(): void {
		$controller = new Mcpwp_REST_MCP();
		$key = $controller->create_scoped_api_key( 'verified', array( 'admin' ) );
		$valid = new WP_REST_Request( 'GET', '/mcpwp/v1/site-info', array(), array( 'X-API-Key' => $key['key'] ) );
		$this->assertTrue( $controller->verify_api_key( $valid ) );
		$this->assertSame( $key['id'], $controller->get_audit_key_id() );
		$invalid = new WP_REST_Request( 'PUT', '/mcpwp/v1/woocommerce/products/41', array( 'id' => 41 ), array( 'X-API-Key' => 'mcpwp_invalid_fixture' ) );
		$this->assertInstanceOf( WP_Error::class, $controller->verify_api_key( $invalid ) );
		$this->assertCount( 0, $this->rows() );
		$this->assertSame( '', $controller->get_audit_key_id() );
	}

	public function test_insert_failure_is_visible_in_site_health(): void {
		$GLOBALS['mcpwp_test_wpdb_insert_fail']['wp_mcpwp_action_log'] = true;
		$this->call( 'wc_update_order', array( 'id' => 11, 'status' => 'completed' ) );
		$this->assertSame( 'critical', Mcpwp_WooCommerce_Audit::site_health_test()['status'] );
		$this->assertNotEmpty( get_option( 'mcpwp_action_log_last_failure' ) );
		unset( $GLOBALS['mcpwp_test_wpdb_insert_fail'] );
		$this->call( 'wc_update_order', array( 'id' => 11, 'status' => 'completed' ) );
		$this->assertSame( 'good', Mcpwp_WooCommerce_Audit::site_health_test()['status'] );
	}

	public function test_argument_json_is_capped_and_remains_valid(): void {
		$log = Mcpwp_WooCommerce_Audit::new_attempt( 'wc_update_product', array( 'id' => 41, 'note' => str_repeat( 'growth ', 100000 ) ), 'verified-key' );
		Mcpwp_WooCommerce_Audit::execute( $log, static function () { return new WP_REST_Response( array( 'id' => 41 ), 200 ); }, 'rest' );
		$args = $this->rows()[0]['arguments'];
		$this->assertLessThanOrEqual( 65536, strlen( $args ) );
		$this->assertSame( '[redacted]', json_decode( $args, true )['note'] );
	}
}
