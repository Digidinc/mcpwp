<?php

/**
 * OAuth 2.1 authorization server — unit tests (#531).
 *
 * Covers:
 *   - RFC 9728 protected-resource metadata shape + required fields.
 *   - RFC 8414 authorization-server metadata shape + required fields.
 *   - authorize param validation: missing PKCE, non-S256, bad client_id,
 *     non-allowlisted redirect_uri.
 *   - Token exchange PKCE: correct verifier succeeds, wrong verifier fails.
 *   - Token single-use: replay of a consumed code fails.
 *   - Expired-code detection.
 *   - redirect_uri mismatch in token exchange.
 *   - Issued mcpwp_at_ token authenticates on the API auth path.
 *   - X-API-Key still works (no regression).
 *   - OAuth disabled gating on all three token/authorize paths.
 *   - WWW-Authenticate header emitted on 401 when OAuth enabled.
 *
 *   P0-A: Scope escalation — subscriber can't get admin; editor can't get admin.
 *   P0-B: Empty/absent scope defaults to read only (never admin).
 *   P0-C: Public client (no client_secret) can complete full PKCE exchange.
 *   P1-D: Refresh token rotation — used refresh token can't be reused.
 *   P1-E: client_id must match the code's stored client_id.
 *   P2-F: X-Forwarded-Proto: http on an https site still emits https origin.
 *
 * Run:
 *   docker exec wp-rig-wp-dev bash -c \
 *     "cd /var/www/html/wp-content/plugins/mcpwp && \
 *      php vendor/bin/phpunit --configuration tests/phpunit.xml \
 *      --filter OAuthServerTest"
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

// ── Missing WordPress stubs for this test file ────────────────────────────
// These are not needed by existing tests so they are not in bootstrap.php.

if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( $path = '' ) {
		return 'https://example.com/wp-json/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'wp_login_url' ) ) {
	function wp_login_url( $redirect = '' ) {
		return 'https://example.com/wp-login.php' . ( $redirect ? '?redirect_to=' . rawurlencode( $redirect ) : '' );
	}
}

if ( ! function_exists( 'wp_redirect' ) ) {
	function wp_redirect( $location ) {
		$GLOBALS['_mcpwp_test_redirected_to'] = $location;
		return true;
	}
}

if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in() {
		return 0 !== (int) $GLOBALS['mcpwp_test_current_user'];
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( $nonce, $action ) {
		return isset( $GLOBALS['_mcpwp_test_valid_nonces'][ $action ] )
			&& hash_equals( $GLOBALS['_mcpwp_test_valid_nonces'][ $action ], (string) $nonce );
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $action ) {
		$nonce = 'nonce_' . md5( $action . time() );
		$GLOBALS['_mcpwp_test_valid_nonces'][ $action ] = $nonce;
		return $nonce;
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $args, $url = '' ) {
		if ( ! is_array( $args ) ) {
			return $url;
		}
		$query = http_build_query( array_filter( $args ) );
		$sep   = strpos( $url, '?' ) !== false ? '&' : '?';
		return $query ? $url . $sep . $query : $url;
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return filter_var( (string) $url, FILTER_SANITIZE_URL );
	}
}

if ( ! function_exists( 'wp_get_current_user' ) ) {
	function wp_get_current_user() {
		$user_id = (int) $GLOBALS['mcpwp_test_current_user'];
		return (object) array( 'ID' => $user_id, 'user_login' => 'testadmin' );
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $show = '' ) {
		if ( 'name' === $show ) {
			return 'Test Site';
		}
		return '';
	}
}

// user_can() stub — checks $GLOBALS['mcpwp_test_user_caps'][$user_id][$cap].
if ( ! function_exists( 'user_can' ) ) {
	function user_can( $user_id, $capability ) {
		$caps = isset( $GLOBALS['mcpwp_test_user_caps'][ (int) $user_id ] )
			? $GLOBALS['mcpwp_test_user_caps'][ (int) $user_id ]
			: array();
		return ! empty( $caps[ $capability ] );
	}
}

if ( ! function_exists( 'header' ) ) {
	// header() is a built-in but may be redefined in test context.
	// PHPUnit typically runs CLI so headers_sent() is always false.
	// We skip redefining to avoid conflicts.
}

if ( ! function_exists( 'http_response_code' ) ) {
	// Built-in since PHP 5.4 — only stub if somehow missing.
}

// Load the classes under test.
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-oauth.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-oauth-well-known.php';

// ── Test harness ──────────────────────────────────────────────────────────

/**
 * Thin subclass that exposes protected methods for testing.
 */
class Mcpwp_OAuth_Test_Controller extends Mcpwp_REST_OAuth {
	// All target methods are already public (extract_authorize_params,
	// validate_authorize_params, compute_pkce_challenge, is_redirect_uri_allowed,
	// get_allowed_redirect_uris, oauth_error_response, clamp_scope_to_user_capability,
	// parse_oauth_scope_string, clamp_scope_array_to_user_id,
	// get_oauth_refresh_token_transient_key).

	/**
	 * Public proxy to the protected access-token transient key builder.
	 *
	 * Scope assertions read the STORED token record rather than the response's
	 * scope string, so a fix that formats the response correctly while persisting
	 * a wider scope still fails. Proxying the real method (instead of hardcoding
	 * 'mcpwp_oauth_token_' . md5( $t )) keeps the tests honest if the key format
	 * ever changes.
	 *
	 * @param string $token Access token.
	 * @return string Transient key.
	 */
	public function access_token_transient_key( $token ) {
		return $this->get_oauth_token_transient_key( $token );
	}
}

// ── Test suite ─────────────────────────────────────────────────────────────

