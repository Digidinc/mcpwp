<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/OAuthRevocation1101Test.php';

final class PocV1113Test extends TestCase {
	/** @var mixed Copied PoC: REMOTE_ADDR restored after each test (other tests rely on it being unset). */
	private $saved_addr;
	/** @var array */
	private $saved_users;
	const REDIRECT = 'https://client.example.com/callback';
	const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
	const DCR = 'mcpwpc_0123456789abcdef0123456789abcdef';
	protected function setUp(): void {
		$this->saved_addr = $_SERVER['REMOTE_ADDR'] ?? null;
		$this->saved_users = $GLOBALS['mcpwp_test_users'];
		$GLOBALS['mcpwp_test_users'][] = (object) array( 'ID' => 1, 'roles' => array(), 'user_login' => 'u1' );
		$GLOBALS['mcpwp_test_options'] = array(); $GLOBALS['mcpwp_test_transients'] = array();
		$GLOBALS['mcpwp_test_wpdb_options'] = array(); $GLOBALS['mcpwp_test_user_meta'] = array();
		$GLOBALS['mcpwp_test_user_caps'] = array( 1 => array( 'read' => true, 'edit_posts' => true, 'edit_others_posts' => true ) );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
		update_option( 'mcpwp_settings', array( 'oauth_enabled' => true, 'oauth_client_id' => 'test_client', 'oauth_token_ttl' => 3600, 'oauth_redirect_uris' => array( self::REDIRECT ) ) );
		update_option( Mcpwp_OAuth_Clients::OPTION, array( self::DCR => array( 'client_id' => self::DCR, 'client_name' => 'X', 'redirect_uris' => array( 'https://chatgpt.com/cb' ), 'created_at' => time() - 100, 'last_used_at' => time() - 50, 'authorized_at' => time() - 50 ) ), false );
	}
	protected function tearDown(): void { if ( null === $this->saved_addr ) { unset( $_SERVER['REMOTE_ADDR'] ); } else { $_SERVER['REMOTE_ADDR'] = $this->saved_addr; } $GLOBALS['mcpwp_test_current_user'] = 0; $GLOBALS['mcpwp_test_user_caps'] = array(); $GLOBALS['mcpwp_test_users'] = $this->saved_users; }
	private function c() { return new Mcpwp_OAuth_1101_Controller(); }
	private function refresh( $rt, $cid ) { return $this->c()->handle_token( new WP_REST_Request( 'POST', '/x', array( 'grant_type' => 'refresh_token', 'refresh_token' => $rt, 'client_id' => $cid ) ) ); }
	private function revoke( $t, $cid ) { return $this->c()->handle_revoke( new WP_REST_Request( 'POST', '/x', array( 'token' => $t, 'client_id' => $cid ) ) ); }
	private function works( $at ) { return true === ( new Mcpwp_Api_Auth_Test_Harness() )->verify_api_key( new WP_REST_Request( 'GET', '/mcpwp/v1/site-info', array(), array( 'Authorization' => 'Bearer ' . $at ) ) ); }

	/** Legacy (no family_id) RT: a losing concurrent refresh overwrites the tombstone with a family that never got tokens. */
	public function test_legacy_race_tombstone_names_wrong_family(): void {
		$legacy = 'mcpwp_rt_' . bin2hex( random_bytes( 16 ) );
		$key    = $this->c()->get_oauth_refresh_token_transient_key( $legacy );
		$rec    = array( 'scopes' => array( 'read', 'write' ), 'user_id' => 1, 'created_at' => time() - 60, 'expires_at' => time() + 3600 );
		set_transient( $key, $rec, 3600 );
		// Thief request A wins.
		$a = $this->refresh( $legacy, self::DCR );
		$this->assertSame( 200, $a->get_status() );
		$thief = $a->get_data();
		// Thief request B read the record before A's delete (simulated), loses the claim.
		$served = false;
		$GLOBALS['mcpwp_test_transient_hook'] = function ( $n ) use ( $key, $rec, &$served ) { if ( $n === $key && ! $served ) { $served = true; return $rec; } return null; };
		$b = $this->refresh( $legacy, self::DCR );
		unset( $GLOBALS['mcpwp_test_transient_hook'] );
		$this->assertSame( 400, $b->get_status() );
		// Victim revokes the token they hold, then also presents it (reuse detection).
		$this->assertSame( 200, $this->revoke( $legacy, self::DCR )->get_status() );
		$this->refresh( $legacy, self::DCR );
		$this->assertFalse( $this->works( $thief['access_token'] ), 'BUG: thief access token survives revoke + reuse detection' );
	}

