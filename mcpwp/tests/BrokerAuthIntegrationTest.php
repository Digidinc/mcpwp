<?php

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-encryption.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-broker-auth.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-oauth-well-known.php';

/** Harness exposing the auth trait for direct verify_api_key() calls. */
class Mcpwp_Broker_Integration_Auth_Harness {
	use Mcpwp_Api_Auth;
}

/**
 * Plan 2 / Task 4 — broker Bearer-token validation wired into verify_api_key().
 *
 * A valid mumcp.com broker Bearer token must authenticate a request the same
 * way an X-API-Key does, capped at read/write scope — NEVER admin. This is
 * the integration that makes claude.ai sign-in work end to end:
 *   Mcpwp_Broker_Auth::validate_token() (Task 3) → verify_api_key() Bearer
 *   branch (this task) → the same $key_record shape/scope enforcement/
 *   self-approval identity every other auth path already uses.
 *
 * @package MCPWP
 */
final class BrokerAuthIntegrationTest extends TestCase {

	/**
	 * Leaves no pre_http_request mock behind for the next class. See #757 —
	 * cleaning up only in setUp leaves the last test's mock live for whoever
	 * runs next, which under --order-by=random is a different file each run.
	 */
	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_filters'] = array();
	}

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_is_admin']     = false;
		$GLOBALS['mcpwp_test_filters']      = array();

		$ref      = new ReflectionClass( Mcpwp_Encryption::class );
		$instance = $ref->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );
	}

	/** Enroll the site with an ENCRYPTED site_credential, as the real enrollment flow would. */
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

	/** Register a pre_http_request mock and return the captured-calls store. */
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
				'active'  => true,
				'scope'   => array( 'write' ),
				'sub'     => 'user-42',
				'site_id' => 'site-123',
				'aud'     => 'https://example.test',
				'exp'     => time() + 120,
			),
			$overrides
		);

		return array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode( $body ),
		);
	}

	// ── Valid write token ────────────────────────────────────────────────

	public function test_valid_write_token_authenticates_and_grants_write(): void {
		$this->enroll();
		$this->mockIntrospection( $this->activeResponse( array( 'scope' => array( 'write' ) ) ) );

		$auth    = new Mcpwp_Broker_Integration_Auth_Harness();
		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/posts',
			array(),
			array( 'Authorization' => 'Bearer opaque-write-token' )
		);

		$result = $auth->verify_api_key( $request );

		$this->assertTrue( $result );

		$record = $auth->get_current_api_key_record();
		$this->assertIsArray( $record );
		$this->assertSame( 'broker:user-42', $record['id'] );
		$this->assertSame( 'broker', $record['type'] );
		$this->assertContains( 'write', $record['scopes'] );
		$this->assertContains( 'read', $record['scopes'] );
		$this->assertNotContains( 'admin', $record['scopes'] );
	}

	public function test_write_token_can_see_non_public_content(): void {
		$this->enroll();
		$this->mockIntrospection( $this->activeResponse( array( 'scope' => array( 'write' ) ) ) );

		$auth    = new Mcpwp_Broker_Integration_Auth_Harness();
		$request = new WP_REST_Request(
			'GET',
			'/mcpwp/v1/pages',
			array(),
			array( 'Authorization' => 'Bearer opaque-write-token' )
		);

		$this->assertTrue( $auth->verify_api_key( $request ) );

		$method = new ReflectionMethod( Mcpwp_Broker_Integration_Auth_Harness::class, 'requester_can_see_non_public' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( $auth ), 'A write-scoped broker token must be able to see non-public content.' );
	}

	// ── Valid read token ─────────────────────────────────────────────────

	public function test_valid_read_token_authenticates_read_requests(): void {
		$this->enroll();
		$this->mockIntrospection( $this->activeResponse( array( 'scope' => array( 'read' ) ) ) );

		$auth    = new Mcpwp_Broker_Integration_Auth_Harness();
		$request = new WP_REST_Request(
			'GET',
			'/mcpwp/v1/site-info',
			array(),
			array( 'Authorization' => 'Bearer opaque-read-token' )
		);

		$this->assertTrue( $auth->verify_api_key( $request ) );

		$record = $auth->get_current_api_key_record();
		$this->assertSame( array( 'read' ), $record['scopes'] );
	}

	public function test_read_token_cannot_write(): void {
		$this->enroll();
		$this->mockIntrospection( $this->activeResponse( array( 'scope' => array( 'read' ) ) ) );

		$auth    = new Mcpwp_Broker_Integration_Auth_Harness();
		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/posts',
			array(),
			array( 'Authorization' => 'Bearer opaque-read-token' )
		);

		$result = $auth->verify_api_key( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'insufficient_scope', $result->get_error_code() );
	}

	public function test_read_token_cannot_see_non_public_content(): void {
		$this->enroll();
		$this->mockIntrospection( $this->activeResponse( array( 'scope' => array( 'read' ) ) ) );

		$auth    = new Mcpwp_Broker_Integration_Auth_Harness();
		$request = new WP_REST_Request(
			'GET',
			'/mcpwp/v1/pages',
			array(),
			array( 'Authorization' => 'Bearer opaque-read-token' )
		);

		$this->assertTrue( $auth->verify_api_key( $request ) );

		$method = new ReflectionMethod( Mcpwp_Broker_Integration_Auth_Harness::class, 'requester_can_see_non_public' );
		$method->setAccessible( true );

		$this->assertFalse( $method->invoke( $auth ), 'A read-scoped broker token must NOT be able to see non-public/draft content (#683 draft-leak model).' );
	}

	// ── Admin is impossible via broker, regardless of granted scope ───────

	public function test_write_token_denied_admin_scoped_route(): void {
		$this->enroll();
		$this->mockIntrospection( $this->activeResponse( array( 'scope' => array( 'write' ) ) ) );

		$auth    = new Mcpwp_Broker_Integration_Auth_Harness();
		// /mcpwp/v1/settings is a blanket-admin route — even GET requires admin scope.
		$request = new WP_REST_Request(
			'GET',
			'/mcpwp/v1/settings',
			array(),
			array( 'Authorization' => 'Bearer opaque-write-token' )
		);

		$result = $auth->verify_api_key( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'insufficient_scope', $result->get_error_code() );
	}

	public function test_read_token_denied_admin_scoped_route(): void {
		$this->enroll();
		$this->mockIntrospection( $this->activeResponse( array( 'scope' => array( 'read' ) ) ) );

		$auth    = new Mcpwp_Broker_Integration_Auth_Harness();
		$request = new WP_REST_Request(
			'GET',
			'/mcpwp/v1/settings',
			array(),
			array( 'Authorization' => 'Bearer opaque-read-token' )
		);

		$result = $auth->verify_api_key( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'insufficient_scope', $result->get_error_code() );
	}

	public function test_admin_scope_in_introspection_response_is_never_granted(): void {
		// Defense-in-depth: even if a compromised/future broker response somehow
		// included 'admin' in the scope array, scope_to_capability() maps it down
		// to 'read' and the constructed $key_record can never carry 'admin'.
		$this->enroll();
		$this->mockIntrospection( $this->activeResponse( array( 'scope' => array( 'admin', 'write' ) ) ) );

		$auth    = new Mcpwp_Broker_Integration_Auth_Harness();
		$request = new WP_REST_Request(
			'GET',
			'/mcpwp/v1/settings',
			array(),
			array( 'Authorization' => 'Bearer opaque-token' )
		);

		$result = $auth->verify_api_key( $request );

		$this->assertInstanceOf( WP_Error::class, $result, 'admin-scoped route must remain unreachable no matter what the broker response claims.' );

		$record = $auth->get_current_api_key_record();
		$this->assertNotContains( 'admin', $record['scopes'] );
	}

	// ── Kill-switch: not enrolled ──────────────────────────────────────────

	public function test_not_enrolled_falls_through_with_no_network_call(): void {
		$captured = $this->mockIntrospection( $this->activeResponse() );

		$auth    = new Mcpwp_Broker_Integration_Auth_Harness();
		$request = new WP_REST_Request(
			'GET',
			'/mcpwp/v1/site-info',
			array(),
			array( 'Authorization' => 'Bearer opaque-token' )
		);

		$result = $auth->verify_api_key( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'insufficient_scope', $result->get_error_code() );
		$this->assertCount( 0, $captured, 'An unenrolled site must never make a network call for a Bearer token.' );
	}

	// ── X-API-Key present: broker branch must be skipped, key path unaffected ──

	public function test_api_key_header_present_skips_broker_branch(): void {
		$this->enroll();
		$captured = $this->mockIntrospection( $this->activeResponse() );

		$auth    = new Mcpwp_Broker_Integration_Auth_Harness();
		$created = $auth->create_scoped_api_key( 'writer', array( 'write' ) );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/posts',
			array(),
			array(
				'X-API-Key'     => $created['key'],
				'Authorization' => 'Bearer some-broker-token',
			)
		);

		$result = $auth->verify_api_key( $request );

		$this->assertTrue( $result );
		$this->assertCount( 0, $captured, 'X-API-Key present must skip broker introspection entirely.' );

		$record = $auth->get_current_api_key_record();
		$this->assertSame( $created['id'], $record['id'], 'The matched scoped API key must be used, not a broker principal.' );
	}

	// ── Fail-closed: introspection error ──────────────────────────────────

	public function test_broker_validate_token_error_is_rejected(): void {
		$this->enroll();
		$this->mockIntrospection(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'active' => false ) ),
			)
		);

		$auth    = new Mcpwp_Broker_Integration_Auth_Harness();
		$request = new WP_REST_Request(
			'GET',
			'/mcpwp/v1/site-info',
			array(),
			array( 'Authorization' => 'Bearer opaque-token' )
		);

		$result = $auth->verify_api_key( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'broker_token_invalid', $result->get_error_code() );
	}

	public function test_audience_mismatch_is_rejected(): void {
		$this->enroll();
		$this->mockIntrospection( $this->activeResponse( array( 'aud' => 'https://someone-else.test' ) ) );

		$auth    = new Mcpwp_Broker_Integration_Auth_Harness();
		$request = new WP_REST_Request(
			'GET',
			'/mcpwp/v1/site-info',
			array(),
			array( 'Authorization' => 'Bearer opaque-token' )
		);

		$result = $auth->verify_api_key( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'broker_audience_mismatch', $result->get_error_code() );
	}

	// ── #683 self-approval identity invariant ─────────────────────────────

	public function test_broker_principal_id_is_distinct_namespace(): void {
		$this->enroll();
		$this->mockIntrospection( $this->activeResponse( array( 'scope' => array( 'write' ), 'sub' => '7' ) ) );

		$auth    = new Mcpwp_Broker_Integration_Auth_Harness();
		$request = new WP_REST_Request(
			'GET',
			'/mcpwp/v1/site-info',
			array(),
			array( 'Authorization' => 'Bearer opaque-token' )
		);

		$this->assertTrue( $auth->verify_api_key( $request ) );

		$record = $auth->get_current_api_key_record();
		// Distinct from oauth-user:7 / oauth-client:7 / scoped-key uuid — a broker
		// principal can never collide with (or be mistaken for) any other identity
		// namespace the self-approval gate (current_requester_key_id()) recognizes.
		$this->assertSame( 'broker:7', $record['id'] );
		$this->assertStringStartsWith( 'broker:', $record['id'] );
	}

	// ── 401 discovery header ───────────────────────────────────────────────

	public function test_www_authenticate_header_added_when_broker_enrolled_only(): void {
		$this->enroll();
		update_option( 'mcpwp_settings', array( 'oauth_enabled' => false ) );

		$response = new WP_REST_Response( array( 'code' => 'missing_api_key' ), 401 );
		$request  = new WP_REST_Request( 'GET', '/mcpwp/v1/mcp' );

		$result  = Mcpwp_OAuth_Well_Known::add_www_authenticate_header( $response, null, $request );
		$headers = $result->get_headers();

		$this->assertArrayHasKey( 'WWW-Authenticate', $headers );
		$this->assertStringContainsString( 'Bearer', $headers['WWW-Authenticate'] );
		$this->assertStringContainsString( 'resource_metadata=', $headers['WWW-Authenticate'] );
		$this->assertStringContainsString( '.well-known/oauth-protected-resource', $headers['WWW-Authenticate'] );
	}

	public function test_www_authenticate_header_absent_when_neither_broker_nor_legacy_oauth(): void {
		update_option( 'mcpwp_broker_enrollment', array() );
		update_option( 'mcpwp_settings', array( 'oauth_enabled' => false ) );

		$response = new WP_REST_Response( array( 'code' => 'missing_api_key' ), 401 );
		$request  = new WP_REST_Request( 'GET', '/mcpwp/v1/mcp' );

		$result  = Mcpwp_OAuth_Well_Known::add_www_authenticate_header( $response, null, $request );
		$headers = $result->get_headers();

		$this->assertArrayNotHasKey( 'WWW-Authenticate', $headers );
	}

	// ── Finding 2: local API key sent as Bearer must resolve LOCALLY (no egress) ──

	/**
	 * A VALID scoped API key sent via `Authorization: Bearer mcpwp_<key>` (no
	 * X-API-Key) on an ENROLLED site must authenticate through the LOCAL key
	 * path — NEVER shipped to mumcp.com as a broker token. The plugin accepts
	 * API keys via the Bearer header (get_api_key_from_request), so broker
	 * introspection must be a fallback that runs ONLY after no local key matched.
	 */
	public function test_local_api_key_via_bearer_header_resolves_locally_no_egress(): void {
		$this->enroll();
		$captured = $this->mockIntrospection( $this->activeResponse() );

		$auth    = new Mcpwp_Broker_Integration_Auth_Harness();
		$created = $auth->create_scoped_api_key( 'writer', array( 'write' ) );

		// Real API key on the Authorization: Bearer header, NO X-API-Key header.
		$request = new WP_REST_Request(
			'GET',
			'/mcpwp/v1/site-info',
			array(),
			array( 'Authorization' => 'Bearer ' . $created['key'] )
		);

		$result = $auth->verify_api_key( $request );

		$this->assertTrue( $result, 'A real API key on the Bearer header must authenticate locally on an enrolled site.' );
		$this->assertCount( 0, $captured, 'A valid local key must NEVER be sent to the broker as a token (no secret egress).' );

		$record = $auth->get_current_api_key_record();
		$this->assertSame( $created['id'], $record['id'], 'The matched scoped API key must be used, not a broker principal.' );
		$this->assertNotSame( 'broker', $record['type'] ?? '' );
		$this->assertContains( 'read', $record['scopes'] );
		$this->assertContains( 'write', $record['scopes'] );
	}

	// ── Finding 1: anonymous introspection floods are throttled before the network ──

	/**
	 * Repeated invalid Bearer tokens from one IP must be rate-limited BEFORE the
	 * outbound introspection network call. Once throttled, no further
	 * wp_remote_post is made — bounding worker exhaustion + amplification against
	 * the broker.
	 */
	public function test_anonymous_bearer_attempts_are_rate_limited_before_network(): void {
		$this->enroll();

		// Tight burst limit so the third attempt from one IP is throttled.
		update_option(
			'mcpwp_rate_limit_settings',
			array(
				'enabled'             => true,
				'requests_per_minute' => 100,
				'requests_per_hour'   => 1000,
				'burst_limit'         => 2,
				'whitelist'           => array(),
			)
		);
		$this->resetRateLimiterSingleton();

		// Invalid token (active:false) — each accepted attempt makes one network call.
		$captured = $this->mockIntrospection(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'active' => false ) ),
			)
		);

		$auth = new Mcpwp_Broker_Integration_Auth_Harness();

		$make = function () use ( $auth ) {
			return $auth->verify_api_key(
				new WP_REST_Request(
					'POST',
					'/mcpwp/v1/posts',
					array(),
					array( 'Authorization' => 'Bearer invalid-token' )
				)
			);
		};

		$r1 = $make();
		$r2 = $make();
		$calls_before_throttle = count( $captured );
		$r3 = $make();

		$this->assertSame( 2, $calls_before_throttle, 'First two attempts should each introspect once.' );
		$this->assertCount( 2, $captured, 'Once throttled, no further introspection network call may be made.' );

		$this->assertInstanceOf( WP_Error::class, $r3 );
		$this->assertSame( 'rate_limit_exceeded', $r3->get_error_code(), 'The throttled attempt must return the rate-limit error without a network call.' );

		// The first two attempts fail-closed on the invalid token (not a rate-limit error).
		$this->assertInstanceOf( WP_Error::class, $r1 );
		$this->assertSame( 'broker_token_invalid', $r1->get_error_code() );
	}

	private function resetRateLimiterSingleton(): void {
		$ref  = new ReflectionClass( Mcpwp_Rate_Limiter::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
	}
}
