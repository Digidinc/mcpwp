<?php

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-encryption.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-broker-auth.php';

/**
 * Cached introspection validator for mumcp.com broker-issued Bearer tokens.
 *
 * Plan 2 / Task 3: Mcpwp_Broker_Auth::validate_token() is the sole chokepoint
 * a future Bearer-auth branch (Task 4) will call. It must:
 *   - kill-switch instantly (no network call) when the site isn't enrolled
 *   - serve a cached positive result on repeat calls within the TTL window
 *   - fail closed (401) on any transport error, non-200, or active:false,
 *     and never cache a negative result
 *   - enforce audience + site_id match (403) even on an otherwise-active token
 *   - never let scope_to_capability() admit 'admin'
 *
 * @package MCPWP
 */
final class BrokerAuthTest extends TestCase {

	/**
	 * Leaves no pre_http_request mock behind for the next class. See #757 —
	 * cleaning up only in setUp leaves the last test's mock live for whoever
	 * runs next, which under --order-by=random is a different file each run.
	 */
	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_filters'] = array();
	}

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']    = array();
		$GLOBALS['mcpwp_test_transients'] = array();
		$GLOBALS['mcpwp_test_filters']    = array();

		$ref      = new ReflectionClass( Mcpwp_Encryption::class );
		$instance = $ref->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );
	}

	/**
	 * Write mcpwp_broker_enrollment with an ENCRYPTED site_credential, as the
	 * real enrollment flow (Task 2/5) would.
	 */
	private function enroll( array $overrides = array() ): void {
		$encrypted = Mcpwp_Encryption::get_instance()->encrypt( 'super-secret-cred' );

		update_option(
			'mcpwp_broker_enrollment',
			array_merge(
				array(
					'issuer'                 => 'https://mumcp.com',
					'site_id'                => 'site-123',
					'audience'               => 'https://example.test',
					'introspection_endpoint' => 'https://mumcp.com/oauth/introspect',
					'site_credential'        => $encrypted,
				),
				$overrides
			)
		);
	}

	/**
	 * Register a pre_http_request mock and return the captured-calls store
	 * (same ArrayObject pattern as FigmaDesignTokensTest/CloudflareProviderTest).
	 *
	 * @return ArrayObject<int, array{url:string, method:string}>
	 */
	private function mockIntrospection( $response ): ArrayObject {
		$captured = new ArrayObject();
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $captured, $response ) {
				$captured[] = array(
					'url'    => $url,
					'method' => $args['method'],
					'args'   => $args,
				);
				return $response;
			},
			10,
			3
		);
		return $captured;
	}

	private function activeResponse( array $overrides = array() ): array {
		$body = array_merge(
			array(
				'active'   => true,
				'scope'    => array( 'read', 'write' ),
				'sub'      => 'user-42',
				'site_id'  => 'site-123',
				'aud'      => 'https://example.test',
				'exp'      => time() + 120,
			),
			$overrides
		);

		return array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode( $body ),
		);
	}

	// ── Kill-switch ──────────────────────────────────────────────────────

	public function test_not_enrolled_rejects_with_no_network_call(): void {
		$captured = $this->mockIntrospection( $this->activeResponse() );

		$auth   = new Mcpwp_Broker_Auth();
		$result = $auth->validate_token( 'opaque-token' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'broker_not_enrolled', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
		$this->assertCount( 0, $captured, 'Kill-switch must not make any network call.' );
	}

	public function test_empty_enrollment_array_is_kill_switched(): void {
		update_option( 'mcpwp_broker_enrollment', array() );
		$captured = $this->mockIntrospection( $this->activeResponse() );

		$auth   = new Mcpwp_Broker_Auth();
		$result = $auth->validate_token( 'opaque-token' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'broker_not_enrolled', $result->get_error_code() );
		$this->assertCount( 0, $captured );
	}

	public function test_is_enrolled_requires_site_id_credential_and_audience(): void {
		$auth = new Mcpwp_Broker_Auth();
		$this->assertFalse( $auth->is_enrolled() );

		update_option( 'mcpwp_broker_enrollment', array( 'site_id' => 'x' ) );
		$this->assertFalse( $auth->is_enrolled() );

		$this->enroll();
		$this->assertTrue( $auth->is_enrolled() );
	}

	// ── Happy path + positive cache ──────────────────────────────────────

	public function test_active_write_token_returns_normalized_result(): void {
		$this->enroll();
		$this->mockIntrospection( $this->activeResponse() );

		$auth   = new Mcpwp_Broker_Auth();
		$result = $auth->validate_token( 'opaque-token' );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'read', 'write' ), $result['scope'] );
		$this->assertSame( 'user-42', $result['sub'] );
		$this->assertSame( 'site-123', $result['site_id'] );
		$this->assertSame( 'https://example.test', $result['aud'] );

		$key = 'mcpwp_bt_' . hash( 'sha256', 'opaque-token|site-123|https://example.test' );
		$this->assertIsArray( get_transient( $key ), 'A positive result must be cached.' );
	}

	public function test_cache_hit_makes_no_second_network_call(): void {
		$this->enroll();
		$captured = $this->mockIntrospection( $this->activeResponse() );

		$auth = new Mcpwp_Broker_Auth();
		$auth->validate_token( 'opaque-token' );
		$this->assertCount( 1, $captured );

		$auth->validate_token( 'opaque-token' );
		$this->assertCount( 1, $captured, 'A cache hit must not trigger a second introspection call.' );
	}

	public function test_ttl_is_bounded_to_sixty_seconds(): void {
		$this->enroll();
		$this->mockIntrospection( $this->activeResponse( array( 'exp' => time() + 100000 ) ) );

		$auth = new Mcpwp_Broker_Auth();
		$auth->validate_token( 'opaque-token' );

		$key  = 'mcpwp_bt_' . hash( 'sha256', 'opaque-token|site-123|https://example.test' );
		$item = $GLOBALS['mcpwp_test_transients'][ $key ];
		$this->assertLessThanOrEqual( time() + 60, $item['expires'] );
		$this->assertGreaterThan( time(), $item['expires'] );
	}

	public function test_ttl_floor_is_at_least_one_second(): void {
		$this->enroll();
		// exp already in the past would floor to <=0; must still cache >=1s.
		$this->mockIntrospection( $this->activeResponse( array( 'exp' => time() - 5 ) ) );

		$auth   = new Mcpwp_Broker_Auth();
		$result = $auth->validate_token( 'opaque-token' );

		$this->assertIsArray( $result );
		$key  = 'mcpwp_bt_' . hash( 'sha256', 'opaque-token|site-123|https://example.test' );
		$item = $GLOBALS['mcpwp_test_transients'][ $key ];
		$this->assertGreaterThanOrEqual( time(), $item['expires'] );
	}

	// ── Fail-closed paths ──────────────────────────────────────────────

	public function test_active_false_is_401_and_not_cached(): void {
		$this->enroll();
		$this->mockIntrospection(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'active' => false ) ),
			)
		);

		$auth   = new Mcpwp_Broker_Auth();
		$result = $auth->validate_token( 'opaque-token' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'broker_token_invalid', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );

		$key = 'mcpwp_bt_' . hash( 'sha256', 'opaque-token|site-123|https://example.test' );
		$this->assertFalse( get_transient( $key ), 'Negative results must never be cached.' );
	}

	public function test_http_500_fails_closed_and_is_not_cached(): void {
		$this->enroll();
		$this->mockIntrospection(
			array(
				'response' => array( 'code' => 500 ),
				'body'     => wp_json_encode( array( 'error' => 'server_error' ) ),
			)
		);

		$auth   = new Mcpwp_Broker_Auth();
		$result = $auth->validate_token( 'opaque-token' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'broker_token_invalid', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );

		$key = 'mcpwp_bt_' . hash( 'sha256', 'opaque-token|site-123|https://example.test' );
		$this->assertFalse( get_transient( $key ) );
	}

	public function test_wp_error_transport_failure_fails_closed(): void {
		$this->enroll();
		$this->mockIntrospection( new WP_Error( 'http_request_failed', 'Could not resolve host' ) );

		$auth   = new Mcpwp_Broker_Auth();
		$result = $auth->validate_token( 'opaque-token' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'broker_token_invalid', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );

		$key = 'mcpwp_bt_' . hash( 'sha256', 'opaque-token|site-123|https://example.test' );
		$this->assertFalse( get_transient( $key ) );
	}

	public function test_malformed_json_body_fails_closed(): void {
		$this->enroll();
		$this->mockIntrospection(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => 'not json {{{',
			)
		);

		$auth   = new Mcpwp_Broker_Auth();
		$result = $auth->validate_token( 'opaque-token' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'broker_token_invalid', $result->get_error_code() );
	}

	// ── Audience / site_id enforcement ──────────────────────────────────

	public function test_audience_mismatch_is_403(): void {
		$this->enroll();
		$this->mockIntrospection( $this->activeResponse( array( 'aud' => 'https://someone-else.test' ) ) );

		$auth   = new Mcpwp_Broker_Auth();
		$result = $auth->validate_token( 'opaque-token' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'broker_audience_mismatch', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );

		$key = 'mcpwp_bt_' . hash( 'sha256', 'opaque-token|site-123|https://example.test' );
		$this->assertFalse( get_transient( $key ), 'An audience mismatch must not be cached as valid.' );
	}

	public function test_site_id_mismatch_is_403(): void {
		$this->enroll();
		$this->mockIntrospection( $this->activeResponse( array( 'site_id' => 'someone-elses-site' ) ) );

		$auth   = new Mcpwp_Broker_Auth();
		$result = $auth->validate_token( 'opaque-token' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'broker_audience_mismatch', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	// ── Introspection request shape ──────────────────────────────────────

	public function test_introspection_request_uses_basic_auth_and_form_body(): void {
		$this->enroll();
		$captured = $this->mockIntrospection( $this->activeResponse() );

		$auth = new Mcpwp_Broker_Auth();
		$auth->validate_token( 'opaque-token' );

		$this->assertCount( 1, $captured );
		$call = $captured[0];
		$this->assertSame( 'https://mumcp.com/oauth/introspect', $call['url'] );
		$this->assertSame( 'POST', $call['method'] );
		$this->assertSame( 'token=opaque-token', $call['args']['body'] );

		$expected_auth = 'Basic ' . base64_encode( 'site-123:super-secret-cred' );
		$this->assertSame( $expected_auth, $call['args']['headers']['Authorization'] );
	}

	// ── scope_to_capability() ───────────────────────────────────────────

	public function test_scope_to_capability_write(): void {
		$auth = new Mcpwp_Broker_Auth();
		$this->assertSame( 'write', $auth->scope_to_capability( array( 'write' ) ) );
		$this->assertSame( 'write', $auth->scope_to_capability( array( 'read', 'write' ) ) );
	}

	public function test_scope_to_capability_read(): void {
		$auth = new Mcpwp_Broker_Auth();
		$this->assertSame( 'read', $auth->scope_to_capability( array( 'read' ) ) );
		$this->assertSame( 'read', $auth->scope_to_capability( array() ) );
	}

	public function test_scope_to_capability_never_admits_admin(): void {
		$auth = new Mcpwp_Broker_Auth();
		$this->assertSame( 'read', $auth->scope_to_capability( array( 'admin' ) ) );

		// Even with write present alongside admin, the result is never the
		// literal string 'admin' — defense in depth per the broker contract,
		// which never issues admin in the first place.
		$this->assertNotSame( 'admin', $auth->scope_to_capability( array( 'admin', 'write' ) ) );
	}
}
