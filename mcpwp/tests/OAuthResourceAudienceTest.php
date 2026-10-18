<?php
/**
 * PR-2: RFC 8707 resource/audience binding, discovery shape, RFC 9207 iss.
 *
 * Decisions covered: D1 (canonical resource = MCP URL; legacy accepted;
 * mismatch = invalid_target), D2 (aud enforced on every request, aud-less
 * grace), D8 (scopes_supported without admin), D12 (path-insertion PRM +
 * WWW-Authenticate), D13 (iss on every authorization response, AS metadata).
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-oauth-clients.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-oauth.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-oauth-well-known.php';

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
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return filter_var( (string) $url, FILTER_SANITIZE_URL );
	}
}

/** Exposes the protected access-token transient key builder. */
final class Mcpwp_OAuth_Audience_Test_Controller extends Mcpwp_REST_OAuth {
	public function access_token_key( $token ) {
		return $this->get_oauth_token_transient_key( $token );
	}
}

final class OAuthResourceAudienceTest extends TestCase {

	const MCP_URL  = 'https://example.com/wp-json/mcpwp/v1/mcp';
	const REDIRECT = 'https://client.example.com/callback';
	const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

	/** @var array */
	private $saved_users;

	protected function setUp(): void {
		// PR-2 P0: an OAuth token acts as its user, who must exist.
		$this->saved_users = $GLOBALS['mcpwp_test_users'];
		foreach ( array( 1, 2, 7, 8, 9, 10 ) as $mcpwp_uid ) {
			$GLOBALS['mcpwp_test_users'][] = (object) array( 'ID' => $mcpwp_uid, 'roles' => array(), 'user_login' => 'user' . $mcpwp_uid );
		}
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_user_caps']    = array( 7 => array( 'read' => true, 'edit_posts' => true, 'edit_others_posts' => true ) );
		$_SERVER['REQUEST_URI']             = '/';

		update_option(
			'mcpwp_settings',
			array(
				'oauth_enabled'       => true,
				'oauth_client_id'     => 'test_client',
				'oauth_redirect_uris' => array( self::REDIRECT ),
			)
		);
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_users'] = $this->saved_users;
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_user_caps']    = array();
		unset( $_SERVER['REQUEST_URI'] );
	}

	private function controller(): Mcpwp_OAuth_Audience_Test_Controller {
		return new Mcpwp_OAuth_Audience_Test_Controller();
	}

	private function params( array $extra = array() ): array {
		return array_merge(
			array(
				'response_type'         => 'code',
				'client_id'             => 'test_client',
				'redirect_uri'          => self::REDIRECT,
				'code_challenge'        => $this->controller()->compute_pkce_challenge( self::VERIFIER ),
				'code_challenge_method' => 'S256',
				'scope'                 => 'read write',
				'state'                 => 'st',
			),
			$extra
		);
	}

	private function login_editor(): void {
		$GLOBALS['mcpwp_test_current_user'] = 7;
		$GLOBALS['mcpwp_test_user_caps'][7] = array( 'read' => true, 'edit_posts' => true, 'edit_others_posts' => true );
	}

	/** Render consent, then POST it back approved, returning the redirect query. */
	private function approve( array $extra = array(), bool $keep_write = true ): array {
		$this->login_editor();
		$ctrl = $this->controller();
		$html = (string) $ctrl->handle_authorize_get( new WP_REST_Request( 'GET', '/mcpwp/v1/oauth/authorize', $this->params( $extra ) ) )->get_data();

		preg_match_all( '/<input type="hidden" name="([^"]+)" value="([^"]*)">/', $html, $m, PREG_SET_ORDER );
		$post = array();
		foreach ( $m as $row ) {
			$post[ $row[1] ] = html_entity_decode( $row[2], ENT_QUOTES, 'UTF-8' );
		}
		$post['action'] = 'approve';
		if ( $keep_write ) {
			$post['scope_write'] = '1';
		}

		$response = $ctrl->handle_authorize_post( new WP_REST_Request( 'POST', '/mcpwp/v1/oauth/authorize', $post ) );
		$this->assertSame( 302, $response->get_status() );
		parse_str( (string) wp_parse_url( $response->get_headers()['Location'], PHP_URL_QUERY ), $query );
		return $query;
	}

	private function token_request( string $code, array $extra = array() ): WP_REST_Request {
		return new WP_REST_Request(
			'POST',
			'/mcpwp/v1/oauth/token',
			array_merge(
				array(
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'client_id'     => 'test_client',
					'redirect_uri'  => self::REDIRECT,
					'code_verifier' => self::VERIFIER,
				),
				$extra
			)
		);
	}

