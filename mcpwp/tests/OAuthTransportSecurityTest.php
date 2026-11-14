<?php
/**
 * OAuth bearer access tokens are refused over plain HTTP.
 *
 * The refusal comes before the OAuth switch, any token lookup and any
 * rate-limit charge, so it is one fixed 403 for every token on both auth
 * planes (direct REST and the /mcp + /batch sub-request path). API keys are
 * unchanged. Local development has three documented exceptions.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-oauth-clients.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-oauth-revocation.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-oauth.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-oauth-well-known.php';

/** The auth trait with the limiter observed and the sub-request path exposed. */
class Mcpwp_OAuth_Transport_Test_Auth {
	use Mcpwp_Api_Auth;

	/** @var string[] */
	public $limited = array();

	protected function check_rate_limit( $identifier = null, $method = 'POST', $key_record = null, $weight = 1 ) {
		$this->limited[] = (string) $identifier;
		return true;
	}

	public function auth_only( $request ) {
		return $this->verify_api_key_auth_only( $request );
	}
}

final class OAuthTransportSecurityTest extends TestCase {

	const REDIRECT = 'https://client.example.com/callback';
	const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
	const SERVER_KEYS = array( 'HTTPS', 'SERVER_PORT', 'HTTP_HOST', 'HTTP_X_FORWARDED_PROTO', 'REMOTE_ADDR' );

	/** @var array */
	private $saved_server = array();
	/** @var array */
	private $saved_users;
	/** @var string|false */
	private $saved_env;

	protected function setUp(): void {
		foreach ( self::SERVER_KEYS as $key ) {
			$this->saved_server[ $key ] = array_key_exists( $key, $_SERVER ) ? $_SERVER[ $key ] : null;
		}
		$this->saved_env   = getenv( 'WP_ENVIRONMENT_TYPE' );
		$this->saved_users = $GLOBALS['mcpwp_test_users'];
		$GLOBALS['mcpwp_test_users'][]      = (object) array( 'ID' => 1, 'roles' => array(), 'user_login' => 'user1' );
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_wpdb_options'] = array();
		$GLOBALS['mcpwp_test_user_meta']    = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_user_caps']    = array( 1 => array( 'read' => true, 'edit_posts' => true, 'edit_others_posts' => true ) );
		$_SERVER['HTTPS']       = 'on';
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$_SERVER['HTTP_HOST']   = 'example.com';
		unset( $_SERVER['SERVER_PORT'], $_SERVER['HTTP_X_FORWARDED_PROTO'] );
		putenv( 'WP_ENVIRONMENT_TYPE' );
		update_option( 'home', 'https://example.com' );
		update_option(
			'mcpwp_settings',
			array(
				'oauth_enabled'       => true,
				'oauth_client_id'     => 'test_client',
				'oauth_token_ttl'     => 3600,
				'oauth_redirect_uris' => array( self::REDIRECT ),
			)
		);
	}

	protected function tearDown(): void {
		foreach ( $this->saved_server as $key => $value ) {
			if ( null === $value ) {
				unset( $_SERVER[ $key ] );
			} else {
				$_SERVER[ $key ] = $value;
			}
		}
		if ( false === $this->saved_env ) {
			putenv( 'WP_ENVIRONMENT_TYPE' );
		} else {
			putenv( 'WP_ENVIRONMENT_TYPE=' . $this->saved_env );
		}
		$GLOBALS['mcpwp_test_users']        = $this->saved_users;
		$GLOBALS['mcpwp_test_user_caps']    = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		if ( class_exists( 'Mcpwp_REST_Batch' ) ) {
			Mcpwp_REST_Batch::$is_batch_sub_request = false;
		}
	}

	// ── helpers ───────────────────────────────────────────────────────────