	/** 503 says retry; the retry finds neither record nor tombstone and answers 200 without persisting. */
	public function test_503_retry_is_false_200(): void {
		$code = 'code_' . bin2hex( random_bytes( 8 ) );
		set_transient( 'mcpwp_oauth_code_' . $code, array( 'client_id' => self::DCR, 'redirect_uri' => 'https://chatgpt.com/cb', 'code_challenge' => $this->c()->compute_pkce_challenge( self::VERIFIER ), 'scope' => 'read', 'user_id' => 1, 'issued_at' => Mcpwp_OAuth_Revocation::now(), 'expires' => time() + 60 ), 60 );
		$g = $this->c()->handle_token( new WP_REST_Request( 'POST', '/x', array( 'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => 'https://chatgpt.com/cb', 'code_verifier' => self::VERIFIER, 'client_id' => self::DCR ) ) )->get_data();
		$GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_OAuth_Revocation::LOCK_OPTION ] = ( time() + 60 ) . '|deadbeef';
		$log = ini_set( 'error_log', '/dev/null' );
		$this->assertSame( 503, $this->revoke( $g['refresh_token'], self::DCR )->get_status() );
		unset( $GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_OAuth_Revocation::LOCK_OPTION ] );
		$retry = $this->revoke( $g['refresh_token'], self::DCR )->get_status();
		ini_set( 'error_log', (string) $log );
		$fams = get_option( Mcpwp_OAuth_Revocation::FAMILIES_OPTION, array() );
		// Object cache evicts the family transient marker.
		foreach ( array_keys( $GLOBALS['mcpwp_test_transients'] ) as $n ) { if ( 0 === strpos( $n, Mcpwp_OAuth_Revocation::FAMILY_TRANSIENT_PREFIX ) ) unset( $GLOBALS['mcpwp_test_transients'][ $n ] ); }
		$this->assertTrue( 200 !== $retry || ! empty( $fams ), "BUG: retry=$retry with no durable family marker" );
		$this->assertFalse( $this->works( $g['access_token'] ), 'BUG: access token revives after marker eviction' );
	}

	/** to_micros boundary probes. */
	public function test_to_micros_probes(): void {
		$now = Mcpwp_OAuth_Revocation::now();
		$this->assertSame( $now, Mcpwp_OAuth_Revocation::to_micros( (string) $now ) );
		$this->assertSame( 1760000000 * 1000000, Mcpwp_OAuth_Revocation::to_micros( '1760000000' ) );
		$this->assertSame( 1760000000500000, Mcpwp_OAuth_Revocation::to_micros( '1760000000.5' ) );
		$this->assertSame( 999999999999000000, Mcpwp_OAuth_Revocation::to_micros( 999999999999 ) );
		$this->assertSame( 1000000000000, Mcpwp_OAuth_Revocation::to_micros( 1000000000000 ) );
		$this->assertSame( 0, Mcpwp_OAuth_Revocation::to_micros( 'abc' ) );
		$this->assertSame( 0, Mcpwp_OAuth_Revocation::to_micros( '' ) );
		$this->assertLessThan( 0, Mcpwp_OAuth_Revocation::to_micros( '-5' ) );
		$this->assertSame( 1760000000 * 1000000, Mcpwp_OAuth_Revocation::to_micros( ' 1760000000' ) ?: 1760000000 * 1000000 );
		// float micros as serialized by PHP with exponent
		$this->assertSame( (int) 1.76e15, Mcpwp_OAuth_Revocation::to_micros( '1.76E+15' ) );
	}
}