	private function bearer_request( string $token ): WP_REST_Request {
		return new WP_REST_Request( 'GET', '/mcpwp/v1/site-info', array(), array( 'Authorization' => 'Bearer ' . $token ) );
	}

	// ── D1: canonical resource ─────────────────────────────────────────────

	public function test_canonical_resource_is_the_mcp_url(): void {
		$this->assertSame( self::MCP_URL, Mcpwp_OAuth_Well_Known::get_canonical_resource() );
		$this->assertSame( rest_url( 'mcpwp/v1/mcp' ), Mcpwp_OAuth_Well_Known::get_canonical_resource() );
	}

	public function test_canonical_resource_scheme_follows_the_home_option(): void {
		update_option( 'home', 'http://example.com' );
		$this->assertSame( 'http://example.com/wp-json/mcpwp/v1/mcp', Mcpwp_OAuth_Well_Known::get_canonical_resource() );
	}

	public function test_acceptable_resources(): void {
		$this->assertTrue( Mcpwp_OAuth_Well_Known::is_acceptable_resource( self::MCP_URL ) );
		$this->assertTrue( Mcpwp_OAuth_Well_Known::is_acceptable_resource( 'HTTPS://EXAMPLE.COM:443/wp-json/mcpwp/v1/mcp/' ), 'Normalised: case, default port, trailing slash.' );
		$this->assertTrue( Mcpwp_OAuth_Well_Known::is_acceptable_resource( 'https://example.com' ), 'D1: the legacy origin stays accepted.' );
		$this->assertTrue( Mcpwp_OAuth_Well_Known::is_acceptable_resource( 'https://example.com/' ) );

		foreach ( array(
			'https://evil.example/wp-json/mcpwp/v1/mcp',
			'https://example.com.evil.example/wp-json/mcpwp/v1/mcp',
			'https://example.com/wp-json/mcpwp/v1/mcp/../../wp/v2',
			'https://example.com/wp-json/mcpwp/v1',
			'https://example.com/wp-json/mcpwp/v1/mcp#x',
			'https://u@example.com/wp-json/mcpwp/v1/mcp',
			'http://example.com/wp-json/mcpwp/v1/mcp',
			'https://example.com:8443/wp-json/mcpwp/v1/mcp',
			'ftp://example.com/wp-json/mcpwp/v1/mcp',
			'not a url',
			'',
		) as $bad ) {
			$this->assertFalse( Mcpwp_OAuth_Well_Known::is_acceptable_resource( $bad ), $bad );
		}
		$this->assertFalse( Mcpwp_OAuth_Well_Known::is_acceptable_resource( array( self::MCP_URL ) ) );
	}

	public function test_authorize_accepts_absent_canonical_and_legacy_resource(): void {
		$this->login_editor();
		foreach ( array( array(), array( 'resource' => self::MCP_URL ), array( 'resource' => 'https://example.com' ) ) as $extra ) {
			$response = $this->controller()->handle_authorize_get( new WP_REST_Request( 'GET', '/mcpwp/v1/oauth/authorize', $this->params( $extra ) ) );
			$this->assertSame( 200, $response->get_status(), wp_json_encode( $extra ) );
		}
	}

	public function test_authorize_with_mismatched_resource_is_invalid_target_with_no_redirect(): void {
		$this->login_editor();
		foreach ( array( 'https://evil.example/wp-json/mcpwp/v1/mcp', 'https://example.com/wp-json/other/v1/mcp' ) as $bad ) {
			$response = $this->controller()->handle_authorize_get(
				new WP_REST_Request( 'GET', '/mcpwp/v1/oauth/authorize', $this->params( array( 'resource' => $bad ) ) )
			);
			$this->assertSame( 400, $response->get_status() );
			$this->assertSame( 'invalid_target', $response->get_data()['error'] );
			$this->assertArrayNotHasKey( 'Location', $response->get_headers() );
		}

		$array_resource = $this->controller()->handle_authorize_get(
			new WP_REST_Request( 'GET', '/mcpwp/v1/oauth/authorize', $this->params( array( 'resource' => array( self::MCP_URL ) ) ) )
		);
		$this->assertSame( 'invalid_target', $array_resource->get_data()['error'], 'A non-string resource matches nothing.' );
	}

