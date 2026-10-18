<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/ApiAuthTest.php';
require_once __DIR__ . '/OAuthRevocation1101Test.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-oauth-token-store.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-mcpwp-oauth-apps-admin.php';

final class PocV1117Test extends TestCase {
	/** @var mixed Copied PoC: REMOTE_ADDR restored after each test (other tests rely on it being unset). */
	private $saved_addr;
	const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
	const DCR = 'mcpwpc_0123456789abcdef0123456789abcdef';
	private $saved;
	protected function setUp(): void {
		$this->saved_addr = $_SERVER['REMOTE_ADDR'] ?? null;
		$this->saved = $GLOBALS['mcpwp_test_users'];
		$GLOBALS['mcpwp_test_users'][] = (object) array( 'ID' => 1, 'roles' => array(), 'user_login' => 'u1' );
		$GLOBALS['mcpwp_test_options'] = array(); $GLOBALS['mcpwp_test_transients'] = array();
		$GLOBALS['mcpwp_test_wpdb_options'] = array(); $GLOBALS['mcpwp_test_user_meta'] = array();
		$GLOBALS['mcpwp_test_user_caps'] = array( 1 => array( 'read' => true, 'edit_posts' => true, 'edit_others_posts' => true ) );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
		update_option( 'mcpwp_settings', array( 'oauth_enabled' => true, 'oauth_client_id' => 'test_client', 'oauth_token_ttl' => 3600, 'oauth_redirect_uris' => array( 'https://client.example.com/callback' ) ) );
		update_option( Mcpwp_OAuth_Clients::OPTION, array( self::DCR => array( 'client_id' => self::DCR, 'client_name' => 'X', 'redirect_uris' => array( 'https://chatgpt.com/cb' ), 'created_at' => time() - 100, 'last_used_at' => time() - 50, 'authorized_at' => time() - 50 ) ), false );
	}
	protected function tearDown(): void { if ( null === $this->saved_addr ) { unset( $_SERVER['REMOTE_ADDR'] ); } else { $_SERVER['REMOTE_ADDR'] = $this->saved_addr; } $GLOBALS['mcpwp_test_users'] = $this->saved; $GLOBALS['mcpwp_test_user_caps'] = array(); $GLOBALS['mcpwp_test_current_user'] = 0; }
	private function c() { return new Mcpwp_OAuth_1101_Controller(); }
	private function refresh( $rt, $cid ) { return $this->c()->handle_token( new WP_REST_Request( 'POST', '/x', array( 'grant_type' => 'refresh_token', 'refresh_token' => $rt, 'client_id' => $cid ) ) ); }
	private function revoke( $t, $cid ) { return $this->c()->handle_revoke( new WP_REST_Request( 'POST', '/x', array( 'token' => $t, 'client_id' => $cid ) ) ); }
	private function works( $at ) { return true === ( new Mcpwp_Api_Auth_Test_Harness() )->verify_api_key( new WP_REST_Request( 'GET', '/mcpwp/v1/site-info', array(), array( 'Authorization' => 'Bearer ' . $at ) ) ); }
	private function grant() {
		$code = 'code_' . bin2hex( random_bytes( 8 ) );
		set_transient( 'mcpwp_oauth_code_' . $code, array( 'client_id' => self::DCR, 'redirect_uri' => 'https://chatgpt.com/cb', 'code_challenge' => $this->c()->compute_pkce_challenge( self::VERIFIER ), 'scope' => 'read', 'user_id' => 1, 'issued_at' => Mcpwp_OAuth_Revocation::now(), 'expires' => time() + 60 ), 60 );
		$r = $this->c()->handle_token( new WP_REST_Request( 'POST', '/x', array( 'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => 'https://chatgpt.com/cb', 'code_verifier' => self::VERIFIER, 'client_id' => self::DCR ) ) );
		$this->assertSame( 200, $r->get_status() );
		return $r->get_data();
	}

	/** #1115 P2-A adapted: legacy (pre-#1113, no family/client) RT stolen + rotated by thief; victim revoke + replay must kill thief chain. */
	public function test_legacy_rotated_tombstone_reaches_thief_family(): void {
		Mcpwp_OAuth_Token_Store::ensure_table();
		$legacy = 'mcpwp_rt_' . bin2hex( random_bytes( 16 ) );
		set_transient( $this->c()->get_oauth_refresh_token_transient_key( $legacy ), array( 'scopes' => array( 'read', 'write' ), 'user_id' => 1, 'created_at' => time() - 60, 'expires_at' => time() + 3600 ), 3600 );
		$a = $this->refresh( $legacy, self::DCR );
		$this->assertSame( 200, $a->get_status() );
		$thief = $a->get_data();
		$row = mcpwp_test_oauth_row( $legacy );
		fwrite( STDERR, "\n[A] tombstone row family='{$row['family_id']}' client='{$row['client_id']}' type={$row['type']}\n" );
		$rv = $this->revoke( $legacy, self::DCR )->get_status();
		$rp = $this->refresh( $legacy, self::DCR )->get_status();
		fwrite( STDERR, "[A] victim revoke=$rv replay=$rp thief_at_works=" . var_export( $this->works( $thief['access_token'] ), true ) . "\n" );
		$this->assertFalse( $this->works( $thief['access_token'] ), 'BUG: thief chain survives victim revoke + replay of legacy RT' );
	}

	/** Same, post-#1113 record (has family+client): control. */
	public function test_bound_rotated_tombstone_control(): void {
		$g = $this->grant();
		$t = $this->refresh( $g['refresh_token'], self::DCR )->get_data();
		$this->assertSame( 200, $this->revoke( $g['refresh_token'], self::DCR )->get_status() );
		$this->assertFalse( $this->works( $t['access_token'] ) );
	}

	/** #1115 P2-B adapted: lock held -> 503; retry -> must not leave access token alive. */
	public function test_503_retry(): void {
		$g = $this->grant();
		$GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_OAuth_Revocation::LOCK_OPTION ] = ( time() + 60 ) . '|deadbeef';
		$log = ini_set( 'error_log', '/dev/null' );
		$first = $this->revoke( $g['refresh_token'], self::DCR )->get_status();
		unset( $GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_OAuth_Revocation::LOCK_OPTION ] );
		$retry = $this->revoke( $g['refresh_token'], self::DCR )->get_status();
		ini_set( 'error_log', (string) $log );
		foreach ( array_keys( $GLOBALS['mcpwp_test_transients'] ) as $n ) { if ( 0 === strpos( $n, Mcpwp_OAuth_Revocation::FAMILY_TRANSIENT_PREFIX ) ) unset( $GLOBALS['mcpwp_test_transients'][ $n ] ); }
		fwrite( STDERR, "\n[B] first=$first retry=$retry fams=" . json_encode( get_option( Mcpwp_OAuth_Revocation::FAMILIES_OPTION, array() ) ) . "\n" );
		$this->assertSame( 503, $first );
		$this->assertFalse( $this->works( $g['access_token'] ) );
	}

	/** Access-token revoke whose UPDATE fails answers 200 and the token keeps working. */
	public function test_access_revoke_update_failure_false_200(): void {
		$g = $this->grant();
		$GLOBALS['mcpwp_test_oauth_tokens_fail'] = function ( $kind ) { return 'query' === $kind; };
		$st = $this->revoke( $g['access_token'], self::DCR )->get_status();
		$GLOBALS['mcpwp_test_oauth_tokens_fail'] = null;
		fwrite( STDERR, "\n[C] access revoke status=$st works_after=" . var_export( $this->works( $g['access_token'] ), true ) . "\n" );
		$this->assertTrue( 200 !== $st || ! $this->works( $g['access_token'] ), 'BUG: 200 but access token still valid' );
	}

	/** protected user meta (#1115). */
	public function test_revoked_before_meta_is_protected(): void {
		require_once dirname( __DIR__ ) . '/includes/pro/core/class-mcpwp-users.php';
		$m = new ReflectionMethod( 'Mcpwp_Users', 'is_protected_user_meta_key' ); $m->setAccessible( true );
		$this->assertTrue( $m->invoke( null, 'mcpwp_oauth_revoked_before' ), 'BUG: mcpwp_oauth_revoked_before writable via PUT /users meta' );
	}

	/** Per-app Revoke by the user misses an unbound legacy RT (and the profile never lists it). */
	public function test_user_per_app_revoke_misses_legacy_unbound_rt(): void {
		Mcpwp_OAuth_Token_Store::ensure_table();
		$g = $this->grant(); // a bound chain so the app is listed
		$legacy = 'mcpwp_rt_' . bin2hex( random_bytes( 16 ) );
		set_transient( $this->c()->get_oauth_refresh_token_transient_key( $legacy ), array( 'scopes' => array( 'read' ), 'user_id' => 1, 'created_at' => time() - 60, 'expires_at' => time() + 3600 ), 3600 );
		usleep( 2000 );
		$this->assertTrue( Mcpwp_OAuth_Revocation::revoke_user_client( 1, self::DCR ) );
		$this->assertFalse( $this->works( $g['access_token'] ) );
		$r = $this->refresh( $legacy, self::DCR );
		fwrite( STDERR, "\n[D] legacy RT after user revoked app: status=" . $r->get_status() . "\n" );
		$this->assertNotSame( 200, $r->get_status(), 'BUG: legacy unbound RT re-mints after per-app Revoke' );
	}

	/** Subscriber can grow the pairs option with junk client ids. */
	public function test_pairs_option_growth_by_any_user(): void {
		$GLOBALS['mcpwp_test_current_user'] = 1;
		$GLOBALS['mcpwp_test_nonce_strict'] = true;
		for ( $i = 0; $i < 200; $i++ ) {
			Mcpwp_OAuth_Apps_Admin::process_profile( 1, array( 'mcpwp_oauth_revoke_client' => 'junk' . $i . str_repeat( 'a', 90 ), 'mcpwp_oauth_apps_nonce' => wp_create_nonce( 'mcpwp_oauth_apps_profile|1' ) ) );
		}
		unset( $GLOBALS['mcpwp_test_nonce_strict'] );
		$n = count( (array) get_option( Mcpwp_OAuth_Revocation::PAIRS_OPTION, array() ) );
		fwrite( STDERR, "\n[E] pairs entries=$n bytes=" . strlen( serialize( get_option( Mcpwp_OAuth_Revocation::PAIRS_OPTION ) ) ) . "\n" );
		$this->assertLessThan( 10, $n, 'BUG: unbounded pair entries for non-existent clients' );
	}
}
