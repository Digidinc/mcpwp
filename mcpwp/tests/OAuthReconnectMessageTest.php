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

	protected function setUp(): void {
		$this->saved_users = $GLOBALS['mcpwp_test_users'];
		$GLOBALS['mcpwp_test_users'][] = (object) array( 'ID' => 1, 'roles' => array(), 'user_login' => 'user1' );
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_wpdb_options'] = array();
		$GLOBALS['mcpwp_test_user_meta']    = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		update_option( 'mcpwp_settings', array( 'oauth_enabled' => true ) );
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_users'] = $this->saved_users;
	}

	private function issue(): string {
		$auth = new Mcpwp_Api_Auth_Test_Harness();
		return $auth->issue_oauth_access_token( array( 'read' ), 3600, array( 'client_id' => 'test_client' ) )['access_token'];
	}

	private function verify( string $token ) {
		$request = new WP_REST_Request( 'GET', '/mcpwp/v1/site-info', array(), array( 'Authorization' => 'Bearer ' . $token ) );
		return ( new Mcpwp_Api_Auth_Test_Harness() )->verify_api_key( $request );
	}

	/** What a client sees: body plus the challenge header, built as REST does. */
	private function wire( $error ): array {
		$this->assertInstanceOf( WP_Error::class, $error );
		$data     = $error->get_error_data();
		$response = new WP_REST_Response(
			array( 'code' => $error->get_error_code(), 'message' => $error->get_error_message(), 'data' => $data ),
			$data['status']
		);
		$request  = new WP_REST_Request( 'GET', '/mcpwp/v1/site-info' );
		$response = Mcpwp_OAuth_Well_Known::add_www_authenticate_header( $response, null, $request );
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

	public function test_oauth_off_gives_the_same_message_without_discovery(): void {
		// A valid token while local OAuth is off is refused like any other
		// invalid one; there is no discovery to point at, so only the error.
		update_option( 'mcpwp_settings', array( 'oauth_enabled' => false ) );
		$error = $this->verify( $this->issue() );
		$this->assertSame( 'invalid_token', $error->get_error_code() );
		$this->assertSame( Mcpwp_REST_MCP::oauth_reconnect_message(), $error->get_error_message() );
		$wire = $this->wire( $this->verify( 'mcpwp_at_' . bin2hex( random_bytes( 8 ) ) ) );
		$this->assertSame( 'invalid_token', $wire['body']['code'] );
		$this->assertSame( 'Bearer error="invalid_token"', $wire['headers']['WWW-Authenticate'] );
	}
}