	public function test_consent_post_with_mismatched_resource_is_refused_before_minting(): void {
		$this->login_editor();
		$GLOBALS['_mcpwp_test_valid_nonces']['mcpwp_oauth_consent'] = 'test-nonce';
		$post = array_merge( $this->params( array( 'resource' => 'https://evil.example/mcp' ) ), array( 'action' => 'approve', '_mcpwp_oauth_nonce' => 'test-nonce', 'scope_write' => '1' ) );

		$response = $this->controller()->handle_authorize_post( new WP_REST_Request( 'POST', '/mcpwp/v1/oauth/authorize', $post ) );

		$this->assertSame( 'invalid_target', $response->get_data()['error'] );
		$this->assertSame( array(), array_filter( array_keys( $GLOBALS['mcpwp_test_transients'] ), function ( $k ) {
			return 0 === strpos( $k, 'mcpwp_oauth_code_' );
		} ) );
	}

	public function test_code_carries_canonical_aud_even_for_a_legacy_resource(): void {
		$query  = $this->approve( array( 'resource' => 'https://example.com' ) );
		$stored = get_transient( 'mcpwp_oauth_code_' . $query['code'] );
		$this->assertSame( self::MCP_URL, $stored['aud'] );
	}

	public function test_token_request_resource_is_validated(): void {
		$query = $this->approve( array( 'resource' => self::MCP_URL ) );

		$bad = $this->controller()->handle_token( $this->token_request( $query['code'], array( 'resource' => 'https://evil.example/mcp' ) ) );
		$this->assertSame( 400, $bad->get_status() );
		$this->assertSame( 'invalid_target', $bad->get_data()['error'] );

		// Checked before the code is consumed: the same code still redeems.
		$ok = $this->controller()->handle_token( $this->token_request( $query['code'], array( 'resource' => self::MCP_URL ) ) );
		$this->assertSame( 200, $ok->get_status() );
	}

	// ── D2: aud stored and enforced ────────────────────────────────────────

	public function test_issued_tokens_store_the_canonical_aud(): void {
		$query = $this->approve( array( 'resource' => self::MCP_URL ) );
		$data  = $this->controller()->handle_token( $this->token_request( $query['code'] ) )->get_data();

		$record = mcpwp_test_oauth_record( $data['access_token'] );
		$this->assertSame( self::MCP_URL, $record['aud'] );

		$refresh = mcpwp_test_oauth_record( $data['refresh_token'] );
		$this->assertSame( self::MCP_URL, $refresh['aud'] );
	}

	public function test_client_credentials_token_also_carries_aud(): void {
		$auth = new Mcpwp_Api_Auth_Test_Harness();
		$data = $auth->issue_oauth_access_token( array( 'read' ), 3600, array( 'client_id' => 'test_client' ) );
		$record = mcpwp_test_oauth_record( $data['access_token'] );
		$this->assertSame( self::MCP_URL, $record['aud'] );
	}

	public function test_token_with_matching_aud_authenticates(): void {
		$auth  = new Mcpwp_Api_Auth_Test_Harness();
		$token = $auth->issue_oauth_access_token( array( 'read', 'write' ), 3600, array( 'user_id' => 7 ) )['access_token'];

		$this->assertTrue( $auth->verify_api_key( $this->bearer_request( $token ) ) );
	}

	public function test_token_whose_stored_aud_was_altered_is_refused(): void {
		$auth  = new Mcpwp_Api_Auth_Test_Harness();
		$token = $auth->issue_oauth_access_token( array( 'read', 'write' ), 3600, array( 'user_id' => 7 ) )['access_token'];

		// #1101 PR-3b: the aud column is a string; an empty one fails closed.
		foreach ( array( 'https://other-site.example/wp-json/mcpwp/v1/mcp', '', 'https://example.com', ' ' . self::MCP_URL ) as $forged ) {
			mcpwp_test_oauth_set( $token, array( 'aud' => $forged ) );

			$result = $auth->verify_api_key( $this->bearer_request( $token ) );
			$this->assertInstanceOf( WP_Error::class, $result, 'aud=' . wp_json_encode( $forged ) );
			$this->assertSame( 'invalid_token', $result->get_error_code() );
			$this->assertSame( 401, $result->get_error_data()['status'] );
		}
	}

	public function test_pre_pr2_token_without_aud_is_accepted_until_it_expires(): void {
		$auth  = new Mcpwp_Api_Auth_Test_Harness();
		$token = 'mcpwp_at_' . bin2hex( random_bytes( 24 ) );
		set_transient( 'mcpwp_oauth_token_' . md5( $token ), array( 'scopes' => array( 'read' ), 'user_id' => 7, 'created_at' => time(), 'expires_at' => time() + 600 ), 600 );

		$this->assertTrue( $auth->verify_api_key( $this->bearer_request( $token ) ), 'D2 grace: aud-less tokens predate this release.' );

		// #1101 PR-3b: that use moved it into the token table (re-bound to
		// this site's endpoint); it still ends with its own TTL.
		$this->assertFalse( get_transient( 'mcpwp_oauth_token_' . md5( $token ) ) );
		$this->assertSame( self::MCP_URL, mcpwp_test_oauth_record( $token )['aud'] );
		mcpwp_test_oauth_set( $token, array( 'expires_at' => time() - 1 ) );
		$this->assertInstanceOf( WP_Error::class, $auth->verify_api_key( $this->bearer_request( $token ) ), 'The grace ends with the token TTL.' );
	}

