<?php
/**
 * #1115 — OAuth PR-3a follow-ups, as regression tests:
 *
 *  - P2-A  a refresh token with no family (a record from before the family
 *          column) is migrated into one deterministic family, so concurrent
 *          refreshes of it agree, the tombstone names the chain that really
 *          received tokens, and revocation reaches that chain.
 *  - P2-B  a revoke whose revocation cannot be made durable answers 503 on
 *          every attempt until it is, never a 200 on a retry that persisted
 *          nothing.
 *  - the user's revocation epoch meta is not writable through the users API.
 *  - a refresh claim that does not land is refused and nothing is returned.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ApiAuthTest.php';
require_once __DIR__ . '/OAuthRevocation1101Test.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-oauth-token-store.php';

final class OAuthIssue1115Test extends TestCase {

	const USER     = 1;
	const REDIRECT = 'https://chatgpt.com/cb';
	const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
	const DCR      = 'mcpwpc_0123456789abcdef0123456789abcdef';

	/** @var mixed */
	private $saved_addr;
	/** @var array */
	private $saved_users;

	protected function setUp(): void {
		$this->saved_addr  = $_SERVER['REMOTE_ADDR'] ?? null;
		$this->saved_users = $GLOBALS['mcpwp_test_users'];
		$GLOBALS['mcpwp_test_users'][] = (object) array( 'ID' => self::USER, 'roles' => array(), 'user_login' => 'u1' );
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_wpdb_options'] = array();
		$GLOBALS['mcpwp_test_user_meta']    = array();
		$GLOBALS['mcpwp_test_user_caps']    = array( self::USER => array( 'read' => true, 'edit_posts' => true, 'edit_others_posts' => true ) );
		$_SERVER['REMOTE_ADDR']             = '203.0.113.7';
		update_option( 'mcpwp_settings', array( 'oauth_enabled' => true, 'oauth_client_id' => 'test_client', 'oauth_token_ttl' => 3600, 'oauth_redirect_uris' => array( 'https://client.example.com/cb' ) ) );
		update_option( Mcpwp_OAuth_Clients::OPTION, array( self::DCR => array( 'client_id' => self::DCR, 'client_name' => 'X', 'redirect_uris' => array( self::REDIRECT ), 'created_at' => time() - 100, 'last_used_at' => time() - 50, 'authorized_at' => time() - 50 ) ), false );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['mcpwp_test_transient_hook'] );
		$GLOBALS['mcpwp_test_oauth_tokens_fail'] = null;
		$GLOBALS['mcpwp_test_current_user']      = 0;
		$GLOBALS['mcpwp_test_user_caps']         = array();
		$GLOBALS['mcpwp_test_users']             = $this->saved_users;
		if ( null === $this->saved_addr ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $this->saved_addr;
		}
	}

	private function ctrl() {
		return new Mcpwp_OAuth_1101_Controller();
	}

	private function refresh( string $token ): WP_REST_Response {
		return $this->ctrl()->handle_token( new WP_REST_Request( 'POST', '/x', array( 'grant_type' => 'refresh_token', 'refresh_token' => $token, 'client_id' => self::DCR ) ) );
	}

	private function revoke( string $token ): WP_REST_Response {
		return $this->ctrl()->handle_revoke( new WP_REST_Request( 'POST', '/x', array( 'token' => $token, 'client_id' => self::DCR ) ) );
	}

	private function works( string $access_token ): bool {
		return true === ( new Mcpwp_Api_Auth_Test_Harness() )->verify_api_key( new WP_REST_Request( 'GET', '/mcpwp/v1/site-info', array(), array( 'Authorization' => 'Bearer ' . $access_token ) ) );
	}

	private function grant(): array {
		$code = 'code_' . bin2hex( random_bytes( 8 ) );
		set_transient( 'mcpwp_oauth_code_' . $code, array( 'client_id' => self::DCR, 'redirect_uri' => self::REDIRECT, 'code_challenge' => $this->ctrl()->compute_pkce_challenge( self::VERIFIER ), 'scope' => 'read', 'user_id' => self::USER, 'issued_at' => Mcpwp_OAuth_Revocation::now(), 'expires' => time() + 60 ), 60 );
		$r = $this->ctrl()->handle_token( new WP_REST_Request( 'POST', '/x', array( 'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::REDIRECT, 'code_verifier' => self::VERIFIER, 'client_id' => self::DCR ) ) );
		$this->assertSame( 200, $r->get_status() );
		return $r->get_data();
	}

	private function evict_family_markers(): void {
		foreach ( array_keys( $GLOBALS['mcpwp_test_transients'] ) as $name ) {
			if ( 0 === strpos( $name, Mcpwp_OAuth_Revocation::FAMILY_TRANSIENT_PREFIX ) ) {
				unset( $GLOBALS['mcpwp_test_transients'][ $name ] );
			}
		}
	}

	private function quiet( callable $fn ) {
		$log = ini_set( 'error_log', '/dev/null' );
		try {
			return $fn();
		} finally {
			ini_set( 'error_log', (string) $log );
		}
	}

	// ── P2-A ──────────────────────────────────────────────────────────────

	/**
	 * Two refreshes of the same pre-family token: the second read the record
	 * before the first migrated it and loses the claim.  The tombstone must
	 * name the family the winner's tokens are in, and a revoke of the token
	 * must reach them.
	 */
	public function test_legacy_race_tombstone_names_wrong_family(): void {
		$legacy = 'mcpwp_rt_' . bin2hex( random_bytes( 16 ) );
		$key    = $this->ctrl()->get_oauth_refresh_token_transient_key( $legacy );
		$rec    = array( 'scopes' => array( 'read', 'write' ), 'user_id' => self::USER, 'created_at' => time() - 60, 'expires_at' => time() + 3600 );
		set_transient( $key, $rec, 3600 );

		$winner = $this->refresh( $legacy );
		$this->assertSame( 200, $winner->get_status() );
		$minted = $winner->get_data();

		$served                                = false;
		$GLOBALS['mcpwp_test_transient_hook'] = function ( $name ) use ( $key, $rec, &$served ) {
			if ( $name === $key && ! $served ) {
				$served = true;
				return $rec;
			}
			return null;
		};
		$loser = $this->refresh( $legacy );
		unset( $GLOBALS['mcpwp_test_transient_hook'] );
		$this->assertSame( 400, $loser->get_status() );

		$tombstone = mcpwp_test_oauth_row( $legacy );
		$this->assertSame( 'reuse_tombstone', $tombstone['type'] );
		$this->assertSame( Mcpwp_OAuth_Token_Store::legacy_family_id( $legacy ), $tombstone['family_id'] );
		$this->assertSame( $tombstone['family_id'], mcpwp_test_oauth_row( $minted['refresh_token'] )['family_id'], 'The tombstone names the chain that received tokens.' );
		$this->assertSame( $tombstone['family_id'], mcpwp_test_oauth_row( $minted['access_token'] )['family_id'] );

		$this->assertSame( 200, $this->revoke( $legacy )->get_status() );
		$this->assertFalse( $this->works( $minted['access_token'] ), 'Revoking the old token reaches the chain minted from it.' );
		$this->assertSame( 400, $this->refresh( $minted['refresh_token'] )->get_status() );
	}

	/**
	 * A live refresh row that carries no family must also derive it from the
	 * token — a random one per request is what let concurrent refreshes
	 * disagree.
	 */
	public function test_a_live_refresh_row_without_a_family_gets_the_deterministic_family(): void {
		$token = 'mcpwp_rt_' . bin2hex( random_bytes( 32 ) );
		$this->assertTrue( Mcpwp_OAuth_Token_Store::insert( $token, 'refresh', array( 'scopes' => array( 'read' ), 'user_id' => self::USER, 'client_id' => self::DCR, 'aud' => Mcpwp_OAuth_Well_Known::get_canonical_resource(), 'issued_at' => Mcpwp_OAuth_Revocation::now(), 'expires_at' => time() + 3600 ) ) );
		$this->assertSame( '', (string) mcpwp_test_oauth_row( $token )['family_id'] );

		$minted = $this->refresh( $token );
		$this->assertSame( 200, $minted->get_status() );

		$expected = Mcpwp_OAuth_Token_Store::legacy_family_id( $token );
		$this->assertSame( $expected, mcpwp_test_oauth_row( $token )['family_id'] );
		$this->assertSame( $expected, mcpwp_test_oauth_row( $minted->get_data()['refresh_token'] )['family_id'] );
	}

	// ── P2-B ──────────────────────────────────────────────────────────────

	/**
	 * While the revocation cannot be persisted, every attempt answers 503 —
	 * including the retry that finds the token already marked — and the
	 * attempt that finally lands is the one that answers 200 with a durable
	 * family marker.
	 */
	public function test_503_retry_is_false_200(): void {
		$g = $this->grant();
		$GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_OAuth_Revocation::LOCK_OPTION ] = ( time() + 600 ) . '|held';

		$this->assertSame( 503, $this->quiet( function () use ( $g ) { return $this->revoke( $g['refresh_token'] )->get_status(); } ) );
		$this->assertSame( 503, $this->quiet( function () use ( $g ) { return $this->revoke( $g['refresh_token'] )->get_status(); } ), 'The retry must not be a false 200.' );
		$this->assertSame( array(), get_option( Mcpwp_OAuth_Revocation::FAMILIES_OPTION, array() ), 'Nothing durable yet.' );

		unset( $GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_OAuth_Revocation::LOCK_OPTION ] );
		$this->assertSame( 200, $this->revoke( $g['refresh_token'] )->get_status() );
		$this->assertNotEmpty( get_option( Mcpwp_OAuth_Revocation::FAMILIES_OPTION, array() ), 'The 200 means the family is persisted.' );

		$this->evict_family_markers();
		$this->assertFalse( $this->works( $g['access_token'] ), 'Still revoked after the cache marker is gone.' );
		$this->assertSame( 400, $this->refresh( $g['refresh_token'] )->get_status() );
	}

	/** The same holds when the failing store is the token table. */
	public function test_503_retry_with_a_failing_token_table_is_not_a_false_200(): void {
		$g = $this->grant();
		$GLOBALS['mcpwp_test_oauth_tokens_fail'] = function ( $kind ) {
			return 'query' === $kind;
		};
		$first  = $this->quiet( function () use ( $g ) { return $this->revoke( $g['refresh_token'] )->get_status(); } );
		$second = $this->quiet( function () use ( $g ) { return $this->revoke( $g['refresh_token'] )->get_status(); } );
		$GLOBALS['mcpwp_test_oauth_tokens_fail'] = null;
		$this->assertSame( array( 503, 503 ), array( $first, $second ) );

		$this->assertSame( 200, $this->revoke( $g['refresh_token'] )->get_status() );
		$this->assertFalse( $this->works( $g['access_token'] ) );
	}

	// ── protected epoch meta, tombstone write ─────────────────────────────

	public function test_the_revocation_epoch_meta_key_is_protected(): void {
		require_once dirname( __DIR__ ) . '/includes/pro/core/class-mcpwp-users.php';
		$probe = new class() extends Mcpwp_Users {
			public static function probe( string $key ): bool {
				return self::is_protected_user_meta_key( $key );
			}
		};
		$this->assertSame( 'mcpwp_oauth_revoked_before', Mcpwp_OAuth_Revocation::USER_META );
		$this->assertTrue( $probe::probe( Mcpwp_OAuth_Revocation::USER_META ) );
		$this->assertTrue( $probe::probe( 'mcpwp_oauth_anything_else' ) );
		$this->assertFalse( $probe::probe( 'first_name' ) );
	}

	public function test_a_refresh_claim_that_does_not_land_is_refused_and_returns_no_tokens(): void {
		$g = $this->grant();
		$GLOBALS['mcpwp_test_oauth_tokens_fail'] = function ( $kind ) {
			return 'query' === $kind;
		};
		$response = $this->refresh( $g['refresh_token'] );
		$GLOBALS['mcpwp_test_oauth_tokens_fail'] = null;

		$this->assertSame( 400, $response->get_status() );
		$this->assertArrayNotHasKey( 'access_token', (array) $response->get_data() );
		$this->assertSame( 'refresh', mcpwp_test_oauth_row( $g['refresh_token'] )['type'], 'Not consumed: the client can retry.' );
	}
}
