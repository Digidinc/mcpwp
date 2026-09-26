<?php
/**
 * Adversarial review round 2 on #902/PR 972 — two findings on
 * handle_resources_read()'s internal sub-request:
 *
 * WARN: the credential was always re-sent as X-API-Key, even when the caller
 * authenticated with `Authorization: Bearer` (an OAuth access token or a
 * mumcp.com broker token). The broker fallback in trait-mcpwp-api-auth.php
 * only ever runs for a genuine Bearer header with no X-API-Key present, so
 * converting Bearer -> X-API-Key made a broker caller's own sub-request
 * unauthenticatable ("invalid_api_key" 401) even though its outer /mcp
 * request had already authenticated.
 *
 * NOTE: resources/read cost two rate-limit slots (outer /mcp + this inner
 * dispatch) for the same caller. tools/call avoids this by wrapping its own
 * internal dispatch in Mcpwp_REST_Batch::$is_batch_sub_request, which routes
 * the inner permission_callback to verify_api_key_auth_only() — auth, scope
 * and category checks still run; only the rate-limit charge is skipped.
 * verify_api_key_auth_only() had no OAuth or broker branch at all, so mirroring
 * that flag for resources/read would have "fixed" metering by breaking OAuth/
 * broker auth a second, different way (a bare "invalid_api_key" with no rate
 * bucket charged instead of one with a charge). The real fix adds unmetered
 * OAuth/broker branches to verify_api_key_auth_only() itself, mirroring
 * compute_api_key_verdict()'s ordering and identity handling exactly, only
 * skipping their meter_request() calls.
 *
 * This file tests the auth-trait fix directly (same harness pattern
 * BrokerAuthIntegrationTest / OAuthSelfApprovalTest / KeyCategoryRestrictionAuthPlaneTest
 * already use), since the shared PHPUnit rest_do_request() double does not
 * run a real permission_callback unless a test wires the rest_dispatch_request
 * filter — McpEndpointTest covers the header-carrying half (what
 * handle_resources_read() puts on the sub-request) through the full /mcp
 * dispatch instead.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-batch.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-encryption.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-broker-auth.php';

/** Harness exposing the auth trait, mirroring the sibling auth-plane tests. */
class Mcpwp_Resources_Read_Auth_Harness {
	use Mcpwp_Api_Auth;
}

final class ResourcesReadSubRequestAuthTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_is_admin']     = false;
		$GLOBALS['mcpwp_test_filters']      = array();

		update_option(
			'mcpwp_settings',
			array(
				'oauth_enabled' => true,
			)
		);

		$ref      = new ReflectionClass( Mcpwp_Encryption::class );
		$instance = $ref->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );
	}

	protected function tearDown(): void {
		if ( class_exists( 'Mcpwp_REST_Batch' ) ) {
			Mcpwp_REST_Batch::$is_batch_sub_request = false;
		}
		$GLOBALS['mcpwp_test_filters'] = array();

		if ( class_exists( 'Mcpwp_Rate_Limiter' ) ) {
			$instance = ( new ReflectionClass( Mcpwp_Rate_Limiter::class ) )->getProperty( 'instance' );
			$instance->setAccessible( true );
			$instance->setValue( null );
		}
	}

	private function harness(): Mcpwp_Resources_Read_Auth_Harness {
		return new Mcpwp_Resources_Read_Auth_Harness();
	}

	/**
	 * Burst-window counter set_transient() writes on a successful, metered
	 * check_limit(). The test double's transient store wraps every value as
	 * ['value' => ..., 'expires' => ..., 'ttl' => ...], so the actual payload
	 * check_limit() wrote is one level deeper than the raw set_transient()
	 * call site suggests.
	 */
	private function burst_count( string $identifier ): int {
		$name = 'mcpwp_rate_' . md5( $identifier . '_burst' );
		$slot = $GLOBALS['mcpwp_test_transients'][ $name ] ?? null;
		$data = is_array( $slot ) && array_key_exists( 'value', $slot ) ? $slot['value'] : $slot;

		return is_array( $data ) && isset( $data['count'] ) ? (int) $data['count'] : 0;
	}

	// ── Enrollment/introspection helpers, same shape as BrokerAuthIntegrationTest ──

	private function enrollBroker( array $overrides = array() ): void {
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

	private function mockBrokerIntrospection( array $body_overrides = array() ): void {
		$body = array_merge(
			array(
				'active'  => true,
				'scope'   => array( 'write' ),
				'sub'     => 'user-42',
				'site_id' => 'site-123',
				'aud'     => 'https://example.test',
				'exp'     => time() + 120,
			),
			$body_overrides
		);

		add_filter(
			'pre_http_request',
			function () use ( $body ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( $body ),
				);
			},
			10,
			3
		);
	}

	// ── WARN: all three credential types authenticate through the unmetered
	//    sub-request fast path (Mcpwp_REST_Batch::$is_batch_sub_request), and
	//    none of them charges the caller's rate-limit bucket a second time. ──

	public function test_scoped_api_key_authenticates_as_sub_request_without_metering(): void {
		$auth    = $this->harness();
		$created = $auth->create_scoped_api_key( 'k', array( 'read', 'write' ), 'admin' );

		Mcpwp_REST_Batch::$is_batch_sub_request = true;
		$result = $auth->verify_api_key(
			new WP_REST_Request( 'GET', '/mcpwp/v1/site-info', array(), array( 'X-API-Key' => $created['key'] ) )
		);

		$this->assertTrue( $result, 'A plain scoped API key must still authenticate as a sub-request.' );
		$this->assertSame(
			0,
			$this->burst_count( 'key:' . $created['id'] ),
			'The sub-request fast path must not charge the rate-limit bucket a second time.'
		);
	}

	public function test_oauth_access_token_authenticates_as_sub_request_without_metering(): void {
		$auth  = $this->harness();
		$token = $auth->issue_oauth_access_token( array( 'read', 'write' ), 3600, array( 'user_id' => 7 ) )['access_token'];

		Mcpwp_REST_Batch::$is_batch_sub_request = true;
		$result = $auth->verify_api_key(
			new WP_REST_Request( 'GET', '/mcpwp/v1/site-info', array(), array( 'Authorization' => 'Bearer ' . $token ) )
		);

		$this->assertTrue(
			$result,
			'An OAuth access token must authenticate as a sub-request — verify_api_key_auth_only() had no OAuth branch before this fix, so this previously returned invalid_api_key.'
		);

		$record = $auth->get_current_api_key_record();
		$this->assertIsArray( $record );
		$this->assertSame( 'oauth', $record['type'] );

		$this->assertSame(
			0,
			$this->burst_count( 'oauth:' . substr( hash( 'sha256', $token ), 0, 16 ) ),
			'The sub-request fast path must not charge the OAuth bucket.'
		);
	}

	public function test_broker_bearer_token_authenticates_as_sub_request_without_metering(): void {
		$this->enrollBroker();
		$this->mockBrokerIntrospection( array( 'scope' => array( 'write' ) ) );

		$auth  = $this->harness();
		$token = 'opaque-broker-token';

		Mcpwp_REST_Batch::$is_batch_sub_request = true;
		$result = $auth->verify_api_key(
			new WP_REST_Request( 'GET', '/mcpwp/v1/site-info', array(), array( 'Authorization' => 'Bearer ' . $token ) )
		);

		$this->assertTrue(
			$result,
			'A mumcp.com broker Bearer token must authenticate as a sub-request — verify_api_key_auth_only() had no broker branch before this fix, so this previously returned invalid_api_key.'
		);

		$record = $auth->get_current_api_key_record();
		$this->assertIsArray( $record );
		$this->assertSame( 'broker', $record['type'] );

		$this->assertSame(
			0,
			$this->burst_count( 'broker:' . substr( hash( 'sha256', $token ), 0, 16 ) ),
			'The sub-request fast path must not charge the broker bucket.'
		);
	}

	// ── Regression guard: the SAME three credential types, NOT as a
	//    sub-request, still meter exactly as before this fix. ──────────────

	public function test_scoped_api_key_still_meters_outside_a_sub_request(): void {
		$auth    = $this->harness();
		$created = $auth->create_scoped_api_key( 'k', array( 'read', 'write' ), 'admin' );

		$result = $auth->verify_api_key(
			new WP_REST_Request( 'GET', '/mcpwp/v1/site-info', array(), array( 'X-API-Key' => $created['key'] ) )
		);

		$this->assertTrue( $result );
		$this->assertSame( 1, $this->burst_count( 'key:' . $created['id'] ) );
	}

	public function test_oauth_access_token_still_meters_outside_a_sub_request(): void {
		$auth  = $this->harness();
		$token = $auth->issue_oauth_access_token( array( 'read', 'write' ), 3600, array( 'user_id' => 7 ) )['access_token'];

		$result = $auth->verify_api_key(
			new WP_REST_Request( 'GET', '/mcpwp/v1/site-info', array(), array( 'Authorization' => 'Bearer ' . $token ) )
		);

		$this->assertTrue( $result );
		$this->assertSame( 1, $this->burst_count( 'oauth:' . substr( hash( 'sha256', $token ), 0, 16 ) ) );
	}

	public function test_broker_bearer_token_still_meters_outside_a_sub_request(): void {
		$this->enrollBroker();
		$this->mockBrokerIntrospection( array( 'scope' => array( 'write' ) ) );

		$auth  = $this->harness();
		$token = 'opaque-broker-token-2';

		$result = $auth->verify_api_key(
			new WP_REST_Request( 'GET', '/mcpwp/v1/site-info', array(), array( 'Authorization' => 'Bearer ' . $token ) )
		);

		$this->assertTrue( $result );
		$this->assertSame( 1, $this->burst_count( 'broker:' . substr( hash( 'sha256', $token ), 0, 16 ) ) );
	}

	// ── "Don't skip auth — only avoid double counting": a category-restricted
	//    key is still denied through the unmetered sub-request path. ────────

	public function test_category_restricted_key_is_still_denied_as_sub_request(): void {
		$auth    = $this->harness();
		// Author's categories are content/media/taxonomy — elementor-info is not
		// one of them (KeyCategoryRestrictionAuthPlaneTest establishes the same
		// fixture for the sibling /batch and direct-REST entry points).
		$created = $auth->create_scoped_api_key( 'author-key', array( 'read', 'write' ), 'author' );

		Mcpwp_REST_Batch::$is_batch_sub_request = true;
		$result = $auth->verify_api_key(
			new WP_REST_Request( 'GET', '/mcpwp/v1/elementor/status', array(), array( 'X-API-Key' => $created['key'] ) )
		);

		$this->assertInstanceOf( WP_Error::class, $result, 'An author-role key must still be blocked from an elementor-info route as a sub-request.' );
		$this->assertSame( 'forbidden_category', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_missing_credential_is_still_refused_as_sub_request(): void {
		$auth = $this->harness();

		Mcpwp_REST_Batch::$is_batch_sub_request = true;
		$result = $auth->verify_api_key( new WP_REST_Request( 'GET', '/mcpwp/v1/site-info', array(), array() ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'missing_api_key', $result->get_error_code() );
	}

	public function test_unknown_bearer_token_is_still_refused_as_sub_request_when_not_enrolled(): void {
		// No enrollBroker() call — the kill-switch inside Mcpwp_Broker_Auth::
		// is_enrolled() means an unrecognised Bearer token falls straight
		// through to invalid_api_key, not a network call.
		$auth = $this->harness();

		Mcpwp_REST_Batch::$is_batch_sub_request = true;
		$result = $auth->verify_api_key(
			new WP_REST_Request( 'GET', '/mcpwp/v1/site-info', array(), array( 'Authorization' => 'Bearer not-a-real-token' ) )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_api_key', $result->get_error_code() );
	}
}
