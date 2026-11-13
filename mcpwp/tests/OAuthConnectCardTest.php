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
require_once __DIR__ . '/ApiAuthTest.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-uninstaller.php';

if ( ! function_exists( 'wp_die' ) ) {
	function wp_die( $message = '', $title = '', $args = array() ) {
		throw new RuntimeException( is_string( $message ) ? $message : 'wp_die called' );
	}
}

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
		$this->assertStringContainsString( 'No connected AI apps are listed.', $html );
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


	// ── review round: one chokepoint, per site ────────────────────────────

	private function legacy_refresh( int $user ): string {
		$token = 'mcpwp_rt_' . bin2hex( random_bytes( 32 ) );
		set_transient( ( new Mcpwp_REST_OAuth() )->get_oauth_refresh_token_transient_key( $token ), array( 'scopes' => array( 'read', 'write' ), 'user_id' => $user, 'created_at' => time() - 10, 'expires_at' => time() + 3600 ), 3600 );
		return $token;
	}

	private function legacy_access( int $user ): string {
		$token = 'mcpwp_at_' . bin2hex( random_bytes( 24 ) );
		set_transient( 'mcpwp_oauth_token_' . md5( $token ), array( 'scopes' => array( 'read' ), 'user_id' => $user, 'aud' => Mcpwp_OAuth_Well_Known::get_canonical_resource(), 'created_at' => time() - 10, 'expires_at' => time() + 600 ), 600 );
		return $token;
	}

	private function refresh_with( string $token ): WP_REST_Response {
		return ( new Mcpwp_REST_OAuth() )->handle_token( new WP_REST_Request( 'POST', '/mcpwp/v1/oauth/token', array( 'grant_type' => 'refresh_token', 'refresh_token' => $token, 'client_id' => 'test_client' ) ) );
	}

	private function bearer_works( string $token ): bool {
		return true === ( new Mcpwp_Api_Auth_Test_Harness() )->verify_api_key( new WP_REST_Request( 'GET', '/mcpwp/v1/site-info', array(), array( 'Authorization' => 'Bearer ' . $token ) ) );
	}

	public function test_admin_disconnect_all_kills_a_user_with_only_a_legacy_storage_token(): void {
		$refresh = $this->legacy_refresh( self::BOB );
		$access  = $this->legacy_access( self::BOB );
		$this->assertTrue( $this->bearer_works( $access ), 'Precondition: the legacy access token works.' );
		$this->as_user( self::ADMIN );
		$result = Mcpwp_OAuth_Apps_Admin::process_disconnect_all( array( Mcpwp_OAuth_Apps_Admin::NONCE_FIELD => $this->nonce_for( self::ADMIN ) ) );
		$this->assertSame( 'updated', $result['type'] );
		usleep( 20 );
		$this->assertFalse( $this->bearer_works( $access ), 'The legacy access token is refused.' );
		$response = $this->refresh_with( $refresh );
		$this->assertSame( 400, $response->get_status(), 'The unused pre-table refresh token cannot refresh.' );
		$this->assertSame( 'invalid_grant', $response->get_data()['error'] );
	}

	public function test_admin_disconnect_all_kills_an_unused_auth_code(): void {
		$code = 'code_' . bin2hex( random_bytes( 8 ) );
		set_transient( 'mcpwp_oauth_code_' . $code, array( 'client_id' => 'test_client', 'redirect_uri' => 'https://client.example.com/cb', 'code_challenge' => ( new Mcpwp_REST_OAuth() )->compute_pkce_challenge( self::VERIFIER ), 'scope' => 'read write', 'user_id' => self::BOB, 'issued_at' => Mcpwp_OAuth_Revocation::now(), 'expires' => time() + 60 ), 60 );
		$this->as_user( self::ADMIN );
		Mcpwp_OAuth_Apps_Admin::process_disconnect_all( array( Mcpwp_OAuth_Apps_Admin::NONCE_FIELD => $this->nonce_for( self::ADMIN ) ) );
		$response = ( new Mcpwp_REST_OAuth() )->handle_token( new WP_REST_Request( 'POST', '/mcpwp/v1/oauth/token', array( 'grant_type' => 'authorization_code', 'code' => $code, 'client_id' => 'test_client', 'redirect_uri' => 'https://client.example.com/cb', 'code_verifier' => self::VERIFIER ) ) );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_a_grant_issued_after_disconnect_all_works(): void {
		$this->as_user( self::ADMIN );
		Mcpwp_OAuth_Apps_Admin::process_disconnect_all( array( Mcpwp_OAuth_Apps_Admin::NONCE_FIELD => $this->nonce_for( self::ADMIN ) ) );
		usleep( 20 );
		$data = $this->grant( self::ALICE );
		$this->assertTrue( $this->bearer_works( $data['access_token'] ), 'Reconnecting works.' );
	}

	public function test_non_admin_disconnect_cuts_only_their_own_legacy_token(): void {
		$mine   = $this->legacy_access( self::ALICE );
		$theirs = $this->legacy_access( self::BOB );
		$this->as_user( self::ALICE );
		Mcpwp_OAuth_Apps_Admin::process_disconnect_all( array( Mcpwp_OAuth_Apps_Admin::NONCE_FIELD => $this->nonce_for( self::ALICE ) ) );
		usleep( 20 );
		$this->assertFalse( $this->bearer_works( $mine ) );
		$this->assertTrue( $this->bearer_works( $theirs ) );
	}

	public function test_disconnect_all_is_per_site_and_writes_no_network_wide_user_meta(): void {
		$this->as_user( self::ADMIN );
		Mcpwp_OAuth_Apps_Admin::process_disconnect_all( array( Mcpwp_OAuth_Apps_Admin::NONCE_FIELD => $this->nonce_for( self::ADMIN ) ) );
		$this->as_user( self::ALICE );
		Mcpwp_OAuth_Apps_Admin::process_disconnect_all( array( Mcpwp_OAuth_Apps_Admin::NONCE_FIELD => $this->nonce_for( self::ALICE ) ) );
		$this->assertSame( array(), $GLOBALS['mcpwp_test_user_meta'], 'No user meta: that is shared by every site of a network.' );
		$this->assertGreaterThan( 0, Mcpwp_OAuth_Revocation::site_epoch(), 'The marker is a per-site option.' );
	}

	public function test_card_tells_the_truth_in_the_legacy_window_and_after_it(): void {
		$this->as_user( self::ADMIN );
		$html = $this->card();
		$this->assertStringContainsString( 'may be connected without being listed', $html );
		$this->assertStringContainsString( '>Disconnect all AI apps<', $html, 'The button is there even with no rows.' );
		update_option( Mcpwp_OAuth_Token_Store::GRACE_OPTION, time() - 1, false );
		$html = $this->card();
		$this->assertStringNotContainsString( 'without being listed', $html );
		$this->assertStringNotContainsString( '>Disconnect all AI apps<', $html );
	}

	public function test_a_failed_write_is_reported_as_failure_not_success(): void {
		$GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_OAuth_Revocation::LOCK_OPTION ] = ( time() + 60 ) . '|held';
		$log = ini_set( 'error_log', '/dev/null' );
		try {
			$this->as_user( self::ADMIN );
			$result = Mcpwp_OAuth_Apps_Admin::process_disconnect_all( array( Mcpwp_OAuth_Apps_Admin::NONCE_FIELD => $this->nonce_for( self::ADMIN ) ) );
			$this->as_user( self::ALICE );
			$own = Mcpwp_OAuth_Apps_Admin::process_disconnect_all( array( Mcpwp_OAuth_Apps_Admin::NONCE_FIELD => $this->nonce_for( self::ALICE ) ) );
		} finally {
			ini_set( 'error_log', (string) $log );
		}
		$this->assertSame( 'error', $result['type'] );
		$this->assertSame( 'error', $own['type'] );
		$this->assertStringContainsString( 'could not be disconnected', $result['message'] );
	}

	public function test_the_handler_refuses_a_logged_out_caller_before_anything_else(): void {
		$this->as_user( 0 );
		$this->expectException( RuntimeException::class );
		Mcpwp_OAuth_Apps_Admin::handle_disconnect_all();
	}

	public function test_the_loader_registers_the_action_for_logged_in_users_only(): void {
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-mcpwp-loader.php' );
		$this->assertMatchesRegularExpression( "/add_action\( 'admin_post_' \. Mcpwp_OAuth_Apps_Admin::DISCONNECT_ALL_ACTION, 'Mcpwp_OAuth_Apps_Admin', 'handle_disconnect_all' \)/", $src );
		$this->assertStringNotContainsString( 'admin_post_nopriv_', substr( $src, (int) strpos( $src, 'DISCONNECT_ALL_ACTION' ) - 60, 200 ) );
	}

	// ── round 2 ───────────────────────────────────────────────────────────

	public function test_a_never_used_old_storage_access_token_is_refused_after_disconnect_all(): void {
		$access = $this->legacy_access( self::BOB ); // Never presented: it is not in the table.
		$this->assertNull( mcpwp_test_oauth_row( $access ) );
		$this->as_user( self::ADMIN );
		Mcpwp_OAuth_Apps_Admin::process_disconnect_all( array( Mcpwp_OAuth_Apps_Admin::NONCE_FIELD => $this->nonce_for( self::ADMIN ) ) );
		usleep( 20 );
		$this->assertFalse( $this->bearer_works( $access ), 'Only the site epoch can stop it: there is no row to revoke.' );
	}

	public function test_the_site_epoch_boundary_is_inclusive_and_fails_closed(): void {
		$epoch = 1700000000123456;
		update_option( Mcpwp_OAuth_Revocation::SITE_EPOCH_OPTION, array( 'site' => $epoch ), false );
		$this->assertTrue( Mcpwp_OAuth_Revocation::is_revoked( array( 'issued_at' => $epoch ) ), 'issued_at == epoch is revoked.' );
		$this->assertTrue( Mcpwp_OAuth_Revocation::is_revoked( array( 'issued_at' => $epoch - 1 ) ) );
		$this->assertFalse( Mcpwp_OAuth_Revocation::is_revoked( array( 'issued_at' => $epoch + 1 ) ), 'One microsecond later is valid.' );
		$this->assertTrue( Mcpwp_OAuth_Revocation::is_revoked( array() ), 'No issued_at: revoked.' );
		$this->assertTrue( Mcpwp_OAuth_Revocation::is_revoked( array( 'issued_at' => 0 ) ) );
		$this->assertTrue( Mcpwp_OAuth_Revocation::is_revoked( array( 'issued_at' => -5 ) ) );
	}

	public function test_the_per_site_user_epoch_boundary_is_inclusive_and_fails_closed(): void {
		$epoch = 1700000000123456;
		update_option( Mcpwp_OAuth_Revocation::SITE_USERS_OPTION, array( (string) self::ALICE => $epoch ), false );
		$rec = array( 'user_id' => self::ALICE );
		$this->assertTrue( Mcpwp_OAuth_Revocation::is_revoked( $rec + array( 'issued_at' => $epoch ) ) );
		$this->assertFalse( Mcpwp_OAuth_Revocation::is_revoked( $rec + array( 'issued_at' => $epoch + 1 ) ) );
		$this->assertTrue( Mcpwp_OAuth_Revocation::is_revoked( $rec ), 'No issued_at: revoked.' );
		$this->assertTrue( Mcpwp_OAuth_Revocation::is_revoked( $rec + array( 'issued_at' => 0 ) ) );
		$this->assertTrue( Mcpwp_OAuth_Revocation::is_revoked( $rec + array( 'issued_at' => -5 ) ) );
		$this->assertFalse( Mcpwp_OAuth_Revocation::is_revoked( array( 'user_id' => self::BOB, 'issued_at' => $epoch ) ), 'Another user is not caught.' );
	}

	private function machine_token(): string {
		$token = 'mcpwp_at_' . bin2hex( random_bytes( 24 ) );
		Mcpwp_OAuth_Token_Store::insert( $token, Mcpwp_OAuth_Token_Store::TYPE_ACCESS, array( 'client_id' => 'test_client', 'scopes' => array( 'read' ), 'issued_at' => Mcpwp_OAuth_Revocation::now(), 'expires_at' => time() + 600, 'family_id' => 'm', 'aud' => Mcpwp_OAuth_Well_Known::get_canonical_resource() ) );
		return $token;
	}

	public function test_machine_tokens_are_listed_for_admins_only_and_keep_the_button_after_the_window(): void {
		update_option( Mcpwp_OAuth_Token_Store::GRACE_OPTION, time() - 1, false );
		$this->machine_token();
		$this->as_user( self::ADMIN );
		$html = $this->card();
		$this->assertStringContainsString( 'No user (client secret)', $html );
		$this->assertStringContainsString( 'Client secret: Read only', $html );
		$this->assertStringContainsString( 'Rotate the OAuth client secret to stop it', $html );
		$this->assertStringContainsString( '>Disconnect all AI apps<', $html, 'Button stays while a machine token is live.' );
		$this->assertStringNotContainsString( 'action=' . Mcpwp_OAuth_Apps_Admin::REVOKE_ACTION . '&user_id=0', $html, 'No per-user revoke link for a machine token.' );
		$this->as_user( self::ALICE );
		$this->assertStringNotContainsString( 'No user (client secret)', $this->card(), 'Non-admins never see machine tokens.' );
	}

	public function test_the_success_message_does_not_overclaim_for_client_secret_and_broker(): void {
		$this->as_user( self::ADMIN );
		$result = Mcpwp_OAuth_Apps_Admin::process_disconnect_all( array( Mcpwp_OAuth_Apps_Admin::NONCE_FIELD => $this->nonce_for( self::ADMIN ) ) );
		$this->assertSame( 'updated', $result['type'] );
		$this->assertStringContainsString( 'client secret still works until you rotate it', $result['message'] );
		$this->assertStringContainsString( 'managed at the broker', $result['message'] );
		$this->assertStringContainsString( 'one-link Add to ChatGPT connection', $result['message'] );
		$this->assertStringNotContainsString( 'ChatGPT links are not affected', $result['message'] );
	}

	public function test_the_legacy_hint_also_shows_when_the_list_is_not_empty(): void {
		$this->grant( self::ALICE );
		$this->as_user( self::ADMIN );
		$html = $this->card();
		$this->assertStringContainsString( 'after its next API call or refresh', $html );
		$this->assertStringNotContainsString( 'next signs in', $html );
	}

	public function test_uninstall_drops_exactly_the_two_site_epoch_options(): void {
		foreach ( array( 'mcpwp_oauth_site_epoch', 'mcpwp_oauth_site_user_epochs' ) as $option ) {
			$this->assertContains( $option, Mcpwp_Uninstaller::APPROVALS_OPTIONS, $option );
			update_option( $option, array( 'site' => 1 ) );
		}
		update_option( 'mcpwp_oauth_site_unrelated', 'keep' );
		Mcpwp_Uninstaller::drop_approvals_data_for_current_site();
		$this->assertFalse( get_option( 'mcpwp_oauth_site_epoch', false ) );
		$this->assertFalse( get_option( 'mcpwp_oauth_site_user_epochs', false ) );
		$this->assertSame( 'keep', get_option( 'mcpwp_oauth_site_unrelated' ), 'Exact names only, no wildcard.' );
		foreach ( Mcpwp_Uninstaller::APPROVALS_OPTIONS as $option ) {
			$this->assertDoesNotMatchRegularExpression( '/[*%?]/', $option );
		}
	}

	private function redeem_code_without_issued_at( int $expires ): WP_REST_Response {
		$code = 'code_' . bin2hex( random_bytes( 8 ) );
		set_transient( 'mcpwp_oauth_code_' . $code, array( 'client_id' => 'test_client', 'redirect_uri' => 'https://client.example.com/cb', 'code_challenge' => ( new Mcpwp_REST_OAuth() )->compute_pkce_challenge( self::VERIFIER ), 'scope' => 'read write', 'user_id' => self::BOB, 'expires' => $expires ), 60 );
		return ( new Mcpwp_REST_OAuth() )->handle_token( new WP_REST_Request( 'POST', '/mcpwp/v1/oauth/token', array( 'grant_type' => 'authorization_code', 'code' => $code, 'client_id' => 'test_client', 'redirect_uri' => 'https://client.example.com/cb', 'code_verifier' => self::VERIFIER ) ) );
	}

	public function test_an_auth_code_without_issued_at_is_dated_from_its_expiry(): void {
		// Issued 20 s ago (expires - 60), epoch 10 s ago: revoked.  Epoch 100 s ago: valid.
		update_option( Mcpwp_OAuth_Revocation::SITE_EPOCH_OPTION, array( 'site' => Mcpwp_OAuth_Revocation::now() - 10 * 1000000 ), false );
		$this->assertSame( 400, $this->redeem_code_without_issued_at( time() + 40 )->get_status(), 'Issued before the epoch.' );
		update_option( Mcpwp_OAuth_Revocation::SITE_EPOCH_OPTION, array( 'site' => Mcpwp_OAuth_Revocation::now() - 100 * 1000000 ), false );
		$this->assertSame( 200, $this->redeem_code_without_issued_at( time() + 40 )->get_status(), 'Issued after the epoch: valid.' );
	}

	public function test_each_surface_prints_only_its_own_notice(): void {
		$this->as_user( self::ADMIN );
		$remember = new ReflectionMethod( 'Mcpwp_OAuth_Apps_Admin', 'remember_notice' );
		$remember->setAccessible( true );
		$remember->invoke( null, array( 'type' => 'error', 'message' => 'Delete failed.' ), 'list' );
		$this->assertStringNotContainsString( 'Delete failed.', $this->card(), 'The card must not swallow the list\'s notice.' );
		ob_start();
		Mcpwp_OAuth_Apps_Admin::render_clients_list();
		$this->assertStringContainsString( 'Delete failed.', (string) ob_get_clean() );
	}

	// ── review round: exact escaping ──────────────────────────────────────

	public function test_user_login_app_name_url_and_endpoint_are_escaped_exactly(): void {
		foreach ( $GLOBALS['mcpwp_test_users'] as $u ) {
			if ( self::ALICE === (int) $u->ID ) {
				$u->user_login = '<script>alert(2)</script>';
			}
		}
		$token = 'mcpwp_at_' . bin2hex( random_bytes( 24 ) );
		Mcpwp_OAuth_Token_Store::insert( $token, Mcpwp_OAuth_Token_Store::TYPE_ACCESS, array( 'client_id' => 'evil"><b>x', 'user_id' => self::ALICE, 'scopes' => array( 'read' ), 'issued_at' => Mcpwp_OAuth_Revocation::now(), 'expires_at' => time() + 600, 'family_id' => 'f', 'aud' => Mcpwp_OAuth_Well_Known::get_canonical_resource() ) );
		$GLOBALS['mcpwp_test_rest_scheme']   = 'https://a<i>';
		$GLOBALS['mcpwp_test_strict_esc_url'] = true;
		try {
			$this->as_user( self::ADMIN );
			$html = $this->card();
		} finally {
			unset( $GLOBALS['mcpwp_test_rest_scheme'], $GLOBALS['mcpwp_test_strict_esc_url'] );
		}
		$this->assertStringContainsString( 'app=evilbx">Revoke</a>', str_replace( '&#038;', '&', $html ), 'The revoke href is escaped by esc_url().' );
		$this->assertStringContainsString( '<td>&lt;script&gt;alert(2)&lt;/script&gt;</td>', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '<td>evil&quot;&gt;&lt;b&gt;x</td>', $html );
		$this->assertStringContainsString( '&#038;user_id=', $html, 'The revoke href went through esc_url().' );
		$this->assertStringNotContainsString( '"><b>', $html, 'Neither the name nor the revoke href breaks out of its attribute.' );
		$this->assertStringNotContainsString( '<i>', $html );
		$this->assertMatchesRegularExpression( '#<pre class="mcpwp-code-block" id="mcpwp-connect-endpoint">https://a&lt;i&gt;://example\.com/wp-json/mcpwp/v1/mcp</pre>#', $html );
	}

	// ── consent screen ────────────────────────────────────────────────────

	private function consent( string $scope ): string {
		return ( new Mcpwp_REST_OAuth() )->build_consent_screen_html(
			array(
				'client_id'     => self::CLIENT,
				'redirect_uri'  => self::REDIRECT,
				'scope'         => $scope,
				'response_type' => 'code',
				'state'         => 's',
			)
		);
	}

	public function test_consent_screen_states_access_name_host_and_where_to_revoke(): void {
		$this->as_user( self::ALICE );
		$html = $this->consent( 'read write' );
		$this->assertStringContainsString( '<strong>ChatGPT</strong>', $html );
		$this->assertStringContainsString( '<strong>chatgpt.com</strong>', $html );
		$this->assertStringContainsString( '<li>Read access lets the app view your site&#039;s content.</li>', $html );
		$this->assertStringContainsString( '<li>Write access also lets it create, edit and delete content as you. Untick the box above to give read access only.</li>', $html );
		$this->assertStringContainsString( '<li>It can never do more than your own WordPress account is allowed to do. The access listed above is already capped to what your account may do.</li>', $html );
		$this->assertStringContainsString( '<li>You can disconnect the app at any time under Users, Profile, Connected AI apps.</li>', $html );
		$this->assertStringContainsString( 'Never administrator access', $html );
		$this->assertStringNotContainsString( 'cannot change anything', $html );
	}

	public function test_consent_screen_does_not_explain_write_when_write_is_not_on_offer(): void {
		$this->as_user( self::ALICE );
		$html = $this->consent( 'read' );
		$this->assertStringNotContainsString( 'Write access also lets it', $html );
		$this->assertStringNotContainsString( 'name="scope_write"', $html );
		$this->assertStringContainsString( '<li>This connection is read only: the app cannot create, edit or delete anything.</li>', $html );
	}
}
