<?php
/**
 * #1101 PR-3b part 2 — the admin AI-app list and the per-user "Connected AI
 * apps" profile section, and the primitives under them.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-oauth-token-store.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-oauth.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-oauth-well-known.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-mcpwp-oauth-apps-admin.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-posts.php';

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return filter_var( (string) $url, FILTER_SANITIZE_URL );
	}
}

final class OAuthConnectedApps1101bTest extends TestCase {

	const ADMIN  = 1;
	const EDITOR = 2;
	const ALICE  = 3; // an Editor who connected apps
	const BOB    = 4; // another Editor who connected apps

	const CLIENT     = 'mcpwpc_0123456789abcdef0123456789abcdef';
	const EVIL       = 'mcpwpc_fedcba9876543210fedcba9876543210';
	const REDIRECT   = 'https://chatgpt.com/connector_platform_oauth_redirect';
	const VERIFIER   = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
	const EDITOR_CAPS = array( 'read' => true, 'edit_posts' => true, 'edit_others_posts' => true );

	/** @var array */
	private $saved_users;

	protected function setUp(): void {
		$this->saved_users = $GLOBALS['mcpwp_test_users'];
		foreach ( array( self::ADMIN, self::EDITOR, self::ALICE, self::BOB ) as $uid ) {
			$GLOBALS['mcpwp_test_users'][] = (object) array( 'ID' => $uid, 'roles' => array(), 'user_login' => 'user' . $uid );
		}
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_wpdb_options'] = array();
		$GLOBALS['mcpwp_test_user_meta']    = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_nonce_strict'] = true;
		$GLOBALS['mcpwp_test_user_caps']    = array(
			self::ADMIN  => array_merge( self::EDITOR_CAPS, array( 'manage_options' => true, 'edit_users' => true, 'activate_plugins' => true ) ),
			self::EDITOR => self::EDITOR_CAPS,
			self::ALICE  => self::EDITOR_CAPS,
			self::BOB    => self::EDITOR_CAPS,
		);
		update_option( 'mcpwp_settings', array( 'oauth_enabled' => true, 'oauth_client_id' => 'test_client', 'oauth_token_ttl' => 3600, 'oauth_redirect_uris' => array( 'https://client.example.com/cb' ) ) );
		update_option(
			Mcpwp_OAuth_Clients::OPTION,
			array(
				self::CLIENT => array( 'client_id' => self::CLIENT, 'client_name' => 'ChatGPT', 'redirect_uris' => array( self::REDIRECT ), 'created_at' => time() - 100, 'last_used_at' => time() - 50, 'authorized_at' => time() - 50 ),
				self::EVIL   => array( 'client_id' => self::EVIL, 'client_name' => '<script>alert(1)</script>', 'redirect_uris' => array( 'https://evil.example/<img>/cb' ), 'created_at' => time() - 90, 'last_used_at' => time() - 40, 'authorized_at' => time() - 40 ),
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

	// ── helpers ───────────────────────────────────────────────────────────

	private function as_user( int $uid ): void {
		$GLOBALS['mcpwp_test_current_user'] = $uid;
	}

	/** A live grant for $user from $client, through the real token endpoint. */
	private function grant( int $user, string $client = self::CLIENT ): array {
		$code = 'code_' . bin2hex( random_bytes( 8 ) );
		set_transient( 'mcpwp_oauth_code_' . $code, array( 'client_id' => $client, 'redirect_uri' => self::REDIRECT, 'code_challenge' => ( new Mcpwp_REST_OAuth() )->compute_pkce_challenge( self::VERIFIER ), 'scope' => 'read write', 'user_id' => $user, 'issued_at' => Mcpwp_OAuth_Revocation::now(), 'expires' => time() + 60 ), 60 );
		$saved = $GLOBALS['mcpwp_test_current_user'];
		$response = ( new Mcpwp_REST_OAuth() )->handle_token( new WP_REST_Request( 'POST', '/mcpwp/v1/oauth/token', array( 'grant_type' => 'authorization_code', 'code' => $code, 'client_id' => $client, 'redirect_uri' => self::REDIRECT, 'code_verifier' => self::VERIFIER ) ) );
		$GLOBALS['mcpwp_test_current_user'] = $saved;
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		return $response->get_data();
	}

	/** Whether an access token passes the real REST permission callback. */
	private function works( string $access_token ): bool {
		$saved  = $GLOBALS['mcpwp_test_current_user'];
		$result = ( new Mcpwp_REST_Posts() )->check_permission( new WP_REST_Request( 'GET', '/mcpwp/v1/posts', array(), array( 'Authorization' => 'Bearer ' . $access_token ) ) );
		$GLOBALS['mcpwp_test_current_user'] = $saved;
		return true === $result;
	}

	private function html( callable $render ): string {
		ob_start();
		$render();
		return (string) ob_get_clean();
	}

	private function delete_post( string $client, ?string $nonce ): array {
		$post = array( Mcpwp_OAuth_Apps_Admin::FIELD_CLIENT => $client );
		if ( null !== $nonce ) {
			$post[ Mcpwp_OAuth_Apps_Admin::NONCE_FIELD ] = $nonce;
		}
		return $post;
	}

	private function profile_post( int $owner, array $buttons, ?string $nonce = 'own' ): array {
		if ( 'own' === $nonce ) {
			$nonce = wp_create_nonce( Mcpwp_OAuth_Apps_Admin::PROFILE_ACTION . '|' . $owner );
		}
		return null === $nonce ? $buttons : $buttons + array( Mcpwp_OAuth_Apps_Admin::NONCE_FIELD => $nonce );
	}

	// ── A. primitives ─────────────────────────────────────────────────────

	public function test_deleting_a_client_also_marks_its_rows_revoked(): void {
		$data = $this->grant( self::ALICE );
		$this->assertTrue( Mcpwp_OAuth_Clients::delete( self::CLIENT ) );
		$this->assertNotNull( mcpwp_test_oauth_row( $data['access_token'] )['revoked_at'] );
		$this->assertNotNull( mcpwp_test_oauth_row( $data['refresh_token'] )['revoked_at'] );
	}

	public function test_revoke_user_client_kills_only_that_pair(): void {
		$alice_chatgpt = $this->grant( self::ALICE );
		$alice_evil    = $this->grant( self::ALICE, self::EVIL );
		$bob_chatgpt   = $this->grant( self::BOB );

		$this->assertTrue( Mcpwp_OAuth_Revocation::revoke_user_client( self::ALICE, self::CLIENT ) );
		$this->assertNotNull( mcpwp_test_oauth_row( $alice_chatgpt['refresh_token'] )['revoked_at'], 'The pair\'s rows are marked.' );
		$this->assertNull( mcpwp_test_oauth_row( $alice_evil['refresh_token'] )['revoked_at'] );
		$this->assertFalse( $this->works( $alice_chatgpt['access_token'] ) );
		$this->assertTrue( $this->works( $alice_evil['access_token'] ), 'Alice\'s other app is untouched.' );
		$this->assertTrue( $this->works( $bob_chatgpt['access_token'] ), 'Bob\'s use of the same app is untouched.' );

		// The epoch holds even if the rows were missed (it is the guard).
		mcpwp_test_oauth_set( $alice_chatgpt['access_token'], array( 'revoked_at' => null ) );
		$this->assertFalse( $this->works( $alice_chatgpt['access_token'] ), 'The pair epoch refuses it.' );

		usleep( 20 );
		$again = $this->grant( self::ALICE );
		$this->assertTrue( $this->works( $again['access_token'] ), 'Reconnecting works.' );
	}

	public function test_revoke_user_client_fails_closed_and_reports(): void {
		$GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_OAuth_Revocation::LOCK_OPTION ] = ( time() + 60 ) . '|held';
		$seen = array();
		add_action( Mcpwp_OAuth_Revocation::WRITE_FAILED_ACTION, function ( $kind ) use ( &$seen ) { $seen[] = $kind; } );
		$log = ini_set( 'error_log', '/dev/null' );
		try {
			$this->assertFalse( Mcpwp_OAuth_Revocation::revoke_user_client( self::ALICE, self::CLIENT ) );
		} finally {
			ini_set( 'error_log', (string) $log );
			unset( $GLOBALS['mcpwp_test_actions'][ Mcpwp_OAuth_Revocation::WRITE_FAILED_ACTION ] );
		}
		$this->assertSame( array( 'user_client' ), $seen );
	}

	public function test_live_apps_and_counts_ignore_revoked_expired_and_epoch_dead_tokens(): void {
		$this->grant( self::ALICE );
		$second = $this->grant( self::ALICE );
		$this->grant( self::ALICE, self::EVIL );
		$this->grant( self::BOB );
		mcpwp_test_oauth_set( $second['access_token'], array( 'last_used_at' => time() - 5 ) );

		$apps = Mcpwp_OAuth_Token_Store::live_apps_for_user( self::ALICE );
		$this->assertSame( array( self::CLIENT, self::EVIL ), array_keys( $apps ) );
		$this->assertSame( 'ChatGPT', $apps[ self::CLIENT ]['client_name'] );
		$this->assertSame( array( 'read', 'write' ), $apps[ self::CLIENT ]['scopes'] );
		$this->assertSame( time() - 5, $apps[ self::CLIENT ]['last_used_at'] );
		$this->assertSame( array( self::CLIENT => 6, self::EVIL => 2 ), Mcpwp_OAuth_Token_Store::active_counts_by_client() );

		mcpwp_test_oauth_set( $second['refresh_token'], array( 'expires_at' => time() - 1 ) );
		Mcpwp_OAuth_Revocation::revoke_user( self::BOB );
		$this->assertSame( array( self::CLIENT => 3, self::EVIL => 2 ), Mcpwp_OAuth_Token_Store::active_counts_by_client(), 'Expired and revoked tokens do not count.' );

		// An epoch alone — no row marked — also takes a token off the lists.
		update_user_meta( self::ALICE, Mcpwp_OAuth_Revocation::USER_META, (string) Mcpwp_OAuth_Revocation::now() );
		$this->assertSame( array(), Mcpwp_OAuth_Token_Store::live_apps_for_user( self::ALICE ) );
		$this->assertSame( array(), Mcpwp_OAuth_Token_Store::active_counts_by_client() );
	}

	// ── B. admin app list ─────────────────────────────────────────────────

	public function test_only_manage_options_sees_the_app_list(): void {
		$this->as_user( self::EDITOR );
		$this->assertSame( '', $this->html( array( 'Mcpwp_OAuth_Apps_Admin', 'render_clients_list' ) ) );
		$this->as_user( self::ADMIN );
		$this->assertStringContainsString( 'ChatGPT', $this->html( array( 'Mcpwp_OAuth_Apps_Admin', 'render_clients_list' ) ) );
	}

	public function test_the_app_list_shows_its_columns_and_escapes_registered_names(): void {
		$this->grant( self::ALICE );
		$this->as_user( self::ADMIN );
		$html = $this->html( array( 'Mcpwp_OAuth_Apps_Admin', 'render_clients_list' ) );

		$this->assertStringNotContainsString( '<script>', $html, 'A DCR name is attacker-controlled.' );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
		$this->assertStringNotContainsString( '<img>', $html );
		$this->assertStringContainsString( 'chatgpt.com', $html );
		$this->assertStringContainsString( substr( self::CLIENT, 0, 14 ) . '…', $html );
		$this->assertStringContainsString( '<td>2</td>', $html, 'ChatGPT: one grant, two live tokens (access + refresh).' );
		$this->assertStringContainsString( 'value="nonce|' . Mcpwp_OAuth_Apps_Admin::DELETE_ACTION . '|' . self::CLIENT . '"', $html, 'The Remove nonce is bound to the app.' );
	}

	public function test_delete_needs_manage_options(): void {
		$data = $this->grant( self::ALICE );
		$this->as_user( self::EDITOR );
		$result = Mcpwp_OAuth_Apps_Admin::process_delete( $this->delete_post( self::CLIENT, wp_create_nonce( Mcpwp_OAuth_Apps_Admin::DELETE_ACTION . '|' . self::CLIENT ) ) );
		$this->assertSame( 'error', $result['type'] );
		$this->assertNotNull( Mcpwp_OAuth_Clients::find( self::CLIENT ) );
		$this->assertTrue( $this->works( $data['access_token'] ) );
	}

	public function test_delete_needs_a_nonce_bound_to_that_app(): void {
		$this->as_user( self::ADMIN );
		foreach ( array( null, 'wrong', wp_create_nonce( Mcpwp_OAuth_Apps_Admin::DELETE_ACTION . '|' . self::EVIL ), wp_create_nonce( Mcpwp_OAuth_Apps_Admin::DELETE_ACTION ) ) as $nonce ) {
			$result = Mcpwp_OAuth_Apps_Admin::process_delete( $this->delete_post( self::CLIENT, $nonce ) );
			$this->assertSame( 'error', $result['type'], var_export( $nonce, true ) );
			$this->assertNotNull( Mcpwp_OAuth_Clients::find( self::CLIENT ) );
		}
	}

	public function test_an_admin_delete_removes_the_app_and_its_live_tokens_fail(): void {
		$data = $this->grant( self::ALICE );
		$this->assertTrue( $this->works( $data['access_token'] ) );

		$this->as_user( self::ADMIN );
		$result = Mcpwp_OAuth_Apps_Admin::process_delete( $this->delete_post( self::CLIENT, wp_create_nonce( Mcpwp_OAuth_Apps_Admin::DELETE_ACTION . '|' . self::CLIENT ) ) );
		$this->assertSame( 'updated', $result['type'] );
		$this->assertNull( Mcpwp_OAuth_Clients::find( self::CLIENT ) );
		$this->assertFalse( $this->works( $data['access_token'] ), 'Through check_permission.' );
	}

	public function test_a_non_delete_post_is_ignored(): void {
		$this->as_user( self::ADMIN );
		$this->assertNull( Mcpwp_OAuth_Apps_Admin::process_delete( array( 'something' => 'else' ) ) );
	}

	// ── C. profile: Connected AI apps ─────────────────────────────────────

	public function test_a_user_sees_and_revokes_their_own_app(): void {
		$chatgpt = $this->grant( self::ALICE );
		$evil    = $this->grant( self::ALICE, self::EVIL );

		$this->as_user( self::ALICE );
		$html = $this->html( function () { Mcpwp_OAuth_Apps_Admin::render_profile_section( (object) array( 'ID' => self::ALICE ) ); } );
		$this->assertStringContainsString( 'Connected AI apps', $html );
		$this->assertStringContainsString( 'the app loses access immediately; it will need to be connected again', $html );
		$this->assertStringContainsString( 'ChatGPT', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( 'Read and write', $html );
		$this->assertStringNotContainsString( Mcpwp_OAuth_Apps_Admin::FIELD_REVOKE_ALL, $html, 'Disconnect all is for administrators.' );

		$result = Mcpwp_OAuth_Apps_Admin::process_profile( self::ALICE, $this->profile_post( self::ALICE, array( Mcpwp_OAuth_Apps_Admin::FIELD_REVOKE => self::EVIL ) ) );
		$this->assertSame( 'updated', $result['type'] );
		$this->assertFalse( $this->works( $evil['access_token'] ), 'Revoked, through check_permission.' );
		$this->assertTrue( $this->works( $chatgpt['access_token'] ), 'The other app stays.' );
	}

	public function test_the_revoke_notice_escapes_the_app_name(): void {
		$this->grant( self::ALICE, self::EVIL );
		$this->as_user( self::ALICE );
		$GLOBALS['mcpwp_test_current_user'] = self::ALICE;
		// What handle_revoke_request() does with the result (it then exits).
		$result   = Mcpwp_OAuth_Apps_Admin::process_profile( self::ALICE, $this->profile_post( self::ALICE, array( Mcpwp_OAuth_Apps_Admin::FIELD_REVOKE => self::EVIL ) ) );
		$remember = new ReflectionMethod( 'Mcpwp_OAuth_Apps_Admin', 'remember_notice' );
		$remember->setAccessible( true );
		$remember->invoke( null, $result );
		$html = $this->html( function () { Mcpwp_OAuth_Apps_Admin::render_profile_section( (object) array( 'ID' => self::ALICE ) ); } );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt; lost access immediately', $html );
		$this->assertStringNotContainsString( '<script>', $html );
	}

	public function test_user_a_cannot_see_or_revoke_user_bs_apps(): void {
		$bob = $this->grant( self::BOB );
		$this->as_user( self::ALICE );

		$this->assertSame( '', $this->html( function () { Mcpwp_OAuth_Apps_Admin::render_profile_section( (object) array( 'ID' => self::BOB ) ); } ) );

		// With Bob's nonce, with her own, with none: refused every way.
		foreach ( array( 'own', wp_create_nonce( Mcpwp_OAuth_Apps_Admin::PROFILE_ACTION . '|' . self::ALICE ), null ) as $nonce ) {
			$result = Mcpwp_OAuth_Apps_Admin::process_profile( self::BOB, $this->profile_post( self::BOB, array( Mcpwp_OAuth_Apps_Admin::FIELD_REVOKE => self::CLIENT ), $nonce ) );
			$this->assertSame( 'error', $result['type'] );
		}
		$this->assertTrue( $this->works( $bob['access_token'] ) );
	}

	public function test_a_profile_revoke_needs_a_nonce_bound_to_that_user(): void {
		$alice = $this->grant( self::ALICE );
		$this->as_user( self::ALICE );
		foreach ( array( null, 'wrong', wp_create_nonce( Mcpwp_OAuth_Apps_Admin::PROFILE_ACTION . '|' . self::BOB ), wp_create_nonce( Mcpwp_OAuth_Apps_Admin::PROFILE_ACTION ) ) as $nonce ) {
			$result = Mcpwp_OAuth_Apps_Admin::process_profile( self::ALICE, $this->profile_post( self::ALICE, array( Mcpwp_OAuth_Apps_Admin::FIELD_REVOKE => self::CLIENT ), $nonce ) );
			$this->assertSame( 'error', $result['type'], var_export( $nonce, true ) );
		}
		$this->assertTrue( $this->works( $alice['access_token'] ) );
	}

	public function test_an_editor_without_edit_users_cannot_revoke_for_someone_else(): void {
		$GLOBALS['mcpwp_test_user_caps'][ self::EDITOR ]['manage_options'] = true; // manage_options alone is not enough
		$bob = $this->grant( self::BOB );
		$this->as_user( self::EDITOR );
		$result = Mcpwp_OAuth_Apps_Admin::process_profile( self::BOB, $this->profile_post( self::BOB, array( Mcpwp_OAuth_Apps_Admin::FIELD_REVOKE => self::CLIENT ) ) );
		$this->assertSame( 'error', $result['type'] );
		$this->assertTrue( $this->works( $bob['access_token'] ) );
	}

	public function test_an_admin_revokes_anyones_app_and_can_disconnect_all(): void {
		$bob_chatgpt = $this->grant( self::BOB );
		$bob_evil    = $this->grant( self::BOB, self::EVIL );
		$alice       = $this->grant( self::ALICE );
		$this->as_user( self::ADMIN );

		$html = $this->html( function () { Mcpwp_OAuth_Apps_Admin::render_profile_section( (object) array( 'ID' => self::BOB ) ); } );
		$this->assertStringContainsString( 'Disconnect all AI apps for this user', $html );

		$result = Mcpwp_OAuth_Apps_Admin::process_profile( self::BOB, $this->profile_post( self::BOB, array( Mcpwp_OAuth_Apps_Admin::FIELD_REVOKE => self::CLIENT ) ) );
		$this->assertSame( 'updated', $result['type'] );
		$this->assertFalse( $this->works( $bob_chatgpt['access_token'] ) );
		$this->assertTrue( $this->works( $bob_evil['access_token'] ) );

		$result = Mcpwp_OAuth_Apps_Admin::process_profile( self::BOB, $this->profile_post( self::BOB, array( Mcpwp_OAuth_Apps_Admin::FIELD_REVOKE_ALL => '1' ) ) );
		$this->assertSame( 'updated', $result['type'] );
		$this->assertFalse( $this->works( $bob_evil['access_token'] ), 'Disconnect all.' );
		$this->assertTrue( $this->works( $alice['access_token'] ), 'Only Bob.' );
		$this->assertSame( array(), Mcpwp_OAuth_Token_Store::live_apps_for_user( self::BOB ) );
	}

	public function test_a_user_cannot_disconnect_all_even_for_themself(): void {
		$alice = $this->grant( self::ALICE );
		$this->as_user( self::ALICE );
		$result = Mcpwp_OAuth_Apps_Admin::process_profile( self::ALICE, $this->profile_post( self::ALICE, array( Mcpwp_OAuth_Apps_Admin::FIELD_REVOKE_ALL => '1' ) ) );
		$this->assertSame( 'error', $result['type'] );
		$this->assertTrue( $this->works( $alice['access_token'] ) );
	}

	public function test_a_profile_save_without_our_buttons_does_nothing(): void {
		$alice = $this->grant( self::ALICE );
		$this->as_user( self::ALICE );
		$this->assertNull( Mcpwp_OAuth_Apps_Admin::process_profile( self::ALICE, array( 'first_name' => 'A' ) ) );
		$this->assertTrue( $this->works( $alice['access_token'] ) );
	}

	public function test_logged_out_nobody_manages_anything(): void {
		$this->as_user( 0 );
		$this->assertFalse( Mcpwp_OAuth_Apps_Admin::can_manage_apps_of( self::ALICE ) );
		$this->assertSame( 'error', Mcpwp_OAuth_Apps_Admin::process_profile( self::ALICE, $this->profile_post( self::ALICE, array( Mcpwp_OAuth_Apps_Admin::FIELD_REVOKE => self::CLIENT ) ) )['type'] );
	}
}