	/** A live access token, minted through the real token endpoint. */
	private function live_token(): string {
		$code = 'code_' . bin2hex( random_bytes( 8 ) );
		$ctrl = new Mcpwp_REST_OAuth();
		set_transient(
			'mcpwp_oauth_code_' . $code,
			array(
				'client_id'      => 'test_client',
				'redirect_uri'   => self::REDIRECT,
				'code_challenge' => $ctrl->compute_pkce_challenge( self::VERIFIER ),
				'scope'          => 'read write',
				'user_id'        => 1,
				'issued_at'      => Mcpwp_OAuth_Revocation::now(),
				'expires'        => time() + 60,
			),
			60
		);
		$response = $ctrl->handle_token(
			new WP_REST_Request(
				'POST',
				'/mcpwp/v1/oauth/token',
				array(
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'client_id'     => 'test_client',
					'redirect_uri'  => self::REDIRECT,
					'code_verifier' => self::VERIFIER,
				)
			)
		);
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		return (string) $data['access_token'];
	}

	private function bearer( string $token, string $route = '/mcpwp/v1/site-info' ): WP_REST_Request {
		return new WP_REST_Request( 'GET', $route, array(), array( 'Authorization' => 'Bearer ' . $token ) );
	}

	private function over_http(): void {
		unset( $_SERVER['HTTPS'], $_SERVER['SERVER_PORT'] );
	}

	/** Everything a caller sees of a refusal. */
	private function shape( $result ): array {
		$this->assertInstanceOf( WP_Error::class, $result );
		return array( $result->get_error_code(), $result->get_error_message(), $result->get_error_data() );
	}

	private function assert_https_required( $result, string $context = '' ): void {
		$this->assertInstanceOf( WP_Error::class, $result, $context );
		$this->assertSame( 'https_required', $result->get_error_code(), $context );
		$data = $result->get_error_data();
		$this->assertSame( 403, $data['status'], $context );
		$this->assertSame( Mcpwp_OAuth_Transport_Test_Auth::oauth_https_required_hint(), $data['hint'], $context );
	}

	// ── the refusal ──────────────────────────────────────────────────────

	public function test_a_live_token_works_over_https(): void {
		$token = $this->live_token();
		$this->assertTrue( ( new Mcpwp_OAuth_Transport_Test_Auth() )->verify_api_key( $this->bearer( $token ) ) );
	}

	public function test_a_live_token_over_plain_http_is_refused_without_leaking_it(): void {
		$token = $this->live_token();
		$this->over_http();
		$result = ( new Mcpwp_OAuth_Transport_Test_Auth() )->verify_api_key( $this->bearer( $token ) );
		$this->assert_https_required( $result );
		$this->assertStringNotContainsString( $token, wp_json_encode( $this->shape( $result ) ) );
		$this->assertStringContainsStringIgnoringCase( 'https', $result->get_error_message() );
	}

	public function test_the_refusal_is_identical_for_every_token_and_both_auth_planes(): void {
		$live    = $this->live_token();
		$revoked = $this->live_token();
		Mcpwp_OAuth_Token_Store::revoke( $revoked );
		$this->over_http();

		$shapes = array();
		foreach ( array( 'live' => $live, 'revoked' => $revoked, 'never issued' => 'mcpwp_at_' . str_repeat( 'a', 40 ), 'legacy prefix' => 'spai_at_' . str_repeat( 'b', 40 ) ) as $label => $token ) {
			$shapes[ 'direct ' . $label ] = $this->shape( ( new Mcpwp_OAuth_Transport_Test_Auth() )->verify_api_key( $this->bearer( $token ) ) );
			$shapes[ 'sub ' . $label ]    = $this->shape( ( new Mcpwp_OAuth_Transport_Test_Auth() )->auth_only( $this->bearer( $token, '/mcpwp/v1/pages' ) ) );
		}
		if ( class_exists( 'Mcpwp_REST_Batch' ) ) {
			Mcpwp_REST_Batch::$is_batch_sub_request = true;
			$shapes['flagged sub-request'] = $this->shape( ( new Mcpwp_OAuth_Transport_Test_Auth() )->verify_api_key( $this->bearer( $live ) ) );
			Mcpwp_REST_Batch::$is_batch_sub_request = false;
		}
		// OAuth switched off: still the transport answer, not a different one.
		$settings                  = get_option( 'mcpwp_settings' );
		$settings['oauth_enabled'] = false;
		update_option( 'mcpwp_settings', $settings );
		$shapes['oauth off'] = $this->shape( ( new Mcpwp_OAuth_Transport_Test_Auth() )->verify_api_key( $this->bearer( $live ) ) );

		$first = reset( $shapes );
		$this->assertSame( 'https_required', $first[0] );
		foreach ( $shapes as $label => $shape ) {
			$this->assertSame( $first, $shape, $label . ' must answer exactly like the others' );
		}
	}

