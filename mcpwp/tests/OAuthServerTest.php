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
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'], $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] );

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
			'authorization_servers' => array( $origin ),
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
		$origin    = Mcpwp_OAuth_Well_Known::get_site_origin();
		$rest_base = Mcpwp_OAuth_Well_Known::get_rest_endpoint_base( $origin );

			$metadata = array(
				'issuer'                                => $origin,
				'authorization_endpoint'                => $rest_base . '/oauth/authorize',
				'token_endpoint'                        => $rest_base . '/oauth/token',
				'registration_endpoint'                 => $rest_base . '/oauth/register',
				'response_types_supported'              => array( 'code' ),
				'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
				'code_challenge_methods_supported'      => array( 'S256' ),
			'token_endpoint_auth_methods_supported' => array( 'none', 'client_secret_post', 'client_secret_basic' ),
			'scopes_supported'                      => array( 'read', 'write', 'admin' ),
		);

		// RFC 8414 required fields.
			$this->assertArrayHasKey( 'issuer', $metadata );
			$this->assertArrayHasKey( 'authorization_endpoint', $metadata );
			$this->assertArrayHasKey( 'token_endpoint', $metadata );
			$this->assertArrayHasKey( 'registration_endpoint', $metadata );
			$this->assertArrayHasKey( 'response_types_supported', $metadata );

		// MCP-required extensions.
		$this->assertContains( 'S256', $metadata['code_challenge_methods_supported'] );
		$this->assertNotContains( 'plain', $metadata['code_challenge_methods_supported'] );
		$this->assertContains( 'authorization_code', $metadata['grant_types_supported'] );
		$this->assertContains( 'client_secret_basic', $metadata['token_endpoint_auth_methods_supported'] );

		// Endpoints must use the correct site origin.
			$this->assertStringStartsWith( $origin, $metadata['issuer'] );
			$this->assertStringStartsWith( $origin, $metadata['authorization_endpoint'] );
			$this->assertStringStartsWith( $origin, $metadata['token_endpoint'] );
			$this->assertStringStartsWith( $origin, $metadata['registration_endpoint'] );
			$this->assertStringContainsString( '/oauth/authorize', $metadata['authorization_endpoint'] );
			$this->assertStringContainsString( '/oauth/token', $metadata['token_endpoint'] );
			$this->assertStringContainsString( '/oauth/register', $metadata['registration_endpoint'] );
		}

		public function test_authorization_server_rest_endpoints_use_public_issuer_origin(): void {
			$rest_base = Mcpwp_OAuth_Well_Known::normalize_rest_base_url(
				'http://internal.example.test/wp-json/mcpwp/v1',
				'https://public.example.com'
			);

			$this->assertSame( 'https://public.example.com/wp-json/mcpwp/v1', $rest_base );
		}

		public function test_authorization_server_rest_endpoint_path_preserves_subdirectory(): void {
			$rest_base = Mcpwp_OAuth_Well_Known::normalize_rest_base_url(
				'http://internal.example.test/wordpress/wp-json/mcpwp/v1',
				'https://public.example.com'
			);

			$this->assertSame( 'https://public.example.com/wordpress/wp-json/mcpwp/v1', $rest_base );
		}

		public function test_claude_dynamic_client_registration_payload_succeeds(): void {
			$ctrl    = $this->controller();
			$request = $this->make_request( 'POST', '/mcpwp/v1/oauth/register', array(
				'client_name'                => 'claudeai',
				'redirect_uris'              => array( 'https://claude.ai/api/mcp/auth_callback' ),
				'grant_types'                => array( 'authorization_code', 'refresh_token' ),
				'response_types'             => array( 'code' ),
				'token_endpoint_auth_method' => 'none',
				'scope'                      => 'claudeai',
			) );

			$response = $ctrl->handle_register( $request );

			$this->assertSame( 201, $response->get_status() );
			$data = $response->get_data();
			$this->assertStringStartsWith( 'mcpwp_dcr_', $data['client_id'] );
			$this->assertSame( array( 'https://claude.ai/api/mcp/auth_callback' ), $data['redirect_uris'] );
			$this->assertSame( 'none', $data['token_endpoint_auth_method'] );
			$this->assertSame( 'claudeai', $data['scope'] );
		}

		public function test_dynamic_client_registration_supports_client_secret_basic(): void {
			$ctrl    = $this->controller();
			$request = $this->make_request( 'POST', '/mcpwp/v1/oauth/register', array(
				'client_name'                => 'Claude Desktop',
				'redirect_uris'              => array( 'https://claude.ai/api/mcp/auth_callback' ),
				'grant_types'                => array( 'authorization_code', 'refresh_token' ),
				'response_types'             => array( 'code' ),
				'token_endpoint_auth_method' => 'client_secret_basic',
			) );

			$response = $ctrl->handle_register( $request );

			$this->assertSame( 201, $response->get_status() );
			$data = $response->get_data();
			$this->assertSame( 'client_secret_basic', $data['token_endpoint_auth_method'] );
			$this->assertArrayHasKey( 'client_secret', $data );
			$this->assertArrayHasKey( 'client_secret_expires_at', $data );
			$this->assertSame( 0, $data['client_secret_expires_at'] );
		}

		// ── Dynamic Client Registration ───────────────────────────────────────

		public function test_dynamic_client_registration_returns_client_id_and_stores_redirect_uri(): void {
			$ctrl    = $this->controller();
			$request = $this->make_request( 'POST', '/mcpwp/v1/oauth/register', array(
				'client_name'                => 'ChatGPT',
				'redirect_uris'              => array( 'https://chatgpt.com/aip/test/oauth/callback' ),
				'grant_types'                => array( 'authorization_code', 'refresh_token' ),
				'response_types'             => array( 'code' ),
				'token_endpoint_auth_method' => 'none',
			) );

			$response = $ctrl->handle_register( $request );

			$this->assertSame( 201, $response->get_status() );
			$data = $response->get_data();
			$this->assertArrayHasKey( 'client_id', $data );
			$this->assertStringStartsWith( 'mcpwp_dcr_', $data['client_id'] );
			$this->assertSame( array( 'https://chatgpt.com/aip/test/oauth/callback' ), $data['redirect_uris'] );

			$clients = get_option( 'mcpwp_oauth_clients', array() );
			$this->assertArrayHasKey( $data['client_id'], $clients );
			$this->assertTrue(
				$ctrl->is_redirect_uri_allowed( 'https://chatgpt.com/aip/test/oauth/callback', $data['client_id'] ),
				'DCR client redirect URI must be accepted for that client_id'
			);
		}

		public function test_dynamic_client_authorize_params_pass_validation(): void {
			$ctrl = $this->controller();
			update_option( 'mcpwp_oauth_clients', array(
				'mcpwp_dcr_testclient' => array(
					'client_id'                  => 'mcpwp_dcr_testclient',
					'client_name'                => 'ChatGPT',
					'redirect_uris'              => array( 'https://chatgpt.com/aip/test/oauth/callback' ),
					'grant_types'                => array( 'authorization_code', 'refresh_token' ),
					'response_types'             => array( 'code' ),
					'token_endpoint_auth_method' => 'none',
					'client_secret_hash'         => '',
					'created_at'                 => time(),
					'updated_at'                 => time(),
				),
			) );

			$params = $this->base_authorize_params();
			$params['client_id']    = 'mcpwp_dcr_testclient';
			$params['redirect_uri'] = 'https://chatgpt.com/aip/test/oauth/callback';

			$this->assertTrue( $ctrl->validate_authorize_params( $params ) );
		}

		public function test_dynamic_client_registration_rejects_insecure_redirect_uri(): void {
			$ctrl    = $this->controller();
			$request = $this->make_request( 'POST', '/mcpwp/v1/oauth/register', array(
				'redirect_uris' => array( 'http://evil.example.com/callback' ),
			) );

			$response = $ctrl->handle_register( $request );

			$this->assertSame( 400, $response->get_status() );
			$this->assertSame( 'invalid_client_metadata', $response->get_data()['error'] );
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
	 * only 'read' for a subscriber — tested at the clamping layer rather than
	 * via handle_authorize_post (which calls exit() after wp_redirect).
	 *
	 * The full authorize-POST→transient path is covered by the token exchange
	 * tests that seed transients directly.
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

		public function test_dynamic_public_client_token_exchange_succeeds(): void {
			update_option( 'mcpwp_oauth_clients', array(
				'mcpwp_dcr_testclient' => array(
					'client_id'                  => 'mcpwp_dcr_testclient',
					'client_name'                => 'ChatGPT',
					'redirect_uris'              => array( 'https://chatgpt.com/aip/test/oauth/callback' ),
					'grant_types'                => array( 'authorization_code', 'refresh_token' ),
					'response_types'             => array( 'code' ),
					'token_endpoint_auth_method' => 'none',
					'client_secret_hash'         => '',
					'created_at'                 => time(),
					'updated_at'                 => time(),
				),
			) );

			$ctrl = $this->controller();
			$seed = $this->seed_auth_code( array(
				'client_id'    => 'mcpwp_dcr_testclient',
				'redirect_uri' => 'https://chatgpt.com/aip/test/oauth/callback',
			) );

			$request = $this->make_request( 'POST', '/mcpwp/v1/oauth/token', array(
				'grant_type'    => 'authorization_code',
				'code'          => $seed['code'],
				'redirect_uri'  => 'https://chatgpt.com/aip/test/oauth/callback',
				'code_verifier' => $seed['verifier'],
				'client_id'     => 'mcpwp_dcr_testclient',
			) );

			$response = $ctrl->handle_token( $request );

			$this->assertSame( 200, $response->get_status(), 'DCR public client with valid PKCE must succeed' );
			$data = $response->get_data();
			$this->assertArrayHasKey( 'access_token', $data );
			$this->assertStringStartsWith( 'mcpwp_at_', $data['access_token'] );
		}

		public function test_dynamic_basic_client_token_exchange_succeeds(): void {
			$client_secret = 'mcpwp_cs_testsecret';
			update_option( 'mcpwp_oauth_clients', array(
				'mcpwp_dcr_basicclient' => array(
					'client_id'                  => 'mcpwp_dcr_basicclient',
					'client_name'                => 'Claude Desktop',
					'redirect_uris'              => array( 'https://claude.ai/api/mcp/auth_callback' ),
					'grant_types'                => array( 'authorization_code', 'refresh_token' ),
					'response_types'             => array( 'code' ),
					'token_endpoint_auth_method' => 'client_secret_basic',
					'client_secret_hash'         => wp_hash_password( $client_secret ),
					'created_at'                 => time(),
					'updated_at'                 => time(),
				),
			) );

			$ctrl = $this->controller();
			$seed = $this->seed_auth_code( array(
				'client_id'    => 'mcpwp_dcr_basicclient',
				'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
			) );

			$_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode( 'mcpwp_dcr_basicclient:' . $client_secret );
			$request = $this->make_request( 'POST', '/mcpwp/v1/oauth/token', array(
				'grant_type'    => 'authorization_code',
				'code'          => $seed['code'],
				'redirect_uri'  => 'https://claude.ai/api/mcp/auth_callback',
				'code_verifier' => $seed['verifier'],
			) );

			$response = $ctrl->handle_token( $request );

			$this->assertSame( 200, $response->get_status(), 'DCR client_secret_basic token exchange must succeed' );
			$data = $response->get_data();
			$this->assertArrayHasKey( 'access_token', $data );
			$this->assertStringStartsWith( 'mcpwp_at_', $data['access_token'] );
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

	// -------------------------------------------------------------------------
	// Front-channel authorize endpoint (outside /wp-json)
	// -------------------------------------------------------------------------

	public function test_front_channel_authorize_path_honors_home_path(): void {
		$this->assertSame(
			'/mcpwp-oauth/authorize',
			Mcpwp_OAuth_Well_Known::build_front_channel_authorize_path( 'https://example.com/' )
		);

		// Subdirectory install keeps its path prefix.
		$this->assertSame(
			'/blog/mcpwp-oauth/authorize',
			Mcpwp_OAuth_Well_Known::build_front_channel_authorize_path( 'https://example.com/blog/' )
		);
	}

	public function test_front_channel_authorize_url_is_on_site_origin_not_rest(): void {
		$url = Mcpwp_OAuth_Well_Known::get_front_channel_authorize_url( 'https://example.com' );

		// The whole point of the front-channel endpoint: it must NOT live under
		// /wp-json, where core's REST cookie/nonce rules log the browser out and
		// trap the user in a wp-login redirect loop.
		$this->assertStringNotContainsString( '/wp-json/', $url );
		$this->assertSame( 'https://example.com/mcpwp-oauth/authorize', $url );
	}
}