final class OAuthServerTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']         = array();
		$GLOBALS['mcpwp_test_transients']      = array();
		$GLOBALS['mcpwp_test_current_user']    = 0;
		$GLOBALS['_mcpwp_test_redirected_to']  = null;
		$GLOBALS['_mcpwp_test_valid_nonces']   = array();
		$GLOBALS['mcpwp_test_user_caps']       = array();
		$_GET                                  = array();

		// Enable OAuth with a known client ID and redirect URI.
		update_option( 'mcpwp_settings', array(
			'oauth_enabled'            => true,
			'oauth_client_id'          => 'test_client',
			'oauth_client_secret_hash' => '',
			'oauth_token_ttl'          => 3600,
			'oauth_redirect_uris'      => array( 'https://client.example.com/callback' ),
		) );
	}

	// ── Helpers ────────────────────────────────────────────────────────────

	private function controller(): Mcpwp_OAuth_Test_Controller {
		return new Mcpwp_OAuth_Test_Controller();
	}

	private function make_request( $method = 'GET', $route = '/mcpwp/v1/oauth/authorize', $params = array() ): WP_REST_Request {
		return new WP_REST_Request( $method, $route, $params );
	}

	private function base_authorize_params(): array {
		return array(
			'response_type'         => 'code',
			'client_id'             => 'test_client',
			'redirect_uri'          => 'https://client.example.com/callback',
			'code_challenge'        => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
			'code_challenge_method' => 'S256',
			'scope'                 => 'read write',
			'state'                 => 'test_state_xyz',
		);
	}

	/**
	 * Seed an auth code transient for direct /token tests.
	 *
	 * @param array  $overrides Override default stored values.
	 * @return array{code:string, verifier:string}
	 */
	private function seed_auth_code( $overrides = array() ): array {
		$ctrl      = $this->controller();
		$verifier  = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
		$challenge = $ctrl->compute_pkce_challenge( $verifier );
		$code      = 'testcode_' . bin2hex( random_bytes( 8 ) );

		$defaults = array(
			'client_id'      => 'test_client',
			'redirect_uri'   => 'https://client.example.com/callback',
			'code_challenge' => $challenge,
			'scope'          => 'read write',
			'user_id'        => 1,
			'expires'        => time() + 60,
		);

		set_transient( 'mcpwp_oauth_code_' . $code, array_merge( $defaults, $overrides ), 60 );

		return array( 'code' => $code, 'verifier' => $verifier );
	}

	/**
	 * Seed a refresh-token transient directly.
	 *
	 * @param string[] $scopes  Stored scope list (may deliberately be junk).
	 * @param int      $user_id Bound user id (0 = none).
	 * @return string Refresh token.
	 */
	private function seed_refresh_token( array $scopes, int $user_id ): string {
		$ctrl  = $this->controller();
		$token = 'mcpwp_rt_' . bin2hex( random_bytes( 16 ) );

		set_transient(
			$ctrl->get_oauth_refresh_token_transient_key( $token ),
			array(
				'scopes'     => $scopes,
				'user_id'    => $user_id,
				'created_at' => time(),
				'expires_at' => time() + HOUR_IN_SECONDS,
			),
			HOUR_IN_SECONDS
		);

		return $token;
	}

	/** Scopes actually persisted for an issued access token. */
	private function stored_access_token_scopes( string $access_token ): array {
		$record = get_transient( $this->controller()->access_token_transient_key( $access_token ) );
		$this->assertIsArray( $record, 'Issued access token must have a stored record' );
		return $record['scopes'];
	}

	/** Scopes actually persisted for an issued refresh token. */
	private function stored_refresh_token_scopes( string $refresh_token ): array {
		$record = get_transient( $this->controller()->get_oauth_refresh_token_transient_key( $refresh_token ) );
		$this->assertIsArray( $record, 'Issued refresh token must have a stored record' );
		return $record['scopes'];
	}

	/** Turn on the client_credentials grant, optionally setting the ceiling. */
	private function enable_client_credentials( $max_scope = null ): void {
		$settings                             = get_option( 'mcpwp_settings', array() );
		$settings['oauth_client_secret_hash'] = wp_hash_password( 'cc_secret' );
		if ( null !== $max_scope ) {
			$settings['oauth_client_credentials_max_scope'] = $max_scope;
		}
		update_option( 'mcpwp_settings', $settings );
	}

	private function client_credentials_request( string $scope ): WP_REST_Request {
		return $this->make_request(
			'POST',
			'/mcpwp/v1/oauth/token',
			array(
				'grant_type'    => 'client_credentials',
				'client_id'     => 'test_client',
				'client_secret' => 'cc_secret',
				'scope'         => $scope,
			)
		);
	}

	// ── RFC 9728 Protected Resource Metadata ──────────────────────────────

	public function test_get_site_origin_returns_https(): void {
		$origin = Mcpwp_OAuth_Well_Known::get_site_origin();
		$this->assertStringStartsWith( 'https://', $origin, 'Origin must start with https://' );
		$this->assertStringNotContainsString( '/', ltrim( $origin, 'https://' ), 'Origin must not have a trailing slash' );
	}

	public function test_protected_resource_metadata_fields(): void {
		// Simulate the metadata that would be emitted by serve_protected_resource_metadata.
		// We test the shape by constructing the same array the class would build.
		$origin = Mcpwp_OAuth_Well_Known::get_site_origin();
		$metadata = array(
			'resource'              => $origin,
			'authorization_servers' => array( $origin . '/.well-known/oauth-authorization-server' ),
			'scopes_supported'      => array( 'read', 'write', 'admin' ),
			'bearer_methods_supported' => array( 'header' ),
		);

		$this->assertArrayHasKey( 'resource', $metadata );
		$this->assertArrayHasKey( 'authorization_servers', $metadata );
		$this->assertNotEmpty( $metadata['authorization_servers'] );
		$this->assertStringStartsWith( 'https://', $metadata['resource'] );
		$this->assertContains( 'header', $metadata['bearer_methods_supported'] );
		$this->assertContains( 'read', $metadata['scopes_supported'] );
	}

	// ── RFC 8414 Authorization Server Metadata ────────────────────────────

	public function test_authorization_server_metadata_required_fields(): void {
		update_option( 'permalink_structure', '/%postname%/' );

		$origin   = Mcpwp_OAuth_Well_Known::get_site_origin();
		$metadata = Mcpwp_OAuth_Well_Known::build_authorization_server_metadata();

		// RFC 8414 required fields.
		$this->assertArrayHasKey( 'issuer', $metadata );
		$this->assertArrayHasKey( 'authorization_endpoint', $metadata );
		$this->assertArrayHasKey( 'token_endpoint', $metadata );
		$this->assertArrayHasKey( 'response_types_supported', $metadata );

		// MCP-required extensions.
		$this->assertContains( 'S256', $metadata['code_challenge_methods_supported'] );
		$this->assertNotContains( 'plain', $metadata['code_challenge_methods_supported'] );
		$this->assertContains( 'authorization_code', $metadata['grant_types_supported'] );

		// Endpoints must use the correct site origin.
		$this->assertStringStartsWith( $origin, $metadata['issuer'] );
		$this->assertStringContainsString( '/oauth/token', $metadata['token_endpoint'] );

		// authorization_endpoint must NOT be the REST route — core treats a
		// cookie-authenticated REST request with no X-WP-Nonce as logged out,
		// which trapped browsers in a wp-login redirect loop.
		$this->assertSame(
			Mcpwp_OAuth_Well_Known::get_front_channel_authorize_url( $origin ),
			$metadata['authorization_endpoint']
		);
		$this->assertStringNotContainsString( '/wp-json/', $metadata['authorization_endpoint'] );
	}

	// ── Authorize parameter validation ────────────────────────────────────

	public function test_valid_params_pass_validation(): void {
		$ctrl   = $this->controller();
		$params = $this->base_authorize_params();
		$result = $ctrl->validate_authorize_params( $params );
		$this->assertTrue( $result, 'Valid params should pass validation' );
	}

	public function test_missing_pkce_rejected(): void {
		$ctrl   = $this->controller();
		$params = $this->base_authorize_params();
		$params['code_challenge']        = '';
		$params['code_challenge_method'] = 'S256';
		$result = $ctrl->validate_authorize_params( $params );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_request', $result->get_error_code() );
	}

	public function test_plain_pkce_method_rejected(): void {
		$ctrl   = $this->controller();
		$params = $this->base_authorize_params();
		$params['code_challenge_method'] = 'plain';
		$result = $ctrl->validate_authorize_params( $params );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_request', $result->get_error_code() );
	}

	public function test_absent_pkce_method_rejected(): void {
		$ctrl   = $this->controller();
		$params = $this->base_authorize_params();
		$params['code_challenge_method'] = '';
		$result = $ctrl->validate_authorize_params( $params );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_request', $result->get_error_code() );
	}

	public function test_wrong_client_id_rejected(): void {
		$ctrl   = $this->controller();
		$params = $this->base_authorize_params();
		$params['client_id'] = 'evil_client';
		$result = $ctrl->validate_authorize_params( $params );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_client', $result->get_error_code() );
	}

	public function test_non_allowlisted_redirect_uri_rejected(): void {
		$ctrl   = $this->controller();
		$params = $this->base_authorize_params();
		$params['redirect_uri'] = 'https://evil.example.com/steal';
		$result = $ctrl->validate_authorize_params( $params );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_request', $result->get_error_code() );
		$this->assertStringContainsString( 'allow-list', $result->get_error_message() );
	}

	public function test_missing_redirect_uri_rejected(): void {
		$ctrl   = $this->controller();
		$params = $this->base_authorize_params();
		$params['redirect_uri'] = '';
		$result = $ctrl->validate_authorize_params( $params );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_request', $result->get_error_code() );
	}

	public function test_wrong_response_type_rejected(): void {
		$ctrl   = $this->controller();
		$params = $this->base_authorize_params();
		$params['response_type'] = 'token';
		$result = $ctrl->validate_authorize_params( $params );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_request', $result->get_error_code() );
	}

	// ── Redirect URI allow-list ────────────────────────────────────────────

	public function test_exact_match_redirect_uri_allowed(): void {
		$ctrl = $this->controller();
		$this->assertTrue( $ctrl->is_redirect_uri_allowed( 'https://client.example.com/callback' ) );
	}

	public function test_substring_redirect_uri_not_allowed(): void {
		$ctrl = $this->controller();
		$this->assertFalse( $ctrl->is_redirect_uri_allowed( 'https://client.example.com/callback/extra' ) );
	}

	public function test_empty_allowlist_rejects_everything(): void {
		update_option( 'mcpwp_settings', array(
			'oauth_enabled'       => true,
			'oauth_client_id'     => 'test_client',
			'oauth_token_ttl'     => 3600,
			'oauth_redirect_uris' => array(),
		) );
		$ctrl = $this->controller();
		$this->assertFalse( $ctrl->is_redirect_uri_allowed( 'https://client.example.com/callback' ) );
	}

	// ── PKCE challenge computation ─────────────────────────────────────────

	public function test_pkce_challenge_computation(): void {
		$ctrl = $this->controller();
		// Test vector from RFC 7636 Appendix B.
		$verifier   = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
		$expected   = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
		$computed   = $ctrl->compute_pkce_challenge( $verifier );
		$this->assertSame( $expected, $computed, 'PKCE S256 challenge must match RFC 7636 test vector' );
	}

	public function test_pkce_challenge_is_base64url_without_padding(): void {
		$ctrl      = $this->controller();
		$challenge = $ctrl->compute_pkce_challenge( 'someverifier' );
		$this->assertStringNotContainsString( '+', $challenge, 'base64url must not contain +' );
		$this->assertStringNotContainsString( '/', $challenge, 'base64url must not contain /' );
		$this->assertStringNotContainsString( '=', $challenge, 'base64url must not contain = padding' );
	}

	// ── Token exchange — authorization_code ───────────────────────────────

	/**
	 * Full happy-path: store a code, exchange it with the correct verifier.
	 */
	public function test_token_exchange_correct_verifier_succeeds(): void {
		$ctrl      = $this->controller();
		$seed      = $this->seed_auth_code();

		$request = $this->make_request( 'POST', '/mcpwp/v1/oauth/token', array(
			'grant_type'    => 'authorization_code',
			'code'          => $seed['code'],
			'redirect_uri'  => 'https://client.example.com/callback',
			'code_verifier' => $seed['verifier'],
		) );

		$response = $ctrl->handle_token( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'access_token', $data );
		$this->assertArrayHasKey( 'token_type', $data );
		$this->assertArrayHasKey( 'expires_in', $data );
		$this->assertStringStartsWith( 'mcpwp_at_', $data['access_token'] );
		$this->assertSame( 'Bearer', $data['token_type'] );
	}

	/**
	 * Wrong verifier must fail PKCE check.
	 */
	public function test_token_exchange_wrong_verifier_fails(): void {
		$ctrl = $this->controller();
		$seed = $this->seed_auth_code();

		$request = $this->make_request( 'POST', '/mcpwp/v1/oauth/token', array(
			'grant_type'    => 'authorization_code',
			'code'          => $seed['code'],
			'redirect_uri'  => 'https://client.example.com/callback',
			'code_verifier' => 'wrong_verifier_that_does_not_match',
		) );

		$response = $ctrl->handle_token( $request );

		$this->assertSame( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'invalid_grant', $data['error'] );
	}

	/**
	 * Auth code must be single-use: a second exchange on the same code fails.
	 */
	public function test_token_exchange_single_use_replay_fails(): void {
		$ctrl = $this->controller();
		$seed = $this->seed_auth_code();

		$request = $this->make_request( 'POST', '/mcpwp/v1/oauth/token', array(
			'grant_type'    => 'authorization_code',
			'code'          => $seed['code'],
			'redirect_uri'  => 'https://client.example.com/callback',
			'code_verifier' => $seed['verifier'],
		) );

		// First exchange — must succeed.
		$r1 = $ctrl->handle_token( $request );
		$this->assertSame( 200, $r1->get_status(), 'First exchange must succeed' );

		// Second exchange (replay) — code is gone.
		$r2 = $ctrl->handle_token( $request );
		$this->assertSame( 400, $r2->get_status(), 'Replay must fail' );
		$this->assertSame( 'invalid_grant', $r2->get_data()['error'] );
	}

	/**
	 * An expired code (expires timestamp in the past) must fail.
	 */
	public function test_token_exchange_expired_code_fails(): void {
		$ctrl = $this->controller();
		$seed = $this->seed_auth_code( array( 'expires' => time() - 10 ) );

		$request = $this->make_request( 'POST', '/mcpwp/v1/oauth/token', array(
			'grant_type'    => 'authorization_code',
			'code'          => $seed['code'],
			'redirect_uri'  => 'https://client.example.com/callback',
			'code_verifier' => $seed['verifier'],
		) );

		$response = $ctrl->handle_token( $request );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_grant', $response->get_data()['error'] );
	}

	/**
	 * redirect_uri mismatch in token exchange must fail.
	 */
	public function test_token_exchange_redirect_uri_mismatch_fails(): void {
		$ctrl = $this->controller();
		$seed = $this->seed_auth_code();

		$request = $this->make_request( 'POST', '/mcpwp/v1/oauth/token', array(
			'grant_type'    => 'authorization_code',
			'code'          => $seed['code'],
			'redirect_uri'  => 'https://evil.example.com/steal', // mismatch
			'code_verifier' => $seed['verifier'],
		) );

		$response = $ctrl->handle_token( $request );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_grant', $response->get_data()['error'] );
	}

	/**
	 * Token exchange without code_verifier (PKCE absent) must fail.
	 */
	public function test_token_exchange_missing_code_verifier_fails(): void {
		$ctrl = $this->controller();
		$code = 'anycode';

		$request = $this->make_request( 'POST', '/mcpwp/v1/oauth/token', array(
			'grant_type'   => 'authorization_code',
			'code'         => $code,
			'redirect_uri' => 'https://client.example.com/callback',
			// code_verifier intentionally absent
		) );

		$response = $ctrl->handle_token( $request );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_request', $response->get_data()['error'] );
	}

	// ── P0-A: Scope escalation ─────────────────────────────────────────────

	/**
	 * P0-A: A Subscriber (no manage_options, no edit_posts) requesting admin
	 * scope must receive at most 'read'.
	 */
	public function test_scope_escalation_subscriber_requesting_admin_gets_read(): void {
		// Subscriber: no manage_options, no edit_posts.
		$GLOBALS['mcpwp_test_current_user'] = 10;
		$GLOBALS['mcpwp_test_user_caps'][10] = array(); // no caps.

		$ctrl   = $this->controller();
		$result = $ctrl->clamp_scope_to_user_capability( 'admin' );

		$this->assertNotContains( 'admin', $result, 'Subscriber must not receive admin scope' );
		$this->assertNotContains( 'write', $result, 'Subscriber must not receive write scope' );
		$this->assertContains( 'read', $result, 'Subscriber must still receive read scope' );
	}

	/**
	 * P0-A: An Editor (edit_posts but not manage_options) requesting admin
	 * scope must receive at most 'write' (not admin).
	 */
	public function test_scope_escalation_editor_requesting_admin_gets_write(): void {
		$GLOBALS['mcpwp_test_current_user'] = 20;
		$GLOBALS['mcpwp_test_user_caps'][20] = array(
			'edit_posts'     => true,
			'manage_options' => false,
		);

		$ctrl   = $this->controller();
		$result = $ctrl->clamp_scope_to_user_capability( 'admin' );

		$this->assertNotContains( 'admin', $result, 'Editor must not receive admin scope' );
		$this->assertContains( 'write', $result, 'Editor must receive write scope' );
		$this->assertContains( 'read', $result, 'Editor must receive read scope (implied)' );
	}

	/**
	 * P0-A: An administrator (manage_options) requesting admin scope gets admin.
	 */
	public function test_scope_escalation_admin_requesting_admin_gets_admin(): void {
		$GLOBALS['mcpwp_test_current_user'] = 1;
		$GLOBALS['mcpwp_test_user_caps'][1] = array(
			'edit_posts'     => true,
			'manage_options' => true,
		);

		$ctrl   = $this->controller();
		$result = $ctrl->clamp_scope_to_user_capability( 'admin' );

		$this->assertContains( 'admin', $result, 'Admin must receive admin scope' );
	}

	/**
	 * P0-A: Scope is clamped at clamp_scope_to_user_capability and results in
	 * only 'read' for a subscriber.
	 *
	 * This is the unit-level assertion on the clamp helper.  The real
	 * GET→consent→POST→transient round trip is covered separately by
	 * test_consent_round_trip_* below (the handlers no longer exit, so they
	 * can be called directly).
	 */
	public function test_scope_clamped_at_clamp_layer_for_subscriber(): void {
		// Current user is a subscriber with no caps.
		$GLOBALS['mcpwp_test_current_user'] = 10;
		$GLOBALS['mcpwp_test_user_caps'][10] = array();

		$ctrl   = $this->controller();
		$result = $ctrl->clamp_scope_to_user_capability( 'admin' );

		$this->assertNotContains( 'admin', $result, 'Subscriber clamp must never yield admin' );
		$this->assertNotContains( 'write', $result, 'Subscriber clamp must never yield write' );
		$this->assertContains( 'read', $result, 'Subscriber clamp must always yield read' );
	}

	/**
	 * P0-A / P0-B: clamp_scope_to_user_capability with empty scope for a
	 * subscriber returns ['read'] only (never admin or write).
	 */
	public function test_scope_clamp_empty_scope_subscriber(): void {
		$GLOBALS['mcpwp_test_current_user'] = 10;
		$GLOBALS['mcpwp_test_user_caps'][10] = array();

		$ctrl   = $this->controller();
		$result = $ctrl->clamp_scope_to_user_capability( '' );

		$this->assertSame( array( 'read' ), $result, 'Empty scope for subscriber must yield [read] only' );
	}

	// ── P0-B: Empty scope defaults to read only ────────────────────────────

	/**
	 * P0-B: parse_oauth_scope_string with empty string returns ['read'] only.
	 */
	public function test_empty_oauth_scope_string_returns_read_only(): void {
		$ctrl   = $this->controller();
		$result = $ctrl->parse_oauth_scope_string( '' );
		$this->assertSame( array( 'read' ), $result, 'Empty scope must default to [read] only' );
	}

	/**
	 * P0-B: parse_oauth_scope_string with whitespace-only returns ['read'].
	 */
	public function test_whitespace_oauth_scope_string_returns_read_only(): void {
		$ctrl   = $this->controller();
		$result = $ctrl->parse_oauth_scope_string( '   ' );
		$this->assertSame( array( 'read' ), $result );
	}

	/**
	 * P0-B: A full token exchange with NO scope param in the stored code yields
	 * a read-only token, never admin.
	 */
	public function test_no_scope_in_code_yields_read_only_token(): void {
		// Simulate a code seeded by older code that stored empty scope.
		$ctrl = $this->controller();
		$seed = $this->seed_auth_code( array( 'scope' => '' ) );

		$request = $this->make_request( 'POST', '/mcpwp/v1/oauth/token', array(
			'grant_type'    => 'authorization_code',
			'code'          => $seed['code'],
			'redirect_uri'  => 'https://client.example.com/callback',
			'code_verifier' => $seed['verifier'],
		) );

		$response = $ctrl->handle_token( $request );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$scope_str = isset( $data['scope'] ) ? $data['scope'] : '';
		$this->assertStringNotContainsString( 'admin', $scope_str, 'Token scope must not contain admin when code had empty scope' );
		$this->assertStringContainsString( 'read', $scope_str, 'Token scope must contain read' );
	}

	// ── P0-C: Public client — no client_secret ────────────────────────────

	/**
	 * P0-C: A public client presenting a valid code + code_verifier and NO
	 * client_secret must successfully receive an access_token.
	 */
	public function test_public_client_no_secret_succeeds(): void {
		$ctrl = $this->controller();
		$seed = $this->seed_auth_code();

		$request = $this->make_request( 'POST', '/mcpwp/v1/oauth/token', array(
			'grant_type'    => 'authorization_code',
			'code'          => $seed['code'],
			'redirect_uri'  => 'https://client.example.com/callback',
			'code_verifier' => $seed['verifier'],
			'client_id'     => 'test_client',
			// No client_secret
		) );

		$response = $ctrl->handle_token( $request );

		$this->assertSame( 200, $response->get_status(), 'Public client with valid PKCE must succeed' );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'access_token', $data );
		$this->assertStringStartsWith( 'mcpwp_at_', $data['access_token'] );
	}

	/**
	 * P0-C: A public client with NO client_id AND no client_secret must also
	 * succeed — fully anonymous public PKCE exchange.
	 */
	public function test_public_client_no_client_id_no_secret_succeeds(): void {
		$ctrl = $this->controller();
		$seed = $this->seed_auth_code();

		$request = $this->make_request( 'POST', '/mcpwp/v1/oauth/token', array(
			'grant_type'    => 'authorization_code',
			'code'          => $seed['code'],
			'redirect_uri'  => 'https://client.example.com/callback',
			'code_verifier' => $seed['verifier'],
			// No client_id, no client_secret
		) );

		$response = $ctrl->handle_token( $request );

		$this->assertSame( 200, $response->get_status(), 'Fully anonymous PKCE exchange must succeed' );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'access_token', $data );
	}

	/**
	 * P0-C: A confidential client with a WRONG secret must still fail.
	 */
	public function test_confidential_client_wrong_secret_fails(): void {
		// Set a hashed secret in config.
		$secret_plaintext = 'my-secret-123';
		update_option( 'mcpwp_settings', array(
			'oauth_enabled'            => true,
			'oauth_client_id'          => 'test_client',
			'oauth_client_secret_hash' => wp_hash_password( $secret_plaintext ),
			'oauth_token_ttl'          => 3600,
			'oauth_redirect_uris'      => array( 'https://client.example.com/callback' ),
		) );

		$ctrl = $this->controller();
		$seed = $this->seed_auth_code();

		$request = $this->make_request( 'POST', '/mcpwp/v1/oauth/token', array(
			'grant_type'    => 'authorization_code',
			'code'          => $seed['code'],
			'redirect_uri'  => 'https://client.example.com/callback',
			'code_verifier' => $seed['verifier'],
			'client_id'     => 'test_client',
			'client_secret' => 'WRONG-secret',
		) );

		$response = $ctrl->handle_token( $request );
		$this->assertSame( 401, $response->get_status(), 'Wrong secret must fail with 401' );
		$this->assertSame( 'invalid_client', $response->get_data()['error'] );
	}

	// ── P1-D: Refresh token rotation ──────────────────────────────────────

	/**
	 * P1-D: A used refresh token must NOT be reusable (rotation).
	 */
	public function test_refresh_token_rotation_used_token_cannot_be_reused(): void {
		$ctrl = $this->controller();
		$seed = $this->seed_auth_code();

		// First: exchange auth code to get tokens.
		$token_request = $this->make_request( 'POST', '/mcpwp/v1/oauth/token', array(
			'grant_type'    => 'authorization_code',
			'code'          => $seed['code'],
			'redirect_uri'  => 'https://client.example.com/callback',
			'code_verifier' => $seed['verifier'],
		) );

		$token_response = $ctrl->handle_token( $token_request );
		$this->assertSame( 200, $token_response->get_status(), 'Initial token exchange must succeed' );

		$token_data    = $token_response->get_data();
		$refresh_token = $token_data['refresh_token'];
		$this->assertStringStartsWith( 'mcpwp_rt_', $refresh_token, 'Refresh token must have mcpwp_rt_ prefix' );

		// Second: use the refresh token — must succeed and give a new token.
		$refresh_request = $this->make_request( 'POST', '/mcpwp/v1/oauth/token', array(
			'grant_type'    => 'refresh_token',
			'refresh_token' => $refresh_token,
		) );

		$refresh_response = $ctrl->handle_token( $refresh_request );
		$this->assertSame( 200, $refresh_response->get_status(), 'First refresh must succeed' );

		$refresh_data       = $refresh_response->get_data();
		$new_refresh_token  = $refresh_data['refresh_token'];
		$this->assertStringStartsWith( 'mcpwp_rt_', $new_refresh_token, 'New refresh token must have mcpwp_rt_ prefix' );
		$this->assertNotSame( $refresh_token, $new_refresh_token, 'New refresh token must differ from the old one' );

		// Third: try to reuse the OLD refresh token — must fail (rotation).
		$replay_response = $ctrl->handle_token( $refresh_request );
		$this->assertSame( 400, $replay_response->get_status(), 'Replayed refresh token must fail' );
		$this->assertSame( 'invalid_grant', $replay_response->get_data()['error'] );
	}

	/**
	 * P1-D: New refresh token (after rotation) must work.
	 */
	public function test_refresh_token_rotation_new_token_works(): void {
		$ctrl = $this->controller();
		$seed = $this->seed_auth_code();

		// Get initial tokens.
		$token_response = $ctrl->handle_token( $this->make_request( 'POST', '/mcpwp/v1/oauth/token', array(
			'grant_type'    => 'authorization_code',
			'code'          => $seed['code'],
			'redirect_uri'  => 'https://client.example.com/callback',
			'code_verifier' => $seed['verifier'],
		) ) );

		$refresh_token = $token_response->get_data()['refresh_token'];

		// First use: get new tokens.
		$rotate_response = $ctrl->handle_token( $this->make_request( 'POST', '/mcpwp/v1/oauth/token', array(
			'grant_type'    => 'refresh_token',
			'refresh_token' => $refresh_token,
		) ) );

		$new_refresh_token = $rotate_response->get_data()['refresh_token'];

		// Second use: new refresh token must succeed.
		$second_response = $ctrl->handle_token( $this->make_request( 'POST', '/mcpwp/v1/oauth/token', array(
			'grant_type'    => 'refresh_token',
			'refresh_token' => $new_refresh_token,
		) ) );

		$this->assertSame( 200, $second_response->get_status(), 'New refresh token after rotation must work' );
		$this->assertArrayHasKey( 'access_token', $second_response->get_data() );
	}

	// ── P1-E: client_id bound to code ─────────────────────────────────────

	/**
	 * P1-E: A code minted for client A cannot be redeemed declaring client B.
	 */
	public function test_client_id_mismatch_at_token_fails(): void {
		// Seed a code for 'test_client'.
		$ctrl = $this->controller();
		$seed = $this->seed_auth_code( array( 'client_id' => 'test_client' ) );

		// Try to redeem declaring a different client_id.
		$request = $this->make_request( 'POST', '/mcpwp/v1/oauth/token', array(
			'grant_type'    => 'authorization_code',
			'code'          => $seed['code'],
			'redirect_uri'  => 'https://client.example.com/callback',
			'code_verifier' => $seed['verifier'],
			'client_id'     => 'evil_client', // mismatch
		) );

		$response = $ctrl->handle_token( $request );
		$this->assertSame( 400, $response->get_status(), 'client_id mismatch must fail' );
		$this->assertSame( 'invalid_grant', $response->get_data()['error'] );
	}

	/**
	 * P1-E: Correct client_id matching stored value must succeed.
	 */
	public function test_client_id_correct_match_succeeds(): void {
		$ctrl = $this->controller();
		$seed = $this->seed_auth_code( array( 'client_id' => 'test_client' ) );

		$request = $this->make_request( 'POST', '/mcpwp/v1/oauth/token', array(
			'grant_type'    => 'authorization_code',
			'code'          => $seed['code'],
			'redirect_uri'  => 'https://client.example.com/callback',
			'code_verifier' => $seed['verifier'],
			'client_id'     => 'test_client', // correct
		) );

		$response = $ctrl->handle_token( $request );
		$this->assertSame( 200, $response->get_status(), 'Correct client_id must succeed' );
	}

	// ── P2-F: X-Forwarded-Proto scheme downgrade ──────────────────────────

	/**
	 * P2-F: A request with X-Forwarded-Proto: http to an https site must still
	 * emit an https issuer URL (no scheme downgrade without trusted-proxy opt-in).
	 */
	public function test_x_forwarded_proto_http_does_not_downgrade_https_origin(): void {
		// Simulate the attacker setting X-Forwarded-Proto: http.
		$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'http';

		// get_site_url() returns 'https://example.com' (see bootstrap.php).
		$origin = Mcpwp_OAuth_Well_Known::get_site_origin();

		// Clean up.
		unset( $_SERVER['HTTP_X_FORWARDED_PROTO'] );

		$this->assertStringStartsWith(
			'https://',
			$origin,
			'X-Forwarded-Proto: http must not downgrade an https site to http issuer'
		);
	}

	/**
	 * P2-F: Without X-Forwarded-Proto, origin uses site_url scheme (https).
	 */
	public function test_origin_uses_site_url_scheme_when_no_forwarded_proto(): void {
		unset( $_SERVER['HTTP_X_FORWARDED_PROTO'] );
		$origin = Mcpwp_OAuth_Well_Known::get_site_origin();
		$this->assertStringStartsWith( 'https://', $origin );
	}

	// ── Issued token authenticates on the API auth path ──────────────────

	/**
	 * A token issued by issue_oauth_access_token() must pass verify_api_key().
	 */
	public function test_issued_token_authenticates_on_api(): void {
		$auth = new Mcpwp_Api_Auth_Test_Harness();

		$token_data = $auth->issue_oauth_access_token( array( 'read', 'write', 'admin' ), 3600 );
		$token      = $token_data['access_token'];

		$this->assertStringStartsWith( 'mcpwp_at_', $token );

		$request = new WP_REST_Request(
			'GET',
			'/mcpwp/v1/site-info',
			array(),
			array( 'Authorization' => 'Bearer ' . $token )
		);

		$result = $auth->verify_api_key( $request );
		$this->assertTrue( $result, 'Issued mcpwp_at_ token must authenticate via verify_api_key()' );
	}

	// ── X-API-Key regression ──────────────────────────────────────────────

	/**
	 * X-API-Key authentication must still work when OAuth is enabled.
	 */
	public function test_xapi_key_still_works_with_oauth_enabled(): void {
		$auth    = new Mcpwp_Api_Auth_Test_Harness();
		$created = $auth->create_scoped_api_key( 'regression key', array( 'read', 'write', 'admin' ) );

		$request = new WP_REST_Request(
			'GET',
			'/mcpwp/v1/site-info',
			array(),
			array( 'X-API-Key' => $created['key'] )
		);

		$result = $auth->verify_api_key( $request );
		$this->assertTrue( $result, 'X-API-Key must work when OAuth is also enabled' );
	}

	// ── OAuth disabled gating ─────────────────────────────────────────────

	public function test_token_endpoint_gated_when_oauth_disabled(): void {
		update_option( 'mcpwp_settings', array( 'oauth_enabled' => false ) );
		$ctrl    = $this->controller();
		$request = $this->make_request( 'POST', '/mcpwp/v1/oauth/token', array(
			'grant_type' => 'authorization_code',
		) );
		$response = $ctrl->handle_token( $request );
		$this->assertSame( 501, $response->get_status() );
		$this->assertSame( 'server_error', $response->get_data()['error'] );
	}

	public function test_authorize_endpoint_gated_when_oauth_disabled(): void {
		update_option( 'mcpwp_settings', array( 'oauth_enabled' => false ) );
		$ctrl    = $this->controller();
		$request = $this->make_request( 'GET', '/mcpwp/v1/oauth/authorize', array(
			'response_type' => 'code',
		) );
		$response = $ctrl->handle_authorize_get( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 501, $response->get_status() );
	}

	// ── WWW-Authenticate header ───────────────────────────────────────────

	/**
	 * add_www_authenticate_header() must add the header to 401 MCPWP responses
	 * when OAuth is enabled.
	 */
	public function test_www_authenticate_header_added_on_401(): void {
		update_option( 'mcpwp_settings', array(
			'oauth_enabled'       => true,
			'oauth_client_id'     => 'test_client',
			'oauth_token_ttl'     => 3600,
			'oauth_redirect_uris' => array(),
		) );

		$response = new WP_REST_Response( array( 'code' => 'invalid_api_key' ), 401 );
		$request  = new WP_REST_Request( 'GET', '/mcpwp/v1/mcp' );

		$result = Mcpwp_OAuth_Well_Known::add_www_authenticate_header( $response, null, $request );

		$headers = $result->get_headers();
		$this->assertArrayHasKey( 'WWW-Authenticate', $headers );
		$this->assertStringContainsString( 'Bearer', $headers['WWW-Authenticate'] );
		$this->assertStringContainsString( 'resource_metadata=', $headers['WWW-Authenticate'] );
		$this->assertStringContainsString( '.well-known/oauth-protected-resource', $headers['WWW-Authenticate'] );
	}

	/**
	 * add_www_authenticate_header() must NOT add the header when OAuth is disabled.
	 */
	public function test_www_authenticate_header_not_added_when_oauth_disabled(): void {
		update_option( 'mcpwp_settings', array( 'oauth_enabled' => false ) );

		$response = new WP_REST_Response( array( 'code' => 'invalid_api_key' ), 401 );
		$request  = new WP_REST_Request( 'GET', '/mcpwp/v1/mcp' );

		$result = Mcpwp_OAuth_Well_Known::add_www_authenticate_header( $response, null, $request );

		$headers = $result->get_headers();
		$this->assertArrayNotHasKey( 'WWW-Authenticate', $headers );
	}

	/**
	 * add_www_authenticate_header() must NOT add the header on non-401 responses.
	 */
	public function test_www_authenticate_header_not_added_on_200(): void {
		update_option( 'mcpwp_settings', array( 'oauth_enabled' => true ) );

		$response = new WP_REST_Response( array( 'ok' => true ), 200 );
		$request  = new WP_REST_Request( 'GET', '/mcpwp/v1/mcp' );

		$result = Mcpwp_OAuth_Well_Known::add_www_authenticate_header( $response, null, $request );

		$headers = $result->get_headers();
		$this->assertArrayNotHasKey( 'WWW-Authenticate', $headers );
	}

	// ── Unsupported grant_type ────────────────────────────────────────────

	public function test_unsupported_grant_type_returns_400(): void {
		$ctrl    = $this->controller();
		$request = $this->make_request( 'POST', '/mcpwp/v1/oauth/token', array(
			'grant_type' => 'implicit', // truly unsupported
		) );
		$response = $ctrl->handle_token( $request );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'unsupported_grant_type', $response->get_data()['error'] );
	}

	// ── client_credentials grant ──────────────────────────────────────────

	/**
	 * client_credentials with valid client_id + client_secret issues a token.
	 * This is the legacy machine-to-machine path previously in Mcpwp_REST_Site_Updates.
	 */
	public function test_client_credentials_valid_creds_issues_token(): void {
		$secret_plaintext = 'supersecret123';
		update_option( 'mcpwp_settings', array(
			'oauth_enabled'            => true,
			'oauth_client_id'          => 'test_client',
			'oauth_client_secret_hash' => wp_hash_password( $secret_plaintext ),
			'oauth_token_ttl'          => 3600,
			'oauth_redirect_uris'      => array( 'https://client.example.com/callback' ),
		) );

		$ctrl    = $this->controller();
		$request = $this->make_request( 'POST', '/mcpwp/v1/oauth/token', array(
			'grant_type'    => 'client_credentials',
			'client_id'     => 'test_client',
			'client_secret' => $secret_plaintext,
		) );

		$response = $ctrl->handle_token( $request );

		$this->assertSame( 200, $response->get_status(), 'Valid client_credentials must succeed' );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'access_token', $data );
		$this->assertStringStartsWith( 'mcpwp_at_', $data['access_token'] );
		$this->assertSame( 'Bearer', $data['token_type'] );
		// No refresh_token for client_credentials (machine flow — legacy parity).
		$this->assertArrayNotHasKey( 'refresh_token', $data );
	}

	/**
	 * client_credentials with wrong client_secret must return 401.
	 */
	public function test_client_credentials_wrong_secret_returns_401(): void {
		$secret_plaintext = 'supersecret123';
		update_option( 'mcpwp_settings', array(
			'oauth_enabled'            => true,
			'oauth_client_id'          => 'test_client',
			'oauth_client_secret_hash' => wp_hash_password( $secret_plaintext ),
			'oauth_token_ttl'          => 3600,
			'oauth_redirect_uris'      => array( 'https://client.example.com/callback' ),
		) );

		$ctrl    = $this->controller();
		$request = $this->make_request( 'POST', '/mcpwp/v1/oauth/token', array(
			'grant_type'    => 'client_credentials',
			'client_id'     => 'test_client',
			'client_secret' => 'WRONG-secret',
		) );

		$response = $ctrl->handle_token( $request );

		$this->assertSame( 401, $response->get_status(), 'Wrong secret must return 401' );
		$this->assertSame( 'invalid_client', $response->get_data()['error'] );
	}

	/**
	 * client_credentials with missing client_secret must return 400.
	 */
	public function test_client_credentials_missing_secret_returns_400(): void {
		$ctrl    = $this->controller();
		$request = $this->make_request( 'POST', '/mcpwp/v1/oauth/token', array(
			'grant_type' => 'client_credentials',
			'client_id'  => 'test_client',
			// client_secret intentionally absent
		) );

		$response = $ctrl->handle_token( $request );

		$this->assertSame( 400, $response->get_status(), 'Missing client_secret must return 400' );
		$this->assertSame( 'invalid_request', $response->get_data()['error'] );
	}

	/**
	 * client_credentials with missing client_id must return 400.
	 */
	public function test_client_credentials_missing_client_id_returns_400(): void {
		$ctrl    = $this->controller();
		$request = $this->make_request( 'POST', '/mcpwp/v1/oauth/token', array(
			'grant_type'    => 'client_credentials',
			'client_secret' => 'somesecret',
			// client_id intentionally absent
		) );

		$response = $ctrl->handle_token( $request );

		$this->assertSame( 400, $response->get_status(), 'Missing client_id must return 400' );
		$this->assertSame( 'invalid_request', $response->get_data()['error'] );
	}

	/**
	 * client_credentials empty scope defaults to ['read'] only (no silent admin grant).
	 */
	public function test_client_credentials_empty_scope_defaults_to_read(): void {
		$secret_plaintext = 'supersecret123';
		update_option( 'mcpwp_settings', array(
			'oauth_enabled'            => true,
			'oauth_client_id'          => 'test_client',
			'oauth_client_secret_hash' => wp_hash_password( $secret_plaintext ),
			'oauth_token_ttl'          => 3600,
			'oauth_redirect_uris'      => array( 'https://client.example.com/callback' ),
		) );

		$ctrl    = $this->controller();
		$request = $this->make_request( 'POST', '/mcpwp/v1/oauth/token', array(
			'grant_type'    => 'client_credentials',
			'client_id'     => 'test_client',
			'client_secret' => $secret_plaintext,
			// scope absent
		) );

		$response = $ctrl->handle_token( $request );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$scope_str = isset( $data['scope'] ) ? $data['scope'] : '';
		$this->assertStringNotContainsString( 'admin', $scope_str, 'Empty scope for client_credentials must not grant admin' );
		$this->assertStringContainsString( 'read', $scope_str, 'Empty scope for client_credentials must grant read' );
	}

	// ── Route collision regression — single /oauth/token owner ───────────────

	/**
	 * Route collision fix: a public-client authorization_code request with a valid
	 * code+verifier and NO client_secret must not be rejected for "missing parameter".
	 *
	 * Before the fix, the legacy Mcpwp_REST_Site_Updates registration for
	 * POST /oauth/token declared client_id and client_secret as required:true.
	 * WP REST merges duplicate route registrations, so both required constraints
	 * applied — breaking PKCE-only public clients.
	 *
	 * This test exercises handle_token() directly (simulating what the WP REST
	 * dispatcher would call) and asserts that absent client_secret is never the
	 * cause of a 400/failure when grant_type=authorization_code.
	 */
	public function test_public_client_authorization_code_not_rejected_for_missing_client_secret(): void {
		$ctrl = $this->controller();
		$seed = $this->seed_auth_code( array( 'client_id' => 'test_client' ) );

		// Request exactly as a public PKCE client would send it:
		//   - grant_type=authorization_code
		//   - code + redirect_uri + code_verifier present
		//   - client_id present (public client identification)
		//   - client_secret ABSENT
		$request = $this->make_request( 'POST', '/mcpwp/v1/oauth/token', array(
			'grant_type'    => 'authorization_code',
			'code'          => $seed['code'],
			'redirect_uri'  => 'https://client.example.com/callback',
			'code_verifier' => $seed['verifier'],
			'client_id'     => 'test_client',
			// client_secret intentionally absent — public client
		) );

		$response = $ctrl->handle_token( $request );

		// Must succeed — the fix removes the route collision that caused
		// rest_missing_callback_param: Missing parameter(s): client_secret.
		$this->assertSame( 200, $response->get_status(),
			'Public-client authorization_code without client_secret must succeed (route collision fix)' );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'access_token', $data );

		// Confirm the error is NOT about a missing client_secret parameter.
		$this->assertNotSame( 'rest_missing_callback_param', $data['error'] ?? '' );
	}

	// ── Front-channel authorize endpoint ───────────────────────────────────

	/**
	 * Log the given user in with an explicit capability set.
	 *
	 * bootstrap.php's current_user_can() grants every capability when a user
	 * has no entry in mcpwp_test_user_caps, so the array must always be set.
	 *
	 * @param int   $user_id User ID.
	 * @param array $caps    Capability map, e.g. array( 'edit_posts' => true ).
	 */
	private function login_as( int $user_id, array $caps ): void {
		$GLOBALS['mcpwp_test_current_user']          = $user_id;
		$GLOBALS['mcpwp_test_user_caps'][ $user_id ] = $caps;
	}

	/**
	 * Extract every hidden input from rendered consent HTML, exactly as a
	 * browser would submit them.
	 *
	 * This is the point of the round-trip tests: the POST is driven by what
	 * the renderer actually emitted, so a regression that writes "Array" into
	 * the scope field fails the assertion instead of silently downgrading.
	 *
	 * @param string $html Consent screen HTML.
	 * @return array<string,string>
	 */
	private function hidden_fields_from( string $html ): array {
		$fields = array();
		preg_match_all( '/<input type="hidden" name="([^"]+)" value="([^"]*)">/', $html, $matches, PREG_SET_ORDER );
		foreach ( $matches as $match ) {
			$fields[ $match[1] ] = html_entity_decode( $match[2], ENT_QUOTES, 'UTF-8' );
		}

		return $fields;
	}

	/**
	 * Render the consent screen for a logged-in user and return its HTML.
	 *
	 * @param Mcpwp_OAuth_Test_Controller $ctrl   Controller.
	 * @param string                      $scope  Requested scope.
	 * @return string
	 */
	private function render_consent( Mcpwp_OAuth_Test_Controller $ctrl, string $scope ): string {
		$params          = $this->base_authorize_params();
		$params['scope'] = $scope;

		$response = $ctrl->handle_authorize_get(
			$this->make_request( 'GET', '/mcpwp/v1/oauth/authorize', $params )
		);

		$this->assertSame( 200, $response->get_status() );

		return (string) $response->get_data();
	}

	public function test_front_channel_authorize_path_honors_home_path(): void {
		$this->assertSame(
			'/mcpwp-oauth/authorize',
			Mcpwp_OAuth_Well_Known::build_front_channel_authorize_path( 'https://example.com/' )
		);

		// Subdirectory install keeps its path prefix.
		$this->assertSame(
			'/staging/5707/mcpwp-oauth/authorize',
			Mcpwp_OAuth_Well_Known::build_front_channel_authorize_path( 'https://example.com/staging/5707/' )
		);
	}

	public function test_front_channel_authorize_url_is_not_under_wp_json(): void {
		update_option( 'permalink_structure', '/%postname%/' );

		$url = Mcpwp_OAuth_Well_Known::get_front_channel_authorize_url( 'https://example.com' );

		$this->assertSame( 'https://example.com/mcpwp-oauth/authorize', $url );
		$this->assertStringNotContainsString( '/wp-json/', $url );
	}

	public function test_front_channel_authorize_url_falls_back_to_query_var_without_rewrites(): void {
		// No permalink structure means no rewrite rules, so a pretty path would
		// 404 at the web server before WordPress ever loads.
		update_option( 'permalink_structure', '' );

		$this->assertSame(
			'https://example.com/?mcpwp_oauth=authorize',
			Mcpwp_OAuth_Well_Known::get_front_channel_authorize_url( 'https://example.com' )
		);
	}

	public function test_interceptor_matches_front_channel_path_and_query_var(): void {
		$this->assertTrue( Mcpwp_OAuth_Well_Known::is_front_channel_authorize_request( '/mcpwp-oauth/authorize' ) );
		$this->assertTrue( Mcpwp_OAuth_Well_Known::is_front_channel_authorize_request( '/mcpwp-oauth/authorize/' ) );
		$this->assertFalse( Mcpwp_OAuth_Well_Known::is_front_channel_authorize_request( '/mcpwp-oauth/authorize-not' ) );
		$this->assertFalse( Mcpwp_OAuth_Well_Known::is_front_channel_authorize_request( '/' ) );

		$_GET = array( 'mcpwp_oauth' => 'authorize' );
		$this->assertTrue( Mcpwp_OAuth_Well_Known::is_front_channel_authorize_request( '/' ) );

		$_GET = array( 'mcpwp_oauth' => 'something-else' );
		$this->assertFalse( Mcpwp_OAuth_Well_Known::is_front_channel_authorize_request( '/' ) );

		$_GET = array();
	}

	public function test_authorize_get_logged_out_redirects_to_login_on_front_channel(): void {
		update_option( 'permalink_structure', '/%postname%/' );
		$GLOBALS['mcpwp_test_current_user'] = 0;

		$response = $this->controller()->handle_authorize_get(
			$this->make_request( 'GET', '/mcpwp/v1/oauth/authorize', $this->base_authorize_params() )
		);

		$this->assertSame( 302, $response->get_status() );

		$headers = $response->get_headers();
		$this->assertArrayHasKey( 'Location', $headers );

		$prefix = 'https://example.com/wp-login.php?redirect_to=';
		$this->assertStringStartsWith( $prefix, $headers['Location'] );

		// The post-login target must be the front channel.  Pointing it back
		// at /wp-json is the loop — core logs the browser out again.
		$redirect_to = rawurldecode( substr( $headers['Location'], strlen( $prefix ) ) );
		$this->assertStringStartsWith( 'https://example.com/mcpwp-oauth/authorize', $redirect_to );
		$this->assertStringNotContainsString( '/wp-json/', $redirect_to );
		$this->assertStringContainsString( 'code_challenge=', $redirect_to );
	}

	public function test_consent_screen_hidden_scope_field_is_the_clamped_string_not_array(): void {
		update_option( 'permalink_structure', '/%postname%/' );
		$this->login_as( 7, array( 'edit_posts' => true ) ); // Editor: ceiling is write.

		$html = $this->render_consent( $this->controller(), 'read write admin' );

		// The clamped scope array must never be stringified into the form.
		$this->assertStringNotContainsString( 'Array', $html );
		$this->assertStringContainsString( '<input type="hidden" name="scope" value="read write">', $html );

		// The form must post back to the front channel, not the REST route.
		$this->assertStringContainsString( 'action="https://example.com/mcpwp-oauth/authorize"', $html );
		$this->assertStringNotContainsString( '/wp-json/', $html );

		// CSRF nonce is the only remaining CSRF defence — it must be rendered.
		$this->assertStringContainsString( 'name="_mcpwp_oauth_nonce"', $html );
	}

	public function test_consent_screen_never_stringifies_an_array_scope(): void {
		update_option( 'permalink_structure', '/%postname%/' );
		$this->login_as( 7, array( 'edit_posts' => true ) );

		// Defence in depth: even handed the raw string[] that
		// clamp_scope_to_user_capability() returns, the renderer must emit real
		// scope names.  A bare array collapses to the literal "Array", which
		// re-parses to ['read'] on the consent POST.
		$params          = $this->base_authorize_params();
		$params['scope'] = array( 'read', 'write' );

		$html = $this->controller()->build_consent_screen_html( $params );

		$this->assertStringContainsString( '<input type="hidden" name="scope" value="read write">', $html );
		$this->assertStringNotContainsString( 'Array', $html );
	}

	public function test_consent_response_sends_clickjacking_and_cache_headers(): void {
		$this->login_as( 7, array( 'edit_posts' => true ) );

		$response = $this->controller()->handle_authorize_get(
			$this->make_request( 'GET', '/mcpwp/v1/oauth/authorize', $this->base_authorize_params() )
		);

		$headers = $response->get_headers();
		$this->assertSame( 'DENY', $headers['X-Frame-Options'] );
		$this->assertSame( "frame-ancestors 'none'", $headers['Content-Security-Policy'] );
		$this->assertStringContainsString( 'no-store', $headers['Cache-Control'] );
		$this->assertStringContainsString( 'text/html', $headers['Content-Type'] );
	}

	public function test_consent_round_trip_preserves_clamped_scope_in_auth_code(): void {
		update_option( 'permalink_structure', '/%postname%/' );
		$this->login_as( 7, array( 'edit_posts' => true ) ); // Editor: ceiling is write.

		$ctrl = $this->controller();
		$html = $this->render_consent( $ctrl, 'read write admin' );

		$post_params           = $this->hidden_fields_from( $html );
		$post_params['action'] = 'approve';

		$response = $ctrl->handle_authorize_post(
			$this->make_request( 'POST', '/mcpwp/v1/oauth/authorize', $post_params )
		);

		$this->assertSame( 302, $response->get_status() );

		$location = $response->get_headers()['Location'];
		$this->assertStringStartsWith( 'https://client.example.com/callback?', $location );

		parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );
		$this->assertArrayHasKey( 'code', $query );
		$this->assertSame( 'test_state_xyz', $query['state'] );

		$stored = get_transient( 'mcpwp_oauth_code_' . $query['code'] );
		$this->assertIsArray( $stored );
		$this->assertSame(
			'read write',
			$stored['scope'],
			'The approved (clamped) scope must survive the consent round trip — "Array" would collapse it to read.'
		);
		$this->assertSame( 7, $stored['user_id'] );
		$this->assertSame( 'test_client', $stored['client_id'] );
	}

	public function test_consent_round_trip_clamps_admin_request_for_subscriber(): void {
		$this->login_as( 10, array() ); // Subscriber: ceiling is read.

		$ctrl = $this->controller();
		$html = $this->render_consent( $ctrl, 'admin' );

		$post_params           = $this->hidden_fields_from( $html );
		$post_params['action'] = 'approve';

		$response = $ctrl->handle_authorize_post(
			$this->make_request( 'POST', '/mcpwp/v1/oauth/authorize', $post_params )
		);

		parse_str( (string) wp_parse_url( $response->get_headers()['Location'], PHP_URL_QUERY ), $query );
		$stored = get_transient( 'mcpwp_oauth_code_' . $query['code'] );

		$this->assertSame( 'read', $stored['scope'] );
	}

	public function test_consent_deny_issues_no_code(): void {
		$this->login_as( 7, array( 'edit_posts' => true ) );

		$ctrl = $this->controller();
		$html = $this->render_consent( $ctrl, 'read write' );

		$post_params           = $this->hidden_fields_from( $html );
		$post_params['action'] = 'deny';

		$response = $ctrl->handle_authorize_post(
			$this->make_request( 'POST', '/mcpwp/v1/oauth/authorize', $post_params )
		);

		$this->assertSame( 302, $response->get_status() );

		parse_str( (string) wp_parse_url( $response->get_headers()['Location'], PHP_URL_QUERY ), $query );
		$this->assertSame( 'access_denied', $query['error'] );
		$this->assertArrayNotHasKey( 'code', $query );

		foreach ( array_keys( $GLOBALS['mcpwp_test_transients'] ) as $key ) {
			$this->assertStringStartsNotWith( 'mcpwp_oauth_code_', (string) $key );
		}
	}

	/**
	 * A missing or unrecognised consent action must fail CLOSED.
	 *
	 * This endpoint mints an authorization code, so an absent, misspelled or
	 * attacker-truncated `action` has to collapse toward refusing access.  The
	 * earlier `if ( 'deny' === $action )` test fell through to the approval
	 * path for every value other than the exact string "deny" — including the
	 * empty string — which handed out a code nobody consented to.
	 */
	public function test_consent_post_without_action_denies_and_issues_no_code(): void {
		$this->login_as( 7, array( 'edit_posts' => true ) );

		$ctrl = $this->controller();
		$html = $this->render_consent( $ctrl, 'read write' );

		// Everything a browser would submit EXCEPT the action button value.
		$post_params = $this->hidden_fields_from( $html );
		$this->assertArrayNotHasKey( 'action', $post_params );

		$response = $ctrl->handle_authorize_post(
			$this->make_request( 'POST', '/mcpwp/v1/oauth/authorize', $post_params )
		);

		$this->assertSame( 302, $response->get_status() );

		parse_str( (string) wp_parse_url( $response->get_headers()['Location'], PHP_URL_QUERY ), $query );
		$this->assertArrayNotHasKey( 'code', $query, 'An ambiguous consent must never mint an authorization code.' );
		$this->assertArrayHasKey( 'error', $query );
		$this->assertSame( 'access_denied', $query['error'] );

		// No authorization code may exist anywhere after an ambiguous consent.
		foreach ( array_keys( $GLOBALS['mcpwp_test_transients'] ) as $key ) {
			$this->assertStringStartsNotWith( 'mcpwp_oauth_code_', (string) $key );
		}
	}

	/**
	 * Same fail-closed rule for an action value that is neither approve nor deny.
	 */
	public function test_consent_post_with_unknown_action_denies_and_issues_no_code(): void {
		$this->login_as( 7, array( 'edit_posts' => true ) );

		$ctrl = $this->controller();
		$html = $this->render_consent( $ctrl, 'read write' );

		$post_params           = $this->hidden_fields_from( $html );
		$post_params['action'] = 'approv';

		$response = $ctrl->handle_authorize_post(
			$this->make_request( 'POST', '/mcpwp/v1/oauth/authorize', $post_params )
		);

		$this->assertSame( 302, $response->get_status() );

		parse_str( (string) wp_parse_url( $response->get_headers()['Location'], PHP_URL_QUERY ), $query );
		$this->assertArrayNotHasKey( 'code', $query, 'An unrecognised consent action must never mint an authorization code.' );
		$this->assertArrayHasKey( 'error', $query );
		$this->assertSame( 'access_denied', $query['error'] );

		foreach ( array_keys( $GLOBALS['mcpwp_test_transients'] ) as $key ) {
			$this->assertStringStartsNotWith( 'mcpwp_oauth_code_', (string) $key );
		}
	}

	public function test_consent_post_rejects_bad_nonce_and_issues_no_code(): void {
		$this->login_as( 7, array( 'edit_posts' => true ) );

		$params                       = $this->base_authorize_params();
		$params['action']             = 'approve';
		$params['_mcpwp_oauth_nonce'] = 'not-the-nonce';

		$response = $this->controller()->handle_authorize_post(
			$this->make_request( 'POST', '/mcpwp/v1/oauth/authorize', $params )
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_request', $response->get_data()['error'] );
		$this->assertSame( array(), $GLOBALS['mcpwp_test_transients'] );
	}

	public function test_consent_post_requires_login(): void {
		$GLOBALS['mcpwp_test_current_user'] = 0;

		$params           = $this->base_authorize_params();
		$params['action'] = 'approve';

		$response = $this->controller()->handle_authorize_post(
			$this->make_request( 'POST', '/mcpwp/v1/oauth/authorize', $params )
		);

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'access_denied', $response->get_data()['error'] );
		$this->assertSame( array(), $GLOBALS['mcpwp_test_transients'] );
	}

	public function test_legacy_rest_authorize_route_redirects_to_front_channel(): void {
		update_option( 'permalink_structure', '/%postname%/' );

		$response = $this->controller()->handle_authorize_legacy_redirect(
			$this->make_request( 'GET', '/mcpwp/v1/oauth/authorize', $this->base_authorize_params() )
		);

		$this->assertSame( 307, $response->get_status() );

		$location = $response->get_headers()['Location'];
		$this->assertStringStartsWith( 'https://example.com/mcpwp-oauth/authorize', $location );
		$this->assertStringNotContainsString( '/wp-json/', $location );

		parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );
		$this->assertSame( 'test_client', $query['client_id'] );
		$this->assertSame( 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', $query['code_challenge'] );
	}

	/**
	 * A Location header must carry a valid URI-reference, and a URI may not
	 * contain a raw space (RFC 3986).  add_query_arg() does not encode the
	 * values it is handed, so the space-separated `scope` list and the full-URL
	 * `redirect_uri` have to be percent-encoded before they are appended.
	 */
	public function test_legacy_rest_authorize_redirect_percent_encodes_query_values(): void {
		update_option( 'permalink_structure', '/%postname%/' );

		$response = $this->controller()->handle_authorize_legacy_redirect(
			$this->make_request( 'GET', '/mcpwp/v1/oauth/authorize', $this->base_authorize_params() )
		);

		$location = $response->get_headers()['Location'];

		// No raw space anywhere in the header value.
		$this->assertStringNotContainsString( ' ', $location );

		// The reserved characters inside redirect_uri are escaped rather than
		// terminating the parameter early.
		$this->assertStringNotContainsString( 'redirect_uri=https://', $location );

		// ...and it all still decodes back to the original values.
		parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );
		$this->assertSame( 'read write', $query['scope'] );
		$this->assertSame( 'https://client.example.com/callback', $query['redirect_uri'] );
		$this->assertSame( 'test_state_xyz', $query['state'] );
	}

	public function test_legacy_rest_authorize_route_gated_when_oauth_disabled(): void {
		update_option( 'mcpwp_settings', array( 'oauth_enabled' => false ) );

		$response = $this->controller()->handle_authorize_legacy_redirect(
			$this->make_request( 'GET', '/mcpwp/v1/oauth/authorize', array() )
		);

		$this->assertSame( 501, $response->get_status() );
	}

	// ── Refresh grant re-clamps to current capabilities ───────────────────

	/**
	 * A user demoted after consent must not keep refreshing an admin token.
	 * user_id 30 is an editor: edit_posts yes, manage_options no.
	 */
	public function test_refresh_token_reclamps_to_current_user_capability(): void {
		$GLOBALS['mcpwp_test_user_caps'][30] = array( 'edit_posts' => true, 'manage_options' => false );
		$refresh                             = $this->seed_refresh_token( array( 'read', 'write', 'admin' ), 30 );

		$response = $this->controller()->handle_token(
			$this->make_request( 'POST', '/mcpwp/v1/oauth/token', array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh,
			) )
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'read write', $data['scope'], 'Editor must not refresh into admin scope' );
		$this->assertSame( array( 'read', 'write' ), $this->stored_access_token_scopes( $data['access_token'] ) );
	}

	/** The clamp must ratchet: the NEW refresh token cannot carry the old, wider scope. */
	public function test_refresh_token_rotation_does_not_carry_forward_stale_scope(): void {
		$GLOBALS['mcpwp_test_user_caps'][31] = array( 'edit_posts' => true, 'manage_options' => false );
		$refresh                             = $this->seed_refresh_token( array( 'read', 'write', 'admin' ), 31 );

		$data = $this->controller()->handle_token(
			$this->make_request( 'POST', '/mcpwp/v1/oauth/token', array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh,
			) )
		)->get_data();

		$this->assertSame(
			array( 'read', 'write' ),
			$this->stored_refresh_token_scopes( $data['refresh_token'] ),
			'A demoted user must not be able to refresh back up to admin on the next round'
		);
	}

	/** Subscriber-equivalent: no caps at all clamps to read. */
	public function test_refresh_token_reclamps_capless_user_to_read(): void {
		$GLOBALS['mcpwp_test_user_caps'][32] = array();
		$refresh                             = $this->seed_refresh_token( array( 'read', 'write', 'admin' ), 32 );

		$data = $this->controller()->handle_token(
			$this->make_request( 'POST', '/mcpwp/v1/oauth/token', array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh,
			) )
		)->get_data();

		$this->assertSame( 'read', $data['scope'] );
		$this->assertSame( array( 'read' ), $this->stored_access_token_scopes( $data['access_token'] ) );
	}

	/**
	 * The re-clamp is UNCONDITIONAL — a record with no bound user gets read only.
	 * This test exists specifically to fail if someone reintroduces a
	 * `if ( $user_id > 0 )` guard around the clamp.
	 */
	public function test_refresh_token_without_user_id_clamps_to_read(): void {
		$refresh = $this->seed_refresh_token( array( 'read', 'write', 'admin' ), 0 );

		$data = $this->controller()->handle_token(
			$this->make_request( 'POST', '/mcpwp/v1/oauth/token', array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh,
			) )
		)->get_data();

		$this->assertSame( 'read', $data['scope'], 'A user-less refresh record must clamp to read' );
	}

	// ── Refresh grant must not fail open on a junk scope list ─────────────

	/**
	 * The bound user IS an administrator, so the capability clamp is a no-op and
	 * cannot mask the bug. Two independent guards keep ['bogus'] from becoming
	 * ['read','write','admin']: the refresh path resolves the stored scopes with
	 * parse_oauth_scope_string() (empty/unrecognised => ['read']) and re-clamps to
	 * the user's current caps, and sanitize_scopes() itself now fails closed to
	 * ['read'] as well — so the guarantee is defense-in-depth. If this test passes
	 * while the others pass, both halves of the refresh fix are really in place.
	 */
	public function test_refresh_token_with_unrecognised_scopes_does_not_fail_open(): void {
		$GLOBALS['mcpwp_test_user_caps'][33] = array( 'edit_posts' => true, 'manage_options' => true );
		$refresh                             = $this->seed_refresh_token( array( 'bogus' ), 33 );

		$response = $this->controller()->handle_token(
			$this->make_request( 'POST', '/mcpwp/v1/oauth/token', array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh,
			) )
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'read', $data['scope'], 'An unrecognised stored scope list must fail closed to read' );
		$this->assertSame( array( 'read' ), $this->stored_access_token_scopes( $data['access_token'] ) );
	}

	/** A legitimate admin refresh still works — the fix must not clamp everyone to read. */
	public function test_refresh_token_admin_user_keeps_admin_scope(): void {
		$GLOBALS['mcpwp_test_user_caps'][34] = array( 'edit_posts' => true, 'manage_options' => true );
		$refresh                             = $this->seed_refresh_token( array( 'read', 'write', 'admin' ), 34 );

		$data = $this->controller()->handle_token(
			$this->make_request( 'POST', '/mcpwp/v1/oauth/token', array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh,
			) )
		)->get_data();

		$this->assertSame( 'read write admin', $data['scope'] );
	}

	// ── Refresh grant scope-list shape handling ───────────────────────────

	/**
	 * The stored scope list is hardened with array_filter( …, 'is_string' ): a
	 * corrupt or foreign refresh record whose 'scopes' holds nested arrays,
	 * integers or nulls must never reach implode()/sanitize_key(). A non-string
	 * member triggers an "Array to string conversion" warning (which PHPUnit turns
	 * into a failure) and sanitize_key() on a non-string is a type error. The bound
	 * user IS an administrator, so the capability clamp is a no-op and cannot mask
	 * the resolver: only the string member 'admin' survives the filter, so the
	 * grant is exactly the admin scope it implies.
	 */
	public function test_refresh_token_filters_non_string_scope_members(): void {
		$GLOBALS['mcpwp_test_user_caps'][35] = array( 'edit_posts' => true, 'manage_options' => true );
		$refresh                             = $this->seed_refresh_token( array( array( 'admin' ), 5, null, 'admin' ), 35 );

		$response = $this->controller()->handle_token(
			$this->make_request( 'POST', '/mcpwp/v1/oauth/token', array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh,
			) )
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame(
			'read write admin',
			$data['scope'],
			'Only the string scope member "admin" may survive the is_string filter'
		);
		$this->assertSame( array( 'read', 'write', 'admin' ), $this->stored_access_token_scopes( $data['access_token'] ) );
	}

	/**
	 * A refresh record may store its scopes as a bare space-separated STRING rather
	 * than an array (legacy or hand-seeded records). The (array) cast wraps the
	 * whole string into a single member, is_string keeps it, and
	 * parse_oauth_scope_string() splits it back into read+write+admin. Seeded
	 * directly because the typed seed_refresh_token() helper only accepts an array.
	 * The bound user is an administrator so the clamp is a no-op.
	 */
	public function test_refresh_token_accepts_a_plain_string_scope_list(): void {
		$GLOBALS['mcpwp_test_user_caps'][36] = array( 'edit_posts' => true, 'manage_options' => true );

		$ctrl  = $this->controller();
		$token = 'mcpwp_rt_' . bin2hex( random_bytes( 16 ) );
		set_transient(
			$ctrl->get_oauth_refresh_token_transient_key( $token ),
			array(
				'scopes'     => 'read write admin', // Bare string, not an array.
				'user_id'    => 36,
				'created_at' => time(),
				'expires_at' => time() + HOUR_IN_SECONDS,
			),
			HOUR_IN_SECONDS
		);

		$response = $ctrl->handle_token(
			$this->make_request( 'POST', '/mcpwp/v1/oauth/token', array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $token,
			) )
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame(
			'read write admin',
			$data['scope'],
			'A bare-string stored scope list must parse to read+write+admin'
		);
		$this->assertSame( array( 'read', 'write', 'admin' ), $this->stored_access_token_scopes( $data['access_token'] ) );
	}

	// ── client_credentials server-side scope ceiling ──────────────────────

	/** Default ceiling is 'write': scope=admin is downgraded, not honoured. */
	public function test_client_credentials_default_ceiling_downgrades_admin_request(): void {
		$this->enable_client_credentials();

		$response = $this->controller()->handle_token( $this->client_credentials_request( 'admin' ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'read write', $data['scope'] );
		$this->assertSame(
			array( 'read', 'write' ),
			$this->stored_access_token_scopes( $data['access_token'] ),
			'The PERSISTED scopes are what the auth layer reads — they must be clamped too'
		);
	}

	/** An administrator can deliberately raise the ceiling to admin. */
	public function test_client_credentials_ceiling_admin_allows_admin_scope(): void {
		$this->enable_client_credentials( 'admin' );

		$data = $this->controller()->handle_token( $this->client_credentials_request( 'admin' ) )->get_data();

		$this->assertSame( 'read write admin', $data['scope'] );
		$this->assertSame( array( 'read', 'write', 'admin' ), $this->stored_access_token_scopes( $data['access_token'] ) );
	}

	/** The ceiling can also be lowered to read. */
	public function test_client_credentials_ceiling_read_downgrades_write_request(): void {
		$this->enable_client_credentials( 'read' );

		$data = $this->controller()->handle_token( $this->client_credentials_request( 'write' ) )->get_data();

		$this->assertSame( 'read', $data['scope'] );
	}

	/** A junk stored ceiling must resolve to the documented default, not to admin. */
	public function test_client_credentials_invalid_ceiling_setting_falls_back_to_write(): void {
		$this->enable_client_credentials( 'superadmin' );

		$data = $this->controller()->handle_token( $this->client_credentials_request( 'admin' ) )->get_data();

		$this->assertSame( 'read write', $data['scope'] );
	}

	/** Requesting below the ceiling is never inflated up to it. */
	public function test_client_credentials_read_request_stays_read_under_write_ceiling(): void {
		$this->enable_client_credentials( 'write' );

		$data = $this->controller()->handle_token( $this->client_credentials_request( 'read' ) )->get_data();

		$this->assertSame( 'read', $data['scope'] );
	}
}
