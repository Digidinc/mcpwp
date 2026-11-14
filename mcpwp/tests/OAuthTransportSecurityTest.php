<?php
/**
 * OAuth bearer access tokens are refused over plain HTTP.
 *
 * The refusal comes before the OAuth switch, validation and any rate-limit
 * charge, so it is one fixed 403 for every token on both auth planes (direct
 * REST and the /mcp + /batch sub-request path). A live token is revoked with
 * its refresh family on the way. The consent screen and the local OAuth
 * discovery documents are refused on the same transport. Refusals are shown
 * to an administrator (Site Health, notice). API keys are unchanged. Local
 * development has three documented exceptions.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-oauth-clients.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-oauth-revocation.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-oauth.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-oauth-well-known.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-oauth-transport.php';

/** The auth trait with the limiter observed and the sub-request path exposed. */
class Mcpwp_OAuth_Transport_Test_Auth {
	use Mcpwp_Api_Auth;

	/** @var string[] */
	public $limited = array();
	/** @var bool Answer the 'insecure:' bucket as over budget. */
	public $insecure_over = false;

	protected function check_rate_limit( $identifier = null, $method = 'POST', $key_record = null, $weight = 1 ) {
		$this->limited[] = (string) $identifier;
		if ( $this->insecure_over && 0 === strpos( (string) $identifier, 'insecure:' ) ) {
			return new WP_Error( 'rate_limit_exceeded', 'over', array( 'status' => 429 ) );
		}
		return true;
	}

	public function auth_only( $request ) {
		return $this->verify_api_key_auth_only( $request );
	}
}

final class OAuthTransportSecurityTest extends TestCase {

