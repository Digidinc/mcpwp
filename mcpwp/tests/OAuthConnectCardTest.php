<?php
/**
 * "Connect an AI app" Setup card and the plainer consent screen.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-oauth-token-store.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-oauth.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-oauth-well-known.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-mcpwp-oauth-apps-admin.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-mcpwp-chatgpt-connector.php';

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return filter_var( (string) $url, FILTER_SANITIZE_URL );
	}
}

final class OAuthConnectCardTest extends TestCase {

	const ADMIN  = 1;
	const ALICE  = 3;
	const BOB    = 4;
	const CLIENT = 'mcpwpc_0123456789abcdef0123456789abcdef';
	const EVIL   = 'mcpwpc_fedcba9876543210fedcba9876543210';
	const REDIRECT = 'https://chatgpt.com/connector_platform_oauth_redirect';
	const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
	const EDITOR_CAPS = array( 'read' => true, 'edit_posts' => true, 'edit_others_posts' => true );

	/** @var array */
	private $saved_users;

	protected function setUp(): void {
		$this->saved_users = $GLOBALS['mcpwp_test_users'];
		foreach ( array( self::ADMIN, self::ALICE, self::BOB ) as $uid ) {
			$GLOBALS['mcpwp_test_users'][] = (object) array( 'ID' => $uid, 'roles' => array(), 'user_login' => 'user' . $uid );
		}
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_wpdb_options'] = array();
		$GLOBALS['mcpwp_test_user_meta']    = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_nonce_strict'] = true;
		$GLOBALS['mcpwp_test_user_caps']    = array(
			self::ADMIN => array_merge( self::EDITOR_CAPS, array( 'manage_options' => true, 'edit_users' => true, 'activate_plugins' => true ) ),
			self::ALICE => self::EDITOR_CAPS,
			self::BOB   => self::EDITOR_CAPS,
		);
		update_option( 'mcpwp_settings', array( 'oauth_enabled' => true, 'oauth_client_id' => 'test_client', 'oauth_token_ttl' => 3600, 'oauth_redirect_uris' => array( 'https://client.example.com/cb' ) ) );
		update_option(
			Mcpwp_OAuth_Clients::OPTION,
			array(
				self::CLIENT => array( 'client_id' => self::CLIENT, 'client_name' => 'ChatGPT', 'redirect_uris' => array( self::REDIRECT ), 'created_at' => time() - 100, 'last_used_at' => time() - 50, 'authorized_at' => time() - 50 ),
				self::EVIL   => array( 'client_id' => self::EVIL, 'client_name' => '<script>alert(1)</script>', 'redirect_uris' => array( 'https://evil.example/cb' ), 'created_at' => time() - 90, 'last_used_at' => time() - 40, 'authorized_at' => time() - 40 ),
			),
			false
		);
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_users']        = $this->saved_users;
		$GLOBALS['mcpwp_test_user_caps']    = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_nonce_strict'] = false;
	}

	private function as_user( int $uid ): void {
		$GLOBALS['mcpwp_test_current_user'] = $uid;
	}

	private function grant( int $user, string $client = self::CLIENT ): array {
		$code = 'code_' . bin2hex( random_bytes( 8 ) );
		set_transient( 'mcpwp_oauth_code_' . $code, array( 'client_id' => $client, 'redirect_uri' => self::REDIRECT, 'code_challenge' => ( new Mcpwp_REST_OAuth() )->compute_pkce_challenge( self::VERIFIER ), 'scope' => 'read write', 'user_id' => $user, 'issued_at' => Mcpwp_OAuth_Revocation::now(), 'expires' => time() + 60 ), 60 );
		$saved    = $GLOBALS['mcpwp_test_current_user'];
		$response = ( new Mcpwp_REST_OAuth() )->handle_token( new WP_REST_Request( 'POST', '/mcpwp/v1/oauth/token', array( 'grant_type' => 'authorization_code', 'code' => $code, 'client_id' => $client, 'redirect_uri' => self::REDIRECT, 'code_verifier' => self::VERIFIER ) ) );
		$GLOBALS['mcpwp_test_current_user'] = $saved;
		$this->assertSame( 200, $response->get_status() );
		return $response->get_data();
	}

	private function card(): string {
		ob_start();
		Mcpwp_OAuth_Apps_Admin::render_connect_card( true );
		return (string) ob_get_clean();
	}

	private function nonce_for( int $uid ): string {
		return wp_create_nonce( Mcpwp_OAuth_Apps_Admin::DISCONNECT_ALL_ACTION . '|' . $uid );
	}

	// ── card ──────────────────────────────────────────────────────────────

	public function test_card_shows_public_endpoint_copy_button_and_client_instructions(): void {
		$this->as_user( self::ADMIN );
		$html = $this->card();
		$this->assertStringContainsString( 'id="mcpwp-connect-ai-app"', $html );
		$this->assertStringContainsString( Mcpwp_ChatGPT_Connector::endpoint_url(), $html );
		$this->assertStringContainsString( 'data-target="mcpwp-connect-endpoint"', $html );
		$this->assertStringContainsString( 'claude.ai', $html );
		$this->assertStringContainsString( 'ChatGPT', $html );
		$this->assertStringContainsString( 'Cursor', $html );
		$this->assertStringContainsString( 'No AI apps are connected.', $html );
	}

	public function test_card_never_renders_token_material(): void {
		$data = $this->grant( self::ALICE );
		$this->as_user( self::ADMIN );
		$html = $this->card();
		$this->assertStringNotContainsString( $data['access_token'], $html );
		$this->assertStringNotContainsString( $data['refresh_token'], $html );
		$this->assertStringNotContainsString( Mcpwp_OAuth_Token_Store::hash( $data['access_token'] ), $html );
		$this->assertStringNotContainsString( 'mcpwp_api_key', $html );
	}

	public function test_admin_sees_every_users_connections_with_revoke_links(): void {
		$this->grant( self::ALICE );
		$this->grant( self::BOB, self::EVIL );
		$this->as_user( self::ADMIN );
		$html = $this->card();
		$this->assertStringContainsString( 'user3', $html );
		$this->assertStringContainsString( 'user4', $html );
		$this->assertStringContainsString( 'action=' . Mcpwp_OAuth_Apps_Admin::REVOKE_ACTION, $html );
		$this->assertStringContainsString( 'Disconnect all AI apps', $html );
		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html, 'Registered names are escaped.' );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	public function test_non_admin_sees_only_their_own_connections(): void {
		$this->grant( self::ALICE );
		$this->grant( self::BOB, self::EVIL );
		$this->as_user( self::ALICE );
		$html = $this->card();
		$this->assertStringContainsString( 'user3', $html );
		$this->assertStringNotContainsString( 'user4', $html );
		$this->assertStringNotContainsString( '&lt;script&gt;', $html );
		$this->assertStringContainsString( 'Disconnect my AI apps', $html );
		$this->assertStringNotContainsString( '>Disconnect all AI apps<', $html );
	}

	public function test_oauth_off_warning_is_shown(): void {
		$this->as_user( self::ADMIN );
		ob_start();
		Mcpwp_OAuth_Apps_Admin::render_connect_card( false );
		$this->assertStringContainsString( 'switched off', (string) ob_get_clean() );
	}

	// ── disconnect all ────────────────────────────────────────────────────

	public function test_admin_disconnect_all_revokes_every_users_grants(): void {
		$a = $this->grant( self::ALICE );
		$b = $this->grant( self::BOB, self::EVIL );
		$this->as_user( self::ADMIN );
		$result = Mcpwp_OAuth_Apps_Admin::process_disconnect_all( array( Mcpwp_OAuth_Apps_Admin::NONCE_FIELD => $this->nonce_for( self::ADMIN ) ) );
		$this->assertSame( 'updated', $result['type'] );
		$this->assertNotNull( mcpwp_test_oauth_row( $a['refresh_token'] )['revoked_at'] );
		$this->assertNotNull( mcpwp_test_oauth_row( $b['refresh_token'] )['revoked_at'] );
		$this->assertSame( array(), Mcpwp_OAuth_Token_Store::live_rows() );
	}

	public function test_non_admin_disconnect_all_revokes_only_their_own(): void {
		$a = $this->grant( self::ALICE );
		$b = $this->grant( self::BOB );
		$this->as_user( self::ALICE );
		$result = Mcpwp_OAuth_Apps_Admin::process_disconnect_all( array( Mcpwp_OAuth_Apps_Admin::NONCE_FIELD => $this->nonce_for( self::ALICE ) ) );
		$this->assertSame( 'updated', $result['type'] );
		$this->assertNotNull( mcpwp_test_oauth_row( $a['refresh_token'] )['revoked_at'] );
		$this->assertNull( mcpwp_test_oauth_row( $b['refresh_token'] )['revoked_at'], 'Another user\'s grant is untouched.' );
	}

	public function test_disconnect_all_needs_a_valid_nonce_bound_to_the_actor(): void {
		$a = $this->grant( self::ALICE );
		$this->as_user( self::ADMIN );
		foreach ( array( array(), array( Mcpwp_OAuth_Apps_Admin::NONCE_FIELD => 'bogus' ), array( Mcpwp_OAuth_Apps_Admin::NONCE_FIELD => $this->nonce_for( self::BOB ) ) ) as $post ) {
			$result = Mcpwp_OAuth_Apps_Admin::process_disconnect_all( $post );
			$this->assertSame( 'error', $result['type'] );
		}
		$this->assertNull( mcpwp_test_oauth_row( $a['refresh_token'] )['revoked_at'] );
	}

	public function test_disconnect_all_refuses_a_logged_out_caller_and_ignores_request_scope(): void {
		$a = $this->grant( self::ALICE );
		$this->as_user( 0 );
		$this->assertSame( 'error', Mcpwp_OAuth_Apps_Admin::process_disconnect_all( array( Mcpwp_OAuth_Apps_Admin::NONCE_FIELD => $this->nonce_for( 0 ) ) )['type'] );
		// A non-admin cannot widen the scope by posting a user list.
		$this->grant( self::BOB );
		$this->as_user( self::BOB );
		Mcpwp_OAuth_Apps_Admin::process_disconnect_all( array( Mcpwp_OAuth_Apps_Admin::NONCE_FIELD => $this->nonce_for( self::BOB ), 'user_id' => self::ALICE, 'all' => '1' ) );
		$this->assertNull( mcpwp_test_oauth_row( $a['refresh_token'] )['revoked_at'] );
	}

	public function test_disconnect_all_leaves_api_keys_and_other_options_alone(): void {
		update_option( 'mcpwp_api_keys', array( 'k' => array( 'hash' => 'x' ) ) );
		$this->as_user( self::ADMIN );
		Mcpwp_OAuth_Apps_Admin::process_disconnect_all( array( Mcpwp_OAuth_Apps_Admin::NONCE_FIELD => $this->nonce_for( self::ADMIN ) ) );
		$this->assertSame( array( 'k' => array( 'hash' => 'x' ) ), get_option( 'mcpwp_api_keys' ) );
	}

	// ── consent screen ────────────────────────────────────────────────────

	public function test_consent_screen_states_access_name_host_and_where_to_revoke(): void {
		$this->as_user( self::ALICE );
		$html = ( new Mcpwp_REST_OAuth() )->build_consent_screen_html(
			array(
				'client_id'     => self::CLIENT,
				'redirect_uri'  => self::REDIRECT,
				'scope'         => 'read write',
				'response_type' => 'code',
				'state'         => 's',
			)
		);
		$this->assertStringContainsString( 'ChatGPT', $html );
		$this->assertStringContainsString( 'chatgpt.com', $html );
		$this->assertStringContainsString( 'Read: the app can look at', $html );
		$this->assertStringContainsString( 'Write: the app can also create and edit', $html );
		$this->assertStringContainsString( 'never do more than your own WordPress account', $html );
		$this->assertStringContainsString( 'Connected AI apps', $html );
		$this->assertStringContainsString( 'Never administrator access', $html );
	}
}
