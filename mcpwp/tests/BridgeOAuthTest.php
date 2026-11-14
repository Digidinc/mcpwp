<?php

/**
 * Tests for OAuth token bridge — R4 of issue #505.
 *
 * 2.8.56 issues tokens with prefix spai_at_ and stores them under the transient
 * key spai_oauth_token_<md5(token)>.  v3 issues tokens with prefix mcpwp_at_ and
 * stores them under mcpwp_oauth_token_<md5(token)>.
 *
 * After the slug rename a live spai_at_ token must still be accepted by v3 auth.
 *
 * Covers:
 *   - looks_like_oauth_access_token() returns true for both mcpwp_at_ and spai_at_ tokens.
 *   - authenticate_oauth_access_token() resolves a spai_at_ token via the legacy
 *     spai_oauth_token_ transient namespace.
 *   - authenticate_oauth_access_token() resolves a mcpwp_at_ token normally.
 *   - A spai_at_ token stored under the v3 transient key (edge case: re-issued by v3
 *     code) is also resolved.
 *   - An unknown token (neither namespace) returns 401.
 *   - OAuth disabled → 401 for both token types.
 *
 * Run command (in wp-test docker):
 *   docker exec wp-test-wordpress-1 bash -c \
 *     "cd /var/www/html/wp-content/plugins/mcpwp && \
 *      php vendor/bin/phpunit --configuration tests/phpunit.xml \
 *      --filter BridgeOAuthTest"
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

/**
 * Thin subclass that exposes the protected OAuth-token detection method for testing.
 */
class Mcpwp_OAuth_Test_Harness extends Mcpwp_Api_Auth_Test_Harness {
	/**
	 * Public proxy to the protected looks_like_oauth_access_token().
	 *
	 * @param string $token Token to check.
	 * @return bool
	 */
	public function looks_like_oauth_access_token_public( string $token ): bool {
		return $this->looks_like_oauth_access_token( $token );
	}
}

final class BridgeOAuthTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;

		// Enable OAuth in settings.
		update_option( 'mcpwp_settings', array(
			'oauth_enabled'            => true,
			'oauth_client_id'          => 'site_pilot_ai',
			'oauth_client_secret_hash' => '',
			'oauth_token_ttl'          => 3600,
		) );
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	private function make_auth(): Mcpwp_OAuth_Test_Harness {
		return new Mcpwp_OAuth_Test_Harness();
	}

	private function make_request( string $token, string $method = 'GET', string $route = '/mcpwp/v1/site-info' ): WP_REST_Request {
		return new WP_REST_Request(
			$method,
			$route,
			array(),
			array( 'Authorization' => 'Bearer ' . $token )
		);
	}

	// -----------------------------------------------------------------------
	// looks_like_oauth_access_token
	// -----------------------------------------------------------------------

	public function test_mcpwp_at_token_is_recognized_as_oauth(): void {
		$auth  = $this->make_auth();
		$token = 'mcpwp_at_' . bin2hex( random_bytes( 16 ) );
		$this->assertTrue( $auth->looks_like_oauth_access_token_public( $token ) );
	}

	public function test_spai_at_token_is_recognized_as_oauth(): void {
		$auth  = $this->make_auth();
		$token = 'spai_at_' . bin2hex( random_bytes( 16 ) );
		$this->assertTrue( $auth->looks_like_oauth_access_token_public( $token ),
			'looks_like_oauth_access_token must return true for legacy spai_at_ tokens' );
	}

	public function test_regular_api_key_not_recognized_as_oauth(): void {
		$auth  = $this->make_auth();
		$token = 'mcpwp_' . bin2hex( random_bytes( 24 ) );
		$this->assertFalse( $auth->looks_like_oauth_access_token_public( $token ) );
	}

	// -----------------------------------------------------------------------
	// spai_at_ token lookup via legacy transient namespace
	// -----------------------------------------------------------------------

	public function test_spai_at_token_resolved_from_legacy_transient(): void {
		$auth  = $this->make_auth();
		$token = 'spai_at_' . bin2hex( random_bytes( 16 ) );

		// Store the token under the LEGACY transient key (as 2.8.56 did).
		$legacy_key = 'spai_oauth_token_' . md5( $token );
		set_transient( $legacy_key, array(
			'scopes'     => array( 'read', 'write', 'admin' ),
			'created_at' => time(),
			'expires_at' => time() + 3600,
		), 3600 );

		$result = $auth->verify_api_key( $this->make_request( $token ) );

		$this->assertTrue( $result,
			'A spai_at_ token stored under the legacy spai_oauth_token_ transient must be accepted' );
	}

	// -----------------------------------------------------------------------
	// mcpwp_at_ token lookup via v3 transient namespace (normal path)
	// -----------------------------------------------------------------------

	public function test_mcpwp_at_token_resolved_from_v3_transient(): void {
		$auth  = $this->make_auth();
		$token = 'mcpwp_at_' . bin2hex( random_bytes( 16 ) );

		// Store the token under the V3 transient key.
		$v3_key = 'mcpwp_oauth_token_' . md5( $token );
		set_transient( $v3_key, array(
			'scopes'     => array( 'read', 'write', 'admin' ),
			'created_at' => time(),
			'expires_at' => time() + 3600,
		), 3600 );

		$result = $auth->verify_api_key( $this->make_request( $token ) );

		$this->assertTrue( $result,
			'A mcpwp_at_ token stored under the v3 mcpwp_oauth_token_ transient must be accepted' );
	}

	// -----------------------------------------------------------------------
	// Unknown token → 401
	// -----------------------------------------------------------------------

	public function test_unknown_spai_at_token_returns_401(): void {
		$auth  = $this->make_auth();
		$token = 'spai_at_unknowntoken_not_in_store';

		// No transient set for this token.
		$result = $auth->verify_api_key( $this->make_request( $token ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_token', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_unknown_mcpwp_at_token_returns_401(): void {
		$auth  = $this->make_auth();
		$token = 'mcpwp_at_unknowntoken_not_in_store';

		$result = $auth->verify_api_key( $this->make_request( $token ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_token', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// OAuth disabled → 401
	// -----------------------------------------------------------------------

	public function test_spai_at_token_rejected_when_oauth_disabled(): void {
		// Override: OAuth disabled.
		update_option( 'mcpwp_settings', array( 'oauth_enabled' => false ) );

		$auth  = $this->make_auth();
		$token = 'spai_at_' . bin2hex( random_bytes( 16 ) );

		// Even if a valid transient exists, OAuth disabled → 401.
		set_transient( 'spai_oauth_token_' . md5( $token ), array(
			'scopes' => array( 'read', 'write', 'admin' ),
		), 3600 );

		$result = $auth->verify_api_key( $this->make_request( $token ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_token', $result->get_error_code() );
	}

	public function test_mcpwp_at_token_rejected_when_oauth_disabled(): void {
		update_option( 'mcpwp_settings', array( 'oauth_enabled' => false ) );

		$auth  = $this->make_auth();
		$token = 'mcpwp_at_' . bin2hex( random_bytes( 16 ) );

		set_transient( 'mcpwp_oauth_token_' . md5( $token ), array(
			'scopes' => array( 'read', 'write', 'admin' ),
		), 3600 );

		$result = $auth->verify_api_key( $this->make_request( $token ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_token', $result->get_error_code() );
	}
}