	const REDIRECT    = 'https://client.example.com/callback';
	const VERIFIER    = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
	const SERVER_KEYS = array( 'HTTPS', 'SERVER_PORT', 'HTTP_HOST', 'HTTP_X_FORWARDED_PROTO', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED_HOST', 'HTTP_FORWARDED', 'HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP', 'HTTP_CF_VISITOR', 'REMOTE_ADDR' );

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
		$GLOBALS['mcpwp_test_filters']      = array();
		$GLOBALS['mcpwp_test_user_caps']    = array( 1 => array( 'read' => true, 'edit_posts' => true, 'edit_others_posts' => true ) );
		$_SERVER['HTTPS']       = 'on';
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$_SERVER['HTTP_HOST']   = 'example.com';
		unset( $_SERVER['SERVER_PORT'], $_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_FORWARDED_HOST'], $_SERVER['HTTP_FORWARDED'], $_SERVER['HTTP_X_REAL_IP'], $_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_CF_VISITOR'] );
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
		$GLOBALS['mcpwp_test_filters']      = array();
		if ( class_exists( 'Mcpwp_REST_Batch' ) ) {
			Mcpwp_REST_Batch::$is_batch_sub_request = false;
		}
	}

	// ── helpers ───────────────────────────────────────────────────────────

	/** A live grant (access + refresh), minted through the real token endpoint. */
	private function grant(): array {
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
		return $response->get_data();
	}

	private function live_token(): string {
		$data = $this->grant();
		return (string) $data['access_token'];
	}

	private function refresh( string $refresh_token ): WP_REST_Response {
		return ( new Mcpwp_REST_OAuth() )->handle_token(
			new WP_REST_Request( 'POST', '/mcpwp/v1/oauth/token', array( 'grant_type' => 'refresh_token', 'refresh_token' => $refresh_token, 'client_id' => 'test_client' ) )
		);
	}

	private function bearer( string $token, string $route = '/mcpwp/v1/site-info' ): WP_REST_Request {
		return new WP_REST_Request( 'GET', $route, array(), array( 'Authorization' => 'Bearer ' . $token ) );
	}

	private function verify( string $token ) {
		return ( new Mcpwp_OAuth_Transport_Test_Auth() )->verify_api_key( $this->bearer( $token ) );
	}

	private function over_http(): void {
		unset( $_SERVER['HTTPS'], $_SERVER['SERVER_PORT'] );
	}

	private function over_https(): void {
		$_SERVER['HTTPS'] = 'on';
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
		$this->assertSame( Mcpwp_OAuth_Transport::refusal_error()->get_error_data()['hint'], $data['hint'], $context );
	}

	/** What a client sees: the permission callback's WP_Error through the server and rest_post_dispatch. */
	private function wire( $error, string $route = '/mcpwp/v1/mcp' ): array {
		$this->assertInstanceOf( WP_Error::class, $error );
		Mcpwp_OAuth_Well_Known::init();
		$server   = new WP_REST_Server();
		$response = $server->error_to_response( $error );
		$response = apply_filters( 'rest_post_dispatch', $response, $server, new WP_REST_Request( 'POST', $route ) );
		return array( 'status' => $response->get_status(), 'body' => $response->get_data(), 'headers' => $response->get_headers() );
	}

	/** Every stored thing except the refusal record. */
	private function store_snapshot(): array {
		$transients = $GLOBALS['mcpwp_test_transients'];
		unset( $transients[ Mcpwp_OAuth_Transport::REFUSALS_TRANSIENT ] );
		return array( $GLOBALS['mcpwp_test_oauth_tokens'], $GLOBALS['mcpwp_test_options'], $transients, $GLOBALS['mcpwp_test_wpdb_options'] );
	}

	// ── the refusal ──────────────────────────────────────────────────────

	public function test_a_live_token_works_over_https(): void {
		$this->assertTrue( $this->verify( $this->live_token() ) );
	}

	public function test_a_live_token_over_plain_http_is_refused_without_leaking_it(): void {
		$token = $this->live_token();
		$this->over_http();
		$result = $this->verify( $token );
		$this->assert_https_required( $result );
		$this->assertStringNotContainsString( $token, wp_json_encode( $this->shape( $result ) ) );
		$this->assertStringContainsStringIgnoringCase( 'https', $result->get_error_message() );
		$this->assertStringNotContainsString( $token, wp_json_encode( Mcpwp_OAuth_Transport::get_record() ) );
	}

	public function test_a_token_refused_over_http_is_dead_afterwards_with_its_refresh_family(): void {
		$grant = $this->grant();
		$other = $this->grant();
		$this->over_http();
		$this->assert_https_required( $this->verify( (string) $grant['access_token'] ) );

		$this->over_https();
		$after = $this->verify( (string) $grant['access_token'] );
		$this->assertInstanceOf( WP_Error::class, $after, 'The access token sent over http must not work over https afterwards.' );
		$this->assertSame( 'invalid_token', $after->get_error_code() );
		$refresh = $this->refresh( (string) $grant['refresh_token'] );
		$this->assertSame( 400, $refresh->get_status(), 'Its refresh token (same family) is dead too.' );
		$this->assertSame( 'invalid_grant', $refresh->get_data()['error'] );

		// Another grant of the same user and client is untouched.
		$this->assertTrue( $this->verify( (string) $other['access_token'] ) );
	}

	public function test_a_legacy_transient_token_refused_over_http_is_dead_afterwards(): void {
		$token = 'mcpwp_at_' . str_repeat( 'd', 40 );
		set_transient( 'mcpwp_oauth_token_' . md5( $token ), array( 'scopes' => array( 'read' ), 'client_id' => 'test_client' ), 3600 );
		$this->over_http();
		$this->assert_https_required( $this->verify( $token ) );
		$this->assertFalse( get_transient( 'mcpwp_oauth_token_' . md5( $token ) ) );
		$this->assertNull( Mcpwp_OAuth_Token_Store::find( $token ), 'Not migrated into the table either.' );
	}

	public function test_an_unknown_token_gets_the_identical_403_and_writes_nothing(): void {
		$live = $this->live_token();
		$this->over_http();
		$before  = $this->store_snapshot();
		$unknown = $this->verify( 'mcpwp_at_' . str_repeat( 'a', 40 ) );
		$this->assertSame( $before, $this->store_snapshot(), 'An unknown token must cause no token-store, option or transient write.' );
		// An already-revoked one writes nothing either.
		$this->verify( $live );
		$after_first = $this->store_snapshot();
		$this->verify( $live );
		$this->assertSame( $after_first, $this->store_snapshot(), 'Revoking twice writes nothing the second time.' );
		$this->assertSame( $this->shape( $unknown ), $this->shape( $this->verify( $live ) ) );
	}

	public function test_the_refusal_is_identical_for_every_token_and_both_auth_planes(): void {
		$live    = $this->live_token();
		$revoked = $this->live_token();
		Mcpwp_OAuth_Token_Store::revoke( $revoked );
		$this->over_http();

		$shapes = array();
		foreach ( array( 'live' => $live, 'revoked' => $revoked, 'never issued' => 'mcpwp_at_' . str_repeat( 'a', 40 ), 'legacy prefix' => 'spai_at_' . str_repeat( 'b', 40 ) ) as $label => $token ) {
			$shapes[ 'direct ' . $label ] = $this->shape( $this->verify( $token ) );
			$shapes[ 'sub ' . $label ]    = $this->shape( ( new Mcpwp_OAuth_Transport_Test_Auth() )->auth_only( $this->bearer( $token, '/mcpwp/v1/pages' ) ) );
		}
		if ( class_exists( 'Mcpwp_REST_Batch' ) ) {
			Mcpwp_REST_Batch::$is_batch_sub_request = true;
			$shapes['flagged sub-request'] = $this->shape( $this->verify( $live ) );
			Mcpwp_REST_Batch::$is_batch_sub_request = false;
		}
		// OAuth switched off: still the transport answer, not a different one.
		$settings                  = get_option( 'mcpwp_settings' );
		$settings['oauth_enabled'] = false;
		update_option( 'mcpwp_settings', $settings );
		$shapes['oauth off'] = $this->shape( $this->verify( $live ) );

		$first = reset( $shapes );
		$this->assertSame( 'https_required', $first[0] );
		foreach ( $shapes as $label => $shape ) {
			$this->assertSame( $first, $shape, $label . ' must answer exactly like the others' );
		}
	}

	public function test_the_refusal_charges_only_its_own_bucket(): void {
		$this->over_http();
		$auth = new Mcpwp_OAuth_Transport_Test_Auth();
		for ( $i = 0; $i < 5; $i++ ) {
			$this->assert_https_required( $auth->verify_api_key( $this->bearer( 'mcpwp_at_' . str_repeat( 'c', 40 ) ) ) );
		}
		$this->assertSame( array_fill( 0, 5, 'insecure:203.0.113.9' ), $auth->limited, 'Never the invalid:<ip> budget wrong credentials share.' );

		// Contrast: the same dead token over HTTPS is counted as a failure.
		$this->over_https();
		$auth->limited = array();
		$auth->verify_api_key( $this->bearer( 'mcpwp_at_' . str_repeat( 'c', 40 ) ) );
		$this->assertSame( array( 'invalid:203.0.113.9' ), $auth->limited );
	}

	public function test_the_sub_request_path_charges_nothing(): void {
		$this->over_http();
		$auth = new Mcpwp_OAuth_Transport_Test_Auth();
		$this->assert_https_required( $auth->auth_only( $this->bearer( 'mcpwp_at_' . str_repeat( 'c', 40 ) ) ) );
		$this->assertSame( array(), $auth->limited );
	}

	public function test_over_the_insecure_budget_the_lookup_is_skipped_and_the_answer_is_the_same(): void {
		$token = $this->live_token();
		$this->over_http();
		$under = $this->shape( $this->verify( 'mcpwp_at_' . str_repeat( 'm', 40 ) ) );

		$auth                = new Mcpwp_OAuth_Transport_Test_Auth();
		$auth->insecure_over = true;
		$before              = $this->store_snapshot();
		$over                = $auth->verify_api_key( $this->bearer( $token ) );
		$this->assertSame( $under, $this->shape( $over ), 'Identical answer over budget.' );
		$this->assertSame( $before, $this->store_snapshot(), 'Over budget: no lookup, no revocation, no record.' );
		$this->assertFalse( Mcpwp_OAuth_Transport::get_record() );

		$this->over_https();
		$this->assertTrue( $this->verify( $token ), 'The skipped token was not revoked.' );
	}

	public function test_the_403_reaches_the_client_without_a_reconnect_challenge(): void {
		$this->over_http();
		$wire = $this->wire( $this->verify( $this->live_token() ) );
		$this->assertSame( 403, $wire['status'] );
		$this->assertSame( 'https_required', $wire['body']['code'] );
		$this->assertArrayNotHasKey( 'WWW-Authenticate', $wire['headers'], 'A challenge would make the client sign in again over the same http URL.' );
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
			$this->over_https();
			$over_https = ( new Mcpwp_OAuth_Transport_Test_Auth() )->verify_api_key( $request );
			$this->over_http();
			$over_http = ( new Mcpwp_OAuth_Transport_Test_Auth() )->verify_api_key( $request );
			$this->assertEquals( $over_https, $over_http, $label . ': transport must not change an API key verdict' );
			if ( is_wp_error( $over_http ) ) {
				$this->assertNotSame( 'https_required', $over_http->get_error_code(), $label );
			}
		}
		$this->assertTrue( ( new Mcpwp_OAuth_Transport_Test_Auth() )->verify_api_key( $requests['X-API-Key'] ) );
	}

	// ── the text ─────────────────────────────────────────────────────────

	public function test_an_https_site_reached_through_a_proxy_over_http_gets_the_proxy_text(): void {
		$this->over_http();
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.4';
		$error = $this->verify( 'mcpwp_at_' . str_repeat( 'h', 40 ) );
		$this->assertStringContainsString( 'proxy', $error->get_error_message() );
		$hint = $error->get_error_data()['hint'];
		$this->assertStringStartsWith( 'Use the site\'s https:// MCP URL.', $hint );
		$this->assertStringContainsString( 'configure the proxy or host', $hint );
		$this->assertStringContainsString( 'reached solely through that proxy', $hint, 'The snippet is conditional.' );
		$this->assertStringContainsString( 'fake HTTPS', $hint, 'The forgery risk is stated.' );
		$snippet = strpos( $hint, '$_SERVER[\'HTTPS\'] = \'on\'' );
		$trust   = strpos( $hint, 'MCPWP_TRUST_PROXY_HEADERS' );
		$this->assertNotFalse( $snippet );
		$this->assertNotFalse( $trust );
		$this->assertLessThan( $trust, $snippet, 'The wp-config snippet comes first.' );
		$this->assertStringContainsString( 'client-IP headers', $hint, 'The trust constant\'s side effect is stated.' );
		$this->assertStringContainsString( 'revoked', $hint );
	}

	public function test_an_https_site_without_proxy_headers_gets_the_plain_text(): void {
		$this->over_http();
		$error = $this->verify( 'mcpwp_at_' . str_repeat( 'n', 40 ) );
		$this->assertStringNotContainsString( 'proxy', $error->get_error_message() );
		$this->assertSame( Mcpwp_OAuth_Transport::hint( false ), $error->get_error_data()['hint'] );
	}

	public function test_an_http_site_gets_the_plain_text(): void {
		update_option( 'home', 'http://example.com' );
		$this->over_http();
		$error = $this->verify( 'mcpwp_at_' . str_repeat( 'i', 40 ) );
		$this->assertStringNotContainsString( 'proxy in front', $error->get_error_message() );
		$this->assertSame( Mcpwp_OAuth_Transport::hint( false ), $error->get_error_data()['hint'] );
	}

	public function test_no_client_facing_text_names_the_insecure_escape_hatch(): void {
		foreach ( array( true, false ) as $proxy ) {
			$this->assertStringNotContainsString( 'MCPWP_ALLOW_INSECURE_OAUTH', Mcpwp_OAuth_Transport::hint( $proxy ) );
			$this->assertStringNotContainsString( 'MCPWP_ALLOW_INSECURE_OAUTH', Mcpwp_OAuth_Transport::message( $proxy ) );
		}
	}

	public function test_error_hints_carry_the_same_text(): void {
		$hint = Mcpwp_Error_Hints::get_hint_for_code( 'https_required' );
		$this->assertSame( Mcpwp_OAuth_Transport::hint( false ), $hint['hint'] );
	}

	// ── what counts as secure ────────────────────────────────────────────

	public function test_port_443_counts_as_https_like_core(): void {
		$token = $this->live_token();
		$this->over_http();
		$_SERVER['SERVER_PORT'] = '443';
		$this->assertTrue( $this->verify( $token ) );
	}

	public function test_request_is_secure_matrix(): void {
		$c = 'Mcpwp_OAuth_Transport';
		$this->assertTrue( $c::request_is_secure( true, false, '' ) );
		$this->assertFalse( $c::request_is_secure( false, false, '' ) );
		// X-Forwarded-Proto is anyone's to send unless the proxy is trusted.
		$this->assertFalse( $c::request_is_secure( false, false, 'https' ) );
		$this->assertTrue( $c::request_is_secure( false, true, 'https' ) );
		$this->assertTrue( $c::request_is_secure( false, true, 'HTTPS, https' ) );
		// Every hop must say https: a client-sent "https" in front of a
		// proxy-appended "http" is not enough, nor the other way round.
		$this->assertFalse( $c::request_is_secure( false, true, 'https, http' ) );
		$this->assertFalse( $c::request_is_secure( false, true, 'http, https' ) );
		$this->assertFalse( $c::request_is_secure( false, true, 'https,' ) );
		$this->assertFalse( $c::request_is_secure( false, true, 'http' ) );
		$this->assertFalse( $c::request_is_secure( false, true, '' ) );
		$this->assertFalse( $c::request_is_secure( false, true, '  ' ) );
	}

	public function test_untrusted_forwarded_proto_does_not_lift_the_refusal(): void {
		if ( Mcpwp_OAuth_Transport::trust_proxy_headers() ) {
			$this->markTestSkipped( 'MCPWP_TRUST_PROXY_HEADERS is defined true in this process.' );
		}
		$this->over_http();
		$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
		$this->assert_https_required( $this->verify( 'mcpwp_at_' . str_repeat( 'f', 40 ) ) );
	}

	// ── local-development exceptions ─────────────────────────────────────

	public function test_insecure_allowed_matrix(): void {
		$c = 'Mcpwp_OAuth_Transport';
		$this->assertTrue( $c::insecure_transport_allowed( true, 'production', 'https://example.com', 'example.com' ), 'constant' );
		$this->assertTrue( $c::insecure_transport_allowed( false, 'local', 'http://example.test', 'example.test' ), 'local env' );
		$this->assertFalse( $c::insecure_transport_allowed( false, 'development', 'http://dev.example.com', 'dev.example.com' ), 'development is not local' );
		$this->assertFalse( $c::insecure_transport_allowed( false, 'staging', 'http://stage.example.com', 'stage.example.com' ), 'staging is not local' );
		$this->assertFalse( $c::insecure_transport_allowed( false, 'production', 'http://example.com', 'example.com' ) );
		$this->assertTrue( $c::insecure_transport_allowed( false, 'production', 'http://localhost:8080', 'localhost:8080' ) );
		$this->assertTrue( $c::insecure_transport_allowed( false, 'production', 'http://127.0.0.1', '127.0.0.1' ) );
		$this->assertTrue( $c::insecure_transport_allowed( false, 'production', 'http://[::1]:8080/wp', '[::1]:8080' ) );
		$this->assertTrue( $c::insecure_transport_allowed( false, 'production', 'http://site.localhost', 'site.localhost' ) );
		// Both must be loopback: a localhost site reached by another name...
		$this->assertFalse( $c::insecure_transport_allowed( false, 'production', 'http://localhost:8080', '192.168.1.5:8080' ) );
		// ...and a public site whose Host header claims localhost.
		$this->assertFalse( $c::insecure_transport_allowed( false, 'production', 'http://example.com', 'localhost' ) );
		$this->assertFalse( $c::insecure_transport_allowed( false, 'production', '', 'localhost' ) );
		$this->assertFalse( $c::insecure_transport_allowed( false, 'production', 'http://localhost', '' ) );
	}

	public function test_loopback_host_matrix(): void {
		$c = 'Mcpwp_OAuth_Transport';
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
		$this->assertTrue( $this->verify( $token ) );

		$_SERVER['HTTP_HOST'] = 'lan-name.example:8080';
		$this->assert_https_required( $this->verify( $token ) );
	}

	public function test_a_local_environment_accepts_a_token_over_http(): void {
		if ( defined( 'WP_ENVIRONMENT_TYPE' ) ) {
			$this->markTestSkipped( 'WP_ENVIRONMENT_TYPE is defined in this process.' );
		}
		$token = $this->live_token();
		$this->over_http();
		putenv( 'WP_ENVIRONMENT_TYPE=local' );
		$this->assertTrue( $this->verify( $token ) );
		putenv( 'WP_ENVIRONMENT_TYPE=development' );
		$this->assert_https_required( $this->verify( $token ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_constant_accepts_a_token_over_http(): void {
		define( 'MCPWP_ALLOW_INSECURE_OAUTH', true );
		$token = $this->live_token();
		$this->over_http();
		$this->assertTrue( $this->verify( $token ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_constant_must_be_true_not_truthy(): void {
		define( 'MCPWP_ALLOW_INSECURE_OAUTH', 'yes' );
		$this->over_http();
		$this->assert_https_required( $this->verify( 'mcpwp_at_' . str_repeat( 'g', 40 ) ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_trusted_proxy_reporting_https_on_every_hop_is_accepted(): void {
		define( 'MCPWP_TRUST_PROXY_HEADERS', true );
		$token = $this->live_token();
		$this->over_http();
		$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
		$this->assertTrue( $this->verify( $token ) );
		$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https, http';
		$this->assert_https_required( $this->verify( $token ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_truthy_trust_proxy_constant_is_not_trust(): void {
		define( 'MCPWP_TRUST_PROXY_HEADERS', 'false' );
		$this->assertFalse( Mcpwp_OAuth_Transport::trust_proxy_headers() );
		$this->over_http();
		$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
		$this->assert_https_required( $this->verify( 'mcpwp_at_' . str_repeat( 'j', 40 ) ) );
		// The client-IP resolver uses the same strict reading.
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1';
		$ip = ( new ReflectionMethod( Mcpwp_OAuth_Transport_Test_Auth::class, 'get_client_ip' ) );
		$ip->setAccessible( true );
		$this->assertSame( '203.0.113.9', $ip->invoke( new Mcpwp_OAuth_Transport_Test_Auth() ) );
		// ...and so do the rate limiter and the issuer origin.
		$limiter = new ReflectionMethod( Mcpwp_Rate_Limiter::class, 'get_client_identifier' );
		$limiter->setAccessible( true );
		$this->assertSame( '203.0.113.9', $limiter->invoke( Mcpwp_Rate_Limiter::get_instance() ) );
		$GLOBALS['mcpwp_test_site_url'] = 'http://example.com';
		$this->assertStringStartsWith( 'http://', Mcpwp_OAuth_Well_Known::get_site_origin(), 'X-Forwarded-Proto: https is not believed.' );
	}

	public function test_every_reader_of_the_trust_constant_goes_through_the_one_helper(): void {
		$root  = dirname( __DIR__ ) . '/includes';
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() || 'class-mcpwp-proxy-trust.php' === $file->getFilename() ) {
				continue;
			}
			$code = (string) file_get_contents( $file->getPathname() );
			$this->assertDoesNotMatchRegularExpression( "/defined\\(\\s*'MCPWP_TRUST_PROXY_HEADERS'/", $code, $file->getPathname() . ' must use Mcpwp_Proxy_Trust::headers_trusted()' );
		}
	}

	// ── consent screen and discovery ─────────────────────────────────────

	public function test_the_consent_screen_is_refused_over_http(): void {
		$GLOBALS['mcpwp_test_current_user'] = 1;
		$this->over_http();
		$ctrl = new Mcpwp_REST_OAuth();
		foreach ( array( 'GET' => 'handle_authorize_get', 'POST' => 'handle_authorize_post' ) as $method => $handler ) {
			$response = $ctrl->$handler( new WP_REST_Request( $method, '/mcpwp/v1/oauth/authorize', array( 'client_id' => 'test_client', 'redirect_uri' => self::REDIRECT ) ) );
			$this->assertSame( 403, $response->get_status(), $method );
			$this->assertStringContainsString( 'needs HTTPS', (string) $response->get_data(), $method );
			$this->assertArrayNotHasKey( 'Location', $response->get_headers(), $method . ': never a redirect carrying a code' );
		}
		$this->assertFalse( Mcpwp_OAuth_Transport::get_record(), 'An anonymous consent request never reaches Site Health.' );

		// Over https the transport rule does not answer.
		$this->over_https();
		$response = $ctrl->handle_authorize_get( new WP_REST_Request( 'GET', '/mcpwp/v1/oauth/authorize', array( 'client_id' => 'test_client', 'redirect_uri' => self::REDIRECT ) ) );
		$this->assertStringNotContainsString( 'needs HTTPS', wp_json_encode( $response->get_data() ) );
	}

	public function test_local_discovery_is_not_advertised_over_http(): void {
		$this->assertFalse( Mcpwp_OAuth_Well_Known::local_oauth_transport_refused() );
		$this->assertTrue( Mcpwp_OAuth_Well_Known::protected_resource_metadata_served_now() );
		$this->over_http();
		$this->assertTrue( Mcpwp_OAuth_Well_Known::local_oauth_transport_refused() );
		$this->assertFalse( Mcpwp_OAuth_Well_Known::protected_resource_metadata_served_now() );
		$this->assertFalse( Mcpwp_OAuth_Transport::get_record(), 'Discovery never records.' );

		// An unauthenticated 401 over http no longer points at metadata that is not served.
		$wire = $this->wire( ( new Mcpwp_OAuth_Transport_Test_Auth() )->verify_api_key( new WP_REST_Request( 'POST', '/mcpwp/v1/mcp' ) ) );
		$this->assertSame( 401, $wire['status'] );
		$this->assertStringNotContainsString( 'resource_metadata', $wire['headers']['WWW-Authenticate'] ?? '' );

		$this->over_https();
		$wire = $this->wire( ( new Mcpwp_OAuth_Transport_Test_Auth() )->verify_api_key( new WP_REST_Request( 'POST', '/mcpwp/v1/mcp' ) ) );
		$this->assertStringContainsString( 'resource_metadata', $wire['headers']['WWW-Authenticate'] ?? '' );
	}

	private function enroll_broker(): void {
		update_option( 'mcpwp_broker_enrollment', array( 'site_id' => 'abc', 'audience' => home_url(), 'site_credential' => 'x' ) );
	}

	public function test_broker_only_discovery_survives_plain_http_and_the_challenge_agrees(): void {
		$this->enroll_broker();
		update_option( 'mcpwp_settings', array( 'oauth_enabled' => false ) );
		$this->over_http();
		$this->assertTrue( Mcpwp_OAuth_Well_Known::protected_resource_metadata_served_now(), 'Names mumcp.com, whose bearers this rule does not cover.' );
		$this->assertSame( array( 'https://mumcp.com' ), Mcpwp_OAuth_Well_Known::build_protected_resource_metadata()['authorization_servers'] );
		$wire = $this->wire( ( new Mcpwp_OAuth_Transport_Test_Auth() )->verify_api_key( new WP_REST_Request( 'POST', '/mcpwp/v1/mcp' ) ) );
		$this->assertStringContainsString( 'resource_metadata', $wire['headers']['WWW-Authenticate'] ?? '' );
	}

	public function test_broker_plus_local_over_http_suppresses_document_and_challenge_together(): void {
		$this->enroll_broker();
		$this->over_http();
		$this->assertFalse( Mcpwp_OAuth_Well_Known::protected_resource_metadata_served_now() );
		$wire = $this->wire( ( new Mcpwp_OAuth_Transport_Test_Auth() )->verify_api_key( new WP_REST_Request( 'POST', '/mcpwp/v1/mcp' ) ) );
		$this->assertStringNotContainsString( 'resource_metadata', $wire['headers']['WWW-Authenticate'] ?? '', 'The challenge never points at a suppressed document.' );
	}

	// ── what an administrator sees ───────────────────────────────────────

	public function test_only_a_real_live_token_is_recorded_without_token_data_and_throttled(): void {
		$this->assertSame( 'good', Mcpwp_OAuth_Transport::get_status()['status'] );
		$token = $this->live_token();
		$this->over_http();
		$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'http';

		// Made-up tokens, the consent screen and discovery write nothing.
		$this->verify( 'mcpwp_at_' . str_repeat( 'o', 40 ) );
		( new Mcpwp_REST_OAuth() )->handle_authorize_get( new WP_REST_Request( 'GET', '/mcpwp/v1/oauth/authorize', array() ) );
		Mcpwp_OAuth_Well_Known::protected_resource_metadata_served_now();
		$this->assertFalse( Mcpwp_OAuth_Transport::get_record() );

		$this->verify( $token );
		$record = Mcpwp_OAuth_Transport::get_record();
		$this->assertIsArray( $record );
		$this->assertSame( array( 'first_at', 'last_at', 'proxy_suspected' ), array_keys( $record ) );
		$this->assertTrue( $record['proxy_suspected'], 'https site + proxy header at the refusal.' );
		$this->assertStringNotContainsString( substr( $token, 9, 12 ), wp_json_encode( $record ) );

		// The same, now dead, token does not record again.
		set_transient( Mcpwp_OAuth_Transport::REFUSALS_TRANSIENT, array( 'first_at' => 1, 'last_at' => 1, 'proxy_suspected' => false ), 60 );
		$this->verify( $token );
		$this->assertSame( 1, Mcpwp_OAuth_Transport::get_record()['last_at'] );

		// Within the interval a second live refusal does not rewrite the record.
		$record = array( 'first_at' => time() - 20, 'last_at' => time() - 10, 'proxy_suspected' => false );
		set_transient( Mcpwp_OAuth_Transport::REFUSALS_TRANSIENT, $record, 60 );
		Mcpwp_OAuth_Transport::record_refusal();
		$this->assertSame( $record, Mcpwp_OAuth_Transport::get_record() );
		// After it, it does.
		$record['last_at'] = time() - Mcpwp_OAuth_Transport::RECORD_INTERVAL - 1;
		set_transient( Mcpwp_OAuth_Transport::REFUSALS_TRANSIENT, $record, 60 );
		Mcpwp_OAuth_Transport::record_refusal();
		$this->assertGreaterThan( $record['last_at'], Mcpwp_OAuth_Transport::get_record()['last_at'] );
	}

	public function test_anonymous_requests_never_raise_a_critical_alarm(): void {
		$this->over_http();
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.9';
		for ( $i = 0; $i < 3; $i++ ) {
			$this->verify( 'mcpwp_at_' . bin2hex( random_bytes( 20 ) ) );
			( new Mcpwp_REST_OAuth() )->handle_authorize_get( new WP_REST_Request( 'GET', '/mcpwp/v1/oauth/authorize', array() ) );
		}
		// The administrator then looks over https: nothing to report.
		$this->over_https();
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
		$this->assertSame( 'good', Mcpwp_OAuth_Transport::run_site_status_test()['status'] );
	}

	public function test_site_health_severity(): void {
		$this->assertSame( 'good', Mcpwp_OAuth_Transport::run_site_status_test()['status'] );

		// The administrator's own request shows the proxy: critical, before any refusal.
		$this->over_http();
		$result = Mcpwp_OAuth_Transport::run_site_status_test();
		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( 'proxy', $result['label'] );
		$this->over_https();
		$this->assertSame( 'good', Mcpwp_OAuth_Transport::run_site_status_test()['status'] );

		// A real token refused with no proxy headers: recommended, never critical.
		$token = $this->live_token();
		$this->over_http();
		$this->verify( $token );
		$this->over_https();
		$this->assertSame( 'recommended', Mcpwp_OAuth_Transport::run_site_status_test()['status'] );

		// A real token refused through a proxy: critical.
		delete_transient( Mcpwp_OAuth_Transport::REFUSALS_TRANSIENT );
		$token = $this->live_token();
		$this->over_http();
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.7';
		$this->verify( $token );
		unset( $_SERVER['HTTP_CF_CONNECTING_IP'] );
		$this->over_https();
		$this->assertSame( 'critical', Mcpwp_OAuth_Transport::run_site_status_test()['status'] );

		$tests = Mcpwp_OAuth_Transport::register_site_status_test( array( 'direct' => array() ) );
		$this->assertArrayHasKey( 'mcpwp_oauth_transport', $tests['direct'] );
	}

	public function test_the_refusal_page_docblocks_belong_to_their_methods(): void {
		$min = ( new ReflectionMethod( Mcpwp_REST_OAuth::class, 'min_role_refusal_response' ) )->getDocComment();
		$ins = ( new ReflectionMethod( Mcpwp_REST_OAuth::class, 'insecure_transport_refusal_response' ) )->getDocComment();
		$this->assertStringContainsString( 'minimum role', (string) $min );
		$this->assertStringNotContainsString( 'transport', (string) $min );
		$this->assertStringContainsString( 'consent screen over a transport', (string) $ins );
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-oauth.php' );
		$this->assertDoesNotMatchRegularExpression( '#\*/\s*/\*\*#', $source, 'No docblock directly followed by another docblock.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_admin_notice_shows_on_mcpwp_screens_only(): void {
		$GLOBALS['mcpwp_test_screen_id'] = 'toplevel_page_mcpwp';
		require_once __DIR__ . '/fixtures/get-current-screen.php';
		$GLOBALS['mcpwp_test_current_user'] = 1;
		$GLOBALS['mcpwp_test_user_caps']    = array( 1 => array( 'read' => true, 'edit_posts' => true, 'edit_others_posts' => true, 'manage_options' => true ) );

		ob_start();
		Mcpwp_OAuth_Transport::render_notice();
		$this->assertSame( '', ob_get_clean(), 'Nothing to report yet.' );

		$token = $this->live_token();
		$this->over_http();
		$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'http';
		$this->verify( $token );
		$this->over_https();
		unset( $_SERVER['HTTP_X_FORWARDED_PROTO'] );
		ob_start();
		Mcpwp_OAuth_Transport::render_notice();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'refusing OAuth over plain HTTP', $html );

		$GLOBALS['mcpwp_test_screen_id'] = 'dashboard';
		ob_start();
		Mcpwp_OAuth_Transport::render_notice();
		$this->assertSame( '', ob_get_clean() );
	}
}