	public function test_access_token_ttl_is_capped_at_one_day(): void {
		$auth = new Mcpwp_Api_Auth_Test_Harness();
		$data = $auth->issue_oauth_access_token( array( 'read' ), 10 * DAY_IN_SECONDS );
		$this->assertSame( DAY_IN_SECONDS, $data['expires_in'], 'The aud-less grace period is bounded by this cap.' );
	}

	public function test_refresh_token_with_foreign_aud_is_refused(): void {
		$ctrl  = $this->controller();
		$GLOBALS['mcpwp_test_user_caps'][7] = array( 'read' => true, 'edit_posts' => true, 'edit_others_posts' => true );
		$token = 'mcpwp_rt_' . bin2hex( random_bytes( 16 ) );
		set_transient( $ctrl->get_oauth_refresh_token_transient_key( $token ), array( 'scopes' => array( 'read' ), 'user_id' => 7, 'aud' => 'https://other.example/wp-json/mcpwp/v1/mcp' ), 3600 );

		$response = $ctrl->handle_token( new WP_REST_Request( 'POST', '/mcpwp/v1/oauth/token', array( 'grant_type' => 'refresh_token', 'client_id' => 'test_client', 'refresh_token' => $token ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_grant', $response->get_data()['error'] );
	}

	public function test_refresh_request_with_mismatched_resource_is_invalid_target(): void {
		$ctrl  = $this->controller();
		$token = 'mcpwp_rt_' . bin2hex( random_bytes( 16 ) );
		set_transient( $ctrl->get_oauth_refresh_token_transient_key( $token ), array( 'scopes' => array( 'read' ), 'user_id' => 7 ), 3600 );

		$response = $ctrl->handle_token( new WP_REST_Request( 'POST', '/mcpwp/v1/oauth/token', array( 'grant_type' => 'refresh_token', 'client_id' => 'test_client', 'refresh_token' => $token, 'resource' => 'https://evil.example/mcp' ) ) );

		$this->assertSame( 'invalid_target', $response->get_data()['error'] );
		$this->assertNotFalse( get_transient( $ctrl->get_oauth_refresh_token_transient_key( $token ) ), 'Refused before rotation: the refresh token is not burned.' );
	}

	// ── P2-5: state round-trips byte-exact ────────────────────────────────

	public function test_state_with_percent_sequences_round_trips_exactly(): void {
		$state = 'a%2Fb%zz %41&amp;=x/y?z~' . "'" . '"<>';
		$query = $this->approve( array( 'state' => $state ) );
		$this->assertSame( $state, $query['state'] );
	}

	public function test_state_is_validated_not_sanitised(): void {
		$this->login_editor();
		foreach ( array( "bad\x01", "tab\there", str_repeat( 'a', 1025 ), "caf\xc3\xa9" ) as $bad ) {
			$response = $this->controller()->handle_authorize_get( new WP_REST_Request( 'GET', '/mcpwp/v1/oauth/authorize', $this->params( array( 'state' => $bad ) ) ) );
			$this->assertSame( 400, $response->get_status(), bin2hex( substr( $bad, 0, 12 ) ) );
			$this->assertSame( 'invalid_request', $response->get_data()['error'] );
			$this->assertArrayNotHasKey( 'Location', $response->get_headers() );
		}
		$ok = $this->controller()->handle_authorize_get( new WP_REST_Request( 'GET', '/mcpwp/v1/oauth/authorize', $this->params( array( 'state' => str_repeat( 'a', 1024 ) ) ) ) );
		$this->assertSame( 200, $ok->get_status() );
	}

	// ── P2-2: client IP behind a trusted proxy ─────────────────────────────

	public function test_trusted_proxy_prefers_cf_connecting_ip_over_first_xff_hop(): void {
		$probe = new class() extends Mcpwp_Api_Auth_Test_Harness {
			public function ip( $trust ) {
				return $this->resolve_client_ip( $trust );
			}
		};
		$saved                           = $_SERVER;
		$_SERVER['REMOTE_ADDR']          = '10.0.0.1';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '6.6.6.6, 198.51.100.4';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.9';
		try {
			$this->assertSame( '203.0.113.9', $probe->ip( true ) );
			unset( $_SERVER['HTTP_CF_CONNECTING_IP'] );
			$this->assertSame( '6.6.6.6', $probe->ip( true ), 'Without Cloudflare, the first XFF hop (unchanged behaviour).' );
			$this->assertSame( '10.0.0.1', $probe->ip( false ), 'Untrusted: REMOTE_ADDR only.' );
		} finally {
			$_SERVER = $saved;
		}
	}

	// ── D13: iss ───────────────────────────────────────────────────────────

	public function test_iss_is_on_the_approval_redirect(): void {
		$query = $this->approve();
		$this->assertSame( 'https://example.com', $query['iss'] );
		$this->assertSame( Mcpwp_OAuth_Well_Known::build_authorization_server_metadata()['issuer'], $query['iss'] );
		$this->assertSame( 'st', $query['state'] );
	}

	public function test_iss_is_on_the_denial_redirect(): void {
		$this->login_editor();
		$ctrl = $this->controller();
		$html = (string) $ctrl->handle_authorize_get( new WP_REST_Request( 'GET', '/mcpwp/v1/oauth/authorize', $this->params() ) )->get_data();
		preg_match_all( '/<input type="hidden" name="([^"]+)" value="([^"]*)">/', $html, $m, PREG_SET_ORDER );
		$post = array();
		foreach ( $m as $row ) {
			$post[ $row[1] ] = html_entity_decode( $row[2], ENT_QUOTES, 'UTF-8' );
		}
		$post['action'] = 'deny';

		$location = $ctrl->handle_authorize_post( new WP_REST_Request( 'POST', '/mcpwp/v1/oauth/authorize', $post ) )->get_headers()['Location'];
		parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );

		$this->assertSame( 'access_denied', $query['error'] );
		$this->assertSame( 'https://example.com', $query['iss'] );
	}

	public function test_as_metadata_advertises_dcr_iss_and_no_admin(): void {
		$metadata = Mcpwp_OAuth_Well_Known::build_authorization_server_metadata();

		$this->assertSame( 'https://example.com/wp-json/mcpwp/v1/oauth/register', $metadata['registration_endpoint'] );
		$this->assertTrue( $metadata['authorization_response_iss_parameter_supported'] );
		$this->assertSame( array( 'read', 'write' ), $metadata['scopes_supported'] );
		$this->assertSame( array( 'none' ), $metadata['token_endpoint_auth_methods_supported'], 'P1-3: only public clients are advertised.' );
	}

	// ── PRM + D12 ──────────────────────────────────────────────────────────

	public function test_prm_resource_is_the_mcp_url_and_names_the_issuer(): void {
		$metadata = Mcpwp_OAuth_Well_Known::build_protected_resource_metadata();

		$this->assertSame( self::MCP_URL, $metadata['resource'] );
		$this->assertSame( array( 'https://example.com' ), $metadata['authorization_servers'] );
		$this->assertSame( array( 'read', 'write' ), $metadata['scopes_supported'] );
	}

	public function test_prm_path_insertion_url(): void {
		$this->assertSame( '/.well-known/oauth-protected-resource/wp-json/mcpwp/v1/mcp', Mcpwp_OAuth_Well_Known::get_protected_resource_metadata_path() );
		$this->assertSame( 'https://example.com/.well-known/oauth-protected-resource/wp-json/mcpwp/v1/mcp', Mcpwp_OAuth_Well_Known::get_protected_resource_metadata_url() );
	}

	public function test_www_authenticate_points_at_the_path_insertion_url(): void {
		$response = new WP_REST_Response( array( 'code' => 'missing_api_key' ), 401 );
		$result   = Mcpwp_OAuth_Well_Known::add_www_authenticate_header( $response, null, new WP_REST_Request( 'POST', '/mcpwp/v1/mcp' ) );

		$this->assertSame(
			'Bearer resource_metadata="https://example.com/.well-known/oauth-protected-resource/wp-json/mcpwp/v1/mcp"',
			$result->get_headers()['WWW-Authenticate']
		);
	}

	// The dispatcher exit()s on a match, so the served document itself is
	// verified over real HTTP in the rig check, not here.

	public function test_interceptor_ignores_unrelated_paths_without_serving(): void {
		$_SERVER['REQUEST_URI'] = '/.well-known/oauth-protected-resource/wp-json/other/v1/mcp';
		$this->assertTrue( Mcpwp_OAuth_Well_Known::maybe_serve_well_known( true, null ) );

		$_SERVER['REQUEST_URI'] = '/some-page/';
		$this->assertTrue( Mcpwp_OAuth_Well_Known::maybe_serve_well_known( true, null ) );
	}
}