	public function test_the_refusal_charges_no_rate_limit_bucket(): void {
		$this->over_http();
		$auth = new Mcpwp_OAuth_Transport_Test_Auth();
		for ( $i = 0; $i < 5; $i++ ) {
			$this->assert_https_required( $auth->verify_api_key( $this->bearer( 'mcpwp_at_' . str_repeat( 'c', 40 ) ) ) );
		}
		$this->assertSame( array(), $auth->limited, 'An http:// client must not spend the shared invalid:<ip> budget.' );

		// Contrast: the same dead token over HTTPS is counted as a failure.
		$_SERVER['HTTPS'] = 'on';
		$auth->verify_api_key( $this->bearer( 'mcpwp_at_' . str_repeat( 'c', 40 ) ) );
		$this->assertSame( array( 'invalid:203.0.113.9' ), $auth->limited );
	}

	public function test_the_refusal_does_no_token_lookup(): void {
		$this->over_http();
		// A token that WOULD migrate from a legacy transient over https.
		$token = 'mcpwp_at_' . str_repeat( 'd', 40 );
		set_transient( 'mcpwp_oauth_token_' . md5( $token ), array( 'scopes' => array( 'read' ), 'client_id' => 'test_client' ), 3600 );
		$this->assert_https_required( ( new Mcpwp_OAuth_Transport_Test_Auth() )->verify_api_key( $this->bearer( $token ) ) );
		$this->assertNull( Mcpwp_OAuth_Token_Store::find( $token ), 'Refused before the legacy migration ran.' );
	}

	public function test_api_keys_over_plain_http_are_unchanged(): void {
		$plain = 'mcpwp_' . str_repeat( 'e', 48 );
		update_option(
			'mcpwp_api_keys',
			array(
				array(
					'id'         => 'httpkey',
					'label'      => 'httpkey',
					'hash'       => wp_hash_password( $plain ),
					'scopes'     => array( 'read' ),
					'role'       => 'custom',
					'created_at' => '2026-01-01 00:00:00',
					'revoked_at' => null,
				),
			)
		);
		$requests = array(
			'X-API-Key'     => new WP_REST_Request( 'GET', '/mcpwp/v1/site-info', array(), array( 'X-API-Key' => $plain ) ),
			'Bearer apikey' => $this->bearer( $plain ),
			'wrong key'     => new WP_REST_Request( 'GET', '/mcpwp/v1/site-info', array(), array( 'X-API-Key' => 'mcpwp_wrong' ) ),
		);
		foreach ( $requests as $label => $request ) {
			$_SERVER['HTTPS'] = 'on';
			$over_https       = ( new Mcpwp_OAuth_Transport_Test_Auth() )->verify_api_key( $request );
			$this->over_http();
			$over_http = ( new Mcpwp_OAuth_Transport_Test_Auth() )->verify_api_key( $request );
			$this->assertEquals( $over_https, $over_http, $label . ': transport must not change an API key verdict' );
			if ( is_wp_error( $over_http ) ) {
				$this->assertNotSame( 'https_required', $over_http->get_error_code(), $label );
			}
		}
		$this->assertTrue( ( new Mcpwp_OAuth_Transport_Test_Auth() )->verify_api_key( $requests['X-API-Key'] ) );
	}

	// ── what counts as secure ────────────────────────────────────────────

	public function test_port_443_counts_as_https_like_core(): void {
		$token = $this->live_token();
		$this->over_http();
		$_SERVER['SERVER_PORT'] = '443';
		$this->assertTrue( ( new Mcpwp_OAuth_Transport_Test_Auth() )->verify_api_key( $this->bearer( $token ) ) );
	}

