<?php
/**
 * An OAuth access token that is not valid, for ANY reason, is answered with one
 * identical 401 `invalid_token` that says to reconnect, plus the RFC 6750
 * challenge. It must not reveal whether the string was expired, revoked or
 * never issued. A bad API key keeps `invalid_api_key`.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-oauth-clients.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-oauth-revocation.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-oauth-token-store.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-oauth-well-known.php';

final class OAuthReconnectMessageTest extends TestCase {

	/** @var array */
	private $saved_users;
	/** @var mixed */
	private $saved_addr;

	protected function setUp(): void {
		$this->saved_users = $GLOBALS['mcpwp_test_users'];
		$GLOBALS['mcpwp_test_users'][] = (object) array( 'ID' => 1, 'roles' => array(), 'user_login' => 'user1' );
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_wpdb_options'] = array();
		$GLOBALS['mcpwp_test_user_meta']    = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_filters']      = array();
		$this->saved_addr = $_SERVER['REMOTE_ADDR'] ?? null;
		Mcpwp_Rate_Limiter::get_instance()->update_settings( array( 'enabled' => true, 'requests_per_minute' => 60, 'requests_per_hour' => 1000, 'burst_limit' => 5, 'whitelist' => array() ) );
		update_option( 'mcpwp_settings', array( 'oauth_enabled' => true ) );
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_users']   = $this->saved_users;
		$GLOBALS['mcpwp_test_filters'] = array();
		if ( null === $this->saved_addr ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $this->saved_addr;
		}
		$instance = ( new ReflectionClass( Mcpwp_Rate_Limiter::class ) )->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );
	}

	private function issue(): string {
		$auth = new Mcpwp_Api_Auth_Test_Harness();
		return $auth->issue_oauth_access_token( array( 'read' ), 3600, array( 'client_id' => 'test_client' ) )['access_token'];
	}

	private function verify( string $token ) {
		$request = new WP_REST_Request( 'GET', '/mcpwp/v1/site-info', array(), array( 'Authorization' => 'Bearer ' . $token ) );
		return ( new Mcpwp_Api_Auth_Test_Harness() )->verify_api_key( $request );
	}

	/** What a client sees: the server turns the WP_Error into a response, then rest_post_dispatch filters run. */
	private function wire( $error ): array {
		$this->assertInstanceOf( WP_Error::class, $error );
		Mcpwp_OAuth_Well_Known::init();
		$server   = new WP_REST_Server();
		$request  = new WP_REST_Request( 'GET', '/mcpwp/v1/site-info' );
		$response = $server->error_to_response( $error );
		$response = apply_filters( 'rest_post_dispatch', $response, $server, $request );
		return array( 'status' => $response->get_status(), 'body' => $response->get_data(), 'headers' => $response->get_headers() );
	}

	private function assert_reconnect( array $wire, string $label ): void {
		$this->assertSame( 401, $wire['status'], $label );
		$this->assertSame( 'invalid_token', $wire['body']['code'], $label );
		$this->assertStringContainsString( 'expired or was revoked', $wire['body']['message'], $label );
		$this->assertStringContainsString( 'Reconnect', $wire['body']['message'], $label );
		$challenge = $wire['headers']['WWW-Authenticate'] ?? '';
		$this->assertStringContainsString( 'Bearer error="invalid_token"', $challenge, $label );
		$this->assertStringContainsString( 'resource_metadata="', $challenge, $label );
		$this->assertStringContainsString( '.well-known/oauth-protected-resource', $challenge, $label );
	}

	public function test_every_reason_yields_the_identical_response(): void {
		$wires = array();

		// Unknown (never issued).
		$wires['unknown'] = $this->wire( $this->verify( 'mcpwp_at_' . bin2hex( random_bytes( 24 ) ) ) );

		// Malformed: prefix only, junk, spai_ legacy shape.
		$wires['prefix only'] = $this->wire( $this->verify( 'mcpwp_at_' ) );
		$wires['junk']        = $this->wire( $this->verify( 'mcpwp_at_%%%% ../../x' ) );
		$wires['legacy']      = $this->wire( $this->verify( 'spai_at_' . bin2hex( random_bytes( 8 ) ) ) );

		// Expired row.
		$expired = 'mcpwp_at_' . bin2hex( random_bytes( 24 ) );
		Mcpwp_OAuth_Token_Store::insert( $expired, 'access', array( 'scopes' => array( 'read' ), 'client_id' => 'test_client', 'aud' => Mcpwp_OAuth_Well_Known::get_canonical_resource(), 'issued_at' => Mcpwp_OAuth_Revocation::now(), 'expires_at' => time() - 5 ) );
		$wires['expired'] = $this->wire( $this->verify( $expired ) );

		// Revoked row (/oauth/revoke, family kill, app removal all mark the row).
		$revoked = $this->issue();
		$this->assertTrue( true === $this->verify( $revoked ) );
		Mcpwp_OAuth_Token_Store::revoke( $revoked );
		$wires['revoked row'] = $this->wire( $this->verify( $revoked ) );

		// Client epoch (row still active, an epoch covers it).
		$by_client = $this->issue();
		usleep( 20 );
		Mcpwp_OAuth_Revocation::revoke_client( 'test_client' );
		$wires['client epoch'] = $this->wire( $this->verify( $by_client ) );

		// Site epoch (Disconnect all).
		$GLOBALS['mcpwp_test_options'] = array();
		update_option( 'mcpwp_settings', array( 'oauth_enabled' => true ) );
		$site = $this->issue();
		usleep( 20 );
		Mcpwp_OAuth_Revocation::revoke_site();
		$wires['site epoch'] = $this->wire( $this->verify( $site ) );

		$reference = $wires['unknown'];
		foreach ( $wires as $label => $wire ) {
			$this->assert_reconnect( $wire, $label );
			$this->assertSame( $reference, $wire, "'$label' must be indistinguishable from an unknown token" );
		}
	}

	public function test_the_token_is_never_echoed(): void {
		$token = 'mcpwp_at_' . bin2hex( random_bytes( 24 ) );
		$wire  = $this->wire( $this->verify( $token ) );
		$this->assertStringNotContainsString( $token, wp_json_encode( $wire ) );
		$this->assertStringNotContainsString( substr( $token, 9, 12 ), wp_json_encode( $wire ) );
	}

	public function test_the_unmetered_sub_request_path_answers_the_same(): void {
		$harness = new class() extends Mcpwp_Api_Auth_Test_Harness {
			public function sub( $request ) {
				return $this->verify_api_key_auth_only( $request );
			}
		};
		$request = new WP_REST_Request( 'GET', '/mcpwp/v1/site-info', array(), array( 'Authorization' => 'Bearer mcpwp_at_' . bin2hex( random_bytes( 8 ) ) ) );
		$error   = $harness->sub( $request );
		$this->assertSame( 'invalid_token', $error->get_error_code() );
		$this->assertSame( Mcpwp_REST_MCP::oauth_reconnect_message(), $error->get_error_message() );
	}

	public function test_a_valid_token_still_authenticates(): void {
		$this->assertTrue( $this->verify( $this->issue() ) );
	}

	public function test_a_bad_api_key_still_says_invalid_api_key(): void {
		$auth = new Mcpwp_Api_Auth_Test_Harness();
		$auth->create_scoped_api_key( 'live', array( 'read' ), 'admin' );
		foreach ( array( 'mcpwp_' . str_repeat( 'a', 48 ), 'not-a-key', 'at_mcpwp_at_' ) as $key ) {
			$error = $this->verify( $key );
			$this->assertSame( 'invalid_api_key', $error->get_error_code(), $key );
			$this->assertSame( 'Invalid API key.', $error->get_error_message(), $key );

			$request = new WP_REST_Request( 'GET', '/mcpwp/v1/site-info', array(), array( 'X-API-Key' => $key ) );
			$via_x   = ( new Mcpwp_Api_Auth_Test_Harness() )->verify_api_key( $request );
			$this->assertSame( 'invalid_api_key', $via_x->get_error_code(), 'X-API-Key: ' . $key );
		}
	}

	public function test_an_oauth_token_in_x_api_key_is_still_not_read(): void {
		$request = new WP_REST_Request( 'GET', '/mcpwp/v1/site-info', array(), array( 'X-API-Key' => $this->issue() ) );
		$error   = ( new Mcpwp_Api_Auth_Test_Harness() )->verify_api_key( $request );
		$this->assertSame( 'missing_api_key', $error->get_error_code() );
	}

	public function test_oauth_off_not_enrolled_says_so_and_is_identical_for_every_token(): void {
		update_option( 'mcpwp_settings', array( 'oauth_enabled' => false ) );
		$wires = array();
		foreach ( array( $this->issue(), 'mcpwp_at_' . bin2hex( random_bytes( 8 ) ), 'spai_at_x', 'mcpwp_at_' ) as $token ) {
			$wires[] = $this->wire( $this->verify( $token ) );
			$_SERVER['REMOTE_ADDR'] = '10.0.0.' . count( $wires ); // keep the limiter out of this test
		}
		foreach ( $wires as $wire ) {
			$this->assertSame( $wires[0], $wire );
			$this->assertSame( 'invalid_token', $wire['body']['code'] );
			$this->assertStringContainsString( 'switched off', $wire['body']['message'] );
			$this->assertStringContainsString( 'API key', $wire['body']['message'] );
			$this->assertStringNotContainsString( 'Reconnect', $wire['body']['message'] );
			$this->assertSame( 'Bearer error="invalid_token"', $wire['headers']['WWW-Authenticate'] );
		}
	}

	public function test_oauth_off_on_an_enrolled_site_keeps_the_reconnect_answer(): void {
		update_option( 'mcpwp_settings', array( 'oauth_enabled' => false ) );
		update_option( 'mcpwp_broker_enrollment', array( 'site_id' => 's', 'site_credential' => 'c', 'audience' => 'https://broker.example' ) );
		$error = $this->verify( 'mcpwp_at_' . bin2hex( random_bytes( 8 ) ) );
		$this->assertSame( Mcpwp_REST_MCP::oauth_reconnect_message(), $error->get_error_message() );
	}

	// ---- the dead-token cases that are not a plain missing row ------------

	private function row( string $token, array $over = array() ): void {
		$this->assertTrue( Mcpwp_OAuth_Token_Store::insert(
			$token,
			'access',
			array_merge(
				array(
					'scopes'     => array( 'read' ),
					'client_id'  => 'test_client',
					'user_id'    => 1,
					'aud'        => Mcpwp_OAuth_Well_Known::get_canonical_resource(),
					'issued_at'  => Mcpwp_OAuth_Revocation::now() - 5000000,
					'expires_at' => time() + 600,
				),
				$over
			)
		) );
	}

	public function test_user_epoch_and_deleted_user_answer_like_an_unknown_token(): void {
		$reference = $this->wire( $this->verify( 'mcpwp_at_' . bin2hex( random_bytes( 24 ) ) ) );

		// User epoch alone (the row stays active; only the epoch covers it).
		update_user_meta( 1, Mcpwp_OAuth_Revocation::USER_META, (string) Mcpwp_OAuth_Revocation::now() );
		$token = 'mcpwp_at_' . bin2hex( random_bytes( 24 ) );
		$this->row( $token );
		$this->assertSame( $reference, $this->wire( $this->verify( $token ) ), 'user epoch' );

		// Deleted user: the tombstone, with the user gone, row still active.
		$GLOBALS['mcpwp_test_users'][]   = (object) array( 'ID' => 2, 'roles' => array(), 'user_login' => 'user2' );
		$GLOBALS['mcpwp_test_user_caps'] = array( 2 => array( 'read' => true, 'edit_posts' => true, 'edit_others_posts' => true ) );
		$token2 = 'mcpwp_at_' . bin2hex( random_bytes( 24 ) );
		$this->row( $token2, array( 'user_id' => 2 ) );
		$this->assertTrue( $this->verify( $token2 ) === true, 'sanity: valid before deletion' );
		Mcpwp_OAuth_Revocation::on_user_deleted( 2 );
		$GLOBALS['mcpwp_test_users'] = $this->saved_users;
		$GLOBALS['mcpwp_test_user_caps'] = array();
		$this->assertSame( $reference, $this->wire( $this->verify( $token2 ) ), 'deleted user' );
	}

	public function test_an_epoch_revoked_token_with_a_foreign_audience_still_answers_reconnect(): void {
		$reference = $this->wire( $this->verify( 'mcpwp_at_' . bin2hex( random_bytes( 24 ) ) ) );
		Mcpwp_OAuth_Revocation::revoke_client( 'test_client' );
		$token = 'mcpwp_at_' . bin2hex( random_bytes( 24 ) );
		$this->row( $token, array( 'aud' => 'https://elsewhere.example/wp-json/mcpwp/v1/mcp' ) );
		$this->assertSame( $reference, $this->wire( $this->verify( $token ) ) );
	}

	public function test_a_live_token_for_a_foreign_audience_keeps_its_own_accurate_message_and_hint(): void {
		$token = 'mcpwp_at_' . bin2hex( random_bytes( 24 ) );
		$this->row( $token, array( 'aud' => 'https://elsewhere.example/wp-json/mcpwp/v1/mcp' ) );
		$error = $this->verify( $token );
		$this->assertSame( 'invalid_token', $error->get_error_code() );
		$this->assertStringContainsString( 'different resource', $error->get_error_message() );
		$hint = $error->get_error_data()['hint'];
		$this->assertStringContainsString( 'different site address', $hint );
		$this->assertStringNotContainsString( 'refresh token', $hint );
	}

	public function test_the_hint_survives_the_mcp_tool_path_for_the_two_live_token_refusals(): void {
		// The /mcp inner path fills a generic hint from the code table only when
		// the error carries none.
		foreach ( array( 'resource', 'user' ) as $case ) {
			if ( 'resource' === $case ) {
				$token = 'mcpwp_at_' . bin2hex( random_bytes( 24 ) );
				$this->row( $token, array( 'aud' => 'https://elsewhere.example/x' ) );
				$error = $this->verify( $token );
			} else {
				$GLOBALS['mcpwp_test_users'] = $this->saved_users; // approving user gone, no epoch
				$token = 'mcpwp_at_' . bin2hex( random_bytes( 24 ) );
				$this->row( $token, array( 'user_id' => 99 ) );
				$error = $this->verify( $token );
			}
			$this->assertSame( 'invalid_token', $error->get_error_code(), $case );
			$hint = $error->get_error_data()['hint'] ?? '';
			$this->assertNotSame( '', $hint, $case );
			$this->assertNotSame( Mcpwp_Error_Hints::get_hint_for_code( 'invalid_token' )['hint'], $hint, "$case: not the generic expired/revoked hint" );
		}
		$this->assertStringContainsString( 'role', ( $this->verify( $token ) )->get_error_data()['hint'] );
	}

	// ---- throttling: the same per-address limiter wrong API keys use ------

	private function first_throttled( callable $attempt, int $max = 40 ): ?int {
		for ( $i = 1; $i <= $max; $i++ ) {
			$result = $attempt();
			if ( $result instanceof WP_Error && 'rate_limit_exceeded' === $result->get_error_code() ) {
				return $i;
			}
		}
		return null;
	}

	private function reset_limiter(): void {
		$GLOBALS['mcpwp_test_transients'] = array();
		$instance = ( new ReflectionClass( Mcpwp_Rate_Limiter::class ) )->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );
		Mcpwp_Rate_Limiter::get_instance()->update_settings( array( 'enabled' => true, 'requests_per_minute' => 60, 'requests_per_hour' => 1000, 'burst_limit' => 5, 'whitelist' => array() ) );
	}

	public function test_a_loop_of_random_oauth_tokens_is_throttled_like_a_loop_of_garbage_api_keys(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
		$auth = new Mcpwp_Api_Auth_Test_Harness();
		$auth->create_scoped_api_key( 'live', array( 'read' ), 'admin' );

		$keys = $this->first_throttled( fn() => $this->verify( 'mcpwp_' . bin2hex( random_bytes( 24 ) ) ) );
		$this->assertNotNull( $keys, 'sanity: garbage API keys hit the limiter' );

		$this->reset_limiter();
		$tokens = $this->first_throttled( fn() => $this->verify( 'mcpwp_at_' . bin2hex( random_bytes( 24 ) ) ) );
		$this->assertSame( $keys, $tokens, 'Random OAuth tokens must be counted exactly like garbage keys.' );
	}

	public function test_each_dead_token_reason_counts_against_the_limiter(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		Mcpwp_OAuth_Revocation::revoke_site();
		$n = $this->first_throttled(
			function () {
				$t = 'mcpwp_at_' . bin2hex( random_bytes( 24 ) );
				$this->row( $t ); // issued before the site epoch: epoch branch
				return $this->verify( $t );
			}
		);
		$this->assertNotNull( $n, 'epoch-covered tokens are counted' );

		$this->reset_limiter();
		update_option( 'mcpwp_settings', array( 'oauth_enabled' => false ) );
		$this->assertNotNull( $this->first_throttled( fn() => $this->verify( 'mcpwp_at_' . bin2hex( random_bytes( 24 ) ) ) ), 'OAuth-off tokens are counted' );
	}

	public function test_a_valid_token_is_never_throttled_by_another_addresses_failures(): void {
		$token = $this->issue();
		$_SERVER['REMOTE_ADDR'] = '198.51.100.1';
		$this->assertNotNull( $this->first_throttled( fn() => $this->verify( 'mcpwp_at_' . bin2hex( random_bytes( 24 ) ) ) ) );

		$_SERVER['REMOTE_ADDR'] = '198.51.100.2';
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertTrue( $this->verify( $token ), 'a different address is unaffected' );
		}
	}

	public function test_a_valid_token_is_not_counted_as_a_failure(): void {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.3';
		$token = $this->issue();
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertTrue( $this->verify( $token ) );
		}
		$budget = Mcpwp_Rate_Limiter::get_instance()->get_budget();
		$this->assertTrue( null === $budget || 'invalid:198.51.100.3' !== ( $budget['identifier'] ?? '' ) );
	}
}
