<?php
/**
 * #1101 PR-3b — persistent hashed OAuth token storage.
 *
 * The table double (Mcpwp_Test_OAuth_Tokens_Table in bootstrap.php) answers
 * the store's exact statements, keeps token_hash UNIQUE, returns affected
 * rows from every UPDATE, and can be made missing or failing.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-oauth-token-store.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-oauth.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-oauth-well-known.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-uninstaller.php';

final class OAuthTokenStore1101bTest extends TestCase {

	const EDITOR_CAPS = array( 'read' => true, 'edit_posts' => true, 'edit_others_posts' => true );
	const REDIRECT    = 'https://client.example.com/callback';
	const VERIFIER    = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

	/** @var array */
	private $saved_users;

	protected function setUp(): void {
		$this->saved_users = $GLOBALS['mcpwp_test_users'];
		$GLOBALS['mcpwp_test_users'][] = (object) array( 'ID' => 1, 'roles' => array(), 'user_login' => 'user1' );
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_wpdb_options'] = array();
		$GLOBALS['mcpwp_test_user_meta']    = array();
		$GLOBALS['mcpwp_test_cron']         = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_user_caps']    = array( 1 => self::EDITOR_CAPS );
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
		$GLOBALS['mcpwp_test_users']     = $this->saved_users;
		$GLOBALS['mcpwp_test_user_caps'] = array();
	}

	// ── helpers ───────────────────────────────────────────────────────────

	private function ctrl(): Mcpwp_REST_OAuth {
		return new Mcpwp_REST_OAuth();
	}

	private function seed_code(): string {
		$code = 'code_' . bin2hex( random_bytes( 8 ) );
		set_transient( 'mcpwp_oauth_code_' . $code, array( 'client_id' => 'test_client', 'redirect_uri' => self::REDIRECT, 'code_challenge' => $this->ctrl()->compute_pkce_challenge( self::VERIFIER ), 'scope' => 'read write', 'user_id' => 1, 'issued_at' => Mcpwp_OAuth_Revocation::now(), 'expires' => time() + 60 ), 60 );
		return $code;
	}

	private function redeem( string $code ): WP_REST_Response {
		return $this->ctrl()->handle_token( new WP_REST_Request( 'POST', '/mcpwp/v1/oauth/token', array( 'grant_type' => 'authorization_code', 'code' => $code, 'client_id' => 'test_client', 'redirect_uri' => self::REDIRECT, 'code_verifier' => self::VERIFIER ) ) );
	}

	private function refresh( string $token ): WP_REST_Response {
		return $this->ctrl()->handle_token( new WP_REST_Request( 'POST', '/mcpwp/v1/oauth/token', array( 'grant_type' => 'refresh_token', 'refresh_token' => $token, 'client_id' => 'test_client' ) ) );
	}

	private function works( string $access_token ): bool {
		return true === ( new Mcpwp_Api_Auth_Test_Harness() )->verify_api_key( new WP_REST_Request( 'GET', '/mcpwp/v1/site-info', array(), array( 'Authorization' => 'Bearer ' . $access_token ) ) );
	}

	// ── schema ────────────────────────────────────────────────────────────

	public function test_ensure_table_creates_records_the_version_and_opens_the_grace_window(): void {
		$GLOBALS['mcpwp_test_oauth_tokens_exists'] = false;
		$GLOBALS['mcpwp_test_dbdelta']             = array();

		$this->assertTrue( Mcpwp_OAuth_Token_Store::ensure_table() );
		$this->assertMatchesRegularExpression( '/CREATE TABLE wp_mcpwp_oauth_tokens \(.*token_hash char\(64\) NOT NULL.*revoked_at bigint\(20\) unsigned DEFAULT NULL.*UNIQUE KEY token_hash \(token_hash\)/s', implode( "\n", $GLOBALS['mcpwp_test_dbdelta'] ) );
		$this->assertSame( Mcpwp_OAuth_Token_Store::SCHEMA_VERSION, get_option( Mcpwp_OAuth_Token_Store::SCHEMA_OPTION ) );
		$this->assertEqualsWithDelta( time() + Mcpwp_OAuth_Token_Store::grace_seconds(), (int) get_option( Mcpwp_OAuth_Token_Store::GRACE_OPTION ), 5 );

		$GLOBALS['mcpwp_test_dbdelta'] = array();
		$this->assertTrue( Mcpwp_OAuth_Token_Store::ensure_table() );
		$this->assertSame( array(), $GLOBALS['mcpwp_test_dbdelta'], 'Once current: no dbDelta, one option read.' );
	}

	public function test_ensure_table_records_nothing_when_the_table_did_not_appear(): void {
		$GLOBALS['mcpwp_test_oauth_tokens_exists'] = false;
		// dbDelta() returns without the table being there (no CREATE
		// privilege, a failed ALTER…): the existence check runs after it.
		$GLOBALS['mcpwp_test_oauth_tokens_before'] = function ( $kind ) {
			if ( 'get_var' === $kind ) {
				$GLOBALS['mcpwp_test_oauth_tokens_exists'] = false;
			}
		};
		try {
			$this->assertFalse( Mcpwp_OAuth_Token_Store::ensure_table() );
		} finally {
			$GLOBALS['mcpwp_test_oauth_tokens_before'] = null;
		}
		$this->assertFalse( get_option( Mcpwp_OAuth_Token_Store::SCHEMA_OPTION, false ), 'No version recorded: the next load retries.' );
	}

	// ── CRUD ──────────────────────────────────────────────────────────────

	public function test_rows_are_keyed_by_hash_and_the_token_is_never_stored(): void {
		$token = 'mcpwp_at_' . bin2hex( random_bytes( 24 ) );
		$this->assertTrue( Mcpwp_OAuth_Token_Store::insert( $token, 'access', array( 'scopes' => array( 'read', 'write' ), 'user_id' => 1, 'client_id' => 'c1', 'family_id' => 'f1', 'aud' => 'https://x/mcp', 'issued_at' => 1760000000123456, 'expires_at' => time() + 60 ) ) );

		$row = Mcpwp_OAuth_Token_Store::find( $token );
		$this->assertSame( hash( 'sha256', $token ), $row['token_hash'] );
		$this->assertStringNotContainsString( $token, wp_json_encode( $GLOBALS['mcpwp_test_oauth_tokens'] ), 'Only the hash.' );
		$this->assertSame(
			array( 'scopes' => array( 'read', 'write' ), 'aud' => 'https://x/mcp', 'issued_at' => 1760000000123456, 'expires_at' => (int) $row['expires_at'], 'family_id' => 'f1', 'client_id' => 'c1', 'user_id' => 1 ),
			Mcpwp_OAuth_Token_Store::to_record( $row )
		);
		$this->assertTrue( Mcpwp_OAuth_Token_Store::is_active( $row, 'access' ) );
		$this->assertFalse( Mcpwp_OAuth_Token_Store::is_active( $row, 'refresh' ), 'Type matters.' );

		$this->assertFalse( Mcpwp_OAuth_Token_Store::insert( $token, 'access', array( 'scopes' => array( 'read' ), 'expires_at' => time() + 60 ) ), 'token_hash is unique.' );

		$this->assertTrue( Mcpwp_OAuth_Token_Store::revoke( $token ) );
		$this->assertFalse( Mcpwp_OAuth_Token_Store::revoke( $token ), 'Already revoked: 0 rows.' );
		$this->assertFalse( Mcpwp_OAuth_Token_Store::is_active( Mcpwp_OAuth_Token_Store::find( $token ), 'access' ) );
	}

	public function test_an_expired_row_is_not_active(): void {
		$token = 'mcpwp_at_' . bin2hex( random_bytes( 24 ) );
		Mcpwp_OAuth_Token_Store::insert( $token, 'access', array( 'scopes' => array( 'read' ), 'expires_at' => time() + 60 ) );
		mcpwp_test_oauth_set( $token, array( 'expires_at' => time() ) );
		$this->assertFalse( Mcpwp_OAuth_Token_Store::is_active( Mcpwp_OAuth_Token_Store::find( $token ), 'access' ) );
	}

	public function test_revoke_family_marks_every_live_row_of_the_family(): void {
		foreach ( array( 'a', 'b', 'c' ) as $i => $n ) {
			Mcpwp_OAuth_Token_Store::insert( 'tok_' . $n, 'access', array( 'scopes' => array( 'read' ), 'family_id' => 2 === $i ? 'other' : 'fam', 'expires_at' => time() + 60 ) );
		}
		$this->assertTrue( Mcpwp_OAuth_Token_Store::revoke_family( 'fam' ) );
		$this->assertNotNull( mcpwp_test_oauth_row( 'tok_a' )['revoked_at'] );
		$this->assertNotNull( mcpwp_test_oauth_row( 'tok_b' )['revoked_at'] );
		$this->assertNull( mcpwp_test_oauth_row( 'tok_c' )['revoked_at'] );
	}

	public function test_last_used_is_recorded_on_use_at_most_once_a_minute(): void {
		$data = $this->redeem( $this->seed_code() )->get_data();
		$this->assertSame( '0', mcpwp_test_oauth_row( $data['access_token'] )['last_used_at'] );
		$this->assertTrue( $this->works( $data['access_token'] ) );
		$first = (int) mcpwp_test_oauth_row( $data['access_token'] )['last_used_at'];
		$this->assertEqualsWithDelta( time(), $first, 2 );

		mcpwp_test_oauth_set( $data['access_token'], array( 'last_used_at' => time() - 30 ) );
		$this->works( $data['access_token'] );
		$this->assertSame( (string) ( time() - 30 ), mcpwp_test_oauth_row( $data['access_token'] )['last_used_at'], 'Within the interval: not rewritten.' );
	}

	// ── atomic claim ──────────────────────────────────────────────────────

	public function test_a_refresh_claim_passes_once_and_the_second_affects_no_row(): void {
		$token = 'mcpwp_rt_' . bin2hex( random_bytes( 32 ) );
		Mcpwp_OAuth_Token_Store::insert( $token, 'refresh', array( 'scopes' => array( 'read' ), 'family_id' => 'f', 'expires_at' => time() + 60 ) );

		// A concurrent claim lands between this one's read and its UPDATE.
		$inner = null;
		$GLOBALS['mcpwp_test_oauth_tokens_before'] = function ( $kind, $sql ) use ( $token, &$inner ) {
			if ( null === $inner && 'query' === $kind && false !== strpos( $sql, "SET type = 'reuse_tombstone'" ) ) {
				$inner = false;
				$inner = Mcpwp_OAuth_Token_Store::claim_refresh( $token );
			}
		};
		try {
			$outer = Mcpwp_OAuth_Token_Store::claim_refresh( $token );
		} finally {
			$GLOBALS['mcpwp_test_oauth_tokens_before'] = null;
		}
		$this->assertTrue( $inner );
		$this->assertFalse( $outer, 'affected rows 0: refused.' );
		$this->assertSame( 'reuse_tombstone', mcpwp_test_oauth_row( $token )['type'], 'The claimed row is its own tombstone.' );
	}

	public function test_a_revoked_refresh_row_cannot_be_claimed(): void {
		$token = 'mcpwp_rt_' . bin2hex( random_bytes( 32 ) );
		Mcpwp_OAuth_Token_Store::insert( $token, 'refresh', array( 'scopes' => array( 'read' ), 'expires_at' => time() + 60 ) );
		Mcpwp_OAuth_Token_Store::revoke( $token );
		$this->assertFalse( Mcpwp_OAuth_Token_Store::claim_refresh( $token ) );
	}

	// ── transient → table migration ───────────────────────────────────────

	public function test_a_pre_table_access_token_moves_into_the_table_on_use(): void {
		$token = 'mcpwp_at_' . bin2hex( random_bytes( 24 ) );
		set_transient( 'mcpwp_oauth_token_' . md5( $token ), array( 'scopes' => array( 'read' ), 'user_id' => 1, 'aud' => Mcpwp_OAuth_Well_Known::get_canonical_resource(), 'created_at' => time() - 10, 'expires_at' => time() + 600 ), 600 );

		$this->assertTrue( $this->works( $token ) );
		$this->assertFalse( get_transient( 'mcpwp_oauth_token_' . md5( $token ) ), 'The transient is gone…' );
		$row = mcpwp_test_oauth_row( $token );
		$this->assertSame( 'access', $row['type'], '…and the row is there.' );
		$this->assertSame( (string) ( ( time() - 10 ) * 1000000 ), $row['issued_at'], 'created_at seconds, as microseconds.' );
		$this->assertTrue( $this->works( $token ), 'And it keeps working from the table.' );
	}

	public function test_a_pre_table_refresh_token_migrates_and_rotates(): void {
		$token = 'mcpwp_rt_' . bin2hex( random_bytes( 32 ) );
		set_transient( $this->ctrl()->get_oauth_refresh_token_transient_key( $token ), array( 'scopes' => array( 'read', 'write' ), 'user_id' => 1, 'created_at' => time() - 10, 'expires_at' => time() + 3600 ), 3600 );

		$response = $this->refresh( $token );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'reuse_tombstone', mcpwp_test_oauth_row( $token )['type'], 'Migrated, then claimed.' );
		$this->assertSame( 'test_client', mcpwp_test_oauth_row( $response->get_data()['refresh_token'] )['client_id'] );
		$this->assert_invalid_grant( $this->refresh( $token ), 'A replay is reuse.' );
	}

	public function test_after_the_grace_window_a_transient_token_is_refused(): void {
		update_option( Mcpwp_OAuth_Token_Store::GRACE_OPTION, time() - 1, false );
		$token = 'mcpwp_at_' . bin2hex( random_bytes( 24 ) );
		set_transient( 'mcpwp_oauth_token_' . md5( $token ), array( 'scopes' => array( 'read' ), 'user_id' => 1, 'aud' => Mcpwp_OAuth_Well_Known::get_canonical_resource(), 'expires_at' => time() + 600 ), 600 );
		$this->assertFalse( $this->works( $token ) );
	}

	public function test_a_migration_that_cannot_write_refuses_and_keeps_the_transient(): void {
		$token = 'mcpwp_at_' . bin2hex( random_bytes( 24 ) );
		set_transient( 'mcpwp_oauth_token_' . md5( $token ), array( 'scopes' => array( 'read' ), 'user_id' => 1, 'aud' => Mcpwp_OAuth_Well_Known::get_canonical_resource(), 'expires_at' => time() + 600 ), 600 );
		$GLOBALS['mcpwp_test_oauth_tokens_fail'] = function ( $kind ) {
			return 'insert' === $kind;
		};
		$this->assertFalse( $this->works( $token ), 'Fail closed.' );
		$this->assertNotFalse( get_transient( 'mcpwp_oauth_token_' . md5( $token ) ), 'Nothing lost: a later use can migrate it.' );
	}

	// ── fail closed ───────────────────────────────────────────────────────

	public function test_with_the_table_missing_nothing_is_issued_and_nothing_validates(): void {
		$data = $this->redeem( $this->seed_code() )->get_data();
		$GLOBALS['mcpwp_test_oauth_tokens_exists'] = false;

		$this->assertFalse( $this->works( $data['access_token'] ) );

		$code     = $this->seed_code();
		$response = $this->redeem( $code );
		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( 'temporarily_unavailable', $response->get_data()['error'] );
		$this->assertNotFalse( get_transient( 'mcpwp_oauth_code_' . $code ), 'The code is not burned.' );

		$cc = ( new Mcpwp_Api_Auth_Test_Harness() )->issue_oauth_access_token( array( 'read' ), 3600, array( 'client_id' => 'test_client' ) );
		$this->assertInstanceOf( WP_Error::class, $cc );
		$this->assertSame( 503, $cc->get_error_data()['status'] );
	}

	public function test_a_refresh_whose_new_rows_cannot_be_written_burns_nothing(): void {
		$data = $this->redeem( $this->seed_code() )->get_data();
		$GLOBALS['mcpwp_test_oauth_tokens_fail'] = function ( $kind ) {
			return 'insert' === $kind;
		};
		$response = $this->refresh( $data['refresh_token'] );
		$GLOBALS['mcpwp_test_oauth_tokens_fail'] = null;

		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( 'refresh', mcpwp_test_oauth_row( $data['refresh_token'] )['type'], 'Not claimed.' );
		$this->assertSame( 200, $this->refresh( $data['refresh_token'] )->get_status(), 'The client retries.' );
	}

	public function test_a_refresh_row_that_cannot_be_written_revokes_the_access_token_just_issued(): void {
		$count = 0;
		$GLOBALS['mcpwp_test_oauth_tokens_fail'] = function ( $kind ) use ( &$count ) {
			return 'insert' === $kind && 2 === ++$count; // access row ok, refresh row fails
		};
		$response = $this->redeem( $this->seed_code() );
		$GLOBALS['mcpwp_test_oauth_tokens_fail'] = null;

		$this->assertSame( 503, $response->get_status() );
		$rows = array_values( $GLOBALS['mcpwp_test_oauth_tokens'] );
		$this->assertCount( 1, $rows );
		$this->assertNotNull( $rows[0]['revoked_at'], 'The orphan access token is revoked.' );
	}

	public function test_a_code_claim_lost_after_the_mint_discards_what_was_minted(): void {
		$code = $this->seed_code();
		$GLOBALS['mcpwp_test_oauth_tokens_before'] = function ( $kind ) use ( $code ) {
			if ( 'insert' === $kind ) {
				delete_transient( 'mcpwp_oauth_code_' . $code ); // the other redemption claims it
			}
		};
		try {
			$response = $this->redeem( $code );
		} finally {
			$GLOBALS['mcpwp_test_oauth_tokens_before'] = null;
		}
		$this->assert_invalid_grant( $response, 'Lost the claim.' );
		foreach ( $GLOBALS['mcpwp_test_oauth_tokens'] as $row ) {
			$this->assertNotNull( $row['revoked_at'] );
		}
	}

	// ── revocation reaches the rows ───────────────────────────────────────

	public function test_rfc7009_marks_the_row_revoked(): void {
		$data = $this->redeem( $this->seed_code() )->get_data();
		$this->ctrl()->handle_revoke( new WP_REST_Request( 'POST', '/mcpwp/v1/oauth/revoke', array( 'token' => $data['access_token'], 'client_id' => 'test_client' ) ) );
		$this->assertNotNull( mcpwp_test_oauth_row( $data['access_token'] )['revoked_at'] );
		$this->assertNull( mcpwp_test_oauth_row( $data['refresh_token'] )['revoked_at'] );

		$this->ctrl()->handle_revoke( new WP_REST_Request( 'POST', '/mcpwp/v1/oauth/revoke', array( 'token' => $data['refresh_token'], 'client_id' => 'test_client' ) ) );
		$this->assertNotNull( mcpwp_test_oauth_row( $data['refresh_token'] )['revoked_at'] );
	}

	public function test_a_revoked_row_is_refused_even_with_no_epoch(): void {
		$data = $this->redeem( $this->seed_code() )->get_data();
		mcpwp_test_oauth_set( $data['access_token'], array( 'revoked_at' => Mcpwp_OAuth_Revocation::now() ) );
		$this->assertFalse( $this->works( $data['access_token'] ) );
		mcpwp_test_oauth_set( $data['refresh_token'], array( 'revoked_at' => Mcpwp_OAuth_Revocation::now() ) );
		$this->assert_invalid_grant( $this->refresh( $data['refresh_token'] ), 'revoked_at honoured.' );
	}

	// ── cleanup, uninstall ────────────────────────────────────────────────

	public function test_the_daily_cleanup_deletes_only_expired_rows(): void {
		Mcpwp_OAuth_Token_Store::insert( 'old', 'access', array( 'scopes' => array( 'read' ), 'expires_at' => time() - 1 ) );
		Mcpwp_OAuth_Token_Store::insert( 'live', 'access', array( 'scopes' => array( 'read' ), 'expires_at' => time() + 60 ) );
		$this->assertSame( 1, Mcpwp_OAuth_Token_Store::cleanup() );
		$this->assertNull( mcpwp_test_oauth_row( 'old' ) );
		$this->assertNotNull( mcpwp_test_oauth_row( 'live' ) );

		Mcpwp_OAuth_Token_Store::schedule_cleanup();
		$this->assertNotFalse( wp_next_scheduled( Mcpwp_OAuth_Token_Store::CLEANUP_CRON_HOOK ) );
	}

	public function test_uninstall_drops_the_table_its_options_and_its_cron(): void {
		update_option( Mcpwp_OAuth_Token_Store::SCHEMA_OPTION, 1 );
		update_option( Mcpwp_OAuth_Token_Store::GRACE_OPTION, time() );
		Mcpwp_OAuth_Token_Store::schedule_cleanup();
		Mcpwp_Uninstaller::drop_oauth_tokens_for_current_site();
		$this->assertFalse( $GLOBALS['mcpwp_test_oauth_tokens_exists'] );
		$this->assertFalse( get_option( Mcpwp_OAuth_Token_Store::SCHEMA_OPTION, false ) );
		$this->assertFalse( get_option( Mcpwp_OAuth_Token_Store::GRACE_OPTION, false ) );
		$this->assertFalse( wp_next_scheduled( Mcpwp_OAuth_Token_Store::CLEANUP_CRON_HOOK ) );
	}

	private function assert_invalid_grant( WP_REST_Response $response, string $context ): void {
		$this->assertSame( 400, $response->get_status(), $context );
		$this->assertSame( 'invalid_grant', $response->get_data()['error'], $context );
	}
}