	public function test_request_is_secure_matrix(): void {
		$this->assertTrue( Mcpwp_OAuth_Transport_Test_Auth::oauth_request_is_secure( true, false, '' ) );
		$this->assertFalse( Mcpwp_OAuth_Transport_Test_Auth::oauth_request_is_secure( false, false, '' ) );
		// X-Forwarded-Proto is anyone's to send unless the proxy is trusted.
		$this->assertFalse( Mcpwp_OAuth_Transport_Test_Auth::oauth_request_is_secure( false, false, 'https' ) );
		$this->assertTrue( Mcpwp_OAuth_Transport_Test_Auth::oauth_request_is_secure( false, true, 'https' ) );
		$this->assertTrue( Mcpwp_OAuth_Transport_Test_Auth::oauth_request_is_secure( false, true, 'HTTPS, http' ) );
		// The first hop is the client-facing one.
		$this->assertFalse( Mcpwp_OAuth_Transport_Test_Auth::oauth_request_is_secure( false, true, 'http, https' ) );
		$this->assertFalse( Mcpwp_OAuth_Transport_Test_Auth::oauth_request_is_secure( false, true, 'http' ) );
		$this->assertFalse( Mcpwp_OAuth_Transport_Test_Auth::oauth_request_is_secure( false, true, '' ) );
	}

	public function test_untrusted_forwarded_proto_does_not_lift_the_refusal(): void {
		if ( defined( 'MCPWP_TRUST_PROXY_HEADERS' ) && MCPWP_TRUST_PROXY_HEADERS ) {
			$this->markTestSkipped( 'MCPWP_TRUST_PROXY_HEADERS is defined true in this process.' );
		}
		$this->over_http();
		$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
		$this->assert_https_required( ( new Mcpwp_OAuth_Transport_Test_Auth() )->verify_api_key( $this->bearer( 'mcpwp_at_' . str_repeat( 'f', 40 ) ) ) );
	}

	// ── local-development exceptions ─────────────────────────────────────

	public function test_insecure_allowed_matrix(): void {
		$c = 'Mcpwp_OAuth_Transport_Test_Auth';
		$this->assertTrue( $c::oauth_insecure_transport_allowed( true, 'production', 'https://example.com', 'example.com' ), 'constant' );
		$this->assertTrue( $c::oauth_insecure_transport_allowed( false, 'local', 'http://example.test', 'example.test' ), 'local env' );
		$this->assertFalse( $c::oauth_insecure_transport_allowed( false, 'development', 'http://dev.example.com', 'dev.example.com' ), 'development is not local' );
		$this->assertFalse( $c::oauth_insecure_transport_allowed( false, 'staging', 'http://stage.example.com', 'stage.example.com' ), 'staging is not local' );
		$this->assertFalse( $c::oauth_insecure_transport_allowed( false, 'production', 'http://example.com', 'example.com' ) );
		$this->assertTrue( $c::oauth_insecure_transport_allowed( false, 'production', 'http://localhost:8080', 'localhost:8080' ) );
		$this->assertTrue( $c::oauth_insecure_transport_allowed( false, 'production', 'http://127.0.0.1', '127.0.0.1' ) );
		$this->assertTrue( $c::oauth_insecure_transport_allowed( false, 'production', 'http://[::1]:8080/wp', '[::1]:8080' ) );
		$this->assertTrue( $c::oauth_insecure_transport_allowed( false, 'production', 'http://site.localhost', 'site.localhost' ) );
		// Both must be loopback: a localhost site reached by another name...
		$this->assertFalse( $c::oauth_insecure_transport_allowed( false, 'production', 'http://localhost:8080', '192.168.1.5:8080' ) );
		// ...and a public site whose Host header claims localhost.
		$this->assertFalse( $c::oauth_insecure_transport_allowed( false, 'production', 'http://example.com', 'localhost' ) );
		$this->assertFalse( $c::oauth_insecure_transport_allowed( false, 'production', '', 'localhost' ) );
		$this->assertFalse( $c::oauth_insecure_transport_allowed( false, 'production', 'http://localhost', '' ) );
	}

	public function test_loopback_host_matrix(): void {
		$c = 'Mcpwp_OAuth_Transport_Test_Auth';
		foreach ( array( 'localhost', 'LOCALHOST', 'localhost:80', 'localhost.', '127.0.0.1', '127.0.0.1:18931', '::1', '[::1]', '[::1]:8080', 'wp.localhost', 'a.b.localhost:9' ) as $host ) {
			$this->assertTrue( $c::is_loopback_host( $host ), $host );
		}
		foreach ( array( '', '.localhost', 'localhost.example.com', 'notlocalhost', 'example.com', '127.0.0.2', '10.0.0.1', '[::2]', '[::1', 'evil-localhost', '0.0.0.0' ) as $host ) {
			$this->assertFalse( $c::is_loopback_host( $host ), $host );
		}
	}

