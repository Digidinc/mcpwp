<?php
/**
 * PR-2: RFC 7591 Dynamic Client Registration + per-client redirect store.
 *
 * Decisions covered: D3 (DCR endpoint, public clients, id shape), D4
 * (redirect validation + exact / loopback matching), D5 (own option, bounds:
 * rate limit, cap, purge), D6 (legacy client still works, registered first),
 * D7 (consent screen copy).
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-oauth-clients.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-oauth.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-oauth-well-known.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-mcpwp-settings.php';

// Guarded copies of the front-channel stubs OAuthServerTest.php defines, so
// this file also runs on its own (identical bodies; first definition wins).
if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in() {
		return 0 !== (int) $GLOBALS['mcpwp_test_current_user'];
	}
}
if ( ! function_exists( 'wp_get_current_user' ) ) {
	function wp_get_current_user() {
		return (object) array( 'ID' => (int) $GLOBALS['mcpwp_test_current_user'], 'user_login' => 'testadmin' );
	}
}
if ( ! function_exists( 'wp_login_url' ) ) {
	function wp_login_url( $redirect = '' ) {
		return 'https://example.com/wp-login.php' . ( $redirect ? '?redirect_to=' . rawurlencode( $redirect ) : '' );
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return filter_var( (string) $url, FILTER_SANITIZE_URL );
	}
}

final class OAuthDynamicRegistrationTest extends TestCase {

	const CHATGPT_REDIRECT = 'https://chatgpt.com/connector_platform_oauth_redirect';
	const VERIFIER         = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

	/** @var mixed */
	private $saved_remote_addr;

	/** @var array */
	private $saved_users;

	protected function setUp(): void {
		// PR-2 P0: an OAuth token acts as its user, who must exist.
		$this->saved_users = $GLOBALS['mcpwp_test_users'];
		foreach ( array( 1, 2, 7, 8, 9, 10 ) as $mcpwp_uid ) {
			$GLOBALS['mcpwp_test_users'][] = (object) array( 'ID' => $mcpwp_uid, 'roles' => array(), 'user_login' => 'user' . $mcpwp_uid );
		}
		$GLOBALS['mcpwp_test_options']          = array();
		$GLOBALS['mcpwp_test_options_autoload'] = array();
		$GLOBALS['mcpwp_test_transients']       = array();
		$GLOBALS['mcpwp_test_current_user']     = 0;
		$GLOBALS['mcpwp_test_user_caps']        = array();
		$GLOBALS['mcpwp_test_wpdb_options']     = array();
		$this->saved_remote_addr                = $_SERVER['REMOTE_ADDR'] ?? null;
		$_SERVER['REMOTE_ADDR']                 = '203.0.113.7';

		update_option(
			'mcpwp_settings',
			array(
				'oauth_enabled'       => true,
				'oauth_client_id'     => 'legacy_client',
				'oauth_redirect_uris' => array( 'https://legacy.example.com/cb' ),
			)
		);
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_users'] = $this->saved_users;
		if ( null === $this->saved_remote_addr ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $this->saved_remote_addr;
		}
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_user_caps']    = array();
	}

	// ── Helpers ────────────────────────────────────────────────────────────

	private function controller(): Mcpwp_REST_OAuth {
		return new Mcpwp_REST_OAuth();
	}

	private function register( array $metadata, string $ip = '203.0.113.7' ) {
		return Mcpwp_OAuth_Clients::register( $metadata, $ip );
	}

	private function register_chatgpt(): string {
		$result = $this->register(
			array(
				'client_name'                => 'ChatGPT',
				'redirect_uris'              => array( self::CHATGPT_REDIRECT ),
				'token_endpoint_auth_method' => 'none',
				'grant_types'                => array( 'authorization_code', 'refresh_token' ),
				'response_types'             => array( 'code' ),
			)
		);
		$this->assertIsArray( $result );
		return $result['client_id'];
	}

	private function authorize_params( string $client_id, string $redirect, array $extra = array() ): array {
		return array_merge(
			array(
				'response_type'         => 'code',
				'client_id'             => $client_id,
				'redirect_uri'          => $redirect,
				'code_challenge'        => $this->controller()->compute_pkce_challenge( self::VERIFIER ),
				'code_challenge_method' => 'S256',
				'scope'                 => 'read write',
				'state'                 => 'st_123',
			),
			$extra
		);
	}

	private function login_editor(): void {
		$GLOBALS['mcpwp_test_current_user'] = 7;
		$GLOBALS['mcpwp_test_user_caps'][7] = array( 'read' => true, 'edit_posts' => true, 'edit_others_posts' => true );
	}

	private function assert_error( $result, string $code ): void {
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
	}

	// ── D3: registration shape ─────────────────────────────────────────────

	public function test_registration_returns_public_client_information(): void {
		$result = $this->register( array( 'client_name' => 'ChatGPT', 'redirect_uris' => array( self::CHATGPT_REDIRECT ) ) );

		$this->assertIsArray( $result );
		$this->assertMatchesRegularExpression( '/^mcpwpc_[0-9a-f]{32}$/', $result['client_id'] );
		$this->assertSame( $result['client_id'], sanitize_key( $result['client_id'] ), 'D3: the id must survive sanitize_key() unchanged.' );
		$this->assertSame( 'none', $result['token_endpoint_auth_method'] );
		$this->assertSame( array( self::CHATGPT_REDIRECT ), $result['redirect_uris'] );
		$this->assertArrayNotHasKey( 'client_secret', $result, 'Public clients only: no secret is ever minted.' );
		$this->assertSame( 'ChatGPT', $result['client_name'] );
	}

	public function test_endpoint_answers_201_with_no_store(): void {
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/oauth/register' );
		$request->set_body( wp_json_encode( array( 'redirect_uris' => array( self::CHATGPT_REDIRECT ) ) ) );

		$response = $this->controller()->handle_register( $request );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
		$this->assertNotNull( Mcpwp_OAuth_Clients::find( $response->get_data()['client_id'] ) );
	}

	public function test_endpoint_rejects_non_json_body_as_invalid_client_metadata(): void {
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/oauth/register' );
		$request->set_body( 'redirect_uris=https://x.example/cb' );

		$response = $this->controller()->handle_register( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_client_metadata', $response->get_data()['error'] );
	}

	public function test_endpoint_gated_when_oauth_stored_off(): void {
		update_option( 'mcpwp_settings', array( 'oauth_enabled' => false ) );
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/oauth/register' );
		$request->set_body( wp_json_encode( array( 'redirect_uris' => array( self::CHATGPT_REDIRECT ) ) ) );

		$response = $this->controller()->handle_register( $request );

		$this->assertSame( 501, $response->get_status() );
		$this->assertSame( array(), Mcpwp_OAuth_Clients::all() );
	}

	/**
	 * P1-3 (was: a confidential auth method is refused with 400). RFC 7591
	 * §3.2.1 lets the server replace requested metadata: the client is
	 * registered as public, the 201 says "none", and no secret exists.
	 */
	public function test_requested_confidential_auth_method_is_replaced_with_none(): void {
		foreach ( array( 'client_secret_basic', 'client_secret_post', 'private_key_jwt' ) as $method ) {
			$result = $this->register( array( 'redirect_uris' => array( 'https://' . str_replace( '_', '-', $method ) . '.example/cb' ), 'token_endpoint_auth_method' => $method ) );
			$this->assertIsArray( $result, $method );
			$this->assertSame( 'none', $result['token_endpoint_auth_method'], $method );
			$this->assertArrayNotHasKey( 'client_secret', $result, $method );
			$this->assertArrayNotHasKey( 'client_secret', Mcpwp_OAuth_Clients::find( $result['client_id'] ), $method );
		}
		$this->assert_error( $this->register( array( 'redirect_uris' => array( self::CHATGPT_REDIRECT ), 'token_endpoint_auth_method' => array( 'none' ) ) ), 'invalid_client_metadata' );
	}

	public function test_unsupported_grant_or_response_types_are_refused(): void {
		$this->assert_error( $this->register( array( 'redirect_uris' => array( self::CHATGPT_REDIRECT ), 'grant_types' => array( 'client_credentials' ) ) ), 'invalid_client_metadata' );
		$this->assert_error( $this->register( array( 'redirect_uris' => array( self::CHATGPT_REDIRECT ), 'grant_types' => array( 'authorization_code', 'implicit' ) ) ), 'invalid_client_metadata' );
		$this->assert_error( $this->register( array( 'redirect_uris' => array( self::CHATGPT_REDIRECT ), 'response_types' => array( 'token' ) ) ), 'invalid_client_metadata' );
		$this->assert_error( $this->register( array( 'redirect_uris' => array( self::CHATGPT_REDIRECT ), 'client_name' => array( 'x' ) ) ), 'invalid_client_metadata' );
	}

	public function test_client_name_is_plain_text_capped_and_stripped_of_bidi_controls(): void {
		$result = $this->register(
			array(
				'client_name'   => '<b>Chat</b>GPT' . "\u{202E}" . 'txt.exe' . str_repeat( 'x', 200 ),
				'redirect_uris' => array( self::CHATGPT_REDIRECT ),
			)
		);

		$this->assertStringNotContainsString( '<', $result['client_name'] );
		$this->assertStringNotContainsString( "\u{202E}", $result['client_name'] );
		$this->assertSame( 100, mb_strlen( $result['client_name'] ) );
		$this->assertStringStartsWith( 'ChatGPTtxt.exe', $result['client_name'] );
	}

	// ── D4: redirect URI validation ────────────────────────────────────────

	/**
	 * @dataProvider rejected_redirects
	 */
	public function test_bad_redirect_uris_are_refused( $uri ): void {
		$this->assert_error( $this->register( array( 'redirect_uris' => array( $uri ) ) ), 'invalid_redirect_uri' );
	}

	public static function rejected_redirects(): array {
		return array(
			'plain http on a public host' => array( 'http://evil.example/cb' ),
			'javascript scheme'           => array( 'javascript:alert(1)' ),
			'custom scheme'               => array( 'cursor://callback' ),
			'uppercase scheme'            => array( 'HTTPS://chatgpt.com/cb' ),
			'fragment'                    => array( 'https://chatgpt.com/cb#frag' ),
			'empty fragment'              => array( 'https://chatgpt.com/cb#' ),
			'userinfo'                    => array( 'https://user:pw@chatgpt.com/cb' ),
			'userinfo no password'        => array( 'https://chatgpt.com@evil.example/cb' ),
			'backslash authority trick'   => array( 'https://evil.example\\@chatgpt.com/cb' ),
			'whitespace'                  => array( 'https://chatgpt.com/c b' ),
			'no host'                     => array( 'https:///cb' ),
			'relative'                    => array( '/cb' ),
			'too long'                    => array( 'https://chatgpt.com/' . str_repeat( 'a', 2000 ) ),
			'not a string'                => array( 42 ),
			'empty'                       => array( '' ),
			'http loopback lookalike'     => array( 'http://localhost.evil.example/cb' ),
		);
	}

	public function test_redirect_uris_must_be_a_non_empty_list_of_at_most_five(): void {
		$this->assert_error( $this->register( array() ), 'invalid_redirect_uri' );
		$this->assert_error( $this->register( array( 'redirect_uris' => array() ) ), 'invalid_redirect_uri' );
		$this->assert_error( $this->register( array( 'redirect_uris' => self::CHATGPT_REDIRECT ) ), 'invalid_redirect_uri' );
		$this->assert_error( $this->register( array( 'redirect_uris' => array( 'a' => self::CHATGPT_REDIRECT ) ) ), 'invalid_redirect_uri' );

		$six = array();
		for ( $i = 0; $i < 6; $i++ ) {
			$six[] = 'https://app.example/cb' . $i;
		}
		$this->assert_error( $this->register( array( 'redirect_uris' => $six ) ), 'invalid_redirect_uri' );
		$this->assertIsArray( $this->register( array( 'redirect_uris' => array_slice( $six, 0, 5 ) ) ) );
	}

	public function test_loopback_and_https_redirects_are_accepted(): void {
		$result = $this->register(
			array(
				'redirect_uris' => array(
					'http://localhost:33418/callback',
					'http://127.0.0.1/callback',
					'http://[::1]:8080/cb',
					'https://claude.ai/api/mcp/auth_callback',
				),
			)
		);
		$this->assertIsArray( $result );
		$this->assertCount( 4, $result['redirect_uris'] );
	}

	// ── D5: store, bounds ──────────────────────────────────────────────────

	public function test_store_is_its_own_non_autoloaded_option_and_survives_a_settings_save(): void {
		$client_id = $this->register_chatgpt();

		$this->assertArrayHasKey( Mcpwp_OAuth_Clients::OPTION, $GLOBALS['mcpwp_test_options'] );
		$this->assertFalse( $GLOBALS['mcpwp_test_options_autoload'][ Mcpwp_OAuth_Clients::OPTION ], 'D5: never autoloaded.' );
		$settings = get_option( 'mcpwp_settings' );
		$this->assertArrayNotHasKey( 'oauth_clients', $settings );

		// The sanitizer rebuilds mcpwp_settings from a fixed key list — the
		// trap that deleted oauth_redirect_uris.  The client store is outside it.
		$sanitized = ( new Mcpwp_Settings() )->sanitize_settings( array( 'log_retention_days' => 9 ) );
		update_option( 'mcpwp_settings', $sanitized );

		$this->assertNotNull( Mcpwp_OAuth_Clients::find( $client_id ) );
	}

	/** Round 2: the bucket is (caller IP, redirect hosts), 10 per hour. */
	public function test_registration_is_rate_limited_to_ten_per_ip_and_redirect_host_per_hour(): void {
		for ( $i = 0; $i < 10; $i++ ) {
			$this->assertIsArray( $this->register( array( 'redirect_uris' => array( self::CHATGPT_REDIRECT ) ), '198.51.100.1' ) );
		}

		$eleventh = $this->register( array( 'redirect_uris' => array( self::CHATGPT_REDIRECT ) ), '198.51.100.1' );
		$this->assert_error( $eleventh, 'temporarily_unavailable' );
		$this->assertSame( 429, $eleventh->get_error_data()['status'] );
		$this->assertGreaterThan( 0, $eleventh->get_error_data()['retry_after'] );
		$this->assertCount( 10, Mcpwp_OAuth_Clients::all() );

		// Same host, another path: same bucket (the key is the HOST).
		$this->assert_error( $this->register( array( 'redirect_uris' => array( 'https://chatgpt.com/other' ) ), '198.51.100.1' ), 'temporarily_unavailable' );

		// Same address, another app's redirect host: its own bucket, so one
		// shared egress address (Cloudflare, OpenAI) cannot starve other apps.
		$this->assertIsArray( $this->register( array( 'redirect_uris' => array( 'https://claude.ai/api/mcp/auth_callback' ) ), '198.51.100.1' ) );

		// Another address has its own bucket.
		$this->assertIsArray( $this->register( array( 'redirect_uris' => array( self::CHATGPT_REDIRECT ) ), '198.51.100.2' ) );

		// The raw address is never stored.
		$this->assertStringNotContainsString( '198.51.100.1', serialize( $GLOBALS['mcpwp_test_transients'] ) );
		$this->assertStringNotContainsString( '198.51.100.1', serialize( get_option( Mcpwp_OAuth_Clients::OPTION ) ) );
	}

	/**
	 * Round 2: validation runs first (the bucket is keyed on the validated
	 * redirect host), so a malformed request writes nothing — not even a
	 * bucket — and does not cost a legitimate client its slots.
	 */
	public function test_invalid_attempts_write_nothing_and_cost_no_slot(): void {
		for ( $i = 0; $i < 20; $i++ ) {
			$this->register( array( 'redirect_uris' => array( 'http://evil.example/cb' ) ), '198.51.100.9' );
		}
		$this->assertSame( array(), $GLOBALS['mcpwp_test_transients'] );
		$this->assertIsArray( $this->register( array( 'redirect_uris' => array( self::CHATGPT_REDIRECT ) ), '198.51.100.9' ) );
	}

	/**
	 * The site cap counts LIVE pending registrations (never authorized, under an
	 * hour old), not a day's worth of attempts.  At the limit a newcomer is
	 * refused (nothing is displaced) with the time the oldest one expires; once
	 * they expire they stop counting.
	 */
	public function test_site_pending_limit_refuses_at_one_hundred_and_expiry_frees_it(): void {
		$now = time();
		for ( $i = 0; $i < Mcpwp_OAuth_Clients::SITE_PENDING_LIMIT; $i++ ) {
			// A different address and host each time (2 s apart, under the
			// per-minute ceiling): only the site limit can act on number 101.
			$this->assertIsArray( Mcpwp_OAuth_Clients::register( array( 'redirect_uris' => array( 'https://app' . $i . '.example/cb' ) ), '198.51.' . intdiv( $i, 250 ) . '.' . ( $i % 250 ), $now + 2 * $i ) );
		}
		$ids = array_keys( Mcpwp_OAuth_Clients::all() );
		$this->assertCount( 100, $ids );

		$over = Mcpwp_OAuth_Clients::register( array( 'redirect_uris' => array( 'https://app-new.example/cb' ) ), '203.0.113.200', $now + 250 );
		$this->assert_error( $over, 'temporarily_unavailable' );
		$this->assertSame( 429, $over->get_error_data()['status'] );
		$this->assertSame( $now + HOUR_IN_SECONDS - ( $now + 250 ), $over->get_error_data()['retry_after'], 'Honest Retry-After: when the oldest pending one expires.' );
		$this->assertSame( $ids, array_keys( Mcpwp_OAuth_Clients::all() ), 'Nothing was displaced.' );

		$later = $now + HOUR_IN_SECONDS + 400;
		$this->assertIsArray( Mcpwp_OAuth_Clients::register( array( 'redirect_uris' => array( 'https://app-later.example/cb' ) ), '203.0.113.201', $later ) );
		$this->assertCount( 1, Mcpwp_OAuth_Clients::all(), 'The expired ones are purged.' );
	}

	public function test_endpoint_sends_retry_after_on_429(): void {
		for ( $i = 0; $i < 10; $i++ ) {
			$this->register( array( 'redirect_uris' => array( self::CHATGPT_REDIRECT ) ) );
		}
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/oauth/register' );
		$request->set_body( wp_json_encode( array( 'redirect_uris' => array( self::CHATGPT_REDIRECT ) ) ) );

		$response = $this->controller()->handle_register( $request );

		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 'temporarily_unavailable', $response->get_data()['error'] );
		$this->assertArrayHasKey( 'Retry-After', $response->get_headers() );
	}

	private function seed_clients( int $count, int $created_at, int $last_used_at ): void {
		$clients = get_option( Mcpwp_OAuth_Clients::OPTION, array() );
		for ( $i = 0; $i < $count; $i++ ) {
			$id             = 'mcpwpc_' . str_pad( dechex( count( $clients ) + 1 ), 32, '0', STR_PAD_LEFT );
			$clients[ $id ] = array(
				'client_id'            => $id,
				'client_name'          => 'seed',
				'redirect_uris'        => array( 'https://seed.example/cb' ),
				'created_at'           => $created_at,
				'last_used_at'         => $last_used_at,
				'registration_ip_hash' => 'x',
			);
		}
		update_option( Mcpwp_OAuth_Clients::OPTION, $clients, false );
	}

	/** At the 500 cap nothing that has not expired is displaced: the newcomer is refused. */
	public function test_cap_of_500_never_displaces_a_live_registration(): void {
		$this->seed_clients( 1, time() - 20 * MINUTE_IN_SECONDS, 0 );
		$oldest = array_keys( Mcpwp_OAuth_Clients::all() )[0];
		$clients = Mcpwp_OAuth_Clients::all();
		foreach ( $clients as $id => $record ) {
			$clients[ $id ]['registered_by_admin'] = true;
		}
		update_option( Mcpwp_OAuth_Clients::OPTION, $clients, false );
		$this->seed_clients( 499, time() - 10 * MINUTE_IN_SECONDS, time() ); // authorized

		$result = $this->register( array( 'redirect_uris' => array( self::CHATGPT_REDIRECT ) ) );

		$this->assert_error( $result, 'temporarily_unavailable' );
		$this->assertSame( 429, $result->get_error_data()['status'] );
		$this->assertCount( 500, Mcpwp_OAuth_Clients::all() );
		$this->assertArrayHasKey( $oldest, Mcpwp_OAuth_Clients::all() );
		$this->assertStringContainsString( 'delete apps', $result->get_error_message(), 'The answer names the way out.' );
		$this->assertArrayNotHasKey( 'retry_after', $result->get_error_data(), 'It never clears by itself: no Retry-After.' );
	}

	public function test_cap_refuses_only_when_all_500_have_been_authorized(): void {
		$this->seed_clients( 500, time() - DAY_IN_SECONDS, time() );

		$result = $this->register( array( 'redirect_uris' => array( self::CHATGPT_REDIRECT ) ) );

		$this->assert_error( $result, 'temporarily_unavailable' );
		$this->assertSame( 429, $result->get_error_data()['status'] );
		$this->assertCount( 500, Mcpwp_OAuth_Clients::all() );
	}

	// ── Round 2: store lock + verification ────────────────────────────────

	private function hold_lock( int $expires_at ): void {
		$GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_OAuth_Clients::LOCK_OPTION ] = $expires_at . '|deadbeef';
	}

	public function test_lock_held_answers_503_and_stores_nothing(): void {
		$held_until = time() + 60;
		$this->hold_lock( $held_until );

		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/oauth/register' );
		$request->set_body( wp_json_encode( array( 'redirect_uris' => array( self::CHATGPT_REDIRECT ) ) ) );
		$response = $this->controller()->handle_register( $request );

		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( 'temporarily_unavailable', $response->get_data()['error'] );
		$this->assertStringContainsString( 'retry', $response->get_data()['error_description'] );
		$this->assertArrayHasKey( 'Retry-After', $response->get_headers() );
		$this->assertArrayNotHasKey( 'client_id', $response->get_data() );
		$this->assertSame( array(), Mcpwp_OAuth_Clients::all() );
		$this->assertSame( $held_until . '|deadbeef', $GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_OAuth_Clients::LOCK_OPTION ], 'A held lock is never deleted by the loser.' );
	}

	public function test_stale_lock_is_broken_after_its_timeout(): void {
		$this->hold_lock( time() - 1 );

		$result = $this->register( array( 'redirect_uris' => array( self::CHATGPT_REDIRECT ) ) );

		$this->assertIsArray( $result );
		$this->assertNotNull( Mcpwp_OAuth_Clients::find( $result['client_id'] ) );
		$this->assertArrayNotHasKey( Mcpwp_OAuth_Clients::LOCK_OPTION, $GLOBALS['mcpwp_test_wpdb_options'], 'Released after use.' );
	}

	public function test_lock_is_released_after_a_normal_registration(): void {
		$this->assertIsArray( $this->register( array( 'redirect_uris' => array( self::CHATGPT_REDIRECT ) ) ) );
		$this->assertArrayNotHasKey( Mcpwp_OAuth_Clients::LOCK_OPTION, $GLOBALS['mcpwp_test_wpdb_options'] );
	}

	public function test_lost_write_answers_503_never_a_phantom_client_id(): void {
		// The write "succeeds" but the stored value does not contain the new
		// client (a concurrent writer, a cache, a failed replica write…).
		$GLOBALS['mcpwp_test_option_store_hook'] = function ( $name, $value ) {
			return Mcpwp_OAuth_Clients::OPTION === $name ? array() : $value;
		};
		try {
			$result = $this->register( array( 'redirect_uris' => array( self::CHATGPT_REDIRECT ) ) );
		} finally {
			unset( $GLOBALS['mcpwp_test_option_store_hook'] );
		}

		$this->assert_error( $result, 'temporarily_unavailable' );
		$this->assertSame( 503, $result->get_error_data()['status'] );
		foreach ( array_keys( $GLOBALS['mcpwp_test_transients'] ) as $name ) {
			$this->assertStringStartsNotWith( 'mcpwp_oauth_dcr_', $name, 'A failed registration is not counted.' );
		}
		$this->assertArrayNotHasKey( Mcpwp_OAuth_Clients::LOCK_OPTION, $GLOBALS['mcpwp_test_wpdb_options'] );
	}

	public function test_failed_write_answers_503(): void {
		$GLOBALS['mcpwp_test_update_option_fail'][ Mcpwp_OAuth_Clients::OPTION ] = true;
		try {
			$result = $this->register( array( 'redirect_uris' => array( self::CHATGPT_REDIRECT ) ) );
		} finally {
			unset( $GLOBALS['mcpwp_test_update_option_fail'][ Mcpwp_OAuth_Clients::OPTION ] );
		}
		$this->assert_error( $result, 'temporarily_unavailable' );
		$this->assertSame( 503, $result->get_error_data()['status'] );
	}

	/** Never-authorized clients are purged after an hour (was 24 hours: #1115 hardening). */
	public function test_registration_purges_never_authorized_clients_older_than_an_hour_first(): void {
		$this->seed_clients( 499, time() - 2 * HOUR_IN_SECONDS, 0 );                      // never authorized, 2 h → purged
		$this->seed_clients( 1, time() - 8 * DAY_IN_SECONDS, time() - 9 * DAY_IN_SECONDS ); // old but AUTHORIZED → kept
		$this->seed_clients( 1, time() - 30 * MINUTE_IN_SECONDS, 0 );                     // never authorized, 30 min → kept

		$result = $this->register( array( 'redirect_uris' => array( self::CHATGPT_REDIRECT ) ) );

		$this->assertIsArray( $result, 'Purging stale clients makes room under the cap.' );
		$this->assertCount( 3, Mcpwp_OAuth_Clients::all() );
	}

	public function test_purge_boundary_is_one_hour(): void {
		$now     = time();
		$max_age = Mcpwp_OAuth_Clients::UNUSED_CLIENT_MAX_AGE;
		$this->assertSame( HOUR_IN_SECONDS, $max_age );
		$clients = array(
			'mcpwpc_' . str_repeat( '1', 32 ) => array( 'created_at' => $now - $max_age - 1, 'last_used_at' => 0, 'authorized_at' => 0 ),
			'mcpwpc_' . str_repeat( '2', 32 ) => array( 'created_at' => $now - $max_age + 60, 'last_used_at' => 0, 'authorized_at' => 0 ),
			'mcpwpc_' . str_repeat( '3', 32 ) => array( 'created_at' => $now - 30 * DAY_IN_SECONDS, 'last_used_at' => 0, 'authorized_at' => $now - 29 * DAY_IN_SECONDS ),
		);
		$kept = Mcpwp_OAuth_Clients::purge_stale( $clients, $now );
		$this->assertSame( array( 'mcpwpc_' . str_repeat( '2', 32 ), 'mcpwpc_' . str_repeat( '3', 32 ) ), array_keys( $kept ) );
	}

	// ── P1-B: rate-limit bucket address ────────────────────────────────────

	public function test_rate_limit_address_keys_ipv6_on_its_64_and_ipv4_mapped_as_ipv4(): void {
		$this->assertSame( '203.0.113.7', Mcpwp_OAuth_Clients::rate_limit_address( '203.0.113.7' ) );
		$this->assertSame( Mcpwp_OAuth_Clients::rate_limit_address( '2001:db8:1:2::1' ), Mcpwp_OAuth_Clients::rate_limit_address( '2001:db8:1:2:ffff:ffff:ffff:9' ) );
		$this->assertSame( Mcpwp_OAuth_Clients::rate_limit_address( '2001:DB8:1:2::1' ), Mcpwp_OAuth_Clients::rate_limit_address( '2001:0db8:0001:0002:0:0:0:1' ), 'Normalised, not compared as text.' );
		$this->assertNotSame( Mcpwp_OAuth_Clients::rate_limit_address( '2001:db8:1:2::1' ), Mcpwp_OAuth_Clients::rate_limit_address( '2001:db8:1:3::1' ) );
		$this->assertSame( '203.0.113.7', Mcpwp_OAuth_Clients::rate_limit_address( '::ffff:203.0.113.7' ) );
		$this->assertSame( '203.0.113.7', Mcpwp_OAuth_Clients::rate_limit_address( '::FFFF:cb00:7107' ) );
	}

	private function fill_bucket( string $ip ): void {
		for ( $i = 0; $i < 10; $i++ ) {
			$this->assertIsArray( $this->register( array( 'redirect_uris' => array( self::CHATGPT_REDIRECT ) ), $ip ) );
		}
	}

	public function test_two_addresses_in_one_64_share_a_bucket(): void {
		$this->fill_bucket( '2001:db8:aa:bb::1' );
		$this->assert_error( $this->register( array( 'redirect_uris' => array( self::CHATGPT_REDIRECT ) ), '2001:db8:aa:bb:dead:beef:0:42' ), 'temporarily_unavailable' );
	}

	public function test_a_different_64_has_its_own_bucket(): void {
		$this->fill_bucket( '2001:db8:aa:bb::1' );
		$this->assertIsArray( $this->register( array( 'redirect_uris' => array( self::CHATGPT_REDIRECT ) ), '2001:db8:aa:bc::1' ) );
	}

	public function test_an_ipv4_mapped_address_shares_the_ipv4_bucket(): void {
		$this->fill_bucket( '198.51.100.77' );
		$this->assert_error( $this->register( array( 'redirect_uris' => array( self::CHATGPT_REDIRECT ) ), '::ffff:198.51.100.77' ), 'temporarily_unavailable' );
		$this->assertIsArray( $this->register( array( 'redirect_uris' => array( self::CHATGPT_REDIRECT ) ), '198.51.100.78' ), 'A neighbouring IPv4 address is not swept in.' );
	}

	public function test_429_text_is_truthful_and_names_no_admin_screen(): void {
		$this->fill_bucket( '198.51.100.90' );
		$over = $this->register( array( 'redirect_uris' => array( self::CHATGPT_REDIRECT ) ), '198.51.100.90' );
		$this->assertSame( 'Too many app registrations right now; try again later.', $over->get_error_message() );
	}

	public function test_malformed_store_entries_are_never_clients(): void {
		update_option(
			Mcpwp_OAuth_Clients::OPTION,
			array(
				'mcpwpc_' . str_repeat( 'a', 32 ) => array( 'client_id' => 'mcpwpc_' . str_repeat( 'b', 32 ), 'redirect_uris' => array( 'https://x/cb' ), 'created_at' => 1, 'last_used_at' => 0 ),
				'not_a_client_id'                 => array( 'client_id' => 'not_a_client_id', 'redirect_uris' => array( 'https://x/cb' ), 'created_at' => 1, 'last_used_at' => 0 ),
				'mcpwpc_' . str_repeat( 'c', 32 ) => 'garbage',
			)
		);

		$this->assertSame( array(), Mcpwp_OAuth_Clients::all() );
		$this->assertNull( Mcpwp_OAuth_Clients::find( 'mcpwpc_' . str_repeat( 'a', 32 ) ) );
	}

	// ── D4/D6: authorize with registered + legacy clients ─────────────────

	public function test_registered_client_with_exact_redirect_reaches_consent(): void {
		$client_id = $this->register_chatgpt();
		$this->login_editor();

		$response = $this->controller()->handle_authorize_get(
			new WP_REST_Request( 'GET', '/mcpwp/v1/oauth/authorize', $this->authorize_params( $client_id, self::CHATGPT_REDIRECT ) )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( '<form', (string) $response->get_data() );
	}

	public function test_registered_client_with_wrong_redirect_is_400_json_with_no_redirect(): void {
		$client_id = $this->register_chatgpt();
		$this->login_editor();

		foreach ( array(
			'https://evil.example/cb',
			self::CHATGPT_REDIRECT . '/extra',
			self::CHATGPT_REDIRECT . '?x=1',
			'https://chatgpt.com.evil.example/connector_platform_oauth_redirect',
			'https://legacy.example.com/cb', // the LEGACY allow-list is not borrowed
		) as $bad ) {
			$response = $this->controller()->handle_authorize_get(
				new WP_REST_Request( 'GET', '/mcpwp/v1/oauth/authorize', $this->authorize_params( $client_id, $bad ) )
			);
			$this->assertSame( 400, $response->get_status(), $bad );
			$this->assertSame( 'invalid_request', $response->get_data()['error'], $bad );
			$this->assertArrayNotHasKey( 'Location', $response->get_headers(), 'Never redirect to an unvalidated redirect_uri: ' . $bad );
		}
	}

	public function test_unknown_client_is_invalid_client_with_no_redirect(): void {
		$this->login_editor();
		$response = $this->controller()->handle_authorize_get(
			new WP_REST_Request( 'GET', '/mcpwp/v1/oauth/authorize', $this->authorize_params( 'mcpwpc_' . str_repeat( 'f', 32 ), self::CHATGPT_REDIRECT ) )
		);
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_client', $response->get_data()['error'] );
		$this->assertArrayNotHasKey( 'Location', $response->get_headers() );
	}

	public function test_loopback_redirect_matches_on_any_port_but_nothing_else(): void {
		$result    = $this->register( array( 'redirect_uris' => array( 'http://127.0.0.1:5000/callback' ) ) );
		$client    = Mcpwp_OAuth_Clients::find( $result['client_id'] );

		$this->assertTrue( Mcpwp_OAuth_Clients::client_allows_redirect( $client, 'http://127.0.0.1:61234/callback' ) );
		$this->assertTrue( Mcpwp_OAuth_Clients::client_allows_redirect( $client, 'http://127.0.0.1/callback' ) );
		$this->assertFalse( Mcpwp_OAuth_Clients::client_allows_redirect( $client, 'http://127.0.0.1:61234/other' ) );
		$this->assertFalse( Mcpwp_OAuth_Clients::client_allows_redirect( $client, 'http://localhost:5000/callback' ), 'Host must match.' );
		$this->assertFalse( Mcpwp_OAuth_Clients::client_allows_redirect( $client, 'http://127.0.0.1:5000/callback?x=1' ), 'Query must match.' );
		$this->assertFalse( Mcpwp_OAuth_Clients::client_allows_redirect( $client, 'https://127.0.0.1:5000/callback' ), 'Scheme must match.' );
		$this->assertFalse( Mcpwp_OAuth_Clients::client_allows_redirect( $client, 'http://127.0.0.1:5000/callback#x' ) );
		$this->assertFalse( Mcpwp_OAuth_Clients::client_allows_redirect( $client, 'http://u@127.0.0.1:5000/callback' ) );
	}

	public function test_https_redirect_port_is_not_ignored(): void {
		$result = $this->register( array( 'redirect_uris' => array( 'https://app.example/cb' ) ) );
		$client = Mcpwp_OAuth_Clients::find( $result['client_id'] );

		$this->assertFalse( Mcpwp_OAuth_Clients::client_allows_redirect( $client, 'https://app.example:8443/cb' ) );
	}

	public function test_legacy_settings_client_still_uses_its_allow_list(): void {
		$this->login_editor();
		$ok = $this->controller()->handle_authorize_get(
			new WP_REST_Request( 'GET', '/mcpwp/v1/oauth/authorize', $this->authorize_params( 'legacy_client', 'https://legacy.example.com/cb' ) )
		);
		$this->assertSame( 200, $ok->get_status() );

		$bad = $this->controller()->handle_authorize_get(
			new WP_REST_Request( 'GET', '/mcpwp/v1/oauth/authorize', $this->authorize_params( 'legacy_client', self::CHATGPT_REDIRECT ) )
		);
		$this->assertSame( 400, $bad->get_status() );
	}

	public function test_array_client_id_fails_closed_without_warning(): void {
		$this->login_editor();
		$params              = $this->authorize_params( 'x', self::CHATGPT_REDIRECT );
		$params['client_id'] = array( 'legacy_client' );

		$response = $this->controller()->handle_authorize_get( new WP_REST_Request( 'GET', '/mcpwp/v1/oauth/authorize', $params ) );

		$this->assertSame( 'invalid_client', $response->get_data()['error'] );
	}

	// ── Token exchange with a registered client ───────────────────────────

	private function seed_code( string $client_id, string $redirect ): string {
		$code = 'code_' . bin2hex( random_bytes( 8 ) );
		set_transient(
			'mcpwp_oauth_code_' . $code,
			array(
				'client_id'      => $client_id,
				'redirect_uri'   => $redirect,
				'code_challenge' => $this->controller()->compute_pkce_challenge( self::VERIFIER ),
				'scope'          => 'read write',
				'user_id'        => 7,
				'expires'        => time() + 60,
			),
			60
		);
		return $code;
	}

	private function token_request( string $code, string $client_id, string $redirect ): WP_REST_Request {
		return new WP_REST_Request(
			'POST',
			'/mcpwp/v1/oauth/token',
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'client_id'     => $client_id,
				'redirect_uri'  => $redirect,
				'code_verifier' => self::VERIFIER,
			)
		);
	}

	public function test_approval_marks_the_client_authorized_before_the_code(): void {
		$client_id = $this->register_chatgpt();
		$this->login_editor();
		$this->assertFalse( Mcpwp_OAuth_Clients::has_been_authorized( Mcpwp_OAuth_Clients::find( $client_id ) ) );

		$location = $this->approve( $client_id );

		$this->assertStringContainsString( 'code=', $location );
		$this->assertGreaterThan( 0, Mcpwp_OAuth_Clients::find( $client_id )['authorized_at'] );
	}

	public function test_approval_with_the_store_locked_redirects_temporarily_unavailable_and_mints_no_code(): void {
		$client_id = $this->register_chatgpt();
		$this->login_editor();
		$html = (string) $this->controller()->handle_authorize_get(
			new WP_REST_Request( 'GET', '/mcpwp/v1/oauth/authorize', $this->authorize_params( $client_id, self::CHATGPT_REDIRECT ) )
		)->get_data();
		$this->hold_lock( time() + 60 );

		$location = $this->post_consent( $html );

		$this->assertStringStartsWith( self::CHATGPT_REDIRECT . '?', $location );
		parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );
		$this->assertSame( 'temporarily_unavailable', $query['error'] );
		$this->assertArrayNotHasKey( 'code', $query );
		$this->assertSame( array(), array_filter( array_keys( $GLOBALS['mcpwp_test_transients'] ), function ( $k ) {
			return 0 === strpos( $k, 'mcpwp_oauth_code_' );
		} ) );
	}

	private function post_consent( string $html ): string {
		preg_match_all( '/<input type="hidden" name="([^"]+)" value="([^"]*)">/', $html, $m, PREG_SET_ORDER );
		$post = array();
		foreach ( $m as $row ) {
			$post[ $row[1] ] = html_entity_decode( $row[2], ENT_QUOTES, 'UTF-8' );
		}
		$post['action']      = 'approve';
		$post['scope_write'] = '1';
		$response            = $this->controller()->handle_authorize_post( new WP_REST_Request( 'POST', '/mcpwp/v1/oauth/authorize', $post ) );
		$this->assertSame( 302, $response->get_status() );
		return $response->get_headers()['Location'];
	}

	private function approve( string $client_id ): string {
		$html = (string) $this->controller()->handle_authorize_get(
			new WP_REST_Request( 'GET', '/mcpwp/v1/oauth/authorize', $this->authorize_params( $client_id, self::CHATGPT_REDIRECT ) )
		)->get_data();
		return $this->post_consent( $html );
	}

	public function test_registered_public_client_redeems_its_code_and_is_marked_used(): void {
		$client_id                          = $this->register_chatgpt();
		$GLOBALS['mcpwp_test_user_caps'][7] = array( 'read' => true, 'edit_posts' => true, 'edit_others_posts' => true );

		$response = $this->controller()->handle_token( $this->token_request( $this->seed_code( $client_id, self::CHATGPT_REDIRECT ), $client_id, self::CHATGPT_REDIRECT ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'read write', $response->get_data()['scope'] );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
		$this->assertGreaterThan( 0, Mcpwp_OAuth_Clients::find( $client_id )['last_used_at'], 'A used client is never purged.' );
	}

	public function test_code_for_a_client_that_no_longer_exists_is_invalid_client(): void {
		$client_id = $this->register_chatgpt();
		$code      = $this->seed_code( $client_id, self::CHATGPT_REDIRECT );
		update_option( Mcpwp_OAuth_Clients::OPTION, array(), false );

		$response = $this->controller()->handle_token( $this->token_request( $code, $client_id, self::CHATGPT_REDIRECT ) );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'invalid_client', $response->get_data()['error'] );
	}

	// ── D7: consent screen ─────────────────────────────────────────────────

	public function test_consent_marks_name_unverified_and_bolds_the_redirect_host(): void {
		$result = $this->register( array( 'client_name' => 'ChatGPT', 'redirect_uris' => array( self::CHATGPT_REDIRECT ) ) );
		$this->login_editor();

		$html = (string) $this->controller()->handle_authorize_get(
			new WP_REST_Request( 'GET', '/mcpwp/v1/oauth/authorize', $this->authorize_params( $result['client_id'], self::CHATGPT_REDIRECT ) )
		)->get_data();

		$this->assertStringContainsString( '<strong>ChatGPT</strong> <em>(name supplied by the app, not verified)</em>', $html );
		$this->assertStringContainsString( '<strong>chatgpt.com</strong>', $html, 'D7: the redirect host decides where the code goes.' );
		$this->assertStringContainsString( 'testadmin', $html, 'D7: the approving WordPress user is shown.' );
		$this->assertStringContainsString( 'Read your site&#039;s content', $html );
		$this->assertStringContainsString( 'Create and edit content (drafts, pages, posts, media, SEO, Elementor)', $html );
		$this->assertStringContainsString( 'Never administrator access', $html );
		$this->assertStringNotContainsString( $result['client_id'] . '</strong>', $html, 'The opaque id is not the headline.' );
	}

	public function test_consent_escapes_a_hostile_client_name(): void {
		$clients               = get_option( Mcpwp_OAuth_Clients::OPTION, array() );
		$result                = $this->register( array( 'redirect_uris' => array( self::CHATGPT_REDIRECT ) ) );
		$clients               = get_option( Mcpwp_OAuth_Clients::OPTION );
		$clients[ $result['client_id'] ]['client_name'] = '"><script>alert(1)</script>';
		update_option( Mcpwp_OAuth_Clients::OPTION, $clients, false );
		$this->login_editor();

		$html = (string) $this->controller()->handle_authorize_get(
			new WP_REST_Request( 'GET', '/mcpwp/v1/oauth/authorize', $this->authorize_params( $result['client_id'], self::CHATGPT_REDIRECT ) )
		)->get_data();

		$this->assertStringNotContainsString( '<script>', $html );
	}
}
