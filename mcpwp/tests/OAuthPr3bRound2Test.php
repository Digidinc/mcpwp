<?php
/**
 * #1101 PR-3b round 2 — legacy (pre-#1101) tokens keep reuse detection and
 * revocation, the epoch meta is protected, the profile Revoke is Enter-safe,
 * and the survivors from the review lenses.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-oauth-token-store.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-oauth.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-oauth-well-known.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-mcpwp-oauth-apps-admin.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-uninstaller.php';

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return filter_var( (string) $url, FILTER_SANITIZE_URL );
	}
}

final class OAuthPr3bRound2Test extends TestCase {

	const USER     = 1;
	const ADMIN    = 9;
	const DCR      = 'mcpwpc_0123456789abcdef0123456789abcdef';
	const REDIRECT = 'https://chatgpt.com/cb';
	const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
	const CAPS     = array( 'read' => true, 'edit_posts' => true, 'edit_others_posts' => true );

	/** @var array */
	private $saved_users;
	/** @var mixed */
	private $saved_addr;

	protected function setUp(): void {
		$this->saved_users = $GLOBALS['mcpwp_test_users'];
		$this->saved_addr  = $_SERVER['REMOTE_ADDR'] ?? null;
		foreach ( array( self::USER, self::ADMIN ) as $uid ) {
			$GLOBALS['mcpwp_test_users'][] = (object) array( 'ID' => $uid, 'roles' => array(), 'user_login' => 'u' . $uid );
		}
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_wpdb_options'] = array();
		$GLOBALS['mcpwp_test_user_meta']    = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_nonce_strict'] = true;
		$GLOBALS['mcpwp_test_user_caps']    = array(
			self::USER  => self::CAPS,
			self::ADMIN => array_merge( self::CAPS, array( 'manage_options' => true, 'edit_users' => true ) ),
		);
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
		update_option( 'mcpwp_settings', array( 'oauth_enabled' => true, 'oauth_client_id' => 'test_client', 'oauth_token_ttl' => 3600, 'oauth_redirect_uris' => array( 'https://client.example.com/cb' ) ) );
		update_option( Mcpwp_OAuth_Clients::OPTION, array( self::DCR => array( 'client_id' => self::DCR, 'client_name' => 'ChatGPT', 'redirect_uris' => array( self::REDIRECT ), 'created_at' => time() - 100, 'last_used_at' => time() - 50, 'authorized_at' => time() - 50 ) ), false );
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_users']                  = $this->saved_users;
		$GLOBALS['mcpwp_test_user_caps']              = array();
		$GLOBALS['mcpwp_test_current_user']           = 0;
		$GLOBALS['mcpwp_test_nonce_strict']           = false;
		$GLOBALS['mcpwp_test_user_meta_write_fails']  = array();
		unset( $GLOBALS['mcpwp_test_option_store_hook'] );
		if ( null === $this->saved_addr ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $this->saved_addr;
		}
	}

	// ── helpers ───────────────────────────────────────────────────────────

	private function ctrl(): Mcpwp_REST_OAuth {
		return new Mcpwp_REST_OAuth();
	}

	private function legacy_refresh( array $extra = array() ): string {
		$t = 'mcpwp_rt_' . bin2hex( random_bytes( 32 ) );
		set_transient( $this->ctrl()->get_oauth_refresh_token_transient_key( $t ), array_merge( array( 'scopes' => array( 'read', 'write' ), 'user_id' => self::USER, 'created_at' => time() - 60, 'expires_at' => time() + 3600 ), $extra ), 3600 );
		return $t;
	}

	private function legacy_access(): string {
		$t = 'mcpwp_at_' . bin2hex( random_bytes( 24 ) );
		set_transient( 'mcpwp_oauth_token_' . md5( $t ), array( 'scopes' => array( 'read' ), 'user_id' => self::USER, 'aud' => Mcpwp_OAuth_Well_Known::get_canonical_resource(), 'created_at' => time() - 60, 'expires_at' => time() + 600 ), 600 );
		return $t;
	}

	private function refresh( string $token, string $client = self::DCR ): WP_REST_Response {
		return $this->ctrl()->handle_token( new WP_REST_Request( 'POST', '/mcpwp/v1/oauth/token', array( 'grant_type' => 'refresh_token', 'refresh_token' => $token, 'client_id' => $client ) ) );
	}

	private function grant(): array {
		$code = 'code_' . bin2hex( random_bytes( 8 ) );
		set_transient( 'mcpwp_oauth_code_' . $code, array( 'client_id' => self::DCR, 'redirect_uri' => self::REDIRECT, 'code_challenge' => $this->ctrl()->compute_pkce_challenge( self::VERIFIER ), 'scope' => 'read write', 'user_id' => self::USER, 'issued_at' => Mcpwp_OAuth_Revocation::now(), 'expires' => time() + 60 ), 60 );
		$r = $this->ctrl()->handle_token( new WP_REST_Request( 'POST', '/mcpwp/v1/oauth/token', array( 'grant_type' => 'authorization_code', 'code' => $code, 'client_id' => self::DCR, 'redirect_uri' => self::REDIRECT, 'code_verifier' => self::VERIFIER ) ) );
		$this->assertSame( 200, $r->get_status() );
		return $r->get_data();
	}

	private function works( string $access_token ): bool {
		$saved  = $GLOBALS['mcpwp_test_current_user'];
		$result = true === ( new Mcpwp_Api_Auth_Test_Harness() )->verify_api_key( new WP_REST_Request( 'GET', '/mcpwp/v1/site-info', array(), array( 'Authorization' => 'Bearer ' . $access_token ) ) );
		$GLOBALS['mcpwp_test_current_user'] = $saved;
		return $result;
	}

	private function html( callable $render ): string {
		ob_start();
		$render();
		return (string) ob_get_clean();
	}

	private function quiet( callable $fn ) {
		$log = ini_set( 'error_log', '/dev/null' );
		try {
			return $fn();
		} finally {
			ini_set( 'error_log', (string) $log );
		}
	}

	// ── P0: the claim names the family and client ─────────────────────────

	public function test_a_legacy_refresh_token_migrates_into_a_deterministic_family_and_its_tombstone_is_bound(): void {
		$legacy = $this->legacy_refresh();
		$minted = $this->refresh( $legacy )->get_data();

		$row = mcpwp_test_oauth_row( $legacy );
		$this->assertSame( 'reuse_tombstone', $row['type'] );
		$this->assertSame( Mcpwp_OAuth_Token_Store::legacy_family_id( $legacy ), $row['family_id'] );
		$this->assertSame( self::DCR, $row['client_id'], 'The tombstone names the client it was rotated into.' );
		$this->assertSame( $row['family_id'], mcpwp_test_oauth_row( $minted['refresh_token'] )['family_id'] );
	}

	public function test_claim_refresh_rewrites_family_and_client_on_the_tombstone(): void {
		$t = 'mcpwp_rt_' . bin2hex( random_bytes( 32 ) );
		Mcpwp_OAuth_Token_Store::insert( $t, 'refresh', array( 'scopes' => array( 'read' ), 'expires_at' => time() + 60 ) );
		$this->assertTrue( Mcpwp_OAuth_Token_Store::claim_refresh( $t, 'fam-x', 'cli-y' ) );
		$this->assertSame( array( 'reuse_tombstone', 'fam-x', 'cli-y' ), array( mcpwp_test_oauth_row( $t )['type'], mcpwp_test_oauth_row( $t )['family_id'], mcpwp_test_oauth_row( $t )['client_id'] ) );
	}

	// ── P1 (a): protected epoch meta ──────────────────────────────────────

	/**
	 * PUT /users/{id} meta (Mcpwp_Users::update_user) cannot set or clear the
	 * revocation epoch.  Separate process: the WordPress user functions it
	 * needs are defined here and must not leak into other tests.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_users_put_meta_cannot_set_or_clear_the_epoch(): void {
		require_once dirname( __DIR__ ) . '/includes/pro/core/class-mcpwp-users.php';
		if ( ! function_exists( 'get_user_by' ) ) {
			function get_user_by( $field, $value ) {
				return (object) array( 'ID' => (int) $value, 'user_login' => 'u', 'user_email' => 'u@x.test', 'roles' => array( 'editor' ) );
			}
		}
		if ( ! function_exists( 'wp_update_user' ) ) {
			function wp_update_user( $data ) {
				return (int) $data['ID'];
			}
		}
		$users = new class() extends Mcpwp_Users {
			public function __construct() {}
			public function get_user( $user_id ) {
				return array( 'id' => (int) $user_id );
			}
		};
		$GLOBALS['mcpwp_test_current_user'] = self::ADMIN;
		$epoch = (string) Mcpwp_OAuth_Revocation::now();
		update_user_meta( 5, Mcpwp_OAuth_Revocation::USER_META, $epoch );

		foreach ( array( '0', '', '99999999999999999' ) as $forged ) {
			$users->update_user( 5, array( 'meta' => array( 'mcpwp_oauth_revoked_before' => $forged, 'nickname_extra' => 'ok' ) ) );
			$this->assertSame( $epoch, get_user_meta( 5, Mcpwp_OAuth_Revocation::USER_META, true ), 'forged ' . var_export( $forged, true ) );
		}
		$this->assertSame( 'ok', get_user_meta( 5, 'nickname_extra', true ), 'Ordinary meta still writes.' );
	}

	// ── P1 (b): legacy, client-less tokens ────────────────────────────────

	public function test_a_per_app_revoke_reaches_the_users_legacy_tokens_migrated_or_not(): void {
		$this->grant();
		$migrated_access = $this->legacy_access();
		$this->assertTrue( $this->works( $migrated_access ), 'Migrated into an unattributed row.' );
		$unmigrated_refresh = $this->legacy_refresh();
		$unmigrated_access  = $this->legacy_access();

		usleep( 20 );
		$this->assertTrue( Mcpwp_OAuth_Revocation::revoke_user_client( self::USER, self::DCR ) );

		$this->assertNotNull( mcpwp_test_oauth_row( $migrated_access )['revoked_at'], 'The unattributed row is marked.' );
		$this->assertFalse( $this->works( $migrated_access ) );
		$this->assertFalse( $this->works( $unmigrated_access ), 'Not yet migrated: the epoch rule catches it at migration.' );
		$this->assertNotSame( 200, $this->refresh( $unmigrated_refresh )->get_status() );
	}

	public function test_revoke_user_reaches_legacy_tokens(): void {
		$access  = $this->legacy_access();
		$refresh = $this->legacy_refresh();
		usleep( 20 );
		$this->assertTrue( Mcpwp_OAuth_Revocation::revoke_user( self::USER ) );
		$this->assertFalse( $this->works( $access ) );
		$this->assertNotSame( 200, $this->refresh( $refresh )->get_status() );
	}

	public function test_a_legacy_refresh_is_attributed_to_its_presenting_client_for_the_epoch_check(): void {
		$legacy = $this->legacy_refresh();
		usleep( 20 );
		Mcpwp_OAuth_Revocation::revoke_client( self::DCR ); // the app is revoked (not deleted)
		$this->assertNotSame( 200, $this->refresh( $legacy )->get_status(), 'Presented by the revoked app: refused.' );
	}

	public function test_admin_remove_refuses_a_legacy_refresh_presented_by_the_removed_app(): void {
		$legacy = $this->legacy_refresh();
		$this->assertTrue( Mcpwp_OAuth_Clients::delete( self::DCR ) );
		$this->assertNotSame( 200, $this->refresh( $legacy )->get_status() );
	}

	public function test_the_profile_lists_legacy_tokens_and_their_revoke_cuts_only_those(): void {
		$bound  = $this->grant();
		$legacy = $this->legacy_access();
		$this->works( $legacy ); // migrated: now a row with no client

		$apps = Mcpwp_OAuth_Token_Store::live_apps_for_user( self::USER );
		$this->assertArrayHasKey( Mcpwp_OAuth_Token_Store::LEGACY_APP, $apps );
		$this->assertSame( 'App connected before this update', $apps['legacy']['client_name'] );

		$GLOBALS['mcpwp_test_current_user'] = self::USER;
		$html = $this->html( function () { Mcpwp_OAuth_Apps_Admin::render_profile_section( (object) array( 'ID' => self::USER ) ); } );
		$this->assertStringContainsString( 'App connected before this update', $html );

		$result = Mcpwp_OAuth_Apps_Admin::process_profile( self::USER, array( Mcpwp_OAuth_Apps_Admin::FIELD_REVOKE => 'legacy', Mcpwp_OAuth_Apps_Admin::NONCE_FIELD => wp_create_nonce( Mcpwp_OAuth_Apps_Admin::PROFILE_ACTION . '|' . self::USER ) ) );
		$this->assertSame( 'updated', $result['type'] );
		$this->assertFalse( $this->works( $legacy ) );
		$this->assertTrue( $this->works( $bound['access_token'] ), 'The bound app stays.' );
		$this->assertArrayHasKey( '1|', get_option( Mcpwp_OAuth_Revocation::PAIRS_OPTION ), 'One bounded key per user.' );
	}

	// ── P2.1: access-token revoke checks its write ────────────────────────

	public function test_a_failed_access_token_revoke_is_503_and_the_retry_revokes(): void {
		$g = $this->grant();
		$GLOBALS['mcpwp_test_oauth_tokens_fail'] = function ( $kind ) { return 'query' === $kind; };
		$first = $this->ctrl()->handle_revoke( new WP_REST_Request( 'POST', '/mcpwp/v1/oauth/revoke', array( 'token' => $g['access_token'], 'client_id' => self::DCR ) ) );
		$GLOBALS['mcpwp_test_oauth_tokens_fail'] = null;
		$this->assertSame( 503, $first->get_status() );
		$this->assertSame( 'temporarily_unavailable', $first->get_data()['error'] );

		$retry = $this->ctrl()->handle_revoke( new WP_REST_Request( 'POST', '/mcpwp/v1/oauth/revoke', array( 'token' => $g['access_token'], 'client_id' => self::DCR ) ) );
		$this->assertSame( 200, $retry->get_status() );
		$this->assertFalse( $this->works( $g['access_token'] ), 'The retry really revoked it.' );
	}

	// ── P2.2: Enter in a profile field revokes nothing ────────────────────

	public function test_the_profile_section_has_no_submit_buttons(): void {
		$this->grant();
		$GLOBALS['mcpwp_test_current_user'] = self::ADMIN;
		$html = $this->html( function () { Mcpwp_OAuth_Apps_Admin::render_profile_section( (object) array( 'ID' => self::USER ) ); } );
		$this->assertStringContainsString( 'Revoke', $html );
		$this->assertStringContainsString( 'Disconnect all AI apps for this user', $html );
		$this->assertDoesNotMatchRegularExpression( '/<button|type="submit"|<input[^>]+type="image"/i', $html, 'Enter presses the first submit button in the profile form: there must be none here.' );
		$this->assertStringContainsString( 'action=' . Mcpwp_OAuth_Apps_Admin::REVOKE_ACTION, $html );
		$this->assertStringContainsString( Mcpwp_OAuth_Apps_Admin::NONCE_FIELD . '=nonce|' . Mcpwp_OAuth_Apps_Admin::PROFILE_ACTION . '|' . self::USER, $html, 'The link carries a nonce bound to the profile owner.' );
	}

	public function test_a_plain_profile_save_revokes_nothing(): void {
		$g = $this->grant();
		$GLOBALS['mcpwp_test_current_user'] = self::USER;
		$post = array( 'action' => 'update', 'user_id' => self::USER, 'first_name' => 'A', 'email' => 'a@x.test', '_wpnonce' => 'x', Mcpwp_OAuth_Apps_Admin::NONCE_FIELD => wp_create_nonce( Mcpwp_OAuth_Apps_Admin::PROFILE_ACTION . '|' . self::USER ) );
		$this->assertNull( Mcpwp_OAuth_Apps_Admin::process_profile( self::USER, $post ) );
		$this->assertTrue( $this->works( $g['access_token'] ) );
		$this->assertArrayNotHasKey( 'personal_options_update', $GLOBALS['mcpwp_test_actions'] ?? array(), 'No profile-save hook acts on our behalf any more.' );
	}

	public function test_the_confirm_page_needs_the_owners_nonce_and_escapes_the_name(): void {
		update_option( Mcpwp_OAuth_Clients::OPTION, array( self::DCR => array( 'client_id' => self::DCR, 'client_name' => '<script>x</script>', 'redirect_uris' => array( self::REDIRECT ), 'created_at' => time() - 100, 'last_used_at' => 0, 'authorized_at' => time() - 50 ) ), false );
		$this->grant();
		$GLOBALS['mcpwp_test_current_user'] = self::USER;
		$good = array( 'user_id' => self::USER, 'app' => self::DCR, Mcpwp_OAuth_Apps_Admin::NONCE_FIELD => wp_create_nonce( Mcpwp_OAuth_Apps_Admin::PROFILE_ACTION . '|' . self::USER ) );

		$page = Mcpwp_OAuth_Apps_Admin::render_confirm_page( $good );
		$this->assertStringContainsString( 'Revoke &lt;script&gt;x&lt;/script&gt;?', $page );
		$this->assertStringNotContainsString( '<script>', $page );
		$this->assertStringContainsString( 'method="post"', $page );
		$this->assertStringContainsString( 'loses access immediately; it will need to be connected again', $page );

		$this->assertSame( '', Mcpwp_OAuth_Apps_Admin::render_confirm_page( array( Mcpwp_OAuth_Apps_Admin::NONCE_FIELD => 'x' ) + $good ), 'Wrong nonce.' );
		$this->assertSame( '', Mcpwp_OAuth_Apps_Admin::render_confirm_page( array( 'app' => 'mcpwpc_nope' ) + $good ), 'Not a connected app.' );
		$this->assertSame( '', Mcpwp_OAuth_Apps_Admin::render_confirm_page( array( 'all' => '1' ) + $good ), 'Disconnect all is for administrators.' );
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$this->assertSame( '', Mcpwp_OAuth_Apps_Admin::render_confirm_page( $good ), 'Logged out.' );
	}

	// ── P2.3: only connected apps ─────────────────────────────────────────

	public function test_a_revoke_for_an_app_the_user_has_not_connected_is_refused_and_writes_nothing(): void {
		$GLOBALS['mcpwp_test_current_user'] = self::USER;
		foreach ( array( self::DCR, 'mcpwpc_never_registered', 'junk' ) as $client ) {
			$r = Mcpwp_OAuth_Apps_Admin::process_profile( self::USER, array( Mcpwp_OAuth_Apps_Admin::FIELD_REVOKE => $client, Mcpwp_OAuth_Apps_Admin::NONCE_FIELD => wp_create_nonce( Mcpwp_OAuth_Apps_Admin::PROFILE_ACTION . '|' . self::USER ) ) );
			$this->assertSame( 'error', $r['type'], $client );
		}
		$this->assertSame( array(), (array) get_option( Mcpwp_OAuth_Revocation::PAIRS_OPTION, array() ) );
	}

	// ── P2.4: a concurrent migration loser uses the winner's row ──────────

	public function test_a_migration_that_loses_the_unique_key_uses_the_winners_row(): void {
		$token = $this->legacy_access();
		$done  = false;
		$GLOBALS['mcpwp_test_oauth_tokens_before'] = function ( $kind ) use ( $token, &$done ) {
			if ( ! $done && 'insert' === $kind ) {
				$done = true; // the other request migrates first
				Mcpwp_OAuth_Token_Store::migrate_from_transient( $token, 'access', 'mcpwp_oauth_token_' . md5( $token ), DAY_IN_SECONDS );
			}
		};
		try {
			$this->assertTrue( $this->works( $token ), 'Not a false 401.' );
		} finally {
			$GLOBALS['mcpwp_test_oauth_tokens_before'] = null;
		}
		$this->assertCount( 1, $GLOBALS['mcpwp_test_oauth_tokens'] );
	}

	// ── P2.5: ensure_table is cheap once current ──────────────────────────

	public function test_ensure_table_runs_nothing_once_the_schema_is_current(): void {
		update_option( Mcpwp_OAuth_Token_Store::SCHEMA_OPTION, Mcpwp_OAuth_Token_Store::SCHEMA_VERSION );
		$GLOBALS['mcpwp_test_dbdelta'] = array();
		$statements = 0;
		$GLOBALS['mcpwp_test_oauth_tokens_before'] = function () use ( &$statements ) {
			++$statements;
		};
		try {
			for ( $i = 0; $i < 5; $i++ ) {
				$this->assertTrue( Mcpwp_OAuth_Token_Store::ensure_table() );
			}
		} finally {
			$GLOBALS['mcpwp_test_oauth_tokens_before'] = null;
		}
		$this->assertSame( array(), $GLOBALS['mcpwp_test_dbdelta'], 'No dbDelta.' );
		$this->assertSame( 0, $statements, 'Not even SHOW TABLES.' );

		update_option( Mcpwp_OAuth_Token_Store::SCHEMA_OPTION, Mcpwp_OAuth_Token_Store::SCHEMA_VERSION - 1 );
		Mcpwp_OAuth_Token_Store::ensure_table();
		$this->assertCount( 1, $GLOBALS['mcpwp_test_dbdelta'], 'Behind: one dbDelta.' );
	}

	// ── survivors ─────────────────────────────────────────────────────────

	/** M7/M10: a transient past its record's expiry is not migrated. */
	public function test_migration_refuses_an_expired_record_and_derives_a_missing_expiry(): void {
		$expired = 'mcpwp_at_' . bin2hex( random_bytes( 24 ) );
		set_transient( 'mcpwp_oauth_token_' . md5( $expired ), array( 'scopes' => array( 'read' ), 'user_id' => self::USER, 'aud' => Mcpwp_OAuth_Well_Known::get_canonical_resource(), 'expires_at' => time() - 1 ), 600 );
		$this->assertFalse( $this->works( $expired ) );
		$this->assertNull( mcpwp_test_oauth_row( $expired ) );

		$old = 'mcpwp_at_' . bin2hex( random_bytes( 24 ) );
		set_transient( 'mcpwp_oauth_token_' . md5( $old ), array( 'scopes' => array( 'read' ), 'user_id' => self::USER, 'aud' => Mcpwp_OAuth_Well_Known::get_canonical_resource(), 'created_at' => time() - DAY_IN_SECONDS - 5 ), 600 );
		$this->assertFalse( $this->works( $old ), 'No expires_at: created_at + the default lifetime, already past.' );

		$young = 'mcpwp_at_' . bin2hex( random_bytes( 24 ) );
		set_transient( 'mcpwp_oauth_token_' . md5( $young ), array( 'scopes' => array( 'read' ), 'user_id' => self::USER, 'aud' => Mcpwp_OAuth_Well_Known::get_canonical_resource(), 'created_at' => time() - 10 ), 600 );
		$this->assertTrue( $this->works( $young ) );
		$this->assertSame( (string) ( time() - 10 + DAY_IN_SECONDS ), mcpwp_test_oauth_row( $young )['expires_at'] );
	}

	/** M34: redirect hosts are printed escaped. */
	public function test_redirect_hosts_are_escaped_in_the_app_list(): void {
		update_option( Mcpwp_OAuth_Clients::OPTION, array( self::DCR => array( 'client_id' => self::DCR, 'client_name' => 'X', 'redirect_uris' => array( 'https://a"onmouseover=alert(1)&b.example/cb' ), 'created_at' => time() - 100, 'last_used_at' => 0, 'authorized_at' => 0 ) ), false );
		$GLOBALS['mcpwp_test_current_user'] = self::ADMIN;
		$html = $this->html( array( 'Mcpwp_OAuth_Apps_Admin', 'render_clients_list' ) );
		$this->assertStringNotContainsString( '"onmouseover', $html );
		$this->assertStringContainsString( 'a&quot;onmouseover=alert(1)&amp;b.example', $html );
	}

	/** Uninstall wiring: the per-site drop reaches the token table and every new option. */
	public function test_uninstall_wiring_covers_the_token_table_and_the_new_options(): void {
		foreach ( array( 'mcpwp_oauth_revoked_pairs', 'mcpwp_oauth_revoked_families', 'mcpwp_oauth_client_epochs', 'mcpwp_oauth_deleted_users', 'mcpwp_oauth_revocation_lock' ) as $option ) {
			$this->assertContains( $option, Mcpwp_Uninstaller::APPROVALS_OPTIONS, $option );
			update_option( $option, array( 'x' => 1 ) );
		}
		Mcpwp_OAuth_Token_Store::insert( 'tok', 'access', array( 'scopes' => array( 'read' ), 'expires_at' => time() + 60 ) );
		update_option( Mcpwp_OAuth_Token_Store::SCHEMA_OPTION, 1 );
		Mcpwp_Uninstaller::drop_approvals_data_for_current_site();
		$this->assertFalse( $GLOBALS['mcpwp_test_oauth_tokens_exists'], 'The token table is dropped by the same per-site call.' );
		$this->assertFalse( get_option( 'mcpwp_oauth_revoked_pairs', false ) );
		$this->assertFalse( get_option( Mcpwp_OAuth_Token_Store::SCHEMA_OPTION, false ) );
	}

	/** Old M11/M14: write_map_entry verifies its write; revoke_user verifies its meta. */
	public function test_a_map_write_that_does_not_land_is_false_and_reported(): void {
		$GLOBALS['mcpwp_test_option_store_hook'] = function ( $name, $value ) {
			return Mcpwp_OAuth_Revocation::CLIENT_EPOCHS_OPTION === $name ? array() : $value;
		};
		$this->assertFalse( $this->quiet( function () { return Mcpwp_OAuth_Revocation::revoke_client( 'test_client' ); } ) );
	}

	public function test_a_user_revoke_whose_meta_does_not_land_is_false(): void {
		$GLOBALS['mcpwp_test_user_meta_write_fails'] = array( Mcpwp_OAuth_Revocation::USER_META => true );
		$this->assertFalse( $this->quiet( function () { return Mcpwp_OAuth_Revocation::revoke_user( self::USER ); } ) );
	}

	/** M16: a 503 (store busy) costs no registration slot. */
	public function test_a_busy_registration_costs_no_dcr_slot(): void {
		$GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_OAuth_Clients::LOCK_OPTION ] = ( time() + 60 ) . '|held';
		$result = Mcpwp_OAuth_Clients::register( array( 'redirect_uris' => array( self::REDIRECT ) ), '198.51.100.40' );
		$this->assertSame( 503, $result->get_error_data()['status'] );
		foreach ( array_keys( $GLOBALS['mcpwp_test_transients'] ) as $name ) {
			$this->assertStringStartsNotWith( 'mcpwp_oauth_dcr_', $name, 'No bucket was counted.' );
		}
		unset( $GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_OAuth_Clients::LOCK_OPTION ] );
		for ( $i = 0; $i < Mcpwp_OAuth_Clients::REGISTRATIONS_PER_WINDOW; $i++ ) {
			$this->assertIsArray( Mcpwp_OAuth_Clients::register( array( 'redirect_uris' => array( self::REDIRECT ) ), '198.51.100.40' ), 'The full hourly allowance remains: ' . $i );
		}
	}
}