	public function test_a_loopback_site_accepts_a_token_over_http(): void {
		// The token's audience follows the configured address, so mint it after.
		update_option( 'home', 'http://localhost:8080' );
		$token = $this->live_token();
		$this->over_http();
		$_SERVER['HTTP_HOST'] = 'localhost:8080';
		$this->assertTrue( ( new Mcpwp_OAuth_Transport_Test_Auth() )->verify_api_key( $this->bearer( $token ) ) );

		$_SERVER['HTTP_HOST'] = 'lan-name.example:8080';
		$this->assert_https_required( ( new Mcpwp_OAuth_Transport_Test_Auth() )->verify_api_key( $this->bearer( $token ) ) );
	}

	public function test_a_local_environment_accepts_a_token_over_http(): void {
		if ( defined( 'WP_ENVIRONMENT_TYPE' ) ) {
			$this->markTestSkipped( 'WP_ENVIRONMENT_TYPE is defined in this process.' );
		}
		$token = $this->live_token();
		$this->over_http();
		putenv( 'WP_ENVIRONMENT_TYPE=local' );
		$this->assertTrue( ( new Mcpwp_OAuth_Transport_Test_Auth() )->verify_api_key( $this->bearer( $token ) ) );
		putenv( 'WP_ENVIRONMENT_TYPE=development' );
		$this->assert_https_required( ( new Mcpwp_OAuth_Transport_Test_Auth() )->verify_api_key( $this->bearer( $token ) ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_constant_accepts_a_token_over_http(): void {
		define( 'MCPWP_ALLOW_INSECURE_OAUTH', true );
		$token = $this->live_token();
		$this->over_http();
		$this->assertTrue( ( new Mcpwp_OAuth_Transport_Test_Auth() )->verify_api_key( $this->bearer( $token ) ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_constant_must_be_true_not_truthy(): void {
		define( 'MCPWP_ALLOW_INSECURE_OAUTH', 'yes' );
		$this->over_http();
		$this->assert_https_required( ( new Mcpwp_OAuth_Transport_Test_Auth() )->verify_api_key( $this->bearer( 'mcpwp_at_' . str_repeat( 'g', 40 ) ) ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_trusted_proxy_reporting_https_is_accepted(): void {
		define( 'MCPWP_TRUST_PROXY_HEADERS', true );
		$token = $this->live_token();
		$this->over_http();
		$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
		$this->assertTrue( ( new Mcpwp_OAuth_Transport_Test_Auth() )->verify_api_key( $this->bearer( $token ) ) );
		$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'http';
		$this->assert_https_required( ( new Mcpwp_OAuth_Transport_Test_Auth() )->verify_api_key( $this->bearer( $token ) ) );
	}

	// ── one hint ─────────────────────────────────────────────────────────

	public function test_error_hints_carry_the_same_text(): void {
		$hint = Mcpwp_Error_Hints::get_hint_for_code( 'https_required' );
		$this->assertSame( Mcpwp_OAuth_Transport_Test_Auth::oauth_https_required_hint(), $hint['hint'] );
		$this->assertStringContainsString( 'MCPWP_ALLOW_INSECURE_OAUTH', $hint['hint'] );
	}

	public function test_the_403_gets_no_reconnect_challenge(): void {
		update_option( 'mcpwp_settings', array( 'oauth_enabled' => true ) );
		$error    = Mcpwp_OAuth_Transport_Test_Auth::oauth_https_required_error();
		$response = new WP_REST_Response( array( 'code' => $error->get_error_code(), 'message' => $error->get_error_message(), 'data' => $error->get_error_data() ), 403 );
		$out      = Mcpwp_OAuth_Well_Known::add_www_authenticate_header( $response, null, new WP_REST_Request( 'POST', '/mcpwp/v1/mcp' ) );
		$headers  = method_exists( $out, 'get_headers' ) ? $out->get_headers() : array();
		$this->assertArrayNotHasKey( 'WWW-Authenticate', $headers, 'A challenge would make the client sign in again over the same http URL.' );
	}
}
